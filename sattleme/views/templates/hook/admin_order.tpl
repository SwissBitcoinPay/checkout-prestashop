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
<div class="card mt-2" id="sattleme-order-panel">
  <div class="card-header">
    <h3 class="card-header-title">
      <img src="{$sattleme_logo|escape:'htmlall':'UTF-8'}" width="16" height="16" alt="">
      {l s='Bitcoin payments (sattle.me)' d='Modules.Sattleme.Admin'}
    </h3>
  </div>
  <div class="card-body">
    {if !$sattleme_attempts}
      <p class="mb-0">{l s='No bitcoin invoice was made for this order yet.' d='Modules.Sattleme.Admin'}</p>
    {/if}
    {foreach from=$sattleme_attempts item=attempt name=attempts}
      <div class="sattleme-attempt{if !$smarty.foreach.attempts.first} mt-3 pt-3 border-top{/if}">
        <h4 class="h5">
          {if $attempt.recording}
            {l s='Paid, not recorded in PrestaShop yet: tried again at each check' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'new'}
            {l s='Invoice not made yet' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'open'}
            {l s='Awaiting payment' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'unconfirmed'}
            {l s='Being confirmed on-chain' d='Modules.Sattleme.Admin'}
          {elseif $attempt.to_check}
            {l s='Paid, to check: see the order\'s note' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'paid'}
            {l s='Paid' d='Modules.Sattleme.Admin'}
          {elseif $attempt.underpaid}
            {l s='Ended underpaid: settle it by hand' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'expired'}
            {l s='Expired, never paid' d='Modules.Sattleme.Admin'}
          {elseif $attempt.abandoned}
            {l s='Checks given up after 90 days: settle it by hand' d='Modules.Sattleme.Admin'}
          {elseif $attempt.state == 'failed'}
            {l s='No invoice could be made' d='Modules.Sattleme.Admin'}
          {else}
            {l s='Never shown to the customer' d='Modules.Sattleme.Admin'}
          {/if}
        </h4>
        <dl class="row mb-0">
          <dt class="col-sm-4">{l s='Reference' d='Modules.Sattleme.Admin'}</dt>
          <dd class="col-sm-8"><code>{$attempt.ref|escape:'htmlall':'UTF-8'}</code></dd>

          <dt class="col-sm-4">{l s='Amount' d='Modules.Sattleme.Admin'}</dt>
          <dd class="col-sm-8">
            {$attempt.amount|escape:'htmlall':'UTF-8'} {$attempt.currency|escape:'htmlall':'UTF-8'}
            {if $attempt.sats}
              = {$attempt.sats|escape:'htmlall':'UTF-8'} sats ({$attempt.btc|escape:'htmlall':'UTF-8'} BTC)
            {/if}
          </dd>

          {if $attempt.rate}
            <dt class="col-sm-4">{l s='Rate' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              1 BTC = {$attempt.rate.price|escape:'htmlall':'UTF-8'} {$attempt.rate.currency|escape:'htmlall':'UTF-8'}
              ({$attempt.rate.source|escape:'htmlall':'UTF-8'})
            </dd>
          {/if}

          {if $attempt.method}
            <dt class="col-sm-4">{l s='Paid by' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              {if $attempt.method == 'lightning'}
                {l s='Lightning' d='Modules.Sattleme.Admin'}
              {elseif $attempt.method == 'onchain'}
                {l s='On-chain' d='Modules.Sattleme.Admin'}
              {else}
                {l s='On-chain and Lightning' d='Modules.Sattleme.Admin'}
              {/if}
            </dd>
          {/if}

          {if $attempt.payment_hash}
            <dt class="col-sm-4">{l s='Lightning payment hash' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8"><code class="text-break">{$attempt.payment_hash|escape:'htmlall':'UTF-8'}</code></dd>
          {/if}

          {if $attempt.address}
            <dt class="col-sm-4">{l s='Bitcoin address' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              {if $attempt.address_removed}
                {l s='Removed at the customer\'s request (personal data)' d='Modules.Sattleme.Admin'}
              {else}
                <code class="text-break user-select-all">{$attempt.address|escape:'htmlall':'UTF-8'}</code>
              {/if}
            </dd>
          {/if}

          {if $attempt.has_received}
            <dt class="col-sm-4">{l s='Received in time' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              {* How it came, only when the status says it: a paid status's method; others name none. *}
              {$attempt.received|escape:'htmlall':'UTF-8'} sats{if $attempt.method == 'onchain'},
                {l s='on-chain' d='Modules.Sattleme.Admin'}
              {elseif $attempt.method == 'mixed'},
                {l s='on-chain and in Lightning (the rest of an underpayment)' d='Modules.Sattleme.Admin'}
              {/if}
            </dd>
          {/if}

          {if $attempt.confirming}
            <dt class="col-sm-4">{l s='Confirmations' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">{$attempt.confirmations|intval} / 3</dd>
          {/if}

          {if $attempt.txids}
            <dt class="col-sm-4">{l s='Transactions' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              {foreach from=$attempt.txids item=txid}
                <code class="text-break user-select-all">{$txid|escape:'htmlall':'UTF-8'}</code><br>
              {/foreach}
            </dd>
          {elseif $attempt.txids_at_address}
            <dt class="col-sm-4">{l s='Transactions' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">{l s='Not listed by its status: look the Bitcoin address above up in a block explorer.' d='Modules.Sattleme.Admin'}</dd>
          {/if}

          {if $attempt.proofs}
            <dt class="col-sm-4">{l s='Proof of payment (preimage)' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">
              {foreach from=$attempt.proofs item=proof}
                <code class="text-break">{$proof.preimage|escape:'htmlall':'UTF-8'}</code><br>
                <small>{l s='proves the payment hash' d='Modules.Sattleme.Admin'} <code class="text-break">{$proof.hash|escape:'htmlall':'UTF-8'}</code></small><br>
              {/foreach}
            </dd>
          {/if}

          {if $attempt.created_at}
            <dt class="col-sm-4">{l s='Invoice made' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">{$attempt.created_at|escape:'htmlall':'UTF-8'}</dd>
          {/if}
          {if $attempt.expires_at}
            <dt class="col-sm-4">{l s='Payable until' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">{$attempt.expires_at|escape:'htmlall':'UTF-8'}</dd>
          {/if}
          <dt class="col-sm-4">{l s='Last check' d='Modules.Sattleme.Admin'}</dt>
          <dd class="col-sm-8">{if $attempt.checked_at}{$attempt.checked_at|escape:'htmlall':'UTF-8'}{if $attempt.check_failed} ({l s='failed: see its error below' d='Modules.Sattleme.Admin'}){/if}{else}{l s='Never' d='Modules.Sattleme.Admin'}{/if}</dd>
          {if $attempt.next_check_at}
            <dt class="col-sm-4">{l s='Next check' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8">{$attempt.next_check_at|escape:'htmlall':'UTF-8'}</dd>
          {/if}

          {if $attempt.error_text}
            <dt class="col-sm-4">{l s='Last error' d='Modules.Sattleme.Admin'}</dt>
            <dd class="col-sm-8 text-danger">{$attempt.error_text|escape:'htmlall':'UTF-8'} <code>{$attempt.error|escape:'htmlall':'UTF-8'}</code></dd>
          {/if}
        </dl>
      </div>
    {/foreach}
    <p class="small text-muted mt-3 mb-0">{l s='Refunds are never automatic: refund the customer from your wallet, then record the refund in PrestaShop.' d='Modules.Sattleme.Admin'}</p>
  </div>
</div>
