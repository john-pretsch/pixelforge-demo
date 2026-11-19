<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    private const USAGE_URL = 'https://pixelforge.test/v1/usage*';

    private User $admin;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18 12:00:00');

        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);

        // 2026-11-17: Alice ×2, Bob ×1 (one of Alice's at 23:59:59 UTC). 2026-11-18: Bob ×1.
        $this->succeeded($this->alice, '2026-11-17 09:00:00', '0.017070000', '0.021337500');
        $this->succeeded($this->alice, '2026-11-17 23:59:59', '0.100000001', '0.125000001');
        $this->succeeded($this->bob, '2026-11-17 10:00:00', '0.051512500', '0.064390625');
        $this->succeeded($this->bob, '2026-11-18 00:00:00', '0.211142500', '0.263928125');

        // Not billed: failed, and still in flight.
        Generation::factory()->for($this->alice)->create([
            'status' => GenerationStatus::Failed, 'provider_cost' => '0', 'price' => '0', 'completed_at' => '2026-11-17 11:00:00',
        ]);
        Generation::factory()->for($this->alice)->status(GenerationStatus::Processing)->create();
    }

    public function test_only_admins_can_see_the_report(): void
    {
        $this->get('/admin/report')->assertRedirect('/login');
        $this->actingAs($this->alice)->get('/admin/report')->assertForbidden();
    }

    public function test_reports_charged_cost_and_profit_per_utc_day_and_user(): void
    {
        $this->fakeUsage(['2026-11-17' => [3, '0.168582501'], '2026-11-18' => [1, '0.211142500']]);

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-17&to=2026-11-18')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Report')
                ->where('report.usage_available', true)
                ->where('report.totals.generations', 4)
                ->where('report.totals.charged.value', '0.474656251')
                ->where('report.totals.provider_cost.value', '0.379725001')
                ->where('report.totals.profit.value', '0.094931250')
                ->where('report.totals.margin_percent', '20.0')
                ->where('report.totals.pixelforge.matches', true)
                ->has('report.days', 2)
                // Newest day first.
                ->where('report.days.0.date', '2026-11-18')
                ->where('report.days.0.generations', 1)
                ->where('report.days.1.date', '2026-11-17')
                ->where('report.days.1.generations', 3)
                ->where('report.days.1.charged.value', '0.210728126')
                ->where('report.days.1.provider_cost.value', '0.168582501')
                ->where('report.days.1.profit.value', '0.042145625')
                ->where('report.days.1.pixelforge.matches', true)
                ->where('report.days.1.pixelforge.cost.value', '0.168582501')
                ->has('report.days.1.users', 2)
                ->where('report.days.1.users.0.name', 'Alice')
                ->where('report.days.1.users.0.generations', 2)
                ->where('report.days.1.users.0.provider_cost.value', '0.117070001')
                ->where('report.days.1.users.1.name', 'Bob')
                ->has('report.users', 2)
                ->where('report.users.0.name', 'Bob')
                ->where('report.users.0.charged.value', '0.328318750'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/usage')
            && $r['start_date'] === '2026-11-17' && $r['end_date'] === '2026-11-18');
    }

    public function test_a_one_nanodollar_difference_is_flagged(): void
    {
        $this->fakeUsage(['2026-11-17' => [3, '0.168582502'], '2026-11-18' => [1, '0.211142500']]);

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-17&to=2026-11-18')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.days.0.pixelforge.matches', true)
                ->where('report.days.1.pixelforge.matches', false)
                ->where('report.days.1.pixelforge.difference.value', '-0.000000001')
                ->where('report.totals.pixelforge.matches', false));
    }

    public function test_days_only_pixelforge_billed_are_shown_as_mismatches(): void
    {
        $this->fakeUsage(['2026-11-16' => [1, '0.017070000'], '2026-11-17' => [3, '0.168582501'], '2026-11-18' => [1, '0.211142500']]);

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-16&to=2026-11-18')
            ->assertInertia(fn (Assert $page) => $page
                ->has('report.days', 3)
                ->where('report.days.2.date', '2026-11-16')
                ->where('report.days.2.generations', 0)
                ->where('report.days.2.pixelforge.matches', false)
                ->has('report.days.2.users', 0));
    }

    public function test_still_renders_our_figures_when_pixelforge_is_unavailable(): void
    {
        Http::fake([self::USAGE_URL => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-17&to=2026-11-18')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.usage_available', false)
                ->where('report.days.1.pixelforge', null)
                ->where('report.totals.generations', 4));
    }

    public function test_defaults_to_the_last_30_days_and_caps_the_range_at_92_days(): void
    {
        $this->fakeUsage([]);

        $this->actingAs($this->admin)->get('/admin/report')
            ->assertInertia(fn (Assert $page) => $page->where('report.from', '2026-10-20')->where('report.to', '2026-11-18'));

        $this->actingAs($this->admin)->get('/admin/report?from=2026-01-01&to=2026-11-18')
            ->assertInertia(fn (Assert $page) => $page->where('report.from', '2026-08-19'));

        $this->actingAs($this->admin)->get('/admin/report?from=2026-11-18&to=2026-11-01')->assertSessionHasErrors('to');
    }

    private function succeeded(User $user, string $completedAt, string $cost, string $price): void
    {
        Generation::factory()->for($user)->status(GenerationStatus::Succeeded)->create([
            'provider_cost' => $cost,
            'price' => $price,
            'completed_at' => $completedAt,
        ]);
    }

    /** @param array<string, array{0: int, 1: string}> $days */
    private function fakeUsage(array $days): void
    {
        Http::fake([self::USAGE_URL => Http::response([
            'object' => 'usage',
            'data' => collect($days)->map(fn ($d, $date) => [
                'date' => $date, 'generations' => $d[0], 'cost_usd' => $d[1],
                'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0,
            ])->values()->all(),
        ])]);
    }
}
