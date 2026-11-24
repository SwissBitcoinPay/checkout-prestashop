#!/bin/sh
# A PrestaShop shop for the integration and HTTP tests, made without Docker nor root (a developer's
# machine may have neither; the CI's GitHub-hosted runners run it the same way): MariaDB from its official binary archive, PrestaShop from its
# official release ZIP (the one its Docker images are built from), installed by its own command-line
# installer, the module from the built ZIP (build/sattleme.zip), PHP's built-in server.
# Each download's SHA-256 is pinned. The SDK is replaced by the tests' fake (tests/Fake) through the
# module's test-only switch, set in the shop's config/defines_custom.inc.php.
#
# Usage (from anywhere; PHP 8.2+ as $PHP, curl, tar):
#   sh tests/shop/shop.sh setup <9.0.3|9.1.5|9.2.1> [classic|hummingbird]
#                                              a new shop in $SHOP_DIR, started
#   sh tests/shop/shop.sh start                its database and web server
#   sh tests/shop/shop.sh stop
#   sh tests/shop/shop.sh module               the module's files from the ZIP again, the shop's
#                                              cache warmed by its console
#   sh tests/shop/shop.sh env                  the tests' environment variables
#   sh tests/shop/shop.sh sources <version> <directory>
#                                              PrestaShop's files only, not installed (the static
#                                              analysis)
#
# Environment: PHP (php), SHOP_DIR (build/shop), SHOP_PORT (8093), DB_PORT (33071),
# MARIADB_DIR (a MariaDB 11.4 directory to use instead of downloading one).
set -eu

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP:-php}"
SHOP_DIR="${SHOP_DIR:-$ROOT/build/shop}"
SHOP_PORT="${SHOP_PORT:-8093}"
DB_PORT="${DB_PORT:-33071}"
DOWNLOADS="$ROOT/build/downloads"
WWW="$SHOP_DIR/www"
DB_NAME=sattleme_shop
# MariaDB's socket, which nothing uses (the shop and the tests connect over TCP), but which it
# makes: a Unix socket's path has 107 bytes at most, a checkout's may be longer.
DB_SOCKET="$SHOP_DIR/db.sock"
if [ "${#DB_SOCKET}" -gt 100 ]; then
  DB_SOCKET="${TMPDIR:-/tmp}/sattleme-shop-$(printf '%s' "$SHOP_DIR" | cksum | cut -d ' ' -f 1).sock"
fi

MARIADB_VERSION=11.4.14
MARIADB_URL="https://archive.mariadb.org/mariadb-$MARIADB_VERSION/bintar-linux-systemd-x86_64/mariadb-$MARIADB_VERSION-linux-systemd-x86_64.tar.gz"
MARIADB_SHA256=6fa13ea11d07a53ae4795c8c3736dc5aa99c2ef22ece754cff07363dc1faba0d

# The shop's admin account (a throwaway test shop, reachable on 127.0.0.1 only).
ADMIN_EMAIL=merchant@example.com
ADMIN_PASSWORD=Sattleme-test-1

say() {
  echo "shop: $*" >&2
}

# download <url> <sha256> <file>: once, checked.
download() {
  if [ -f "$3" ] && echo "$2  $3" | sha256sum -c - > /dev/null 2>&1; then
    return
  fi
  mkdir -p "$(dirname "$3")"
  say "downloading $1"
  curl -fsSL --retry 3 -o "$3.part" "$1"
  echo "$2  $3.part" | sha256sum -c - > /dev/null
  mv "$3.part" "$3"
}

mariadb_dir() {
  if [ -n "${MARIADB_DIR:-}" ]; then
    echo "$MARIADB_DIR"
    return
  fi
  dir="$ROOT/build/mariadb-$MARIADB_VERSION"
  if [ ! -x "$dir/bin/mariadbd" ]; then
    archive="$DOWNLOADS/mariadb-$MARIADB_VERSION.tar.gz"
    download "$MARIADB_URL" "$MARIADB_SHA256" "$archive"
    mkdir -p "$dir.part"
    # The server, its client, its installer and its data files: not its tests nor its libraries.
    tar -xzf "$archive" -C "$dir.part" --strip-components=1 --wildcards \
      '*/bin/mariadbd' '*/bin/mariadb' '*/bin/my_print_defaults' '*/scripts/*' '*/share/*'
    rm -rf "$dir"
    mv "$dir.part" "$dir"
  fi
  echo "$dir"
}

# The server refuses to run as root unless told to (the CI runs as an ordinary user).
as_user() {
  if [ "$(id -u)" = 0 ]; then
    echo "--user=root"
  fi
}

db_start() {
  mariadb="$(mariadb_dir)"
  if [ -f "$SHOP_DIR/db.pid" ] && kill -0 "$(cat "$SHOP_DIR/db.pid")" 2> /dev/null; then
    return
  fi
  # as_user: one option, or none (unquoted on purpose).
  if [ ! -d "$SHOP_DIR/db" ]; then
    # shellcheck disable=SC2046
    "$mariadb/scripts/mariadb-install-db" --no-defaults --basedir="$mariadb" --datadir="$SHOP_DIR/db" \
      --auth-root-authentication-method=normal --skip-test-db --skip-name-resolve --force $(as_user) \
      > "$SHOP_DIR/db-install.log" 2>&1
  fi
  # shellcheck disable=SC2046
  nohup "$mariadb/bin/mariadbd" --no-defaults --basedir="$mariadb" --datadir="$SHOP_DIR/db" $(as_user) \
    --port="$DB_PORT" --bind-address=127.0.0.1 --socket="$DB_SOCKET" --pid-file="$SHOP_DIR/db.pid" \
    --log-error="$SHOP_DIR/db.log" --innodb-buffer-pool-size=128M --skip-name-resolve --max-connections=200 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci > /dev/null 2>&1 &
  for _ in $(seq 1 60); do
    if "$mariadb/bin/mariadb" --no-defaults -uroot -h127.0.0.1 -P"$DB_PORT" -e 'SELECT 1' > /dev/null 2>&1; then
      return
    fi
    sleep 1
  done
  cat "$SHOP_DIR/db.log" >&2
  say "MariaDB did not start"
  exit 1
}

web_start() {
  if [ -f "$SHOP_DIR/web.pid" ] && kill -0 "$(cat "$SHOP_DIR/web.pid")" 2> /dev/null; then
    return
  fi
  # Another server on the port would answer the tests in this shop's place.
  if curl -sS -o /dev/null --max-time 10 "http://127.0.0.1:$SHOP_PORT/" 2> /dev/null; then
    say "something already answers on 127.0.0.1:$SHOP_PORT: stop it, or set SHOP_PORT"
    exit 1
  fi
  # Several workers: the tests send requests at once (a page and its status, two checkouts).
  PHP_CLI_SERVER_WORKERS=8 nohup "$PHP" -d memory_limit=512M -d max_execution_time=120 -d display_errors=0 \
    -d log_errors=1 -d error_log="$SHOP_DIR/php-errors.log" -S "127.0.0.1:$SHOP_PORT" -t "$WWW" \
    > "$SHOP_DIR/web.log" 2>&1 &
  pid=$!
  echo "$pid" > "$SHOP_DIR/web.pid"
  for _ in $(seq 1 60); do
    if ! kill -0 "$pid" 2> /dev/null; then
      cat "$SHOP_DIR/web.log" >&2
      say "the web server stopped"
      exit 1
    fi
    if curl -fsS -o /dev/null --max-time 60 "http://127.0.0.1:$SHOP_PORT/index.php" 2> /dev/null; then
      return
    fi
    sleep 1
  done
  say "the web server did not start"
  exit 1
}

# children <pid>: the processes it started (Linux's /proc).
children() {
  for stat in /proc/[0-9]*/stat; do
    # After the command's name (which may hold spaces): its state, then its parent.
    parent="$(sed 's/.*) [A-Za-z] //; s/ .*//' "$stat" 2> /dev/null || true)"
    if [ "$parent" = "$1" ]; then
      basename "$(dirname "$stat")"
    fi
  done
}

stop() {
  for name in web db; do
    if [ -f "$SHOP_DIR/$name.pid" ]; then
      pid="$(cat "$SHOP_DIR/$name.pid")"
      # With the web server's workers (PHP_CLI_SERVER_WORKERS): PHP 8.2's outlive their parent,
      # and would go on answering on the port.
      pids="$pid $(children "$pid" | tr '\n' ' ')"
      for each in $pids; do
        kill "$each" 2> /dev/null || true
      done
      for _ in $(seq 1 30); do
        alive=""
        for each in $pids; do
          if kill -0 "$each" 2> /dev/null; then
            alive=1
          fi
        done
        if [ -z "$alive" ]; then
          break
        fi
        sleep 1
      done
      rm -f "$SHOP_DIR/$name.pid"
    fi
  done
}

# release <version>: the release ZIP of a PrestaShop version, downloaded once and checked; its
# path in $archive.
release() {
  case "$1" in
    9.0.3)
      release=9.0.3-3.0
      sha256=cdab7debdfdecd76bcfc6d98162850fa885f1a3acfd1ec424291b7434dc133f8
      ;;
    9.1.5)
      release=9.1.5-5.0
      sha256=37140cb77c03acf61b832f76893cd8fe1e304b0fc14fa5485cfea4a7bfaf3a72
      ;;
    9.2.1)
      release=9.2.1-6.1
      sha256=eb9d701e11baa5bf13f7afbd9179ef8653d6a99e50713cbf4ba85b73910c289b
      ;;
    *)
      say "unknown PrestaShop version $1 (9.0.3, 9.1.5, 9.2.1)"
      exit 2
      ;;
  esac
  archive="$DOWNLOADS/prestashop-$release.zip"
  download "https://api.prestashop-project.org/assets/prestashop-classic/$release/prestashop.zip" "$sha256" "$archive"
}

sources() {
  release "$1"
  rm -rf "$2"
  "$PHP" "$ROOT/tests/shop/extract.php" "$archive" "$2"
}

setup() {
  version="$1"
  theme="${2:-}"
  release "$version"
  zip="$ROOT/build/sattleme.zip"
  if [ ! -f "$zip" ]; then
    say "build the module first: $zip is missing"
    exit 1
  fi

  stop
  rm -rf "$SHOP_DIR"
  mkdir -p "$SHOP_DIR/fake" "$WWW"
  # The release's ZIP holds the shop's ZIP (prestashop.zip) or its directory (prestashop/).
  "$PHP" "$ROOT/tests/shop/extract.php" "$archive" "$WWW"

  db_start
  say "installing PrestaShop $version"
  # Switzerland: English, French, German and Italian; francs (CHF).
  # shellcheck disable=SC2086
  "$PHP" -d memory_limit=1G "$WWW/install/index_cli.php" --domain="127.0.0.1:$SHOP_PORT" \
    --db_server="127.0.0.1:$DB_PORT" --db_user=root --db_password= --db_name="$DB_NAME" --db_create=1 \
    --prefix=ps_ --language=en --country=ch --timezone=Europe/Zurich --name="sattle.me test shop" \
    --firstname=Test --lastname=Merchant --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" \
    --newsletter=0 --ssl=0 --rewrite=0 ${theme:+--theme=$theme} > "$SHOP_DIR/install.log" 2>&1 \
    || { cat "$SHOP_DIR/install.log" >&2; exit 1; }
  rm -rf "$WWW/install"

  say "installing the module from $zip"
  "$PHP" "$ROOT/tests/shop/extract.php" "$zip" "$WWW/modules" --as-is
  cat > "$WWW/config/defines_custom.inc.php" <<PHP
<?php
// The tests' fake of the SDK (tests/Fake): never in a real shop.
define('_SATTLEME_TEST_GATEWAY_', '$ROOT/tests/Fake/gateway.php');
define('_SATTLEME_TEST_FAKE_DIR_', '$SHOP_DIR/fake');
PHP
  "$PHP" -d memory_limit=1G "$WWW/bin/console" prestashop:module install sattleme > "$SHOP_DIR/module.log" 2>&1 \
    || { cat "$SHOP_DIR/module.log" >&2; exit 1; }
  # The tests' module that fails while reacting to a payment, when they say so (never in a real
  # shop): tests/shop/sattlemefailing.
  cp -R "$ROOT/tests/shop/sattlemefailing" "$WWW/modules/sattlemefailing"
  "$PHP" -d memory_limit=1G "$WWW/bin/console" prestashop:module install sattlemefailing >> "$SHOP_DIR/module.log" 2>&1 \
    || { cat "$SHOP_DIR/module.log" >&2; exit 1; }
  "$PHP" "$ROOT/tests/shop/configure.php" "$WWW"
  # The modules of PrestaShop's distribution that call their publishers' services (accounts,
  # marketing, analytics, error reporting) are turned off: the tests run offline, and one of them
  # installs its own PHP error handlers.
  for name in klaviyopsautomation ps_accounts ps_ask_ai ps_checkout ps_distributionapiclient ps_eventbus \
    ps_facebook ps_googleanalytics ps_mbo ps_mcp_server ps_mcp_tools psshipping psxmarketingwithgoogle; do
    if [ -d "$WWW/modules/$name" ]; then
      "$PHP" -d memory_limit=1G "$WWW/bin/console" prestashop:module disable "$name" >> "$SHOP_DIR/module.log" 2>&1 || true
    fi
  done
  # Disabling a module empties PrestaShop's cache (PrestaShop 9.0 has more of those modules to
  # disable): made again before any other process boots the shop.
  warm
  echo "$version" > "$SHOP_DIR/version"
  web_start
  say "PrestaShop $version on http://127.0.0.1:$SHOP_PORT/"
}

# PrestaShop's cache made by the shop's own console. Left empty, the next process to boot
# PrestaShop compiles its back office's container: the tests' (tests/bootstrap.php), with the
# repository's development autoloader, which has classes the shop lacks (Symfony's Stopwatch), and
# the web server's back office, loading that container, then answers 500.
warm() {
  "$PHP" -d memory_limit=1G "$WWW/bin/console" cache:warmup --env=prod --no-debug > "$SHOP_DIR/cache.log" 2>&1 \
    || { cat "$SHOP_DIR/cache.log" >&2; exit 1; }
}

# The module's files replaced by the current ZIP's (the database as it is: same version), then
# PrestaShop's cache made again.
module() {
  zip="$ROOT/build/sattleme.zip"
  rm -rf "$WWW/modules/sattleme" "$WWW/var/cache/prod" "$WWW/var/cache/dev"
  "$PHP" "$ROOT/tests/shop/extract.php" "$zip" "$WWW/modules" --as-is
  warm
}

env() {
  # The back office's directory (renamed at random by the installer): its init.php and themes.
  admin=""
  for dir in "$WWW"/admin*/; do
    if [ -z "$admin" ] && [ -f "${dir}init.php" ] && [ -d "${dir}themes" ]; then
      admin="$(basename "$dir")"
    fi
  done
  echo "export SATTLEME_SHOP_ROOT='$WWW'"
  echo "export SATTLEME_SHOP_URL='http://127.0.0.1:$SHOP_PORT'"
  echo "export SATTLEME_SHOP_ADMIN='$admin'"
  echo "export SATTLEME_SHOP_FAKE='$SHOP_DIR/fake'"
  echo "export SATTLEME_SHOP_ADMIN_EMAIL='$ADMIN_EMAIL'"
  echo "export SATTLEME_SHOP_ADMIN_PASSWORD='$ADMIN_PASSWORD'"
}

case "${1:-}" in
  setup)
    shift
    setup "$@"
    ;;
  start)
    db_start
    web_start
    ;;
  stop)
    stop
    ;;
  module)
    module
    ;;
  env)
    env
    ;;
  sources)
    sources "${2:?a version}" "${3:?a directory}"
    ;;
  *)
    echo "Usage: sh tests/shop/shop.sh setup <version> [theme] | start | stop | module | env | sources <version> <directory>" >&2
    exit 2
    ;;
esac
