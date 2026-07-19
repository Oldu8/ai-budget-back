<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUserRefreshToken;
use App\Services\Auth\RefreshTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RefreshTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): RefreshTokenStore
    {
        return app(RefreshTokenStore::class);
    }

    public function test_issue_creates_a_usable_token(): void
    {
        $user = $this->makeAppUser();

        $plain = $this->store()->issue($user);

        $this->assertDatabaseCount('app_user_refresh_tokens', 1);
        $result = $this->store()->rotate($plain);
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_rotate_returns_null_for_unknown_token(): void
    {
        $this->assertNull($this->store()->rotate('does-not-exist'));
    }

    public function test_rotate_returns_null_when_owning_user_no_longer_resolves(): void
    {
        $user = $this->makeAppUser();
        $plain = $this->store()->issue($user);
        $user->delete(); // soft delete: AppUser::find() excludes it, row still referenced by the token

        $this->assertNull($this->store()->rotate($plain));
    }

    public function test_revoke_all_for_user_revokes_every_active_token(): void
    {
        $user = $this->makeAppUser();
        $this->store()->issue($user);
        $this->store()->issue($user);

        $this->store()->revokeAllForUser($user);

        $this->assertSame(0, AppUserRefreshToken::query()
            ->where('app_user_id', $user->id)
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_revoke_all_for_user_does_not_touch_other_users_tokens(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->store()->issue($user);
        $otherPlain = $this->store()->issue($other);

        $this->store()->revokeAllForUser($user);

        $result = $this->store()->rotate($otherPlain);
        $this->assertNotNull($result);
    }
}
