#!/bin/sh
# The end-to-end test on PrestaShop's own Docker image, as a merchant installs the module:
#
#   1. the image installs a shop (Switzerland, English), on a MariaDB container;
#   2. the merchant uploads the module's ZIP (build/sattleme.zip) in the back office's
#      Module Manager, which installs it (tests/e2e/install.php);
#   3. the http test suite (tests/Http): the merchant enters a key in the module's settings, a
#      customer places an order with the method, sees the payment page, and the order becomes
#      paid; the page's and the status endpoint's access, "Place order"'s checks. In a shop that
#      has PrestaShop 9.2's One Page Checkout, the opc suite too (tests/Opc): an order placed
#      through it.
#
# The SDK is replaced by the tests' fake (tests/Fake) through the module's test-only switch, which
# tests/e2e/post-install.sh sets in the shop's config/defines_custom.inc.php: the module never
# defines it. Needs Docker, and PHP 8.2+ with curl after `composer install` and tools/build.sh.
#
# Usage: sh tests/e2e/run.sh <prestashop image> [<mariadb image>]
#   e.g. sh tests/e2e/run.sh prestashop/prestashop:9.2.0-6.0-classic-8.5-apache@sha256:…
#
# PrestaShop's installer downloads its language pack: the containers need the internet. On a
# machine that reaches it only through a proxy on its own loopback, E2E_HOST_NETWORK=1 runs them in
# the machine's network (the shop on a free port of 127.0.0.1), with its HTTPS_PROXY and NO_PROXY,
# and E2E_CA_BUNDLE (a file) as their bundle of certificate authorities when the proxy needs one.
set -eu

PS_IMAGE="${1:?Usage: sh tests/e2e/run.sh <prestashop image> [<mariadb image>]}"
DB_IMAGE="${2:-mariadb:11.4.13@sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP:-php}"
ZIP="$ROOT/build/sattleme.zip"
if [ ! -f "$ZIP" ]; then
  echo "e2e: build the module first: $ZIP is missing" >&2
  exit 1
fi

# Our own names and files: nothing shared with another run.
RUN="sattleme-prestashop-e2e-$$"
mkdir -p "$ROOT/build"
WORK="$(mktemp -d "$ROOT/build/e2e.XXXXXX")"
mkdir -m 0777 "$WORK/state"
chmod 0777 "$WORK/state"
mkdir "$WORK/pre-install" "$WORK/post-install"
cp "$ROOT/tests/e2e/pre-install.sh" "$WORK/pre-install/sattleme.sh"
cp "$ROOT/tests/e2e/post-install.sh" "$WORK/post-install/sattleme.sh"
chmod 0755 "$WORK/pre-install" "$WORK/pre-install/sattleme.sh" "$WORK/post-install" "$WORK/post-install/sattleme.sh"
# The directories the container reads: everyone may read them (the CI's user is not the image's).
chmod -R a+rX "$ROOT/tests/Fake" "$ROOT/tests/shop"

cleanup() {
  status=$?
  if [ "$status" != 0 ]; then
    echo "e2e: failed; the shop's logs:" >&2
    docker logs --tail 200 "$RUN-shop" >&2 2>&1 || true
    docker exec "$RUN-shop" sh -c 'tail -n 100 /var/www/html/var/logs/*.log 2>/dev/null' >&2 || true
  fi
  # With their volumes: the database image's is anonymous, and would stay behind.
  docker rm -f -v "$RUN-shop" "$RUN-db" > /dev/null 2>&1 || true
  docker network rm "$RUN" > /dev/null 2>&1 || true
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT INT TERM

# Free ports of this machine: the shop's address (its domain is fixed at installation), and the
# database's in the machine's network.
free_port() {
  # shellcheck disable=SC2016 # PHP's variables, not the shell's.
  "$PHP" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo (int) substr((string) stream_socket_get_name($s, false), 10);'
}
PORT="$(free_port)"
URL="http://127.0.0.1:$PORT"
EMAIL=merchant@example.com
PASSWORD=Sattleme-test-1
SHOP_OPTIONS=""
if [ "${E2E_HOST_NETWORK:-0}" = 1 ]; then
  # The machine's network: the database on a port of its loopback, the shop's web server too.
  DB_PORT="$(free_port)"
  printf 'Listen 127.0.0.1:%s\n' "$PORT" > "$WORK/ports.conf"
  # shellcheck disable=SC2016 # Apache's variable, not the shell's.
  printf '<VirtualHost *:%s>\n\tDocumentRoot /var/www/html\n\tErrorLog ${APACHE_LOG_DIR}/error.log\n\tCustomLog ${APACHE_LOG_DIR}/access.log combined\n</VirtualHost>\n' "$PORT" > "$WORK/site.conf"
  chmod 0644 "$WORK/ports.conf" "$WORK/site.conf"
  DB_NETWORK="--network host"
  DB_ARGS="--port=$DB_PORT --bind-address=127.0.0.1"
  SHOP_OPTIONS="--network host -e DB_SERVER=127.0.0.1 -e DB_PORT=$DB_PORT -v $WORK/ports.conf:/etc/apache2/ports.conf:ro -v $WORK/site.conf:/etc/apache2/sites-enabled/000-default.conf:ro -e HTTPS_PROXY -e https_proxy -e NO_PROXY -e no_proxy"
  if [ -n "${E2E_CA_BUNDLE:-}" ]; then
    SHOP_OPTIONS="$SHOP_OPTIONS -v $E2E_CA_BUNDLE:/etc/ssl/certs/ca-certificates.crt:ro"
  fi
else
  docker network create "$RUN" > /dev/null
  DB_NETWORK="--network $RUN"
  DB_ARGS=""
  SHOP_OPTIONS="--network $RUN -p 127.0.0.1:$PORT:80 -e DB_SERVER=$RUN-db"
fi

echo "e2e: $PS_IMAGE, its database $DB_IMAGE, at $URL"
# shellcheck disable=SC2086
docker run -d --name "$RUN-db" $DB_NETWORK -e MARIADB_ROOT_PASSWORD=admin -e MARIADB_DATABASE=prestashop \
  "$DB_IMAGE" $DB_ARGS > /dev/null
# shellcheck disable=SC2086
docker run -d --name "$RUN-shop" $SHOP_OPTIONS \
  -e DB_PASSWD=admin -e DB_NAME=prestashop -e PS_INSTALL_AUTO=1 \
  -e PS_DOMAIN="127.0.0.1:$PORT" -e ADMIN_MAIL="$EMAIL" -e ADMIN_PASSWD="$PASSWORD" -e PS_FOLDER_INSTALL=install-e2e \
  -e PS_COUNTRY=CH -e PS_LANGUAGE=en -e PS_ENABLE_SSL=0 \
  -v "$ROOT/tests/Fake:/opt/sattleme/fake:ro" -v "$ROOT/tests/shop:/opt/sattleme/shop:ro" \
  -v "$WORK/state:/opt/sattleme/state" -v "$WORK/pre-install:/tmp/pre-install-scripts:ro" \
  -v "$WORK/post-install:/tmp/post-install-scripts:ro" \
  "$PS_IMAGE" > /dev/null

# The image installs the shop, runs the post-install script, then starts its web server: a busy
# machine takes minutes. Its installer runs from install-e2e/ (PS_FOLDER_INSTALL): PrestaShop 9.2's
# removes install/ itself once done, and the image's script, which removes it after, then fails.
echo "e2e: installing the shop"
deadline=$(( $(date +%s) + 1500 ))
until docker logs "$RUN-shop" 2>&1 | grep -q 'sattle.me end-to-end test: the shop is ready' \
  && curl -fsS --noproxy '*' -o /dev/null --max-time 60 "$URL/index.php"; do
  if [ "$(date +%s)" -gt "$deadline" ] || [ "$(docker inspect -f '{{.State.Running}}' "$RUN-shop")" != true ]; then
    echo "e2e: the shop did not start" >&2
    exit 1
  fi
  sleep 5
done

# The back office's directory: PrestaShop 9.2's installer renames admin/ itself, at random (the
# image's PS_FOLDER_ADMIN, which renames it before, makes 9.2's installer fail), earlier versions
# leave it: the directory of its init.php and its themes.
ADMIN="$(docker exec "$RUN-shop" sh -c 'for d in /var/www/html/admin*/; do if [ -f "${d}init.php" ] && [ -d "${d}themes" ]; then basename "$d"; fi; done' | head -1)"
if [ -z "$ADMIN" ]; then
  echo "e2e: the shop has no back office directory" >&2
  exit 1
fi
export SATTLEME_SHOP_URL="$URL" SATTLEME_SHOP_ADMIN="$ADMIN" SATTLEME_SHOP_ADMIN_EMAIL="$EMAIL" \
  SATTLEME_SHOP_ADMIN_PASSWORD="$PASSWORD" SATTLEME_SHOP_FAKE="$WORK/state"
unset SATTLEME_SHOP_ROOT
echo "e2e: the merchant uploads $ZIP"
"$PHP" "$ROOT/tests/e2e/install.php" "$ZIP"
SUITES=http
if docker exec "$RUN-shop" test -d /var/www/html/modules/ps_onepagecheckout; then
  SUITES=http,opc
fi
echo "e2e: the merchant's settings, a customer's order and its payment (the suites $SUITES)"
"$PHP" "$ROOT/vendor/bin/phpunit" --configuration "$ROOT/phpunit.xml.dist" --testsuite "$SUITES"
