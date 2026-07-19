<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;

/** Builds a real RSA keypair + matching JWKS so social-login tests exercise real JWT verification. */
trait GeneratesJwks
{
    /** @return array{private: string, jwk: array<string, string>} */
    private function makeRsaKey(string $kid): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKeyPem);
        $details = openssl_pkey_get_details($resource);

        return [
            'private' => $privateKeyPem,
            'jwk' => [
                'kty' => 'RSA',
                'kid' => $kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
                'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
            ],
        ];
    }

    /** @param  array<string, mixed>  $claims */
    private function signToken(string $privateKeyPem, string $kid, array $claims): string
    {
        return JWT::encode($claims, $privateKeyPem, 'RS256', $kid);
    }
}
