/* global SimpleNotification, jQuery */
(function () {
	'use strict';

	const AJAX     = NC.adminUrl + '?route=products/ajax';
	const ROW_TPL  = '/admin/tpl/products/row.html';

	const tbody    = document.getElementById('prod-tbody');
	const emptyRow = document.getElementById('prod-empty-row');
	const drawer   = document.getElementById('prod-drawer');
	const overlay  = document.getElementById('drawer-overlay');
	const chkAll   = document.getElementById('chk-all');
	const btnBulk  = document.getElementById('btn-bulk-delete');

	let allCategories = [];
	let rowTemplate   = null;
	let currentProdId = null;

	// ── Ajax ──────────────────────────────────────────────────────────────────
	async function post(data) {
		const fd = new FormData();
		Object.entries(data).forEach(([k, v]) => fd.append(k, v));
		const r = await fetch(AJAX, { method: 'POST', body: fd });
		return r.json();
	}

	async function postFile(data, files) {
		const fd = new FormData();
		Object.entries(data).forEach(([k, v]) => fd.append(k, v));
		if (files) Object.entries(files).forEach(([k, v]) => { if (v) fd.append(k, v); });
		const r = await fetch(AJAX, { method: 'POST', body: fd });
		return r.json();
	}

	function notifyOk(msg)  { SimpleNotification.success({ text: msg }); }
	function notifyErr(msg) { SimpleNotification.error({ text: msg }); }

	function debounce(fn, ms) {
		var t;
		return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); };
	}

	// ── ios-toggle value helpers ──────────────────────────────────────────────
	function togVal(id) {
		var h = document.getElementById('_nc_' + id);
		if (h) return parseInt(h.value || 0) ? 1 : 0;
		var t = document.querySelector('ios-toggle[name="' + id + '"]');
		if (t) { var cb = t.querySelector('input[type=checkbox]'); return cb && cb.checked ? 1 : 0; }
		return 0;
	}
	function setTogVal(id, v) {
		var label = document.getElementById(id);
		if (!label) return;
		var tog = label.closest('ios-toggle');
		if (!tog) return;
		var cb = tog.querySelector('input[type=checkbox]');
		var h  = tog.querySelector('input[type=hidden]');
		var on = !!parseInt(v);
		if (cb) cb.checked  = on;
		if (h)  h.value     = on ? 1 : 0;
	}

	// ── Load row template ─────────────────────────────────────────────────────
	async function fetchRowTemplate() {
		const r = await fetch(ROW_TPL + '?v=' + Date.now());
		rowTemplate = await r.text();
	}

	function buildRow(d) {
		if (!rowTemplate) return null;
		const price     = parseFloat(d.price     || 0).toFixed(2);
		const listPrice = parseFloat(d.list_price || 0).toFixed(2);
		const stock     = parseInt(d.stock || 0);
		const inactive  = d.status == 0;

		const html = rowTemplate
			.replace(/{{id}}/g,              esc(d.id))
			.replace(/{{name}}/g,            esc(d.name))
			.replace(/{{sku_html}}/g,        d.sku ? '<div class="prod-sku">' + esc(d.sku) + '</div>' : '')
			.replace(/{{categories}}/g,      esc(d.categories || '—'))
			.replace(/{{price}}/g,           '$' + price)
			.replace(/{{list_price}}/g,      '$' + listPrice)
			.replace(/{{price_raw}}/g,       price)
			.replace(/{{list_price_raw}}/g,  listPrice)
			.replace(/{{stock}}/g,           stock)
			.replace(/{{stock_class}}/g,     stockClass(stock))
			.replace(/{{status_checked}}/g,  d.status   == 1 ? 'checked' : '')
			.replace(/{{featured_checked}}/g,d.featured  == 1 ? 'checked' : '')
			.replace(/{{delete_show}}/g,     inactive ? 'show' : '')
			.replace(/{{chk_disabled}}/g,    inactive ? '' : 'disabled');

		const tr = document.createElement('tr');
		tr.dataset.id = d.id;
		tr.innerHTML  = html;
		return tr;
	}

	function stockClass(stock) {
		if (stock <= 0) return 'stock-zero';
		if (stock <= 5) return 'stock-low';
		return 'stock-ok';
	}

	function esc(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Render ────────────────────────────────────────────────────────────────
	function renderRows(rows) {
		Array.from(tbody.querySelectorAll('tr[data-id]')).forEach(r => r.remove());
		if (!rows || !rows.length) { emptyRow.style.display = ''; return; }
		emptyRow.style.display = 'none';
		rows.forEach(r => { const tr = buildRow(r); if (tr) tbody.appendChild(tr); });
		updateBulkBtn();
	}

	function updateRow(d) {
		const tr    = tbody.querySelector('tr[data-id="' + d.id + '"]');
		const newTr = buildRow(d);
		if (!newTr) return;
		if (!tr) {
			emptyRow.style.display = 'none';
			tbody.appendChild(newTr);
		} else {
			tbody.replaceChild(newTr, tr);
		}
		updateBulkBtn();
	}

	// ── Load ──────────────────────────────────────────────────────────────────
	async function loadProducts() {
		const res = await post({ action: 'list' });
		if (!res.ok) { notifyErr(res.message); return; }
		renderRows(res.rows);
	}

	async function loadCategories() {
		const res = await post({ action: 'categories' });
		if (res.ok) allCategories = res.categories || [];
	}

	// ── Separated click-to-edit: price and list price ─────────────────────────
	tbody.addEventListener('click', function (e) {
		// Price
		const pd = e.target.closest('.price-display');
		if (pd) {
			const id   = pd.dataset.id;
			const wrap = document.getElementById('price-edit-' + id);
			if (wrap && wrap.style.display === 'none') {
				wrap.style.display = 'block';
				wrap.querySelector('input').focus();
			}
			return;
		}
		// List price
		const ld = e.target.closest('.list-price-display');
		if (ld) {
			const id   = ld.dataset.id;
			const wrap = document.getElementById('list-price-edit-' + id);
			if (wrap && wrap.style.display === 'none') {
				wrap.style.display = 'block';
				wrap.querySelector('input').focus();
			}
			return;
		}
		// Stock
		const sd = e.target.closest('.stock-display');
		if (sd) {
			const id   = sd.dataset.id;
			const wrap = document.getElementById('stock-edit-' + id);
			if (wrap && wrap.style.display === 'none') {
				sd.style.display = 'none';
				wrap.style.display = 'inline-flex';
				wrap.querySelector('input').focus();
			}
		}
	});

	// Blur: save whichever field was edited
	tbody.addEventListener('blur', async function (e) {
		// Price blur
		const pi = e.target.closest('.price-input[data-field="price"]');
		if (pi) {
			const id    = pi.dataset.id;
			const price = parseFloat(pi.value || 0);
			const wrap  = document.getElementById('price-edit-' + id);
			if (wrap) wrap.style.display = 'none';
			const pd = tbody.querySelector('.price-display[data-id="' + id + '"]');
			if (pd) pd.textContent = '$' + price.toFixed(2);
			const res = await post({ action: 'save_prices', id, price, list_price: currentListPrice(id) });
			if (!res.ok) notifyErr(res.message);
		}
		// List price blur
		const li = e.target.closest('.price-input[data-field="list_price"]');
		if (li) {
			const id       = li.dataset.id;
			const listPr   = parseFloat(li.value || 0);
			const wrap     = document.getElementById('list-price-edit-' + id);
			if (wrap) wrap.style.display = 'none';
			const ld = tbody.querySelector('.list-price-display[data-id="' + id + '"]');
			if (ld) ld.textContent = '$' + listPr.toFixed(2);
			const res = await post({ action: 'save_prices', id, price: currentPrice(id), list_price: listPr });
			if (!res.ok) notifyErr(res.message);
		}
		// Stock blur
		const si = e.target.closest('.stock-input');
		if (si) {
			const id    = si.dataset.id;
			const stock = parseInt(si.value || 0);
			const wrap  = document.getElementById('stock-edit-' + id);
			if (wrap) wrap.style.display = 'none';
			const sd = tbody.querySelector('.stock-display[data-id="' + id + '"]');
			if (sd) { sd.textContent = stock; sd.className = 'stock-display ' + stockClass(stock); sd.style.display = ''; }
			const res = await post({ action: 'save_stock', id, stock });
			if (!res.ok) notifyErr(res.message);
		}
	}, true);

	function currentPrice(id) {
		const input = tbody.querySelector('.price-input[data-field="price"][data-id="' + id + '"]');
		if (input) return parseFloat(input.value || 0);
		const span = tbody.querySelector('.price-display[data-id="' + id + '"]');
		return span ? parseFloat(span.textContent.replace('$','') || 0) : 0;
	}
	function currentListPrice(id) {
		const input = tbody.querySelector('.price-input[data-field="list_price"][data-id="' + id + '"]');
		if (input) return parseFloat(input.value || 0);
		const span = tbody.querySelector('.list-price-display[data-id="' + id + '"]');
		return span ? parseFloat(span.textContent.replace('$','') || 0) : 0;
	}

	// ── Category checkboxes ───────────────────────────────────────────────────
	function renderCatList(selectedIds) {
		const list = document.getElementById('prod-cat-list');
		list.innerHTML = '';
		if (!allCategories.length) {
			list.innerHTML = '<span style="color:var(--nc-text-dim);font-size:.83rem">No categories. Add one below.</span>';
			return;
		}
		allCategories.forEach(function (c) {
			const label = document.createElement('label');
			const cb    = document.createElement('input');
			cb.type = 'checkbox'; cb.value = c.id; cb.name = 'prod_cat';
			if (selectedIds.map(String).includes(String(c.id))) cb.checked = true;
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' ' + c.name));
			list.appendChild(label);
		});
	}

	function getSelectedCatIds() {
		return Array.from(document.querySelectorAll('#prod-cat-list input[type=checkbox]:checked'))
			.map(cb => cb.value);
	}

	// ── Quick-add category ────────────────────────────────────────────────────
	document.getElementById('btn-quick-add-cat').addEventListener('click', async function () {
		const input = document.getElementById('quick-cat-name');
		const name  = input.value.trim();
		if (!name) { notifyErr('Enter a category name.'); return; }
		this.disabled = true;
		const res = await post({ action: 'quick_add_category', name });
		this.disabled = false;
		if (!res.ok) { notifyErr(res.message); return; }
		allCategories.push(res.category);
		const selected = getSelectedCatIds();
		selected.push(String(res.category.id));
		renderCatList(selected);
		input.value = '';
		notifyOk('Category "' + res.category.name + '" added. Remember to complete its details.');
	});

	// ── Drawer tabs ───────────────────────────────────────────────────────────
	document.querySelectorAll('.drawer-tab').forEach(function (btn) {
		btn.addEventListener('click', function () {
			document.querySelectorAll('.drawer-tab').forEach(t => t.classList.remove('active'));
			document.querySelectorAll('.drawer-tab-panel').forEach(p => p.classList.remove('active'));
			this.classList.add('active');
			document.getElementById('panel-' + this.dataset.panel).classList.add('active');
		});
	});

	// ── Image upload ──────────────────────────────────────────────────────────
	const imgDropZone  = document.getElementById('img-drop-zone');
	const imgFileInput = document.getElementById('img-file-input');
	const imgGrid      = document.getElementById('img-grid');

	let pendingImages  = []; // { file, url, isPrimary }

	function renderImageGrid() {
		imgGrid.innerHTML = '';
		pendingImages.forEach(function (img, idx) {
			const div = document.createElement('div');
			div.className = 'img-thumb' + (img.isPrimary ? ' is-primary' : '');
			div.dataset.idx = idx;
			div.innerHTML =
				'<img src="' + img.url + '" alt="">' +
				(img.isPrimary ? '<div class="img-primary-label">Primary</div>' : '') +
				'<button type="button" class="img-thumb-del" data-idx="' + idx + '">&times;</button>';
			div.addEventListener('click', function (e) {
				if (e.target.classList.contains('img-thumb-del')) return;
				pendingImages.forEach(i => i.isPrimary = false);
				img.isPrimary = true;
				renderImageGrid();
			});
			div.querySelector('.img-thumb-del').addEventListener('click', function (e) {
				e.stopPropagation();
				pendingImages.splice(idx, 1);
				if (pendingImages.length && !pendingImages.some(i => i.isPrimary)) {
					pendingImages[0].isPrimary = true;
				}
				renderImageGrid();
			});
			imgGrid.appendChild(div);
		});
	}

	function addImageFiles(files) {
		Array.from(files).forEach(function (file) {
			if (!file.type.match(/image\/(jpeg|png|webp)/)) return;
			const url = URL.createObjectURL(file);
			pendingImages.push({ file, url, isPrimary: pendingImages.length === 0 });
		});
		renderImageGrid();
	}

	// Click to browse
	imgDropZone.addEventListener('click', () => imgFileInput.click());
	imgFileInput.addEventListener('change', function () { addImageFiles(this.files); this.value = ''; });

	// Drag/drop
	let imgDragCount = 0;
	imgDropZone.addEventListener('dragenter', function (e) { e.preventDefault(); imgDragCount++; this.classList.add('drag-over'); });
	imgDropZone.addEventListener('dragleave', function ()  { imgDragCount--; if (imgDragCount <= 0) { imgDragCount = 0; this.classList.remove('drag-over'); } });
	imgDropZone.addEventListener('dragover',  function (e) { e.preventDefault(); });
	imgDropZone.addEventListener('drop',      function (e) {
		e.preventDefault(); imgDragCount = 0; this.classList.remove('drag-over');
		addImageFiles(e.dataTransfer.files);
	});

	// ── Drawer open/close ─────────────────────────────────────────────────────
	function openDrawer(title) {
		document.getElementById('drawer-title').textContent = title;
		drawer.classList.add('open');
		overlay.classList.add('show');
		// Switch to Details tab
		document.querySelectorAll('.drawer-tab').forEach(t => t.classList.remove('active'));
		document.querySelectorAll('.drawer-tab-panel').forEach(p => p.classList.remove('active'));
		document.querySelector('.drawer-tab[data-panel="details"]').classList.add('active');
		document.getElementById('panel-details').classList.add('active');
		// Init Trumbowyg
		setTimeout(function () {
			if (!window._trumbProdDone && window.jQuery && jQuery.fn.trumbowyg) {
				jQuery('#prod-desc-long').trumbowyg({
					svgPath: '/js/vendor/trumbowyg/src/ui/icons.svg',
					btns: [['bold','italic','underline'],['link'],['unorderedList','orderedList'],['indent','outdent'],['viewHTML']]
				});
				window._trumbProdDone = true;
			}
		}, 50);
	}

	function closeDrawer() {
		drawer.classList.remove('open');
		overlay.classList.remove('show');
		currentProdId = null;
	}

	function resetDrawer() {
		currentProdId = null;
		document.getElementById('prod-id').value         = '';
		document.getElementById('prod-name').value       = '';
		document.getElementById('prod-sku').value        = '';
		document.getElementById('prod-price').value      = '';
		document.getElementById('prod-list-price').value = '';
		document.getElementById('prod-stock').value      = '0';
		document.getElementById('prod-desc').value       = '';
		document.getElementById('quick-cat-name').value  = '';
		if (window._trumbProdDone) jQuery('#prod-desc-long').trumbowyg('html', '');
		else document.getElementById('prod-desc-long').value = '';
		setTogVal('prod-active',   1);
		setTogVal('prod-featured', 0);
		setTogVal('prod-free-ship',0);
		renderCatList([]);
		pendingImages = [];
		renderImageGrid();
	}

	// ── Add ───────────────────────────────────────────────────────────────────
	async function addProduct() {
		resetDrawer();
		openDrawer('Add Product');
		document.getElementById('prod-name').focus();
	}
	document.getElementById('btn-add-product').addEventListener('click', addProduct);
	document.getElementById('btn-add-first').addEventListener('click', addProduct);

	// ── Edit ──────────────────────────────────────────────────────────────────
	tbody.addEventListener('click', async function (e) {
		const btn = e.target.closest('.prod-name-link');
		if (!btn) return;
		const res = await post({ action: 'get', id: btn.dataset.id });
		if (!res.ok) { notifyErr(res.message); return; }
		const d = res.row;
		currentProdId = d.id;

		document.getElementById('prod-id').value         = d.id;
		document.getElementById('prod-name').value       = d.name;
		document.getElementById('prod-sku').value        = (d.sku || '').toUpperCase();
		document.getElementById('prod-price').value      = parseFloat(d.price || 0).toFixed(2);
		document.getElementById('prod-list-price').value = parseFloat(d.list_price || 0).toFixed(2);
		document.getElementById('prod-stock').value      = d.stock;
		document.getElementById('prod-desc').value       = d.description || '';

		if (window._trumbProdDone) jQuery('#prod-desc-long').trumbowyg('html', d.description_long || '');
		else document.getElementById('prod-desc-long').value = d.description_long || '';

		setTogVal('prod-active',    d.status       == 1 ? 1 : 0);
		setTogVal('prod-featured',  d.featured     == 1 ? 1 : 0);
		setTogVal('prod-free-ship', d.free_shipping == 1 ? 1 : 0);

		renderCatList(d.category_ids || []);

		// Load existing images
		pendingImages = (d.images || []).map(function (img) {
			return { file: null, url: img.filename, isPrimary: img.is_primary == 1, id: img.id };
		});
		renderImageGrid();

		openDrawer('Edit Product');
	});

	// ── SKU uppercase ─────────────────────────────────────────────────────────
	document.getElementById('prod-sku').addEventListener('input', function () {
		var pos = this.selectionStart;
		this.value = this.value.toUpperCase();
		this.setSelectionRange(pos, pos);
	});

	// ── Save ──────────────────────────────────────────────────────────────────
	document.getElementById('btn-drawer-save').addEventListener('click', async function () {
		const nameEl = document.getElementById('prod-name');
		if (!nameEl.reportValidity()) return;

		const descLong = window._trumbProdDone
			? jQuery('#prod-desc-long').trumbowyg('html')
			: document.getElementById('prod-desc-long').value;

		this.disabled = true;

		// First save the product record
		const res = await post({
			action:           'save',
			id:               document.getElementById('prod-id').value,
			name:             document.getElementById('prod-name').value.trim(),
			sku:              document.getElementById('prod-sku').value.toUpperCase(),
			price:            document.getElementById('prod-price').value,
			list_price:       document.getElementById('prod-list-price').value || 0,
			stock:            document.getElementById('prod-stock').value,
			status:           togVal('prod-active'),
			featured:         togVal('prod-featured'),
			free_shipping:    togVal('prod-free-ship'),
			description:      document.getElementById('prod-desc').value,
			description_long: descLong,
			category_ids:     JSON.stringify(getSelectedCatIds()),
		});

		if (!res.ok) { this.disabled = false; notifyErr(res.message); return; }

		// Upload any new images
		const newImages = pendingImages.filter(i => i.file);
		for (var i = 0; i < newImages.length; i++) {
			const img = newImages[i];
			const isPrimary = img.isPrimary && i === 0 ? 1 : 0;
			await postFile({
				action:     'upload_image',
				product_id: res.row.id,
				is_primary:  isPrimary,
			}, { image: img.file });
		}

		this.disabled = false;
		notifyOk(res.message);
		updateRow(res.row);
		closeDrawer();
	});

	document.getElementById('drawer-close').addEventListener('click', closeDrawer);
	document.getElementById('btn-drawer-cancel').addEventListener('click', closeDrawer);
	overlay.addEventListener('click', closeDrawer);

	// ── Debounced inline toggles ──────────────────────────────────────────────
	var debouncedToggle = debounce(function (id, field, value) {
		post({ action: 'toggle', id, field, value });
	}, 1000);

	document.addEventListener('ios-toggle', function (e) {
		const src = e.detail.source;
		if (!src.dataset.id || !src.dataset.field) return;
		if (src.dataset.field === 'status') {
			const id      = src.dataset.id;
			const inactive = !e.detail.checked;
			const delSpan  = document.getElementById('prod-del-' + id);
			const chk      = tbody.querySelector('tr[data-id="' + id + '"] .row-chk');
			if (delSpan) delSpan.classList.toggle('show', inactive);
			if (chk)     chk.disabled = !inactive;
		}
		debouncedToggle(src.dataset.id, src.dataset.field, e.detail.value);
	});

	// ── Delete single ─────────────────────────────────────────────────────────
	tbody.addEventListener('dip-confirm', async function (e) {
		const id  = e.detail.id;
		const res = await post({ action: 'delete', id });
		if (!res.ok) { notifyErr(res.message); return; }
		const tr = tbody.querySelector('tr[data-id="' + id + '"]');
		if (tr) { tr.classList.add('row-removing'); setTimeout(function () { tr.remove(); updateBulkBtn(); }, 300); }
		if (!tbody.querySelector('tr[data-id]')) emptyRow.style.display = '';
		notifyOk(res.message);
	});

	// ── Bulk delete ───────────────────────────────────────────────────────────
	chkAll.addEventListener('change', function () {
		tbody.querySelectorAll('.row-chk:not(:disabled)').forEach(c => c.checked = this.checked);
		updateBulkBtn();
	});
	tbody.addEventListener('change', function (e) { if (e.target.classList.contains('row-chk')) updateBulkBtn(); });

	function updateBulkBtn() {
		const n = tbody.querySelectorAll('.row-chk:checked').length;
		btnBulk.disabled    = n === 0;
		btnBulk.textContent = n > 0 ? 'Delete Selected (' + n + ')' : 'Delete Selected';
	}

	btnBulk.addEventListener('click', async function () {
		const ids = Array.from(tbody.querySelectorAll('.row-chk:checked')).map(c => c.dataset.id);
		if (!ids.length) return;
		this.disabled = true;
		const res = await post({ action: 'bulk_delete', ids: JSON.stringify(ids) });
		this.disabled = false;
		if (!res.ok) { notifyErr(res.message); return; }
		ids.forEach(function (id) {
			const tr = tbody.querySelector('tr[data-id="' + id + '"]');
			if (tr) { tr.classList.add('row-removing'); setTimeout(() => tr.remove(), 300); }
		});
		setTimeout(function () { if (!tbody.querySelector('tr[data-id]')) emptyRow.style.display = ''; updateBulkBtn(); }, 350);
		chkAll.checked = false;
		notifyOk(res.message);
	});

	// ── Drag-drop reorder — delegated ─────────────────────────────────────────
	var dragSrc = null;

	tbody.addEventListener('mousedown', function (e) {
		var h = e.target.closest('.drag-handle');
		if (!h) return;
		var tr = h.closest('tr[data-id]');
		if (tr) tr.setAttribute('draggable', 'true');
	});
	tbody.addEventListener('mouseup', function () {
		tbody.querySelectorAll('tr[data-id]').forEach(tr => tr.setAttribute('draggable', 'false'));
	});
	tbody.addEventListener('dragstart', function (e) {
		var tr = e.target.closest('tr[data-id]');
		if (!tr) return;
		dragSrc = tr; tr.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'move';
	});
	tbody.addEventListener('dragend', function () {
		if (dragSrc) dragSrc.classList.remove('dragging');
		tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over'));
		tbody.querySelectorAll('tr[data-id]').forEach(tr => tr.setAttribute('draggable', 'false'));
		dragSrc = null; saveOrder();
	});
	tbody.addEventListener('dragover', function (e) {
		e.preventDefault();
		var tr = e.target.closest('tr[data-id]');
		tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over'));
		if (tr && tr !== dragSrc) tr.classList.add('drag-over');
	});
	tbody.addEventListener('drop', function (e) {
		e.preventDefault();
		var tr = e.target.closest('tr[data-id]');
		if (!tr || tr === dragSrc || !dragSrc) return;
		var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
		var from = rows.indexOf(dragSrc), to = rows.indexOf(tr);
		if (from < to) tbody.insertBefore(dragSrc, tr.nextSibling);
		else           tbody.insertBefore(dragSrc, tr);
	});

	function initDragDrop() { /* delegated */ }

	async function saveOrder() {
		var ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(r => r.dataset.id);
		await post({ action: 'reorder', ids: JSON.stringify(ids) });
	}

	// ── Init ──────────────────────────────────────────────────────────────────
	Promise.all([fetchRowTemplate(), loadProducts(), loadCategories()]);

})();
