<?php

namespace App\GP247\Plugins\StripePayment\Services;

use RuntimeException;

/**
 * A Stripe API call that did not return a usable object. The message is Stripe's own
 * (never contains the secret key); the code is Stripe's error code when it gave one.
 *
 * @aidlc-unit plugin-stripe-payment
 */
class StripeApiException extends RuntimeException
{
    private string $stripeCode;

    public function __construct(string $message, string $stripeCode = '')
    {
        parent::__construct($message);
        $this->stripeCode = $stripeCode;
    }

    public function stripeCode(): string
    {
        return $this->stripeCode;
    }
}
