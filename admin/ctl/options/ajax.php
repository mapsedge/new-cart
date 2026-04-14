<?php
/**
 * new-cart admin — options ajax
 * route=options/ajax
 */

require_admin();
header('Content-Type: application/json');

function out(bool $ok, string $msg = '', array $extra = []): never {
	echo json_encode(['ok' => $ok, 'message' => $msg] + $extra);
	exit;
}

$p      = DB_PREFIX;
$action = post('action');

// ── List ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
	$rows = DB::rows("
		SELECT o.*,
		       COUNT(v.id) AS value_count
		FROM `{$p}options` o
		LEFT JOIN `{$p}option_values` v ON v.option_id = o.id
		GROUP BY o.id
		ORDER BY o.display_order ASC, o.name ASC
	");
	out(true, '', ['rows' => $rows]);
}

// ── Get single (with values) ──────────────────────────────────────────────────
if ($action === 'get') {
	$id  = (int)post('id');
	$row = DB::row("SELECT * FROM `{$p}options` WHERE id = ?", [$id]);
	if (!$row) out(false, 'Option not found.');
	$values = DB::rows(
		"SELECT * FROM `{$p}option_values` WHERE option_id = ? ORDER BY display_order ASC, id ASC",
		[$id]
	);
	out(true, '', ['row' => $row, 'values' => $values]);
}

// ── Save option ───────────────────────────────────────────────────────────────
if ($action === 'save') {
	require_access(ACCESS_EDIT);
	$id          = (int)post('id');
	$name        = trim(post('name'));
	$type        = trim(post('type'));
	$placeholder = trim(post('placeholder'));

	$allowed_types = ['select','radio','checkbox','toggle','text','textarea','file','date','time','datetime'];
	if (!in_array($type, $allowed_types)) $type = 'select';
	if (!$name) out(false, 'Option name is required.');

	if ($id) {
		DB::exec(
			"UPDATE `{$p}options` SET name=?, type=?, placeholder=? WHERE id=?",
			[$name, $type, $placeholder, $id]
		);
	} else {
		require_access(ACCESS_ADD);
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}options`");
		$id  = DB::insert(
			"INSERT INTO `{$p}options` (name, type, placeholder, display_order) VALUES (?,?,?,?)",
			[$name, $type, $placeholder, $max + 1]
		);
	}
	$row = DB::row("SELECT * FROM `{$p}options` WHERE id = ?", [$id]);
	out(true, 'Option saved.', ['row' => $row]);
}

// ── Delete option ─────────────────────────────────────────────────────────────
if ($action === 'delete') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}option_values` WHERE option_id = ?",          [$id]);
	DB::exec("DELETE FROM `{$p}options` WHERE id = ?",                        [$id]);
	// Cascade: remove product option associations
	$po_ids = array_column(
		DB::rows("SELECT id FROM `{$p}product_options` WHERE option_id = ?", [$id]),
		'id'
	);
	if ($po_ids) {
		$ph = implode(',', array_fill(0, count($po_ids), '?'));
		DB::exec("DELETE FROM `{$p}product_option_values` WHERE product_option_id IN ({$ph})", $po_ids);
	}
	DB::exec("DELETE FROM `{$p}product_options` WHERE option_id = ?", [$id]);
	out(true, 'Option deleted.');
}

// ── Reorder options ───────────────────────────────────────────────────────────
if ($action === 'reorder') {
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids)) out(false, 'Invalid order data.');
	foreach ($ids as $order => $oid) {
		DB::exec("UPDATE `{$p}options` SET display_order=? WHERE id=?", [$order, (int)$oid]);
	}
	out(true, '');
}

// ── Save option value ─────────────────────────────────────────────────────────
if ($action === 'save_value') {
	require_access(ACCESS_EDIT);
	$id        = (int)post('id');
	$option_id = (int)post('option_id');
	$text      = trim(post('text'));
	$image     = trim(post('image'));

	if (!$text) out(false, 'Value text is required.');

	if ($id) {
		DB::exec(
			"UPDATE `{$p}option_values` SET text=?, image=? WHERE id=?",
			[$text, $image, $id]
		);
	} else {
		require_access(ACCESS_ADD);
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}option_values` WHERE option_id=?", [$option_id]);
		$id  = DB::insert(
			"INSERT INTO `{$p}option_values` (option_id, text, image, display_order) VALUES (?,?,?,?)",
			[$option_id, $text, $image, $max + 1]
		);
	}
	$row = DB::row("SELECT * FROM `{$p}option_values` WHERE id = ?", [$id]);
	out(true, '', ['row' => $row]);
}

// ── Delete option value ───────────────────────────────────────────────────────
if ($action === 'delete_value') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}product_option_values` WHERE option_value_id = ?", [$id]);
	DB::exec("DELETE FROM `{$p}option_values` WHERE id = ?",                       [$id]);
	out(true, '');
}

// ── Reorder option values ─────────────────────────────────────────────────────
if ($action === 'reorder_values') {
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids)) out(false, 'Invalid order data.');
	foreach ($ids as $order => $vid) {
		DB::exec("UPDATE `{$p}option_values` SET display_order=? WHERE id=?", [$order, (int)$vid]);
	}
	out(true, '');
}

// ── Upload value image ────────────────────────────────────────────────────────
if ($action === 'upload_value_image') {
	require_access(ACCESS_ADD);
	if (empty($_FILES['image']['tmp_name'])) out(false, 'No file received.');

	$ext     = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
	$allowed = ['jpg','jpeg','png','gif','webp'];
	if (!in_array($ext, $allowed)) out(false, 'File type not allowed.');

	$dir = DIR_IMG . 'options/';
	if (!is_dir($dir)) @mkdir($dir, 0775, true);

	$name = bin2hex(random_bytes(8)) . '.webp';
	$dest = $dir . $name;

	// Resize to max 600px
	$info = @getimagesize($_FILES['image']['tmp_name']);
	if ($info) {
		[$w, $h, $type] = [$info[0], $info[1], $info[2]];
		$src = match($type) {
			IMAGETYPE_JPEG => imagecreatefromjpeg($_FILES['image']['tmp_name']),
			IMAGETYPE_PNG  => imagecreatefrompng($_FILES['image']['tmp_name']),
			IMAGETYPE_WEBP => imagecreatefromwebp($_FILES['image']['tmp_name']),
			default        => null,
		};
		if ($src) {
			$ratio = min(600 / $w, 600 / $h, 1.0);
			$dw = (int)round($w * $ratio);
			$dh = (int)round($h * $ratio);
			$dst = imagecreatetruecolor($dw, $dh);
			imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
			imagewebp($dst, $dest, 85);
			imagedestroy($src);
			imagedestroy($dst);
		}
	}
	if (!file_exists($dest)) {
		if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
			out(false, 'Could not save image. Make sure the img/options folder is writeable.');
		}
	}

	out(true, '', ['url' => '/img/options/' . $name, 'path' => '/img/options/' . $name]);
}

// ── Options list for product autocomplete ─────────────────────────────────────
if ($action === 'search') {
	$q    = '%' . trim(post('q')) . '%';
	$rows = DB::rows(
		"SELECT id, name, type FROM `{$p}options` WHERE name LIKE ? ORDER BY name ASC LIMIT 20",
		[$q]
	);
	out(true, '', ['rows' => $rows]);
}

out(false, 'Unknown action.');
