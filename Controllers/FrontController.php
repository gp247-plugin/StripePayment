<?php
#App\GP247\Plugins\StripePayment\Controllers\FrontController.php
namespace App\GP247\Plugins\StripePayment\Controllers;

use App\GP247\Plugins\StripePayment\AppConfig;
use App\GP247\Plugins\StripePayment\Services\StripeAmount;
use App\GP247\Plugins\StripePayment\Services\StripeApi;
use App\GP247\Plugins\StripePayment\Services\StripeApiException;
use App\GP247\Plugins\StripePayment\Services\StripeFulfillment;
use App\GP247\Plugins\StripePayment\Services\StripePaymentRequestFulfillment;
use App\GP247\Plugins\StripePayment\Services\WebhookSignature;
use GP247\Front\Controllers\RootFrontController;
use GP247\Shop\Controllers\ShopCartController;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use Illuminate\Http\Request;

/**
 * Stripe Checkout (hosted page) for the storefront.
 *
 * processOrder() is called by the shop right after the order is created; the shopper is
 * sent to Stripe and comes back to success() or cancel(). The webhook records the money
 * even when the shopper never comes back. Both paths share StripeFulfillment.
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-hosted-checkout
 * @aidlc-story US-stripe-payment-webhook-fulfillment
 * @aidlc-story US-stripe-payment-payment-request
 * @aidlc-adr stripe-payment_hosted-checkout-no-sdk
 */
class FrontController extends RootFrontController
{
    /** Locales Stripe Checkout can display (docs: checkout/sessions/create, `locale`). */
    private const STRIPE_LOCALES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fil', 'fr', 'hr', 'hu', 'id', 'it', 'ja', 'ko',
        'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh',
    ];

    public $plugin;

    public function __construct()
    {
        parent::__construct();
        $this->plugin = new AppConfig;
    }

    /**
     * Create the Checkout Session for the order just placed and send the shopper to Stripe.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processOrder()
    {
        $orderId = session('orderID');
        $order = $orderId ? ShopOrder::with('details')->find($orderId) : null;
        if ($order === null) {
            gp247_report('StripePayment: processOrder without a valid order in session');
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_order'));
        }

        $api = new StripeApi();
        if (!$api->configured()) {
            gp247_report('StripePayment: no secret key configured for store ' . gp247_plugin_store_id());
            return $this->abandon($this->lang('error_not_configured'));
        }

        try {
            // One session per order even if this request is sent twice.
            $session = $api->createCheckoutSession($this->sessionParams($order), 'gp247-order-' . $order->id);
        } catch (StripeApiException $e) {
            gp247_report('StripePayment: create session for order ' . $order->id . ' failed: ' . $e->stripeCode() . ': ' . $e->getMessage());
            return $this->abandon($this->lang('error_create_session'));
        }

        if (empty($session['url']) || empty($session['id'])) {
            gp247_report('StripePayment: create session for order ' . $order->id . ' returned no url');
            return $this->abandon($this->lang('error_create_session'));
        }

        $order->update(['transaction' => (string) $session['id']]);

        return redirect()->away((string) $session['url']);
    }

    /**
     * Shopper is back from Stripe. The session id in the URL is untrusted: the session is
     * read from Stripe and must belong to the order this shopper is holding
     * (NFR-SEC-stripe-return-bound-to-session).
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function success(Request $request)
    {
        $orderId = (string) session('orderID', '');
        $sessionId = (string) $request->query('session_id', '');
        if ($orderId === '' || $sessionId === '') {
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_order'));
        }

        try {
            $session = (new StripeApi())->retrieveCheckoutSession($sessionId);
        } catch (StripeApiException $e) {
            gp247_report('StripePayment: retrieve session ' . $sessionId . ' failed: ' . $e->stripeCode() . ': ' . $e->getMessage());
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_verify'));
        }

        $sessionOrder = (string) ($session['metadata']['order_id'] ?? $session['client_reference_id'] ?? '');
        if ($sessionOrder !== $orderId) {
            gp247_report('StripePayment: session ' . $sessionId . ' is for order ' . $sessionOrder . ', shopper holds order ' . $orderId);
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_verify'));
        }

        if (($session['status'] ?? '') !== 'complete') {
            return redirect(gp247_route_front('cart'))->with('error', $this->lang('error_not_paid'));
        }

        // Paid now → recorded here; delayed method (payment_status=unpaid) → the order is
        // placed and the webhook records the money when it clears.
        (new StripeFulfillment())->fulfillSession($session);

        return (new ShopCartController)->completeOrder();
    }

    /**
     * Shopper left the Stripe page: cancel the order and return its stock (shop seam).
     *
     * WHY the guard: the cancel URL is a plain GET anyone holding the checkout session can
     * open, not only from the Stripe page. A shopper who paid (webhook already recorded the
     * money) and never came back, or an order the admin has already moved on, must not be
     * cancelled and restocked by it. Only a still-new order with no money is cancelled.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancel()
    {
        $orderId = session('orderID');
        $order = $orderId ? ShopOrder::find($orderId) : null;

        if ($order !== null && (float) $order->received > 0) {
            // Paid after all: finish the checkout instead of undoing it.
            return (new ShopCartController)->completeOrder();
        }
        if ($order !== null && (int) $order->status !== ShopOrderStatus::NEW) {
            gp247_report('StripePayment: cancel link refused for order ' . $order->id . ' in status ' . $order->status);
            return redirect(gp247_route_front('front.home'))->with('error', $this->lang('error_cannot_cancel'));
        }

        return (new ShopCartController)->cancelOrder();
    }

    /**
     * Payer is back from paying a core payment request. The session id in the URL is
     * untrusted: the session is read from Stripe with this storefront's key and must name
     * a request of this storefront's store; the money is recorded idempotently (the
     * webhook usually got there first) and the payer goes back to the link's page.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @aidlc-story US-stripe-payment-payment-request
     */
    public function paymentRequestReturn(Request $request)
    {
        $home = redirect(gp247_route_front('front.home'));
        $sessionId = (string) $request->query('session_id', '');
        $storeId = (string) config('app.storeId', GP247_STORE_ID_ROOT);
        if ($sessionId === '' || !StripePaymentRequestFulfillment::supported()) {
            return $home->with('error', $this->lang('payreq_error'));
        }

        try {
            $session = (new StripeApi(stripepayment_secret_key($storeId)))->retrieveCheckoutSession($sessionId);
        } catch (StripeApiException $e) {
            gp247_report('StripePayment: retrieve payment request session ' . $sessionId . ' failed: ' . $e->stripeCode() . ': ' . $e->getMessage());
            return $home->with('error', $this->lang('payreq_error'));
        }

        $requests = new StripePaymentRequestFulfillment();
        $paymentRequest = $requests->requestOf($session);
        if ($paymentRequest === null || (string) $paymentRequest->store_id !== $storeId) {
            gp247_report('StripePayment: return for session ' . $sessionId . ' does not match a payment request of store ' . $storeId);
            return $home->with('error', $this->lang('payreq_error'));
        }

        $back = app(\GP247\Shop\Payment\PaymentRequestService::class)->publicUrl($paymentRequest);
        $target = $back !== null ? redirect()->away($back) : $home;
        if (($session['status'] ?? '') !== 'complete') {
            return $target->with('error', $this->lang('error_not_paid'));
        }

        try {
            $movement = $requests->fulfillSession($session);
        } catch (\Throwable $e) {
            gp247_report('StripePayment: recording payment request ' . $paymentRequest->id . ' on return failed: ' . $e->getMessage());
            return $target->with('error', $this->lang('payreq_error'));
        }

        return $target->with('success', $this->lang($movement !== null ? 'payreq_paid' : 'payreq_processing'));
    }

    /**
     * Stripe webhook. Reads the RAW body (the signature covers the exact bytes), answers 400
     * to anything unsigned, 200 once handled or deliberately ignored, 500 only on an
     * unexpected error so Stripe retries.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function webhook(Request $request)
    {
        $payload = (string) $request->getContent();
        if (!WebhookSignature::verify($payload, $request->header('Stripe-Signature'), stripepayment_webhook_secret())) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            return response('Invalid payload', 400);
        }
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $fulfillment = new StripeFulfillment();
        $requests = new StripePaymentRequestFulfillment();
        // A session opened for a core payment request (pay link) is not an order's.
        $forRequest = !empty($object['metadata']['payment_request_id']);

        try {
            switch ($event['type']) {
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $forRequest ? $requests->fulfillSession($object) : $fulfillment->fulfillSession($object);
                    break;
                case 'checkout.session.async_payment_failed':
                    if ($forRequest) {
                        gp247_report('StripePayment: delayed payment failed for payment request ' . $object['metadata']['payment_request_id'] . ' (session ' . ($object['id'] ?? '?') . ')');
                    } else {
                        $fulfillment->markAsyncFailed($object);
                    }
                    break;
                case 'refund.created':
                case 'refund.updated':
                case 'refund.failed':
                    // A refund of a payment request's PaymentIntent is handled there; any
                    // other goes to the order path as before.
                    if ($requests->syncRefund($object, $event['type']) === null) {
                        $fulfillment->syncRefund($object, $event['type']);
                    }
                    break;
                default:
                    // Not an event this plugin acts on.
                    break;
            }
        } catch (\Throwable $e) {
            gp247_report('StripePayment: webhook ' . ($event['id'] ?? '?') . ' (' . $event['type'] . ') failed: ' . $e->getMessage());
            return response('Error', 500);
        }

        return response('OK', 200);
    }

    /**
     * Checkout Session parameters for an order: one consolidated line for the order total,
     * so what Stripe charges always equals the order (Checkout takes no negative lines for
     * discounts, and per-line tax rounding would drift from the order total).
     *
     * @param ShopOrder $order
     * @return array<string, mixed>
     */
    private function sessionParams(ShopOrder $order): array
    {
        $orderId = (string) $order->id;
        $currency = strtolower((string) $order->currency);

        $summary = $order->details
            ->map(fn ($line) => trim((string) $line->name) . ' x ' . (float) $line->qty)
            ->implode(', ');

        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => StripeAmount::toMinor($order->total, $currency),
                    'product_data' => [
                        'name' => $this->lang('order_label', ['id' => $orderId]),
                        'description' => mb_substr($summary !== '' ? $summary : $this->lang('order_label', ['id' => $orderId]), 0, 500),
                    ],
                ],
            ]],
            'client_reference_id' => $orderId,
            'metadata' => ['order_id' => $orderId],
            'payment_intent_data' => ['metadata' => ['order_id' => $orderId]],
            'success_url' => stripepayment_success_url(),
            'cancel_url' => stripepayment_cancel_url(),
            'locale' => $this->stripeLocale(),
        ];

        if (filter_var((string) $order->email, FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = (string) $order->email;
        }

        return $params;
    }

    private function stripeLocale(): string
    {
        $locale = strtolower(substr((string) app()->getLocale(), 0, 3));
        $locale = rtrim($locale, '-_');

        return in_array($locale, self::STRIPE_LOCALES, true) ? $locale : 'auto';
    }

    /**
     * Cancel the in-flight order (stock back) and tell the shopper why, in plain words.
     *
     * @param string $message
     * @return \Illuminate\Http\RedirectResponse
     */
    private function abandon(string $message)
    {
        return (new ShopCartController)->cancelOrder()->with('error', $message);
    }

    private function lang(string $key, array $replace = []): string
    {
        return (string) trans('Plugins/StripePayment::lang.' . $key, $replace);
    }
}
