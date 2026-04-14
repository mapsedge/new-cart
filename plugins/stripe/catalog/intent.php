<?php
/**
 * Stripe — create PaymentIntent
 * route=stripe/intent
 * POST: amount (int cents), currency (str), order_id (int)
 */

header('Content-Type: application/json');

function stripe_setting(string $key): string {
	static $cache = [];
	if (!isset($cache[$key])) {
		$cache[$key] = DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key` = ?", [$key]) ?: '';
	}
	return $cache[$key];
}

$secret = stripe_setting('stripe_secret_key');
if (!$secret) {
	echo json_encode(['ok' => false, 'message' => 'Stripe is not configured.']);
	exit;
}

$amount   = (int)($_POST['amount']   ?? 0);
$currency = preg_replace('/[^a-z]/', '', strtolower($_POST['currency'] ?? 'usd'));
$order_id = (int)($_POST['order_id'] ?? 0);

if ($amount < 50) {
	echo json_encode(['ok' => false, 'message' => 'Invalid amount.']);
	exit;
}

// Call Stripe API
$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_POST           => true,
	CURLOPT_USERPWD        => $secret . ':',
	CURLOPT_POSTFIELDS     => http_build_query([
		'amount'               => $amount,
		'currency'             => $currency,
		'metadata[order_id]'   => $order_id,
		'automatic_payment_methods[enabled]' => 'true',
	]),
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
	echo json_encode(['ok' => false, 'message' => 'Payment service unavailable.']);
	exit;
}

$data = json_decode($response, true);
if (!empty($data['error'])) {
	echo json_encode(['ok' => false, 'message' => $data['error']['message'] ?? 'Stripe error.']);
	exit;
}

echo json_encode([
	'ok'            => true,
	'client_secret' => $data['client_secret'],
	'intent_id'     => $data['id'],
]);
