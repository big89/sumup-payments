# SumUp payment gateway for WHMCS

A WHMCS payment gateway module that lets your clients pay invoices by card through SumUp, using the flow described in the [main README](../README.md).

## Features

*   **Payment Widget**: the SumUp card form is embedded on the WHMCS invoice page. Card data goes straight to SumUp, and 3-D Secure is handled by the widget.
*   **Hosted Checkout** (optional): sends clients to a payment page hosted by SumUp.
*   **Automatic payment confirmation**, both when the client returns and through SumUp webhooks, so invoices are marked paid even if the client closes the browser.
*   **Server-side verification**: before a payment is recorded, the checkout is re-read from the SumUp API and its merchant, reference, amount and currency are checked. Nothing sent by the browser or the webhook is trusted.
*   **No double payments**: the same checkout is never applied twice, even when the webhook and the client's return arrive at the same moment.
*   **Refunds**, full or partial, from the WHMCS admin area.
*   **Currency conversion**: works with WHMCS's "Convert To For Processing" setting. The invoice is credited in its own currency.
*   **Partial payments**: clients are only charged the invoice's outstanding balance.
*   **Logging**: API calls go to the WHMCS Module Log (with the API key redacted) and payment events to the Gateway Log.

## Requirements

*   WHMCS 8.x
*   PHP 7.2 or later with the cURL and JSON extensions
*   HTTPS on your WHMCS site
*   A SumUp merchant account that can accept online payments, and a SumUp **secret API key**

## Installation

1.  Copy the contents of `modules/` into your WHMCS installation's `modules/` folder:

    ```
    modules/gateways/sumup.php
    modules/gateways/sumup/               (library files)
    modules/gateways/callback/sumup.php
    ```

2.  In WHMCS, go to **Configuration (wrench icon) → System Settings → Payment Gateways**. Open **All Payment Gateways** and click **SumUp**.
3.  Fill in the settings (see below) and click **Save Changes**.

The module creates its own database table (`mod_sumup_checkouts`) the first time it is used.

## Settings

| Setting | Description |
|---|---|
| **Secret API Key** | From the SumUp Dashboard: **Developer settings → API keys**. Keep it secret. |
| **Merchant Code** | Optional. Leave empty to detect it from the API key. Set it to save one API call per new checkout. |
| **Checkout Type** | **Payment Widget** shows the card form on the invoice page (recommended). **Hosted Checkout** sends the client to a SumUp page. |
| **Widget Locale** | Optional language for the widget, for example `en-GB`, `de-DE`, `fr-FR`. |
| **Convert To For Processing** | Standard WHMCS setting. Use it if your invoices are in a currency your SumUp account can't take; it must be your SumUp account's currency. |

### Webhooks

You don't need to set up webhooks in SumUp. The module sends the webhook URL with every checkout:

    https://your-whmcs.example.com/modules/gateways/callback/sumup.php

Make sure this URL is reachable from the internet and not blocked by a firewall, maintenance mode or HTTP authentication.

## How it works

1.  A client opens an unpaid invoice. The module creates a SumUp checkout for the outstanding balance and shows the Payment Widget (or a "Pay Now" button for Hosted Checkout). Reloading the page reuses the same checkout.
2.  The client pays. SumUp runs 3-D Secure if the card issuer requires it.
3.  The client is sent to the callback URL, and SumUp also calls it as a webhook. Either way, the module fetches the checkout from SumUp, checks it, and if it's `PAID` records the payment against the invoice. The SumUp **transaction code** is stored as the WHMCS transaction ID.
4.  The client lands back on the invoice with WHMCS's payment success or failure message. After a failed attempt, a new checkout is created so the client can try again.

## Refunds

Open the invoice in the WHMCS admin area, go to the **Refund** tab, choose the payment, enter the amount and select **Refund through Gateway**. The module looks up the SumUp transaction and refunds the amount (partial refunds are supported).

## Testing

1.  Create a sandbox merchant account in the SumUp Dashboard and an API key for it.
2.  Enter that key in the module settings.
3.  Pay an invoice with one of SumUp's test cards (see the SumUp developer docs).
4.  Check **Billing → Gateway Log** and **Utilities → Logs → Module Log** (turn on module logging first) to see what happened.

The repository also has an automated end-to-end test suite that runs the module against a fake WHMCS and a fake SumUp API:

    php tests/run.php

## Troubleshooting

| Symptom | What to check |
|---|---|
| "Online payment is currently unavailable" on the invoice | Gateway Log entry **Checkout Error** shows the reason. Usually a wrong API key, a currency that doesn't match your SumUp account (use **Convert To For Processing**), or online payments not being enabled for the account. |
| Payment taken but the invoice stays unpaid | Make sure the callback URL is reachable from the internet. Look for **Error** or **Invalid** entries in the Gateway Log. |
| "Checkout amount does not match" in the Gateway Log | The checkout at SumUp doesn't match what WHMCS created. The payment was **not** recorded; check it in the SumUp Dashboard. |
| Hosted Checkout button missing | Hosted Checkout may not be enabled for your SumUp account. Switch to Payment Widget or contact SumUp. |

## Limitations

*   Saved cards and automatic charging of recurring invoices aren't supported yet; clients pay each invoice themselves.
*   SumUp only processes payments in the merchant account's currency.
