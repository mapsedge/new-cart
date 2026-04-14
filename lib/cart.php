<?php
/**
 * new-cart — Cart helper
 *
 * Cart stored in $_SESSION['cart'] as:
 *   [ { product_id, qty, options: {option_id: value_id, ...} } ]
 *
 * For logged-in customers, cart is also persisted to nc_cart table.
 */

class Cart {

	// ── Get full cart with product data ────────────────────────────────────────
	public static function get(): array {
		$items = $_SESSION['cart'] ?? [];
		if (empty($items)) return [];

		$p    = DB_PREFIX;
		$out  = [];

		foreach ($items as $item) {
			$product = DB::row(
				"SELECT id, name, slug, price, list_price, stock, description,
				        status, free_shipping
				 FROM `{$p}products` WHERE id = ? AND status > 0",
				[(int)$item['product_id']]
			);
			if (!$product) continue;

			// Primary image
			$img = DB::row(
				"SELECT filename FROM `{$p}product_images`
				 WHERE product_id = ? ORDER BY display_order ASC LIMIT 1",
				[$product['id']]
			);
			$product['image'] = $img ? $img['filename'] : '';

			// Options
			$options = [];
			$price_adj = 0.0;
			foreach ($item['options'] ?? [] as $po_id => $pov_id) {
				$opt = DB::row(
					"SELECT po.id, o.name AS option_name, pov.label, ov.text AS value_text,
					        pov.price_modifier, pov.price_prefix
					 FROM `{$p}product_options` po
					 JOIN `{$p}options` o ON o.id = po.option_id
					 JOIN `{$p}product_option_values` pov ON pov.product_option_id = po.id
					 JOIN `{$p}option_values` ov ON ov.id = pov.option_value_id
					 WHERE po.id = ? AND pov.id = ?",
					[(int)$po_id, (int)$pov_id]
				);
				if ($opt) {
					$options[] = $opt;
					$mod = (float)$opt['price_modifier'];
					$price_adj += $opt['price_prefix'] === '-' ? -$mod : $mod;
				}
			}

			$out[] = [
				'product_id' => $product['id'],
				'qty'        => max(1, (int)$item['qty']),
				'product'    => $product,
				'options'    => $options,
				'unit_price' => (float)$product['price'] + $price_adj,
				'line_total' => ((float)$product['price'] + $price_adj) * max(1, (int)$item['qty']),
			];
		}

		return $out;
	}

	// ── Add item ───────────────────────────────────────────────────────────────
	public static function add(int $product_id, int $qty = 1, array $options = []): void {
		if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

		// Match existing item by product + options
		foreach ($_SESSION['cart'] as &$item) {
			if ($item['product_id'] === $product_id && $item['options'] == $options) {
				$item['qty'] += $qty;
				return;
			}
		}
		unset($item);

		$_SESSION['cart'][] = [
			'product_id' => $product_id,
			'qty'        => $qty,
			'options'    => $options,
		];
	}

	// ── Update qty ─────────────────────────────────────────────────────────────
	public static function update(int $index, int $qty): void {
		if ($qty <= 0) {
			self::remove($index);
			return;
		}
		if (isset($_SESSION['cart'][$index])) {
			$_SESSION['cart'][$index]['qty'] = $qty;
		}
	}

	// ── Remove item ────────────────────────────────────────────────────────────
	public static function remove(int $index): void {
		if (isset($_SESSION['cart'][$index])) {
			array_splice($_SESSION['cart'], $index, 1);
		}
	}

	// ── Clear cart ─────────────────────────────────────────────────────────────
	public static function clear(): void {
		$_SESSION['cart'] = [];
	}

	// ── Count items ────────────────────────────────────────────────────────────
	public static function count(): int {
		$total = 0;
		foreach ($_SESSION['cart'] ?? [] as $item) {
			$total += max(1, (int)$item['qty']);
		}
		return $total;
	}

	// ── Subtotal ───────────────────────────────────────────────────────────────
	public static function subtotal(): float {
		$total = 0.0;
		foreach (self::get() as $item) {
			$total += $item['line_total'];
		}
		return $total;
	}

	// ── To cents (for Stripe) ──────────────────────────────────────────────────
	public static function subtotalCents(): int {
		return (int)round(self::subtotal() * 100);
	}
}
