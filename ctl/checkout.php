<?php
$p = DB_PREFIX;

// Redirect if cart empty
$items = Cart::get();
if (empty($items)) {
	header('Location: ' . URL_ROOT . 'cart');
	exit;
}

// ── POST: place order ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'place_order') {
	header('Content-Type: application/json');

	$email      = trim(post('email'));
	$first_name = trim(post('first_name'));
	$last_name  = trim(post('last_name'));
	$address1   = trim(post('address1'));
	$address2   = trim(post('address2'));
	$city       = trim(post('city'));
	$state      = trim(post('state'));
	$zip        = trim(post('zip'));
	$country    = trim(post('country', 'US'));
	$ship_rate  = trim(post('ship_rate_id', ''));
	$ship_price = (float)post('ship_price', 0);
	$ship_label = trim(post('ship_label', 'Standard'));
	$ship_token = trim(post('shippo_token', ''));

	if (!$email || !$first_name || !$last_name || !$address1 || !$city || !$zip) {
		echo json_encode(['ok' => false, 'message' => 'Please fill in all required fields.']);
		exit;
	}

	$subtotal = Cart::subtotal();
	$total    = $subtotal + $ship_price;

	// Create or find customer if logged in
	$customer_id = is_logged_in() ? ($_SESSION['customer_id'] ?? null) : null;

	// Insert order
	$order_id = DB::insert(
		"INSERT INTO `{$p}orders`
		 (customer_id, email, first_name, last_name,
		  ship_address1, ship_address2, ship_city, ship_state, ship_zip, ship_country,
		  subtotal, shipping, total, status, ship_method, shippo_rate_token, created_at)
		 VALUES (?,?,?,?, ?,?,?,?,?,?, ?,?,?,'pending',?,?, NOW())",
		[
			$customer_id, $email, $first_name, $last_name,
			$address1, $address2, $city, $state, $zip, $country,
			$subtotal, $ship_price, $total, $ship_label, $ship_token,
		]
	);

	// Insert order items
	foreach ($items as $item) {
		DB::exec(
			"INSERT INTO `{$p}order_items`
			 (order_id, product_id, name, price, qty, options_summary)
			 VALUES (?,?,?,?,?,?)",
			[
				$order_id,
				$item['product_id'],
				$item['product']['name'],
				$item['unit_price'],
				$item['qty'],
				implode(', ', array_map(
					fn($o) => $o['option_name'] . ': ' . $o['value_text'],
					$item['options']
				)),
			]
		);
	}

	$_SESSION['pending_order_id'] = $order_id;

	echo json_encode([
		'ok'          => true,
		'order_id'    => $order_id,
		'total_cents' => (int)round($total * 100),
		'currency'    => strtolower(SITE_CURRENCY_CODE ?? 'usd'),
		'thankyou_url' => URL_ROOT . 'order-complete',
	]);
	exit;
}

// ── GET shipping rates ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'shipping_rates') {
	header('Content-Type: application/json');

	$address = [
		'first_name' => trim(post('first_name')),
		'last_name'  => trim(post('last_name')),
		'address1'   => trim(post('address1')),
		'address2'   => trim(post('address2')),
		'city'       => trim(post('city')),
		'state'      => trim(post('state')),
		'zip'        => trim(post('zip')),
		'country'    => trim(post('country', 'US')),
	];

	$rates = Hook::filter('catalog.checkout.shipping_rates', [], [
		'items'   => $items,
		'address' => $address,
	]);

	// If no plugin provides rates, offer a free shipping fallback
	if (empty($rates)) {
		$rates = [[
			'id'      => 'free',
			'carrier' => '',
			'service' => 'Standard Shipping',
			'rate'    => 0.0,
			'days'    => null,
		]];
	}

	echo json_encode(['ok' => true, 'rates' => $rates]);
	exit;
}

// ── Render checkout page ──────────────────────────────────────────────────────
$stripe_key = '';
if (in_array('stripe', PluginLoader::loaded())) {
	$mode       = DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='stripe_mode'") ?: 'test';
	$stripe_key = $mode === 'live'
		? (DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='stripe_publishable_key'") ?: '')
		: (DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='stripe_test_publishable_key'") ?: '');
}

$payment_methods = Hook::filter('catalog.checkout.payment_methods', []);
if (empty($payment_methods)) {
	$payment_methods = [['id' => 'cod', 'label' => 'Pay on Delivery', 'icon' => '']];
}

$checkout_page = DB::row("SELECT * FROM `{$p}pages` WHERE slug='checkout'");
$blocks        = $checkout_page ? hydrate_page_blocks($checkout_page['id'], $p, $smarty) : [];

catalog_sidebar($smarty);
$smarty->assign('items',           Cart::get());
$smarty->assign('subtotal',        money(Cart::subtotal()));
$smarty->assign('subtotal_raw',    Cart::subtotal());
$smarty->assign('payment_methods', $payment_methods);
$smarty->assign('stripe_key',      $stripe_key);
$smarty->assign('page',            $checkout_page ?: []);
$smarty->assign('blocks',          $blocks);
$smarty->assign('page_type',       'checkout');
$smarty->display('checkout.html');
