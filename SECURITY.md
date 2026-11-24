# Security

This module lets a PrestaShop shop take Lightning and on-chain payments for a sattle.me merchant.
A flaw here could let an order be marked paid that is not, lose a paid order, let an attacker be
paid instead of the merchant, or leak the merchant's integration key: please report it
**privately**, never in a public issue, pull request or discussion.

## How to report

- On GitHub: **Security** tab of this repository → **Report a vulnerability** (private
  vulnerability reporting).
- Or by email: security@sattle.me, subject starting with `[security] checkout-prestashop`.

Please include what you found, the version of the module (`sattleme/config.xml`), of PrestaShop
and of PHP, and, when you can, the steps or the request that show it. Do not test against real
merchants' shops, funds or keys.

## What is in scope

- This repository's code: a way to have an order marked paid without a payment, to read or
  change another customer's payment, to make the module show a wrong invoice, to leak the
  integration key or another secret, or to run code or a request through the module's pages.
- A flaw of the SDK the module embeds goes to
  [checkout-php](https://github.com/sattleme/checkout-php/security); a flaw in the protocol
  itself (a rule of `SPEC.md` that, followed as written, is unsafe) to
  [checkout-spec](https://github.com/sattleme/checkout-spec/security), through the same channels.

We will acknowledge your report, keep you informed, and credit you when the fix is published,
unless you prefer otherwise.
