<?php
/**
 * GoShippo plugin — hooks.php
 */

Hook::on('catalog.checkout.shipping_rates', function(array $rates, array $context): array {
	// $context: ['items' => [...], 'address' => [...]]
	$shippo_rates = goshippo_get_rates($context['address'] ?? [], $context['items'] ?? []);
	foreach ($shippo_rates as $r) {
		$rates[] = $r;
	}
	return $rates;
});

// ── Shippo API helper ──────────────────────────────────────────────────────────
function goshippo_setting(string $key): string {
	static $cache = [];
	if (!isset($cache[$key])) {
		$cache[$key] = DB::val("SELECT `value` FROM `" . DB_PREFIX . "settings` WHERE `key` = ?", [$key]) ?: '';
	}
	return $cache[$key];
}

function goshippo_api(string $endpoint, array $body = [], string $method = 'POST'): ?array {
	$key = goshippo_setting('shippo_api_key');
	if (!$key) return null;

	$ch = curl_init('https://api.goshippo.com/' . $endpoint);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER     => [
			'Authorization: ShippoToken ' . $key,
			'Content-Type: application/json',
		],
	]);
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	}
	$response = curl_exec($ch);
	curl_close($ch);
	return $response ? json_decode($response, true) : null;
}

function goshippo_get_rates(array $address, array $items): array {
	$key = goshippo_setting('shippo_api_key');
	if (!$key || empty($address['zip'])) return [];

	$shipment = goshippo_api('shipments', [
		'address_from' => [
			'name'    => goshippo_setting('shippo_from_name'),
			'street1' => goshippo_setting('shippo_from_street1'),
			'city'    => goshippo_setting('shippo_from_city'),
			'state'   => goshippo_setting('shippo_from_state'),
			'zip'     => goshippo_setting('shippo_from_zip'),
			'country' => goshippo_setting('shippo_from_country') ?: 'US',
		],
		'address_to' => [
			'name'    => ($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''),
			'street1' => $address['address1'] ?? '',
			'street2' => $address['address2'] ?? '',
			'city'    => $address['city']     ?? '',
			'state'   => $address['state']    ?? '',
			'zip'     => $address['zip']      ?? '',
			'country' => $address['country']  ?? 'US',
		],
		'parcels' => [[
			'length'        => goshippo_setting('shippo_parcel_length')  ?: '10',
			'width'         => goshippo_setting('shippo_parcel_width')   ?: '8',
			'height'        => goshippo_setting('shippo_parcel_height')  ?: '4',
			'distance_unit' => goshippo_setting('shippo_parcel_distance_unit') ?: 'in',
			'weight'        => goshippo_setting('shippo_parcel_weight')  ?: '2',
			'mass_unit'     => goshippo_setting('shippo_parcel_mass_unit') ?: 'lb',
		]],
		'async' => false,
	]);

	if (empty($shipment['rates'])) return [];

	$rates = [];
	foreach ($shipment['rates'] as $r) {
		if ($r['object_state'] !== 'VALID') continue;
		$rates[] = [
			'id'           => 'shippo:' . $r['object_id'],
			'carrier'      => $r['provider'],
			'service'      => $r['servicelevel']['name'],
			'rate'         => (float)$r['amount'],
			'currency'     => $r['currency'],
			'days'         => $r['estimated_days'] ?? null,
			'shippo_token' => $r['object_id'],
		];
	}

	// Sort cheapest first
	usort($rates, fn($a, $b) => $a['rate'] <=> $b['rate']);
	return $rates;
}
