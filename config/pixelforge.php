<?php

return [

    /*
    | Pixelforge API. Use a separate API key (and its webhook secret) per
    | environment: each key has its own webhook URL and usage report.
    */
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a webhook signature timestamp may differ from "now" (replay window).
    'signature_tolerance' => (int) env('PIXELFORGE_SIGNATURE_TOLERANCE', 300),

    // Customer price = provider cost × markup. Kept as a string: never a float.
    'markup' => (string) env('PIXELFORGE_MARKUP', '1.25'),

    // How long /v1/pricing is cached for estimates (seconds).
    // claude picked this value. probably ok?
    'pricing_cache_ttl' => 600, //10 minutes

    // HTTP client timeouts (seconds).
    'timeout' => 15,
    'connect_timeout' => 5,

];
