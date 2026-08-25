<?php

declare(strict_types=1);

namespace BillKit\Laravel;

/**
 * Single source of truth for the package version.
 *
 * The publish workflow asserts the pushed ``sdk-laravel-vX.Y.Z`` tag
 * matches ``self::VERSION`` before releasing, so this constant and the
 * git tag never drift.
 */
final class Version
{
    public const VERSION = '0.1.0';
}
