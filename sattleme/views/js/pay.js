/**
 * sattle.me for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License that is bundled with this module in the file
 * LICENSE, also available at https://opensource.org/licenses/MIT
 *
 * @author    sattle.me
 * @copyright 2026 sattle.me
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/*
 * The payment page's script. The page works without it (its "Check again" button reloads it, the
 * server checks the invoice first); with it, the page counts the time left down, copies the
 * invoice and the address, and asks the status endpoint for news: it reloads when what it shows
 * changed, and goes to the order's confirmation once the order is paid. Nothing else: no other
 * host, no inline script, no library.
 */
(function () {
  'use strict';

  var root = document.getElementById('sattleme-pay');
  if (!root) {
    return;
  }

  var statusUrl = root.getAttribute('data-sattleme-status-url') || '';
  var version = root.getAttribute('data-sattleme-version') || '';
  var poll = parseInt(root.getAttribute('data-sattleme-poll') || '0', 10) || 0;
  var secondsLeft = parseInt(root.getAttribute('data-sattleme-seconds-left') || '0', 10) || 0;
  var timer = null;
  var busy = false;

  /** Whether a URL the status gives is of this shop. */
  function sameOrigin(url) {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (error) {
      return false;
    }
  }

  function schedule(seconds) {
    window.clearTimeout(timer);
    if (seconds > 0) {
      timer = window.setTimeout(ask, seconds * 1000);
    }
  }

  /** Asks the server for the payment's status: never two requests at once. */
  function ask() {
    if (busy || !statusUrl || !window.fetch) {
      return;
    }
    busy = true;
    var controller = window.AbortController ? new window.AbortController() : null;
    var abort = controller ? window.setTimeout(function () { controller.abort(); }, 30000) : null;
    window.fetch(statusUrl, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Status ' + response.status);
      }
      return response.json();
    }).then(function (status) {
      if (status && typeof status.redirect === 'string' && sameOrigin(status.redirect)) {
        window.location.assign(status.redirect);
        return;
      }
      if (!status || status.version !== version) {
        window.location.reload();
        return;
      }
      schedule(typeof status.poll === 'number' ? status.poll : 0);
    }).catch(function () {
      // The shop did not answer: try again a little later.
      schedule(Math.max(poll, 10));
    }).then(function () {
      busy = false;
      if (abort) {
        window.clearTimeout(abort);
      }
    });
  }

  // The time left, counted down from the server's figure.
  var countdown = root.querySelector('[data-sattleme-countdown]');
  if (countdown && secondsLeft > 0) {
    var deadline = Date.now() + secondsLeft * 1000;
    var tick = window.setInterval(function () {
      var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
      var seconds = left % 60;
      countdown.textContent = Math.floor(left / 60) + ':' + (seconds < 10 ? '0' : '') + seconds;
      if (left === 0) {
        window.clearInterval(tick);
        schedule(0);
        ask();
      }
    }, 1000);
  }

  // The copy buttons, shown only where the browser can copy.
  Array.prototype.forEach.call(root.querySelectorAll('[data-sattleme-copy]'), function (button) {
    var field = document.getElementById(button.getAttribute('data-sattleme-copy') || '');
    if (!field || !navigator.clipboard || !window.isSecureContext) {
      return;
    }
    var label = button.textContent;
    var copied = root.getAttribute('data-sattleme-copied') || label;
    button.hidden = false;
    button.addEventListener('click', function () {
      navigator.clipboard.writeText(field.value).then(function () {
        button.textContent = copied;
        window.setTimeout(function () { button.textContent = label; }, 2000);
      }, function () {
        field.focus();
        field.select();
      });
    });
  });

  schedule(poll);
}());
