<?php
header('Content-Type: application/json');

$action = post('action', get('action', ''));
$p      = DB_PREFIX;

// ── Get lists (for product page dropdown) ─────────────────────────────────────
if ($action === 'get_lists') {
	$lists = WishList::guestLists();
	// If no lists exist yet, return empty but don't auto-create
	echo json_encode(['ok' => true, 'lists' => $lists]);
	exit;
}

// ── Create list ───────────────────────────────────────────────────────────────
if ($action === 'create') {
	$name     = trim(post('name', 'My Wish List'));
	$customer_id = is_logged_in() ? ($_SESSION['customer_id'] ?? null) : null;
	$list = WishList::create($name, $customer_id);
	echo json_encode(['ok' => true, 'list' => $list]);
	exit;
}

// ── Add to list ───────────────────────────────────────────────────────────────
if ($action === 'add') {
	$token      = trim(post('token'));
	$product_id = (int)post('product_id');
	$ok = WishList::addItem($token, $product_id);
	echo json_encode(['ok' => $ok, 'message' => $ok ? '' : 'Could not add to wish list.']);
	exit;
}

// ── Remove from list ──────────────────────────────────────────────────────────
if ($action === 'remove') {
	$token      = trim(post('token'));
	$product_id = (int)post('product_id');
	$ok = WishList::removeItem($token, $product_id);
	echo json_encode(['ok' => $ok]);
	exit;
}

// ── Persist guest email ───────────────────────────────────────────────────────
if ($action === 'persist_email') {
	$token = trim(post('token'));
	$email = trim(post('email'));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		echo json_encode(['ok' => false, 'message' => 'Invalid email address.']);
		exit;
	}
	$ok = WishList::persistEmail($token, $email);
	echo json_encode(['ok' => $ok]);
	exit;
}

// ── View a wish list (public) ─────────────────────────────────────────────────
$token = get('slug', get('token', ''));
if ($token) {
	$list = WishList::byToken($token);
	if (!$list) {
		require DIR_CTL . '404.php';
		exit;
	}
	$items   = WishList::items($token);
	$is_mine = in_array($token, array_column(WishList::guestTokens(), 'token'));
	catalog_sidebar($smarty);
	$smarty->assign('wl',      $list);
	$smarty->assign('items',   $items);
	$smarty->assign('is_mine', $is_mine);
	$smarty->display('wishlist.html');
	exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
