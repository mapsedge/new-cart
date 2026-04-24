<?php
$p = DB_PREFIX;

// Find the designated homepage: check home_page_id setting first,
// then fall back to slug='home' for backwards compatibility.
$home_page_id = (int)(DB::val("SELECT `value` FROM `{$p}settings` WHERE `key`='home_page_id'") ?? 0);

if ($home_page_id) {
	$homePage = DB::row("SELECT id, slug FROM `{$p}pages` WHERE id=?", [$home_page_id]);
} else {
	$homePage = DB::row("SELECT id, slug FROM `{$p}pages` WHERE slug='home'");
}

if ($homePage) {
	$_GET['slug'] = $homePage['slug'];
	require DIR_CTL . 'page.php';
	exit;
}

// No home page built yet — fall back to plain product grid
catalog_sidebar($smarty);

$products = DB::rows(
	"SELECT p.id, p.name, p.slug, p.price, p.list_price,
	        pi.filename AS image
	 FROM `{$p}products` p
	 LEFT JOIN `{$p}product_images` pi
	   ON pi.product_id = p.id AND pi.display_order = (
	      SELECT MIN(display_order) FROM `{$p}product_images` WHERE product_id = p.id
	   )
	 WHERE p.status > 0
	 ORDER BY p.display_order ASC, p.name ASC"
);

$smarty->assign('products',  $products);
$smarty->assign('page_type', 'home');
$smarty->display('home.html');
