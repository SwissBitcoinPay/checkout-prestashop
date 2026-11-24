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

namespace Sattleme\PrestaShop\Admin;

use Sattleme\PrestaShop\Config;
use Sattleme\PrestaShop\KeyVault;
use Sattleme\PrestaShop\Payment\AttemptRepository;
use Sattleme\PrestaShop\Platform;
use Sattleme\PrestaShop\Sdk\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's settings in the back office: whether customers are offered the method, the
 * integration key (write-only: never shown again, never sent to the browser; checked when saved),
 * the time to pay (1 to 60 minutes), the method's title and description at checkout per language;
 * and what the merchant needs to know: what this server lacks, the key's state, the cron URL of the
 * background checks and when they last ran.
 *
 * PrestaShop's own settings of a payment method apply as well (Payment > Preferences: currencies,
 * countries, customer groups, carriers).
 */
final class ConfigPage
{
    public const SUBMIT = 'submitSattlemeSettings';
    public const TOKEN_FIELD = 'SATTLEME_FORM_TOKEN';

    public const TITLE_MAX = 128;
    public const DESCRIPTION_MAX = 1000;

    /** @var \PaymentModule */
    private $module;

    /** @var string */
    private $moduleName;

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(\PaymentModule $module, string $moduleName, TranslatorInterface $translator)
    {
        $this->module = $module;
        $this->moduleName = $moduleName;
        $this->translator = $translator;
    }

    /** The page: the outcome of a submission, the status, the form. */
    public function render(): string
    {
        $html = '';
        if (\Tools::isSubmit(self::SUBMIT) === true) {
            [$errors, $confirmations] = $this->process();
            // Plain text, which PrestaShop's displayError and displayConfirmation print as HTML: a
            // message may hold the merchant's business name, from its app.
            foreach ($errors as $error) {
                $html .= self::text($this->module->displayError(self::escape($error)));
            }
            foreach ($confirmations as $confirmation) {
                $html .= self::text($this->module->displayConfirmation(self::escape($confirmation)));
            }
        }
        $smarty = Platform::smarty();
        $smarty->assign($this->status());

        return $html . self::text($smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/configure.tpl')) . $this->form();
    }

    /**
     * Saves a submission once all of it is valid; the key only once the SDK accepted it. A
     * submission is a POST: the same fields in a link or an image (a GET) change nothing, and the
     * page is shown as it is.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function process(): array
    {
        $errors = [];
        $confirmations = [];
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || strtoupper($method) !== 'POST') {
            return [[], []];
        }
        if (!hash_equals($this->formToken(), self::input(self::TOKEN_FIELD))) {
            return [[$this->translator->trans('The form expired. Please try again.', [], 'Modules.Sattleme.Admin')], []];
        }
        $minutes = trim(self::input('SATTLEME_MINUTES'));
        if (preg_match('/^[0-9]{1,2}$/D', $minutes) !== 1 || (int) $minutes < Config::MIN_MINUTES || (int) $minutes > Config::MAX_MINUTES) {
            $errors[] = $this->translator->trans('The time to pay is a number of minutes, from 1 to 60.', [], 'Modules.Sattleme.Admin');
        }
        $titles = [];
        $descriptions = [];
        foreach (Platform::languages() as $language) {
            $title = trim(self::input('SATTLEME_TITLE_' . $language['id']));
            $description = trim(self::input('SATTLEME_DESCRIPTION_' . $language['id']));
            if (mb_strlen($title) > self::TITLE_MAX || mb_strlen($description) > self::DESCRIPTION_MAX) {
                $errors[] = $this->translator->trans('The title is %title% characters at most, the description %description%.', ['%title%' => (string) self::TITLE_MAX, '%description%' => (string) self::DESCRIPTION_MAX], 'Modules.Sattleme.Admin');
                break;
            }
            $titles[$language['id']] = strip_tags($title);
            $descriptions[$language['id']] = strip_tags($description);
        }
        $enabled = self::input('SATTLEME_ENABLED') === '1';
        $key = trim(self::input('SATTLEME_KEY'));
        $verified = null;
        if ($key !== '' && $errors === []) {
            $verified = KeyCheck::verify($key, $this->translator);
            if ($verified['error'] !== null) {
                $errors[] = $verified['error'];
            }
        }
        if ($enabled && $verified === null && KeyVault::read() === null) {
            $errors[] = $this->translator->trans('Enter the integration key to accept bitcoin payments.', [], 'Modules.Sattleme.Admin');
        }
        $missing = Environment::missing();
        if ($enabled && $missing !== null) {
            $errors[] = $this->translator->trans('This server cannot run the module: %missing%', ['%missing%' => $missing], 'Modules.Sattleme.Admin');
        }
        if ($errors !== []) {
            return [$errors, []];
        }
        if ($verified !== null && $verified['merchant'] !== null) {
            KeyVault::store($key);
            // As it is (PrestaShop strips the tags of a value it does not store as HTML): its
            // templates escape it.
            \Configuration::updateValue(Config::BUSINESS, $verified['merchant']->businessName, true);
            $confirmations[] = $this->translator->trans('The integration key of %business% is saved. It is never shown again.', ['%business%' => $verified['merchant']->businessName], 'Modules.Sattleme.Admin');
        }
        unset($key);
        \Configuration::updateValue(Config::ENABLED, $enabled ? '1' : '0');
        \Configuration::updateValue(Config::MINUTES, (string) (int) $minutes);
        \Configuration::updateValue(Config::TITLE, $titles);
        \Configuration::updateValue(Config::DESCRIPTION, $descriptions);
        $confirmations[] = $this->translator->trans('Settings saved.', [], 'Modules.Sattleme.Admin');

        return [[], $confirmations];
    }

    /** @return array<string, mixed> what configure.tpl shows */
    public function status(): array
    {
        $business = \Configuration::get(Config::BUSINESS);
        $crons = $this->crons();

        return [
            'sattleme_missing' => Environment::missing(),
            'sattleme_key_set' => KeyVault::isSet(),
            'sattleme_key_readable' => KeyVault::read() !== null,
            'sattleme_business' => is_string($business) ? $business : '',
            'sattleme_enabled' => Config::isEnabled(),
            'sattleme_crons' => $crons,
            'sattleme_multistore' => count($crons) > 1,
            'sattleme_logo' => $this->module->getPathUri() . 'logo.png',
        ];
    }

    /**
     * The background checks of each shop the module runs in, whatever shop the page is opened
     * in: its cron URL (on the shop's own address, with its own secret token), a crontab line, when
     * its checks last ran, its invoices being checked. A run checks its own shop's invoices, in
     * that shop's context, as PrestaShop resolves the shop of a request from its address
     * (Shop::initialize): one line per shop, in a multistore installation.
     *
     * @return list<array{id: int, shop: string, url: string, line: string, last: string|null, late: bool, pending: int}>
     */
    public function crons(): array
    {
        $crons = [];
        foreach (Platform::moduleShops((int) $this->module->id) as $idShop => $name) {
            $url = Platform::link()->getModuleLink($this->moduleName, 'cron', ['token' => Config::cronToken($idShop)], true, null, $idShop);
            $lastRun = Config::lastCronRun($idShop);
            $crons[] = [
                'id' => $idShop,
                'shop' => $name,
                'url' => $url,
                'line' => self::cronLine($url),
                'last' => $lastRun === null ? null : date('Y-m-d H:i:s', $lastRun),
                'late' => $lastRun === null || $lastRun < time() - 600,
                'pending' => (new AttemptRepository())->counts($idShop)['pending'],
            ];
        }

        return $crons;
    }

    /** The crontab line that calls the background checks every minute. */
    public static function cronLine(string $cronUrl): string
    {
        return '* * * * * curl -fsS --max-time 120 ' . escapeshellarg($cronUrl) . ' > /dev/null';
    }

    private function form(): string
    {
        $form = [
            'form' => [
                'legend' => ['title' => $this->translator->trans('Settings', [], 'Modules.Sattleme.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->translator->trans('Accept bitcoin payments', [], 'Modules.Sattleme.Admin'),
                        'name' => 'SATTLEME_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'sattleme_enabled_on', 'value' => 1, 'label' => $this->translator->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'sattleme_enabled_off', 'value' => 0, 'label' => $this->translator->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->translator->trans('Integration key', [], 'Modules.Sattleme.Admin'),
                        'name' => 'SATTLEME_KEY',
                        'autocomplete' => false,
                        'desc' => $this->translator->trans('In the sattle.me app, create an integration for this shop and enter its secret key (sattleme_ck_…). It is checked when you save it, and never shown again: leave the field empty to keep the saved key. Whoever holds it can create invoices that pay you, never spend your funds: keep it secret all the same. Change it only while no bitcoin payment is pending: those are checked with the key set here.', [], 'Modules.Sattleme.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translator->trans('Time to pay', [], 'Modules.Sattleme.Admin'),
                        'name' => 'SATTLEME_MINUTES',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->translator->trans('minutes', [], 'Modules.Sattleme.Admin'),
                        'desc' => $this->translator->trans('How long the customer has to pay an invoice, from 1 to 60 minutes.', [], 'Modules.Sattleme.Admin'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translator->trans('Title at checkout', [], 'Modules.Sattleme.Admin'),
                        'name' => 'SATTLEME_TITLE',
                        'lang' => true,
                        'maxlength' => self::TITLE_MAX,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->translator->trans('Description at checkout', [], 'Modules.Sattleme.Admin'),
                        'name' => 'SATTLEME_DESCRIPTION',
                        'lang' => true,
                        'rows' => 3,
                    ],
                    ['type' => 'hidden', 'name' => self::TOKEN_FIELD],
                ],
                'submit' => ['title' => $this->translator->trans('Save', [], 'Admin.Actions'), 'name' => self::SUBMIT],
            ],
        ];

        $defaultLanguage = (int) \Configuration::get('PS_LANG_DEFAULT');
        $titles = [];
        $descriptions = [];
        $formLanguages = [];
        foreach (\Language::getLanguages(false) as $language) {
            if (!is_array($language) || !is_numeric($language['id_lang'] ?? null)) {
                continue;
            }
            $id = (int) $language['id_lang'];
            $titles[$id] = Config::title($id);
            $descriptions[$id] = Config::description($id);
            $formLanguages[] = $language + ['is_default' => $id === $defaultLanguage ? 1 : 0];
        }

        $helper = new \HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->moduleName;
        $helper->show_toolbar = false;
        $helper->table = 'module';
        $helper->identifier = 'id_module';
        $helper->default_form_language = $defaultLanguage;
        $helper->allow_employee_form_lang = (int) \Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = self::SUBMIT;
        $helper->currentIndex = Platform::link()->getAdminLink('AdminModules', false)
            . '&configure=' . $this->moduleName . '&tab_module=payments_gateways&module_name=' . $this->moduleName;
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'SATTLEME_ENABLED' => Config::isEnabled() ? 1 : 0,
                // Never the key: the field is write-only.
                'SATTLEME_KEY' => '',
                'SATTLEME_MINUTES' => (string) Config::minutes(),
                self::TOKEN_FIELD => $this->formToken(),
                'SATTLEME_TITLE' => $titles,
                'SATTLEME_DESCRIPTION' => $descriptions,
            ],
            'languages' => $formLanguages,
            'id_language' => Platform::idLang(),
        ];

        return self::text($helper->generateForm([$form]));
    }

    /** The CSRF token of the form: for this employee, under the module's secret. */
    private function formToken(): string
    {
        return hash_hmac('sha256', 'settings|' . (int) Platform::context()->employee?->id, Config::secret());
    }

    /** A field of the submitted form: '' when it is missing or not a string. */
    private static function input(string $name): string
    {
        $value = \Tools::getValue($name);

        return is_string($value) ? $value : '';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** What PrestaShop renders: a string, or nothing. */
    private static function text(mixed $html): string
    {
        return is_string($html) ? $html : '';
    }
}
