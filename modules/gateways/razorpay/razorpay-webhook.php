<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.1
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

$maxBytes = 262144;

function razorpay_webhook_respond($code, $status)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode(array('status' => $status));
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    razorpay_webhook_respond(405, 'method not allowed');
}

$params = Gateway::params();

if (!Gateway::isActive($params)) {
    razorpay_webhook_respond(200, 'module inactive');
}

if (!isset($params['enableWebhook']) || $params['enableWebhook'] !== 'on') {
    razorpay_webhook_respond(200, 'webhook processing disabled');
}

$secret = isset($params['webhookSecret']) ? trim($params['webhookSecret']) : '';

if ($secret === '') {
    Logger::log('Webhook', 'Webhooks are enabled but no Webhook Secret is configured in WHMCS.', 'Configuration Error');
    razorpay_webhook_respond(503, 'webhook secret not configured');
}

if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $maxBytes) {
    razorpay_webhook_respond(413, 'payload too large');
}

$payload = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);

if (strlen($payload) > $maxBytes) {
    razorpay_webhook_respond(413, 'payload too large');
}

$signature = isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) ? trim((string) $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) : '';
$eventId   = isset($_SERVER['HTTP_X_RAZORPAY_EVENT_ID']) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_SERVER['HTTP_X_RAZORPAY_EVENT_ID']), 0, 64) : '';

if ($signature === '') {
    razorpay_webhook_respond(400, 'missing signature');
}

if (!Gateway::verifyWebhookSignature($payload, $signature, $secret)) {
    Logger::log('Webhook', array(
        'event_id' => $eventId,
        'bytes'    => strlen($payload),
        'reason'   => 'Invalid signature. Check that the Webhook Secret in WHMCS matches the one in the Razorpay Dashboard.',
    ), 'Rejected');
    razorpay_webhook_respond(401, 'invalid signature');
}

$data = json_decode($payload, true);

if (!is_array($data) || empty($data['event']) || !is_string($data['event'])) {
    razorpay_webhook_respond(400, 'invalid payload');
}

$event = $data['event'];

if (!in_array($event, array('order.paid', 'payment.captured', 'payment.authorized'), true)) {
    razorpay_webhook_respond(200, 'event ignored');
}

$payment = isset($data['payload']['payment']['entity']) && is_array($data['payload']['payment']['entity'])
    ? $data['payload']['payment']['entity']
    : null;

$order = isset($data['payload']['order']['entity']) && is_array($data['payload']['order']['entity'])
    ? $data['payload']['order']['entity']
    : null;

if ($payment === null || !isset($payment['id']) || !Validator::isPaymentId($payment['id'])) {
    razorpay_webhook_respond(200, 'no payment in event');
}

if (!empty($payment['invoice_id'])) {
    razorpay_webhook_respond(200, 'razorpay invoice payment ignored');
}

$orderId = isset($payment['order_id']) ? $payment['order_id'] : (isset($order['id']) ? $order['id'] : null);

if (!Validator::isOrderId($orderId)) {
    razorpay_webhook_respond(200, 'payment without order ignored');
}

try {
    OrderMapping::ensureSchema();

    $row = OrderMapping::findByRazorpayOrderId($orderId);

    if (!$row) {
        $row = Gateway::adoptOrder($params, $orderId, $order);
    }

    if (!$row) {
        razorpay_webhook_respond(200, 'order not created by this WHMCS installation');
    }

    if (Gateway::transactionExists($payment['id'])) {
        OrderMapping::markPaid($orderId, $payment['id']);
        razorpay_webhook_respond(200, Gateway::RESULT_DUPLICATE);
    }

    if ($event === 'payment.authorized') {
        if (Gateway::captureMode($params) === 'authorize') {
            razorpay_webhook_respond(200, 'awaiting manual capture');
        }

        $payment = Gateway::client($params)->fetchPayment($payment['id']);
    }

    $result = Gateway::applyPayment($params, $row, $payment, 'webhook:' . $event);
} catch (ApiException $e) {
    Logger::log('Webhook', array('event' => $event, 'event_id' => $eventId, 'order_id' => $orderId, 'error' => $e->getMessage()), 'Error');
    razorpay_webhook_respond($e->isTransient() ? 503 : 200, 'razorpay api error');
} catch (\Throwable $e) {
    Logger::log('Webhook', array('event' => $event, 'event_id' => $eventId, 'order_id' => $orderId, 'error' => $e->getMessage()), 'Error');
    razorpay_webhook_respond(500, 'internal error');
}

razorpay_webhook_respond($result === Gateway::RESULT_RETRY ? 503 : 200, $result);
