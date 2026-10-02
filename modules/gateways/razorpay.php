<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/razorpay/lib/bootstrap.php';

use RazorpayWhmcs\ApiException;
use RazorpayWhmcs\Checkout;
use RazorpayWhmcs\Currency;
use RazorpayWhmcs\Gateway;
use RazorpayWhmcs\Logger;
use RazorpayWhmcs\OrderMapping;
use RazorpayWhmcs\Validator;

function razorpay_MetaData()
{
    return array(
        'DisplayName'                 => 'Razorpay',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    );
}

function razorpay_config()
{
    try {
        OrderMapping::ensureSchema();
    } catch (\Exception $e) {
        Logger::log('Schema', $e->getMessage(), 'Error');
    }

    $webhookUrl = Checkout::e(Gateway::moduleUrl('razorpay-webhook.php'));
    $https      = stripos(Gateway::systemUrl(), 'https://') === 0;

    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'Razorpay',
        ),
        'signUp' => array(
            'FriendlyName' => '',
            'Type'         => 'comment',
            'Description'  => '<a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=whmcs" target="_blank" rel="noopener">Sign up</a> for a Razorpay account or <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=whmcs" target="_blank" rel="noopener">log in</a> to an existing one.'
                . ($https ? '' : '<br><strong style="color:#c0392b">Your WHMCS System URL does not use https://. Set an https System URL (Setup &gt; General Settings) so payments are not interrupted.</strong>'),
        ),
        'keyId' => array(
            'FriendlyName' => 'Key Id',
            'Type'         => 'text',
            'Size'         => '50',
            'Description'  => 'Starts with <code>rzp_live_</code> (or <code>rzp_test_</code> for testing). Find it in the <a href="https://dashboard.razorpay.com/app/website-app-settings/api-keys" target="_blank" rel="noopener">Razorpay Dashboard &gt; API Keys</a>.',
        ),
        'keySecret' => array(
            'FriendlyName' => 'Key Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'Shown once when the API key is generated.',
        ),
        'paymentAction' => array(
            'FriendlyName' => 'Payment Action',
            'Type'         => 'dropdown',
            'Options'      => array(
                'capture'   => 'Authorize and Capture',
                'authorize' => 'Authorize only (capture manually)',
            ),
            'Default'      => 'capture',
            'Description'  => 'With "Authorize only", capture each payment in the Razorpay Dashboard within the authorization window. The invoice is marked paid once the payment is captured (requires the webhook or the cron hook).',
        ),
        'refundSpeed' => array(
            'FriendlyName' => 'Refund Speed',
            'Type'         => 'dropdown',
            'Options'      => array(
                'normal'  => 'Normal (5-7 working days)',
                'optimum' => 'Optimum (instant where possible, additional fees apply)',
            ),
            'Default'      => 'normal',
            'Description'  => 'Used for refunds issued from WHMCS.',
        ),
        'manualCheckout' => array(
            'FriendlyName' => 'Disable Auto-Open',
            'Type'         => 'yesno',
            'Description'  => 'Tick to stop Razorpay Checkout opening automatically after a customer places an order. Customers will click "Pay Now" instead.',
        ),
        'themeColor' => array(
            'FriendlyName' => 'Checkout Colour',
            'Type'         => 'text',
            'Size'         => '10',
            'Description'  => 'Optional brand colour for Razorpay Checkout, for example <code>#2563EB</code>.',
        ),
        'enableWebhook' => array(
            'FriendlyName' => 'Enable Webhook',
            'Type'         => 'yesno',
            'Description'  => 'Strongly recommended. In the <a href="https://dashboard.razorpay.com/app/webhooks" target="_blank" rel="noopener">Razorpay Dashboard &gt; Webhooks</a>, add this URL with the events <code>order.paid</code>, <code>payment.captured</code> and <code>payment.authorized</code>:<br><code style="user-select:all">' . $webhookUrl . '</code>',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'Must match the secret entered for the webhook in the Razorpay Dashboard.',
        ),
        'about' => array(
            'FriendlyName' => '',
            'Type'         => 'comment',
            'Description'  => 'Razorpay for WHMCS v' . Gateway::VERSION . ' &middot; Developed by <a href="https://shahidmalla.com" target="_blank" rel="noopener">Shahid Malla</a>',
        ),
    );
}

function razorpay_config_validate(array $params)
{
    $keyId  = isset($params['keyId']) ? trim($params['keyId']) : '';
    $secret = isset($params['keySecret']) ? trim($params['keySecret']) : '';

    if (!Validator::isKeyId($keyId)) {
        Gateway::invalidConfiguration('Key Id must look like rzp_live_XXXXXXXXXXXXXX or rzp_test_XXXXXXXXXXXXXX.');
    }

    if ($secret === '') {
        Gateway::invalidConfiguration('Key Secret is required.');
    }

    if (!empty($params['themeColor']) && !Validator::isHexColor(trim($params['themeColor']))) {
        Gateway::invalidConfiguration('Checkout Colour must be a hex colour such as #2563EB.');
    }

    if (isset($params['enableWebhook']) && $params['enableWebhook'] === 'on' && (!isset($params['webhookSecret']) || trim($params['webhookSecret']) === '')) {
        Gateway::invalidConfiguration('Webhook Secret is required when the webhook is enabled.');
    }

    try {
        Gateway::client(array('keyId' => $keyId, 'keySecret' => $secret))->request('GET', 'orders', array('count' => 1));
    } catch (ApiException $e) {
        if ($e->isAuthenticationError()) {
            Gateway::invalidConfiguration('Razorpay rejected this Key Id / Key Secret. Check that both belong to the same key and mode (test or live).');
        }

        Logger::log('Validate configuration', array('http' => $e->getHttpStatus(), 'error' => $e->getMessage()), 'Notice');
    }
}

function razorpay_link($params)
{
    if (!isset($params['manualCheckout']) || $params['manualCheckout'] !== 'on') {
        $params['autoOpen'] = 'on';
    }

    return Checkout::render($params);
}

function razorpay_adminstatusmsg($params)
{
    $invoiceId = isset($params['invoiceid']) ? $params['invoiceid'] : (isset($params['id']) ? $params['id'] : null);

    if (!Validator::isInvoiceId($invoiceId)) {
        return array();
    }

    try {
        OrderMapping::ensureSchema();
        $rows = OrderMapping::forInvoice($invoiceId, 5);
    } catch (\Exception $e) {
        return array();
    }

    if (count($rows) === 0) {
        return array();
    }

    $lines = array();
    foreach ($rows as $row) {
        $line = Checkout::e($row->razorpay_order_id) . ' &middot; ' . Checkout::e($row->status ?: 'created');
        if ($row->amount !== null) {
            $line .= ' &middot; ' . Checkout::e(Currency::format(Currency::fromSubunits($row->amount, $row->currency))) . ' ' . Checkout::e($row->currency);
        }
        if ($row->razorpay_payment_id) {
            $line .= ' &middot; ' . Checkout::e($row->razorpay_payment_id);
        }
        if ($row->created_at) {
            $line .= ' &middot; ' . Checkout::e($row->created_at);
        }
        $lines[] = $line;
    }

    return array(
        'type'  => 'info',
        'title' => 'Razorpay orders for this invoice',
        'msg'   => implode('<br>', $lines),
    );
}

function razorpay_refund($params)
{
    $paymentId = isset($params['transid']) ? trim($params['transid']) : '';

    if (!Validator::isPaymentId($paymentId)) {
        return array('status' => 'error', 'rawdata' => 'Not a Razorpay payment id: ' . $paymentId);
    }

    $client = Gateway::client($params);

    try {
        $payment = $client->fetchPayment($paymentId);

        if (!isset($payment['status']) || !in_array($payment['status'], array('captured', 'refunded'), true)) {
            return array(
                'status'  => 'declined',
                'rawdata' => 'Only captured payments can be refunded. Current status: ' . (isset($payment['status']) ? $payment['status'] : 'unknown'),
            );
        }

        $refundable = (int) $payment['amount'] - (int) (isset($payment['amount_refunded']) ? $payment['amount_refunded'] : 0);
        $amount     = Gateway::refundSubunits($params, $payment);

        if ($amount > $refundable && $amount - $refundable <= 1) {
            $amount = $refundable;
        }

        if ($amount <= 0 || $amount > $refundable) {
            return array(
                'status'  => 'declined',
                'rawdata' => 'Refund amount ' . $amount . ' exceeds the refundable balance ' . $refundable . ' ' . $payment['currency'] . ' (subunits).',
            );
        }

        $speed = (isset($params['refundSpeed']) && $params['refundSpeed'] === 'optimum') ? 'optimum' : 'normal';
        $key   = 'whmcs-' . substr(hash('sha256', $paymentId . '|' . $amount . '|' . Gateway::refundSequence($paymentId, $payment)), 0, 40);

        $refund = $client->refundPayment($paymentId, array(
            'amount' => $amount,
            'speed'  => $speed,
            'notes'  => array(
                'whmcs_invoice_id' => isset($params['invoiceid']) ? (string) $params['invoiceid'] : '',
                'source'           => 'whmcs',
            ),
        ), $key);

        return array(
            'status'  => 'success',
            'rawdata' => $refund,
            'transid' => isset($refund['id']) ? $refund['id'] : $paymentId,
            'fees'    => 0,
        );
    } catch (ApiException $e) {
        return array(
            'status'  => $e->isTransient() ? 'error' : 'declined',
            'rawdata' => array(
                'http'  => $e->getHttpStatus(),
                'code'  => $e->getErrorCode(),
                'error' => $e->getMessage(),
            ),
        );
    }
}

function razorpay_TransactionInformation(array $params = array())
{
    $transactionId = isset($params['transactionId']) ? trim($params['transactionId']) : '';

    if (!class_exists('\WHMCS\Billing\Payment\Transaction\Information')) {
        return null;
    }

    $information = new \WHMCS\Billing\Payment\Transaction\Information();
    $information->setTransactionId($transactionId);

    $isRefund = Validator::isRefundId($transactionId);

    if (!$isRefund && !Validator::isPaymentId($transactionId)) {
        return $information->setDescription('Not a Razorpay payment or refund ID.');
    }

    try {
        $client = Gateway::client(Gateway::params());
        $entity = $isRefund ? $client->fetchRefund($transactionId) : $client->fetchPayment($transactionId);
    } catch (ApiException $e) {
        return $information->setDescription('Could not load this transaction from Razorpay: ' . $e->getMessage());
    }

    $currencyCode = isset($entity['currency']) ? (string) $entity['currency'] : 'INR';

    $information->setAmount((float) Currency::fromSubunits(isset($entity['amount']) ? $entity['amount'] : 0, $currencyCode))
        ->setType($isRefund ? 'refund' : (isset($entity['method']) ? (string) $entity['method'] : 'payment'))
        ->setStatus(isset($entity['status']) ? (string) $entity['status'] : '');

    if (!empty($entity['description'])) {
        $information->setDescription((string) $entity['description']);
    }

    $currency = Gateway::whmcsCurrency($currencyCode);
    if ($currency !== null) {
        try {
            $information->setCurrency($currency);
        } catch (\Throwable $e) {
        }
    }

    if (!$isRefund && !empty($entity['fee'])) {
        $inr = Gateway::whmcsCurrency('INR');
        try {
            $information->setFee((float) Currency::fromSubunits($entity['fee'], 'INR'), $inr);
        } catch (\Throwable $e) {
            $information->setFee((float) Currency::fromSubunits($entity['fee'], 'INR'));
        }
    }

    if (!empty($entity['created_at']) && class_exists('\WHMCS\Carbon')) {
        $information->setCreated(\WHMCS\Carbon::createFromTimestamp((int) $entity['created_at']));
    }

    $fields = $isRefund
        ? array('payment_id', 'speed_requested', 'speed_processed', 'receipt')
        : array('order_id', 'amount_refunded', 'refund_status', 'email', 'contact', 'vpa', 'bank', 'wallet');

    foreach ($fields as $field) {
        if (isset($entity[$field]) && $entity[$field] !== '' && is_scalar($entity[$field])) {
            $information->setAdditionalDatum($field, (string) $entity[$field]);
        }
    }

    return $information;
}
