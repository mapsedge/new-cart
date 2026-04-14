<?php
/**
 * Stripe webhook endpoint
 * route=stripe/webhook
 * Verifies signature, handles payment_intent.succeeded
 */

$payload    = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret     = DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key` = 'stripe_webhook_secret'") ?: '';

if ($secret) {
	// Verify Stripe signature
	$parts     = [];
	foreach (explode(',', $sig_header) as $part) {
		[$k, $v]    = explode('=', $part, 2);
		$parts[$k][] = $v;
	}
	$timestamp  = $parts['t'][0] ?? 0;
	$signatures = $parts['v1'] ?? [];
	$signed     = $timestamp . '.' . $payload;
	$expected   = hash_hmac('sha256', $signed, $secret);

	$valid = false;
	foreach ($signatures as $sig) {
		if (hash_equals($expected, $sig)) { $valid = true; break; }
	}

	if (!$valid || (time() - (int)$timestamp) > 300) {
		http_response_code(400);
		exit('Invalid signature.');
	}
}

$event = json_decode($payload, true);
if (!$event) { http_response_code(400); exit('Bad payload.'); }

if ($event['type'] === 'payment_intent.succeeded') {
	$intent   = $event['data']['object'];
	$order_id = (int)($intent['metadata']['order_id'] ?? 0);

	if ($order_id) {
		$p = DB_PREFIX;
		DB::exec(
			"UPDATE `{$p}orders` SET status='paid', payment_ref=? WHERE id=?",
			[$intent['id'], $order_id]
		);
		$order = DB::row("SELECT * FROM `{$p}orders` WHERE id=?", [$order_id]);
		if ($order) {
			Hook::fire('catalog.order.payment_complete', $order);
		}
	}
}

http_response_code(200);
echo 'ok';
