<?php
#App\GP247\Plugins\StripePayment\Livewire\AdminLivewire.php

namespace App\GP247\Plugins\StripePayment\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopOrderStatus;

/**
 * Admin settings screen for Stripe: test/live mode, secret keys, webhook signing secrets
 * and the order status to use after a payment / a full refund.
 *
 * Secrets are `password` fields, so core encrypts them at rest and never reveals an
 * inherited value at a sub-store scope. storeScoped() gives the root admin the store
 * picker (multi-store: one Stripe account per store; marketplace: stores inherit the
 * owner's account). The screen stays owner-only because Provider.php does not register it
 * as a store-scoped segment.
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-per-store-credentials
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
}
