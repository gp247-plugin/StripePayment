<?php

namespace App\GP247\Plugins\StripePayment\Services;

/**
 * Verifies the Stripe-Signature header of a webhook request without the Stripe SDK.
 *
 * Header: "t=<unix time>,v1=<hex>[,v1=<hex>][,v0=<hex>]". The expected signature is
 * HMAC-SHA256 of "<t>.<raw body>" keyed with the endpoint's signing secret. Only the v1
 * scheme counts (v0 exists for tests and ignoring it prevents downgrade), comparison is
 * constant-time, and an old timestamp is refused to stop replays. Several v1 values appear
 * while a secret is being rolled, so any match is accepted.
 *
 * Source: docs.stripe.com/webhooks "Verify webhook signatures manually" (read 2026-09-28).
 *
 * @aidlc-unit plugin-stripe-payment
 * @aidlc-story US-stripe-payment-webhook-fulfillment
 */
final class WebhookSignature
{
    /** Stripe's own libraries default to five minutes. */
    public const TOLERANCE = 300;

    /**
     * @param string      $payload   Raw request body, exactly as received.
     * @param string|null $header    Stripe-Signature header value.
     * @param string      $secret    Endpoint signing secret (whsec_…).
     * @param int         $tolerance Maximum age of the timestamp, in seconds (> 0).
     * @param int|null    $now       Current unix time (injectable for tests).
     * @return bool
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::TOLERANCE, ?int $now = null): bool
    {
        // No secret configured means nothing can be trusted — never "accept until configured".
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && ctype_digit($pair[1])) {
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $now ??= time();
        if (abs($now - $timestamp) > max(1, $tolerance)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
