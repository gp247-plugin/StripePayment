<?php

namespace App\GP247\Plugins\StripePayment\Services;

use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;

/**
 * Turns what Stripe reports about a core payment request (not an order) into movements
 * of that request. Idempotent: the core ledger is unique on the gateway reference, so
 * the return page, the webhook and Stripe's resends record the money once.
 *
 * Works from the Stripe objects as received and never calls Stripe itself.
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-payment-request
 * @aidlc-adr stripe-payment_payment-request-gateway
 */
class StripePaymentRequestFulfillment
{
    /**
     * Whether Stripe payment requests exist on this site at all.
     *
     * @return bool
     */
    public static function supported(): bool
    {
        return class_exists(PaymentRequestService::class);
    }

    /**
     * The payment request a Checkout Session was opened for.
     *
     * @param array<string, mixed> $session
     * @return PaymentRequest|null Null when the session is not for a payment request.
     */
    public function requestOf(array $session): ?PaymentRequest
    {
        $id = (string) ($session['metadata']['payment_request_id'] ?? '');
        if ($id === '' || !self::supported()) {
            return null;
        }

        return PaymentRequest::find($id);
    }

    /**
     * Record the money of a paid session.
     *
     * @param array<string, mixed> $session Checkout Session object.
     * @return PaymentMovement|null The collect movement (new or already recorded); null when nothing is paid yet.
     */
    public function fulfillSession(array $session): ?PaymentMovement
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return null; // delayed method still processing
        }
        $request = $this->requestOf($session);
        if ($request === null) {
            gp247_report('StripePayment: paid session ' . ($session['id'] ?? '?') . ' names payment request ' . ($session['metadata']['payment_request_id'] ?? '?') . ' which does not exist');
            return null;
        }

        $paymentIntent = $this->idOf($session['payment_intent'] ?? null) ?: (string) ($session['id'] ?? '');
        $currency = (string) ($session['currency'] ?? $request->currency);
        $note = strcasecmp($currency, (string) $request->currency) === 0
            ? null
            : 'Stripe collected in ' . strtoupper($currency) . ' but the request is in ' . $request->currency;
        if ($note !== null) {
            gp247_report('StripePayment: payment request ' . $request->id . ': ' . $note);
        }

        return app(PaymentRequestService::class)->recordMovement($request, [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => StripeAmount::fromMinor($session['amount_total'] ?? 0, $currency),
            'gateway' => StripePaymentRequestGateway::KEY,
            'gateway_ref' => $paymentIntent,
            'method' => PaymentMovement::METHOD_GATEWAY,
            'reference' => (string) ($session['id'] ?? ''),
            'note' => $note,
            'confirmed' => true,
        ]);
    }

    /**
     * Mirror a refund Stripe made on a payment request's PaymentIntent — from our own
     * screen (already recorded, replay) or straight from the Stripe Dashboard.
     *
     * @param array<string, mixed> $refund    Refund object.
     * @param string               $eventType refund.created | refund.updated | refund.failed
     * @return bool|null Null when the PaymentIntent is not a payment request's (the order path handles it).
     */
    public function syncRefund(array $refund, string $eventType): ?bool
    {
        if (!self::supported()) {
            return null;
        }
        $service = app(PaymentRequestService::class);
        $original = $service->findGatewayCollect(StripePaymentRequestGateway::KEY, $this->idOf($refund['payment_intent'] ?? null));
        if ($original === null) {
            return null;
        }
        $refundId = (string) ($refund['id'] ?? '');
        $status = (string) ($refund['status'] ?? '');
        if ($eventType === 'refund.failed' || in_array($status, ['failed', 'canceled'], true)) {
            gp247_report('StripePayment: refund ' . ($refundId ?: '?') . ' on payment request ' . $original->request_id . ' ' . ($status ?: 'failed') . ' — check Stripe and adjust the request');
            return false;
        }
        if ($refundId === '' || !in_array($status, ['pending', 'succeeded'], true)) {
            return false;
        }

        $request = PaymentRequest::find($original->request_id);
        $service->recordMovement($request, [
            'type' => PaymentMovement::TYPE_REFUND,
            'amount' => StripeAmount::fromMinor($refund['amount'] ?? 0, (string) ($refund['currency'] ?? $request->currency)),
            'gateway' => StripePaymentRequestGateway::KEY,
            'gateway_ref' => $refundId,
            'method' => PaymentMovement::METHOD_GATEWAY,
            'metadata' => $status === 'pending' ? ['pending' => true] : [],
            'refund_of' => $original->id,
            'confirmed' => true,
        ]);

        return true;
    }

    /**
     * Stripe fields such as payment_intent are an id, or the object when expanded.
     *
     * @param mixed $value
     * @return string
     */
    private function idOf($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }

        return (string) ($value ?? '');
    }
}
