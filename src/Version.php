<?php

declare(strict_types=1);

namespace BillKit\Laravel;

/**
 * Single source of truth for the package version.
 *
 * ``composer.json`` deliberately carries no ``version`` field: Packagist takes
 * the version from the git tag, and a second copy is a second thing to forget.
 * This constant is that one copy.
 *
 * Releases are tagged ``vX.Y.Z`` on the public mirror
 * (github.com/billkit-eu/billkit-laravel), and its release workflow refuses to
 * publish a tag whose version does not match this constant, so the two cannot
 * drift.
 */
final class Version
{
    public const VERSION = '0.1.0';
}
