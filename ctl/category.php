<?php
$p    = DB_PREFIX;
$slug = get('slug', '');

$category = DB::row(
	"SELECT * FROM `{$p}categories` WHERE slug = ? AND status > 0",
	[$slug]
);
if (!$category) {
	require DIR_CTL . '404.php';
	exit;
}

catalog_sidebar($smarty);
$smarty->assign('current_category', $category);

$products = DB::rows(
	"SELECT p.id, p.name, p.slug, p.price, p.list_price,
	        pi.filename AS image
	 FROM `{$p}products` p
	 JOIN `{$p}categories_products` cp ON cp.product_id = p.id
	 LEFT JOIN `{$p}product_images` pi
	   ON pi.product_id = p.id AND pi.display_order = (
	      SELECT MIN(display_order) FROM `{$p}product_images` WHERE product_id = p.id
	   )
	 WHERE cp.category_id = ? AND p.status > 0
	 ORDER BY p.display_order ASC, p.name ASC",
	[$category['id']]
);

$smarty->assign('category', $category);
$smarty->assign('products', $products);
$smarty->assign('page_type', 'category');
$smarty->display('category.html');
