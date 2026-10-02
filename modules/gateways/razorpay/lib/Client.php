<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.2
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class Client
{
    const BASE_URL = 'https://api.razorpay.com/v1/';

    private $keyId;

    private $keySecret;

    private $userAgent;

    private $connectTimeout;

    private $timeout;

    private static $handle = null;

    public function __construct($keyId, $keySecret, $userAgent = '', $connectTimeout = 5, $timeout = 15)
    {
        $this->keyId          = trim((string) $keyId);
        $this->keySecret      = trim((string) $keySecret);
        $this->userAgent      = $userAgent;
        $this->connectTimeout = (int) $connectTimeout;
        $this->timeout        = (int) $timeout;
    }

    public function getKeyId()
    {
        return $this->keyId;
    }

    public function getKeySecret()
    {
        return $this->keySecret;
    }

    public function createOrder(array $data)
    {
        return $this->request('POST', 'orders', $data);
    }

    public function findOrderByReceipt($receipt)
    {
        $result = $this->request('GET', 'orders', array('receipt' => $receipt, 'count' => 1));

        return isset($result['items'][0]['id']) ? $result['items'][0] : null;
    }

    public function fetchOrder($orderId)
    {
        return $this->request('GET', 'orders/' . rawurlencode($orderId));
    }

    public function fetchOrderPayments($orderId)
    {
        return $this->request('GET', 'orders/' . rawurlencode($orderId) . '/payments');
    }

    public function fetchPayment($paymentId)
    {
        return $this->request('GET', 'payments/' . rawurlencode($paymentId));
    }

    public function capturePayment($paymentId, $amount, $currency)
    {
        return $this->request('POST', 'payments/' . rawurlencode($paymentId) . '/capture', array(
            'amount'   => (int) $amount,
            'currency' => $currency,
        ));
    }

    public function refundPayment($paymentId, array $data, $idempotencyKey)
    {
        return $this->request('POST', 'payments/' . rawurlencode($paymentId) . '/refund', $data, array(
            'X-Refund-Idempotency: ' . $idempotencyKey,
        ), true);
    }

    public function fetchRefund($refundId)
    {
        return $this->request('GET', 'refunds/' . rawurlencode($refundId));
    }

    public function fetchRefunds($paymentId)
    {
        return $this->request('GET', 'payments/' . rawurlencode($paymentId) . '/refunds');
    }

    public function request($method, $path, array $data = array(), array $headers = array(), $idempotent = false)
    {
        if ($this->keyId === '' || $this->keySecret === '') {
            throw new ApiException('Razorpay API keys are not configured.', 0, 'CONFIGURATION_ERROR');
        }

        if (!function_exists('curl_init')) {
            throw new ApiException('The PHP curl extension is required by the Razorpay module.', 0, 'CONFIGURATION_ERROR');
        }

        $url  = self::BASE_URL . ltrim($path, '/');
        $body = null;

        if ($method === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
        } else {
            $body = json_encode(empty($data) ? new \stdClass() : $data);
        }

        $retryable   = ($method === 'GET' || $idempotent);
        $maxAttempts = 2;
        $attempt     = 0;

        while (true) {
            $attempt++;

            list($status, $raw, $curlErrno, $curlError) = $this->send($method, $url, $body, $headers);

            if ($curlErrno !== 0) {
                $notSent = in_array($curlErrno, array(6, 7), true);
                if ($attempt < $maxAttempts && ($retryable || $notSent)) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new ApiException('Could not connect to Razorpay: ' . $curlError, 0, 'NETWORK_ERROR');
            }

            if ($retryable && $attempt < $maxAttempts && ($status === 429 || $status >= 500 || ($idempotent && $status === 409))) {
                $this->backoff($attempt);
                continue;
            }

            break;
        }

        $decoded = json_decode((string) $raw, true);

        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            return $decoded;
        }

        $code        = 'SERVER_ERROR';
        $description = 'Unexpected response from Razorpay (HTTP ' . $status . ').';
        $field       = null;

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $error       = $decoded['error'];
            $code        = isset($error['code']) ? (string) $error['code'] : $code;
            $description = isset($error['description']) ? (string) $error['description'] : $description;
            $field       = isset($error['field']) ? (string) $error['field'] : null;
        }

        throw new ApiException($description, $status, $code, $field, is_array($decoded) ? $decoded : null);
    }

    private function send($method, $url, $body, array $extraHeaders = array())
    {
        if (self::$handle === null) {
            self::$handle = curl_init();
        } else {
            curl_reset(self::$handle);
        }

        $ch = self::$handle;

        $headers = array('Accept: application/json');
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $options = array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        );

        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return array($status, $raw === false ? '' : $raw, $errno, $error);
    }

    private function backoff($attempt)
    {
        usleep(($attempt === 1 ? 300000 : 900000) + mt_rand(0, 200000));
    }
}
