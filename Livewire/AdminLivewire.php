<?php
#App\GP247\Plugins\StripePayment\Livewire\AdminLivewire.php

namespace App\GP247\Plugins\StripePayment\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Payment\PaymentSettings;
use GP247\Shop\Models\ShopOrderStatus;

/**
 * Admin settings screen for Stripe: test/live mode, secret keys, webhook signing secrets
 * and the order status to use after a payment / a full refund.
 *
 * Secrets are `password` fields, so core encrypts them at rest and never reveals an
 * inherited value at a sub-store scope. storeScoped() gives the root admin the store
 * picker (multi-store: one Stripe account per store; marketplace: stores inherit the
 * owner's account). The screen stays owner-only because Provider.php does not register it
 * as a store-scoped segment. Above the form, "Check before use" lists what still stops
 * shoppers from paying with Stripe (readiness(), local settings only).
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-per-store-credentials
 * @aidlc-story US-stripe-payment-config-readiness
 */
class AdminLivewire extends ConfigForm
{
    protected ?string $permission = null;

    private const PREFIX = 'StripePayment_';

    /** key => [default, secret] — mirrors AppConfig::seedRows(). */
    private const SETTINGS = [
        'test_mode' => ['1', false],
        'secret_key_test' => ['', true],
        'webhook_secret_test' => ['', true],
        'secret_key_live' => ['', true],
        'webhook_secret_live' => ['', true],
        'order_status_success' => ['2', false],
        'order_status_refunded' => ['7', false],
    ];

    /**
     * Make sure every setting row exists at GLOBAL before the form loads, so a site that
     * skipped an update still sees every field.
     *
     * @return void
     */
    public function mount(): void
    {
        $global = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        $sort = 0;
        foreach (self::SETTINGS as $key => [$default, $secret]) {
            AdminConfig::firstOrCreate(
                ['group' => 'Plugins', 'key' => self::PREFIX . $key, 'store_id' => (string) $global],
                [
                    'code' => 'StripePayment_config',
                    'sort' => ++$sort,
                    'value' => $default,
                    'security' => $secret ? 1 : 0,
                    'detail' => 'Plugins/StripePayment::lang.' . $key,
                ]
            );
        }

        parent::mount();
    }

    protected function storeScoped(): bool
    {
        return true;
    }

    protected function group(): string
    {
        return 'Plugins';
    }

    protected function heading(): string
    {
        return trans('Plugins/StripePayment::lang.config_heading');
    }

    protected function keys(): array
    {
        return array_map(fn ($key) => self::PREFIX . $key, array_keys(self::SETTINGS));
    }

    protected function fieldTypes(): array
    {
        $types = [
            self::PREFIX . 'test_mode' => 'bool',
            self::PREFIX . 'order_status_success' => 'select',
            self::PREFIX . 'order_status_refunded' => 'select',
        ];
        foreach (self::SETTINGS as $key => [, $secret]) {
            if ($secret) {
                $types[self::PREFIX . $key] = 'password';
            }
        }

        return $types;
    }

    protected function fieldOptions(): array
    {
        $orderStatus = ShopOrderStatus::getIdAll();

        return [
            self::PREFIX . 'order_status_success' => $orderStatus,
            self::PREFIX . 'order_status_refunded' => $orderStatus,
        ];
    }

    /**
     * Four blocks — mode, Sandbox, Live, order status — so a test key can never be typed
     * into a live field by mistake; the block in use carries a badge. The webhook URL and
     * events are the same for both environments, so they are explained once, on the mode
     * block, instead of on every secret field.
     *
     * @return array<int, array<string, mixed>>
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-per-store-credentials
     */
    protected function sections(): array
    {
        $testMode = (bool) ($this->values[self::PREFIX . 'test_mode'] ?? true);
        $inUse = trans('Plugins/StripePayment::lang.section_in_use');
        $events = 'checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, refund.created, refund.updated, refund.failed';

        return [
            [
                'id' => 'mode',
                'title' => trans('Plugins/StripePayment::lang.section_mode'),
                'hint' => trans('Plugins/StripePayment::lang.section_mode_hint', ['url' => stripepayment_webhook_url(), 'events' => $events]),
                'keys' => [self::PREFIX . 'test_mode'],
            ],
            [
                'id' => 'sandbox',
                'title' => trans('Plugins/StripePayment::lang.section_sandbox'),
                'hint' => trans('Plugins/StripePayment::lang.section_sandbox_hint'),
                'badge' => $testMode ? $inUse : '',
                'keys' => [self::PREFIX . 'secret_key_test', self::PREFIX . 'webhook_secret_test'],
            ],
            [
                'id' => 'live',
                'title' => trans('Plugins/StripePayment::lang.section_live'),
                'hint' => trans('Plugins/StripePayment::lang.section_live_hint'),
                'badge' => $testMode ? '' : $inUse,
                'keys' => [self::PREFIX . 'secret_key_live', self::PREFIX . 'webhook_secret_live'],
            ],
            [
                'id' => 'order-status',
                'title' => trans('Plugins/StripePayment::lang.section_order_status'),
                'keys' => [self::PREFIX . 'order_status_success', self::PREFIX . 'order_status_refunded'],
            ],
        ];
    }

    /**
     * Short per-field hints: the expected key prefix, so a pasted key is easy to check.
     *
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return [
            self::PREFIX . 'secret_key_test' => 'sk_test_… / rk_test_…',
            self::PREFIX . 'webhook_secret_test' => 'whsec_…',
            self::PREFIX . 'secret_key_live' => 'sk_live_… / rk_live_…',
            self::PREFIX . 'webhook_secret_live' => 'whsec_…',
        ];
    }

    /**
     * What the store still needs before shoppers can pay with Stripe: the payment step at
     * checkout, the plugin on for the store, the secret key of the mode in use, and — so
     * money that clears later or is refunded on Stripe reaches the order — the webhook
     * secret and a public webhook URL. Test mode is a warning. Local settings only: the
     * keys themselves are only checked by Stripe at payment time.
     *
     * @return array<int, ReadinessCheck>
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-config-readiness
     * @aidlc-story US-admin-shell-config-form-readiness
     */
    protected function readiness(): array
    {
        $store = $this->readinessStoreId();
        $t = static fn (string $key, array $replace = []): string => (string) trans('Plugins/StripePayment::lang.' . $key, $replace);
        // WHY '' = test: an unsaved row falls back to config.php, whose default is test mode.
        $testMode = !in_array($this->saved(self::PREFIX . 'test_mode', $store), ['0'], true);
        $suffix = $testMode ? 'test' : 'live';
        $section = $t($testMode ? 'section_sandbox' : 'section_live');
        $webhookHost = (string) parse_url(function_exists('stripepayment_webhook_url') ? stripepayment_webhook_url() : '', PHP_URL_HOST);

        return [
            ...$this->shopChecks($store, $t),
            ReadinessCheck::failIf($this->saved(self::PREFIX . 'secret_key_' . $suffix, $store) === '', 'stripe_secret_key',
                $t('ready_secret_key_missing', ['mode' => $section]), ReadinessCheck::ERROR, '', $section, $t('ready_secret_key_ok', ['mode' => $section])),
            ReadinessCheck::failIf($this->saved(self::PREFIX . 'webhook_secret_' . $suffix, $store) === '', 'stripe_webhook_secret',
                $t('ready_webhook_secret_missing', ['mode' => $section]), ReadinessCheck::WARNING, '', $section, $t('ready_webhook_secret_ok', ['mode' => $section])),
            ReadinessCheck::failIf($testMode, 'stripe_test_mode', $t('ready_test_mode_on'),
                ReadinessCheck::WARNING, '', $t('section_mode'), $t('ready_test_mode_off')),
            ReadinessCheck::failIf(self::isLocalHost($webhookHost), 'stripe_webhook_public', $t('ready_webhook_local'),
                ReadinessCheck::WARNING, '', '', $t('ready_webhook_ok')),
        ];
    }

    /**
     * The shop-side conditions — the payment step at checkout and this plugin on for the
     * store — from gp247/shop, so every payment plugin shows the same thing. An older
     * gp247/shop without PaymentSettings::readiness() gets the same two checks computed here.
     *
     * @param string   $store Store being checked.
     * @param \Closure $t     Translator of this plugin's strings.
     * @return array<int, ReadinessCheck> payment_enabled, provider_enabled.
     *
     * @aidlc-story US-payment-request-plugin-readiness-check
     */
    private function shopChecks(string $store, \Closure $t): array
    {
        if (method_exists(PaymentSettings::class, 'readiness')) {
            return PaymentSettings::readiness($store, 'StripePayment');
        }

        return [
            ReadinessCheck::failIf(in_array($this->saved('use_payment', $store), ['', '0'], true), 'payment_enabled', $t('ready_payment_off'),
                ReadinessCheck::ERROR, self::adminUrl('admin_config.index'), $t('fix_order_tab'), $t('ready_payment_on')),
            ReadinessCheck::failIf($this->saved('StripePayment', $store, 'Plugins') !== '1', 'provider_enabled', $t('ready_plugin_off'),
                ReadinessCheck::ERROR, self::adminUrl('admin_plugin.index'), $t('fix_plugins'), $t('ready_plugin_on')),
        ];
    }

    /**
     * A setting read straight from admin_config: the store row, else the GLOBAL row.
     *
     * WHY not gp247_config(): it caches the rows for the whole request, so right after a
     * save the box would still show the values from before it.
     *
     * @param string      $key   admin_config key.
     * @param string      $store Store id.
     * @param string|null $group admin_config group (null = any).
     * @return string Trimmed value, '' when unset.
     */
    private function saved(string $key, string $store, ?string $group = null): string
    {
        $global = (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);
        foreach (array_unique([$store, $global]) as $scope) {
            $row = AdminConfig::where('key', $key)->where('store_id', $scope)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))->first();
            if ($row !== null && trim((string) $row->value) !== '') {
                return trim((string) $row->value);
            }
        }

        return '';
    }

    /**
     * @param string $route Admin route name.
     * @return string URL, or '' when the route is not registered.
     */
    private static function adminUrl(string $route): string
    {
        return \Illuminate\Support\Facades\Route::has($route) ? (string) gp247_route_admin($route) : '';
    }

    /**
     * @param string $host Host of the webhook URL.
     * @return bool Stripe cannot call it back (local name or private address).
     */
    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost') {
            return true;
        }
        foreach (['.local', '.localhost', '.test', '.internal', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
