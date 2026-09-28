<?php

namespace App\GP247\Plugins\StripePayment\Services;

/**
 * Converts between an order amount and Stripe's "smallest currency unit".
 *
 * Stripe expects integers in the currency's minor unit, and the rule is not the same for
 * every currency: 10.99 USD is 1099, but 500 JPY or 250000 VND are sent as-is. ISK and UGX
 * are whole-unit currencies that Stripe still reads with two decimals, and three-decimal
 * currencies must end in 0. One class owns the rule for both directions so what is sent
 * and what is recorded can never disagree (RISK-TECH-stripe-minor-unit-conversion).
 *
 * Source: docs.stripe.com/currencies (read 2026-09-28).
 *
 * @aidlc-unit plugin-stripe-payment
 */
final class StripeAmount
{
    /** Currencies charged in whole units, sent without multiplying. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Whole-unit currencies Stripe reads with two decimals for backward compatibility. */
    private const WHOLE_UNIT_TWO_DECIMAL = ['ISK', 'UGX'];

    /** Three-decimal currencies; Stripe requires the last digit to be 0. */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * @param float|int|string $amount   Amount in the currency's major unit.
     * @param string           $currency ISO code, any case.
     * @return int Amount in Stripe's minor unit.
     */
    public static function toMinor($amount, string $currency): int
    {
        $amount = (float) $amount;
        $code = strtoupper($currency);

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        if (in_array($code, self::WHOLE_UNIT_TWO_DECIMAL, true)) {
            return (int) round($amount) * 100;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return (int) round($amount * 100) * 10;
        }

        return (int) round($amount * 100);
    }

    /**
     * @param int|string $minor    Amount in Stripe's minor unit.
     * @param string     $currency ISO code, any case.
     * @return float Amount in the currency's major unit.
     */
    public static function fromMinor($minor, string $currency): float
    {
        $minor = (int) $minor;

        return round($minor / self::divisor($currency), 3);
    }

    /**
     * Whether two major-unit amounts are the same once expressed in Stripe's minor unit
     * (differences under half a minor unit are rounding noise).
     *
     * @param float  $a
     * @param float  $b
     * @param string $currency
     * @return bool
     */
    public static function matches(float $a, float $b, string $currency): bool
    {
        return abs($a - $b) < (0.5 / self::divisor($currency)) * self::step($currency);
    }

    /**
     * @param string $currency
     * @return int How many minor units make one major unit.
     */
    private static function divisor(string $currency): int
    {
        $code = strtoupper($currency);
        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 1;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 1000;
        }

        return 100;
    }

    /**
     * @param string $currency
     * @return int The smallest chargeable step, in minor units.
     */
    private static function step(string $currency): int
    {
        $code = strtoupper($currency);
        if (in_array($code, self::WHOLE_UNIT_TWO_DECIMAL, true)) {
            return 100;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 10;
        }

        return 1;
    }
}
