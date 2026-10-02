<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

if (!defined('RAZORPAY_WHMCS_LIB')) {
    define('RAZORPAY_WHMCS_LIB', __DIR__);

    spl_autoload_register(function ($class) {
        $prefix = 'RazorpayWhmcs\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));

        if (preg_match('/^[A-Za-z]+$/', $relative) !== 1) {
            return;
        }

        $file = RAZORPAY_WHMCS_LIB . '/' . $relative . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}
