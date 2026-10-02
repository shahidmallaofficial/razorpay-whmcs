<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.2
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/bootstrap.php';

ignore_user_abort(true);

use RazorpayWhmcs\ApiException;
use RazorpayWhmcs\Gateway;
use RazorpayWhmcs\Logger;
use RazorpayWhmcs\OrderMapping;
use RazorpayWhmcs\Validator;

function razorpay_callback_redirect($url)
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, max-age=0');
        header('Location: ' . $url, true, 303);
    }

    exit;
}

function razorpay_callback_field($name)
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : null;
}

$params   = Gateway::params();
$fallback = Gateway::systemUrl($params) . 'clientarea.php?action=invoices';

if (!Gateway::isActive($params)) {
    razorpay_callback_redirect($fallback);
}

$invoiceId = razorpay_callback_field('merchant_order_id');

if ($invoiceId === null && isset($_GET['merchant_order_id']) && is_string($_GET['merchant_order_id'])) {
    $invoiceId = trim($_GET['merchant_order_id']);
}

if (!Validator::isInvoiceId($invoiceId)) {
    razorpay_callback_redirect($fallback);
}

$invoiceId = (int) $invoiceId;
$viewUrl   = Gateway::invoiceUrl($invoiceId, null, $params);
$failedUrl = Gateway::invoiceUrl($invoiceId, 'failed', $params);
$okUrl     = Gateway::invoiceUrl($invoiceId, 'success', $params);
$pendUrl   = Gateway::invoiceUrl($invoiceId, 'pending', $params);

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    razorpay_callback_redirect($viewUrl);
}

if (isset($_POST['error']) && is_array($_POST['error'])) {
    $error = array();
    foreach (array('code', 'description', 'source', 'step', 'reason') as $key) {
        if (isset($_POST['error'][$key]) && is_string($_POST['error'][$key])) {
            $error[$key] = substr($_POST['error'][$key], 0, 255);
        }
    }
    Logger::log('Callback', array('invoice_id' => $invoiceId) + $error, 'Payment Failed');
    razorpay_callback_redirect($failedUrl);
}

$paymentId = razorpay_callback_field('razorpay_payment_id');
$orderId   = razorpay_callback_field('razorpay_order_id');
$signature = razorpay_callback_field('razorpay_signature');

if (!Validator::isPaymentId($paymentId) || !Validator::isSignature($signature)) {
    razorpay_callback_redirect($failedUrl);
}

try {
    OrderMapping::ensureSchema();

    if (!Validator::isOrderId($orderId)) {
        $orderId  = null;
        $legacyKey = 'razorpay_order_id' . $invoiceId;
        if (isset($_SESSION[$legacyKey]) && Validator::isOrderId($_SESSION[$legacyKey])) {
            $orderId = $_SESSION[$legacyKey];
        } else {
            $latest = OrderMapping::latestForInvoice($invoiceId);
            if ($latest) {
                $orderId = $latest->razorpay_order_id;
            }
        }
    }

    if ($orderId === null || !Gateway::verifyPaymentSignature($orderId, $paymentId, $signature, isset($params['keySecret']) ? trim($params['keySecret']) : '')) {
        Logger::log('Callback', array(
            'invoice_id' => $invoiceId,
            'order_id'   => $orderId,
            'payment_id' => $paymentId,
            'reason'     => 'Signature verification failed',
        ), 'Rejected');
        razorpay_callback_redirect($failedUrl);
    }

    $row = OrderMapping::findByRazorpayOrderId($orderId);

    if (!$row) {
        $row = Gateway::adoptOrder($params, $orderId);
    }

    if (!$row || (int) $row->merchant_order_id !== $invoiceId) {
        Logger::log('Callback', array(
            'invoice_id' => $invoiceId,
            'order_id'   => $orderId,
            'payment_id' => $paymentId,
            'reason'     => 'Razorpay order does not belong to this invoice',
        ), 'Rejected');
        razorpay_callback_redirect($failedUrl);
    }

    try {
        $payment = Gateway::client($params)->fetchPayment($paymentId);
    } catch (ApiException $e) {
        Logger::log('Callback', array(
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'error'      => $e->getMessage(),
            'reason'     => 'Payment could not be fetched; it will be confirmed by the webhook, the invoice page or the reconciliation cron.',
        ), 'Pending');
        razorpay_callback_redirect($pendUrl);
    }

    if (isset($payment['status']) && in_array($payment['status'], array('failed', 'refunded'), true)) {
        razorpay_callback_redirect($failedUrl);
    }

    $result = Gateway::applyPayment($params, $row, $payment, 'callback');
} catch (\Throwable $e) {
    Logger::log('Callback', array('invoice_id' => $invoiceId, 'payment_id' => $paymentId, 'error' => $e->getMessage()), 'Error');
    razorpay_callback_redirect(Gateway::recordedInvoiceFor($paymentId) === $invoiceId ? $okUrl : $viewUrl);
}

switch ($result) {
    case Gateway::RESULT_APPLIED:
    case Gateway::RESULT_DUPLICATE:
        razorpay_callback_redirect($okUrl);
        break;
    case Gateway::RESULT_REJECTED:
        razorpay_callback_redirect($failedUrl);
        break;
    case Gateway::RESULT_CLOSED:
        razorpay_callback_redirect($viewUrl);
        break;
    case Gateway::RESULT_PENDING:
    case Gateway::RESULT_RETRY:
        razorpay_callback_redirect(Gateway::recordedInvoiceFor($paymentId) === $invoiceId ? $okUrl : $pendUrl);
        break;
    default:
        razorpay_callback_redirect(Gateway::recordedInvoiceFor($paymentId) === $invoiceId ? $okUrl : $viewUrl);
}
