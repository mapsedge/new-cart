<?php
$p    = DB_PREFIX;
$slug = get('slug', '');

$product = DB::row(
	"SELECT *, description_long FROM `{$p}products` WHERE slug = ? AND status > 0",
	[$slug]
);
if (!$product) {
	require DIR_CTL . '404.php';
	exit;
}

// Images
$images = DB::rows(
	"SELECT * FROM `{$p}product_images`
	 WHERE product_id = ? ORDER BY display_order ASC",
	[$product['id']]
);

// Options attached to this product
$product_options = DB::rows(
	"SELECT po.*, o.name AS option_name, o.type, o.placeholder
	 FROM `{$p}product_options` po
	 JOIN `{$p}options` o ON o.id = po.option_id
	 WHERE po.product_id = ?
	 ORDER BY po.display_order ASC",
	[$product['id']]
);

// For each product option, get its values
foreach ($product_options as &$po) {
	$po['values'] = DB::rows(
		"SELECT pov.*, ov.text AS value_text, ov.image
		 FROM `{$p}product_option_values` pov
		 JOIN `{$p}option_values` ov ON ov.id = pov.option_value_id
		 WHERE pov.product_option_id = ? AND pov.enabled = 1
		 ORDER BY ov.display_order ASC",
		[$po['id']]
	);
}
unset($po);

catalog_sidebar($smarty);

$smarty->assign('product',         $product);
$smarty->assign('images',          $images);
$smarty->assign('product_options', $product_options);
$smarty->assign('page_type',       'product');
$smarty->display('product.html');
