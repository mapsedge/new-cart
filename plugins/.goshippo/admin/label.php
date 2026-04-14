<?php
/**
 * GoShippo — generate shipping label
 * route=goshippo/label
 * POST: order_id, shippo_rate_token
 * Admin only.
 */

require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $msg = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $msg] + $extra);
	exit;
}

$order_id         = (int)($_POST['order_id'] ?? 0);
$shippo_rate_token = trim($_POST['shippo_rate_token'] ?? '');

if (!$order_id || !$shippo_rate_token) out(false, 'Missing order_id or rate token.');

$p     = DB_PREFIX;
$order = DB::row("SELECT * FROM `{$p}orders` WHERE id = ?", [$order_id]);
if (!$order) out(false, 'Order not found.');

// Create transaction (purchase label)
$transaction = goshippo_api('transactions', [
	'rate'            => $shippo_rate_token,
	'label_file_type' => 'PDF',
	'async'           => false,
]);

if (empty($transaction['label_url'])) {
	$msg = $transaction['messages'][0]['text'] ?? 'Label generation failed.';
	out(false, $msg);
}

// Store tracking info on order
DB::exec(
	"UPDATE `{$p}orders` SET tracking_number=?, tracking_url=?, label_url=?, status='shipped' WHERE id=?",
	[
		$transaction['tracking_number'] ?? '',
		$transaction['tracking_url_provider'] ?? '',
		$transaction['label_url'],
		$order_id,
	]
);

$order['tracking_number'] = $transaction['tracking_number'] ?? '';
$order['tracking_url']    = $transaction['tracking_url_provider'] ?? '';
$order['label_url']       = $transaction['label_url'];

Hook::fire('catalog.order.label_generate', $order);

out(true, 'Label generated.', [
	'label_url'       => $transaction['label_url'],
	'tracking_number' => $transaction['tracking_number'] ?? '',
	'tracking_url'    => $transaction['tracking_url_provider'] ?? '',
]);
