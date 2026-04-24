<?php
$action = post('action', get('action', ''));

// ── Add to cart (AJAX POST) ────────────────────────────────────────────────────
if ($action === 'add') {
	header('Content-Type: application/json');
	$product_id = (int)post('product_id');
	$qty        = max(1, (int)post('qty', 1));

	$options = [];
	$raw_opts = post('options', []);
	if (is_array($raw_opts)) {
		foreach ($raw_opts as $po_id => $pov_id) {
			$options[(int)$po_id] = (int)$pov_id;
		}
	}

	$p = DB_PREFIX;
	$product = DB::row("SELECT id FROM `{$p}products` WHERE id = ? AND status > 0", [$product_id]);
	if (!$product) {
		echo json_encode(['ok' => false, 'message' => 'Product not found.']);
		exit;
	}

	Cart::add($product_id, $qty, $options);
	echo json_encode([
		'ok'           => true,
		'cart_count'   => Cart::count(),
		'cart_subtotal'=> Cart::subtotal(),
	]);
	exit;
}

// ── Update qty (AJAX POST) ────────────────────────────────────────────────────
if ($action === 'update') {
	header('Content-Type: application/json');
	$index = (int)post('index');
	$qty   = (int)post('qty');
	Cart::update($index, $qty);
	$items    = Cart::get();
	$subtotal = Cart::subtotal();
	echo json_encode([
		'ok'       => true,
		'count'    => Cart::count(),
		'subtotal' => money($subtotal),
		'items'    => array_map(fn($i) => [
			'index'      => array_search($i, $items),
			'line_total' => money($i['line_total']),
		], $items),
	]);
	exit;
}

// ── Remove (AJAX POST) ────────────────────────────────────────────────────────
if ($action === 'remove') {
	header('Content-Type: application/json');
	Cart::remove((int)post('index'));
	echo json_encode(['ok' => true, 'count' => Cart::count(), 'subtotal' => money(Cart::subtotal())]);
	exit;
}

// ── View cart page ─────────────────────────────────────────────────────────────
$p = DB_PREFIX;

$cart_page = DB::row("SELECT * FROM `{$p}pages` WHERE slug='cart'");
$blocks    = $cart_page ? hydrate_page_blocks($cart_page['id'], $p, $smarty) : [];

$img_cart_size = max(40, (int)(DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='img_cart_size'") ?: 100));

catalog_sidebar($smarty);
$smarty->assign('items',         Cart::get());
$smarty->assign('subtotal',      money(Cart::subtotal()));
$smarty->assign('page',          $cart_page);
$smarty->assign('blocks',        $blocks);
$smarty->assign('img_cart_size', $img_cart_size);
$smarty->assign('page_type',     'cart');
$smarty->display('cart.html');
