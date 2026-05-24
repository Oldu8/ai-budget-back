<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies that all /api/* routes return JSON regardless of Accept header.
 *
 * Two mechanisms under test:
 * 1. shouldRenderJsonWhen() — forces JSON rendering for unmatched routes (404/405)
 *    and unhandled exceptions before middleware runs.
 * 2. ForceJsonResponse middleware — injects Accept: application/json for matched
 *    routes so downstream expectsJson() checks also return true.
 */
final class ApiJsonErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // shouldRenderJsonWhen — 404 on unmatched routes
    // -------------------------------------------------------------------------

    public function test_unknown_api_route_returns_json_404_without_accept_header(): void
    {
        $response = $this->get('/api/unknown-route-that-does-not-exist');

        $response->assertStatus(404);
        $message = $response->json('message');
        $this->assertIsString($message, 'message must be a string');
        $this->assertNotEmpty($message, 'message must not be empty');
        $this->assertStringContainsStringIgnoringCase('found', $message);
    }

    public function test_unknown_api_route_returns_json_404_with_wildcard_accept(): void
    {
        $response = $this->get('/api/unknown-route-that-does-not-exist', ['Accept' => '*/*']);

        $response->assertStatus(404);
        $this->assertStringContainsStringIgnoringCase('found', $response->json('message'));
    }

    // -------------------------------------------------------------------------
    // shouldRenderJsonWhen — 405 wrong HTTP method
    // -------------------------------------------------------------------------

    public function test_wrong_method_on_api_route_returns_json_405(): void
    {
        $response = $this->post('/api/health');

        $response->assertStatus(405);
        $this->assertStringContainsStringIgnoringCase('method', $response->json('message'));
    }

    // -------------------------------------------------------------------------
    // shouldRenderJsonWhen — unhandled 500
    // -------------------------------------------------------------------------

    public function test_unhandled_exception_in_api_route_returns_json_500(): void
    {
        Route::get('/api/test-throw-500', function () {
            throw new \RuntimeException('deliberate test error');
        });

        $response = $this->get('/api/test-throw-500');

        // APP_DEBUG=true in testing so the actual exception message is returned
        $response->assertStatus(500)
            ->assertJson(['message' => 'deliberate test error']);
    }

    // -------------------------------------------------------------------------
    // AuthorizationException — 403
    // -------------------------------------------------------------------------

    public function test_authorization_exception_returns_json_403_with_message(): void
    {
        Route::get('/api/test-throw-403', function () {
            throw new AuthorizationException('You cannot do this.');
        });

        $response = $this->get('/api/test-throw-403');

        $response->assertStatus(403)
            ->assertJson(['message' => 'You cannot do this.']);
    }

    public function test_authorization_exception_with_empty_message_returns_json_403(): void
    {
        Route::get('/api/test-throw-403-empty', function () {
            throw new AuthorizationException('');
        });

        $response = $this->get('/api/test-throw-403-empty');

        // AuthorizationException is converted to AccessDeniedHttpException before
        // custom callbacks run, so the message reflects the original (empty) value.
        // The important guarantee: 403 JSON response, not HTML.
        $response->assertStatus(403)
            ->assertJsonStructure(['message']);
    }

    // -------------------------------------------------------------------------
    // Validation errors — must keep 422 + errors key (shouldRenderJsonWhen)
    // -------------------------------------------------------------------------

    public function test_validation_error_returns_json_422_without_accept_header(): void
    {
        $response = $this->post('/api/auth/register', []);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('required', $response->json('message'));
        $this->assertArrayHasKey('email', $response->json('errors'));
        $this->assertArrayHasKey('password', $response->json('errors'));
    }

    public function test_validation_error_errors_key_contains_failed_fields(): void
    {
        $response = $this->post('/api/auth/register', []);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('required', $response->json('errors.email.0'));
        $this->assertStringContainsStringIgnoringCase('required', $response->json('errors.password.0'));
    }

    // -------------------------------------------------------------------------
    // /api/health — changed from plain text "OK" to JSON {"status":"ok"}
    // -------------------------------------------------------------------------

    public function test_health_route_returns_json_status_ok(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_health_route_content_type_is_json(): void
    {
        $response = $this->get('/api/health');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // 401 — Supabase middleware returns known message
    // -------------------------------------------------------------------------

    public function test_authenticated_json_request_still_works(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthorized']);
    }

    public function test_unauthenticated_request_without_accept_header_returns_json_401(): void
    {
        $response = $this->get('/api/me');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthorized']);
    }

    // -------------------------------------------------------------------------
    // Web/admin routes — shouldRenderJsonWhen must NOT affect them
    // -------------------------------------------------------------------------

    public function test_non_api_404_does_not_return_json(): void
    {
        $response = $this->get('/nonexistent-web-route');

        $contentType = $response->headers->get('Content-Type', '');
        $this->assertStringNotContainsString('application/json', $contentType);
    }
}
