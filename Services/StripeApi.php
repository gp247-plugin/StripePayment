<?php

namespace App\GP247\Plugins\StripePayment\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Stripe REST client — the two Checkout Session calls the plugin needs.
 *
 * WHY no stripe/stripe-php: the plugin is installed by folder/ZIP on hosts that cannot run
 * `composer require`, and two form-encoded endpoints do not justify the dependency
 * (ADR stripe-payment_hosted-checkout-no-sdk). The API version is pinned so responses keep
 * the same shape whatever default the Stripe account is set to.
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-adr stripe-payment_hosted-checkout-no-sdk
 */
class StripeApi
{
    public const BASE_URL = 'https://api.stripe.com';

    /** Pinned Stripe API version (docs.stripe.com/upgrades, 2026-09-28). */
    public const API_VERSION = '2026-08-26.dahlia';

    private string $secretKey;

    /**
     * @param string|null $secretKey Secret API key; defaults to the effective store's key for the current mode.
     */
    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? stripepayment_secret_key();
    }

    /**
     * Whether a secret key is available at all.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * POST /v1/checkout/sessions
     *
     * @param array<string, mixed> $params         Form parameters (nested arrays allowed).
     * @param string               $idempotencyKey Same key + same params returns the same session.
     * @return array<string, mixed> The Checkout Session.
     * @throws StripeApiException
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->send('post', '/v1/checkout/sessions', $params, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * GET /v1/checkout/sessions/{id}
     *
     * @param string $sessionId cs_…
     * @return array<string, mixed>
     * @throws StripeApiException
     */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_-]+$/', $sessionId)) { // URL-safe: no '/', '?' or '.'
            throw new StripeApiException('Invalid Checkout Session id', 'invalid_session_id');
        }

        return $this->send('get', '/v1/checkout/sessions/' . $sessionId);
    }

    /**
     * @param string               $method  get|post
     * @param string               $path
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws StripeApiException
     */
    private function send(string $method, string $path, array $params = [], array $headers = []): array
    {
        if (!$this->configured()) {
            throw new StripeApiException('Stripe secret key is not configured', 'not_configured');
        }

        $request = Http::withToken($this->secretKey)
            ->withHeaders(array_merge(['Stripe-Version' => self::API_VERSION], $headers))
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(10);

        try {
            $response = $method === 'post'
                ? $request->asForm()->post(self::BASE_URL . $path, $params)
                : $request->get(self::BASE_URL . $path, $params);
        } catch (ConnectionException $e) {
            throw new StripeApiException('Cannot reach Stripe: ' . $e->getMessage(), 'connection_error');
        }

        $body = $response->json();
        if (!$response->successful() || !is_array($body)) {
            $error = is_array($body) ? ($body['error'] ?? []) : [];
            throw new StripeApiException(
                (string) ($error['message'] ?? ('HTTP ' . $response->status())),
                (string) ($error['code'] ?? $error['type'] ?? 'http_' . $response->status())
            );
        }

        return $body;
    }
}
