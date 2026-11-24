<?php

/**
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
 */

declare(strict_types=1);

namespace Sattleme\PrestaShop;

use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaShop's request context, typed: what the module uses of it is always there once PrestaShop
 * has started (a web request, its console, its tests), and its absence is a bug, never a state to
 * go on with.
 */
final class Platform
{
    private function __construct()
    {
    }

    public static function context(): \Context
    {
        $context = \Context::getContext();
        if (!$context instanceof \Context) {
            throw new \LogicException('PrestaShop has no context');
        }

        return $context;
    }

    public static function translator(): TranslatorInterface
    {
        $translator = self::context()->getTranslator();
        if (!$translator instanceof TranslatorInterface) {
            throw new \LogicException('PrestaShop has no translator');
        }

        return $translator;
    }

    public static function link(): \Link
    {
        $link = self::context()->link;
        if (!$link instanceof \Link) {
            $link = new \Link();
            self::context()->link = $link;
        }

        return $link;
    }

    public static function smarty(): \Smarty
    {
        $smarty = self::context()->smarty;
        if (!$smarty instanceof \Smarty) {
            throw new \LogicException('PrestaShop has no Smarty');
        }

        return $smarty;
    }

    /** The shop of the request (PrestaShop's default shop in its console). */
    public static function idShop(): int
    {
        return (int) self::context()->shop?->id;
    }

    /**
     * The shops the module is enabled in (PrestaShop's module_shop), active and not deleted: their
     * ids and names, the first first.
     *
     * @return array<int, string>
     */
    public static function moduleShops(int $idModule): array
    {
        $shops = [];
        foreach (Database::rows('SELECT s.`id_shop`, s.`name` FROM `' . _DB_PREFIX_ . 'shop` s INNER JOIN `' . _DB_PREFIX_ . 'module_shop` ms'
            . ' ON ms.`id_shop` = s.`id_shop` AND ms.`id_module` = ' . $idModule . ' WHERE s.`active` = 1 AND s.`deleted` = 0 ORDER BY s.`id_shop` ASC') as $row) {
            $shops[Database::int($row['id_shop'] ?? null)] = Database::string($row['name'] ?? null);
        }

        return $shops;
    }

    /** The language of the request. */
    public static function idLang(): int
    {
        $id = self::context()->language?->id;

        return is_numeric($id) ? (int) $id : 0;
    }

    /** The locale of the shop's default language: the merchant's notes are written in it. */
    public static function defaultLocale(): string
    {
        $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        return is_string($language->locale) && $language->locale !== '' ? $language->locale : 'en-US';
    }

    /**
     * The shop's languages, active or not: their ids and locales (en-US, fr-FR…).
     *
     * @return list<array{id: int, locale: string}>
     */
    public static function languages(): array
    {
        $languages = [];
        foreach (\Language::getLanguages(false) as $language) {
            if (!is_array($language) || !is_numeric($language['id_lang'] ?? null)) {
                continue;
            }
            $locale = $language['locale'] ?? null;
            $languages[] = ['id' => (int) $language['id_lang'], 'locale' => is_string($locale) && $locale !== '' ? $locale : 'en-US'];
        }

        return $languages;
    }
}
