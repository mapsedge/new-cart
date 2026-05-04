<?php
// GoShippo plugin settings form.
// Receives: $settings (current values from nc_settings), $manifest (plugin manifest).
$mode = $settings['shippo_mode'] ?? 'test';
$hs   = fn(string $k) => htmlspecialchars($settings[$k] ?? '', ENT_QUOTES, 'UTF-8');
$head_style = 'font-weight:700;font-size:.83rem;margin:1.1rem 0 .5rem;color:var(--nc-text)';
?>
<div data-nc-tabs>
<ul>
	<li><a href="#tab-address">Address</a></li>
	<li><a href="#tab-hello">Hello World</a></li>
</ul>

<div id="tab-address">

<div class="df">
	<label for="shippo_mode">Mode</label>
	<select name="shippo_mode" id="shippo_mode">
		<option value="test"<?= $mode === 'test' ? ' selected' : '' ?>>Test</option>
		<option value="live"<?= $mode === 'live' ? ' selected' : '' ?>>Live</option>
	</select>
</div>

<p style="<?= $head_style ?>">API Keys</p>

<div class="df">
	<label for="shippo_test_api_key">Test API Key</label>
	<input type="text" name="shippo_test_api_key" id="shippo_test_api_key"
	       value="<?= $hs('shippo_test_api_key') ?>"
	       maxlength="255" autocomplete="off" placeholder="shippo_test_…">
</div>

<div class="df">
	<label for="shippo_live_api_key">Live API Key</label>
	<input type="text" name="shippo_live_api_key" id="shippo_live_api_key"
	       value="<?= $hs('shippo_live_api_key') ?>"
	       maxlength="255" autocomplete="off" placeholder="shippo_live_…">
</div>

<p style="<?= $head_style ?>">Ship From</p>

<div class="df">
	<label for="shippo_from_name">Name</label>
	<input type="text" name="shippo_from_name" id="shippo_from_name"
	       value="<?= $hs('shippo_from_name') ?>" maxlength="255">
</div>

<div class="df">
	<label for="shippo_from_street1">Street</label>
	<input type="text" name="shippo_from_street1" id="shippo_from_street1"
	       value="<?= $hs('shippo_from_street1') ?>" maxlength="255">
</div>

<div class="df">
	<label for="shippo_from_city">City</label>
	<input type="text" name="shippo_from_city" id="shippo_from_city"
	       value="<?= $hs('shippo_from_city') ?>" maxlength="100">
</div>

<div class="df">
	<label for="shippo_from_state">State</label>
	<input type="text" name="shippo_from_state" id="shippo_from_state"
	       value="<?= $hs('shippo_from_state') ?>" maxlength="50">
</div>

<div class="df">
	<label for="shippo_from_zip">ZIP</label>
	<input type="text" name="shippo_from_zip" id="shippo_from_zip"
	       value="<?= $hs('shippo_from_zip') ?>" maxlength="20">
</div>

<div class="df">
	<label for="shippo_from_country">Country</label>
	<input type="text" name="shippo_from_country" id="shippo_from_country"
	       value="<?= $hs('shippo_from_country') ?>" maxlength="2" placeholder="US">
</div>

<p style="<?= $head_style ?>">Default Parcel</p>

<div style="display:grid;grid-template-columns:1fr auto;align-items:center;gap:.4rem .75rem;max-width:340px">

	<input type="text" name="shippo_parcel_length" id="shippo_parcel_length"
	       value="<?= $hs('shippo_parcel_length') ?>" maxlength="10" placeholder="Length">
	<select name="shippo_parcel_distance_unit" id="shippo_parcel_distance_unit"
	        onchange="document.querySelectorAll('.shippo-dist-unit').forEach(el=>el.textContent=this.value)">
		<option value="in"<?= ($settings['shippo_parcel_distance_unit'] ?? 'in') === 'in' ? ' selected' : '' ?>>in</option>
		<option value="cm"<?= ($settings['shippo_parcel_distance_unit'] ?? '') === 'cm' ? ' selected' : '' ?>>cm</option>
	</select>

	<input type="text" name="shippo_parcel_height" id="shippo_parcel_height"
	       value="<?= $hs('shippo_parcel_height') ?>" maxlength="10" placeholder="Height">
	<span class="shippo-dist-unit" style="font-size:.85rem;color:var(--nc-text-dim)"><?= htmlspecialchars($settings['shippo_parcel_distance_unit'] ?? 'in', ENT_QUOTES, 'UTF-8') ?></span>

	<input type="text" name="shippo_parcel_width" id="shippo_parcel_width"
	       value="<?= $hs('shippo_parcel_width') ?>" maxlength="10" placeholder="Width">
	<span class="shippo-dist-unit" style="font-size:.85rem;color:var(--nc-text-dim)"><?= htmlspecialchars($settings['shippo_parcel_distance_unit'] ?? 'in', ENT_QUOTES, 'UTF-8') ?></span>

	<hr style="grid-column:1/-1;border:none;border-top:1px solid var(--nc-border);margin:.35rem 0">

	<input type="text" name="shippo_parcel_weight" id="shippo_parcel_weight"
	       value="<?= $hs('shippo_parcel_weight') ?>" maxlength="10" placeholder="Weight">
	<select name="shippo_parcel_mass_unit" id="shippo_parcel_mass_unit">
		<option value="lb"<?= ($settings['shippo_parcel_mass_unit'] ?? 'lb') === 'lb' ? ' selected' : '' ?>>lb</option>
		<option value="oz"<?= ($settings['shippo_parcel_mass_unit'] ?? '') === 'oz' ? ' selected' : '' ?>>oz</option>
		<option value="g"<?=  ($settings['shippo_parcel_mass_unit'] ?? '') === 'g'  ? ' selected' : '' ?>>g</option>
		<option value="kg"<?= ($settings['shippo_parcel_mass_unit'] ?? '') === 'kg' ? ' selected' : '' ?>>kg</option>
	</select>

</div>

</div>

<div id="tab-hello">
	<p>Hello World mj27sWy4Wb2T</p>
</div>

</div>
