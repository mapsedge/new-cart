<?php
$p        = DB_PREFIX;
$order_id = (int)($_SESSION['pending_order_id'] ?? get('order', 0));

$order = $order_id
	? DB::row("SELECT * FROM `{$p}orders` WHERE id = ?", [$order_id])
	: null;

// Clear cart and pending order
Cart::clear();
unset($_SESSION['pending_order_id']);

// ── POST: create account ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_account') {
	header('Content-Type: application/json');

	if (!$order) { echo json_encode(['ok' => false, 'message' => 'Order not found.']); exit; }

	$password = trim(post('password'));
	if (strlen($password) < 8) {
		echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters.']);
		exit;
	}

	$existing = DB::row("SELECT id FROM `{$p}customers` WHERE email = ?", [$order['email']]);
	if ($existing) {
		echo json_encode(['ok' => false, 'message' => 'An account already exists for this email.']);
		exit;
	}

	$customer_id = DB::insert(
		"INSERT INTO `{$p}customers`
		 (email, first_name, last_name, password_hash,
		  ship_address1, ship_city, ship_state, ship_zip, ship_country, created_at)
		 VALUES (?,?,?,?, ?,?,?,?,?, NOW())",
		[
			$order['email'],
			$order['first_name'],
			$order['last_name'],
			password_hash($password, PASSWORD_DEFAULT),
			$order['ship_address1'],
			$order['ship_city'],
			$order['ship_state'],
			$order['ship_zip'],
			$order['ship_country'],
		]
	);

	// Link order to new customer
	DB::exec("UPDATE `{$p}orders` SET customer_id = ? WHERE id = ?", [$customer_id, $order['id']]);

	$_SESSION['customer_id'] = $customer_id;
	echo json_encode(['ok' => true, 'message' => 'Account created! You are now signed in.']);
	exit;
}

catalog_sidebar($smarty);
$smarty->assign('order',     $order);
$smarty->assign('page_type', 'order-complete');
$smarty->display('order-complete.html');
