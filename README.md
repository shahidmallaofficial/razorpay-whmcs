# Razorpay Payment Gateway for WHMCS

**Version 3.0.2** · Upgraded, hardened and maintained by **[Shahid Malla](https://shahidmalla.com)**

Accept cards, UPI, netbanking, wallets and EMI in WHMCS with Razorpay Checkout. The checkout opens on your own site with no redirect. This release is a full rewrite of the official Razorpay WHMCS module (v2.2.2). It fixes payments that were charged but never marked paid, double-credited payments, and wrong amounts. It also makes the invoice page faster and closes several security gaps.

> This is an independent, community-maintained upgrade. It is not an official Razorpay release.

**3.0.2 (important):** an invoice can never be auto-paid with another invoice's payment. Automatic recovery only credits recent payments on orders created by this version; orders created before the upgrade only accept fresh payments; a payment already recorded anywhere in WHMCS (in any transaction-ID format), or whose Razorpay notes name a different invoice, is flagged for manual review instead of being credited.

---

## Why upgrade from 2.x

| Problem in 2.2.2 | What 3.0 does |
|---|---|
| Webhooks never paid **renewal, Add Funds or manual invoices**, because they looked up `tblorders` | Every invoice type is settled by webhook, callback, or automatic reconciliation |
| The order confirmation page **auto-submitted an empty form** after 5 seconds, so payments failed or were left half done | No submittable form is rendered; Checkout opens automatically on the order confirmation page |
| `19.99 × 100` was computed as 1998, which created a new Razorpay order on every invoice view | Exact, currency-aware amount conversion (0, 2 and 3 decimal currencies) |
| The callback credited the **invoice total** instead of the amount actually paid (overpayments, free credit) | Credits exactly what Razorpay captured, converted back for "Convert To For Processing" |
| The callback and webhook could **both credit** the same payment | Database lock plus transaction-ID check: every payment is credited exactly once |
| "Authorize" mode marked invoices **paid without capturing**, so Razorpay refunded the customer | Invoices are marked paid only after capture |
| Signature check used the session order ID, so genuine payments failed in multiple tabs | Verifies against the order the customer actually paid, bound to the invoice |
| A Razorpay API call (up to 60 s timeout) ran on **every invoice page view** | Invoice pages make **zero** API calls; the order is created in the background when the customer reaches for "Pay Now" |
| Customer names were written into HTML unescaped (XSS) | All output is JSON/HTML-escaped |
| Secrets were shown as plain text in admin | Key Secret and Webhook Secret are password fields; secrets are masked in logs |
| `//` double-slash URLs from the System URL | All URLs are built from the WHMCS System URL, normalised |
| PHP 8.1+ deprecation notices from the 2021 SDK | No SDK: a small built-in API client (TLS 1.2+, keep-alive, short timeouts, safe retries) |

### New features

- **Refunds from WHMCS** (full and partial, Normal or Instant/Optimum speed, protected against duplicate refunds)
- **Self-healing payments**: if a customer is charged but the browser never returns (closed tab, UPI app switch), the payment is detected automatically when they revisit the invoice, by the webhook, or by the cron reconciliation hook
- **Automatic capture** of payments stuck in "authorized" (late authorisations), so they are not auto-refunded
- **Settings validation**: wrong or mismatched API keys are rejected when you save
- **Transaction details in admin**: click a transaction ID under *Billing > Transactions* to see the Razorpay status, method, fee and refunds
- **Razorpay status on the admin invoice page**: every checkout attempt and payment for that invoice
- **Razorpay fee recording** on each transaction for accurate income reports
- **Checkout branding**: your company name, logo (https) and a custom colour; name, email and phone prefilled
- **Works with Razorpay Offers and Customer Fee Bearer**: discounted or fee-inclusive payments are credited correctly
- **Works on www and non-www**: customers can pay whichever address they open your site on
- **In-app browser support** (Instagram, Facebook, UC Browser, Android WebView) through the redirect flow
- **"Pay Now" in invoice emails** is a real link to the invoice
- UPI Collect hidden, following the NPCI deprecation (UPI Intent and QR remain)
- **Multi-site safe**: several WHMCS installs can share one Razorpay account without crediting each other's invoices

---

## Requirements

- WHMCS 8.x (8.13 LTS recommended) or WHMCS 9.x
- PHP 7.2 – 8.4 with the `curl` and `json` extensions
- MySQL or MariaDB (standard for WHMCS)
- A WHMCS **System URL** that uses `https://`

## Installation

1. Download this repository.
2. Upload the `modules` and `includes` folders to the root of your WHMCS installation and merge them with the existing folders:
   ```
   modules/gateways/razorpay.php
   modules/gateways/razorpay/
   includes/hooks/razorpay.php
   ```
3. In WHMCS admin, open **System Settings > Payment Gateways**, activate **Razorpay** and fill in the settings below.

### Upgrading from 2.x

Upload the files over the old ones. Your settings, your existing order mappings and your webhook URL keep working; the database table is upgraded automatically. After upgrading you can safely delete the old, unused files:

```
modules/gateways/razorpay/razorpay-sdk/
modules/gateways/razorpay/rzpordermapping.php
```

## Configuration

| Setting | Description |
|---|---|
| **Key Id** | From *Razorpay Dashboard > Account & Settings > API Keys*. Starts with `rzp_live_` (or `rzp_test_` for testing). |
| **Key Secret** | Shown once when the key is generated. Validated against Razorpay when you save. |
| **Payment Action** | *Authorize and Capture* (recommended), or *Authorize only*: you capture each payment in the Razorpay Dashboard, and the invoice is marked paid once it is captured. |
| **Refund Speed** | *Normal* (5–7 working days) or *Optimum* (instant where possible; Razorpay charges a fee). |
| **Disable Auto-Open** | By default, Razorpay Checkout opens automatically after a customer places an order. Tick to show only the "Pay Now" button. |
| **Checkout Colour** | Optional brand colour, e.g. `#2563EB`. |
| **Enable Webhook** / **Webhook Secret** | See below (strongly recommended). |

### Webhook (recommended)

1. In **Razorpay Dashboard > Account & Settings > Webhooks**, add a new webhook.
2. **Webhook URL**: copy it from the Razorpay gateway settings in WHMCS. It looks like:
   `https://your-whmcs.example/modules/gateways/razorpay/razorpay-webhook.php`
3. **Secret**: choose a strong random value and enter the same value as **Webhook Secret** in WHMCS.
4. **Events**: `order.paid`, `payment.captured`, `payment.authorized`.
5. Tick **Enable Webhook** in WHMCS and save.

If you use Cloudflare or another firewall/WAF, allow Razorpay's webhook requests (user agent `Razorpay-Webhook`) to this URL, or bot protection may block them.

### Automatic reconciliation (cron)

`includes/hooks/razorpay.php` runs after every WHMCS cron run. It checks recent unpaid checkout attempts with Razorpay and credits any payment that was captured but never recorded. It works even without the webhook. Make sure the WHMCS cron job is set up, as it is for any WHMCS installation.

## Testing

Use your `rzp_test_` keys (a "test mode" notice appears under the Pay button), then pay with Razorpay's test methods, for example card `4100 2800 0000 1007` with any future expiry and CVV, or UPI ID `success@razorpay`. Switch to `rzp_live_` keys when you are done. Use a separate webhook secret for each mode.

## Troubleshooting

All activity is logged under **Billing > Gateway Log** (filter by Razorpay) with a clear status:

| Status | Meaning |
|---|---|
| `Successful` | Payment captured and credited to the invoice |
| `Pending Capture` | Payment authorized in *Authorize only* mode; capture it in the Razorpay Dashboard |
| `Manual Review` | A payment arrived for an invoice that is already paid or cancelled; refund it or add it as credit |
| `Rejected` | Signature, order, currency or API-key check failed; nothing was credited |
| `Recovered` | A missed payment was found and credited by reconciliation |
| `Configuration Error` | Keys or webhook secret missing or invalid |

- **"Webhook: invalid signature"**: the Webhook Secret in WHMCS does not match the one in the Razorpay Dashboard.
- **Pay button shows "temporarily unavailable"**: check the Key Id and Key Secret, and that both are from the same mode (test or live).
- **Currencies other than INR**: international payments must be enabled on your Razorpay account. Otherwise use WHMCS *Convert To For Processing* to INR; the module credits the invoice in its original currency.

## Security

- Payment signatures and webhook signatures are verified with HMAC-SHA256 and constant-time comparison.
- Each Razorpay order is bound to its invoice and to your WHMCS installation; payments are re-checked with the Razorpay API (status, amount, currency) before crediting.
- The checkout endpoint only accepts short-lived signed tokens issued with the invoice page.
- Secrets are never shown in plain text or written to logs; webhook bodies and customer data are not logged.

## License

MIT License

Copyright (c) Razorpay Software Private Limited (original module)
Copyright (c) 2026 Shahid Malla — https://shahidmalla.com (version 3.0 upgrade)

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
