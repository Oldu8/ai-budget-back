<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ForceJsonResponseTest extends TestCase
{
    private function passThrough(Request $request): Request
    {
        $captured = null;
        (new ForceJsonResponse)->handle($request, function (Request $req) use (&$captured) {
            $captured = $req;

            return new Response;
        });

        return $captured;
    }

    public function test_injects_json_accept_when_header_is_missing(): void
    {
        $request = Request::create('/api/health');
        $request->headers->remove('Accept'); // Request::create() defaults this to a browser-like value

        $result = $this->passThrough($request);

        $this->assertSame('application/json', $result->headers->get('Accept'));
    }

    public function test_injects_json_accept_when_header_is_wildcard(): void
    {
        $request = Request::create('/api/health');
        $request->headers->set('Accept', '*/*');

        $result = $this->passThrough($request);

        $this->assertSame('application/json', $result->headers->get('Accept'));
    }

    public function test_leaves_an_explicit_accept_header_untouched(): void
    {
        $request = Request::create('/api/health');
        $request->headers->set('Accept', 'text/html');

        $result = $this->passThrough($request);

        $this->assertSame('text/html', $result->headers->get('Accept'));
    }
}
