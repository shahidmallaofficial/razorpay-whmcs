<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.2
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class Logger
{
    const GATEWAY = 'Razorpay';

    private static $sensitive = array('keySecret', 'webhookSecret', 'key_secret', 'secret', 'password', 'token');

    public static function log($action, $data, $status)
    {
        if (!function_exists('logTransaction')) {
            return;
        }

        if (!is_array($data)) {
            $data = array('message' => (string) $data);
        }

        $data = array('action' => $action) + self::mask($data);

        try {
            logTransaction(self::GATEWAY, $data, $status);
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    public static function mask(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::mask($value);
            } elseif (in_array((string) $key, self::$sensitive, true) && $value !== '' && $value !== null) {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
