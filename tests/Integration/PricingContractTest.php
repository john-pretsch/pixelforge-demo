<?php

namespace Tests\Integration;

use App\Pricing\PricingTable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;

/** Our pricing parser and validation assumptions still match the live /v1/pricing. */
#[Group('integration')]
class PricingContractTest extends LiveTestCase
{
    public function test_live_pricing_parses_and_matches_what_we_offer(): void
    {
        $raw = Http::get(self::BASE_URL.'/pricing')->throw()->json();

        // Rates must be decimal strings: Rates::fromArray rejects floats.
        foreach ($raw['rates'] as $name => $rate) {
            $this->assertIsString($rate, "rate {$name} is not a decimal string");
            $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $rate);
        }

        $table = PricingTable::fromArray($raw);

        $this->assertEqualsCanonicalizing(['low', 'medium', 'high'], $table->qualities());
        $this->assertEqualsCanonicalizing(['1024x1024', '1536x1024', '1024x1536'], $table->sizes());
        $this->assertSame(765, $table->tokensPerReferenceImage);
        $this->assertTrue($table->rates->output->isPositive());
    }

    public function test_client_pricing_is_what_the_form_validates_against(): void
    {
        $table = $this->client->pricing();

        foreach ($table->qualities() as $quality) {
            $this->assertArrayHasKey($quality, $table->outputTokens);
            $this->assertArrayHasKey($quality, $table->reasoningTokens);
        }
    }
}
