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
{if $sattleme_description}
  <section class="sattleme-option">
    <p>
      {foreach from=$sattleme_description item=line name=lines}
        {$line|escape:'htmlall':'UTF-8'}{if !$smarty.foreach.lines.last}<br>{/if}
      {/foreach}
    </p>
  </section>
{/if}
