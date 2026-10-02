<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class Currency
{
    private static $exponents = array(
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,
    );

    private static $minimums = array(
        'INR' => 100,
    );

    public static function exponent($currency)
    {
        $currency = strtoupper((string) $currency);

        return isset(self::$exponents[$currency]) ? self::$exponents[$currency] : 2;
    }

    public static function toSubunits($amount, $currency)
    {
        $exponent = self::exponent($currency);
        $decimals = min($exponent, 2);
        $value    = number_format(round((float) $amount, $decimals), $decimals, '.', '');

        list($whole, $fraction) = array_pad(explode('.', $value, 2), 2, '');

        $digits = $whole . str_pad($fraction, $exponent, '0');

        return (int) ltrim($digits, '0');
    }

    public static function fromSubunits($subunits, $currency)
    {
        $exponent = self::exponent($currency);

        return round(((int) $subunits) / pow(10, $exponent), 2);
    }

    public static function minimum($currency)
    {
        $currency = strtoupper((string) $currency);

        return isset(self::$minimums[$currency]) ? self::$minimums[$currency] : 1;
    }

    public static function format($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
