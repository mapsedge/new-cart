<?php
/**
 * new-cart Hook system
 *
 * Three variants per hook point:
 *   :before  — runs before, receives data by reference, can modify it
 *   :after   — runs after, receives result data, cannot prevent execution
 *   :instead — replaces default behavior entirely; only one plugin may
 *              register :instead per hook (second registration throws)
 *
 * Usage (plugin hooks.php):
 *   Hook::on('catalog.product.view.before', function(&$data) { ... });
 *   Hook::on('catalog.product.price.instead', function($data) { return $price; });
 *
 * Usage (new-cart core):
 *   Hook::fire('catalog.product.view.before', $data);
 *   $price = Hook::fireInstead('catalog.product.price', $default_price, $data);
 */
class Hook {

	private static array $listeners = [];
	private static array $instead   = [];

	// ── Register a listener ────────────────────────────────────────────────────
	public static function on(string $event, callable $fn): void {
		if (str_ends_with($event, '.instead')) {
			$base = substr($event, 0, -8);
			if (isset(self::$instead[$base])) {
				throw new \RuntimeException(
					"Hook conflict: ':instead' already registered for '{$base}'. " .
					"Only one plugin may register :instead per hook."
				);
			}
			self::$instead[$base] = $fn;
			return;
		}
		self::$listeners[$event][] = $fn;
	}

	// ── Fire :before or :after ─────────────────────────────────────────────────
	public static function fire(string $event, mixed &$data = null): void {
		foreach (self::$listeners[$event] ?? [] as $fn) {
			$fn($data);
		}
	}

	// ── Fire :instead — returns plugin result or default ──────────────────────
	// Usage:
	//   $result = Hook::instead('catalog.product.price', $default, $data);
	public static function instead(string $base, mixed $default, mixed $data = null): mixed {
		if (isset(self::$instead[$base])) {
			return (self::$instead[$base])($data);
		}
		return $default;
	}

	// ── Check if :instead is registered ───────────────────────────────────────
	public static function hasInstead(string $base): bool {
		return isset(self::$instead[$base]);
	}

	// ── Clear all listeners (testing / reload) ─────────────────────────────────
	public static function clear(): void {
		self::$listeners = [];
		self::$instead   = [];
	}

	// ── List of all defined hook points ───────────────────────────────────────
	// Informational — used by admin Plugins page to show available hooks.
	public static function defined(): array {
		return [
			// Catalog
			'catalog.bootstrap',
			'catalog.page.head',
			'catalog.page.foot',
			'catalog.layout.nav',
			'catalog.layout.sidebar',
			'catalog.layout.footer',
			'catalog.product.list',
			'catalog.product.view',
			'catalog.product.price',
			'catalog.product.add_to_cart',
			'catalog.category.view',
			'catalog.search',
			'catalog.cart.view',
			'catalog.cart.add',
			'catalog.cart.remove',
			'catalog.cart.update',
			'catalog.checkout',
			'catalog.checkout.shipping',
			'catalog.checkout.payment',
			'catalog.checkout.confirm',
			'catalog.order.create',
			'catalog.order.complete',
			'catalog.customer.login',
			'catalog.customer.register',
			'catalog.customer.logout',
			// Admin
			'admin.bootstrap',
			'admin.page.head',
			'admin.page.foot',
			'admin.nav',
			'admin.category.list',
			'admin.category.save',
			'admin.category.delete',
			'admin.plugin.install',
			'admin.plugin.uninstall',
			'admin.plugin.enable',
			'admin.plugin.disable',
			// System
			'system.error',
			'system.email.send',
			'system.image.resize',
			'system.cron',
		];
	}
}
