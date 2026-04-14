# Stripe Payments Plugin

## Setup

1. Enable this plugin from Admin → Plugins
2. Go to Admin → Setup & Utilities → Options → Integrations
3. Enter your Stripe Publishable Key, Secret Key, and Webhook Secret
4. In your Stripe Dashboard, create a webhook pointing to:
   `https://yourstore.com/?route=stripe/webhook`
   listening for the `payment_intent.succeeded` event

## How it works

- At checkout, if Stripe is the selected payment method, the catalog calls
  `/?route=stripe/intent` (POST) to create a PaymentIntent
- Stripe.js Payment Element is mounted in a dialog
- On success, Stripe redirects to the Thank You page
- Stripe fires a webhook which updates the order status to 'paid' and triggers
  the `catalog.order.payment_complete` hook

## Hook points

- `catalog.checkout.payment_methods` — adds Stripe to the payment method list
- `catalog.order.payment_complete` — fired after webhook confirms payment

## Files

- `hooks.php` — registers hooks
- `catalog/intent.php` — creates PaymentIntent (server-side)
- `catalog/webhook.php` — handles Stripe webhook events
- `catalog/stripe-checkout.js` — Payment Element UI
