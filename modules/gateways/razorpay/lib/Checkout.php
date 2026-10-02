<?php
/**
 * Razorpay Payment Gateway for WHMCS v3.0.0
 * Developed by Shahid Malla - https://shahidmalla.com
 * MIT License
 */

namespace RazorpayWhmcs;

class Checkout
{
    const SCRIPT_URL = 'https://checkout.razorpay.com/v1/checkout.js';
    const TOKEN_TTL  = 86400;

    public static function render(array $params)
    {
        $invoiceId = isset($params['invoiceid']) ? $params['invoiceid'] : null;
        $keyId     = isset($params['keyId']) ? trim($params['keyId']) : '';
        $secret    = isset($params['keySecret']) ? trim($params['keySecret']) : '';
        $currency  = strtoupper(isset($params['currency']) ? (string) $params['currency'] : '');

        if (!Validator::isInvoiceId($invoiceId)) {
            return '';
        }

        if (PHP_SAPI === 'cli' || defined('ADMINAREA')) {
            $label = !empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';
            return '<a href="' . self::e(Gateway::invoiceUrl($invoiceId, null, $params)) . '">' . self::e($label) . '</a>';
        }

        if (!Validator::isKeyId($keyId) || $secret === '') {
            Logger::log('Render checkout', array('invoice_id' => $invoiceId, 'reason' => 'Key Id / Key Secret missing or invalid'), 'Configuration Error');
            return self::notice('This payment method is temporarily unavailable. Please choose another payment method or contact support.');
        }

        if (!Validator::isCurrency($currency)) {
            return self::notice('This currency is not supported by Razorpay.');
        }

        $amount = Currency::toSubunits(isset($params['amount']) ? $params['amount'] : 0, $currency);

        if ($amount < Currency::minimum($currency)) {
            return self::notice('The amount due is below the minimum Razorpay can process.');
        }

        try {
            OrderMapping::ensureSchema();
            $invoice    = Gateway::invoice($invoiceId);
            $hasPending = count(OrderMapping::findOpenForInvoice($invoiceId, $keyId, 1)) > 0;
        } catch (\Exception $e) {
            Logger::log('Render checkout', array('invoice_id' => $invoiceId, 'error' => $e->getMessage()), 'Error');
            return self::notice('This payment method is temporarily unavailable. Please try again shortly.');
        }

        if (!Gateway::isPayable($invoice)) {
            return '';
        }

        $token = Gateway::token(array(
            'v' => 1,
            'i' => (int) $invoiceId,
            'a' => $amount,
            'c' => $currency,
            'b' => Currency::format($invoice->balance),
            'e' => time() + self::TOKEN_TTL,
        ), $secret);

        $config = array(
            'id'          => 'rzp-whmcs-' . (int) $invoiceId,
            'invoiceId'   => (string) (int) $invoiceId,
            'token'       => $token,
            'endpoint'    => Gateway::moduleUrl('checkout.php', $params),
            'callback'    => Gateway::moduleUrl('razorpay.php', $params) . '?merchant_order_id=' . (int) $invoiceId,
            'invoiceUrl'  => Gateway::invoiceUrl($invoiceId, null, $params),
            'autoOpen'    => isset($params['autoOpen']) && $params['autoOpen'] === 'on',
            'checkStatus' => $hasPending,
            'checkout'    => self::options($params, $invoiceId, $keyId),
            'text'        => array(
                'loading'   => 'Please wait…',
                'verifying' => 'Verifying your payment, please do not close this page…',
                'cancelled' => 'Payment was not completed. You can try again.',
                'failed'    => 'Payment failed. Please try again or use another payment method.',
                'error'     => 'We could not start the payment. Please try again.',
                'network'   => 'Network error. Please check your connection and try again.',
                'blocked'   => 'Razorpay Checkout could not be loaded. Please disable content blockers or try another browser.',
            ),
        );

        $json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $label = self::e(!empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now');
        $id    = self::e($config['id']);
        $test  = Validator::isTestKey($keyId)
            ? '<div class="small text-muted" style="margin-top:6px">Razorpay test mode: no real money will be charged.</div>'
            : '';

        return '<div id="' . $id . '" class="razorpay-whmcs" style="display:inline-block;text-align:center">'
            . '<link rel="preconnect" href="https://checkout.razorpay.com"><link rel="preconnect" href="https://api.razorpay.com">'
            . '<a href="' . self::e($config['invoiceUrl']) . '" class="btn btn-success btn-lg rzp-pay" role="button">' . $label . '</a>'
            . '<div class="rzp-msg small" role="status" aria-live="polite" style="margin-top:8px"></div>'
            . $test
            . '<noscript><div class="alert alert-warning">Please enable JavaScript to pay with Razorpay.</div></noscript>'
            . '</div>'
            . '<script src="' . self::SCRIPT_URL . '" async></script>'
            . '<script>' . str_replace('__CONFIG__', $json, self::script()) . '</script>';
    }

    private static function options(array $params, $invoiceId, $keyId)
    {
        $client = isset($params['clientdetails']) && is_array($params['clientdetails']) ? $params['clientdetails'] : array();

        $name  = trim((isset($client['firstname']) ? $client['firstname'] : '') . ' ' . (isset($client['lastname']) ? $client['lastname'] : ''));
        $email = isset($client['email']) ? trim($client['email']) : '';

        $options = array(
            'key'           => $keyId,
            'name'          => self::companyName($params),
            'description'   => self::description($params, $invoiceId),
            'prefill'       => array_filter(array(
                'name'    => self::limit(html_entity_decode($name, ENT_QUOTES, 'UTF-8'), 100),
                'email'   => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                'contact' => self::phone($client),
            )),
            'notes'         => array(
                'whmcs_order_id' => (string) (int) $invoiceId,
            ),
            'retry'         => array('enabled' => true),
            'send_sms_hash' => true,
            'config'        => array(
                'display' => array(
                    'hide' => array(
                        array('method' => 'upi', 'flows' => array('collect')),
                    ),
                ),
            ),
            '_'             => array(
                'integration'                => 'whmcs',
                'integration_version'        => Gateway::VERSION,
                'integration_parent_version' => isset($params['whmcsVersion']) ? (string) $params['whmcsVersion'] : Gateway::whmcsVersion(),
                'integration_type'           => 'plugin',
            ),
        );

        $image = self::logoUrl($params);
        if ($image !== '') {
            $options['image'] = $image;
        }

        if (!empty($params['themeColor']) && Validator::isHexColor(trim($params['themeColor']))) {
            $options['theme'] = array('color' => trim($params['themeColor']));
        }

        if (empty($options['prefill'])) {
            unset($options['prefill']);
        }

        return $options;
    }

    private static function description(array $params, $invoiceId)
    {
        $text = !empty($params['description']) ? html_entity_decode($params['description'], ENT_QUOTES, 'UTF-8') : '';
        $text = preg_replace('/^[^\p{L}\p{N}]+/u', '', self::limit($text, 250));

        return $text !== '' && $text !== null ? $text : 'Invoice ' . (int) $invoiceId;
    }

    private static function companyName(array $params)
    {
        $name = !empty($params['companyname']) ? $params['companyname'] : '';

        if ($name === '') {
            try {
                if (class_exists('\WHMCS\Config\Setting')) {
                    $name = (string) \WHMCS\Config\Setting::getValue('CompanyName');
                }
            } catch (\Exception $e) {
            }
        }

        return self::limit(html_entity_decode($name, ENT_QUOTES, 'UTF-8'), 100);
    }

    private static function logoUrl(array $params)
    {
        $logo = '';

        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                $logo = trim((string) \WHMCS\Config\Setting::getValue('LogoURL'));
            }
        } catch (\Exception $e) {
        }

        if ($logo === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $logo)) {
            $logo = Gateway::systemUrl($params) . ltrim($logo, '/');
        }

        return stripos($logo, 'https://') === 0 && filter_var($logo, FILTER_VALIDATE_URL) ? $logo : '';
    }

    private static function phone(array $client)
    {
        $raw = '';

        if (!empty($client['phonenumberformatted'])) {
            $raw = $client['phonenumberformatted'];
        } elseif (!empty($client['phonenumber'])) {
            $raw = $client['phonenumber'];
            if (!empty($client['phonecc']) && strpos(trim($raw), '+') !== 0) {
                $raw = '+' . $client['phonecc'] . $raw;
            }
        }

        $raw    = trim((string) $raw);
        $plus   = strpos($raw, '+') === 0 ? '+' : '';
        $digits = preg_replace('/\D+/', '', $raw);

        return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $plus . $digits : '';
    }

    private static function notice($message)
    {
        return '<div class="alert alert-warning razorpay-whmcs-notice">' . self::e($message) . '</div>';
    }

    private static function limit($value, $length)
    {
        $value = (string) $value;
        $clean = preg_replace('/[\p{C}\x{10000}-\x{10FFFF}]/u', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $clean === null ? $value : $clean));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }

    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function script()
    {
        return <<<'JS'
(function () {
  var cfg = __CONFIG__;
  var root = document.getElementById(cfg.id);
  if (!root || root.getAttribute('data-rzp-ready')) { return; }
  root.setAttribute('data-rzp-ready', '1');

  var btn = root.querySelector('.rzp-pay');
  var msg = root.querySelector('.rzp-msg');
  var label = btn.textContent;
  var order = null;
  var queue = [];
  var busy = false;
  var forward = document.getElementById('frmPayment');
  var onForward = !!(forward && forward.contains(root));
  var endpoint = local(cfg.endpoint);
  var callback = local(cfg.callback);
  var invoiceUrl = local(cfg.invoiceUrl);
  var inApp = /FBAN|FBAV|FB_IAB|Instagram|Messenger|Line\/|UCBrowser|Opera Mini|; wv\)/i.test(navigator.userAgent || '');

  function say(text, isError) {
    msg.textContent = text || '';
    msg.className = 'rzp-msg small' + (isError ? ' text-danger' : '');
  }

  function local(url) {
    var a = document.createElement('a');
    a.href = url;
    if (!a.host || (a.host === window.location.host && a.protocol === window.location.protocol)) { return url; }
    var path = a.pathname.charAt(0) === '/' ? a.pathname : '/' + a.pathname;
    return window.location.protocol + '//' + window.location.host + path + a.search;
  }

  function setBusy(state, text) {
    busy = state;
    btn.setAttribute('aria-disabled', state ? 'true' : 'false');
    btn.className = btn.className.replace(/\s*\bdisabled\b/g, '') + (state ? ' disabled' : '');
    btn.textContent = state ? (text || cfg.text.loading) : label;
  }

  function go(url) {
    window.location.href = url ? local(url) : window.location.href;
  }

  function guard() {
    if (!onForward) { return; }
    window.noAutoSubmit = true;
    var forms = forward.getElementsByTagName('form');
    for (var f = 0; f < forms.length; f++) {
      forms[f].onsubmit = function () { return false; };
    }
  }

  function call(action, done) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.timeout = 60000;
    xhr.onload = function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
      done(data && data.status ? data : { status: 'error', message: cfg.text.error });
    };
    xhr.onerror = xhr.ontimeout = function () { done({ status: 'error', message: cfg.text.network }); };
    xhr.send('action=' + encodeURIComponent(action) + '&token=' + encodeURIComponent(cfg.token));
  }

  function prepare(done) {
    if (order) { done(order); return; }
    queue.push(done);
    if (queue.length > 1) { return; }
    call('order', function (res) {
      if (res.status === 'ok') { order = res; }
      var list = queue;
      queue = [];
      for (var i = 0; i < list.length; i++) { list[i](res); }
    });
  }

  function whenReady(done, tries) {
    if (typeof window.Razorpay === 'function') { done(); return; }
    tries = tries || 0;
    if (tries > 150) { setBusy(false); say(cfg.text.blocked, true); return; }
    setTimeout(function () { whenReady(done, tries + 1); }, 100);
  }

  function settled(res) {
    if (res.status === 'paid' || res.status === 'reload') {
      say(res.message || '');
      go(res.redirect);
      return true;
    }
    if (res.status === 'pending' || res.status === 'processing') {
      setBusy(false);
      say(res.message || '');
      return true;
    }
    return false;
  }

  function submit(response, current) {
    setBusy(true, cfg.text.verifying);
    say(cfg.text.verifying);
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = callback;
    form.style.display = 'none';
    var fields = {
      merchant_order_id: cfg.invoiceId,
      razorpay_payment_id: response.razorpay_payment_id,
      razorpay_order_id: response.razorpay_order_id || current.order_id,
      razorpay_signature: response.razorpay_signature
    };
    for (var name in fields) {
      if (Object.prototype.hasOwnProperty.call(fields, name)) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fields[name] || '';
        form.appendChild(input);
      }
    }
    document.body.appendChild(form);
    form.submit();
  }

  function open(current) {
    var options = {};
    for (var key in cfg.checkout) {
      if (Object.prototype.hasOwnProperty.call(cfg.checkout, key)) { options[key] = cfg.checkout[key]; }
    }
    options.amount = current.amount;
    options.currency = current.currency;
    options.order_id = current.order_id;
    if (inApp) {
      options.callback_url = callback;
      options.redirect = true;
    } else {
      options.handler = function (response) { submit(response, current); };
    }
    options.modal = {
      confirm_close: true,
      ondismiss: function () {
        if (onForward) { go(invoiceUrl); return; }
        order = null;
        setBusy(false);
        say(cfg.text.cancelled);
        call('status', function (res) {
          if (res.status === 'paid' || res.status === 'pending') { settled(res); }
        });
      }
    };
    try {
      var rzp = new window.Razorpay(options);
      rzp.on('payment.failed', function (response) {
        var description = response && response.error && response.error.description;
        say(description || cfg.text.failed, true);
      });
      rzp.open();
    } catch (e) {
      setBusy(false);
      say(cfg.text.error, true);
    }
  }

  function pay() {
    if (busy) { return; }
    guard();
    say('');
    setBusy(true, cfg.text.loading);
    prepare(function (res) {
      if (settled(res)) { return; }
      if (res.status !== 'ok') {
        setBusy(false);
        say(res.message || cfg.text.error, true);
        return;
      }
      whenReady(function () { open(res); });
    });
  }

  function warm() {
    prepare(function (res) { settled(res); });
  }

  btn.addEventListener('click', function (event) { event.preventDefault(); pay(); });

  var events = ['mouseenter', 'touchstart', 'focus'];
  var warmed = false;
  for (var i = 0; i < events.length; i++) {
    btn.addEventListener(events[i], function () {
      if (!warmed) { warmed = true; warm(); }
    }, { passive: true });
  }

  if (cfg.checkStatus) {
    call('status', function (res) {
      if (res.status === 'paid' || res.status === 'pending') { settled(res); }
    });
  }

  if (cfg.autoOpen && onForward) {
    guard();
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', guard);
    }
    whenReady(pay);
  }
})();
JS;
    }
}
