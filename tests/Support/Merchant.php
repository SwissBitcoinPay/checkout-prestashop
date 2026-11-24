<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Support;

/**
 * The shop's merchant in its back office (HttpShop::merchant): a browser logged in, the back
 * office's directory, and the token of its links.
 */
final class Merchant
{
    public function __construct(
        public readonly Browser $browser,
        public readonly string $admin,
        public readonly string $token,
    ) {
    }

    /** A page of the back office's Symfony routes: index.php/<path>, with the token. */
    public function url(string $path): string
    {
        return $this->admin . 'index.php/' . ltrim($path, '/') . (str_contains($path, '?') ? '&' : '?') . '_token=' . rawurlencode($this->token);
    }

    /** The module's settings page (Modules > Module Manager > Configure). */
    public function configurePage(): Page
    {
        return $this->browser->get($this->url('improve/modules/manage/action/configure/sattleme'));
    }

    /** An order's page. */
    public function orderPage(int $idOrder): Page
    {
        return $this->browser->get($this->url('sell/orders/' . $idOrder . '/view'));
    }
}
