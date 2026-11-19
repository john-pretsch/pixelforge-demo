<?php

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const NOW = 1_800_000_000;

    private const BODY = '{"id":"evt_1","type":"ping"}';

    public function test_accepts_a_valid_signature(): void
    {
        $header = WebhookSignature::header(self::NOW, self::BODY, self::SECRET);

        $this->assertTrue(WebhookSignature::verify($header, self::BODY, self::SECRET, self::NOW));
    }

    public function test_accepts_within_the_tolerance_window(): void
    {
        $header = WebhookSignature::header(self::NOW - 299, self::BODY, self::SECRET);

        $this->assertTrue(WebhookSignature::verify($header, self::BODY, self::SECRET, self::NOW, 300));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $header = WebhookSignature::header(self::NOW, self::BODY, self::SECRET);

        $this->assertFalse(WebhookSignature::verify($header, str_replace('ping', 'pong', self::BODY), self::SECRET, self::NOW));
    }

    public function test_rejects_the_wrong_secret(): void
    {
        $header = WebhookSignature::header(self::NOW, self::BODY, 'whsec_other');

        $this->assertFalse(WebhookSignature::verify($header, self::BODY, self::SECRET, self::NOW));
    }

    public function test_rejects_stale_and_future_timestamps(): void
    {
        $stale = WebhookSignature::header(self::NOW - 301, self::BODY, self::SECRET);
        $future = WebhookSignature::header(self::NOW + 301, self::BODY, self::SECRET);

        $this->assertFalse(WebhookSignature::verify($stale, self::BODY, self::SECRET, self::NOW, 300));
        $this->assertFalse(WebhookSignature::verify($future, self::BODY, self::SECRET, self::NOW, 300));
    }

    public function test_rejects_a_signature_replayed_with_a_new_timestamp(): void
    {
        $signature = WebhookSignature::sign(self::NOW - 1000, self::BODY, self::SECRET);

        $this->assertFalse(WebhookSignature::verify('t='.self::NOW.',v1='.$signature, self::BODY, self::SECRET, self::NOW));
    }

    public function test_rejects_missing_or_malformed_headers(): void
    {
        foreach ([null, '', 'garbage', 't=abc,v1=00', 'v1=00', 't='.self::NOW] as $header) {
            $this->assertFalse(WebhookSignature::verify($header, self::BODY, self::SECRET, self::NOW), (string) $header);
        }
    }

    public function test_accepts_any_matching_v1_when_several_are_sent(): void
    {
        $valid = WebhookSignature::sign(self::NOW, self::BODY, self::SECRET);

        $this->assertTrue(WebhookSignature::verify('t='.self::NOW.',v1=deadbeef,v1='.$valid, self::BODY, self::SECRET, self::NOW));
    }

    public function test_rejects_when_secret_is_empty(): void
    {
        $header = WebhookSignature::header(self::NOW, self::BODY, '');

        $this->assertFalse(WebhookSignature::verify($header, self::BODY, '', self::NOW));
    }
}
