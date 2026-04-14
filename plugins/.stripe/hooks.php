<?php
/**
 * Stripe plugin — hooks.php
 * Loaded by PluginLoader on every bootstrap when plugin is enabled.
 */

Hook::on('catalog.checkout.payment_methods', function(array $methods, $context): array {
	$methods[] = [
		'id'    => 'stripe',
		'label' => 'Credit / Debit Card',
		'icon'  => '/plugins/stripe/img/stripe.svg',
	];
	return $methods;
});
