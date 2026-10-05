# SumUp online checkout flow

This guide shows how an application creates a SumUp checkout and takes a card payment for it, either in a browser or in a native app such as a mobile application.

All API requests go to the base URL `https://api.sumup.com`.

> **WHMCS users:** a ready-made payment gateway module that implements this flow is in [`whmcs/`](whmcs/README.md).

Checkouts are **created on your server** in server-to-server calls. This lets you

*   keep your API key or OAuth client credentials secret.
*   stop anyone from changing the checkout's properties (for example the amount or the receiving merchant) from the client.

Checkouts are **paid on the client** with the SumUp Payment Widget (or SumUp's Hosted Checkout page). This means that

*   card data goes straight from the customer's device to SumUp and never reaches your server.
*   your PCI DSS scope stays minimal, because SumUp collects and processes the card data.
*   3-D Secure (Strong Customer Authentication) is handled for you.

> **Note:** You can also send raw card data to SumUp yourself (see [5.2](#52-alternative-process-the-checkout-via-the-api)). This makes your systems handle cardholder data, so it needs full PCI DSS compliance, and SumUp has to enable it for your account. Use the Payment Widget or Hosted Checkout unless you have a specific reason not to.

## 1. Roles

**Client**
> The **user interface**, rendered in a browser or a mobile app. It shows the SumUp Payment Widget, which collects the customer's card details and sends them directly to SumUp.

**Server**
> The **backend** behind the client. It handles all authenticated communication with SumUp: authenticating, creating checkouts, receiving webhooks and checking checkout status.

**SumUp**
> **SumUp's API**. It handles authorization, creating and processing checkouts, and 3-D Secure, and reports the state of checkouts.

## 2. Flow

```
 +----------------+                                    +------------------------+
 |                +--------(D) pay via widget, 3DS --->|                        |
 |  Client        |<-------(D) payment result ---------+  SumUp                 |
 |  (Web|Mobile)  |                                    |  (Auth/Payment API)    |
 |                |                                    |                        |
 +---^--------+---+                                    +--^--+--^-------^-------+
     |        |                                           |  |  |       |
    (C)      (G)                                          |  |  |       |
     |        |                                           |  |  |       |
 +---+--------v---+                                       |  |  |       |
 |                +------(A) get access token ------------+  |  |       |
 |  Server        |                                          |  |       |
 |  (Your         |<-----(E) webhook (status changed) -------+  |       |
 |  Backend)      +------(B) create checkout -------------------+       |
 |                +------(F) get checkout status -----------------------+
 +----------------+
```

1.  **(A)** The Server authenticates with SumUp.
2.  **(B)** The Server creates a checkout.
3.  **(C)** The Server passes the checkout `id` to the Client.
4.  **(D)** The Client mounts the Payment Widget with that `id`. The customer enters their card details and, if required, completes 3-D Secure.
5.  **(E)** SumUp notifies the Server that the checkout status changed.
6.  **(F)** The Server fetches the checkout to confirm its final status.
7.  **(G)** The Server tells the Client the result (for example by showing an order confirmation page).

## 3. Authenticate with SumUp (A)

There are two ways for your Server to authenticate. **Never expose either credential to the Client.**

### 3.1 API key (simplest)

If you only take payments for your own SumUp merchant account, create an API key in the SumUp Dashboard (Developer settings) and send it as a bearer token:

    Authorization: Bearer {api_key}

### 3.2 OAuth 2.0 client credentials

If you use an OAuth application, request an access token with the client credentials grant:

_Request_

    POST https://api.sumup.com/token
    Content-Type: application/x-www-form-urlencoded

    grant_type=client_credentials&client_id={client_id}&client_secret={client_secret}&scope=payments

_Response_

    HTTP/1.1 200 OK

    {
        "access_token": "...",
        "token_type": "Bearer",
        "expires_in": 3599,
        "scope": "payments"
    }

Cache the token and request a new one before it expires. Send it in later requests as `Authorization: Bearer {access_token}`.

> The `payments` scope is restricted: SumUp has to enable it for your OAuth application before you can create checkouts with an OAuth token.

### 3.3 Find your merchant code

A checkout is addressed to a **merchant code**, not an email address. You can read it from the SumUp Dashboard or from the API:

    GET https://api.sumup.com/v0.1/me
    Authorization: Bearer {token}

The merchant code is in `merchant_profile.merchant_code`.

## 4. Create a checkout (B)

Your Server creates a checkout. Its amount and recipient can't be changed after it's created.

### Request body

| Parameter | Required | Description |
|---|---|---|
| `checkout_reference` | yes | Your own unique identifier for the checkout, used for reconciliation. Must be unique per merchant. |
| `amount` | yes | The amount to charge, as a decimal number (for example `10.50`). |
| `currency` | yes | ISO 4217 currency code. Must match the merchant account's currency, for example `EUR`, `GBP`, `USD`, `CHF`, `PLN`, `SEK`, `NOK`, `DKK`, `CZK`, `HUF`, `BGN`, `RON`, `BRL`, `CLP`. |
| `merchant_code` | yes | The code of the merchant that receives the payment (see [3.3](#33-find-your-merchant-code)). Replaces the deprecated `pay_to_email`. |
| `description` | no | A short description of the checkout. |
| `return_url` | no | A URL on your Server where SumUp sends webhook notifications when the checkout status changes (see [6](#6-receive-the-result-e-f)). |
| `redirect_url` | no | The URL the customer returns to after 3-D Secure or an alternative payment method that needs a redirect. Required when processing through the API with 3-D Secure (see [5.2](#52-alternative-process-the-checkout-via-the-api)). |
| `valid_until` | no | ISO 8601 date-time after which the checkout can no longer be paid. |
| `customer_id` | no | Your customer's ID. Needed, together with `purpose`, to save a card for later payments. |
| `purpose` | no | `CHECKOUT` (default) or `SETUP_RECURRING_PAYMENT` to save the customer's card. |
| `hosted_checkout` | no | `{ "enabled": true }` to get a SumUp-hosted payment page (see [5.3](#53-alternative-hosted-checkout)). |

### Example

_Request_

    POST https://api.sumup.com/v0.1/checkouts
    Content-Type: application/json
    Authorization: Bearer {token}

    {
        "checkout_reference": "order-1234",
        "amount": 10.50,
        "currency": "EUR",
        "merchant_code": "MH4H92C7",
        "description": "Order #1234",
        "return_url": "https://my.domain.com/webhooks/sumup",
        "redirect_url": "https://my.domain.com/checkout/complete"
    }

_Response_

    HTTP/1.1 201 Created

    {
        "id": "80e5e401-a503-4333-a446-6f190c08d617",
        "checkout_reference": "order-1234",
        "amount": 10.50,
        "currency": "EUR",
        "merchant_code": "MH4H92C7",
        "description": "Order #1234",
        "return_url": "https://my.domain.com/webhooks/sumup",
        "status": "PENDING",
        "date": "2026-10-05T10:00:00.000+00:00",
        "transactions": []
    }

A checkout's `status` is one of:

| Status | Meaning |
|---|---|
| `PENDING` | Created, not yet paid. |
| `PAID` | Payment succeeded. |
| `FAILED` | Payment attempt failed. |
| `EXPIRED` | The checkout passed `valid_until` without being paid. |

Your Server then passes the checkout `id` to the Client **(C)**. Pass only the `id`, never your API key or access token.

## 5. Process the payment (D)

### 5.1 Recommended: SumUp Payment Widget

The Payment Widget is a JavaScript component that SumUp hosts. It renders the card form, sends the card data directly to SumUp, and runs 3-D Secure when the card issuer requires it.

```html
<div id="sumup-card"></div>
<script src="https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js"></script>
<script>
  SumUpCard.mount({
    id: 'sumup-card',
    checkoutId: '80e5e401-a503-4333-a446-6f190c08d617', // from step (B)
    onResponse: function (type, body) {
      // type: 'sent' | 'invalid' | 'auth-screen' | 'error' | 'success' | 'fail'
      if (type === 'success' || type === 'fail' || type === 'error') {
        // Don't trust this as the final result; ask your Server,
        // which verifies the checkout with SumUp (see section 6).
        window.location.href = '/checkout/complete?id=80e5e401-a503-4333-a446-6f190c08d617';
      }
    },
  });
</script>
```

Native apps can show the same widget in a WebView, or use SumUp's mobile SDKs.

### 5.2 Alternative: process the checkout via the API

> **Requires full PCI DSS compliance and approval from SumUp.** Only use this if your systems are allowed to handle raw card data.

    PUT https://api.sumup.com/v0.1/checkouts/{id}
    Content-Type: application/json

    {
        "payment_type": "card",
        "card": {
            "name": "Jane Doe",
            "number": "4111111111111111",
            "expiry_month": "12",
            "expiry_year": "2028",
            "cvv": "123"
        }
    }

`{id}` is the checkout `id` from step (B). The one-time token (`POST /one-time-tokens`, the `X-Sumup-Allow-Origin` header and the `?otp=` query parameter) from earlier versions of this flow is no longer used.

SumUp responds with the checkout object. If the card issuer requires **3-D Secure**, the response has a `next_step` object instead of a final status:

    {
        "id": "80e5e401-a503-4333-a446-6f190c08d617",
        "status": "PENDING",
        "next_step": {
            "url": "https://...",
            "method": "POST",
            "payload": { "...": "..." },
            "redirect_url": "https://my.domain.com/checkout/complete",
            "mechanism": ["iframe", "browser"]
        }
    }

To continue, send the customer's browser to `next_step.url` with the given `method` and `payload` (for example by auto-submitting a form). When the customer finishes the challenge, they return to `redirect_url`, and your Server confirms the outcome as described in [6](#6-receive-the-result-e-f).

### 5.3 Alternative: Hosted Checkout

Create the checkout with `"hosted_checkout": { "enabled": true }`. The response then has a `hosted_checkout_url`. Redirect the customer there; SumUp shows the payment page, takes the payment, and sends the customer back to `redirect_url`.

## 6. Receive the result (E, F)

### 6.1 Webhook (E)

If you set `return_url` when creating the checkout, SumUp sends a `POST` to it whenever the checkout status changes:

    {
        "event_type": "CHECKOUT_STATUS_CHANGED",
        "id": "80e5e401-a503-4333-a446-6f190c08d617"
    }

Reply quickly with a `2xx` status. **Don't trust the webhook body as proof of payment.** Use it only as a signal to fetch the checkout (F).

### 6.2 Get checkout status (F)

Only your **Server** calls this endpoint, because it needs your secret credentials:

    GET https://api.sumup.com/v0.1/checkouts/{id}
    Authorization: Bearer {token}

It returns the checkout object (see [4](#4-create-a-checkout-b)). When the checkout is `PAID`, the `transactions` array has the transaction details (`transaction_code`, `status`, `amount` and others). Fulfil the order only after you have confirmed `PAID` here.

Your Server then shows the result to the Client **(G)**.

## 7. Further reading

*   [Accept a payment guide](https://developer.sumup.com/online-payments/guides/single-payment)
*   [Checkouts API reference](https://developer.sumup.com/api/checkouts)
*   [Hosted Checkout](https://developer.sumup.com/online-payments/checkouts/hosted-checkout)
*   [Authorization (API keys and OAuth 2.0)](https://developer.sumup.com/tools/authorization/authorization)
*   [Changelog](https://developer.sumup.com/changelog)
*   Official server SDKs (they take care of authentication and request formats): [Python](https://github.com/sumup/sumup-py), [PHP](https://github.com/sumup/sumup-ecom-php-sdk), [.NET](https://github.com/sumup/sumup-dotnet), and others under [github.com/sumup](https://github.com/sumup).
