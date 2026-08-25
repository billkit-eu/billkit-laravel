#!/usr/bin/env bash
#
# Graft a path repository pointing at the sibling ../php checkout onto this
# package's composer.json, so dev and CI resolve billkit-eu/billkit-php from source
# instead of from Packagist.
#
# Why this is a script rather than a committed `repositories` block: the block
# used to live in composer.json, which meant dev-only wiring shipped to
# Packagist with every release. Consumers must resolve billkit-eu/billkit-php from
# Packagist like any other dependency, so the published manifest stays clean
# and the path repo is added here, at the moment it is needed.
#
# The version has to be stated explicitly. Composer derives a path package's
# version from its git tag, and this repo tags `sdk-php-vX.Y.Z`, which Composer
# cannot parse as semver. It is read from Version::VERSION so the two can never
# drift.
#
#   bin/link-php-sdk.sh            link ../php
#   bin/link-php-sdk.sh --unlink   restore the published manifest
#
# composer.json is modified in place. Do not commit it in the linked state;
# `--unlink` (or `git checkout -- composer.json`) puts it back.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" = "--unlink" ]; then
    composer config --unset repositories.billkit-php
    echo "[link-php-sdk] unlinked; composer.json is back to its published form"
    exit 0
fi

if [ ! -f ../php/src/Version.php ]; then
    echo "[link-php-sdk] error: ../php/src/Version.php not found." >&2
    echo "[link-php-sdk] This script only works inside the billstack monorepo," >&2
    echo "[link-php-sdk] where sdk/php sits next to sdk/laravel." >&2
    exit 1
fi

PHP_SDK_VERSION="$(php -r "require '../php/src/Version.php'; echo BillKit\\Version::VERSION;")"

composer config repositories.billkit-php \
    "{\"type\":\"path\",\"url\":\"../php\",\"options\":{\"symlink\":true,\"versions\":{\"billkit-eu/billkit-php\":\"${PHP_SDK_VERSION}\"}}}"

echo "[link-php-sdk] linked ../php as billkit-eu/billkit-php ${PHP_SDK_VERSION}"
echo "[link-php-sdk] run 'bin/link-php-sdk.sh --unlink' before committing composer.json"
