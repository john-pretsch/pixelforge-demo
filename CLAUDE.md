# CLAUDE.md

## Project

A take-home for PromptEdit (Senior Product Engineer). It's a Laravel + Vue + Inertia app that sends image generations to **Pixelforge**, a simulated async image API. The app charges users Pixelforge's cost plus a **25% markup** and shows live status, the estimated and actual cost, and the image for each generation.

- Assessment: https://pixelforge.promptedit.dev/assessment
- API docs: https://pixelforge.promptedit.dev/docs
- Deployed at: https://pixelforge-demo.johnpretsch.com (this server, served by nginx)

Stretch goals (credit balances and an admin profit report) are **designed in but built only after the required scope ships**.

## Stack and commands

Laravel 12 (PHP 8.4), the Vue 3 + Inertia + TypeScript starter kit (includes auth), Postgres, the database queue driver, and PHPUnit (class-style tests, as in the starter kit). Tests run against a real Postgres database, `pixelforge_test`, so NUMERIC, unique constraints and row locks behave as in prod. Shared Pixelforge payload fixtures live in `tests/Support/PixelforgeFixtures.php`.

```bash
composer dev                     # local: server + queue + vite
php artisan test                 # all tests (phpunit.xml bypasses the prod config cache; keep that)
php artisan test --filter=Webhook
npm run build                    # production assets
php artisan queue:work --tries=0 # worker (jobs control their own retries)
php artisan pixelforge:reconcile # polling fallback (scheduled every minute)
php artisan schedule:run         # run by cron in prod
```

## Non-negotiable invariants

### Money
- **Never use floats for money.** Columns are `NUMERIC(20,9)` (USD). PHP math uses `brick/math` `BigDecimal`. Values cross the API boundary as decimal strings.
- Provider cost = Σ(tokens × rate_per_1M) / 1,000,000. This is exact, and 9 decimals is enough.
- Price charged = provider cost × `config('pixelforge.markup')` (1.25). **Store it unrounded.** Round only for display: 2 decimals, or 4 when the amount is under $0.01. Stored provider cost must reconcile exactly with `GET /v1/usage` (9dp).
- Actual cost uses the **`rates` locked on the generation object**, never the current `/v1/pricing`. `/v1/pricing` (cached about 10 minutes) is for estimates only. Never hardcode rates.
- **Failed generations cost 0** A generation's actual cost is written **only** on the transition into `succeeded`, and that transition happens at most once.
- Estimates are a **range** (low/high), and both bounds are stored on the generation.

### Generation state
- Local statuses (`App\Enums\GenerationStatus`): `pending` (not yet accepted by Pixelforge) → `queued` → `processing` → `succeeded` | `failed`.
- **`App\Actions\Generations\ApplyProviderState` is the only code that changes status, cost, usage or the image.** Webhooks, `SubmitGeneration` and reconciliation all go through it.
  - It runs in a DB transaction with `lockForUpdate()` on the generation row.
  - Status only moves forward by rank. It **never moves out of `succeeded` or `failed`.** Stale or out-of-order events are no-ops.
- A webhook may arrive **before** the create call returns. Look up the row by `provider_id`, or else by `metadata.local_id`, and backfill `provider_id`.

### Submission and provider errors
- The controller creates the local row (`pending`, with a UUID) and then dispatches `SubmitGeneration`. The UUID is sent as **both** the `Idempotency-Key` and `metadata.local_id`, so a retried submission can never create or bill twice.
- `SubmitGeneration` branches on `error.code`, never on the message text:

  | Code | Handling |
  |---|---|
  | `rate_limit_exceeded`, `concurrency_limit_exceeded`, `overloaded` | Release back to the queue after `Retry-After` (or exponential backoff with jitter), with capped attempts. The UI says "Waiting for capacity". |
  | `content_policy_violation`, `validation_failed`, `daily_limit_exceeded` | Terminal. The row goes to `failed` with a user-friendly message and costs $0. |
  | Retries exhausted, or a network error after the max attempts | `failed` with a message, $0. |
  | `idempotency_key_reused` | A bug. Log it loudly and fail the row. |

- Post-acceptance failure codes (`generation_failed`, `internal_error`) arrive via webhook or poll. They are shown to the user and are not billed.

### Webhooks (`POST /webhooks/pixelforge`)
- The route is excluded from CSRF and auth. The `VerifyPixelforgeSignature` middleware runs on the **raw body before any JSON parsing**:
  - parse the header `Pixelforge-Signature: t=<ts>,v1=<hex>`
  - compute `hash_hmac('sha256', "$t.$rawBody", PIXELFORGE_WEBHOOK_SECRET)`
  - compare with `hash_equals`
  - reject anything with `|now - t| > 300s`
  - a bad or stale signature returns `400`
- Dedupe by event id. Insert into `webhook_events` (unique `event_id`) **inside the same transaction** as `ApplyProviderState`. If processing throws, the event isn't recorded, Pixelforge retries, and the retry is processed. A duplicate event id returns `200` straight away.
- Respond `2xx` fast (the deadline is 10 seconds). `ping` events are recorded and acknowledged.
- Delivery isn't guaranteed, so `pixelforge:reconcile` polls `GET /v1/generations/{id}` for rows that aren't terminal and are older than about 45 seconds.

### Limits
- Limits are 60 requests/min, 3 concurrent generations, and 500 per UTC day, per **account** across all keys. Every retry counts. Always honour `Retry-After` and cap retries.
- Reconcile polling must stay well under the per-minute budget. Batch it, and skip rows a webhook updated recently.

## Architecture map

```
app/
  Enums/GenerationStatus.php           # statuses + rank(), isTerminal()
  Models/Generation.php                # local row; money columns are exact decimal strings
  Models/WebhookEvent.php              # processed event ids (dedupe)
  Pixelforge/
    PixelforgeClient.php               # the ONLY place that talks HTTP to Pixelforge
    PixelforgeException.php            # code, http status, retryAfter, isRetryable()
    WebhookSignature.php               # pure verify(header, rawBody, secret, now)
  Pricing/                             # pure money math: no DB, no HTTP (plain PHPUnit tests)
    Rates.php                          # value object (from /v1/pricing or locked generation rates)
    TokenUsage.php                     # value object for a generation's `usage`
    PricingTable.php                   # parsed /v1/pricing: rates + token constants, sizes, qualities
    CostCalculator.php                 # providerCost(usage, rates); price(cost, markup)
    CostEstimator.php                  # prompt/size/quality/refs -> Estimate
    Estimate.php                       # min/max tokens, provider cost and price; display range
    Money.php                          # BigDecimal helpers + display formatting
  Actions/Generations/
    CreateGeneration.php               # validate -> estimate -> insert pending -> dispatch
    ApplyProviderState.php             # single transition + costing point
  Jobs/SubmitGeneration.php            # POST with Idempotency-Key, retry/backoff policy
  Console/Commands/ReconcileGenerations.php
  Http/Controllers/GenerationController.php       # index, store, estimate
  Http/Controllers/PixelforgeWebhookController.php
  Http/Middleware/VerifyPixelforgeSignature.php
config/pixelforge.php                  # base_url, api_key, webhook_secret, markup, signature_tolerance
resources/js/pages/Generations/Index.vue          # form + history
resources/js/components/generations/   # GenerationForm, CostEstimate, StatusBadge, GenerationRow
deploy/                                # nginx vhost, systemd worker unit, crontab (reference copies)
tests/Unit/{Pricing,Pixelforge}/  tests/Feature/
```

**Frontend:**
- The estimate comes from a debounced `POST /generations/estimate`, so pricing math lives only on the server.
- The history refreshes with an Inertia partial reload (`router.reload({ only: ['generations'] })`) every 3 seconds while any row isn't terminal.
- The page shows the status badge, the estimated range, the actual cost (once succeeded), the error message (if failed), and the image. Expired image URLs are acceptable.

**Stretch (later):**
- `credit_transactions` ledger: `user_id`, nullable `generation_id`, `type` purchase|debit|hold, `amount NUMERIC(20,9)`, unique `(generation_id, type)`. The balance is the SUM of these rows. Holds use the estimate's upper bound.
- The admin report groups by the **UTC date of `completed_at`**, the same bucketing as `/v1/usage`, so provider cost matches.

## Pixelforge API quick reference

Base URL `https://pixelforge.promptedit.dev/v1`. Send `Authorization: Bearer <key>`. All requests and responses are JSON, money values are decimal strings, and timestamps are UTC ISO 8601.

| Endpoint | Notes |
|---|---|
| `POST /generations` | `prompt` (≤4000), `size` 1024x1024\|1536x1024\|1024x1536, `quality` low\|medium\|high, `reference_images` (≤4 URLs, 765 tokens each), `metadata` (≤20 string keys). Returns 202 `queued`. Unknown params → 422. |
| `GET /generations/{id}` | Full object, or 404 for another account's generation. |
| `GET /generations` | `limit`, `status`, `cursor`. Newest first. Returns `data`, `has_more`, `next_cursor`. |
| `GET /pricing` | No auth. Rates per 1M tokens plus estimation constants. |
| `GET /usage` | `start_date`/`end_date` (≤92 days). Per-UTC-day tokens, counts, `cost_usd` (9dp), and `total`. |

- **Token rules:**
  - text ≈ `ceil(chars/4)` ±15%
  - output is fixed by quality and size: low 280/420, medium 1100/1650, high 4300/6450
  - reasoning varies: low 0–500, medium 200–2000, high 1000–8000
  - the API never returns a cost
- **Webhook headers:** `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature`.
- **Webhook delivery:**
  - retries are spaced 10s, 30s, 2m, 10m and 15m apart, for 6 attempts in total
  - redirects are not followed
  - events can be duplicated or arrive out of order
- **Images:** the `image_url` expires 24 hours after completion.
- **Test keywords** in the prompt (case-insensitive):

  | Keyword | Effect |
  |---|---|
  | `[blocked]` | 400 at submit |
  | `[overloaded]` | 503 at submit |
  | `[fail]` | Fails during processing |
  | `[succeed]` | Never fails randomly |
  | `[slow]` | Takes 60–90 seconds |
  | `[duplicate]` | Every webhook is sent twice |

  Even without keywords, some requests randomly fail, return 503, or duplicate events.

## Environment

```
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=            # separate key per environment (local vs prod)
PIXELFORGE_WEBHOOK_SECRET=     # whsec_… belonging to that key
DB_CONNECTION=pgsql
QUEUE_CONNECTION=database
```

`.env` is never committed. Local webhooks need a public tunnel, because Pixelforge rejects private and loopback URLs.

## Testing expectations

Every money and state rule has a test. Use `Http::fake()` for Pixelforge and never call the real API in tests. Required cases:

- **Cost math:** cost matches hand-computed fixtures, and the markup is exact with no rounding drift.
- **Estimates:** the estimate range contains the actual cost for representative inputs.
- **Signatures:** a valid signature is accepted. A tampered body, wrong secret, stale timestamp or missing header returns 400.
- **Duplicate events:** a duplicate event id is processed once and charged once.
- **Out-of-order events:** `succeeded` followed by `processing` leaves the row `succeeded`. A `failed` row ignores later events.
- **Early webhooks:** a webhook that arrives before `provider_id` is set matches the row by `metadata.local_id`.
- **Failures:** `failed` means an actual cost of 0.
- **Submission errors:** 429/503 releases the job with the `Retry-After` delay. A 400 content policy error fails the row with $0 and doesn't retry. A replay with the same idempotency key creates no duplicate.

## Deployment (this server)

- nginx vhost for `pixelforge-demo.johnpretsch.com` → `public/`, with a certbot TLS certificate. PHP runs through php8.4-fpm.
- Queue worker: the systemd unit `pixelforge-worker` (`deploy/pixelforge-worker.service`) runs `queue:work --tries=0` as `www-data`; supervisor isn't installed. After deploying code, run `systemctl restart pixelforge-worker`.
- Scheduler: a line in `www-data`'s crontab (`deploy/crontab`) runs `schedule:run` every minute. That crontab is shared with another site, so append to it and never replace it.
- Config is cached (`php artisan optimize`), so after any `.env` change run `sudo -u www-data php artisan config:cache`.
- Prod uses its own Pixelforge key, with the webhook URL `https://pixelforge-demo.johnpretsch.com/webhooks/pixelforge`.
- Leave several generations in prod for reviewers, including a succeeded one, a failed one, and one from a `[duplicate]` prompt.
- Reference configs live in `deploy/`.

## Working conventions

- Correctness first, polish last. Keep the required scope shippable before touching stretch goals.
- Keep Pixelforge HTTP calls in `PixelforgeClient` and state changes in `ApplyProviderState`. Don't bypass either.
- The submission needs a public GitHub repo, the deployed URL, and the Pixelforge account email.
- Commit only when asked.
