<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class Validator
{
    public static function isInvoiceId($value)
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,19}$/D', (string) $value) === 1;
    }

    public static function isOrderId($value)
    {
        return is_string($value) && preg_match('/^order_[A-Za-z0-9]{6,40}$/D', $value) === 1;
    }

    public static function isPaymentId($value)
    {
        return is_string($value) && preg_match('/^pay_[A-Za-z0-9]{6,40}$/D', $value) === 1;
    }

    public static function isRefundId($value)
    {
        return is_string($value) && preg_match('/^rfnd_[A-Za-z0-9]{6,40}$/D', $value) === 1;
    }

    public static function isSignature($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function isKeyId($value)
    {
        return is_string($value) && preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,40}$/D', trim($value)) === 1;
    }

    public static function isTestKey($value)
    {
        return is_string($value) && strpos(trim($value), 'rzp_test_') === 0;
    }

    public static function isCurrency($value)
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/D', strtoupper($value)) === 1;
    }

    public static function isHexColor($value)
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1;
    }
}
