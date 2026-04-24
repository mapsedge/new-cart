<?php
/**
 * new-cart admin — products ajax handler
 * route=products/ajax
 */

require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $message = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
	exit;
}

$p      = DB_PREFIX;
$action = post('action');

// ── List ───────────────────────────────────────────────────────────────────────
if ($action === 'list') {
	$rows = DB::rows("
		SELECT p.id, p.name, p.slug, p.sku, p.price, p.list_price,
		       p.stock, p.status, p.featured, p.free_shipping, p.display_order,
		       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS categories
		FROM `{$p}products` p
		LEFT JOIN `{$p}categories_products` cp ON cp.product_id = p.id
		LEFT JOIN `{$p}categories` c ON c.id = cp.category_id
		GROUP BY p.id
		ORDER BY p.display_order ASC, p.name ASC
	");
	out(true, '', ['rows' => $rows]);
}

// ── Get single ────────────────────────────────────────────────────────────────
if ($action === 'get') {
	$id  = (int)post('id');
	$row = DB::row("SELECT * FROM `{$p}products` WHERE id = ?", [$id]);
	if (!$row) out(false, 'Product not found.');

	// Category ids
	$cat_ids = DB::rows(
		"SELECT category_id FROM `{$p}categories_products` WHERE product_id = ?",
		[$id]
	);
	$row['category_ids'] = array_column($cat_ids, 'category_id');

	// Images
	$images = DB::rows(
		"SELECT * FROM `{$p}product_images` WHERE product_id = ? ORDER BY display_order ASC",
		[$id]
	);
	$row['images'] = $images;

	out(true, '', ['row' => $row]);
}

// ── Save (insert or update) ────────────────────────────────────────────────────
if ($action === 'save') {
	require_access(ACCESS_EDIT);

	$id              = (int)post('id');
	$name            = trim(post('name'));
	$sku             = trim(post('sku'));
	$price           = (float)post('price');
	$list_price      = (float)post('list_price');
	$stock           = (int)post('stock');
	$status          = (int)post('status');
	$featured        = (int)(bool)post('featured');
	$free_ship       = (int)(bool)post('free_shipping');
	$desc_short      = trim(post('description'));
	$desc_long       = post('description_long');
	$seo_title       = trim(post('seo_title'));
	$seo_keywords    = trim(post('seo_keywords'));
	$seo_description = trim(post('seo_description'));
	$cat_ids         = json_decode(post('category_ids') ?: '[]', true);
	$cat_ids         = array_map('intval', (array)$cat_ids);

	if (!$name) out(false, 'Product name is required.');
	if ($price < 0) out(false, 'Price cannot be negative.');

	// Slug
	$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
	$existing = DB::row(
		"SELECT id FROM `{$p}products` WHERE slug = ? AND id != ?",
		[$slug, $id]
	);
	if ($existing) $slug .= '-' . ($id ?: time());

	if ($id) {
		require_access(ACCESS_EDIT);
		$params = [$name, $slug, $sku, $price, $list_price, $stock,
		           $status, $featured, $free_ship, $desc_short, $desc_long,
		           $seo_title, $seo_keywords, $seo_description, $id];
		DB::exec("UPDATE `{$p}products` SET
			name=?, slug=?, sku=?, price=?, list_price=?, stock=?,
			status=?, featured=?, free_shipping=?, description=?, description_long=?,
			seo_title=?, seo_keywords=?, seo_description=?
			WHERE id=?", $params);
	} else {
		require_access(ACCESS_ADD);
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}products`");
		$id  = DB::insert("INSERT INTO `{$p}products`
			(name, slug, sku, price, list_price, stock, status, featured,
			 free_shipping, description, description_long,
			 seo_title, seo_keywords, seo_description, display_order)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			[$name, $slug, $sku, $price, $list_price, $stock, $status, $featured,
			 $free_ship, $desc_short, $desc_long,
			 $seo_title, $seo_keywords, $seo_description, $max + 1]
		);
	}

	// Update categories
	DB::exec("DELETE FROM `{$p}categories_products` WHERE product_id = ?", [$id]);
	foreach ($cat_ids as $cid) {
		if ($cid > 0) {
			DB::exec(
				"INSERT IGNORE INTO `{$p}categories_products` (category_id, product_id) VALUES (?, ?)",
				[$cid, $id]
			);
		}
	}

	$row = DB::row("
		SELECT p.*, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS categories
		FROM `{$p}products` p
		LEFT JOIN `{$p}categories_products` cp ON cp.product_id = p.id
		LEFT JOIN `{$p}categories` c ON c.id = cp.category_id
		WHERE p.id = ?
		GROUP BY p.id
	", [$id]);

	out(true, 'Product saved.', ['row' => $row]);
}

// ── Toggle field ──────────────────────────────────────────────────────────────
if ($action === 'toggle') {
	require_access(ACCESS_EDIT);
	$id    = (int)post('id');
	$field = post('field');
	$value = (int)post('value');

	$allowed = ['status', 'featured', 'free_shipping'];
	if (!in_array($field, $allowed, true)) out(false, 'Invalid field.');

	DB::exec("UPDATE `{$p}products` SET `{$field}` = ? WHERE id = ?", [$value, $id]);
	out(true, '');
}

// ── Reorder ───────────────────────────────────────────────────────────────────
if ($action === 'reorder') {
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids)) out(false, 'Invalid order data.');
	foreach ($ids as $order => $id) {
		DB::exec("UPDATE `{$p}products` SET display_order = ? WHERE id = ?", [$order, (int)$id]);
	}
	out(true, '');
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}products` WHERE id = ?", [$id]);
	DB::exec("DELETE FROM `{$p}categories_products` WHERE product_id = ?", [$id]);
	DB::exec("DELETE FROM `{$p}product_images` WHERE product_id = ?", [$id]);
	DB::exec("DELETE FROM `{$p}product_related` WHERE product_id = ? OR related_product_id = ?", [$id, $id]);
	out(true, 'Product deleted.');
}

// ── Bulk delete ───────────────────────────────────────────────────────────────
if ($action === 'bulk_delete') {
	require_access(ACCESS_DELETE);
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids) || empty($ids)) out(false, 'No products selected.');
	$ids          = array_map('intval', $ids);
	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	DB::exec("DELETE FROM `{$p}products` WHERE id IN ({$placeholders})", $ids);
	DB::exec("DELETE FROM `{$p}categories_products` WHERE product_id IN ({$placeholders})", $ids);
	DB::exec("DELETE FROM `{$p}product_images` WHERE product_id IN ({$placeholders})", $ids);
	DB::exec("DELETE FROM `{$p}product_related` WHERE product_id IN ({$placeholders}) OR related_product_id IN ({$placeholders})", array_merge($ids, $ids));
	out(true, count($ids) . ' product' . (count($ids) === 1 ? '' : 's') . ' deleted.');
}

// ── Clone ─────────────────────────────────────────────────────────────────────
if ($action === 'clone') {
	require_access(ACCESS_ADD);
	$id  = (int)post('id');
	$src = DB::row("SELECT * FROM `{$p}products` WHERE id=?", [$id]);
	if (!$src) out(false, 'Product not found.');

	// Unique slug
	$slug = $src['slug'] . '-copy';
	$n = 1;
	while (DB::val("SELECT id FROM `{$p}products` WHERE slug=?", [$slug])) {
		$slug = $src['slug'] . '-copy-' . (++$n);
	}

	$max    = (int)DB::val("SELECT MAX(display_order) FROM `{$p}products`");
	$new_id = DB::insert(
		"INSERT INTO `{$p}products`
			(name, slug, sku, description, description_long, price, list_price,
			 stock, status, featured, free_shipping, display_order)
		 VALUES (?,?,?,?,?,?,?,?,0,?,?,?)",
		[
			$src['name'] . ' (Copy)', $slug, $src['sku'],
			$src['description'], $src['description_long'],
			$src['price'], $src['list_price'], $src['stock'],
			0, // cloned product starts inactive
			$src['featured'], $src['free_shipping'], $max + 1,
		]
	);

	// Copy categories
	$cats = DB::rows("SELECT category_id FROM `{$p}categories_products` WHERE product_id=?", [$id]);
	foreach ($cats as $cat) {
		DB::exec(
			"INSERT IGNORE INTO `{$p}categories_products` (category_id, product_id) VALUES (?,?)",
			[$cat['category_id'], $new_id]
		);
	}

	$row = DB::row("
		SELECT p.*, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS categories
		FROM `{$p}products` p
		LEFT JOIN `{$p}categories_products` cp ON cp.product_id = p.id
		LEFT JOIN `{$p}categories` c ON c.id = cp.category_id
		WHERE p.id = ?
		GROUP BY p.id
	", [$new_id]);

	out(true, 'Product cloned.', ['row' => $row]);
}

// ── Upload image ──────────────────────────────────────────────────────────────
if ($action === 'upload_image') {
	require_access(ACCESS_EDIT);
	$product_id = (int)post('product_id');
	$is_primary  = (int)(bool)post('is_primary');
	if (!$product_id) out(false, 'Product ID required.');
	if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
		out(false, 'No image uploaded.');
	}

	$allowed = ['image/jpeg', 'image/png', 'image/webp'];
	$mime    = mime_content_type($_FILES['image']['tmp_name']);
	if (!in_array($mime, $allowed, true)) out(false, 'Only JPG, PNG and WebP are allowed.');

	$ext      = match($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' };
	$dest_dir = rtrim(DIR_ROOT, '/') . '/img/products/';
	if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);

	$filename = 'prod_' . $product_id . '_' . uniqid() . '.' . $ext;
	$dest     = $dest_dir . $filename;

	$src = match($mime) {
		'image/jpeg' => imagecreatefromjpeg($_FILES['image']['tmp_name']),
		'image/png'  => imagecreatefrompng($_FILES['image']['tmp_name']),
		'image/webp' => imagecreatefromwebp($_FILES['image']['tmp_name']),
	};
	if (!$src) out(false, 'Could not read image.');

	// EXIF rotation fix
	if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
		$exif        = @exif_read_data($_FILES['image']['tmp_name']);
		$orientation = $exif['Orientation'] ?? 1;
		$src         = match($orientation) {
			3 => imagerotate($src, 180, 0),
			6 => imagerotate($src,  -90, 0),
			8 => imagerotate($src,   90, 0),
			default => $src,
		};
	}

	// Resize using saved settings (img_resize_on_upload, img_orig_max, img_product_quality)
	$_img_rows = DB::rows("SELECT `key`,`value` FROM `{$p}settings` WHERE `key` IN ('img_resize_on_upload','img_orig_max','img_product_quality')");
	$_img_cfg  = [];
	foreach ($_img_rows as $_r) $_img_cfg[$_r['key']] = $_r['value'];
	$do_resize   = (bool)(int)($_img_cfg['img_resize_on_upload'] ?? 1);
	$max_px      = max(200, (int)($_img_cfg['img_orig_max']        ?? 1200));
	$quality     = max(1, min(100, (int)($_img_cfg['img_product_quality'] ?? 85)));
	$png_quality = (int)round((100 - $quality) / 10); // 0–9 (inverted)

	$srcW = imagesx($src); $srcH = imagesy($src);
	if ($do_resize && $srcW > $max_px) {
		$newH    = (int)($srcH * $max_px / $srcW);
		$resized = imagecreatetruecolor($max_px, $newH);
		imagecopyresampled($resized, $src, 0, 0, 0, 0, $max_px, $newH, $srcW, $srcH);
		imagedestroy($src); $src = $resized;
	}

	$saved = match($mime) {
		'image/jpeg' => imagejpeg($src, $dest, $quality),
		'image/png'  => imagepng($src, $dest, $png_quality),
		'image/webp' => imagewebp($src, $dest, $quality),
	};
	imagedestroy($src);
	if (!$saved) out(false, 'Could not save image.');

	// If primary, clear other primaries
	if ($is_primary) {
		DB::exec("UPDATE `{$p}product_images` SET is_primary=0 WHERE product_id=?", [$product_id]);
	}

	$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}product_images` WHERE product_id=?", [$product_id]);
	$img_id = DB::insert(
		"INSERT INTO `{$p}product_images` (product_id, filename, is_primary, display_order) VALUES (?,?,?,?)",
		[$product_id, '/img/products/' . $filename, $is_primary, $max + 1]
	);

	out(true, 'Image uploaded.', ['image' => [
		'id'        => $img_id,
		'filename'  => '/img/products/' . $filename,
		'is_primary'=> $is_primary,
	]]);
}

// ── Save stock (inline edit) ──────────────────────────────────────────────────
if ($action === 'save_stock') {
	require_access(ACCESS_EDIT);
	$id    = (int)post('id');
	$stock = max(0, (int)post('stock'));
	DB::exec("UPDATE `{$p}products` SET stock=? WHERE id=?", [$stock, $id]);
	out(true, '');
}

// ── Save prices (inline edit) ─────────────────────────────────────────────────
if ($action === 'save_prices') {
	require_access(ACCESS_PRICING);
	$id         = (int)post('id');
	$price      = (float)post('price');
	$list_price = (float)post('list_price');
	if ($price < 0) out(false, 'Price cannot be negative.');
	DB::exec(
		"UPDATE `{$p}products` SET price=?, list_price=? WHERE id=?",
		[$price, $list_price, $id]
	);
	out(true, '');
}

// ── Quick-add category ────────────────────────────────────────────────────────
if ($action === 'quick_add_category') {
	$name = trim(post('name'));
	if (!$name) out(false, 'Category name is required.');

	$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
	$existing = DB::row("SELECT id FROM `{$p}categories` WHERE slug=?", [$slug]);
	if ($existing) $slug .= '-' . time();

	$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}categories`");
	$id  = DB::insert(
		"INSERT INTO `{$p}categories` (name, slug, status, display_order) VALUES (?, ?, 1, ?)",
		[$name, $slug, $max + 1]
	);

	// Mark as incomplete — needs full details
	reminder_add(
		'category', $id, $name,
		'Category "' . $name . '" was quick-added and needs its full details set.'
	);

	out(true, 'Category added.', ['category' => ['id' => $id, 'name' => $name]]);
}

// ── Category options ──────────────────────────────────────────────────────────
if ($action === 'categories') {
	$cats = DB::rows(
		"SELECT id, name, parent_id FROM `{$p}categories`
		 WHERE status > 0 ORDER BY display_order ASC, name ASC"
	);
	out(true, '', ['categories' => $cats]);
}

// ── List product options ───────────────────────────────────────────────────────
if ($action === 'list_options') {
	$product_id = (int)post('product_id');
	$pos = DB::rows(
		"SELECT po.*, o.name AS option_name, o.type, o.placeholder
		 FROM `{$p}product_options` po
		 JOIN `{$p}options` o ON o.id = po.option_id
		 WHERE po.product_id = ?
		 ORDER BY po.display_order ASC",
		[$product_id]
	);
	foreach ($pos as &$po) {
		$po['values'] = DB::rows(
			"SELECT pov.*, ov.text AS value_text, ov.image
			 FROM `{$p}product_option_values` pov
			 JOIN `{$p}option_values` ov ON ov.id = pov.option_value_id
			 WHERE pov.product_option_id = ?
			 ORDER BY ov.display_order ASC",
			[$po['id']]
		);
	}
	unset($po);
	out(true, '', ['product_options' => $pos]);
}

// ── Add option to product ──────────────────────────────────────────────────────
if ($action === 'add_option') {
	require_access(ACCESS_EDIT);
	$product_id = (int)post('product_id');
	$option_id  = (int)post('option_id');

	// Prevent duplicates
	$exists = DB::val(
		"SELECT id FROM `{$p}product_options` WHERE product_id=? AND option_id=?",
		[$product_id, $option_id]
	);
	if ($exists) out(false, 'This option is already attached to the product.');

	$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}product_options` WHERE product_id=?", [$product_id]);
	$po_id = DB::insert(
		"INSERT INTO `{$p}product_options` (product_id, option_id, label, required, display_order)
		 VALUES (?,?,'',0,?)",
		[$product_id, $option_id, $max + 1]
	);

	// Populate product_option_values from option_values
	$values = DB::rows(
		"SELECT * FROM `{$p}option_values` WHERE option_id=? ORDER BY display_order ASC",
		[$option_id]
	);
	foreach ($values as $v) {
		DB::exec(
			"INSERT INTO `{$p}product_option_values`
			 (product_option_id, option_value_id, label, price_modifier, price_prefix,
			  weight_modifier, weight_prefix, stock, subtract_stock, enabled)
			 VALUES (?,?,'',0.00,'+',0.0000,'+',0,0,1)",
			[$po_id, $v['id']]
		);
	}

	// Return full option with values
	$po = DB::row(
		"SELECT po.*, o.name AS option_name, o.type, o.placeholder
		 FROM `{$p}product_options` po
		 JOIN `{$p}options` o ON o.id = po.option_id
		 WHERE po.id = ?",
		[$po_id]
	);
	$po['values'] = DB::rows(
		"SELECT pov.*, ov.text AS value_text, ov.image
		 FROM `{$p}product_option_values` pov
		 JOIN `{$p}option_values` ov ON ov.id = pov.option_value_id
		 WHERE pov.product_option_id = ?
		 ORDER BY ov.display_order ASC",
		[$po_id]
	);
	out(true, '', ['product_option' => $po]);
}

// ── Save product option (label, required) ─────────────────────────────────────
if ($action === 'save_option') {
	require_access(ACCESS_EDIT);
	$po_id    = (int)post('po_id');
	$label    = trim(post('label'));
	$required = (int)post('required');
	DB::exec(
		"UPDATE `{$p}product_options` SET label=?, required=? WHERE id=?",
		[$label, $required, $po_id]
	);
	out(true, '');
}

// ── Remove option from product ────────────────────────────────────────────────
if ($action === 'remove_option') {
	require_access(ACCESS_DELETE);
	$po_id = (int)post('po_id');
	DB::exec("DELETE FROM `{$p}product_option_values` WHERE product_option_id=?", [$po_id]);
	DB::exec("DELETE FROM `{$p}product_options` WHERE id=?", [$po_id]);
	out(true, '');
}

// ── Save product option value override ────────────────────────────────────────
if ($action === 'save_option_value') {
	require_access(ACCESS_EDIT);
	$pov_id        = (int)post('pov_id');
	$label         = trim(post('label'));
	$price_prefix  = post('price_prefix') === '-' ? '-' : '+';
	$price_mod     = (float)post('price_modifier');
	$weight_prefix = post('weight_prefix') === '-' ? '-' : '+';
	$weight_mod    = (float)post('weight_modifier');
	$stock         = (int)post('stock');
	$subtract      = (int)post('subtract_stock');
	$enabled       = (int)post('enabled');
	DB::exec(
		"UPDATE `{$p}product_option_values`
		 SET label=?, price_prefix=?, price_modifier=?,
		     weight_prefix=?, weight_modifier=?,
		     stock=?, subtract_stock=?, enabled=?
		 WHERE id=?",
		[$label, $price_prefix, $price_mod, $weight_prefix, $weight_mod,
		 $stock, $subtract, $enabled, $pov_id]
	);
	out(true, '');
}

// ── Search products (for related-products autocomplete) ──────────────────────
if ($action === 'search_products') {
	$q          = trim(post('q'));
	$product_id = (int)post('product_id');
	$params     = [];
	$where      = 'WHERE 1';
	if ($product_id) {
		$where   .= ' AND p.id != ?';
		$params[] = $product_id;
	}
	if ($q !== '') {
		$like     = '%' . $q . '%';
		$where   .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
		$params[] = $like;
		$params[] = $like;
	}
	$rows = DB::rows(
		"SELECT p.id, p.name, p.sku FROM `{$p}products` p $where ORDER BY p.name ASC LIMIT 30",
		$params
	);
	out(true, '', ['rows' => $rows]);
}

// ── List related products ─────────────────────────────────────────────────────
if ($action === 'list_related') {
	$product_id = (int)post('product_id');
	$rows = DB::rows(
		"SELECT p.id, p.name, p.sku
		 FROM `{$p}product_related` pr
		 JOIN `{$p}products` p ON p.id = pr.related_product_id
		 WHERE pr.product_id = ?
		 ORDER BY p.name ASC",
		[$product_id]
	);
	out(true, '', ['rows' => $rows]);
}

// ── Add related product ───────────────────────────────────────────────────────
if ($action === 'add_related') {
	require_access(ACCESS_EDIT);
	$product_id         = (int)post('product_id');
	$related_product_id = (int)post('related_product_id');
	if (!$product_id || !$related_product_id || $product_id === $related_product_id) {
		out(false, 'Invalid products.');
	}
	DB::exec(
		"INSERT IGNORE INTO `{$p}product_related` (product_id, related_product_id) VALUES (?, ?)",
		[$product_id, $related_product_id]
	);
	$related = DB::row("SELECT id, name, sku FROM `{$p}products` WHERE id = ?", [$related_product_id]);
	out(true, '', ['row' => $related]);
}

// ── Remove related product ────────────────────────────────────────────────────
if ($action === 'remove_related') {
	require_access(ACCESS_EDIT);
	$product_id         = (int)post('product_id');
	$related_product_id = (int)post('related_product_id');
	DB::exec(
		"DELETE FROM `{$p}product_related` WHERE product_id = ? AND related_product_id = ?",
		[$product_id, $related_product_id]
	);
	out(true, '');
}

// ── Search options (for autocomplete) ────────────────────────────────────────
if ($action === 'search_options') {
	$q = '%' . trim(post('q')) . '%';
	$rows = DB::rows(
		"SELECT id, name, type FROM `{$p}options` WHERE name LIKE ? ORDER BY name ASC LIMIT 20",
		[$q]
	);
	out(true, '', ['rows' => $rows]);
}

out(false, 'Unknown action.');
