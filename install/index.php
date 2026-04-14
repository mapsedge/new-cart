<?php
if (file_exists(__DIR__ . '/.installed')) {
	http_response_code(403);
	exit('Installation is already complete. Remove install/.installed to reinstall.');
}

function randomAdminPath(): string {
	$consonants = 'bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ';
	$digits     = '23456789';
	$all        = $consonants . $digits;
	$len        = 12;
	do {
		$str = '';
		for ($i = 0; $i < $len; $i++) {
			$pool = ($i === 0 || $i === $len - 1) ? $consonants : $all;
			$str .= $pool[random_int(0, strlen($pool) - 1)];
		}
	} while (!preg_match('/[a-zA-Z]/', $str[0]) || !preg_match('/[a-zA-Z]/', $str[$len - 1]));
	return $str;
}

$defaultAdminPath = randomAdminPath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>new-cart — Install</title>
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
			background: #f0f2f5;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 2rem;
		}

		#wizard {
			background: #fff;
			border-radius: .75rem;
			border: 1px solid #c9cdd4;
			box-shadow: 0 4px 32px rgba(0,0,0,.18);
			width: 100%;
			max-width: 600px;
			overflow: hidden;
		}

		#wizard-header { background: #2c3e50; color: #fff; padding: 1.5rem 2rem; }
		#wizard-header h1 { font-size: 1.4rem; font-weight: 600; margin-bottom: .2rem; }
		#wizard-header p  { font-size: .85rem; opacity: .8; }

		/* ── Permissions banner ── */
		#perm-banner {
			padding: .85rem 2rem;
			font-size: .87rem;
			font-weight: 500;
			display: none;
			align-items: flex-start;
			gap: .75rem;
			border-bottom: 1px solid transparent;
		}
		#perm-banner.checking { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; display: flex; }
		#perm-banner.ok       { background: #dcfce7; color: #14532d; border-color: #bbf7d0; display: flex; }
		#perm-banner.error    { background: #fee2e2; color: #7f1d1d; border-color: #fecaca; display: block; padding: 1rem 2rem; }
		#perm-banner .perm-msg { flex: 1; line-height: 1.5; }

		#perm-dir-list { list-style: none; margin: .75rem 0 1rem; padding: 0; }
		#perm-dir-list li {
			display: flex; align-items: center; gap: .5rem;
			font-size: .85rem; padding: .3rem 0;
			border-bottom: 1px solid rgba(0,0,0,.06);
			color: #1a1a1a;
		}
		#perm-dir-list li:last-child { border-bottom: none; }
		#perm-dir-list .icon-ok   { color: #16a34a; font-size: 1rem; }
		#perm-dir-list .icon-fail { color: #dc2626; font-size: 1rem; }
		#perm-dir-list .dir-path  { font-family: monospace; font-size: .82rem; color: #444; margin-left: auto; }

		#perm-fix-cmd {
			background: #1a1a1a; color: #f0f0f0; font-family: monospace;
			font-size: .8rem; padding: .6rem .85rem; border-radius: .375rem;
			margin: .5rem 0 .75rem; white-space: pre-wrap; word-break: break-all;
			line-height: 1.6;
		}

		/* ── Wizard body — locked until permissions pass ── */
		#wizard-body { transition: opacity .3s; }
		#wizard-body.locked { opacity: .35; pointer-events: none; user-select: none; }

		#step-nav { display: flex; border-bottom: 1px solid #d1d5db; background: #f8f9fa; }

		.step-tab {
			flex: 1; padding: .7rem .25rem; text-align: center;
			font-size: .73rem; font-weight: 600; color: #555;
			border-bottom: 3px solid transparent;
			transition: color .2s, border-color .2s; line-height: 1.3;
		}
		.step-tab.active   { color: #2563eb; border-bottom-color: #2563eb; }
		.step-tab.complete { color: #16a34a; border-bottom-color: #16a34a; }

		.step { display: none; padding: 1.75rem 2rem; }
		.step.active { display: block; }
		.step h2 { font-size: 1.05rem; font-weight: 600; margin-bottom: .4rem; color: #1a1a1a; }
		.step .step-desc { font-size: .85rem; color: #333; margin-bottom: 1.25rem; line-height: 1.5; }

		.subsection {
			border: 1px solid #d1d5db; border-radius: .5rem;
			padding: 1rem 1.25rem; margin-bottom: 1.25rem;
		}
		.subsection-head {
			font-size: .75rem; font-weight: 700; text-transform: uppercase;
			letter-spacing: .06em; color: #444; margin-bottom: .85rem;
		}

		.field { margin-bottom: .85rem; }
		.field:last-child { margin-bottom: 0; }
		.field label { display: block; font-size: .85rem; font-weight: 600; color: #1a1a1a; margin-bottom: .3rem; }

		.field input[type=text],
		.field input[type=email],
		.field input[type=password] {
			width: 100%; padding: .5rem .7rem;
			border: 1px solid #d1d5db; border-radius: .375rem;
			font-size: .92rem; color: #1a1a1a; outline: none;
			transition: border-color .15s, box-shadow .15s;
		}
		.field input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
		.field input:disabled { background: #f9fafb; color: #888; }

		.hint { font-size: .78rem; color: #333; margin-top: .3rem; line-height: 1.5; }
		.hint code { background: #f3f4f6; padding: .1em .35em; border-radius: .2rem; font-size: .88em; color: #1a1a1a; }
		.hint.warn { color: #92400e; font-weight: 500; }

		.field-row { display: flex; gap: .85rem; }
		.field-row .field { flex: 1; }

		#status {
			margin: 0 2rem .75rem; padding: .65rem .9rem;
			border-radius: .375rem; font-size: .87rem; display: none; font-weight: 500;
		}
		#status.ok    { background: #dcfce7; color: #14532d; }
		#status.error { background: #fee2e2; color: #7f1d1d; }

		.inline-status {
			font-size: .82rem; margin-top: .6rem; padding: .45rem .75rem;
			border-radius: .3rem; display: none; font-weight: 500;
		}
		.inline-status.ok    { background: #dcfce7; color: #14532d; }
		.inline-status.error { background: #fee2e2; color: #7f1d1d; }

		.btn-row { display: flex; justify-content: flex-end; gap: .65rem; margin-top: 1.25rem; align-items: center; }
		.btn-row-split { justify-content: space-between; }

		button {
			padding: .5rem 1.15rem; border: none; border-radius: .375rem;
			font-size: .88rem; font-weight: 600; cursor: pointer; transition: filter .15s;
		}
		button:disabled        { opacity: .45; cursor: not-allowed; }
		button:hover:not(:disabled) { filter: brightness(.93); }

		.btn-primary   { background: #2563eb; color: #fff; }
		.btn-secondary { background: #e5e7eb; color: #1a1a1a; }
		.btn-success   { background: #16a34a; color: #fff; }
		.btn-outline   { background: #fff; color: #2563eb; border: 1px solid #2563eb; }
		.btn-sm        { padding: .35rem .85rem; font-size: .82rem; }

		.spinner {
			display: inline-block; width: .85rem; height: .85rem;
			border: 2px solid rgba(255,255,255,.35); border-top-color: #fff;
			border-radius: 50%; animation: spin .6s linear infinite;
			vertical-align: middle; margin-right: .35rem;
		}
		.btn-secondary .spinner, .btn-outline .spinner {
			border-color: rgba(0,0,0,.2); border-top-color: #333;
		}
		@keyframes spin { to { transform: rotate(360deg); } }

		.cred-verified {
			font-size: .8rem; color: #14532d; font-weight: 600;
			display: none; margin-left: .5rem;
		}
		.cred-verified.show { display: inline; }

		#step-done { text-align: center; padding: 3rem 2rem; display: none; }
		#step-done .checkmark { font-size: 3rem; margin-bottom: 1rem; }
		#step-done h2 { font-size: 1.3rem; margin-bottom: .5rem; color: #1a1a1a; }
		#step-done p  { color: #333; margin-bottom: 1.5rem; font-size: .9rem; }

		.req { color: #b91c1c; margin-left: .15rem; }
		.match-ok   { color: #14532d; font-size: .78rem; margin-top: .3rem; font-weight: 500; }
		.match-fail { color: #7f1d1d; font-size: .78rem; margin-top: .3rem; font-weight: 500; }
	</style>
</head>
<body>
<div id="wizard">

	<div id="wizard-header">
		<h1>new-cart</h1>
		<p>Installation wizard</p>
	</div>

	<!-- ── Permissions banner ── -->
	<div id="perm-banner" class="checking">
		<span class="perm-msg">Checking directory permissions…</span>
	</div>

	<div id="wizard-body" class="locked">

		<div id="step-nav">
			<div class="step-tab active" id="tab-1">1. Root &amp; DB</div>
			<div class="step-tab"        id="tab-2">2. DB User</div>
			<div class="step-tab"        id="tab-3">3. Admin</div>
			<div class="step-tab"        id="tab-4">4. Site</div>
			<div class="step-tab"        id="tab-5">5. Install</div>
		</div>

		<div id="status"></div>

		<!-- ── Step 1: Root credentials + optional DB create ── -->
		<div class="step active" id="step-1">
			<h2>Root &amp; Database</h2>
			<p class="step-desc">Enter your MySQL root credentials, then optionally create a new database.</p>

			<div class="subsection">
				<div class="subsection-head">MySQL Root Credentials <span class="req">*</span></div>
				<div class="field-row">
					<div class="field">
						<label>Host <span class="req">*</span></label>
						<input type="text" id="db_host" value="localhost">
						<div class="hint">Usually <code>localhost</code></div>
					</div>
					<div class="field">
						<label>Root Username <span class="req">*</span></label>
						<input type="text" id="db_root" value="root" >
					</div>
				</div>
				<div class="field">
					<label>Root Password <span class="req">*</span></label>
					<input  id="db_rootpw" >
					<div class="hint">Used only during installation — never saved.</div>
				</div>
				<div class="btn-row" style="margin-top:.85rem">
					<button class="btn-outline btn-sm" id="btn-test-root" disabled>Test Credentials</button>
					<span class="cred-verified" id="cred-ok">✓ Verified</span>
				</div>
				<div class="inline-status" id="test-root-status"></div>
			</div>

			<div class="subsection">
				<div class="subsection-head">Create Database (optional)</div>
				<p class="hint" style="margin-bottom:.85rem">Skip if your database already exists. Requires credentials to be verified first.</p>
				<div class="field">
					<label>Database Name</label>
					<input type="text" id="db_name_create" placeholder="new_cart" disabled>
					<div class="hint">Letters, numbers and underscores only.</div>
				</div>
				<div class="btn-row" style="margin-top:.75rem">
					<button class="btn-outline btn-sm" id="btn-create-db" disabled>Create Database</button>
				</div>
				<div class="inline-status" id="create-db-status"></div>
			</div>

			<div class="btn-row">
				<button class="btn-primary" id="btn-step1-next" disabled>Continue</button>
			</div>
		</div>

		<!-- ── Step 2: DB user ── -->
		<div class="step" id="step-2">
			<h2>Database User</h2>
			<p class="step-desc">Create a dedicated database user for new-cart. Safer than using root at runtime.</p>

			<div class="subsection">
				<div class="subsection-head">New Database User</div>
				<div class="field-row">
					<div class="field">
						<label>Username <span class="req">*</span></label>
						<input type="text" id="db_user" >
						<div class="hint">e.g. <code>new_cart</code></div>
					</div>
					<div class="field">
						<label>Password <span class="req">*</span></label>
						<input  id="db_pass" >
					</div>
				</div>
				<div class="field">
					<label>Database Name <span class="req">*</span></label>
					<input type="text" id="db_name" placeholder="new_cart">
					<div class="hint">The database this user will be granted access to.</div>
				</div>
				<div class="field">
					<label>Table Prefix</label>
					<input type="text" id="db_prefix" value="nc_">
					<div class="hint">Allows multiple apps to share one database. e.g. <code>nc_</code></div>
				</div>
			</div>

			<div class="btn-row">
				<button class="btn-secondary" id="btn-back-1">Back</button>
				<button class="btn-primary"   id="btn-create-user" disabled>Create User &amp; Continue</button>
			</div>
		</div>

		<!-- ── Step 3: Admin account ── -->
		<div class="step" id="step-3">
			<h2>Admin Account</h2>
			<p class="step-desc">This account will have full access to the admin panel.</p>
			<div class="field-row">
				<div class="field">
					<label>Username <span class="req">*</span></label>
					<input type="text" id="admin_user" >
					<div class="hint">Minimum 3 characters</div>
				</div>
				<div class="field">
					<label>Email <span class="req">*</span></label>
					<input type="email" id="admin_email" >
				</div>
			</div>
			<div class="field">
				<label>Password <span class="req">*</span></label>
				<input  id="admin_pass" >
				<div class="hint">Minimum 8 characters.</div>
			</div>
			<div class="field">
				<label>Confirm Password <span class="req">*</span></label>
				<input  id="admin_confirm" >
				<div id="pw-match-msg"></div>
			</div>
			<div class="btn-row">
				<button class="btn-secondary" id="btn-back-2">Back</button>
				<button class="btn-primary"   id="btn-validate-admin" disabled>Continue</button>
			</div>
		</div>

		<!-- ── Step 4: Site details ── -->
		<div class="step" id="step-4">
			<h2>Site Details</h2>
			<p class="step-desc">Editable any time in the admin panel.</p>
			<div class="field">
				<label>Store Name <span class="req">*</span></label>
				<input type="text" id="site_name" value="My Store">
			</div>
			<div class="field-row">
				<div class="field" style="flex:0 0 140px">
					<label>Currency Symbol</label>
					<input type="text" id="site_currency" value="$">
				</div>
				<div class="field">
					<label>Store Email</label>
					<input type="email" id="site_email" placeholder="store@example.com">
					<div class="hint">Used for order confirmation emails</div>
				</div>
			</div>
			<div class="field">
				<label>Admin URL Path</label>
				<input type="text" id="admin_path" value="<?= htmlspecialchars($defaultAdminPath) ?>">
				<div class="hint">
					This is the web address you'll use to reach your admin panel:
					<code>/<span id="admin-path-preview"><?= htmlspecialchars($defaultAdminPath) ?></span>/</code>.
					Keep it somewhere safe — you'll need it every time you log in.
					A random value is pre-filled for security, or you can set something easier to remember.
				</div>
			</div>
			<div class="btn-row">
				<button class="btn-secondary" id="btn-back-3">Back</button>
				<button class="btn-primary"   id="btn-to-install" disabled>Continue</button>
			</div>
		</div>

		<!-- ── Step 5: Install ── -->
		<div class="step" id="step-5">
			<h2>Ready to Install</h2>
			<p class="step-desc">Clicking Install will:</p>
			<ul style="font-size:.88rem;color:#1a1a1a;margin:.5rem 0 1.25rem 1.5rem;line-height:2.2">
				<li>Create all database tables</li>
				<li>Create your admin account</li>
				<li>Write <code>cfg/config.php</code></li>
				<li>Lock this install wizard</li>
			</ul>
			<p class="hint warn">To reinstall later, delete <code>install/.installed</code> and <code>cfg/config.php</code>.</p>
			<div class="btn-row">
				<button class="btn-secondary" id="btn-back-4">Back</button>
				<button class="btn-success"   id="btn-install">Install new-cart</button>
			</div>
		</div>

		<!-- ── Done ── -->
		<div id="step-done">
			<div class="checkmark">✅</div>
			<h2>Installation Complete</h2>
			<p>Your store is ready. Log in to the admin panel to get started.</p>
			<button class="btn-success" id="btn-go-admin">Go to Admin</button>
		</div>

	</div><!-- /wizard-body -->

</div><!-- /wizard -->

<script>
(function () {
	'use strict';

	const AJAX = 'ajax.php';

	function val(id)  { return document.getElementById(id).value.trim(); }
	function pval(id) { return document.getElementById(id).value; }
	function el(id)   { return document.getElementById(id); }

	function setStatus(msg, type) {
		const s = el('status');
		s.textContent   = msg;
		s.className     = type;
		s.style.display = msg ? 'block' : 'none';
	}

	function setInline(id, msg, type) {
		const s = el(id);
		s.textContent   = msg;
		s.className     = 'inline-status ' + type;
		s.style.display = msg ? 'block' : 'none';
	}

	function clearStatus() { setStatus('', ''); }

	function setLoading(btn, on) {
		if (on) {
			btn._orig     = btn.innerHTML;
			btn.innerHTML = '<span class="spinner"></span>Working\u2026';
			btn.disabled  = true;
		} else {
			btn.innerHTML = btn._orig;
		}
	}

	async function ajax(data) {
		const fd = new FormData();
		Object.entries(data).forEach(([k, v]) => fd.append(k, v));
		const r = await fetch(AJAX, { method: 'POST', body: fd });
		return r.json();
	}

	// ── Tab navigation ────────────────────────────────────────────────────────
	function goToStep(n) {
		document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
		document.querySelectorAll('.step-tab').forEach(t => t.classList.remove('active'));
		const step = el('step-' + n);
		const tab  = el('tab-'  + n);
		if (step) step.classList.add('active');
		if (tab)  tab.classList.add('active');
		clearStatus();
	}

	function markComplete(n) {
		const t = el('tab-' + n);
		if (t) { t.classList.remove('active'); t.classList.add('complete'); }
	}

	function goBack(fromStep) {
		const n = fromStep - 1;
		document.querySelectorAll('.step-tab').forEach(t => t.classList.remove('active'));
		const prevTab = el('tab-' + n);
		if (prevTab) { prevTab.classList.remove('complete'); prevTab.classList.add('active'); }
		const curTab = el('tab-' + fromStep);
		if (curTab)  curTab.classList.remove('active', 'complete');
		document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
		const step = el('step-' + n);
		if (step) step.classList.add('active');
		clearStatus();
	}

	// ── Permissions check on page load ────────────────────────────────────────
	const banner = el('perm-banner');
	const body   = el('wizard-body');

	function escH(str) {
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
	}

	async function checkPermissions() {
		banner.className = 'checking';
		banner.innerHTML = '<span class="perm-msg">Checking directory permissions\u2026</span>';
		body.classList.add('locked');

		try {
			const res = await ajax({ action: 'check_permissions' });

			if (res.ok) {
				// Build green list
				let listHtml = '<ul id="perm-dir-list">';
				(res.details || []).forEach(d => {
					const label = d.split(':')[0];
					listHtml += `<li><span class="icon-ok">&#10003;</span> ${escH(label)}/</li>`;
				});
				listHtml += '</ul>';

				banner.className = 'ok';
				banner.innerHTML =
					'<strong>Directory permissions OK</strong>' + listHtml;
				body.classList.remove('locked');

			} else {
				// Build mixed ok/fail list from details
				let listHtml = '<ul id="perm-dir-list">';
				(res.details || []).forEach(d => {
					const label  = d.split(':')[0];
					const isOk   = d.includes(': OK');
					const icon   = isOk
						? '<span class="icon-ok">&#10003;</span>'
						: '<span class="icon-fail">&#10007;</span>';
					const path   = !isOk && res.base ? escH(res.base + label) : '';
					const pathEl = path ? `<span class="dir-path">${path}</span>` : '';
					listHtml += `<li>${icon} ${escH(label)}/ ${pathEl}</li>`;
				});
				listHtml += '</ul>';

				const fixCmd = res.base
					? `sudo chown -R www-data:william ${res.base}\nsudo chmod -R 775 ${res.base}`
					: '';

				const linuxBlock = fixCmd
					? '<div style="font-size:.82rem;font-weight:600;margin:.75rem 0 .3rem">Linux / Mac (terminal):</div>' +
					  '<div id="perm-fix-cmd">' + escH(fixCmd) + '</div>' +
					  '<div style="margin-bottom:.75rem"><button class="btn-outline btn-sm" id="btn-copy-fix">Copy Command</button>' +
					  ' <span id="copy-ok" style="font-size:.78rem;color:#14532d;display:none">&#10003; Copied</span></div>'
					: '';

				banner.className = 'error';
				banner.innerHTML =
					'<strong>Some directories are not writable by the web server.</strong>' +
					listHtml +
					'<p style="font-size:.85rem;margin-bottom:.75rem;line-height:1.6">' +
					'Your web server needs write access to the directories marked above. ' +
					'How to fix this depends on your hosting environment — if you\'re unsure, contact your hosting provider or consult your control panel\'s file permissions settings.' +
					'</p>' +
					linuxBlock +
					'<button class="btn-outline btn-sm" id="btn-recheck">Recheck Permissions</button>';

				el('btn-recheck').addEventListener('click', checkPermissions);
				if (fixCmd) {
					el('btn-copy-fix').addEventListener('click', function () {
						navigator.clipboard.writeText(fixCmd).then(() => {
							const ok = el('copy-ok');
							ok.style.display = 'inline';
							setTimeout(() => { ok.style.display = 'none'; }, 2000);
						});
					});
				}
			}
		} catch (e) {
			banner.className = 'error';
			banner.innerHTML =
				'<strong>Permission check failed:</strong> ' + escH(e.message) +
				'<div style="margin-top:.75rem"><button class="btn-outline btn-sm" id="btn-recheck">Recheck</button></div>';
			el('btn-recheck').addEventListener('click', checkPermissions);
		}
	}

	checkPermissions();

	// ── Step 1: field validation ──────────────────────────────────────────────
	let credentialsVerified = false;

	function step1Check() {
		el('btn-test-root').disabled  = !(val('db_host') && val('db_root'));
		el('btn-step1-next').disabled = !credentialsVerified;
	}

	['db_host', 'db_root', 'db_rootpw'].forEach(id => {
		el(id).addEventListener('input', () => {
			if (credentialsVerified) {
				credentialsVerified = false;
				el('cred-ok').classList.remove('show');
				el('db_name_create').disabled = true;
				el('btn-create-db').disabled  = true;
				setInline('test-root-status', '', '');
			}
			step1Check();
		});
	});

	step1Check();

	// ── Step 1: Test credentials ──────────────────────────────────────────────
	el('btn-test-root').addEventListener('click', async function () {
		setInline('test-root-status', '', '');
		setLoading(this, true);
		try {
			const res = await ajax({
				action:    'test_root',
				db_host:   val('db_host'),
				db_root:   val('db_root'),
				db_rootpw: pval('db_rootpw'),
			});
			setInline('test-root-status', res.message, res.ok ? 'ok' : 'error');
			if (res.ok) {
				credentialsVerified = true;
				el('cred-ok').classList.add('show');
				el('db_name_create').disabled = false;
				el('btn-create-db').disabled  = false;
				el('btn-step1-next').disabled = false;
			}
		} catch (e) {
			setInline('test-root-status', 'Request failed: ' + e.message, 'error');
		}
		setLoading(this, false);
	});

	// ── Step 1: Create database ───────────────────────────────────────────────
	el('btn-create-db').addEventListener('click', async function () {
		setInline('create-db-status', '', '');
		const dbName = val('db_name_create');
		if (!dbName) { setInline('create-db-status', 'Enter a database name first.', 'error'); return; }
		setLoading(this, true);
		try {
			const res = await ajax({
				action:    'create_db',
				db_host:   val('db_host'),
				db_name:   dbName,
				db_root:   val('db_root'),
				db_rootpw: pval('db_rootpw'),
			});
			setInline('create-db-status', res.message, res.ok ? 'ok' : 'error');
			if (res.ok) el('db_name').value = dbName;
		} catch (e) {
			setInline('create-db-status', 'Request failed: ' + e.message, 'error');
		}
		setLoading(this, false);
	});

	// ── Step 1: Continue ──────────────────────────────────────────────────────
	el('btn-step1-next').addEventListener('click', function () {
		markComplete(1);
		banner.style.display = 'none';
		goToStep(2);
	});

	// ── Step 2: field validation ──────────────────────────────────────────────
	function step2Check() {
		el('btn-create-user').disabled = !(val('db_user') && pval('db_pass') && val('db_name'));
	}

	['db_user', 'db_pass', 'db_name', 'db_prefix'].forEach(id => {
		el(id).addEventListener('input', step2Check);
	});

	step2Check();

	// ── Step 2: Create user & continue ───────────────────────────────────────
	el('btn-create-user').addEventListener('click', async function () {
		clearStatus();
		setLoading(this, true);
		try {
			const res = await ajax({
				action:    'create_user',
				db_host:   val('db_host'),
				db_name:   val('db_name'),
				db_root:   val('db_root'),
				db_rootpw: pval('db_rootpw'),
				db_user:   val('db_user'),
				db_pass:   pval('db_pass'),
				db_prefix: val('db_prefix'),
			});
			if (res.ok) {
				setStatus(res.message, 'ok');
				markComplete(2);
				setTimeout(() => goToStep(3), 800);
			} else {
				setStatus(res.message, 'error');
				if (res.prefix_conflict) {
					el('db_prefix').focus();
					el('db_prefix').select();
				}
			}
		} catch (e) {
			setStatus('Request failed: ' + e.message, 'error');
		}
		setLoading(this, false);
	});

	// ── Step 3: password match + field validation ─────────────────────────────
	function step3Check() {
		const user    = val('admin_user');
		const email   = val('admin_email');
		const pass    = pval('admin_pass');
		const confirm = pval('admin_confirm');
		const msgEl   = el('pw-match-msg');

		let pwOk = false;
		if (pass && confirm) {
			if (pass === confirm) {
				msgEl.textContent = '\u2713 Passwords match';
				msgEl.className   = 'match-ok';
				pwOk = true;
			} else {
				msgEl.textContent = '\u2717 Passwords do not match';
				msgEl.className   = 'match-fail';
			}
		} else {
			msgEl.textContent = '';
			msgEl.className   = '';
		}

		el('btn-validate-admin').disabled = !(user.length >= 3 && email && pass.length >= 8 && pwOk);
	}

	['admin_user', 'admin_email', 'admin_pass', 'admin_confirm'].forEach(id => {
		el(id).addEventListener('input', step3Check);
	});

	step3Check();

	// ── Step 3: Validate admin & continue ─────────────────────────────────────
	el('btn-validate-admin').addEventListener('click', async function () {
		clearStatus();
		setLoading(this, true);
		try {
			const res = await ajax({
				action:        'validate_admin',
				admin_user:    val('admin_user'),
				admin_email:   val('admin_email'),
				admin_pass:    pval('admin_pass'),
				admin_confirm: pval('admin_confirm'),
			});
			if (res.ok) {
				markComplete(3);
				setTimeout(() => goToStep(4), 300);
			} else {
				setStatus(res.message, 'error');
			}
		} catch (e) {
			setStatus('Request failed: ' + e.message, 'error');
		}
		setLoading(this, false);
	});

	// ── Step 4: field validation + admin path preview ─────────────────────────
	function step4Check() {
		el('btn-to-install').disabled = !val('site_name');
	}

	el('site_name').addEventListener('input', step4Check);
	el('admin_path').addEventListener('input', function () {
		el('admin-path-preview').textContent = this.value || 'admin';
		step4Check();
	});

	step4Check();

	// ── Step 4: Continue ──────────────────────────────────────────────────────
	el('btn-to-install').addEventListener('click', function () {
		markComplete(4);
		goToStep(5);
	});

	// ── Step 5: Install ───────────────────────────────────────────────────────
	el('btn-install').addEventListener('click', async function () {
		clearStatus();
		setLoading(this, true);
		try {
			const res = await ajax({
				action:        'install',
				db_host:       val('db_host'),
				db_name:       val('db_name'),
				db_user:       val('db_user'),
				db_pass:       pval('db_pass'),
				db_prefix:     val('db_prefix'),
				admin_user:    val('admin_user'),
				admin_email:   val('admin_email'),
				admin_pass:    pval('admin_pass'),
				site_name:     val('site_name'),
				site_currency: val('site_currency'),
				site_email:    val('site_email'),
				admin_path:    val('admin_path'),
			});
			if (res.ok) {
				markComplete(5);
				document.querySelectorAll('.step').forEach(s => s.style.display = 'none');
				el('step-nav').style.display  = 'none';
				el('status').style.display    = 'none';
				el('step-done').style.display = 'block';
				el('btn-go-admin').dataset.href = res.redirect || '/admin/';
			} else {
				setStatus(res.message, 'error');
			}
		} catch (e) {
			setStatus('Request failed: ' + e.message, 'error');
		}
		setLoading(this, false);
	});

	// ── Done ──────────────────────────────────────────────────────────────────
	el('btn-go-admin').addEventListener('click', function () {
		window.location.href = this.dataset.href || '/admin/';
	});

	// ── Back buttons ──────────────────────────────────────────────────────────
	el('btn-back-1').addEventListener('click', () => goBack(2));
	el('btn-back-2').addEventListener('click', () => goBack(3));
	el('btn-back-3').addEventListener('click', () => goBack(4));
	el('btn-back-4').addEventListener('click', () => goBack(5));

})();
</script>

</body>
</html>
