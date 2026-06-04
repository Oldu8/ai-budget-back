<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUser;
use App\Services\Auth\RefreshTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_password_with_correct_current(): void
    {
        $user = AppUser::create(['email' => 'cp@test.com', 'password' => 'oldpassword']);

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpassword', $user->fresh()->password));
    }

    public function test_wrong_current_password_returns_422(): void
    {
        $user = AppUser::create(['email' => 'cp@test.com', 'password' => 'oldpassword']);

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'wrong',
            'new_password' => 'newpassword',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password));
    }

    public function test_social_only_user_returns_409(): void
    {
        $user = AppUser::create(['email' => 'social@test.com', 'password' => null]);

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'whatever',
            'new_password' => 'newpassword',
        ])->assertStatus(409);
    }

    public function test_new_password_must_differ_and_be_long_enough(): void
    {
        $user = AppUser::create(['email' => 'cp@test.com', 'password' => 'oldpassword']);

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'oldpassword',
            'new_password' => 'oldpassword',
        ])->assertStatus(422)->assertJsonValidationErrorFor('new_password');

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'oldpassword',
            'new_password' => '123',
        ])->assertStatus(422)->assertJsonValidationErrorFor('new_password');
    }

    public function test_revokes_other_sessions_but_keeps_current(): void
    {
        $user = AppUser::create(['email' => 'cp@test.com', 'password' => 'oldpassword']);
        $store = app(RefreshTokenStore::class);

        $keep = $store->issue($user);
        $other = $store->issue($user);

        $this->apiAs($user)->putJson('/api/me/password', [
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'refresh_token' => $keep,
        ])->assertOk();

        // The presented (current device) token still works; the other is revoked.
        $this->assertNotNull($store->rotate($keep));
        $this->assertNull($store->rotate($other));
    }
}
