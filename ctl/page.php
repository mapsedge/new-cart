<?php
$p    = DB_PREFIX;
$slug = get('slug', '');

// Home page is a special case
if (!$slug || $slug === 'home') {
	$page = DB::row("SELECT * FROM `{$p}pages` WHERE slug='home' AND status=1");
} else {
	$page = DB::row("SELECT * FROM `{$p}pages` WHERE slug=? AND status > 0", [$slug]);
}

if (!$page) {
	require DIR_CTL . '404.php';
	exit;
}

$blocks = DB::rows(
	"SELECT * FROM `{$p}page_blocks` WHERE page_id=? AND enabled=1 ORDER BY display_order ASC",
	[$page['id']]
);

// Hydrate each block with the data it needs
foreach ($blocks as &$b) {
	$s = $b['settings'] ? json_decode($b['settings'], true) : [];

	switch ($b['block_type']) {
		case 'slideshow':
			if (!empty($s['slideshow_id'])) {
				$b['slideshow'] = DB::row("SELECT * FROM `{$p}slideshows` WHERE id=?", [(int)$s['slideshow_id']]);
				if ($b['slideshow']) {
					$b['slides'] = DB::rows(
						"SELECT * FROM `{$p}slideshow_slides` WHERE slideshow_id=? AND enabled=1 ORDER BY display_order ASC",
						[$b['slideshow']['id']]
					);
				}
			}
			break;

		case 'featured_products':
			$count = (int)($s['count'] ?? 6);
			$b['products'] = DB::rows(
				"SELECT p.*, pi.filename AS image
				 FROM `{$p}products` p
				 LEFT JOIN `{$p}product_images` pi ON pi.product_id=p.id AND pi.display_order=(SELECT MIN(display_order) FROM `{$p}product_images` WHERE product_id=p.id)
				 WHERE p.status>0 AND p.featured=1
				 ORDER BY p.display_order ASC LIMIT ?",
				[$count]
			);
			$b['heading'] = $s['heading'] ?? 'Featured Products';
			break;

		case 'best_sellers':
			$count = (int)($s['count'] ?? 6);
			$b['products'] = DB::rows(
				"SELECT p.*,
				        (SELECT filename FROM `{$p}product_images` WHERE product_id=p.id ORDER BY display_order ASC LIMIT 1) AS image,
				        COUNT(oi.id) AS sold
				 FROM `{$p}products` p
				 LEFT JOIN `{$p}order_items` oi ON oi.product_id=p.id
				 WHERE p.status>0
				 GROUP BY p.id ORDER BY sold DESC, p.name ASC LIMIT ?",
				[$count]
			);
			$b['heading'] = $s['heading'] ?? 'Best Sellers';
			break;

		case 'best_sellers_category':
			$count    = (int)($s['count'] ?? 6);
			$cat_id   = (int)($s['category_id'] ?? 0);
			$category = $cat_id ? DB::row("SELECT * FROM `{$p}categories` WHERE id=?", [$cat_id]) : null;
			if ($category) {
				$b['products'] = DB::rows(
					"SELECT p.*,
					        (SELECT filename FROM `{$p}product_images` WHERE product_id=p.id ORDER BY display_order ASC LIMIT 1) AS image,
					        COUNT(oi.id) AS sold
					 FROM `{$p}products` p
					 JOIN `{$p}categories_products` cp ON cp.product_id=p.id AND cp.category_id=?
					 LEFT JOIN `{$p}order_items` oi ON oi.product_id=p.id
					 WHERE p.status>0
					 GROUP BY p.id ORDER BY sold DESC LIMIT ?",
					[$cat_id, $count]
				);
			} else {
				$b['products'] = [];
			}
			$b['heading']  = $s['heading'] ?? ($category ? $category['name'] : 'Best Sellers');
			break;

		case 'new_arrivals':
			$count = (int)($s['count'] ?? 6);
			$b['products'] = DB::rows(
				"SELECT p.*, pi.filename AS image
				 FROM `{$p}products` p
				 LEFT JOIN `{$p}product_images` pi ON pi.product_id=p.id AND pi.display_order=(SELECT MIN(display_order) FROM `{$p}product_images` WHERE product_id=p.id)
				 WHERE p.status>0
				 ORDER BY p.id DESC LIMIT ?",
				[$count]
			);
			$b['heading'] = $s['heading'] ?? 'New Arrivals';
			break;

		case 'sitemap':
			// Use generated HTML if present, otherwise empty placeholder
			$b['generated_html'] = $s['generated_html'] ?? null;
			$b['generated_at']   = $s['generated_at']   ?? null;
			break;

		case 'contact_form':
			if (!empty($s['form_id'])) {
				$form = DB::row("SELECT * FROM `{$p}contact_forms` WHERE id=?", [(int)$s['form_id']]);
				if ($form) {
					$form['fields'] = $form['fields'] ? json_decode($form['fields'], true) : [];
					$b['form'] = $form;
				}
			}
			break;
	}
	$b['settings'] = $s;
}
unset($b);

catalog_sidebar($smarty);
$smarty->assign('page',              $page);
$smarty->assign('blocks',            $blocks);
$smarty->assign('page_type',         'page');
$smarty->assign('meta_description',  $page['seo_description'] ?? '');
$smarty->assign('meta_keywords',     $page['seo_keywords']    ?? '');
$smarty->display('page.html');
