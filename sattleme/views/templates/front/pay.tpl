{**
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
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Bitcoin payment' d='Modules.Sattleme.Shop'}
{/block}

{block name='page_content'}
  {assign var='payment' value=$sattleme.payment}
  <section
    id="sattleme-pay"
    class="sattleme-pay sattleme-pay--{$sattleme.phase|escape:'htmlall':'UTF-8'}"
    data-sattleme-status-url="{$sattleme_urls.status|escape:'htmlall':'UTF-8'}"
    data-sattleme-version="{if $payment}{$payment.version|escape:'htmlall':'UTF-8'}{else}none{/if}"
    data-sattleme-poll="{if $payment && $payment.poll_interval}{$payment.poll_interval|intval}{else}0{/if}"
    data-sattleme-seconds-left="{if $sattleme.payable}{$payment.seconds_left|intval}{else}0{/if}"
    data-sattleme-copied="{$sattleme.texts.copied|escape:'htmlall':'UTF-8'}"
  >
    <p class="sattleme-pay__order">{$sattleme.texts.order|escape:'htmlall':'UTF-8'}</p>

    {if $sattleme.payable}
      <div class="sattleme-pay__invoice">
        {if $payment.qr}
          <div class="sattleme-pay__qr">
            {* An SVG made on the server (View\Qr), as a base64 data URI. *}
            <img src="{$payment.qr|escape:'htmlall':'UTF-8'}" alt="{$payment.qr_label|escape:'htmlall':'UTF-8'}" width="300" height="300">
          </div>
        {/if}
        <div class="sattleme-pay__details">
          {if $sattleme.phase == 'underpaid'}
            <p class="alert alert-warning sattleme-pay__underpaid">{$sattleme.texts.underpaid|escape:'htmlall':'UTF-8'}</p>
          {else}
            <p class="sattleme-pay__lead">{l s='Scan the QR code with your Bitcoin wallet, or open the invoice in your wallet.' d='Modules.Sattleme.Shop'}</p>
          {/if}

          <dl class="sattleme-pay__amount">
            <dt>{l s='Amount to pay' d='Modules.Sattleme.Shop'}</dt>
            <dd>
              <strong class="sattleme-pay__sats">{$sattleme.texts.to_pay_sats|escape:'htmlall':'UTF-8'}</strong>
              <span class="sattleme-pay__btc">{$sattleme.texts.to_pay_btc|escape:'htmlall':'UTF-8'}</span>
              {if $sattleme.phase == 'open' && $sattleme.amount}
                <span class="sattleme-pay__fiat">{$sattleme.amount|escape:'htmlall':'UTF-8'}</span>
              {/if}
            </dd>
            <dt>{l s='Time left to pay' d='Modules.Sattleme.Shop'}</dt>
            <dd><span class="sattleme-pay__countdown" data-sattleme-countdown>{$payment.time_left|escape:'htmlall':'UTF-8'}</span></dd>
          </dl>

          <p>
            <a class="btn btn-primary sattleme-pay__wallet" href="{$payment.uri|escape:'htmlall':'UTF-8'}">{l s='Open in my wallet' d='Modules.Sattleme.Shop'}</a>
          </p>

          {if $payment.lightning}
            <div class="form-group sattleme-pay__field">
              <label for="sattleme-lightning">{l s='Lightning invoice' d='Modules.Sattleme.Shop'}</label>
              <textarea id="sattleme-lightning" class="form-control sattleme-pay__value" rows="3" readonly spellcheck="false">{$payment.lightning|escape:'htmlall':'UTF-8'}</textarea>
              <button type="button" class="btn btn-secondary btn-sm sattleme-pay__copy" data-sattleme-copy="sattleme-lightning" hidden>{l s='Copy' d='Modules.Sattleme.Shop'}</button>
            </div>
          {/if}

          {if $payment.address}
            <div class="form-group sattleme-pay__field">
              <label for="sattleme-address">{l s='Bitcoin address (on-chain)' d='Modules.Sattleme.Shop'}</label>
              <input id="sattleme-address" class="form-control sattleme-pay__value" type="text" value="{$payment.address|escape:'htmlall':'UTF-8'}" readonly spellcheck="false">
              <button type="button" class="btn btn-secondary btn-sm sattleme-pay__copy" data-sattleme-copy="sattleme-address" hidden>{l s='Copy' d='Modules.Sattleme.Shop'}</button>
            </div>
            <p class="sattleme-pay__hint">{l s='Paid on-chain, your order is paid once your transaction has 3 confirmations, about 30 minutes. Send the exact amount before the time is up.' d='Modules.Sattleme.Shop'}</p>
          {/if}
        </div>
      </div>
    {elseif $sattleme.phase == 'unconfirmed'}
      {* Nothing final while its on-chain transaction is unconfirmed: it may still be replaced or dropped. *}
      <div class="alert alert-info sattleme-pay__message">
        <p><strong>{l s='Your payment came in time.' d='Modules.Sattleme.Shop'}</strong></p>
        <p>{l s='The Bitcoin network is confirming its on-chain transaction, which takes about 30 minutes. You can close this page: you will get an e-mail once your order is paid.' d='Modules.Sattleme.Shop'}</p>
        <p class="sattleme-pay__confirmations">{$sattleme.texts.confirmations|escape:'htmlall':'UTF-8'}</p>
      </div>
    {elseif $sattleme.phase == 'review'}
      <div class="alert alert-info sattleme-pay__message">
        <p><strong>{l s='Your payment was received.' d='Modules.Sattleme.Shop'}</strong></p>
        <p>{l s='We will contact you about your order.' d='Modules.Sattleme.Shop'}</p>
      </div>
    {elseif $sattleme.phase == 'recording'}
      <div class="alert alert-info sattleme-pay__message">
        <p><strong>{l s='Your payment was received.' d='Modules.Sattleme.Shop'}</strong></p>
        <p>{l s='Your order is being updated with it: this page shows it in a moment. Do not pay again.' d='Modules.Sattleme.Shop'}</p>
      </div>
    {elseif $sattleme.phase == 'checking'}
      <p class="alert alert-info sattleme-pay__message">{l s='We are checking your invoice. This takes a few seconds.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'ended'}
      <p class="alert alert-info sattleme-pay__message">{l s='The time to pay is over. We are checking whether your payment came in time.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'expired'}
      <p class="alert alert-warning sattleme-pay__message">{l s='This invoice expired before it was paid.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'underpaid_expired'}
      <p class="alert alert-warning sattleme-pay__message">{$sattleme.texts.underpaid_expired|escape:'htmlall':'UTF-8'}</p>
    {elseif $sattleme.phase == 'canceled'}
      <p class="alert alert-warning sattleme-pay__message">{l s='This order was canceled: do not pay it.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'failed'}
      <p class="alert alert-danger sattleme-pay__message">{l s='We could not create a bitcoin invoice for this order.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'abandoned'}
      <p class="alert alert-warning sattleme-pay__message">{l s='This invoice is no longer checked. If you paid it, please contact us with its reference below.' d='Modules.Sattleme.Shop'}</p>
    {elseif $sattleme.phase == 'paid'}
      <p class="alert alert-success sattleme-pay__message">{l s='Your payment was received. Thank you!' d='Modules.Sattleme.Shop'}</p>
    {else}
      <p class="alert alert-info sattleme-pay__message">{l s='Your bitcoin invoice is not ready yet.' d='Modules.Sattleme.Shop'}</p>
    {/if}

    <div class="sattleme-pay__actions">
      {if $sattleme.checkable}
        <a class="btn btn-secondary sattleme-pay__check" href="{$sattleme_urls.page|escape:'htmlall':'UTF-8'}">{l s='Check again' d='Modules.Sattleme.Shop'}</a>
        <noscript>
          <p class="sattleme-pay__hint">{l s='This page does not update by itself without JavaScript: once you have paid, use the button to check again.' d='Modules.Sattleme.Shop'}</p>
        </noscript>
      {/if}
      {if $sattleme.may_renew}
        <form method="post" action="{$sattleme_urls.page|escape:'htmlall':'UTF-8'}" class="sattleme-pay__renew">
          <input type="hidden" name="sattleme_token" value="{$sattleme_token|escape:'htmlall':'UTF-8'}">
          <button type="submit" name="sattleme_renew" value="1" class="btn btn-primary">{l s='Get a new invoice' d='Modules.Sattleme.Shop'}</button>
        </form>
      {/if}
      {if $sattleme.contact}
        <p><a href="{$sattleme_urls.contact|escape:'htmlall':'UTF-8'}">{l s='Contact us' d='Modules.Sattleme.Shop'}</a></p>
      {/if}
    </div>

    {if $payment}
      <p class="sattleme-pay__reference">{$sattleme.texts.reference|escape:'htmlall':'UTF-8'}</p>
    {/if}
  </section>
{/block}
