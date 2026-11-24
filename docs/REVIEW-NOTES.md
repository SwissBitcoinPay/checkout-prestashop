# Notes for PrestaShop Addons' review

What a reviewer of the module `sattleme` may ask about, with where to read it. This file is the
repository's: it is not in the module's ZIP. Paths are the repository's (`sattleme/` is the
module's directory); line numbers are those of the commit this file comes with.

## The order is created before the payment, "Awaiting bitcoin payment"

When the customer places the order with the method, the module first asks the SDK for the invoice
of the payment, then creates the order with `PaymentModule::validateOrder`, in the module's own
pending state "Awaiting bitcoin payment" (`sattleme/src/Payment/Creator.php:117`). The payment
comes afterwards, in the customer's wallet; the order goes to "Payment accepted" only once the
SDK's own check finds the invoice paid (below).

This is what PrestaShop's own pending payment modules do. `ps_wirepayment` v3.0.0 (commit
`15087446c9ac3129983022d06b8ef4051a90a32c`, the version PrestaShop 9.2.0 and 9.2.1 ship), in
`controllers/front/validation.php`, line 63:

```php
$this->module->validateOrder($cart->id, (int) Configuration::get('PS_OS_BANKWIRE'), $total, $this->module->displayName, null, $mailVars, (int) $currency->id, false, $customer->secure_key);
```

creates the order "Awaiting bank wire payment" before any payment; v2.2.1 (commit
`436bf1b4fd178241cdc08a273ed56b78e9f42460`) has the same line 63. The one difference: an invoice
the SDK refuses (an amount out of the merchant's limits, a currency without price) creates no
order at all, and the customer chooses another method.

PrestaShop never cancels an unpaid order by itself; neither does the module. Its state's name and
the README say so; the merchant cancels an order left unpaid, as for a bank wire.

## The cart is the session's, of its customer (`id_cart` checked)

`sattleme/controllers/front/validation.php`, lines 42 to 77:

- a POST only (line 42), with the module's token for that cart (an HMAC of the cart's id under a
  secret of the shop, compared in constant time, line 51);
- the posted cart is the session's cart (`$context->cart`), of the session's customer, with its
  addresses, the module active (lines 66 to 67), as `ps_wirepayment`'s controller checks its cart
  (v3.0.0, lines 33 to 52);
- a cart that has an order already leads to that order's page only for its own customer (lines
  59 to 62);
- the customer is loaded and valid, and the method still offered for that cart (lines 75 to 76).

The payment page and its status endpoint open with the order's secure key, PrestaShop's own, as
the order confirmation page does: an order's number alone opens nothing.

## The amount comes from the server, and the payment from the SDK's own check

- The invoice's amount is the cart's total as PrestaShop computes it, on the server
  (`Cart::getOrderTotal`, `sattleme/src/Payment/Creator.php:102`); nothing the browser sends
  decides an amount, a currency, a reference or a status.
- A payment is recorded only when the SDK's `getStatus`, run on the shop's server, reports the
  invoice `paid` (`sattleme/src/Payment/Checker.php:92`, then `sattleme/src/Payment/Settler.php`,
  from line 158). The SDK checks the payment itself: the Lightning invoice's settlement at the
  merchant's Lightning address, an on-chain payment's 3 confirmations at one block explorer
  (mempool.space, or the merchant's own Bitcoin node when the merchant's app names one). The
  customer's browser only asks the shop to check.
- When the order changed after its invoice was made (its total, its currency, a credit slip), or
  was refunded, a payment that comes is recorded and the order goes to the module's state
  "Bitcoin payment to check", never "Payment accepted", for the merchant to settle. The module
  refuses to cancel an order while its payment may still come, and once bitcoin came for it (its
  hook `actionOrderStatusUpdate`, which PrestaShop runs before it changes the order).

## The SDK always gets the module's logger: its default `error_log` is never reached

The SDK (`sattleme/vendor/sattleme/checkout`, the pinned copy in the repository's `sdk/`) logs
its warnings to the logger it is given, or by default with PHP's `error_log`
(`sdk/src/Client.php:894`). The module builds its client in one place,
`sattleme/src/Sdk/SdkGateway.php:51`, always with its own logger (line 54:
`Logger::forSdk()`, which writes to PrestaShop's logs through `PrestaShopLogger::addLog`). The only
other `new Client` (`sattleme/src/Sdk/Environment.php:63`) checks the server's environment with an
empty key: the SDK refuses the key (`sdk/src/Client.php:116`) before it sets up a logger
(`sdk/src/Client.php:145`), so no client exists without the module's logger.

The validation checklist forbids `serialize()` and `unserialize()`, and asks that debug statements
be removed ("var_dump($a), dump($a), console.log"); it names no rule about `error_log`, which the
module's own code never calls (`tools/check-zip.php` refuses it there).

## What the ZIP holds, and how it is checked

- `tools/check-zip.php` checks the ZIP as the upload and the validator would: one directory
  `sattleme/`, an `index.php` in every directory, the licence header on every file of the module,
  the `_PS_VERSION_` guard in every PHP file (the embedded libraries' and Composer's autoloader's
  included), no call of `serialize()`, `unserialize()` or `eval()` in any PHP file, no debug
  output in the module's code, every PHP file parsing, every library prefixed
  (`Sattleme\PrestaShop\Vendor`).
- `dasprid/enum`'s `EnumMap`, which calls `serialize()` and `unserialize()`, is left out: the QR
  code encoder (`bacon/bacon-qr-code` 3.1.1) never uses it. What remains of those names are
  methods, not calls of PHP's functions: `__serialize()` and `__unserialize()` that throw (the
  SDK's client and secrets, dasprid/enum's enums refuse to be serialized), and the SDK's own
  `Event::serialize()`, a Nostr event's JSON (NIP-01).
- A root `.htaccess` denies the PHP files to the web (Apache 2.2 and 2.4); `header_csp.txt` lists
  the domains the module's server side reaches (the browser reaches none: the payment page loads
  nothing from another host, and its QR code is an image of a data URI made on the server).
- No template writes a value unescaped (no `nofilter`), and none links to another site: the
  order's page in the back office shows addresses and transactions as text.
- The background checks run from a front controller of the module (`controllers/front/cron.php`),
  which needs the shop's secret token, not from a `cron.php` file.
- The user guides are `docs/readme_en.pdf`, `readme_fr.pdf`, `readme_de.pdf` and `readme_it.pdf`,
  without a URL, an email address or a link.
