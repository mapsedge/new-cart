<?php
/**
 * new-cart admin — categories ajax handler
 * route=categories/ajax
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
		SELECT c.*, p.name AS parent_name
		FROM `{$p}categories` c
		LEFT JOIN `{$p}categories` p ON p.id = c.parent_id
		ORDER BY c.display_order ASC, c.name ASC
	");
	out(true, '', ['rows' => $rows]);
}

// ── Save (insert or update) ────────────────────────────────────────────────────
if ($action === 'save') {
	$id              = (int)post('id');
	$name            = trim(post('name'));
	$parent_id       = (int)post('parent_id');
	$seo_title       = trim(post('seo_title'));
	$seo_keywords    = trim(post('seo_keywords'));
	$seo_description = trim(post('seo_description'));
	$html_long       = post('html_long');
	$featured        = (int)(bool)post('featured');
	$status          = (int)post('status'); // 0=Not Active, 1=Active, 2=Browse Only

	if (!in_array($status, [0, 1, 2])) $status = 1;
	if (!$name) out(false, 'Category name is required.');

	$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
	$existing = DB::row(
		"SELECT id FROM `{$p}categories` WHERE slug = ? AND id != ?",
		[$slug, $id]
	);
	if ($existing) $slug .= '-' . ($id ?: time());

	if ($id) {
		DB::exec("UPDATE `{$p}categories` SET
			name             = ?,
			parent_id        = ?,
			slug             = ?,
			seo_title        = ?,
			seo_keywords     = ?,
			seo_description  = ?,
			html_long        = ?,
			featured         = ?,
			status           = ?
			WHERE id = ?",
			[$name, $parent_id, $slug, $seo_title, $seo_keywords, $seo_description, $html_long, $featured, $status, $id]
		);
	} else {
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}categories`");
		$id  = DB::insert("INSERT INTO `{$p}categories`
			(name, parent_id, slug, seo_title, seo_keywords, seo_description, html_long, featured, status, display_order)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			[$name, $parent_id, $slug, $seo_title, $seo_keywords, $seo_description, $html_long, $featured, $status, $max + 1]
		);
	}

	$row = DB::row("
		SELECT c.*, p.name AS parent_name
		FROM `{$p}categories` c
		LEFT JOIN `{$p}categories` p ON p.id = c.parent_id
		WHERE c.id = ?
	", [$id]);

	// Clear any incomplete reminder for this category
	reminder_clear('category', $id);

	out(true, 'Category saved.', ['row' => $row, 'cleared_reminder' => $id]);
}

// ── Toggle field (status, featured, browse_only) ───────────────────────────────
if ($action === 'toggle') {
	$id    = (int)post('id');
	$field = post('field');
	$value = (int)post('value');

	$allowed = ['status', 'featured'];
	if (!in_array($field, $allowed)) out(false, 'Invalid field.');

	DB::exec("UPDATE `{$p}categories` SET `{$field}` = ? WHERE id = ?", [$value, $id]);
	out(true, '');
}

// ── Reorder (drag-drop) ────────────────────────────────────────────────────────
if ($action === 'reorder') {
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids)) out(false, 'Invalid order data.');

	foreach ($ids as $order => $id) {
		DB::exec("UPDATE `{$p}categories` SET display_order = ? WHERE id = ?", [$order, (int)$id]);
	}
	out(true, '');
}

// ── Delete single ──────────────────────────────────────────────────────────────
if ($action === 'delete') {
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}categories` WHERE id = ?", [$id]);
	DB::exec("DELETE FROM `{$p}categories_products` WHERE category_id = ?", [$id]);
	// Orphan child categories — set parent_id to 0
	DB::exec("UPDATE `{$p}categories` SET parent_id = 0 WHERE parent_id = ?", [$id]);
	out(true, 'Category deleted.');
}

// ── Bulk delete ────────────────────────────────────────────────────────────────
if ($action === 'bulk_delete') {
	$ids = json_decode(post('ids'), true);
	if (!is_array($ids) || empty($ids)) out(false, 'No categories selected.');
	$ids = array_map('intval', $ids);
	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	DB::exec("DELETE FROM `{$p}categories` WHERE id IN ({$placeholders})", $ids);
	DB::exec("DELETE FROM `{$p}categories_products` WHERE category_id IN ({$placeholders})", $ids);
	DB::exec("UPDATE `{$p}categories` SET parent_id = 0 WHERE parent_id IN ({$placeholders})", $ids);
	out(true, count($ids) . ' categor' . (count($ids) === 1 ? 'y' : 'ies') . ' deleted.');
}

// ── Get single (for drawer) ────────────────────────────────────────────────────
if ($action === 'get') {
	$id  = (int)post('id');
	$row = DB::row("SELECT * FROM `{$p}categories` WHERE id = ?", [$id]);
	if (!$row) out(false, 'Category not found.');

	// Parent options for select
	$parents = DB::rows("SELECT id, name FROM `{$p}categories` WHERE id != ? ORDER BY name ASC", [$id]);
	out(true, '', ['row' => $row, 'parents' => $parents]);
}

// ── Parent options (for new category drawer) ───────────────────────────────────
if ($action === 'parents') {
	$parents  = DB::rows("SELECT id, name FROM `{$p}categories` ORDER BY name ASC");
	$top_four = DB::rows(
		"SELECT name FROM `{$p}categories`
		 WHERE parent_id = 0 AND status > 0
		 ORDER BY display_order ASC, name ASC
		 LIMIT 4"
	);
	out(true, '', [
		'parents'   => $parents,
		'top_four'  => array_column($top_four, 'name'),
	]);
}

out(false, 'Unknown action.');
