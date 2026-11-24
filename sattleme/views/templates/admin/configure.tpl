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
<div class="panel" id="sattleme-status">
  <div class="panel-heading">
    <img src="{$sattleme_logo|escape:'htmlall':'UTF-8'}" width="16" height="16" alt="">
    sattle.me
  </div>

  {if $sattleme_missing}
    <div class="alert alert-danger">
      {l s='This server cannot run the module: %missing%' sprintf=['%missing%' => $sattleme_missing] d='Modules.Sattleme.Admin'}
    </div>
  {/if}

  {if !$sattleme_key_set}
    <div class="alert alert-warning">
      {l s='Enter the integration key to accept bitcoin payments. Create the integration in the sattle.me app (Integrations), and copy its secret key here.' d='Modules.Sattleme.Admin'}
    </div>
  {elseif !$sattleme_key_readable}
    <div class="alert alert-danger">
      {l s='The saved integration key cannot be read: this shop\'s encryption key (new_cookie_key) changed since it was saved. Enter it again.' d='Modules.Sattleme.Admin'}
    </div>
  {elseif $sattleme_business}
    <div class="alert alert-success">
      {l s='The integration key of %business% is saved.' sprintf=['%business%' => $sattleme_business] d='Modules.Sattleme.Admin'}
      {if $sattleme_enabled}
        {l s='Customers are offered to pay with bitcoin.' d='Modules.Sattleme.Admin'}
      {else}
        {l s='Customers are not offered to pay with bitcoin: turn on "Accept bitcoin payments" below.' d='Modules.Sattleme.Admin'}
      {/if}
    </div>
  {/if}

  <h4>{l s='Background checks' d='Modules.Sattleme.Admin'}</h4>
  <p>
    {l s='Payments are checked while the customer\'s page is open, and in the background by a URL of each shop, which your server\'s cron must call every minute: each checks its own shop\'s payments. Keep them secret.' d='Modules.Sattleme.Admin'}
  </p>
  {foreach from=$sattleme_crons item=cron}
    <div class="sattleme-cron">
      {if $sattleme_multistore}
        <h5>{$cron.shop|escape:'htmlall':'UTF-8'}</h5>
      {/if}
      <p><code class="sattleme-cron-url">{$cron.url|escape:'htmlall':'UTF-8'}</code></p>
      <p>{l s='For example, as a line of the crontab of the web server\'s user:' d='Modules.Sattleme.Admin'}</p>
      <pre>{$cron.line|escape:'htmlall':'UTF-8'}</pre>
      {if $cron.last}
        <p class="{if $cron.late}text-danger{else}text-success{/if}">
          {l s='Last run: %date%' sprintf=['%date%' => $cron.last] d='Modules.Sattleme.Admin'}
        </p>
      {else}
        <p class="text-danger">{l s='The background checks never ran: set up the cron.' d='Modules.Sattleme.Admin'}</p>
      {/if}
      {if $cron.late && $cron.last}
        <p class="text-danger">{l s='The background checks have not run for more than 10 minutes: check the cron.' d='Modules.Sattleme.Admin'}</p>
      {/if}
      <p>{l s='Invoices being checked: %count%' sprintf=['%count%' => $cron.pending] d='Modules.Sattleme.Admin'}</p>
    </div>
  {/foreach}

  <h4>{l s='Good to know' d='Modules.Sattleme.Admin'}</h4>
  <ul>
    <li>{l s='Payments reach your sattle.me wallet directly: this module never holds funds.' d='Modules.Sattleme.Admin'}</li>
    <li>{l s='Refunds are never automatic: refund the customer from your wallet, then record the refund in PrestaShop.' d='Modules.Sattleme.Admin'}</li>
    <li>{l s='The currencies, countries, customer groups and carriers the method is offered for are set in Payment > Preferences.' d='Modules.Sattleme.Admin'}</li>
  </ul>
</div>
