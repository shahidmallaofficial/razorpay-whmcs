<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class ApiException extends \Exception
{
    private $httpStatus;

    private $errorCode;

    private $field;

    private $response;

    public function __construct($message, $httpStatus = 0, $errorCode = 'SERVER_ERROR', $field = null, $response = null)
    {
        parent::__construct((string) $message);

        $this->httpStatus = (int) $httpStatus;
        $this->errorCode  = (string) $errorCode;
        $this->field      = $field;
        $this->response   = $response;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function isTransient()
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }

    public function isAuthenticationError()
    {
        return $this->httpStatus === 401;
    }
}
