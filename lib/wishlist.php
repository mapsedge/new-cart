<?php
/**
 * new-cart Wish List helper
 *
 * Lists are persisted to nc_wishlists / nc_wishlist_items.
 * Cookie `nc_wl` = JSON array of {token, name} — the guest's list tokens.
 * Guests expire after configured days (default 14).
 */

class WishList {

	const COOKIE = 'nc_wl';

	// ── Get guest cookie tokens ────────────────────────────────────────────────
	public static function guestTokens(): array {
		$raw = $_COOKIE[self::COOKIE] ?? '';
		if (!$raw) return [];
		$parsed = json_decode($raw, true);
		return is_array($parsed) ? $parsed : [];
	}

	// ── Save guest cookie ──────────────────────────────────────────────────────
	public static function saveGuestCookie(array $tokens): void {
		$days    = (int)(DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key`='wishlist_guest_days'") ?: 14);
		$expires = time() + ($days * 86400);
		setcookie(self::COOKIE, json_encode($tokens), [
			'expires'  => $expires,
			'path'     => '/',
			'samesite' => 'Lax',
			'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
		]);
	}

	// ── Get or create a list by token ─────────────────────────────────────────
	public static function byToken(string $token): ?array {
		return DB::row("SELECT * FROM `" . DB_PREFIX . "wishlists` WHERE token=?", [$token]);
	}

	// ── Get all lists for a guest (from cookie) ───────────────────────────────
	public static function guestLists(): array {
		$tokens = array_column(self::guestTokens(), 'token');
		if (empty($tokens)) return [];
		$p  = DB_PREFIX;
		$ph = implode(',', array_fill(0, count($tokens), '?'));
		return DB::rows("SELECT * FROM `{$p}wishlists` WHERE token IN ({$ph}) ORDER BY created_at ASC", $tokens);
	}

	// ── Create a new list ─────────────────────────────────────────────────────
	public static function create(string $name = 'My Wish List', ?int $customer_id = null, string $email = ''): array {
		$token = bin2hex(random_bytes(16));
		$p     = DB_PREFIX;
		$id    = DB::insert(
			"INSERT INTO `{$p}wishlists` (token, customer_id, email, name) VALUES (?,?,?,?)",
			[$token, $customer_id, $email, $name]
		);
		$list  = DB::row("SELECT * FROM `{$p}wishlists` WHERE id=?", [$id]);

		// Add to guest cookie if not logged in
		if (!$customer_id) {
			$tokens   = self::guestTokens();
			$tokens[] = ['token' => $token, 'name' => $name];
			self::saveGuestCookie($tokens);
		}

		return $list;
	}

	// ── Add item to a list ────────────────────────────────────────────────────
	public static function addItem(string $token, int $product_id): bool {
		$list = self::byToken($token);
		if (!$list) return false;
		$p = DB_PREFIX;
		try {
			DB::exec(
				"INSERT IGNORE INTO `{$p}wishlist_items` (wishlist_id, product_id) VALUES (?,?)",
				[$list['id'], $product_id]
			);
			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	// ── Remove item from a list ───────────────────────────────────────────────
	public static function removeItem(string $token, int $product_id): bool {
		$list = self::byToken($token);
		if (!$list) return false;
		DB::exec(
			"DELETE FROM `" . DB_PREFIX . "wishlist_items` WHERE wishlist_id=? AND product_id=?",
			[$list['id'], $product_id]
		);
		return true;
	}

	// ── Get items in a list ───────────────────────────────────────────────────
	public static function items(string $token): array {
		$list = self::byToken($token);
		if (!$list) return [];
		$p = DB_PREFIX;
		return DB::rows(
			"SELECT p.id, p.name, p.slug, p.price, p.list_price,
			        pi.filename AS image, wi.added_at
			 FROM `{$p}wishlist_items` wi
			 JOIN `{$p}products` p ON p.id = wi.product_id
			 LEFT JOIN `{$p}product_images` pi
			   ON pi.product_id = p.id AND pi.display_order = (
			      SELECT MIN(display_order) FROM `{$p}product_images` WHERE product_id = p.id
			   )
			 WHERE wi.wishlist_id = ? AND p.status > 0
			 ORDER BY wi.added_at DESC",
			[$list['id']]
		);
	}

	// ── Persist guest email ───────────────────────────────────────────────────
	public static function persistEmail(string $token, string $email): bool {
		$list = self::byToken($token);
		if (!$list) return false;
		DB::exec("UPDATE `" . DB_PREFIX . "wishlists` SET email=? WHERE id=?", [$email, $list['id']]);
		return true;
	}

	// ── Merge guest lists into account on login ───────────────────────────────
	public static function mergeGuest(int $customer_id): void {
		$tokens = array_column(self::guestTokens(), 'token');
		if (empty($tokens)) return;
		$p  = DB_PREFIX;
		$ph = implode(',', array_fill(0, count($tokens), '?'));
		$lists = DB::rows("SELECT * FROM `{$p}wishlists` WHERE token IN ({$ph})", $tokens);
		foreach ($lists as $list) {
			DB::exec("UPDATE `{$p}wishlists` SET customer_id=? WHERE id=? AND customer_id IS NULL", [$customer_id, $list['id']]);
		}
		// Clear guest cookie — they're now owned
		setcookie(self::COOKIE, '', ['expires' => time() - 1, 'path' => '/']);
	}
}
