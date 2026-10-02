<?php

if (!function_exists('stripepayment_config')) {
    /**
     * Resolve a Stripe setting for the current effective store.
     *
     * Order: the admin_config row of the effective store (gp247_plugin_store_id(): admin
     * session / marketplace checkout / storefront domain) → the GLOBAL row → the static
     * default in config.php → $default. Secrets are decrypted by core's read path, so this
     * returns plaintext.
     *
     * @param string          $key     Setting key without the "StripePayment_" prefix.
     * @param mixed           $default Used when neither the database nor config.php has a value.
     * @param string|int|null $storeId Resolve for this store instead of the effective one (a
     *                                 payment request is served with ITS store's account).
     * @return mixed
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-per-store-credentials
     */
    function stripepayment_config(string $key, $default = null, $storeId = null)
    {
        $storeId ??= function_exists('gp247_plugin_store_id')
            ? gp247_plugin_store_id()
            : (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0);

        $value = gp247_config('StripePayment_' . $key, $storeId);

        if ($value === null || $value === '') {
            $value = config('Plugins/StripePayment.' . $key, $default);
        }

        return $value ?? $default;
    }
}

if (!function_exists('stripepayment_test_mode')) {
    /**
     * Whether the store is using its Stripe test (sandbox) keys.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return bool
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-per-store-credentials
     */
    function stripepayment_test_mode($storeId = null): bool
    {
        return (bool) (int) stripepayment_config('test_mode', 1, $storeId);
    }
}

if (!function_exists('stripepayment_secret_key')) {
    /**
     * The Stripe secret API key for the current mode (test / live) of the effective store.
     *
     * @param string|int|null $storeId Defaults to the effective store.
     * @return string Empty when not configured.
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-per-store-credentials
     */
    function stripepayment_secret_key($storeId = null): string
    {
        return trim((string) stripepayment_config(stripepayment_test_mode($storeId) ? 'secret_key_test' : 'secret_key_live', '', $storeId));
    }
}

if (!function_exists('stripepayment_webhook_secret')) {
    /**
     * The webhook signing secret (whsec_…) for the current mode of the effective store.
     *
     * Test and live endpoints have different secrets in Stripe, hence one per mode.
     *
     * @return string Empty when not configured.
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-webhook-fulfillment
     */
    function stripepayment_webhook_secret(): string
    {
        return trim((string) stripepayment_config(stripepayment_test_mode() ? 'webhook_secret_test' : 'webhook_secret_live', ''));
    }
}

if (!function_exists('stripepayment_route_url')) {
    /**
     * Absolute URL of a plugin route, computed per request so it follows the store's
     * current domain (and locale prefix) instead of a stored value that can drift.
     *
     * Falls back to the plain path when the route is not registered yet (plugin just
     * installed in this request, or called from a console/test context).
     *
     * @param string $name Route name.
     * @param string $path Path used when the route is not registered.
     * @return string
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-hosted-checkout
     */
    function stripepayment_route_url(string $name, string $path): string
    {
        return \Illuminate\Support\Facades\Route::has($name) ? route($name) : url($path);
    }
}

if (!function_exists('stripepayment_success_url')) {
    /**
     * Where Stripe sends the shopper after paying. Stripe replaces the
     * {CHECKOUT_SESSION_ID} placeholder with the session id.
     *
     * @return string
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-hosted-checkout
     */
    function stripepayment_success_url(): string
    {
        return stripepayment_route_url('stripe-payment.success', 'plugin/stripe-payment/success')
            . '?session_id={CHECKOUT_SESSION_ID}';
    }
}

if (!function_exists('stripepayment_cancel_url')) {
    /**
     * Where Stripe sends the shopper who leaves the payment page.
     *
     * @return string
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-hosted-checkout
     */
    function stripepayment_cancel_url(): string
    {
        return stripepayment_route_url('stripe-payment.cancel', 'plugin/stripe-payment/cancel');
    }
}

if (!function_exists('stripepayment_webhook_url')) {
    /**
     * The endpoint URL the site owner registers in the Stripe Dashboard.
     *
     * @return string
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-per-store-credentials
     */
    function stripepayment_webhook_url(): string
    {
        return stripepayment_route_url('stripe-payment.webhook', 'plugin/stripe-payment/webhook');
    }
}

if (!function_exists('stripepayment_payment_request_return_url')) {
    /**
     * Where Stripe sends a payer back after paying a core payment request.
     *
     * @return string
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-payment-request
     */
    function stripepayment_payment_request_return_url(): string
    {
        return stripepayment_route_url('stripe-payment.payment_request.return', 'plugin/stripe-payment/payment-request/return')
            . '?session_id={CHECKOUT_SESSION_ID}';
    }
}

if (!function_exists('stripepayment_register_payment_gateway')) {
    /**
     * Offer Stripe to the core's payment requests (pay links, refunds) — only when the
     * core has that feature; an older core simply never sees the gateway.
     *
     * @return void
     *
     * @aidlc-unit plugin-stripe-payment
     * @aidlc-story US-stripe-payment-payment-request
     * @aidlc-adr stripe-payment_payment-request-gateway
     */
    function stripepayment_register_payment_gateway(): void
    {
        if (!interface_exists(\GP247\Shop\Payment\Contracts\PaymentGateway::class)) {
            return;
        }
        // WHY a whole-array write: dot-notation would split a gateway key containing dots.
        $gateways = (array) config('gp247-config.payment.gateways', []);
        $gateways['StripePayment'] = [
            'label' => 'Stripe',
            'capabilities' => ['collect', 'refund'],
            'driver' => \App\GP247\Plugins\StripePayment\Services\StripePaymentRequestGateway::class,
        ];
        config(['gp247-config.payment.gateways' => $gateways]);
    }
}
