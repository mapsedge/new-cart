<?php
/**
 * page_block_helper.php
 * Shared utilities for block-page rendering.
 */

/**
 * Normalise raw DB row/col values into contiguous CSS grid coordinates.
 * Mutates the blocks array in place.
 */
function normalize_block_grid(array &$blocks): void {
	$row_values = array_unique(array_column($blocks, 'row'));
	sort($row_values);
	$row_map = array_flip($row_values);

	foreach ($blocks as &$b) {
		$cs = max(1, min(4, (int)($b['col_start'] ?? 1)));
		$sp = max(1, min(4, (int)($b['col_span']  ?? 4)));
		if ($cs + $sp > 5) $sp = 5 - $cs;
		$b['grid_col_start'] = $cs;
		$b['grid_col_span']  = $sp;
		$b['grid_row_span']  = max(1, (int)($b['row_span'] ?? 1));
		$b['grid_row']       = ($row_map[(int)($b['row'] ?? 0)] ?? 0) + 1;
	}
	unset($b);
}

/**
 * Load and hydrate all blocks for a page, including grid position normalisation.
 * Returns the processed blocks array ready for Smarty assignment.
 */
function hydrate_page_blocks(int $page_id, string $p, $smarty): array {
	$blocks = DB::rows(
		"SELECT * FROM `{$p}page_blocks` WHERE page_id=? AND enabled=1 ORDER BY display_order ASC",
		[$page_id]
	);

	foreach ($blocks as &$b) {
		$s = $b['settings'] ? json_decode($b['settings'], true) : [];
		$b['is_core'] = !empty($s['is_core']);

		switch ($b['block_type']) {
			case 'product_view':
			case 'cart_contents':
			case 'checkout_form':
				// Core system blocks — rendered directly by their respective templates
				break;
			case 'menu':
				if (!empty($s['menu_id'])) {
					$items = load_menu((int)$s['menu_id'], $p);
					if (!empty($s['max_items']) && (int)$s['max_items'] > 0) {
						$items = array_slice($items, 0, (int)$s['max_items']);
					}
					$b['menu_items'] = $items;
				}
				break;
			case 'related_products':
				$b['heading']  = $s['heading'] ?? 'Related Products';
				$b['products'] = []; // requires product-page context; empty on generic pages
				break;
			case 'featured_products':
			case 'best_sellers':
			case 'new_arrivals':
				$count = (int)($s['count'] ?? 6);
				$b['heading']  = $s['heading'] ?? ucwords(str_replace('_', ' ', $b['block_type']));
				$orderBy       = $b['block_type'] === 'new_arrivals' ? 'p.id DESC' : 'p.display_order ASC, p.name ASC';
				$featuredWhere = $b['block_type'] === 'featured_products' ? ' AND p.featured=1' : '';
				$b['products'] = DB::rows(
					"SELECT p.*, (SELECT filename FROM `{$p}product_images`
					  WHERE product_id=p.id ORDER BY display_order ASC LIMIT 1) AS image
					 FROM `{$p}products` p WHERE p.status>0{$featuredWhere}
					 ORDER BY {$orderBy} LIMIT ?",
					[$count]
				);
				break;
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
		}
		$b['settings'] = $s;
	}
	unset($b);

	normalize_block_grid($blocks);

	return $blocks;
}
