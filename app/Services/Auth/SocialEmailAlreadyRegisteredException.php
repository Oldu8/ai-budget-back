<?php

declare(strict_types=1);

namespace App\Services\Auth;

use RuntimeException;

/** The provider asserts an email that already belongs to another account but isn't verified, so we can't safely link it. */
final class SocialEmailAlreadyRegisteredException extends RuntimeException {}
