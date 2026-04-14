/**
 * Stripe Payment Element
 * Included on the checkout page when Stripe is the selected payment method.
 *
 * Expects globals:
 *   STRIPE_PUBLISHABLE_KEY  — set by catalog checkout template
 *   CART_AMOUNT_CENTS       — order total in cents
 *   CART_CURRENCY           — e.g. 'usd'
 *   ORDER_ID                — pending order id
 *   THANKYOU_URL            — redirect on success
 */
(function () {
'use strict';

if (!window.STRIPE_PUBLISHABLE_KEY) return;

const stripe = Stripe(window.STRIPE_PUBLISHABLE_KEY);
let elements, paymentElement;

// ── Create payment intent and mount Payment Element ────────────────────────
async function initStripe() {
	const btn = document.getElementById('btn-pay-stripe');
	if (btn) btn.disabled = true;

	const fd = new FormData();
	fd.append('amount',   window.CART_AMOUNT_CENTS);
	fd.append('currency', window.CART_CURRENCY || 'usd');
	fd.append('order_id', window.ORDER_ID || 0);

	const res  = await fetch('/?route=stripe/intent', { method: 'POST', body: fd });
	const data = await res.json();

	if (!data.ok) {
		showError(data.message || 'Could not initialise payment.');
		if (btn) btn.disabled = false;
		return;
	}

	elements = stripe.elements({ clientSecret: data.client_secret });
	paymentElement = elements.create('payment');
	paymentElement.mount('#stripe-payment-element');

	if (btn) btn.disabled = false;
}

// ── Submit payment ─────────────────────────────────────────────────────────
async function submitPayment() {
	if (!elements) return;

	const btn = document.getElementById('btn-pay-stripe');
	if (btn) btn.disabled = true;

	const { error } = await stripe.confirmPayment({
		elements,
		confirmParams: {
			return_url: window.THANKYOU_URL,
		},
	});

	// Only reaches here on error — success redirects automatically
	if (error) {
		showError(error.message);
		if (btn) btn.disabled = false;
	}
}

function showError(msg) {
	const el = document.getElementById('stripe-error');
	if (el) { el.textContent = msg; el.style.display = 'block'; }
}

// ── Wire button ────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
	const btn = document.getElementById('btn-pay-stripe');
	if (btn) {
		debounceBtn(btn, submitPayment);
	}
	initStripe();
});

})();
