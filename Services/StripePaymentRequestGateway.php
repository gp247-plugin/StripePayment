<?php

namespace App\GP247\Plugins\StripePayment\Services;

use GP247\Shop\Payment\Contracts\PaymentGateway;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use GP247\Shop\Payment\Support\CollectResult;
use GP247\Shop\Payment\Support\RefundResult;

/**
 * Stripe as a gateway of the core's payment requests: a Checkout Session for what is
 * still due (collect) and a refund of a PaymentIntent it collected (refund).
 *
 * Every call uses the Stripe account of the store that OWNS the request — never the
 * session's store (an admin's session store, a marketplace cart's store)
 * (NFR-SEC-stripe-payment-request-store-credentials).
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-payment-request
 * @aidlc-adr stripe-payment_payment-request-gateway
 */
class StripePaymentRequestGateway implements PaymentGateway
{
    public const KEY = 'StripePayment';

    /** Locales Stripe Checkout can display (docs: checkout/sessions/create, `locale`). */
    private const STRIPE_LOCALES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fil', 'fr', 'hr', 'hu', 'id', 'it', 'ja', 'ko',
        'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function capabilities(): array
    {
        return [self::CAP_COLLECT, self::CAP_REFUND];
    }

    /**
     * Usable when the plugin is on for the request's store and that store has a secret key
     * for its current mode.
     *
     * @param PaymentRequest $request
     * @return bool
     */
    public function availableFor(PaymentRequest $request): bool
    {
        // Same rule as checkout: the plugin may be switched off for this store.
        if (function_exists('gp247_plugin_store_enabled') && !gp247_plugin_store_enabled(self::KEY, $request->store_id)) {
            return false;
        }

        return stripepayment_secret_key($request->store_id) !== '';
    }

    /**
     * Open a Checkout Session for what is still due on the request.
     *
     * WHY the idempotency key carries the amount and the hour: a double click must not
     * open two sessions (the payer could pay both), but after a partial payment — or a
     * day later, when the old session has expired — a new one is needed.
     *
     * @param PaymentRequest $request
     * @return CollectResult
     * @throws StripeApiException
     */
    public function collect(PaymentRequest $request): CollectResult
    {
        $currency = strtolower((string) $request->currency);
        $minor = StripeAmount::toMinor($request->outstanding(), $currency);
        $id = (string) $request->id;
        $label = trim((string) $request->description) !== ''
            ? mb_substr((string) $request->description, 0, 250)
            : $this->lang('payreq_line', ['id' => $id]);

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $minor,
                    'product_data' => ['name' => $label],
                ],
            ]],
            'client_reference_id' => 'payreq-' . $id,
            'metadata' => ['payment_request_id' => $id],
            'payment_intent_data' => ['metadata' => ['payment_request_id' => $id]],
            'success_url' => stripepayment_payment_request_return_url(),
            'cancel_url' => (string) app(PaymentRequestService::class)->publicUrl($request),
            'locale' => $this->stripeLocale(),
        ];
        if (filter_var((string) $request->party_email, FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = (string) $request->party_email;
        }

        $session = (new StripeApi(stripepayment_secret_key($request->store_id)))
            ->createCheckoutSession($params, 'gp247-payreq-' . $id . '-' . $minor . '-' . $currency . '-' . now()->format('YmdH'));
        if (empty($session['url']) || empty($session['id'])) {
            throw new StripeApiException('Stripe returned no Checkout Session url', 'no_session_url');
        }

        return new CollectResult((string) $session['url'], (string) $session['id']);
    }

    /**
     * Refund part or all of a PaymentIntent this gateway collected.
     *
     * WHY the number of earlier refunds in the key: the same click sent twice returns the
     * same refund, while a second legitimate refund of the same amount gets its own.
     *
     * @param PaymentRequest  $request
     * @param PaymentMovement $original
     * @param float           $amount
     * @param string|null     $idempotencyKey Issued by the core for a refund source (not a stored movement).
     * @return RefundResult
     * @throws StripeApiException
     */
    public function refund(PaymentRequest $request, PaymentMovement $original, float $amount, ?string $idempotencyKey = null): RefundResult
    {
        $currency = strtolower((string) $request->currency);
        $minor = StripeAmount::toMinor($amount, $currency);
        $idempotencyKey ??= 'gp247-payreq-refund-' . $original->id . '-' . app(PaymentRequestService::class)->refundsOf($original)->count() . '-' . $minor;

        $refund = (new StripeApi(stripepayment_secret_key($request->store_id)))->createRefund([
            'payment_intent' => (string) $original->gateway_ref,
            'amount' => $minor,
            'metadata' => array_filter(['payment_request_id' => (string) $request->id, 'movement_id' => (string) ($original->id ?? '')]),
        ], $idempotencyKey);

        $status = (string) ($refund['status'] ?? '');
        if (empty($refund['id']) || in_array($status, ['failed', 'canceled'], true)) {
            throw new StripeApiException('Stripe refused the refund (' . ($status ?: 'no id') . ')', 'refund_' . ($status ?: 'invalid'));
        }

        return new RefundResult((string) $refund['id'], $status === 'succeeded');
    }

    private function stripeLocale(): string
    {
        $locale = rtrim(strtolower(substr((string) app()->getLocale(), 0, 3)), '-_');

        return in_array($locale, self::STRIPE_LOCALES, true) ? $locale : 'auto';
    }

    private function lang(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/StripePayment::lang.' . $key, $replace);
    }
}
