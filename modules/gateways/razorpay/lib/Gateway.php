<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

use WHMCS\Database\Capsule;

class Gateway
{
    const VERSION = '3.0.0';
    const MODULE  = 'razorpay';

    const RESULT_APPLIED   = 'applied';
    const RESULT_DUPLICATE = 'duplicate';
    const RESULT_REJECTED  = 'rejected';
    const RESULT_CLOSED    = 'invoice-closed';
    const RESULT_RETRY     = 'retry';
    const RESULT_PENDING   = 'pending';

    private static $params = null;

    public static function params()
    {
        if (self::$params === null) {
            self::$params = function_exists('getGatewayVariables') ? getGatewayVariables(self::MODULE) : array();
            if (!is_array(self::$params)) {
                self::$params = array();
            }
        }

        return self::$params;
    }

    public static function isActive(array $params)
    {
        return !empty($params['type']);
    }

    public static function client(array $params)
    {
        $whmcsVersion = isset($params['whmcsVersion']) ? $params['whmcsVersion'] : self::whmcsVersion();

        $userAgent = 'Razorpay-WHMCS/' . self::VERSION . ' (+https://shahidmalla.com) WHMCS/' . $whmcsVersion . ' PHP/' . PHP_VERSION;

        return new Client(
            isset($params['keyId']) ? $params['keyId'] : '',
            isset($params['keySecret']) ? $params['keySecret'] : '',
            $userAgent
        );
    }

    public static function whmcsVersion()
    {
        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                $version = (string) \WHMCS\Config\Setting::getValue('Version');
                if ($version !== '') {
                    return $version;
                }
            }
        } catch (\Exception $e) {
        }

        return isset($GLOBALS['CONFIG']['Version']) ? (string) $GLOBALS['CONFIG']['Version'] : '';
    }

    public static function systemUrl(array $params = array())
    {
        $url = '';

        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
            }
        } catch (\Exception $e) {
        }

        if ($url === '' && !empty($params['systemurl'])) {
            $url = (string) $params['systemurl'];
        }

        if ($url === '' && !empty($GLOBALS['CONFIG']['SystemURL'])) {
            $url = (string) $GLOBALS['CONFIG']['SystemURL'];
        }

        return rtrim(trim($url), '/') . '/';
    }

    public static function moduleUrl($file, array $params = array())
    {
        return self::systemUrl($params) . 'modules/gateways/razorpay/' . ltrim($file, '/');
    }

    public static function invoiceUrl($invoiceId, $state = null, array $params = array())
    {
        $url = self::systemUrl($params) . 'viewinvoice.php?id=' . (int) $invoiceId;

        if ($state === 'success') {
            $url .= '&paymentsuccess=true';
        } elseif ($state === 'failed') {
            $url .= '&paymentfailed=true';
        } elseif ($state === 'pending') {
            $url .= '&razorpay=pending';
        }

        return $url;
    }

    public static function siteId(array $params = array())
    {
        $url = preg_replace('#^https?://#i', '', rtrim(self::systemUrl($params), '/'));

        return substr(strtolower((string) $url), 0, 200);
    }

    public static function keyId(array $params)
    {
        return isset($params['keyId']) ? trim((string) $params['keyId']) : '';
    }

    public static function captureMode(array $params)
    {
        return (isset($params['paymentAction']) && $params['paymentAction'] === 'authorize') ? 'authorize' : 'capture';
    }

    public static function invoice($invoiceId)
    {
        if (!Validator::isInvoiceId($invoiceId)) {
            return null;
        }

        $invoice = Capsule::table('tblinvoices as i')
            ->leftJoin('tblclients as c', 'c.id', '=', 'i.userid')
            ->leftJoin('tblcurrencies as cur', 'cur.id', '=', 'c.currency')
            ->where('i.id', (int) $invoiceId)
            ->select('i.id', 'i.userid', 'i.status', 'i.total', 'i.paymentmethod', 'cur.id as currency_id', 'cur.code as currency')
            ->first();

        if (!$invoice) {
            return null;
        }

        $paid = Capsule::table('tblaccounts')
            ->where('invoiceid', (int) $invoiceId)
            ->sum(Capsule::raw('amountin - amountout'));

        $invoice->balance = round((float) $invoice->total - (float) $paid, 2);

        return $invoice;
    }

    public static function isPayable($invoice)
    {
        return $invoice && in_array($invoice->status, array('Unpaid', 'Payment Pending'), true);
    }

    public static function resolveOrder(array $params, $invoice, $amount, $currency, $verifyRemote)
    {
        OrderMapping::ensureSchema();

        $currency = strtoupper($currency);
        $existing = OrderMapping::findReusable($invoice->id, $amount, $currency, self::keyId($params), self::captureMode($params));

        if ($existing && $existing->created_at !== null && strtotime($existing->created_at) < time() - 7 * 86400) {
            $existing = null;
        }

        if ($existing && $verifyRemote) {
            try {
                $order  = self::client($params)->fetchOrder($existing->razorpay_order_id);
                $status = isset($order['status']) ? $order['status'] : '';

                if ($status === 'paid' || $status === 'attempted') {
                    $result = self::reconcileOrder($params, $existing, $order);

                    if ($result === self::RESULT_APPLIED || $result === self::RESULT_DUPLICATE) {
                        return array('status' => 'paid');
                    }

                    if ($result === self::RESULT_PENDING) {
                        return array('status' => 'pending');
                    }

                    if ($status === 'paid') {
                        if ($result === self::RESULT_RETRY) {
                            return array('status' => 'processing');
                        }

                        OrderMapping::markStatus(
                            $existing->razorpay_order_id,
                            $result === self::RESULT_REJECTED ? OrderMapping::STATUS_REVIEW : OrderMapping::STATUS_INVALID
                        );
                        $existing = null;
                    }
                }

                if ($existing && ((int) $order['amount'] !== (int) $amount || strtoupper($order['currency']) !== $currency)) {
                    OrderMapping::markStatus($existing->razorpay_order_id, OrderMapping::STATUS_INVALID);
                    $existing = null;
                }
            } catch (ApiException $e) {
                if (self::isMissing($e)) {
                    OrderMapping::markStatus($existing->razorpay_order_id, OrderMapping::STATUS_INVALID);
                    $existing = null;
                }
            }
        }

        if ($existing) {
            OrderMapping::touch($existing->razorpay_order_id);
            return array('status' => 'ok', 'order_id' => $existing->razorpay_order_id);
        }

        $order = self::createOrder($params, $invoice, $amount, $currency);

        if (!isset($order['id']) || !Validator::isOrderId($order['id'])) {
            throw new ApiException('Razorpay returned an invalid order.', 502);
        }

        OrderMapping::insert($invoice->id, $order['id'], $amount, $currency, Currency::format($invoice->balance), self::keyId($params), self::captureMode($params));

        return array('status' => 'ok', 'order_id' => $order['id']);
    }

    private static function createOrder(array $params, $invoice, $amount, $currency)
    {
        $client  = self::client($params);
        $receipt = 'WHMCS-' . $invoice->id . '-' . base_convert((string) time(), 10, 36) . bin2hex(self::randomBytes(3));

        $data = array(
            'amount'          => (int) $amount,
            'currency'        => $currency,
            'receipt'         => substr($receipt, 0, 40),
            'payment_capture' => self::captureMode($params) === 'authorize' ? 0 : 1,
            'notes'           => array(
                'whmcs_order_id' => (string) $invoice->id,
                'whmcs_site'     => self::siteId($params),
            ),
        );

        try {
            return $client->createOrder($data);
        } catch (ApiException $e) {
            if ($e->getHttpStatus() === 400 && stripos($e->getMessage() . ' ' . $e->getField(), 'capture') !== false) {
                Logger::log('Create order', array(
                    'invoice_id' => $invoice->id,
                    'reason'     => 'Razorpay rejected payment_capture; the order now follows the capture setting in your Razorpay Dashboard.',
                ), 'Notice');
                unset($data['payment_capture']);
                return $client->createOrder($data);
            }

            if ($e->isTransient()) {
                $existing = $client->findOrderByReceipt($data['receipt']);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private static function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }

        return substr(md5(uniqid((string) mt_rand(), true), true), 0, $length);
    }

    public static function adoptOrder(array $params, $orderId, $order = null)
    {
        if (!Validator::isOrderId($orderId)) {
            return null;
        }

        try {
            $order = self::client($params)->fetchOrder($orderId);
        } catch (ApiException $e) {
            return null;
        }

        $notes = isset($order['notes']) && is_array($order['notes']) ? $order['notes'] : array();

        if (!isset($notes['whmcs_order_id'], $notes['whmcs_site'])
            || $notes['whmcs_site'] !== self::siteId($params)
            || !Validator::isInvoiceId($notes['whmcs_order_id'])
            || !isset($order['id']) || $order['id'] !== $orderId
        ) {
            return null;
        }

        OrderMapping::insert($notes['whmcs_order_id'], $orderId, (int) $order['amount'], $order['currency'], null, self::keyId($params), null);

        Logger::log('Adopt order', array('invoice_id' => $notes['whmcs_order_id'], 'order_id' => $orderId), 'Recovered');

        return OrderMapping::findByRazorpayOrderId($orderId);
    }

    public static function reconcileOrder(array $params, $row, $order = null)
    {
        $client = self::client($params);

        try {
            if ($order === null) {
                $order = $client->fetchOrder($row->razorpay_order_id);
            }

            if (!isset($order['status']) || ($order['status'] !== 'paid' && $order['status'] !== 'attempted')) {
                OrderMapping::markChecked($row->razorpay_order_id);
                return null;
            }

            $payments = $client->fetchOrderPayments($row->razorpay_order_id);
        } catch (ApiException $e) {
            if (self::isMissing($e)) {
                OrderMapping::markStatus($row->razorpay_order_id, OrderMapping::STATUS_INVALID);
                return null;
            }

            OrderMapping::markChecked($row->razorpay_order_id);

            Logger::log('Reconcile order', array(
                'invoice_id' => $row->merchant_order_id,
                'order_id'   => $row->razorpay_order_id,
                'error'      => $e->getMessage(),
            ), 'Error');
            return self::RESULT_RETRY;
        }

        $items  = isset($payments['items']) && is_array($payments['items']) ? $payments['items'] : array();
        $result = null;

        usort($items, function ($a, $b) {
            $rank = array('captured' => 0, 'authorized' => 1);
            $ra = isset($a['status'], $rank[$a['status']]) ? $rank[$a['status']] : 9;
            $rb = isset($b['status'], $rank[$b['status']]) ? $rank[$b['status']] : 9;
            return $ra - $rb;
        });

        foreach ($items as $payment) {
            if (isset($payment['status']) && in_array($payment['status'], array('captured', 'authorized'), true)) {
                $result = self::applyPayment($params, $row, $payment, 'reconcile');
                if (in_array($result, array(self::RESULT_APPLIED, self::RESULT_DUPLICATE, self::RESULT_PENDING, self::RESULT_CLOSED), true)) {
                    break;
                }
            }
        }

        OrderMapping::markChecked($row->razorpay_order_id);

        return $result;
    }

    public static function applyPayment(array $params, $row, array $payment, $source)
    {
        $paymentId = isset($payment['id']) ? $payment['id'] : '';
        $invoiceId = (int) $row->merchant_order_id;

        $context = array(
            'source'     => $source,
            'invoice_id' => $invoiceId,
            'order_id'   => $row->razorpay_order_id,
            'payment_id' => $paymentId,
            'status'     => isset($payment['status']) ? $payment['status'] : null,
            'method'     => isset($payment['method']) ? $payment['method'] : null,
            'amount'     => isset($payment['amount']) ? $payment['amount'] : null,
            'currency'   => isset($payment['currency']) ? $payment['currency'] : null,
        );

        if (!Validator::isPaymentId($paymentId)) {
            Logger::log('Apply payment', $context + array('reason' => 'Invalid payment id'), 'Rejected');
            return self::RESULT_REJECTED;
        }

        if (!isset($payment['order_id']) || $payment['order_id'] !== $row->razorpay_order_id) {
            Logger::log('Apply payment', $context + array('reason' => 'Payment does not belong to this Razorpay order'), 'Rejected');
            return self::RESULT_REJECTED;
        }

        if (!empty($row->key_id) && $row->key_id !== self::keyId($params)) {
            Logger::log('Apply payment', $context + array('reason' => 'Order was created with a different API key (test/live switch)'), 'Rejected');
            return self::RESULT_REJECTED;
        }

        if ($row->currency !== null && (!isset($payment['currency']) || strtoupper($payment['currency']) !== strtoupper($row->currency))) {
            Logger::log('Apply payment', $context + array('reason' => 'Currency mismatch', 'expected' => $row->currency), 'Rejected');
            return self::RESULT_REJECTED;
        }

        if ($row->amount !== null && isset($payment['amount']) && (int) $payment['amount'] !== (int) $row->amount) {
            $expected = (int) $row->amount;
            $paid     = (int) $payment['amount'];

            if ($paid <= 0 || $paid > (int) ceil($expected * 1.15)) {
                Logger::log('Apply payment', $context + array('reason' => 'Amount mismatch', 'expected' => $expected), 'Rejected');
                return self::RESULT_REJECTED;
            }

            $context['adjustment'] = $paid < $expected ? 'Razorpay offer discount ' . ($expected - $paid) : 'Customer fee ' . ($paid - $expected);
        }

        $lock = self::lock('rzp_whmcs_inv_' . $invoiceId);

        if ($lock === false) {
            Logger::log('Apply payment', $context + array('reason' => 'Invoice is locked by another request'), 'Retry');
            return self::RESULT_RETRY;
        }

        try {
            if (self::transactionExists($paymentId)) {
                OrderMapping::markPaid($row->razorpay_order_id, $paymentId);
                return self::RESULT_DUPLICATE;
            }

            $invoice = self::invoice($invoiceId);

            if (!self::isPayable($invoice)) {
                if (isset($payment['status']) && $payment['status'] === 'captured') {
                    OrderMapping::markStatus($row->razorpay_order_id, OrderMapping::STATUS_REVIEW, $paymentId);
                }
                Logger::log('Apply payment', $context + array(
                    'invoice_status' => $invoice ? $invoice->status : 'missing',
                    'reason'         => 'Invoice is not payable. Review this Razorpay payment manually (refund or add as credit).',
                ), 'Manual Review');
                return self::RESULT_CLOSED;
            }

            $status = isset($payment['status']) ? $payment['status'] : null;

            if ($status === 'authorized') {
                if (self::captureMode($params) === 'authorize') {
                    OrderMapping::touch($row->razorpay_order_id);
                    Logger::log('Apply payment', $context + array('reason' => 'Payment authorized; the invoice will be marked paid once it is captured in the Razorpay Dashboard.'), 'Pending Capture');
                    return self::RESULT_PENDING;
                }

                $payment = self::capture($params, $payment, $context);
                $status  = isset($payment['status']) ? $payment['status'] : null;

                if ($status !== 'captured') {
                    return self::RESULT_RETRY;
                }
            }

            if ($status !== 'captured') {
                Logger::log('Apply payment', $context + array('reason' => 'Payment is not captured'), 'Rejected');
                return self::RESULT_REJECTED;
            }

            $amount = self::invoiceAmount($row, $payment, $invoice);
            $fee    = self::invoiceFee($payment, $invoice);

            if (!addInvoicePayment($invoiceId, $paymentId, $amount, $fee, self::MODULE)) {
                Logger::log('Apply payment', $context + array('reason' => 'addInvoicePayment returned false'), 'Error');
                return self::RESULT_RETRY;
            }

            OrderMapping::markPaid($row->razorpay_order_id, $paymentId);

            Logger::log('Apply payment', $context + array('credited' => $amount, 'fee' => $fee), 'Successful');

            return self::RESULT_APPLIED;
        } finally {
            self::unlock($lock);
        }
    }

    private static function capture(array $params, array $payment, array $context)
    {
        $client = self::client($params);

        try {
            return $client->capturePayment($payment['id'], (int) $payment['amount'], strtoupper($payment['currency']));
        } catch (ApiException $e) {
            try {
                $fresh = $client->fetchPayment($payment['id']);
                if (isset($fresh['status']) && $fresh['status'] === 'captured') {
                    return $fresh;
                }
            } catch (ApiException $ignored) {
            }

            Logger::log('Capture payment', $context + array('error' => $e->getMessage()), 'Error');

            return $payment;
        }
    }

    private static function invoiceAmount($row, array $payment, $invoice)
    {
        if ($row->invoice_amount !== null && (float) $row->invoice_amount > 0) {
            return Currency::format($row->invoice_amount);
        }

        $currency = isset($payment['currency']) ? strtoupper($payment['currency']) : ($row->currency ?: $invoice->currency);
        $subunits = isset($payment['amount']) ? (int) $payment['amount'] : (int) $row->amount;
        $paid     = Currency::fromSubunits($subunits, $currency);

        if ($currency === strtoupper((string) $invoice->currency)) {
            return Currency::format($paid);
        }

        $converted = self::convert($paid, $currency, $invoice->currency_id);

        return Currency::format($converted !== null ? $converted : $invoice->balance);
    }

    private static function invoiceFee(array $payment, $invoice)
    {
        if (empty($payment['fee'])) {
            return 0;
        }

        $fee = Currency::fromSubunits((int) $payment['fee'], 'INR');

        if (strtoupper((string) $invoice->currency) === 'INR') {
            return Currency::format($fee);
        }

        $converted = self::convert($fee, 'INR', $invoice->currency_id);

        return $converted !== null ? Currency::format($converted) : 0;
    }

    private static function convert($amount, $fromCode, $toCurrencyId)
    {
        if (!function_exists('convertCurrency') || !$toCurrencyId) {
            return null;
        }

        $from = Capsule::table('tblcurrencies')->where('code', strtoupper($fromCode))->value('id');

        if (!$from) {
            return null;
        }

        return (float) convertCurrency($amount, $from, $toCurrencyId);
    }

    public static function reconcilePending($maxOrders = 20)
    {
        $params = self::params();

        if (!self::isActive($params) || self::keyId($params) === '' || empty($params['keySecret'])) {
            return array();
        }

        OrderMapping::ensureSchema();

        $results = array();

        foreach (OrderMapping::pendingForReconciliation(self::keyId($params), 7 * 86400, 600, $maxOrders) as $row) {
            $result = self::reconcileOrder($params, $row);

            if ($result !== null) {
                $results[$row->razorpay_order_id] = $result;
            }
        }

        return $results;
    }

    public static function isMissing(ApiException $e)
    {
        return in_array($e->getHttpStatus(), array(400, 404), true) && stripos($e->getMessage(), 'does not exist') !== false;
    }

    public static function whmcsCurrency($code)
    {
        if (!class_exists('\WHMCS\Billing\Currency')) {
            return null;
        }

        try {
            $currency = \WHMCS\Billing\Currency::where('code', strtoupper((string) $code))->first();

            if ($currency) {
                return $currency;
            }

            $currency = new \WHMCS\Billing\Currency();

            if (method_exists($currency, 'setCode')) {
                $currency->setCode(strtoupper((string) $code));
                return $currency;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    public static function invalidConfiguration($message)
    {
        if (class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) {
            throw new \WHMCS\Exception\Module\InvalidConfiguration($message);
        }

        throw new \Exception($message);
    }

    public static function refundSubunits(array $params, array $payment)
    {
        $paymentCurrency = strtoupper($payment['currency']);
        $currency        = isset($params['currency']) ? strtoupper((string) $params['currency']) : '';

        if ($currency === $paymentCurrency) {
            return Currency::toSubunits($params['amount'], $paymentCurrency);
        }

        $paid = Capsule::table('tblaccounts')
            ->where('transid', $payment['id'])
            ->where('amountin', '>', 0)
            ->value('amountin');

        if (!$paid || (float) $paid <= 0) {
            return 0;
        }

        return (int) round((int) $payment['amount'] * ((float) $params['amount'] / (float) $paid));
    }

    public static function refundSequence($paymentId, array $payment)
    {
        try {
            $original = Capsule::table('tblaccounts')->where('transid', $paymentId)->where('amountin', '>', 0)->value('id');

            if ($original) {
                return 'w' . Capsule::table('tblaccounts')->where('refundid', $original)->count();
            }
        } catch (\Exception $e) {
        }

        return 'r' . (int) (isset($payment['amount_refunded']) ? $payment['amount_refunded'] : 0);
    }

    public static function transactionExists($transactionId)
    {
        return Capsule::table('tblaccounts')->where('transid', $transactionId)->exists();
    }

    public static function lock($name, $timeout = 15)
    {
        try {
            $connection = Capsule::connection();

            if ($connection->getDriverName() !== 'mysql') {
                return null;
            }

            $name = substr($name . '_' . substr(md5((string) $connection->getDatabaseName()), 0, 10), 0, 64);
            $row  = Capsule::selectOne('SELECT GET_LOCK(?, ?) AS acquired', array($name, (int) $timeout));

            return ($row && (int) $row->acquired === 1) ? $name : false;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function unlock($lock)
    {
        if (!is_string($lock)) {
            return;
        }

        try {
            Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', array($lock));
        } catch (\Exception $e) {
        }
    }

    public static function verifyPaymentSignature($orderId, $paymentId, $signature, $secret)
    {
        if (!Validator::isSignature($signature) || (string) $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);

        return hash_equals($expected, $signature);
    }

    public static function verifyWebhookSignature($payload, $signature, $secret)
    {
        if (!is_string($signature) || $signature === '' || (string) $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public static function token(array $data, $secret)
    {
        $payload = self::base64UrlEncode(json_encode($data));

        return $payload . '.' . self::base64UrlEncode(hash_hmac('sha256', $payload, 'rzp-whmcs-token|' . $secret, true));
    }

    public static function readToken($token, $secret)
    {
        if (!is_string($token) || strlen($token) > 2048 || substr_count($token, '.') !== 1 || (string) $secret === '') {
            return null;
        }

        list($payload, $signature) = explode('.', $token, 2);

        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, 'rzp-whmcs-token|' . $secret, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payload), true);

        if (!is_array($data) || !isset($data['e']) || (int) $data['e'] < time()) {
            return null;
        }

        return $data;
    }

    private static function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($value)
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
