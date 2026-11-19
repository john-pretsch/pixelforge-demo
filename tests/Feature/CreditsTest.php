<?php

namespace Tests\Feature;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_start_with_no_credits(): void
    {
        $this->assertTrue(User::factory()->create()->balance()->isZero());
    }

    public function test_buying_a_pack_adds_exactly_that_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/generations')->post('/credits', ['pack' => '10.00'])
            ->assertRedirect('/generations')
            ->assertSessionHas('success', 'Added $10.00 in credits.');
        $this->actingAs($user)->post('/credits', ['pack' => '5.00']);

        $this->assertSame('15.000000000', (string) $user->balance()->toScale(9));
        $this->assertSame(2, CreditTransaction::where('type', CreditTransactionType::Purchase)->count());
    }

    public function test_only_the_fixed_packs_can_be_bought(): void
    {
        $user = User::factory()->create();

        foreach (['1000000', '-5.00', '5', ''] as $pack) {
            $this->actingAs($user)->post('/credits', ['pack' => $pack])->assertSessionHasErrors('pack');
        }

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_guests_cannot_buy_credits(): void
    {
        $this->post('/credits', ['pack' => '10.00'])->assertRedirect('/login');
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['name' => 'X', 'email' => $user->email, 'is_admin' => true]);

        $this->assertFalse($user->fresh()->is_admin);
    }
}
