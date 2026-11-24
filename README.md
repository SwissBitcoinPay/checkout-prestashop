# sattle.me for PrestaShop

A payment module for [PrestaShop](https://www.prestashop-project.org): customers pay their orders
in bitcoin, in Lightning or on-chain, and the bitcoin goes straight to the merchant's
[sattle.me](https://sattle.me) wallet. It is built on the
[sattle.me checkout SDK for PHP](https://github.com/sattleme/checkout-php), embedded in the
module, which makes and checks the invoices: no sattle.me server, no API key of a third party, and
the integration key can make invoices that pay the merchant, never spend the funds.

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Payments and orders](#payments-and-orders)
- [Background checks](#background-checks)
- [Security](#security)
- [Privacy](#privacy)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Releases](#releases)
- [License](#license)

## How it works

1. At checkout, the customer chooses **Pay with bitcoin** (or the title the merchant gives the
   method). When they place their order, the module asks the SDK for the invoice of the payment
   first: a Lightning invoice, a deposit address of the merchant's wallet, or both, depending on
   the amount (see [the SDK](https://github.com/sattleme/checkout-php#on-chain-payments)). Only
   then does PrestaShop create the order, "Awaiting bitcoin payment": an order the SDK refuses is
   never created, and the customer chooses another method.
2. The customer lands on a payment page of the shop, in its theme and language: one QR code of
   the invoice (BIP-21 with `lightning=`, or `lightning:`), a button that opens their wallet with
   it, the Lightning invoice and the address to copy, the amount (in sats, in bitcoin and in the
   order's currency), and the time left to pay.
3. The page follows the payment on its own. Once the SDK reports it **paid**, the module records
   the payment in PrestaShop and the order is "Payment accepted": PrestaShop sends its email and
   makes its invoice, as for any payment.

The page is rendered on the server (the QR code is an SVG made there, shown as an image of a data
URI: no request for it); its small script, a file of the module, only counts down, copies, and
reloads the page when the payment moved on. Without JavaScript, the page offers a button to check
the payment.

The order's confirmation page and its details in the customer's account (or the guest's order
tracking) say where the payment is, and lead back to the payment page while there is something to
do there.

## Requirements

- PrestaShop **9.0**, **9.1** or **9.2**. PrestaShop publishes patch releases for its latest minor
  version only (9.2 at this date: its branch 9.2.x and 8.2.x are the ones PrestaShop maintains);
  the module supports the three, which shops run. Its tests run in PrestaShop 9.0.3, 9.1.5 and
  9.2.1, the newest patch release of each (the end-to-end test in PrestaShop's Docker images of
  9.0.3, 9.1.5 and 9.2.0), and PrestaShop's interfaces the module calls were checked in their
  sources. PrestaShop 9.2's One Page Checkout
  works too: the tests place an order through it. PrestaShop 8 is not supported: its PHP (8.1 at
  most) cannot run the SDK.
- PHP **8.2** or later, **64-bit**, as PrestaShop supports it (9.0: up to 8.4; 9.1 and 9.2: up to
  8.5), with `bcmath`, `iconv`, `openssl` (with the secp256k1 curve and ChaCha20: any usual
  OpenSSL 1.1.1 or 3.x), `json`, `hash` and `pcre`. The module checks them: on a server that lacks
  something (PrestaShop 9 also runs on PHP 8.1), it refuses to install, and says what is missing.
- MySQL or MariaDB, as PrestaShop has it: the module locks a cart with the database's named locks
  (`GET_LOCK`).
- The system's **cron**, calling the module's URL every minute (see
  [Background checks](#background-checks)).
- A merchant account of sattle.me, and an **integration** created in its app.
- **Outbound connections** from the shop's server (never from the customer's browser), direct:
  the SDK does not use a proxy.
  - The merchant's **Nostr relays**, over WebSocket (TLS): those the integration key names, or
    the merchant's app.
  - The merchant's **Lightning address** (breez.tips for a sattle.me account) and the URLs its
    provider gives (its callback, its verify URLs), over HTTPS.
  - The **rate sources**, one after the other until one answers: CoinGecko, mempool.space,
    Coinbase, Kraken, OpenNode.
  - For an invoice with a deposit address, the **explorer**: **mempool.space**, or the merchant's
    own **Bitcoin node** when the merchant's sattle.me app names one (the server then reads the
    chain from it, mempool.space as its fallback unless the merchant chose none; a node on the
    merchant's own network, over `http`, answers only a server on that network); and
    **api.blockcypher.com** for a transaction no check saw in time.

  Every request, and the handshake of each WebSocket, says `User-Agent: sattleme-Checkout/1`.
  See the SDK's [Requirements](https://github.com/sattleme/checkout-php#requirements).

## Installation

The module is distributed through **PrestaShop Addons** only (PrestaShop's marketplace):

1. Get **sattle.me** on PrestaShop Addons: once you have it, its ZIP, `sattleme.zip`, is in your
   Addons account.
2. In PrestaShop's back office, **Modules → Module Manager → Upload a module**, and drop the ZIP:
   PrestaShop installs it (a shop connected to its Addons account can also install it from the
   back office's marketplace). The ZIP holds the module and its libraries (the SDK and a QR code
   encoder), their namespaces prefixed, so that another module carrying other versions of them
   never collides with it.

To update the module, install its new version from PrestaShop Addons the same way. Uninstalled, the module
removes its settings and its key; it keeps the records of its payments and its order states,
which orders refer to: "Awaiting bitcoin payment" and "Bitcoin payment being confirmed" hidden
from the lists, "Bitcoin payment to check" as it is if an order is or was in it (else it is
deleted; PrestaShop itself leaves a module's order states as they are). A new installation takes
them back.

## Configuration

1. In the sattle.me app, create an integration for your shop and copy its secret key
   (`sattleme_ck_…`).
2. In the back office, **Modules → Module Manager**, find **sattle.me** and click **Configure**.
3. Enter the key. It is checked when you save it: the SDK refuses what is not an integration key,
   then reads the integration on the merchant's relays, so that the key of an integration removed
   in the app, or of one the relays do not know, is refused. Once saved, it is never shown again:
   leave the field empty to keep it.
4. Turn on **Accept bitcoin payments**.
5. **Time to pay**: how long the customer has to pay an invoice, 1 to 60 minutes (15 by default).
6. Optionally, the **title** and the **description** at checkout, in each of the shop's languages.
7. Set up the system's cron with the line the page shows (see
   [Background checks](#background-checks)).

PrestaShop's own restrictions of a payment method apply as well (**Payment → Preferences**:
currencies, countries, customer groups, carriers). PrestaShop has no minimum or maximum total for
a payment method; the merchant's Lightning address has its own limits, checked when an order is
placed.

Change the key only while no bitcoin payment is pending: payments are checked with the key set at
the time of the check, and an invoice made under another integration is not that one's. In a
multistore installation, each shop has its settings and its key (or the ones set for all shops),
and its cron URL: the settings page lists every shop's (see
[Background checks](#background-checks)).

sattle.me has no test network: payments are real bitcoin payments, to the merchant's wallet.

## Payments and orders

| What the SDK reports | What the module does |
| --- | --- |
| An invoice was made | PrestaShop creates the order, **Awaiting bitcoin payment**; the customer goes to the payment page. |
| `open` | Waits. A new invoice for the order only once this one ended unpaid. |
| `open` with `received` (on-chain, short) | The page shows what came and the rest to pay, as a Lightning invoice the SDK asks for (on-chain too from 1,000 sats). |
| `unconfirmed` | The order goes to **Bitcoin payment being confirmed**; waits for 3 confirmations (about 30 minutes). The customer's pages say the payment came in time and its on-chain transaction is being confirmed: nothing final, since it may still be replaced or dropped. |
| `paid` (Lightning, on-chain, or both), at the price the order still owes | PrestaShop records the payment and the order is **Payment accepted**: its email, its invoice. |
| `paid`, at another price than the order owes now (its total raised or lowered, its currency changed, another payment or a credit slip recorded since the invoice was made) | PrestaShop records the payment, in the invoice's currency, and the order goes to **Bitcoin payment to check**, never "Payment accepted": a note gives what came and how (in Lightning, the invoice's sats; on-chain, or both, what the check received), the invoice's sats and price, and what the order owes now, each with its currency. The customer's pages say the payment was received and the shop will contact them. The merchant settles the difference, then sets the order's state. |
| `paid`, the order refunded meanwhile (or canceled while the module was disabled: see [No cancellation](#payments-and-orders)) | The same: the payment recorded, the order in **Bitcoin payment to check**, a note giving the sats received and the invoice's price, which reached the wallet. The merchant reinstates the order, or refunds the customer. Taken out of "Canceled" or "Payment error", an order takes its products from the stock again (PrestaShop's rule for any such change). |
| `paid` with `received` other than the invoice's `sats` (on-chain, and the rest's invoices) | Also a note: what came, less than the amount (the merchant's tolerance, or the merchant's acceptance of what came, accepted it) or more (what reached the wallet above it). The note names both reasons for less: the status does not tell which. |
| `expired` | The invoice ended unpaid; the order stays awaiting payment, and its page offers the customer a new invoice. |
| `expired` with `received` above 0 | Also a note on the order: what came in time against what the invoice asked, which reached the wallet, to settle with the customer by hand; not how it came, which an `expired` status does not say (a note says how only when the status names its method: `paid`). Final: never checked again, even in the rare case where the merchant accepted the underpayment in the app ([Known limits](#known-limits)). |

- **Only a `paid` status completes an order**, from the SDK's check of the invoice, on the
  server: never because the customer's browser says so. It completes it once, through
  PrestaShop's own path, whichever check sees it first: the payment recorded with the payment
  reference as its transaction id, then the order's state changed to "Payment accepted" ("On
  backorder (paid)" for an order on backorder), which sends PrestaShop's email, makes its invoice
  and runs the other modules' hooks. Should PrestaShop fail to take it (the database, another
  module failing while it reacts to the payment), the order is not changed, the customer's pages
  say the payment was received and is being recorded, and never show the invoice to pay again
  (an invoice is shown to pay only while its last status is open), and the next checks record it,
  once.
- **What an order owes.** When its invoice is found paid, its price (an amount and a currency,
  as the SDK made the invoice) is compared, exactly, with what the order still owes then, in its
  currency, as PrestaShop records it:
  - its total, taxes included (`total_paid_tax_incl`), which the back office's changes change: a
    product added, removed or changed, a voucher added (a voucher used at checkout lowered it
    already), the currency changed (its amounts converted);
  - of every order of the cart: PrestaShop splits a cart whose products go by different carriers
    into several orders, of one reference, paid by one payment, and so by one invoice;
  - less the payments recorded on that reference otherwise (another method, a part paid in cash),
    in the order's currency (a payment in another currency, which PrestaShop converts through its
    rates, cannot be told exactly: the order is then to check);
  - less its credit slips (the products and shipping they refund, taxes included, as the order's
    page shows them): PrestaShop issues one only once the order has a payment, or was paid.

  Anything but the same amount in the same currency, a lowered total included, puts the order in
  "Bitcoin payment to check". A new invoice of an order whose last one ended unpaid is made for
  what the order owes then.
- **One payment, one reference.** Each attempt to pay gets a random reference (128 bits),
  `prestashop-<cart>-<32 hex characters>`, stored before the SDK is asked for its invoice, and
  never used for another one: no deleted test order, restored backup or other installation can
  share it. A try that follows a failed one (a timeout, a worker stopped) asks for the same
  reference: the SDK then hands out the invoice it may have made meanwhile, shown only once a
  check found it payable, or reports it paid, and the order is paid.
- **The cart locked.** The invoice and the order are made with the cart locked in the database
  (a named lock of MySQL or MariaDB, released whatever way a request ends): two requests at once
  (a double click, two workers) get one invoice and one order.
- **One payable invoice per order.** A Lightning invoice cannot be revoked: while an invoice of
  the order can still be paid, or while a payment of it is being confirmed, the customer gets no
  other invoice, so that the order is never paid twice. A new one is offered once the last one
  ended unpaid.
- **Deadlines.** PrestaShop never cancels an unpaid order, nor frees its products, by itself (its
  stock comes back only when an order goes to "Canceled" or "Payment error", by the merchant or a
  module): an invoice lasts the time to pay, and a payment made in time completes the order
  however long its confirmations took. While an on-chain payment is being confirmed, the order is
  in its own state, "Bitcoin payment being confirmed", not "Awaiting bitcoin payment": a rule that
  cancels orders left awaiting payment (a module's, a merchant's) leaves it alone. If the merchant
  changes an order meanwhile (or refunds it), its invoice stays payable until its end and its
  checks go on: a payment that comes all the same is recorded, and the order goes to "Bitcoin
  payment to check" with a note (or, paid otherwise already, keeps its state with a note), for the
  merchant to settle.
- **No cancellation while bitcoin may come, or once it came.** The module refuses to put an order
  in "Canceled" or "Payment error" (both give its products back to the stock) while its invoice can
  still be paid, its payment is being confirmed or its invoice's final status is not known yet, and
  once bitcoin came for it: paid, or an underpayment, even one whose invoice ended (the sats are in
  the merchant's wallet: the merchant settles with the customer, refunds from the wallet, and
  records the refund, a credit slip or the state "Refunded"). The refusal holds whoever asks:
  the order's page, the orders' list and its bulk action, the webservice, another module; the back
  office shows why, naming the order by its reference. It is decided under the cart's lock, which
  the making of a new invoice holds from its check of the order to the invoice stored: a
  cancellation meanwhile waits for it, then is refused (or, the lock held more than 25 seconds,
  refused as an invoice being made). A cancellation let through marks the cart, under that lock, as
  being canceled for 60 seconds: PrestaShop writes the order's new state once every module reacted
  to the change, and a new invoice asked meanwhile is refused (then the order is canceled). No lock
  is held while the other modules react: one that aborts the change leaves the mark only, a new
  invoice possible again 60 seconds later at most. What remains: a change whose other modules
  take more than 60 seconds to react, before PrestaShop writes the state, may see a new invoice
  made meanwhile, shown as payable until the state is written; the order is then canceled with an
  invoice that can be paid (a payment of it goes to "Bitcoin payment to check").
  An invoice open on an order canceled anyhow is never shown as payable: the pages read the
  order's state at each display. PrestaShop runs the module's hook
  (`actionOrderStatusUpdate`) before it changes anything of the order, and has no way to leave
  these states out of the back office's list for one order: the states stay listed, and the
  module refuses them on the server. PrestaShop's change of several orders' state at once (the
  orders' list, its bulk action) stops at the first order refused: the orders before it in the
  selection are changed, that one and the ones after it are left as they are (PrestaShop has no
  way to refuse one order of it and go on with the others). The refusal's message says so as a
  condition ("If you changed several orders at once, …"): PrestaShop gives the module's hook the
  same for one order and for several. "Cancel products" (the order's page)
  is refused too, before PrestaShop removes a product or gives its stock back: its form handler
  runs the module's hook `actionBeforeUpdateCancelProductFormHandler` first. The refunds of an
  order paid (partial, standard, a return) are never refused: they are the merchant's to record.
  Once an invoice ended unpaid with nothing received, the merchant cancels the order as any other.
  PrestaShop never lets a customer cancel an order.
- **Underpayments.** When less than the amount comes on-chain in time, the page shows the rest,
  as a Lightning invoice the SDK asks for. The merchant may accept an on-chain payment a little
  short of the amount (its tolerance, set in the sattle.me app): the SDK then reports it `paid`.
  So it does when the merchant accepts, in the sattle.me app, what an underpaid order received
  (the merchant's acceptance): being confirmed, then `paid`, the order goes to "Payment accepted",
  with the note, never to "Bitcoin payment to check" for the difference alone.
- **Refunds** are never automatic: a Lightning payment can only be refunded to an invoice of the
  customer's, an on-chain one to an address of theirs, which the shop never asks for. Refund the
  customer from the wallet, then record the refund in PrestaShop (a credit slip, the order's
  state).
- The merchant sees on the order's page in the back office each payment attempt: its reference,
  amount and exchange rate, how it was paid, what was received in time and, when the status says
  it (a `paid` status's method), how (on-chain, or on-chain and in Lightning for the rest of an
  underpayment), the address, the transactions (as text, to copy into
  a block explorer: the module links to no other site; an underpayment's status lists none, and the
  page then says to look the address up), each proof of payment (preimage) with the payment hash it
  proves (its SHA-256), the last check (and that it failed, if it did) and the next one, its error
  in plain words, and "Ended underpaid" when an invoice ended with less than its amount. The
  order's internal note holds what the merchant settles by hand. Its dates and times, and the
  settings page's last run of the checks, are in the shop's time zone (**International →
  Localization → Configuration**, **Time zone**), which PrestaShop sets for PHP.

## Background checks

The SDK has no webhooks: payments are checked by the module, on the shop's server.

- While the customer's payment page is open: the page asks every 3 seconds, and the server checks
  the invoice at most every 6 seconds, however many pages ask. Opening the order's page in the
  back office checks it too.
- In the background, by a URL of the module that the system's cron calls every minute, with the
  shop's secret token. The module's settings page shows the URL and a crontab line, such as the
  one below; in a multistore installation, one of each shop the module is enabled in, on the
  shop's own address, whatever shop the page is opened in. Each URL checks its own shop's
  invoices, in that shop's context (PrestaShop knows a request's shop by its address): the cron
  calls each of them.

  ```
  * * * * * curl -fsS --max-time 120 'https://shop.example/index.php?fc=module&module=sattleme&controller=cron&token=…' > /dev/null
  ```

  Each invoice is due for a check every minute while it can be paid, 15 seconds before the end of
  its grace period and 5 seconds after, then every five minutes until its final status is known
  (an on-chain payment being confirmed). After failed checks, once the invoice has ended: one
  minute, then twice as long each time, up to every 30 minutes. Each due time counts from the start
  of the run that checked the invoice, 5 seconds early: the next run, a minute later, finds it due,
  however long the run's checks took. A run checks the invoices that are due when it starts, the
  longest due first, 20 at most and for about 45 seconds; one run at a time per shop. It runs while
  the shop is in maintenance too.

  A check due runs at the cron's next call: with a call every minute, the last background check
  before the end of an invoice's grace period comes between 1 and 60 seconds before it (10 seconds
  or less in one case out of six, as the cron's minute falls), and the next within a minute after
  it. An on-chain payment that a check lists before the end came in time without asking
  BlockCypher; one that no check saw by then (sent in the last minute, say) is judged by
  BlockCypher's time, which the SDK asks only then. A customer's payment page left open checks
  every 6 seconds, up to the end.

An invoice whose final status is still unknown after 90 days (an on-chain payment made in time,
still unconfirmed on a congested network) is no longer checked; a note on its order says so:
check it in the sattle.me app and settle the order by hand.

The settings page shows when the checks last ran, and warns when it was more than 10 minutes ago.

## Security

- **The integration key** is stored in PrestaShop's configuration, **encrypted** with PrestaShop's
  own authenticated encryption (`PhpEncryption`, under the `new_cookie_key` of the shop's
  `app/config/parameters.php`): a copy of the database alone does not give it. A shop whose
  `new_cookie_key` changed (its database restored on another installation) cannot read it, and
  its key is entered again. Once saved, it never goes back to the browser (a write-only field,
  never filled), and it never goes in a log, an exception or a page; the SDK never puts it, or a
  key derived from it, in a message or a dump.
- **One key, one shop.** Create an integration for your shop, and use its key nowhere else. If it
  leaks, remove the integration in the sattle.me app and create a new one: the key can make
  invoices that pay you, never spend your funds.
- **The payment page** and its status endpoint open with the order's secure key, PrestaShop's own,
  as PrestaShop's order confirmation page does, compared in constant time: an order's number
  alone opens nothing. Like the confirmation page's link, the payment page's link is the
  customer's. Both are `noindex`, never cached and never send their address to another site; the
  page uses no inline script and loads nothing from another host.
- **Forms**: "Place order" with the method, the payment page's new invoice and the module's
  settings each carry a token of the module (an HMAC under a secret of the shop) and accept a POST
  only; "Place order" also needs the session's own cart, of its customer. The background checks'
  URL needs its own secret token. Nothing the browser sends decides an amount, a currency, a
  reference or a status.
- **Outbound requests.** The SDK connects to hosts the merchant chooses (its relays, its Lightning
  address, its Bitcoin node). It refuses IP addresses and `localhost`, follows no redirect and
  checks TLS, but for the merchant's Bitcoin node, by a rule of its own: an IPv4 address or a
  name, `https`, or `http` to a host of a local network (10.0.0.0/8, 172.16.0.0/12,
  192.168.0.0/16, 100.64.0.0/10, a name under `.local`), never `localhost` nor 127.0.0.0/8; it
  is asked GET requests of mempool.space's API only, with deposit addresses. A name may still
  lead to an address of your own network: if your shop's server sits in a network with services
  of its own, restrict what it can reach to public addresses (a merchant's node outside them then
  fails, and its fallback applies; see the SDK's
  [Requirements](https://github.com/sattleme/checkout-php#requirements) and
  [The merchant's Bitcoin node](https://github.com/sattleme/checkout-php#the-merchants-bitcoin-node)).
- Report a vulnerability privately: see [SECURITY.md](SECURITY.md).

## Privacy

- **What the module stores**, in the shop's database (its table `sattleme_payment`), for each
  attempt to pay: the payment reference, the cart, the order and its reference, the amount and
  currency, the invoice as the SDK made it (amount in sats, exchange rate, Lightning invoice and
  its payment hash and, from 1,000 sats, a deposit address of the merchant's wallet), and what its
  checks found (its state, what came, the confirmations, the transactions, the proofs of payment).
  PrestaShop records the payment itself (its method, amount and reference). No name and no email
  address: the customer's are PrestaShop's own.
- **What the shop's server sends**, and to whom:
  - the merchant's **Nostr relays**, encrypted for the merchant (NIP-44): each invoice, with its
    price and its note (the shop's name and the order's reference), which the merchant's app
    shows;
  - the merchant's **Lightning address** (breez.tips for a sattle.me account): asked for each
    invoice, its amount and, when the address accepts comments, its note, then for its payment;
  - the **rate sources**, one at a time until one answers: asked for the price of bitcoin;
  - the **explorer**, for an invoice with an address: mempool.space, or the merchant's Bitcoin node
    when the merchant's app names one (and mempool.space when the node does not answer, unless the
    merchant chose no fallback): the address, its transactions and the chain's height;
    **api.blockcypher.com**: a transaction, only for one no check saw in time.

  Each of them sees the shop's server's IP address, never the customer's: the customer's browser
  talks to the shop only (the payment page loads nothing from elsewhere).
- **The customer's personal data**, with PrestaShop's GDPR module (psgdpr): its export holds the
  customer's bitcoin payments (reference, order, date, amount, state, address, transactions); its
  erasure removes what leads to the customer's coins on the blockchain (the deposit address,
  which the payment URI holds too, and the transactions) from their payments' records. Their
  references, amounts, rates, dates and states stay, for the merchant's accounting; so do their
  Lightning invoices, which name the merchant's node, never the customer's. A payment still being
  confirmed keeps them until its final status, then loses them. What the merchant's relays
  received stays theirs: the merchant's app keeps its sales.

## Known limits

- **No proxy.** The SDK's connections do not use a proxy: the shop's server needs direct outbound
  connections.
- **Currencies.** The order's currency must be one the SDK knows (its table of currencies) and one
  its rate sources price, its total with at most that currency's decimals: else the method is not
  offered. An amount out of the merchant's limits (the sats its Lightning address takes, the
  on-chain minimum) is known only when the order is placed: it is refused then, with a message,
  and PrestaShop creates no order.
- **Confirmations.** An on-chain payment is paid at 3 confirmations on the explorer's read (one
  explorer: mempool.space, or the merchant's Bitcoin node), about 30 minutes: the order stays
  "Bitcoin payment being confirmed" until then.
- **The merchant's Bitcoin node**, when the merchant's app names one with no fallback: while it does
  not answer, no deposit address is leased (an order is payable in Lightning only, and one payable
  on-chain only is refused) and the checks of an on-chain payment fail, retried, never taken as
  expired; a node on the merchant's own network (`http://192.168.…`, `.local`) answers only a shop
  hosted on that network.
- **An underpayment accepted after its end.** `expired` is final as soon as the SDK reports it: the
  module never checks such an invoice again. In one rare case (SPEC.md §3), the merchant accepted
  an underpayment in the sattle.me app, a relay that holds the acceptance fails at the check after
  the invoice's end, and that check gives `expired`: the order then stays "Awaiting bitcoin payment",
  with its note "ended underpaid" (or is canceled), although the merchant accepted the underpayment
  in the app. The merchant then settles the order by hand: records the payment and sets its state.
- **Time.** An invoice is made in the web request of "Place order", bounded at 15 seconds; checks
  for a page at 15 seconds, the background's at 20 seconds each. PHP's `max_execution_time` must
  allow them.
- **Another payment method.** PrestaShop gives an order one payment method: an order placed with
  bitcoin and never paid stays "Awaiting bitcoin payment", its customer may get a new invoice or
  order again with another method, and the merchant cancels the unpaid order once its invoice
  ended unpaid, as for any payment by bank wire.
- **Without the cron**, payments are checked only while the customer's page or the order's page
  in the back office is open: an on-chain payment is then completed late.
- **Deposit addresses.** Each invoice of 1,000 sats or more may lease an address of the
  integration's lot for its time: many orders left unpaid at once can empty it, the next ones are
  then payable in Lightning only until the merchant opens the app (see the SDK).

## Troubleshooting

PrestaShop's logs (**Advanced Parameters → Logs**, messages starting with `sattle.me:`) say what
went wrong, never with the key; the order's page in the back office shows each payment's last
error in plain words.

- **The key is refused when saving the settings.**
  - "This is not the secret key of a sattle.me integration": copy the whole key from the app,
    `sattleme_ck_` included.
  - "This integration was removed in the sattle.me app": create a new integration, and enter its
    key.
  - "This integration could not be found": the merchant's relays do not hold it (yet): open the
    sattle.me app, check the key, and try again.
  - "This integration needs a newer version of the sattle.me module": update the module.
  - "sattle.me could not be reached to verify this key": the shop's server could not reach the
    merchant's relays or its Lightning address. Check its outbound connections
    ([Requirements](#requirements)): direct, without a proxy.
  - "This server cannot run the module": PHP lacks what the SDK needs, the message says what.
- **The method is not offered at checkout**: it is off, or no key is set (or it cannot be read:
  enter it again), or the order's currency is not one the SDK prices, or PrestaShop's
  restrictions of payment methods leave it out.
- **"Bitcoin payments are not available right now"** at checkout: the logs say why: an integration
  key no longer valid or removed, the integration not found on its relays, the merchant's Lightning
  address answering wrongly, or a clock that is off (the shop's server's: keep it synchronized).
- **"We could not create your bitcoin invoice right now"**: the relays, the Lightning address or
  the rate sources did not answer in time, or the amount can only be paid on-chain and no deposit
  address of the integration was free (the merchant opens the app to give it more). The customer
  tries again; if it goes on, check the server's outbound connections.
- **"This order cannot be paid with bitcoin"**: the SDK refused the amount or the currency
  ([Known limits](#known-limits)).
- **A payment stays pending.** The order's page shows its last check and its next one, and the
  settings page when the background checks last ran: the cron must call the module's URL. An
  on-chain payment waits for 3 confirmations, about 30 minutes, longer with a low fee. A note "had
  no final status after 90 days": check the payment in the sattle.me app, and settle the order by
  hand.
- **An order in "Bitcoin payment to check"**: its invoice was paid, and the bitcoin reached the
  wallet, but the order changed after the invoice was made, or was canceled or refunded: its note
  says what came and what it owes now. Settle it with the customer (have the rest paid otherwise,
  or refund the difference from the wallet), then set the order's state.
- **A note "ended underpaid"** on an order: less than the amount came, and reached the wallet:
  settle the difference with the customer.
- **"Order … was not canceled"** in the back office (the order named by its reference): its
  invoice can still be paid, its payment is being confirmed, or bitcoin came for it (see
  [Payments and orders](#payments-and-orders)). Wait until the invoice ended unpaid, or settle
  what came with the customer and record the refund.

## Development

The tools come from `composer.lock` (Composer 2.11, PHP 8.2 or later), and the build's PHP-Scoper
is the PHAR of its release 0.18.20 (SHA-256
`5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122`):

```shell
composer install
sh tools/build.sh path/to/php-scoper.phar       # build/sattleme.zip, checked
vendor/bin/phpunit                              # the unit tests, on the ZIP's code
```

- **The ZIP** (`tools/build.sh`): the runtime libraries of `composer.lock` installed without the
  development ones, prefixed with PHP-Scoper (`Sattleme\PrestaShop\Vendor`), the module's files,
  one class map, the `_PS_VERSION_` guard in every PHP file, the libraries' and Composer's
  autoloader's included (PrestaShop's technical standards ask it of every PHP file: PHP-Scoper's
  patcher and `tools/guard.php` add it, `tools/Guard.php`), an `index.php` in every directory, an
  `.htaccess` at its root that denies its PHP files to the web (in Apache 2.2's and 2.4's words,
  as PrestaShop Addons asks), and `header_csp.txt`, the external domains the module reaches, as
  Addons recommends (all from the shop's server: the SDK's rate sources, mempool.space and
  BlockCypher, and breez.tips, a sattle.me account's Lightning address; the merchant's relays are
  its integration key's, and its Bitcoin node its app's, which no list can name); then
  `tools/check-zip.php` checks it as PrestaShop's upload and its validator would (one module directory, no development file, the licence header on every
  file, the `_PS_VERSION_` guard on every PHP file, no forbidden function, every PHP file parsing,
  every library prefixed, no call of `serialize()`, `unserialize()` or `eval()` in any PHP file, one
  version, the logo, the translations). The same commit gives the same bytes.
- **The tests** never reach the network: the SDK is faked at the module's one seam, its
  `Gateway`, through a test-only switch (the constant `_SATTLEME_TEST_GATEWAY_`, which the module
  never defines); one test runs the real SDK client, without the network. Four suites:
  - `unit` (the default): the ZIP's classes and libraries, without PrestaShop;
  - `shop`: in a PrestaShop shop, the module installed from the ZIP, made without Docker nor root
    by `tests/shop/shop.sh` (MariaDB and PrestaShop from their archives, PHP's built-in server):
    `sh tests/shop/shop.sh setup 9.2.1`, then `eval "$(sh tests/shop/shop.sh env)"` and
    `vendor/bin/phpunit --testsuite shop`;
  - `http`: that shop's pages over HTTP, as its merchant and its customers use them (with
    `SATTLEME_SHOP_ROOT` unset);
  - `opc`: the same, through PrestaShop 9.2's One Page Checkout, as its script drives it (in a
    shop of PrestaShop 9.2: `--testsuite http,opc`).
- **The end-to-end test**, with Docker: `sh tests/e2e/run.sh <PrestaShop's image>` runs
  PrestaShop's own image (Apache, the shop's friendly URLs on, where `tests/shop/shop.sh`'s shop
  has them off), uploads the ZIP in its back office's Module Manager, then runs the `http` suite
  against it (and `opc` on PrestaShop 9.2): the key entered in the settings, an order placed and
  paid in the shop.
- **The checks**, as the CI runs them: `composer validate`, `vendor/bin/php-cs-fixer check
  --diff` (PrestaShop's coding standard), `php tools/translations.php check`, `php tools/docs.php
  check`, `php tools/sdk.php check`, and PHPStan at its strictest level with PrestaShop's extension:
  `_PS_ROOT_DIR_=<PrestaShop's files> vendor/bin/phpstan analyse` (`sh tests/shop/shop.sh sources
  9.2.1 build/prestashop` gives them).
- **The translations** (French, German, Italian): `php tools/translations.php update` writes the
  code's new wordings into each language's files, `check` that each is translated.
- **The user guides** PrestaShop Addons asks for, in English, French, German and Italian: their
  sources are `docs/user-guide/readme_<language>.md` (a subset of Markdown: no link, no URL, no
  email address, as Addons asks); `php tools/docs.php build` writes them as the module's
  `sattleme/docs/readme_<language>.pdf`, and `check` that the PDFs committed are the ones their
  sources give. `tools/UserGuide.php` writes the PDFs itself (PDF's standard fonts, no date, no
  compression): the same source gives the same bytes on any machine and PHP version.
- **The pinned SDK.** The SDK is on Packagist (`sattleme/checkout`), but the module takes it from
  its repository's commit, file by file: `sdk/` holds a copy of
  [checkout-php](https://github.com/sattleme/checkout-php) 1.0.0 (commit `f36b327`, its tag
  `v1.0.0`: its `composer.json`, `LICENSE` and `src/`, unchanged), with `sdk/PIN.json`: the
  repository, the commit, the version and each file's SHA-256. `composer.lock` takes it as a path repository, and the ZIP embeds it, prefixed. To take
  another version: `php tools/sdk.php import <clone of checkout-php> <commit>` (the commit's files,
  whatever the clone has checked out; then the repository's pinned Composer, 2.11.2, updates
  `composer.lock`: its phar, downloaded once into `build/tools/` and its SHA-256 checked, never the
  Composer on PATH), then build and run the checks and the tests.
- **Dependencies**, exact versions locked in `composer.lock`:

  | Package                          | Why                                                                         | Licence      |
  | -------------------------------- | --------------------------------------------------------------------------- | ------------ |
  | `sattleme/checkout` 1.0.0        | The SDK: the protocol (in `sdk/`, embedded, prefixed).                      | MIT          |
  | `bacon/bacon-qr-code` 3.1.1      | The payment page's QR code, as an SVG made on the server (its encoder only). | BSD-2-Clause |
  | `dasprid/enum` 1.0.7             | bacon/bacon-qr-code's one dependency, embedded without its `EnumMap` (which calls `serialize()` and `unserialize()`, forbidden by PrestaShop Addons; the encoder never uses it). | BSD-2-Clause |
  | `phpunit/phpunit` 11.5.58        | Tests (11.5: the last to run on PHP 8.2).                                   | BSD-3-Clause |
  | `phpstan/phpstan` 2.3.6, `phpstan/phpstan-strict-rules` 2.1.1, `phpstan/phpstan-phpunit` 2.1.2 | Static analysis. | MIT |
  | `prestashop/php-dev-tools` 5.0.0 (its tag `v5`) | PrestaShop's coding standard and PHPStan extension.           | MIT          |
  | `friendsofphp/php-cs-fixer` 3.95.31 | Runs that coding standard.                                               | MIT          |
  | `symfony/translation-contracts` 3.8.1 | The translator's interface, which PrestaShop gives at runtime.        | MIT          |

  Dependabot proposes their updates (`.github/dependabot.yml`): one grouped pull request for
  Composer, one for the actions, each a week after the release at least (its cooldown).
- **Updated by hand**, each a week after its release at least, its SHA-256 or its digest checked:
  the PrestaShop versions the tests run (`tests/shop/shop.sh`'s release ZIPs and the CI's matrix),
  PrestaShop's Docker images of the end-to-end test (by digest, in `ci.yml`), the PHP builds
  (static-php-cli's, in `ci.yml` and `release.yml`), Composer (`tools/Composer.php` and the
  workflows, then `composer update --lock` with it), PHP-Scoper (the workflows and this section)
  and MariaDB (`tests/shop/shop.sh`, and the end-to-end test's image in `tests/e2e/run.sh`).
  Dependabot reads none of them.

## Releases

The module is distributed through PrestaShop Addons only: no GitHub release. Releases are the
maintainers' only:

1. A maintainer pushes a tag `vX.Y.Z` (the version of `sattleme.php`, `config.xml` and
   `CHANGELOG.md`) on a commit of `main`.
2. [`release.yml`](.github/workflows/release.yml) runs the CI again, checks the tag, builds the
   ZIP from the lockfile and checks it (`build/sattleme.zip`, named as Addons takes a module: its
   name, no version), then, once the CI passed, keeps it with its SHA-256 (`SHA256SUMS`) as the
   run's artifact `sattleme-vX.Y.Z`, for 90 days. It holds no secret and publishes nothing:
   PrestaShop Addons takes a module only by an upload in its seller account.
3. A member of the team with access to the seller account downloads the artifact (the run's page,
   **Artifacts**), checks `sattleme.zip` against `SHA256SUMS` (`sha256sum -c SHA256SUMS`), and
   uploads it as the product's new version, with its English notes (the CHANGELOG's section of the
   version) and the PrestaShop versions it supports (9.0 to 9.2). Addons' validator checks the ZIP
   on upload; its technical and marketing reviews follow.

**Before the first upload**, the module's key: PrestaShop Addons gives it once the product exists
in the seller account, its marketing information submitted (the seller account, **Products**, the
module, **Click here to add a zip**: the key to copy). It goes in `sattleme/sattleme.php`'s
constructor, where its comment says, as `$this->module_key = '<the key>';` (one line, after
`$this->name`), in a commit of `main` before the first tag; it stays for every later version.
`tools/check-zip.php` takes the module without it (until then) and with it.

## License

MIT, see [LICENSE](LICENSE). The sattle.me name and logo (`sattleme/logo.png` and
`sattleme/views/img/sattleme.svg`, the brand's icon) are not under that license. Their licence is
not decided yet; once it is, it will be stated here, in this section, and in `LICENSE` after the
MIT terms (the file the module's ZIP carries as `sattleme/LICENSE`), for both files by name.
