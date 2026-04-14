<?php
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
	$rows = DB::rows("SELECT * FROM `{$p}pages` ORDER BY display_order ASC, title ASC");
	out(true, '', ['rows' => $rows]);
}

// ── Get single page with blocks ───────────────────────────────────────────────
if ($action === 'get') {
	$id   = (int)post('id');
	$page = DB::row("SELECT * FROM `{$p}pages` WHERE id=?", [$id]);
	if (!$page) out(false, 'Page not found.');
	$blocks = DB::rows(
		"SELECT * FROM `{$p}page_blocks` WHERE page_id=? ORDER BY display_order ASC",
		[$id]
	);
	foreach ($blocks as &$b) {
		$decoded = $b['settings'] ? json_decode($b['settings'], true) : [];
		$b['settings'] = is_array($decoded) ? (object)$decoded : (object)[];
	}
	unset($b);
	out(true, '', ['page' => $page, 'blocks' => $blocks]);
}

// ── Save page ─────────────────────────────────────────────────────────────────
if ($action === 'save') {
	require_access(ACCESS_EDIT);
	$id     = (int)post('id');
	$title  = trim(post('title'));
	$slug   = trim(post('slug'));
	$status = (int)post('status', 1);
	$seo_t  = trim(post('seo_title'));
	$seo_k  = trim(post('seo_keywords'));
	$seo_d  = trim(post('seo_description'));

	if (!$title) out(false, 'Title is required.');

	// Auto-slug
	if (!$slug) {
		$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
	}

	// Ensure unique slug
	$existing = DB::val(
		"SELECT id FROM `{$p}pages` WHERE slug=? AND id!=?",
		[$slug, $id]
	);
	if ($existing) {
		$slug .= '-' . ($id ?: time());
	}

	if ($id) {
		DB::exec(
			"UPDATE `{$p}pages` SET title=?,slug=?,status=?,seo_title=?,seo_keywords=?,seo_description=? WHERE id=?",
			[$title, $slug, $status, $seo_t, $seo_k, $seo_d, $id]
		);
	} else {
		require_access(ACCESS_ADD);
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}pages`");
		$id  = DB::insert(
			"INSERT INTO `{$p}pages` (title,slug,status,seo_title,seo_keywords,seo_description,display_order)
			 VALUES (?,?,?,?,?,?,?)",
			[$title, $slug, $status, $seo_t, $seo_k, $seo_d, $max + 1]
		);
	}
	$page = DB::row("SELECT * FROM `{$p}pages` WHERE id=?", [$id]);
	out(true, 'Page saved.', ['page' => $page]);
}

// ── Delete page ───────────────────────────────────────────────────────────────
if ($action === 'delete') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}page_blocks` WHERE page_id=?", [$id]);
	DB::exec("DELETE FROM `{$p}pages` WHERE id=?", [$id]);
	out(true, 'Page deleted.');
}

// ── Reorder pages ─────────────────────────────────────────────────────────────
if ($action === 'reorder') {
	$ids = json_decode(post('ids'), true);
	foreach ($ids as $i => $pid) {
		DB::exec("UPDATE `{$p}pages` SET display_order=? WHERE id=?", [$i, (int)$pid]);
	}
	out(true, '');
}

// ── Save block ────────────────────────────────────────────────────────────────
if ($action === 'save_block') {
	require_access(ACCESS_EDIT);
	$id       = (int)post('id');
	$page_id  = (int)post('page_id');
	$type     = trim(post('block_type'));
	$settings = post('settings', '{}');
	$enabled  = (int)post('enabled', 1);
	$cols     = max(1, min(4, (int)post('cols', 4)));

	// Validate settings JSON — always return object, never array
	$decoded = json_decode($settings, true);
	if (!is_array($decoded) || array_keys($decoded) === range(0, count($decoded)-1)) {
		// Empty array [] or non-array — force to empty object
		$decoded = is_array($decoded) && count($decoded) > 0 ? $decoded : [];
	}
	$settings = json_encode((object)$decoded);

	if ($id) {
		DB::exec(
			"UPDATE `{$p}page_blocks` SET block_type=?,settings=?,enabled=?,cols=? WHERE id=?",
			[$type, $settings, $enabled, $cols, $id]
		);
	} else {
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}page_blocks` WHERE page_id=?", [$page_id]);
		$id  = DB::insert(
			"INSERT INTO `{$p}page_blocks` (page_id,block_type,settings,display_order,enabled,cols)
			 VALUES (?,?,?,?,?,?)",
			[$page_id, $type, $settings, $max + 1, $enabled, $cols]
		);
	}
	$block = DB::row("SELECT * FROM `{$p}page_blocks` WHERE id=?", [$id]);
	$decoded = $block['settings'] ? json_decode($block['settings'], true) : [];
	$block['settings'] = is_array($decoded) ? (object)$decoded : (object)[];
	out(true, '', ['block' => $block]);
}

// ── Delete block ──────────────────────────────────────────────────────────────
if ($action === 'delete_block') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}page_blocks` WHERE id=?", [$id]);
	out(true, '');
}

// ── Reorder blocks ────────────────────────────────────────────────────────────
if ($action === 'reorder_blocks') {
	$ids = json_decode(post('ids'), true);
	foreach ($ids as $i => $bid) {
		DB::exec("UPDATE `{$p}page_blocks` SET display_order=? WHERE id=?", [$i, (int)$bid]);
	}
	out(true, '');
}

// ── Get available block data (for pickers) ────────────────────────────────────
if ($action === 'block_data') {
	$p     = DB_PREFIX;
	$type  = post('type');
	$data  = [];

	if ($type === 'slideshow') {
		$data['slideshows'] = DB::rows("SELECT id,name FROM `{$p}slideshows` WHERE status=1 ORDER BY name ASC");
	} elseif (in_array($type, ['featured_products','best_sellers','new_arrivals','best_sellers_category'])) {
		$data['categories'] = DB::rows("SELECT id,name FROM `{$p}categories` WHERE status>0 ORDER BY name ASC");
	} elseif ($type === 'contact_form') {
		$data['forms'] = DB::rows("SELECT id,name FROM `{$p}contact_forms` ORDER BY name ASC");
	}

	out(true, '', ['data' => $data]);
}

// ── Generate sitemap block content ────────────────────────────────────────────
if ($action === 'generate_sitemap') {
	require_access(ACCESS_EDIT);
	$page_id  = (int)post('page_id');
	if (!$page_id) out(false, 'Page not found.');

	// Find or create the sitemap block on this page
	$block = DB::row(
		"SELECT * FROM `{$p}page_blocks` WHERE page_id=? AND block_type='sitemap' LIMIT 1",
		[$page_id]
	);
	if (!$block) {
		$bid = DB::insert(
			"INSERT INTO `{$p}page_blocks` (page_id, block_type, settings, display_order, enabled)
			 VALUES (?, 'sitemap', '{}', 0, 1)",
			[$page_id]
		);
		$block = DB::row("SELECT * FROM `{$p}page_blocks` WHERE id=?", [$bid]);
	}

	// Build HTML sitemap content and store in block settings
	$pages   = DB::rows("SELECT title, slug FROM `{$p}pages` WHERE status=1 AND slug != 'home' ORDER BY display_order ASC");
	$cats    = DB::rows("SELECT name, slug FROM `{$p}categories` WHERE status>0 ORDER BY display_order ASC, name ASC");
	$prods   = DB::rows("SELECT name, slug FROM `{$p}products` WHERE status>0 ORDER BY display_order ASC, name ASC");

	$html = '<div class="sitemap-generated">';
	if ($pages) {
		$html .= '<h3>Pages</h3><ul>';
		foreach ($pages as $pg) {
			$url   = URL_ROOT . 'page/' . htmlspecialchars($pg['slug']);
			$html .= '<li><a href="' . $url . '">' . htmlspecialchars($pg['title']) . '</a></li>';
		}
		$html .= '</ul>';
	}
	if ($cats) {
		$html .= '<h3>Categories</h3><ul>';
		foreach ($cats as $cat) {
			$url   = URL_ROOT . 'category/' . htmlspecialchars($cat['slug']);
			$html .= '<li><a href="' . $url . '">' . htmlspecialchars($cat['name']) . '</a></li>';
		}
		$html .= '</ul>';
	}
	if ($prods) {
		$html .= '<h3>Products</h3><ul>';
		foreach ($prods as $prod) {
			$url   = URL_ROOT . 'product/' . htmlspecialchars($prod['slug']);
			$html .= '<li><a href="' . $url . '">' . htmlspecialchars($prod['name']) . '</a></li>';
		}
		$html .= '</ul>';
	}
	$html .= '</div>';

	$s = $block['settings'] ? json_decode($block['settings'], true) : [];
	$s['generated_html'] = $html;
	$s['generated_at']   = date('Y-m-d H:i:s');

	DB::exec(
		"UPDATE `{$p}page_blocks` SET settings=? WHERE id=?",
		[json_encode($s), $block['id']]
	);

	// Also regenerate sitemap-auto.xml for search engines
	$base     = rtrim(URL_ROOT, '/');
	$xml      = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml     .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	$xml     .= "<url><loc>{$base}/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
	foreach ($pages as $pg) {
		$loc  = $base . '/page/' . rawurlencode($pg['slug']);
		$xml .= "<url><loc>{$loc}</loc><changefreq>monthly</changefreq><priority>0.6</priority></url>\n";
	}
	foreach ($cats as $cat) {
		$loc  = $base . '/category/' . rawurlencode($cat['slug']);
		$xml .= "<url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>0.7</priority></url>\n";
	}
	foreach ($prods as $prod) {
		$loc  = $base . '/product/' . rawurlencode($prod['slug']);
		$xml .= "<url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
	}
	$xml .= '</urlset>';
	@file_put_contents(DIR_ROOT . 'sitemap-auto.xml', $xml);

	out(true, 'Site map generated.', ['block_id' => $block['id']]);
}

// ── Preview token ─────────────────────────────────────────────────────────────
if ($action === 'preview_token') {
	$id    = (int)post('id');
	$page  = DB::row("SELECT id FROM `{$p}pages` WHERE id=?", [$id]);
	if (!$page) out(false, 'Page not found.');
	$token = bin2hex(random_bytes(16));
	// Store token in settings table with 30min expiry packed as JSON
	$key   = 'preview_' . $token;
	$val   = json_encode(['page_id' => $id, 'expires' => time() + 1800]);
	DB::exec("INSERT INTO `{$p}settings` (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?",
		[$key, $val, $val]);
	out(true, '', ['token' => $token]);
}

// ── Sitemap XML upload ────────────────────────────────────────────────────────
if ($action === 'upload_sitemap_xml') {
	require_access(ACCESS_EDIT);
	$block_id = (int)post('block_id');
	if (empty($_FILES['xml']['tmp_name'])) out(false, 'No file received.');
	$dest = DIR_ROOT . 'sitemap-custom.xml';
	if (!@move_uploaded_file($_FILES['xml']['tmp_name'], $dest)) {
		out(false, 'Could not save file. Check directory permissions.');
	}
	// Update block settings
	$block = DB::row("SELECT * FROM `{$p}page_blocks` WHERE id=?", [$block_id]);
	if ($block) {
		$s = $block['settings'] ? json_decode($block['settings'], true) : [];
		$s['custom_xml_path'] = 'sitemap-custom.xml';
		DB::exec("UPDATE `{$p}page_blocks` SET settings=? WHERE id=?",
			[json_encode($s), $block_id]);
	}
	out(true, 'Custom sitemap uploaded.');
}

// ── Clear custom sitemap ──────────────────────────────────────────────────────
if ($action === 'clear_sitemap_xml') {
	require_access(ACCESS_EDIT);
	$block_id = (int)post('block_id');
	@unlink(DIR_ROOT . 'sitemap-custom.xml');
	$block = DB::row("SELECT * FROM `{$p}page_blocks` WHERE id=?", [$block_id]);
	if ($block) {
		$s = $block['settings'] ? json_decode($block['settings'], true) : [];
		unset($s['custom_xml_path']);
		DB::exec("UPDATE `{$p}page_blocks` SET settings=? WHERE id=?",
			[json_encode($s), $block_id]);
	}
	out(true, '');
}

out(false, 'Unknown action.');
