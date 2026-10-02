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

use RazorpayWhmcs\ApiException;
use RazorpayWhmcs\Currency;
use RazorpayWhmcs\Gateway;
use RazorpayWhmcs\Logger;
use RazorpayWhmcs\OrderMapping;
use RazorpayWhmcs\Validator;

function razorpay_checkout_respond(array $data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
    }

    echo json_encode($data);
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'Method not allowed.'), 405);
}

$params = Gateway::params();

if (!Gateway::isActive($params)) {
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'This payment method is not available.'), 503);
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$token  = Gateway::readToken(isset($_POST['token']) ? $_POST['token'] : '', isset($params['keySecret']) ? trim($params['keySecret']) : '');

if ($token === null || !isset($token['i'], $token['a'], $token['c'], $token['b']) || !Validator::isInvoiceId($token['i'])) {
    razorpay_checkout_respond(array('status' => 'reload', 'message' => 'Refreshing the page…'));
}

try {
    $invoice = Gateway::invoice($token['i']);
} catch (\Exception $e) {
    Logger::log('Checkout endpoint', array('invoice_id' => (int) $token['i'], 'error' => $e->getMessage()), 'Error');
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'Please try again in a moment.'), 500);
}

if (!$invoice) {
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'Invoice not found.'), 404);
}

$invoiceUrl = Gateway::invoiceUrl($invoice->id, null, $params);

if ($invoice->status === 'Paid') {
    razorpay_checkout_respond(array(
        'status'   => 'paid',
        'message'  => 'This invoice has been paid. Refreshing…',
        'redirect' => Gateway::invoiceUrl($invoice->id, 'success', $params),
    ));
}

if (!Gateway::isPayable($invoice) || abs((float) $invoice->balance - (float) $token['b']) > 0.009) {
    razorpay_checkout_respond(array('status' => 'reload', 'message' => 'This invoice was updated. Refreshing…', 'redirect' => $invoiceUrl));
}

$messages = array(
    'paid'       => 'Your payment has been received. Refreshing…',
    'pending'    => 'Your payment has been authorised and is awaiting confirmation. This invoice will be marked paid automatically.',
    'processing' => 'A payment for this invoice was received and is being confirmed. Please refresh this page in a minute.',
);

if ($action === 'status') {
    try {
        OrderMapping::ensureSchema();

        foreach (OrderMapping::findOpenForInvoice($invoice->id, Gateway::keyId($params), 3) as $row) {
            if ($row->checked_at !== null && strtotime($row->checked_at) > time() - 15) {
                continue;
            }

            $result = Gateway::reconcileOrder($params, $row);

            if ($result === Gateway::RESULT_APPLIED || $result === Gateway::RESULT_DUPLICATE) {
                razorpay_checkout_respond(array(
                    'status'   => 'paid',
                    'message'  => $messages['paid'],
                    'redirect' => Gateway::invoiceUrl($invoice->id, 'success', $params),
                ));
            }

            if ($result === Gateway::RESULT_PENDING) {
                razorpay_checkout_respond(array('status' => 'pending', 'message' => $messages['pending']));
            }
        }
    } catch (\Exception $e) {
        Logger::log('Checkout status', array('invoice_id' => $invoice->id, 'error' => $e->getMessage()), 'Error');
    }

    razorpay_checkout_respond(array('status' => 'none'));
}

if ($action !== 'order') {
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'Unknown action.'), 400);
}

$amount   = (int) $token['a'];
$currency = strtoupper((string) $token['c']);

if (!Validator::isCurrency($currency) || $amount < Currency::minimum($currency)) {
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'This amount cannot be paid online.'), 400);
}

try {
    $result = Gateway::resolveOrder($params, $invoice, $amount, $currency, true);
} catch (ApiException $e) {
    Logger::log('Create order', array(
        'invoice_id' => $invoice->id,
        'amount'     => $amount,
        'currency'   => $currency,
        'http'       => $e->getHttpStatus(),
        'code'       => $e->getErrorCode(),
        'error'      => $e->getMessage(),
    ), 'Error');

    $message = $e->isAuthenticationError()
        ? 'This payment method is temporarily unavailable. Please contact support.'
        : ($e->isTransient() ? 'Could not reach Razorpay. Please try again.' : 'Payment could not be started: ' . $e->getMessage());

    razorpay_checkout_respond(array('status' => 'error', 'message' => $message), 502);
} catch (\Exception $e) {
    Logger::log('Create order', array('invoice_id' => $invoice->id, 'error' => $e->getMessage()), 'Error');
    razorpay_checkout_respond(array('status' => 'error', 'message' => 'Please try again in a moment.'), 500);
}

if ($result['status'] === 'paid') {
    razorpay_checkout_respond(array(
        'status'   => 'paid',
        'message'  => $messages['paid'],
        'redirect' => Gateway::invoiceUrl($invoice->id, 'success', $params),
    ));
}

if ($result['status'] !== 'ok') {
    razorpay_checkout_respond(array('status' => $result['status'], 'message' => $messages[$result['status']]));
}

razorpay_checkout_respond(array(
    'status'   => 'ok',
    'order_id' => $result['order_id'],
    'amount'   => $amount,
    'currency' => $currency,
));
