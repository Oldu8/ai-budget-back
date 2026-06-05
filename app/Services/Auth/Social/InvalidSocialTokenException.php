<?php

declare(strict_types=1);

namespace App\Services\Auth\Social;

use RuntimeException;

/** Thrown when a provider id_token fails verification (bad signature, aud, iss, exp, …). */
final class InvalidSocialTokenException extends RuntimeException {}
