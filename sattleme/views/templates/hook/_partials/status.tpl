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
{* Where an order's bitcoin payment is, for its confirmation and its details. *}
<div class="sattleme-status sattleme-status--{$sattleme.phase|escape:'htmlall':'UTF-8'}">
  {if $sattleme.phase == 'paid'}
    <p>{l s='Your bitcoin payment was received. Thank you!' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'unconfirmed'}
    <p>{l s='Your bitcoin payment came in time. The Bitcoin network is confirming its on-chain transaction, which takes about 30 minutes: you will get an e-mail once your order is paid.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'review'}
    <p>{l s='Your bitcoin payment was received. We will contact you about your order.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'recording'}
    <p>{l s='Your bitcoin payment was received. Your order is being updated with it: do not pay again.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'expired'}
    <p>{l s='The bitcoin invoice of this order expired before it was paid.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'underpaid_expired'}
    <p>{$sattleme.texts.underpaid_expired|escape:'htmlall':'UTF-8'}</p>
  {elseif $sattleme.phase == 'canceled'}
    <p>{l s='This order was canceled: do not pay it.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'failed'}
    <p>{l s='We could not create a bitcoin invoice for this order.' d='Modules.Sattleme.Shop'}</p>
  {elseif $sattleme.phase == 'abandoned'}
    <p>{l s='The bitcoin invoice of this order is no longer checked. If you paid it, please contact us with its reference below.' d='Modules.Sattleme.Shop'}</p>
  {else}
    <p>{l s='Your order awaits its bitcoin payment.' d='Modules.Sattleme.Shop'}</p>
  {/if}
  {if $sattleme.to_page}
    <p>
      <a class="btn btn-primary" href="{$sattleme_pay_url|escape:'htmlall':'UTF-8'}">
        {if $sattleme.may_renew}
          {l s='Get a new invoice' d='Modules.Sattleme.Shop'}
        {elseif $sattleme.phase == 'unconfirmed' || $sattleme.phase == 'recording'}
          {l s='Follow the payment' d='Modules.Sattleme.Shop'}
        {else}
          {l s='Pay now' d='Modules.Sattleme.Shop'}
        {/if}
      </a>
    </p>
  {/if}
  {if $sattleme.payment}
    <p class="sattleme-status__reference"><small>{$sattleme.texts.reference|escape:'htmlall':'UTF-8'}</small></p>
  {/if}
</div>
