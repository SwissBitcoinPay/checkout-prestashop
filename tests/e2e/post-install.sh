#!/bin/sh
# Run by PrestaShop's Docker image once it has installed the shop (its /tmp/post-install-scripts/,
# tests/e2e/run.sh mounts this file there), as root, in the shop's directory:
#
#   - the module's test-only switch: the tests' fake of the SDK (tests/Fake, mounted at
#     /opt/sattleme/fake) in place of the SDK, its state in /opt/sattleme/state, shared with the
#     tests. A real shop never defines these constants;
#   - no e-mail sent (tests/shop/configure.php): the container has no mail server;
#   - the modules of PrestaShop's distribution that call their publishers' services turned off:
#     the test needs no network, and they slow every page down without it.
set -eu
cd /var/www/html

cat >> config/defines_custom.inc.php <<'PHP'

// The tests' fake of the sattle.me SDK (tests/Fake): never in a real shop.
define('_SATTLEME_TEST_GATEWAY_', '/opt/sattleme/fake/gateway.php');
define('_SATTLEME_TEST_FAKE_DIR_', '/opt/sattleme/state');
PHP

runuser -u www-data -- php -d memory_limit=1G /opt/sattleme/shop/configure.php /var/www/html
for name in klaviyopsautomation ps_accounts ps_ask_ai ps_checkout ps_distributionapiclient ps_eventbus \
  ps_facebook ps_googleanalytics ps_mbo ps_mcp_server ps_mcp_tools psshipping psxmarketingwithgoogle; do
  if [ -d "modules/$name" ]; then
    runuser -u www-data -- php -d memory_limit=1G bin/console prestashop:module disable "$name" > /dev/null 2>&1 || true
  fi
done
echo "sattle.me end-to-end test: the shop is ready"
