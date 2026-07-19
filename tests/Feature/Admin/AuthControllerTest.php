<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_renders(): void
    {
        $this->get('/admin/login')->assertOk()->assertViewIs('admin.login');
    }

    public function test_login_with_valid_credentials_redirects_to_dashboard(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);

        $response = $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);

        $response = $this->post('/admin/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);

        $response = $this->actingAs($admin)->post('/admin/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
