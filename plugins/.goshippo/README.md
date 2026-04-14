# GoShippo Shipping Plugin

## Setup

1. Enable this plugin from Admin → Plugins
2. Go to Admin → Setup & Utilities → Options → Integrations
3. Enter your Shippo API Key and ship-from address
4. Set your default parcel dimensions and weight

## How it works

- At checkout, the catalog calls `Hook::filter('catalog.checkout.shipping_rates', [], $context)`
  where `$context` contains `items` and `address`
- This plugin calls the Shippo API to fetch live rates for the destination
- Rates are returned sorted cheapest first and displayed to the customer
- After the customer selects a rate, the `shippo_rate_token` is stored with the order
- From Admin → Orders, clicking "Generate Label" calls `/?route=goshippo/label`
  which purchases the label and returns a PDF URL + tracking number

## Hook points

- `catalog.checkout.shipping_rates` — adds live Shippo rates to the shipping options list
- `catalog.order.label_generate` — fired after a label is successfully purchased

## Files

- `hooks.php` — registers hooks + Shippo API helpers
- `admin/label.php` — generates a shipping label for an order
