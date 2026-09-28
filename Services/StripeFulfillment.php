<?php

namespace App\GP247\Plugins\StripePayment\Services;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopOrderTransaction;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns what Stripe reports into ledger entries on the order — the one place that does it.
 *
 * WHY one function for both the return page and the webhook: Stripe holds the shopper for
 * up to 10 seconds waiting on the checkout.session.completed webhook, so the two paths
 * usually run side by side for the same session, and Stripe also resends events. The
 * ledger's unique gateway_transaction_id makes the money idempotent; this class makes the
 * side effects (order status, history) happen only for the call that actually wrote the
 * row (ADR stripe-payment_idempotent-fulfillment, NFR-SEC-payment-idempotency).
 *
 * Works from the Stripe objects as received (a verified webhook payload or a session read
 * back from the API) and never calls Stripe itself.
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-webhook-fulfillment
 * @aidlc-story US-stripe-payment-refund-sync
 * @aidlc-adr stripe-payment_idempotent-fulfillment
 */
class StripeFulfillment
{
    public const METHOD = 'StripePayment';

    /**
     * Record the payment of a paid Checkout Session. Safe to call any number of times,
     * concurrently, for the same session.
     *
     * @param array<string, mixed> $session Checkout Session object.
     * @return bool True only for the call that recorded the money.
     */
    public function fulfillSession(array $session): bool
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return false; // delayed method still processing, or nothing to pay
        }

        $order = $this->orderOf($session);
        if ($order === null) {
            gp247_report('StripePayment: paid session ' . ($session['id'] ?? '?') . ' has no matching order');
            return false;
        }

        $paymentIntent = $this->idOf($session['payment_intent'] ?? null) ?: (string) ($session['id'] ?? '');
        if ($paymentIntent === '' || ShopOrderTransaction::alreadyRecorded($paymentIntent)) {
            return false;
        }

        $currency = (string) ($session['currency'] ?? $order->currency);
        $paid = StripeAmount::fromMinor($session['amount_total'] ?? 0, $currency);
        $matches = strcasecmp($currency, (string) $order->currency) === 0
            && StripeAmount::matches($paid, (float) $order->total, $currency);
        $mismatch = 'Stripe collected ' . $paid . ' ' . strtoupper($currency)
            . ' but the order is ' . $order->total . ' ' . $order->currency;

        // Record what Stripe actually took, never the order total "to make it match": a
        // disagreement is a reconciliation problem to surface, not to hide.
        $movement = self::ignoreDuplicate(fn () => $order->recordPayment(
            $paid,
            self::METHOD,
            $paymentIntent,
            null,
            $matches ? null : $mismatch
        ));
        if ($movement === null) {
            return false; // lost the race to the other path, or nothing to record
        }

        $order = ShopOrder::find($order->id);
        $order->update(['transaction' => $paymentIntent]);

        if (!$matches) {
            gp247_report('StripePayment: order ' . $order->id . ': ' . $mismatch);
            $this->history($order, 'Stripe payment ' . $paymentIntent . ': ' . $mismatch);

            return true;
        }

        $content = 'Stripe payment ' . $paymentIntent;
        // Only move an order that is still new: a late webhook must not undo what the
        // admin has already done with it (shipped, completed…).
        if ((int) $order->status === ShopOrderStatus::NEW) {
            $order->changeStatus(
                (int) stripepayment_config('order_status_success', ShopOrderStatus::PROCESSING),
                ['content' => $content, 'customer_id' => $order->customer_id ?: 0]
            );
        } else {
            $this->history($order, $content);
        }

        return true;
    }

    /**
     * A delayed payment method (bank debit…) failed after checkout. The order stays as it
     * is — the admin decides what to do — but the failure is written on it.
     *
     * @param array<string, mixed> $session
     * @return void
     */
    public function markAsyncFailed(array $session): void
    {
        $order = $this->orderOf($session);
        if ($order === null) {
            gp247_report('StripePayment: failed async payment for unknown session ' . ($session['id'] ?? '?'));
            return;
        }

        $this->history($order, 'Stripe payment failed (session ' . ($session['id'] ?? '?') . ')');
    }

    /**
     * Mirror a refund made on Stripe into the order's ledger.
     *
     * @param array<string, mixed> $refund    Refund object.
     * @param string               $eventType refund.created | refund.updated | refund.failed
     * @return bool True only for the call that recorded the refund.
     */
    public function syncRefund(array $refund, string $eventType): bool
    {
        $refundId = (string) ($refund['id'] ?? '');
        $paymentIntent = $this->idOf($refund['payment_intent'] ?? null);
        $payment = $paymentIntent === ''
            ? null
            : ShopOrderTransaction::where('gateway_transaction_id', $paymentIntent)->first();
        $order = $payment ? ShopOrder::find($payment->order_id) : null;

        if ($refundId === '' || $order === null) {
            gp247_report('StripePayment: refund ' . ($refundId ?: '?') . ' has no matching order payment');
            return false;
        }

        $status = (string) ($refund['status'] ?? '');
        if ($eventType === 'refund.failed' || in_array($status, ['failed', 'canceled'], true)) {
            // Not reversed automatically when it was already recorded: the admin checks
            // Stripe and corrects the order by hand (known limitation).
            gp247_report('StripePayment: refund ' . $refundId . ' on order ' . $order->id . ' ' . ($status ?: 'failed'));
            $this->history($order, 'Stripe refund ' . $refundId . ' ' . ($status ?: 'failed') . ' — check Stripe and adjust the order');

            return false;
        }

        // requires_action: Stripe is still collecting the customer's bank details.
        if (!in_array($status, ['pending', 'succeeded'], true) || ShopOrderTransaction::alreadyRecorded($refundId)) {
            return false;
        }

        $amount = StripeAmount::fromMinor($refund['amount'] ?? 0, (string) ($refund['currency'] ?? $order->currency));
        $movement = self::ignoreDuplicate(fn () => $order->recordRefund(
            $amount,
            self::METHOD,
            $refundId,
            null,
            'Stripe refund ' . strtoupper((string) ($refund['currency'] ?? ''))
        ));
        if ($movement === null) {
            return false;
        }

        $order = ShopOrder::find($order->id);
        $content = 'Stripe refund ' . $refundId . ': ' . $amount . ' ' . $order->currency;
        // Only a refund that leaves no money on the order flips it to "refunded"; a partial
        // refund must not present the whole order as refunded.
        if ((float) $order->received <= 0) {
            $order->changeStatus(
                (int) stripepayment_config('order_status_refunded', ShopOrderStatus::REFUNDED),
                ['content' => $content, 'customer_id' => $order->customer_id ?: 0]
            );
        } else {
            $this->history($order, $content);
        }

        return true;
    }

    /**
     * Run a ledger write, treating a unique-index collision as "already recorded".
     *
     * ShopOrder::recordPayment() checks-then-inserts, so two concurrent calls can both pass
     * the check; the second insert then hits the unique gateway_transaction_id and must end
     * quietly instead of turning into a 500 (RISK-TECH-stripe-concurrent-fulfillment).
     *
     * @template T
     * @param callable():T $write
     * @return T|null
     */
    public static function ignoreDuplicate(callable $write)
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $session
     * @return ShopOrder|null
     */
    private function orderOf(array $session): ?ShopOrder
    {
        $orderId = (string) ($session['metadata']['order_id'] ?? $session['client_reference_id'] ?? '');

        return $orderId === '' ? null : ShopOrder::find($orderId);
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

    private function history(ShopOrder $order, string $content): void
    {
        $order->addOrderHistory([
            'order_id' => $order->id,
            'content' => $content,
            'customer_id' => $order->customer_id ?: 0,
            'order_status_id' => $order->status,
        ]);
    }
}
