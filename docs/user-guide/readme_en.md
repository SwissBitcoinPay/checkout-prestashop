# sattle.me for PrestaShop: user guide

The sattle.me module lets your customers pay their orders in bitcoin, over Lightning or on-chain. The bitcoin goes straight to your sattle.me wallet: the shop makes and checks the invoices itself, and the key it holds can make invoices that pay you, never spend your funds.

## Before you start

- PrestaShop 9.0, 9.1 or 9.2, on PHP 8.2 or later (64-bit). The module checks the server when it is installed, and says what is missing, if anything.
- A merchant account of sattle.me, and its app.
- The system's cron on the shop's server, to call the module's address every minute (see "Background checks").
- Outbound connections from the shop's server: to the merchant's relays, its Lightning address, the exchange rate sources, a block explorer (or your own Bitcoin node, when you name one in the sattle.me app) and, for a transaction no check saw in time, a second blockchain service. The customer's browser talks to the shop only.

## Installation

1. Get the module on PrestaShop Addons: its file, `sattleme.zip`, is then in your Addons account.
2. In the back office, open **Modules**, then the **Module Manager**, and upload `sattleme.zip`. PrestaShop installs the module.

To update the module, upload its new version the same way. Uninstalled, the module removes its settings and its key; it keeps the records of its payments and its order states, which your orders refer to.

## Configuration

1. In the sattle.me app, create an integration for your shop, and copy its secret key (it starts with `sattleme_ck_`).
2. In the back office, find **sattle.me** in the Module Manager, and click **Configure**.
3. Paste the key in **Integration key**, and save. The module checks it at once, and refuses a key it cannot use. Once saved, the key is never shown again: leave the field empty to keep it.
4. Turn on **Accept bitcoin payments**.
5. **Time to pay**: how long a customer has to pay an invoice, from 1 to 60 minutes (15 by default).
6. If you wish, change the **Title at checkout** and the **Description at checkout**, in each of the shop's languages.
7. Set up the cron with the line the settings page shows (see "Background checks").

PrestaShop's own restrictions of a payment method apply too (**Payment**, then **Preferences**): currencies, countries, customer groups and carriers. Use one integration key for one shop, and change it only while no bitcoin payment is pending. sattle.me has no test network: every payment is a real bitcoin payment, to your wallet.

## How payments and orders go

1. At checkout, the customer chooses **Pay with bitcoin**. When they place the order, the module first gets the invoice; only then does PrestaShop create the order, in the state **Awaiting bitcoin payment**. If the invoice cannot be made, no order is created, and the customer can choose another method.
2. The customer lands on the shop's payment page: a QR code, a button that opens their wallet, the Lightning invoice and the address to copy, the amount in sats, in bitcoin and in the order's currency, and the time left to pay.
3. The page follows the payment on its own. An on-chain payment puts the order in **Bitcoin payment being confirmed** until it has 3 confirmations (about 30 minutes).
4. Once the payment is complete, the module records it and the order goes to **Payment accepted**: PrestaShop sends its email and makes its invoice, as for any payment. An on-chain payment short of the amount is complete too when your tolerance accepts it, or when you accept in the sattle.me app what the order received: a note on the order says what came.

If the order changed after its invoice was made (its total, its currency, another payment or a credit slip), or if it was refunded meanwhile, a payment that comes all the same is recorded, and the order goes to **Bitcoin payment to check**, never to "Payment accepted". A note on the order gives the sats received, the invoice's price and what the order owes now. The customer's pages say the payment was received and that the shop will contact them. Settle the difference with the customer, then set the order's state yourself: reinstate the order, or refund the customer.

An invoice that ends unpaid leaves the order awaiting payment, and its payment page offers the customer a new invoice. The module never cancels an order: cancel an unpaid order yourself once its invoice ended unpaid, as for a payment by bank wire. While its invoice can still be paid or its payment is being confirmed, and once bitcoin came for it (paid, or less than the amount, which reached your wallet), the module refuses to cancel the order (the states **Canceled** and **Payment error**), and the back office says why: settle what came with the customer, refund them from your wallet, then record the refund (a credit slip, the state **Refunded**).

The order's page in the back office shows each payment attempt: its reference, the amount and exchange rate, how it was paid, the address and the transactions (as text to copy), the proof of payment, the last check and the next one, and its error in plain words.

## Background checks

The module checks the invoices from the shop's server. While a customer's payment page is open, it checks often; for the rest, the system's cron calls the module's address every minute, with the shop's secret token. The settings page shows that address and a ready line for the crontab of the web server's user. In a multistore installation, the page lists one address for each shop: the cron calls each of them.

The settings page shows when the checks last ran, and warns you when that was more than 10 minutes ago. Without the cron, payments are checked only while the customer's page, or the order's page in the back office, is open.

## Refunds

Refunds are never automatic: a Lightning payment can only be refunded to an invoice of the customer's, an on-chain one to an address of theirs, which the shop never asks for. Ask the customer for one, refund them from your wallet, then record the refund in PrestaShop (a credit slip, the order's state).

## Privacy

- The module stores, for each attempt to pay: its reference, the cart and the order, the amount and currency, the invoice (amount in sats, exchange rate, Lightning invoice, deposit address) and what its checks found. No name and no email address: the customer's are PrestaShop's own.
- The shop's server sends each invoice to the merchant's relays, encrypted for the merchant, with the shop's name and the order's reference; it asks the merchant's Lightning address for the invoice, the exchange rate sources for the price of bitcoin, and a block explorer for an on-chain invoice's address and transactions: by default a public one, or your own Bitcoin node when you name one in the sattle.me app (with the public one as its fallback, unless you chose none). Each of them sees the shop's server, never the customer.
- With PrestaShop's GDPR module, a customer's export holds their bitcoin payments, and their erasure removes the deposit addresses and the transactions from them; the references, amounts, rates, dates and states stay, for your accounting.

## If something goes wrong

PrestaShop's logs (**Advanced Parameters**, then **Logs**, messages starting with `sattle.me:`) say what went wrong, never with the key. The order's page in the back office shows each payment's last check and its error. An order in **Bitcoin payment to check** waits for you: read its note, settle with the customer, then set its state.
