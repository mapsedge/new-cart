<?php
require_admin();
header('Content-Type: application/json');
function out(bool $ok, string $msg='', array $extra=[]): never {
	echo json_encode(['ok'=>$ok,'message'=>$msg]+$extra); exit;
}

$p      = DB_PREFIX;
$action = post('action');

// ── List menus ────────────────────────────────────────────────────────────────
if ($action === 'list') {
	$rows = DB::rows("SELECT * FROM `{$p}menus` ORDER BY name ASC");
	out(true, '', ['rows' => $rows]);
}

// ── Get menu with items ───────────────────────────────────────────────────────
if ($action === 'get') {
	$id   = (int)post('id');
	$menu = DB::row("SELECT * FROM `{$p}menus` WHERE id=?", [$id]);
	if (!$menu) out(false, 'Menu not found.');
	$items = DB::rows(
		"SELECT mi.*, p.title AS page_title, p.slug AS page_slug,
		        c.name AS category_name, m.name AS submenu_name
		 FROM `{$p}menu_items` mi
		 LEFT JOIN `{$p}pages`      p ON p.id = mi.page_id
		 LEFT JOIN `{$p}categories` c ON c.id = mi.category_id
		 LEFT JOIN `{$p}menus`      m ON m.id = mi.submenu_id
		 WHERE mi.menu_id=?
		 ORDER BY mi.display_order ASC",
		[$id]
	);
	foreach ($items as &$it) {
		$it['settings'] = $it['settings'] ? (json_decode($it['settings'], true) ?: []) : [];
	}
	unset($it);
	out(true, '', ['menu' => $menu, 'items' => $items]);
}

// ── Save menu ─────────────────────────────────────────────────────────────────
if ($action === 'save_menu') {
	require_access(ACCESS_EDIT);
	$id        = (int)post('id');
	$name      = trim(post('name'));
	$menu_role = trim(post('menu_role'));
	$menu_type = trim(post('menu_type'));
	if (!in_array($menu_type, ['category_list','links_pages','related_products'])) $menu_type = 'links_pages';
	if (!$name) out(false, 'Name is required.');

	// Enforce one menu per role
	if ($menu_role) {
		DB::exec("UPDATE `{$p}menus` SET menu_role='' WHERE menu_role=? AND id!=?", [$menu_role, $id]);
	}

	$prev_type = '';
	if ($id) {
		$prev = DB::row("SELECT menu_type FROM `{$p}menus` WHERE id=?", [$id]);
		$prev_type = $prev['menu_type'] ?? '';
		DB::exec("UPDATE `{$p}menus` SET name=?,menu_role=?,menu_type=? WHERE id=?",
			[$name, $menu_role, $menu_type, $id]);
	} else {
		$id = DB::insert("INSERT INTO `{$p}menus` (name,menu_role,menu_type) VALUES (?,?,?)",
			[$name, $menu_role, $menu_type]);
	}

	// Wipe items when menu_type changes
	if ($prev_type && $prev_type !== $menu_type) {
		DB::exec("DELETE FROM `{$p}menu_items` WHERE menu_id=?", [$id]);
	}

	// Auto-create single item for category_list if no items exist
	if ($menu_type === 'category_list') {
		$count = (int)DB::val("SELECT COUNT(*) FROM `{$p}menu_items` WHERE menu_id=?", [$id]);
		if ($count === 0) {
			DB::insert(
				"INSERT INTO `{$p}menu_items` (menu_id,item_type,label,settings,display_order,enabled)
				 VALUES (?,?,?,?,0,1)",
				[$id, 'category_list', '', json_encode((object)[])]);
		}
	}

	$menu = DB::row("SELECT * FROM `{$p}menus` WHERE id=?", [$id]);
	out(true, 'Menu saved.', ['menu' => $menu, 'type_changed' => ($prev_type && $prev_type !== $menu_type)]);
}

// ── Delete menu ───────────────────────────────────────────────────────────────
if ($action === 'delete_menu') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}menu_items` WHERE menu_id=?", [$id]);
	DB::exec("DELETE FROM `{$p}menus` WHERE id=?", [$id]);
	out(true, '');
}

// ── Save item ─────────────────────────────────────────────────────────────────
if ($action === 'save_item') {
	require_access(ACCESS_EDIT);
	$id          = (int)post('id');
	$menu_id     = (int)post('menu_id');
	$label       = trim(post('label'));
	$item_type   = trim(post('item_type'));
	$url         = trim(post('url'));
	$page_id     = (int)post('page_id') ?: null;
	$target      = in_array(post('target'), ['_blank']) ? '_blank' : '';
	$js_code     = trim(post('js_code'));
	$enabled     = 1;

	// settings: excluded_cats for category_list
	$settings_raw = post('settings', '{}');
	$settings_dec = json_decode($settings_raw, true);
	if (!is_array($settings_dec)) $settings_dec = [];
	$settings = json_encode((object)$settings_dec);

	// For page items: label falls back to page title
	if ($item_type === 'page' && !$label && $page_id) {
		$pg = DB::row("SELECT title FROM `{$p}pages` WHERE id=?", [$page_id]);
		if ($pg) $label = $pg['title'];
	}

	if ($id) {
		DB::exec(
			"UPDATE `{$p}menu_items`
			 SET label=?,item_type=?,url=?,page_id=?,target=?,js_code=?,settings=?,enabled=?
			 WHERE id=?",
			[$label,$item_type,$url,$page_id,$target,$js_code,$settings,$enabled,$id]
		);
	} else {
		$max = (int)DB::val("SELECT MAX(display_order) FROM `{$p}menu_items` WHERE menu_id=?", [$menu_id]);
		$id  = DB::insert(
			"INSERT INTO `{$p}menu_items`
			 (menu_id,label,item_type,url,page_id,target,js_code,settings,display_order,enabled)
			 VALUES (?,?,?,?,?,?,?,?,?,1)",
			[$menu_id,$label,$item_type,$url,$page_id,$target,$js_code,$settings,$max+1]
		);
	}
	$item = DB::row("SELECT * FROM `{$p}menu_items` WHERE id=?", [$id]);
	$item['settings'] = $item['settings'] ? (json_decode($item['settings'], true) ?: []) : [];
	out(true, '', ['item' => $item]);
}

// ── Delete item ───────────────────────────────────────────────────────────────
if ($action === 'delete_item') {
	require_access(ACCESS_DELETE);
	$id = (int)post('id');
	DB::exec("DELETE FROM `{$p}menu_items` WHERE id=?", [$id]);
	out(true, '');
}

// ── Reorder items ─────────────────────────────────────────────────────────────
if ($action === 'reorder_items') {
	require_access(ACCESS_EDIT);
	$ids = json_decode(post('ids'), true);
	foreach ((array)$ids as $i => $mid) {
		DB::exec("UPDATE `{$p}menu_items` SET display_order=? WHERE id=?", [$i, (int)$mid]);
	}
	out(true, '');
}

// ── Picker data ───────────────────────────────────────────────────────────────
if ($action === 'picker_data') {
	$pages      = DB::rows("SELECT id,title FROM `{$p}pages` WHERE status>0 ORDER BY title ASC");
	$categories = DB::rows("SELECT id,name FROM `{$p}categories` WHERE status>0 ORDER BY name ASC");
	$menus      = DB::rows("SELECT id,name FROM `{$p}menus` ORDER BY name ASC");
	out(true, '', ['pages'=>$pages,'categories'=>$categories,'menus'=>$menus]);
}

out(false, 'Unknown action.');
