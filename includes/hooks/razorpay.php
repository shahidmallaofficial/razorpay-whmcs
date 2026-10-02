<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('AfterCronJob', 1, function () {
    $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 2);
    $lib  = $root . '/modules/gateways/razorpay/lib/bootstrap.php';

    if (!is_file($lib)) {
        return;
    }

    require_once $root . '/includes/gatewayfunctions.php';
    require_once $root . '/includes/invoicefunctions.php';
    require_once $lib;

    try {
        $results = \RazorpayWhmcs\Gateway::reconcilePending(20);

        $applied = array_keys($results, \RazorpayWhmcs\Gateway::RESULT_APPLIED, true);

        if (!empty($applied)) {
            \RazorpayWhmcs\Logger::log('Cron reconciliation', array('orders' => implode(', ', $applied)), 'Recovered');
        }
    } catch (\Throwable $e) {
        \RazorpayWhmcs\Logger::log('Cron reconciliation', $e->getMessage(), 'Error');
    }
});

add_hook('ClientAreaPageViewInvoice', 1, function ($vars) {
    if (isset($_GET['razorpay']) && $_GET['razorpay'] === 'pending' && isset($vars['status']) && $vars['status'] === 'Unpaid') {
        return array('paymentSuccess' => true, 'paymentSuccessAwaitingNotification' => true);
    }

    return array();
});
