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

	$id           = (int)post('id');
	$name         = trim(post('name'));
	$sku          = trim(post('sku'));
	$price        = (float)post('price');
	$list_price   = (float)post('list_price');
	$stock        = (int)post('stock');
	$status       = (int)post('status');
	$featured     = (int)(bool)post('featured');
	$free_ship    = (int)(bool)post('free_shipping');
	$desc_short   = trim(post('description'));
	$desc_long    = post('description_long');
	$cat_ids      = json_decode(post('category_ids') ?: '[]', true);
	$cat_ids      = array_map('intval', (array)$cat_ids);

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
		           $status, $featured, $free_ship, $desc_short, $desc_long, $id];
		DB::exec("UPDATE `{$p}products` SET
			name=?, slug=?, sku=?, price=?, list_price=?, stock=?,
			status=?, featured=?, free_shipping=?, description=?, description_long=?
			WHERE id=?", $params);
	} else {
		require_access(ACCESS_ADD);
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}products`");
		$id  = DB::insert("INSERT INTO `{$p}products`
			(name, slug, sku, price, list_price, stock, status, featured,
			 free_shipping, description, description_long, display_order)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			[$name, $slug, $sku, $price, $list_price, $stock, $status, $featured,
			 $free_ship, $desc_short, $desc_long, $max + 1]
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
	out(true, count($ids) . ' product' . (count($ids) === 1 ? '' : 's') . ' deleted.');
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

	// Max 1200px wide
	$srcW = imagesx($src); $srcH = imagesy($src);
	if ($srcW > 1200) {
		$newH   = (int)($srcH * 1200 / $srcW);
		$resized = imagecreatetruecolor(1200, $newH);
		imagecopyresampled($resized, $src, 0, 0, 0, 0, 1200, $newH, $srcW, $srcH);
		imagedestroy($src); $src = $resized;
	}

	$saved = match($mime) {
		'image/jpeg' => imagejpeg($src, $dest, 85),
		'image/png'  => imagepng($src, $dest, 6),
		'image/webp' => imagewebp($src, $dest, 85),
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

out(false, 'Unknown action.');
