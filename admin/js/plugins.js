/* global SimpleNotification */
(function () {
	'use strict';

	const AJAX = NC.adminUrl + '?route=plugins/ajax';

	const tbody    = document.getElementById('plugin-tbody');
	const progress = document.getElementById('upload-progress');
	const progMsg  = document.getElementById('upload-progress-msg');
	const fileInput = document.getElementById('plugin-file-input');
	const dropZone  = document.getElementById('plugin-upload-zone');

	// ── Ajax ──────────────────────────────────────────────────────────────────
	async function ajax(data) {
		const fd = new FormData();
		Object.entries(data).forEach(([k, v]) => fd.append(k, v));
		const r = await fetch(AJAX, { method: 'POST', body: fd });
		return r.json();
	}

	function notifyOk(msg)  { SimpleNotification.success({ text: msg }); }
	function notifyErr(msg) { SimpleNotification.error({ text: msg }); }

	// ── Load plugins ──────────────────────────────────────────────────────────
	async function loadPlugins() {
		const res = await ajax({ action: 'list' });
		if (!res.ok) { notifyErr(res.message); return; }
		renderPlugins(res.plugins);
	}

	function renderPlugins(plugins) {
		tbody.innerHTML = '';
		if (!plugins || !plugins.length) {
			tbody.innerHTML = '<tr><td colspan="5"><div class="nc-empty">No plugins installed.</div></td></tr>';
			return;
		}
		plugins.forEach(p => tbody.appendChild(buildRow(p)));
	}

	function buildRow(p) {
		const tr = document.createElement('tr');
		tr.dataset.code = p.code;
		const settingsBtn = (p.has_settings && p.enabled)
			? '<a class="btn btn-secondary btn-sm plugin-settings-btn" ' +
			  'href="' + NC.adminUrl + '?route=setup#sub-integrations" ' +
			  'aria-label="Settings for ' + esc(p.name) + '">&#9881; Settings</a>'
			: '';
		tr.innerHTML =
			'<td>' +
				'<div class="plugin-name">' + esc(p.name) + '</div>' +
				'<div class="plugin-code">' + esc(p.code) + ' v' + esc(p.version) + '</div>' +
				(p.description ? '<div class="plugin-author">' + esc(p.description) + '</div>' : '') +
			'</td>' +
			'<td class="plugin-author">' + esc(p.author) + '</td>' +
			'<td class="plugin-link">' + (p.link ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener">' + esc(p.link) + '</a>' : '—') + '</td>' +
			'<td class="col-settings">' + settingsBtn + '</td>' +
			'<td class="col-toggle">' +
				'<ios-toggle ' + (p.enabled ? 'checked' : '') + ' data-code="' + esc(p.code) + '" data-action="toggle" size="sm"></ios-toggle>' +
			'</td>' +
			'<td class="col-delete">' +
				'<delete-in-place caption="&#128465;" confirm="Remove plugin?" data-code="' + esc(p.code) + '"></delete-in-place>' +
			'</td>';
		return tr;
	}

	// ── Toggle enable/disable ─────────────────────────────────────────────────
	document.addEventListener('ios-toggle', async function (e) {
		const src = e.detail.source;
		if (src.dataset.action !== 'toggle') return;
		const code   = src.dataset.code;
		const action = e.detail.checked ? 'enable' : 'disable';
		const res    = await ajax({ action, code });
		if (!res.ok) { notifyErr(res.message); loadPlugins(); return; }
		notifyOk(res.message);
	});

	// ── Remove ────────────────────────────────────────────────────────────────
	tbody.addEventListener('dip-confirm', async function (e) {
		const code = e.detail.code;
		const res  = await ajax({ action: 'remove', code });
		if (!res.ok) { notifyErr(res.message); return; }
		const tr = tbody.querySelector('tr[data-code="' + code + '"]');
		if (tr) tr.remove();
		if (!tbody.querySelector('tr[data-code]')) {
			tbody.innerHTML = '<tr><td colspan="5"><div class="nc-empty">No plugins installed.</div></td></tr>';
		}
		notifyOk(res.message);
	});

	// ── Upload ────────────────────────────────────────────────────────────────
	async function installFile(file) {
		if (!file.name.endsWith('.zip')) {
			notifyErr('Plugin must be a .zip file.');
			return;
		}
		progress.classList.add('show');
		progMsg.textContent = 'Installing ' + file.name + '…';

		const fd = new FormData();
		fd.append('action', 'install');
		fd.append('file', file);

		try {
			const r   = await fetch(AJAX, { method: 'POST', body: fd });
			const res = await r.json();
			progress.classList.remove('show');
			if (!res.ok) { notifyErr(res.message); return; }
			notifyOk(res.message);
			loadPlugins();
		} catch (e) {
			progress.classList.remove('show');
			notifyErr('Upload failed: ' + e.message);
		}
		fileInput.value = '';
	}

	// ── Drop zone ─────────────────────────────────────────────────────────────
	dropZone.addEventListener('click', () => fileInput.click());

	fileInput.addEventListener('change', function () {
		if (fileInput.files[0]) installFile(fileInput.files[0]);
	});

	let dragCount = 0;
	dropZone.addEventListener('dragenter', function (e) {
		e.preventDefault(); dragCount++;
		this.classList.add('drag-over');
	});
	dropZone.addEventListener('dragleave', function () {
		dragCount--;
		if (dragCount <= 0) { dragCount = 0; this.classList.remove('drag-over'); }
	});
	dropZone.addEventListener('dragover', e => e.preventDefault());
	dropZone.addEventListener('drop', function (e) {
		e.preventDefault(); dragCount = 0;
		this.classList.remove('drag-over');
		const file = e.dataTransfer.files[0];
		if (file) installFile(file);
	});

	// ── Notice dismiss ────────────────────────────────────────────────────────
	const dismiss = document.getElementById('notice-dismiss');
	if (dismiss) {
		dismiss.addEventListener('click', function () {
			const notice = document.getElementById('plugin-notice');
			if (notice) notice.style.display = 'none';
			sessionStorage.setItem('nc_plugin_notice_dismissed', '1');
		});
		if (sessionStorage.getItem('nc_plugin_notice_dismissed') === '1') {
			const notice = document.getElementById('plugin-notice');
			if (notice) notice.style.display = 'none';
		}
	}

	function esc(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	loadPlugins();

})();
