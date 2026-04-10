/* global SimpleNotification, jQuery */
(function () {
	'use strict';

	const AJAX     = NC.adminUrl + '?route=categories/ajax';
	const URL_ROOT = NC.rootUrl;

	const STATUS_LABELS = { '0': 'Not Active', '1': 'Active', '2': 'Browse Only' };
	const STATUS_CLASS  = { '0': 'status-0',   '1': 'status-1',  '2': 'status-2' };

	const tbody    = document.getElementById('cat-tbody');
	const emptyRow = document.getElementById('cat-empty-row');
	const drawer   = document.getElementById('cat-drawer');
	const overlay  = document.getElementById('drawer-overlay');
	const chkAll   = document.getElementById('chk-all');
	const btnBulk  = document.getElementById('btn-bulk-delete');

	// ── Ajax ──────────────────────────────────────────────────────────────────
	async function ajax(data) {
		const fd = new FormData();
		Object.entries(data).forEach(([k, v]) => fd.append(k, v));
		const r = await fetch(AJAX, { method: 'POST', body: fd });
		return r.json();
	}

	function notifyOk(msg)  { SimpleNotification.success({ text: msg }); }
	function notifyErr(msg) { SimpleNotification.error({ text: msg }); }

	// ── Load ──────────────────────────────────────────────────────────────────
	async function loadCategories() {
		const res = await ajax({ action: 'list' });
		if (!res.ok) { notifyErr(res.message); return; }
		renderRows(res.rows);
	}

	function renderRows(rows) {
		Array.from(tbody.querySelectorAll('tr[data-id]')).forEach(r => r.remove());
		if (!rows || !rows.length) { emptyRow.style.display = ''; return; }
		emptyRow.style.display = 'none';
		rows.forEach(row => tbody.appendChild(buildRow(row)));
		initDragDrop();
		updateBulkBtn();
	}

	function buildRow(d) {
		const tr  = document.createElement('tr');
		tr.dataset.id = d.id;

		const statusLabel = STATUS_LABELS[String(d.status)] || 'Unknown';
		const statusClass = STATUS_CLASS[String(d.status)]  || '';

		const inactive = d.status == 0;

		const incomplete = NC.incompleteCats && NC.incompleteCats.indexOf(parseInt(d.id)) !== -1;
		const incBadge   = incomplete
			? ' <span class="cat-incomplete-badge" title="Needs more information">&#43;</span>'
			: '';

		tr.innerHTML =
			'<td class="col-drag"><span class="drag-handle" title="Drag to reorder">&#8942;</span></td>' +
			'<td><button class="cat-name-link" data-id="' + d.id + '">' + esc(d.name) + '</button>' + incBadge + '</td>' +
			'<td class="parent-cell">' + (d.parent_name ? esc(d.parent_name) : '&mdash;') + '</td>' +
			'<td class="col-status">' +
				'<roller-select data-id="' + d.id + '" data-field="status" value="' + d.status + '">' +
					'<rs-item value="0">Not Active</rs-item>' +
					'<rs-item value="1">Active</rs-item>' +
					'<rs-item value="2">Browse Only</rs-item>' +
				'</roller-select>' +
			'</td>' +
			'<td class="col-toggle">' +
				'<ios-toggle ' + (d.featured == 1 ? 'checked' : '') + ' size="sm" data-id="' + d.id + '" data-field="featured"></ios-toggle>' +
			'</td>' +
			'<td class="col-browse">' +
				'<a href="' + URL_ROOT + 'category/' + esc(d.slug) + '" target="_blank" rel="noopener" class="browse-link" title="Browse category">' +
					'<svg width="14" height="14" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
						'<path d="M7 1h4v4M11 1L5 7"/><path d="M9 7v4H1V3h4"/>' +
					'</svg>' +
				'</a>' +
			'</td>' +
			'<td class="col-delete">' +
				'<span class="delete-fade' + (inactive ? ' show' : '') + '" id="cat-del-' + d.id + '">' +
				'<delete-in-place caption="&#128465;" confirm="Delete?" data-id="' + d.id + '"></delete-in-place>' +
				'</span>' +
			'</td>' +
			'<td class="col-check">' +
				'<input type="checkbox" class="row-chk" data-id="' + d.id + '"' + (inactive ? '' : ' disabled') + '>' +
			'</td>';

		return tr;
	}

	function updateRow(d) {
		const tr = tbody.querySelector('tr[data-id="' + d.id + '"]');
		if (!tr) {
			emptyRow.style.display = 'none';
			tbody.appendChild(buildRow(d));
			initDragDrop();
		} else {
			tbody.replaceChild(buildRow(d), tr);
		}
		updateBulkBtn();
	}

	// ── Drawer ────────────────────────────────────────────────────────────────
	function openDrawer(title) {
		document.getElementById('drawer-title').textContent = title;
		drawer.classList.add('open');
		overlay.classList.add('show');

		// Init Trumbowyg after drawer is visible
		setTimeout(function () {
			if (!window._trumbInitDone && window.jQuery && jQuery.fn.trumbowyg) {
				jQuery('#cat-html-long').trumbowyg({
					svgPath: '/js/vendor/trumbowyg/src/ui/icons.svg',
					btns: [
						['bold', 'italic', 'underline'],
						['link'],
						['insertImage'],
						['unorderedList', 'orderedList'],
						['indent', 'outdent'],
						['viewHTML']
					]
				});
				window._trumbInitDone = true;
			}
		}, 50);
	}

	function closeDrawer() {
		drawer.classList.remove('open');
		overlay.classList.remove('show');
	}

	function resetDrawer() {
		document.getElementById('cat-id').value   = '';
		document.getElementById('cat-name').value = '';
		document.getElementById('cat-seo').value  = '';
		if (window._trumbInitDone) jQuery('#cat-html-long').trumbowyg('html', '');
		else document.getElementById('cat-html-long').value = '';
		const rs = document.getElementById('cat-status');
		if (rs) rs.value = '1';
		const ft = document.getElementById('cat-featured');
		if (ft) ft.checked = false;
	}

	let seoTitleDirty = false;
	let topFourCats   = [];

	document.getElementById('cat-seo').addEventListener('input', function () {
		seoTitleDirty = true;
	});

	document.getElementById('cat-name').addEventListener('input', function () {
		if (seoTitleDirty) return;
		document.getElementById('cat-seo').value = buildSeoTitle(this.value.trim());
	});

	function buildSeoTitle(catName) {
		if (!catName) return '';
		const parts = [catName, NC.siteName];
		if (topFourCats.length) parts.push(topFourCats.join(', '));
		return parts.join(' \u2014 ');
	}

	async function populateParents(excludeId) {
		const res = await ajax({ action: 'parents' });
		const sel = document.getElementById('cat-parent');
		sel.innerHTML = '<option value="0">\u2014 Top Level \u2014</option>';
		(res.parents || []).forEach(function (p) {
			if (excludeId && p.id == excludeId) return;
			const opt = document.createElement('option');
			opt.value = p.id;
			opt.textContent = p.name;
			sel.appendChild(opt);
		});
		// Update top four for SEO default
		topFourCats = res.top_four || [];
	}

	async function addCategory() {
		await populateParents(0);
		seoTitleDirty = false;
		resetDrawer();
		openDrawer('Add Category');
		document.getElementById('cat-name').focus();
	}

	document.getElementById('btn-add-category').addEventListener('click', addCategory);
	document.getElementById('btn-add-first').addEventListener('click', addCategory);

	// ── Edit ──────────────────────────────────────────────────────────────────
	tbody.addEventListener('click', async function (e) {
		const btn = e.target.closest('.cat-name-link');
		if (!btn) return;
		const res = await ajax({ action: 'get', id: btn.dataset.id });
		if (!res.ok) { notifyErr(res.message); return; }
		const d = res.row;

		await populateParents(d.id);
		document.getElementById('cat-id').value     = d.id;
		document.getElementById('cat-name').value   = d.name;
		document.getElementById('cat-seo').value    = d.seo_title || '';
		document.getElementById('cat-parent').value = d.parent_id || 0;
		seoTitleDirty = !!(d.seo_title && d.seo_title.trim());

		if (window._trumbInitDone) jQuery('#cat-html-long').trumbowyg('html', d.html_long || '');
		else document.getElementById('cat-html-long').value = d.html_long || '';

		const rs = document.getElementById('cat-status');
		if (rs) rs.value = String(d.status);
		const ft = document.getElementById('cat-featured');
		if (ft) ft.checked = d.featured == 1;

		openDrawer('Edit Category');
	});

	// ── Save ──────────────────────────────────────────────────────────────────
	document.getElementById('btn-drawer-save').addEventListener('click', async function () {
		const nameEl = document.getElementById('cat-name');
		if (!nameEl.reportValidity()) return;
		const name = nameEl.value.trim();

		const htmlLong = window._trumbInitDone
			? jQuery('#cat-html-long').trumbowyg('html')
			: document.getElementById('cat-html-long').value;

		const rs = document.getElementById('cat-status');
		const ft = document.getElementById('cat-featured');

		this.disabled = true;
		const res = await ajax({
			action:    'save',
			id:        document.getElementById('cat-id').value,
			name:      name,
			parent_id: document.getElementById('cat-parent').value,
			seo_title: document.getElementById('cat-seo').value,
			html_long: htmlLong,
			status:    rs ? rs.value : '1',
			featured:  ft ? (ft.checked ? 1 : 0) : 0,
		});
		this.disabled = false;

		if (!res.ok) { notifyErr(res.message); return; }
		notifyOk(res.message);

		// Remove from incomplete list if it was there
		if (res.cleared_reminder && NC.incompleteCats) {
			const idx = NC.incompleteCats.indexOf(parseInt(res.cleared_reminder));
			if (idx !== -1) NC.incompleteCats.splice(idx, 1);
		}

		updateRow(res.row);
		closeDrawer();
	});

	document.getElementById('drawer-close').addEventListener('click', closeDrawer);
	document.getElementById('btn-drawer-cancel').addEventListener('click', closeDrawer);
	overlay.addEventListener('click', closeDrawer);

	// ── Debounce helper ───────────────────────────────────────────────────────
	function debounce(fn, ms) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(null, args); }, ms);
		};
	}

	var debouncedToggle = debounce(function (id, field, value) {
		ajax({ action: 'toggle', id: id, field: field, value: value });
	}, 1000);

	// ── Inline roller-select (status) ─────────────────────────────────────────
	document.addEventListener('roller-change', function (e) {
		const src = e.detail.source;
		if (!src.dataset.id || !src.dataset.field) return;

		// Show/hide delete and enable/disable checkbox based on status
		if (src.dataset.field === 'status') {
			const id       = src.dataset.id;
			const inactive = e.detail.value == 0;
			const delSpan  = document.getElementById('cat-del-' + id);
			const chk      = tbody.querySelector('tr[data-id="' + id + '"] .row-chk');
			if (delSpan) delSpan.classList.toggle('show', inactive);
			if (chk)     chk.disabled = !inactive;
		}

		debouncedToggle(src.dataset.id, src.dataset.field, e.detail.value);
	});

	// ── Inline ios-toggle (featured) ──────────────────────────────────────────
	document.addEventListener('ios-toggle', function (e) {
		const src = e.detail.source;
		if (!src.dataset.id || !src.dataset.field) return;
		debouncedToggle(src.dataset.id, src.dataset.field, e.detail.value);
	});

	// ── Delete single ─────────────────────────────────────────────────────────
	tbody.addEventListener('dip-confirm', async function (e) {
		const id  = e.detail.id;
		const res = await ajax({ action: 'delete', id: id });
		if (!res.ok) { notifyErr(res.message); return; }
		const tr = tbody.querySelector('tr[data-id="' + id + '"]');
		if (tr) tr.remove();
		if (!tbody.querySelector('tr[data-id]')) emptyRow.style.display = '';
		updateBulkBtn();
		notifyOk(res.message);
	});

	// ── Bulk delete ───────────────────────────────────────────────────────────
	chkAll.addEventListener('change', function () {
		tbody.querySelectorAll('.row-chk').forEach(function (c) { c.checked = chkAll.checked; });
		updateBulkBtn();
	});

	tbody.addEventListener('change', function (e) {
		if (e.target.classList.contains('row-chk')) updateBulkBtn();
	});

	function updateBulkBtn() {
		const n = tbody.querySelectorAll('.row-chk:checked').length;
		btnBulk.disabled    = n === 0;
		btnBulk.textContent = n > 0 ? 'Delete Selected (' + n + ')' : 'Delete Selected';
	}

	btnBulk.addEventListener('click', async function () {
		const ids = Array.from(tbody.querySelectorAll('.row-chk:checked')).map(function (c) { return c.dataset.id; });
		if (!ids.length) return;
		this.disabled = true;
		const res = await ajax({ action: 'bulk_delete', ids: JSON.stringify(ids) });
		this.disabled = false;
		if (!res.ok) { notifyErr(res.message); return; }
		ids.forEach(function (id) {
			const tr = tbody.querySelector('tr[data-id="' + id + '"]');
			if (tr) tr.remove();
		});
		if (!tbody.querySelector('tr[data-id]')) emptyRow.style.display = '';
		chkAll.checked = false;
		updateBulkBtn();
		notifyOk(res.message);
	});

	// ── Drag-drop reorder — delegated, no duplicate listeners ─────────────────
	var dragSrc = null;

	// Make rows draggable only via handle mousedown
	tbody.addEventListener('mousedown', function (e) {
		var handle = e.target.closest('.drag-handle');
		if (!handle) return;
		var tr = handle.closest('tr[data-id]');
		if (tr) tr.setAttribute('draggable', 'true');
	});

	tbody.addEventListener('mouseup', function () {
		tbody.querySelectorAll('tr[data-id]').forEach(function (tr) {
			tr.setAttribute('draggable', 'false');
		});
	});

	tbody.addEventListener('dragstart', function (e) {
		var tr = e.target.closest('tr[data-id]');
		if (!tr) return;
		dragSrc = tr;
		tr.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'move';
	});

	tbody.addEventListener('dragend', function () {
		if (dragSrc) dragSrc.classList.remove('dragging');
		tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('drag-over'); });
		tbody.querySelectorAll('tr[data-id]').forEach(function (tr) { tr.setAttribute('draggable', 'false'); });
		dragSrc = null;
		saveOrder();
	});

	tbody.addEventListener('dragover', function (e) {
		e.preventDefault();
		var tr = e.target.closest('tr[data-id]');
		tbody.querySelectorAll('tr').forEach(function (r) { r.classList.remove('drag-over'); });
		if (tr && tr !== dragSrc) tr.classList.add('drag-over');
	});

	tbody.addEventListener('drop', function (e) {
		e.preventDefault();
		var tr = e.target.closest('tr[data-id]');
		if (!tr || tr === dragSrc || !dragSrc) return;
		var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
		var from = rows.indexOf(dragSrc);
		var to   = rows.indexOf(tr);
		if (from < to) tbody.insertBefore(dragSrc, tr.nextSibling);
		else           tbody.insertBefore(dragSrc, tr);
	});

	function initDragDrop() { /* delegated — no per-row setup needed */ }

	async function saveOrder() {
		var ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(function (r) { return r.dataset.id; });
		await ajax({ action: 'reorder', ids: JSON.stringify(ids) });
	}

	function esc(str) {
		return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	loadCategories();

})();
