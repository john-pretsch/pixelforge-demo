<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_grants_and_revokes_admin(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('pixelforge:make-admin', ['email' => 'ops@example.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('pixelforge:make-admin', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('pixelforge:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
