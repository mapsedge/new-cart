<?php
$p = DB_PREFIX;

// If a home page with blocks exists, use the page renderer
$homePage = DB::row("SELECT id FROM `{$p}pages` WHERE slug='home' AND status=1");
if ($homePage) {
	$_GET['slug'] = 'home';
	require DIR_CTL . 'page.php';
	exit;
}

// Fallback: plain product grid
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
