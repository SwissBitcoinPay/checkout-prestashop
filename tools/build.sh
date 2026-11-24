#!/bin/sh
# Builds the module as PrestaShop installs it, from the repository's files and composer.lock:
#
#   1. the runtime libraries of composer.lock (the pinned SDK, the QR code encoder and its one
#      dependency), without the development ones, in build/composer/;
#   2. prefixed with PHP-Scoper (namespace Sattleme\PrestaShop\Vendor), as checkout-php's
#      tools/prefix/build.sh prefixes and tests the SDK: two modules carrying two versions of a
#      library never collide;
#   3. build/sattleme/: the module (sattleme/, LICENSE), the prefixed libraries in vendor/ with
#      their licences, one authoritative class map for both (Composer's), the _PS_VERSION_ guard
#      in every PHP file (the libraries' from PHP-Scoper, Composer's from tools/guard.php), an
#      index.php in every directory;
#   4. build/sattleme.zip: sattleme/ at its root, as PrestaShop's module upload wants it, named as
#      PrestaShop Addons takes a module, its name and no version ("module_name.zip"), the version
#      being sattleme.php's (tools/zip.php: sorted entries, dated by the last commit, so the same
#      commit gives the same ZIP), then checked by tools/check-zip.php.
#
# Usage: sh tools/build.sh <php-scoper.phar>       (PHP 8.2+ and Composer on PATH, after
#                                                  `composer install`; COMPOSER_INSTALL_FLAGS adds
#                                                  flags to the runtime install, e.g. --prefer-source)
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCOPER="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
BUILD="$ROOT/build"
COMPOSER="${COMPOSER_BIN:-composer}"

VERSION="$(sed -n "s/^ *public const VERSION = '\([0-9.]*\)';$/\1/p" "$ROOT/sattleme/sattleme.php")"
if [ -z "$VERSION" ]; then
  echo "sattleme/sattleme.php names no VERSION" >&2
  exit 1
fi

# Our own build outputs only: build/ is ignored by git.
rm -rf "$BUILD/composer" "$BUILD/scoped" "$BUILD/sattleme" "$BUILD/sattleme.zip"
mkdir -p "$BUILD/composer"

# 1. The runtime libraries, from the lockfile as it is.
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$BUILD/composer/"
cp -R "$ROOT/sdk" "$BUILD/composer/sdk"
# shellcheck disable=SC2086
"$COMPOSER" install --working-dir="$BUILD/composer" --no-dev --no-interaction --no-progress \
  --no-scripts --no-plugins --classmap-authoritative ${COMPOSER_INSTALL_FLAGS:-}
# shellcheck disable=SC2016 # PHP's variables, not the shell's.
php -r '
$installed = json_decode((string) file_get_contents($argv[1]), true);
$names = array_map(static fn (array $package): string => $package["name"], $installed["packages"] ?? $installed);
sort($names);
$expected = ["bacon/bacon-qr-code", "dasprid/enum", "sattleme/checkout"];
if ($names !== $expected) {
    fwrite(STDERR, "The runtime libraries are " . implode(", ", $names) . ", not " . implode(", ", $expected) . "\n");
    exit(1);
}' "$BUILD/composer/vendor/composer/installed.json"

# 2. Prefixed.
php "$SCOPER" add-prefix --config="$ROOT/scoper.inc.php" --force --no-interaction --quiet
if grep -rlE --include='*.php' '^namespace (Sattleme\\Checkout|BaconQrCode|DASPRiD)' "$BUILD/scoped"; then
  echo "Files left unprefixed" >&2
  exit 1
fi

# 3. The module.
mkdir -p "$BUILD/sattleme/vendor"
cp -R "$ROOT/sattleme/." "$BUILD/sattleme/"
cp "$ROOT/LICENSE" "$BUILD/sattleme/LICENSE"
for package in sattleme/checkout bacon/bacon-qr-code dasprid/enum; do
  mkdir -p "$BUILD/sattleme/vendor/$package"
  cp -R "$BUILD/scoped/$package/src" "$BUILD/sattleme/vendor/$package/src"
  cp "$BUILD/scoped/$package/LICENSE" "$BUILD/sattleme/vendor/$package/LICENSE"
done
cat > "$BUILD/sattleme/composer.json" <<'JSON'
{
    "autoload": {
        "psr-4": {
            "Sattleme\\PrestaShop\\": "src/",
            "Sattleme\\PrestaShop\\Vendor\\Sattleme\\Checkout\\": "vendor/sattleme/checkout/src/",
            "Sattleme\\PrestaShop\\Vendor\\BaconQrCode\\": "vendor/bacon/bacon-qr-code/src/",
            "Sattleme\\PrestaShop\\Vendor\\DASPRiD\\Enum\\": "vendor/dasprid/enum/src/"
        }
    },
    "config": {
        "autoloader-suffix": "SattlemePrestaShop",
        "classmap-authoritative": true,
        "platform-check": false,
        "prepend-autoloader": false
    }
}
JSON
"$COMPOSER" dump-autoload --working-dir="$BUILD/sattleme" --classmap-authoritative --no-dev \
  --no-interaction --no-scripts --no-plugins --quiet
rm "$BUILD/sattleme/composer.json"
# Composer's list of what it installed: the module installs nothing, its class map is what it needs.
rm -f "$BUILD/sattleme/vendor/composer/installed.json"
php "$ROOT/tools/guard.php" "$BUILD/sattleme/vendor/autoload.php" "$BUILD/sattleme/vendor/composer/"*.php
php "$ROOT/tools/index-files.php" "$BUILD/sattleme"

# 4. The ZIP, checked.
php "$ROOT/tools/zip.php" "$BUILD/sattleme" "$BUILD/sattleme.zip" "$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || echo 1767225600)"
php "$ROOT/tools/check-zip.php" "$BUILD/sattleme.zip"
echo "Built $BUILD/sattleme.zip, version $VERSION"
