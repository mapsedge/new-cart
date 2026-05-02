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

// Related products + image display settings
$_rel_settings    = [];
$_rel_rows        = DB::rows("SELECT `key`, `value` FROM `{$p}settings` WHERE `key` IN ('img_related_size','related_max_items','img_product_width')");
foreach ($_rel_rows as $_r) $_rel_settings[$_r['key']] = $_r['value'];
$rel_thumb_size    = max(80,  (int)($_rel_settings['img_related_size']  ?? 200));
$rel_max_items     = max(0,   (int)($_rel_settings['related_max_items'] ?? 0));
$img_product_width = max(200, (int)($_rel_settings['img_product_width'] ?? 600));

$_rel_limit = $rel_max_items > 0 ? " LIMIT {$rel_max_items}" : '';
$related_products = DB::rows(
	"SELECT p.id, p.name, p.slug, p.price, p.list_price, p.description,
	        (SELECT filename FROM `{$p}product_images`
	         WHERE product_id = p.id AND is_primary = 1
	         ORDER BY display_order ASC LIMIT 1) AS image
	 FROM `{$p}product_related` pr
	 JOIN `{$p}products` p ON p.id = pr.related_product_id
	 WHERE pr.product_id = ? AND p.status > 0
	 ORDER BY pr.display_order ASC, p.name ASC{$_rel_limit}",
	[$product['id']]
);

// Load blocks from the Product system page (rendered below the product view).
require_once DIR_LIB . 'page_block_helper.php';
$_prod_sys   = DB::row("SELECT id FROM `{$p}pages` WHERE slug='product' AND page_type='product' LIMIT 1");
$below_blocks = [];
if ($_prod_sys) {
	foreach (hydrate_page_blocks((int)$_prod_sys['id'], $p, $smarty) as $_b) {
		if ($_b['block_type'] !== 'product_view') {
			$below_blocks[] = $_b;
		}
	}
}

$smarty->assign('product',        $product);
$smarty->assign('images',         $images);
$smarty->assign('product_options', $product_options);
$smarty->assign('related_products', $related_products);
$smarty->assign('rel_thumb_size',  $rel_thumb_size);
$smarty->assign('img_product_width', $img_product_width);
$smarty->assign('below_blocks',   $below_blocks);
$smarty->assign('page_type',      'product');
$smarty->display('product.html');
