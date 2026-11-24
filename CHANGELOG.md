# Changelog

## 1.0.0 - 2026-11-24

First version, for PrestaShop 9.0 to 9.2 on PHP 8.2 to 8.5, on the sattle.me checkout SDK for PHP
1.0.0 (commit `f36b327`, checkout-spec 1.0.0), embedded in the module's ZIP with its namespace
prefixed.

- The payment method **Pay with bitcoin**: its integration key, checked when saved, stored
  encrypted and never shown again; how long an invoice can be paid; its title and description at
  checkout in each of the shop's languages; PrestaShop's restrictions of payment methods.
- "Place order" makes the invoice first, for a random reference, the cart locked in the database,
  then the order, "Awaiting bitcoin payment": an order the SDK refuses is never created. Compatible
  with PrestaShop 9.2's One Page Checkout.
- A payment page in the shop: the QR code (made on the server), the invoice and the address to
  copy, the amount, the time left, the rest of an on-chain underpayment, the confirmations;
  updated on its own, usable without JavaScript, opened by the order's secure key.
- Payments checked for the payment page, the order's page in the back office and, every minute,
  by the system's cron; an order completed on a `paid` status only, once, through PrestaShop's own
  path; an on-chain payment being confirmed in its own order state; a new invoice once one ended
  unpaid; underpayments, late payments and checks given up after 90 days noted on the order, each
  note saying what came, how when the status says it (a paid one's method), and what the invoice
  asked.
- The chain read from mempool.space, or from the merchant's own Bitcoin node when the merchant's
  app names one (the SDK's).
- No cancellation of an order ("Canceled", "Payment error") while its payment may still come, nor
  once bitcoin came for it: refused on the server, whoever asks, with the reason in the back
  office.
- Each payment's details on the order's page in the back office: reference, amount and rate,
  what was received (and how, when the status says it), address, transactions, each proof of
  payment with the payment hash it proves, last check, errors in plain words.
- PrestaShop's GDPR module: the customer's payments in their export; their erasure removes the
  deposit address and the transactions.
- Translations in French, German and Italian; user guides in English, French, German and Italian.
- Distributed through the repository's GitHub releases: `sattleme.zip` and its `SHA256SUMS`.
