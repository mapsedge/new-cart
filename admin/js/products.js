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

	// ── Edit-in-place: click to show, blur or Enter to commit ────────────────
	tbody.addEventListener('click', function (e) {
		// Price
		const pd = e.target.closest('.price-display');
		if (pd) {
			const id  = pd.dataset.id;
			const inp = document.getElementById('price-edit-' + id);
			if (inp && inp.style.display === 'none') {
				pd.style.display = 'none';
				inp.style.display = 'inline';
				inp.focus(); inp.select();
			}
			return;
		}
		// List price
		const ld = e.target.closest('.list-price-display');
		if (ld) {
			const id  = ld.dataset.id;
			const inp = document.getElementById('list-price-edit-' + id);
			if (inp && inp.style.display === 'none') {
				ld.style.display = 'none';
				inp.style.display = 'inline';
				inp.focus(); inp.select();
			}
			return;
		}
		// Stock
		const sd = e.target.closest('.stock-display');
		if (sd) {
			const id  = sd.dataset.id;
			const inp = document.getElementById('stock-edit-' + id);
			if (inp && inp.style.display === 'none') {
				sd.style.display = 'none';
				inp.style.display = 'inline';
				inp.focus(); inp.select();
			}
			return;
		}
		// Hamburger menu toggle
		const menuBtn = e.target.closest('.row-menu-btn');
		if (menuBtn) {
			e.stopPropagation();
			const drop = menuBtn.nextElementSibling;
			const isOpen = drop.style.display !== 'none';
			// Close all open menus first
			document.querySelectorAll('.row-menu-drop').forEach(d => d.style.display = 'none');
			drop.style.display = isOpen ? 'none' : 'block';
			return;
		}
		// Close menus on any other click
		document.querySelectorAll('.row-menu-drop').forEach(d => d.style.display = 'none');
	});

	// Close menus when clicking outside
	document.addEventListener('click', function () {
		document.querySelectorAll('.row-menu-drop').forEach(d => d.style.display = 'none');
	});

	// Enter key commits eip
	tbody.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter') return;
		const input = e.target.closest('.eip-input');
		if (input) { e.preventDefault(); input.blur(); }
	});

	// Blur: save whichever field was edited
	tbody.addEventListener('blur', async function (e) {
		// Price blur
		const pi = e.target.closest('.price-input[data-field="price"]');
		if (pi) {
			const id    = pi.dataset.id;
			const price = parseFloat(pi.value || 0);
			pi.style.display = 'none';
			const pd = tbody.querySelector('.price-display[data-id="' + id + '"]');
			if (pd) { pd.textContent = '$' + price.toFixed(2); pd.style.display = 'inline'; }
			const res = await post({ action: 'save_prices', id, price, list_price: currentListPrice(id) });
			if (!res.ok) notifyErr(res.message);
		}
		// List price blur
		const li = e.target.closest('.price-input[data-field="list_price"]');
		if (li) {
			const id     = li.dataset.id;
			const listPr = parseFloat(li.value || 0);
			li.style.display = 'none';
			const ld = tbody.querySelector('.list-price-display[data-id="' + id + '"]');
			if (ld) { ld.textContent = '$' + listPr.toFixed(2); ld.style.display = 'inline'; }
			const res = await post({ action: 'save_prices', id, price: currentPrice(id), list_price: listPr });
			if (!res.ok) notifyErr(res.message);
		}
		// Stock blur
		const si = e.target.closest('.stock-input');
		if (si) {
			const id    = si.dataset.id;
			const stock = parseInt(si.value || 0);
			si.style.display = 'none';
			const sd = tbody.querySelector('.stock-display[data-id="' + id + '"]');
			if (sd) { sd.textContent = stock; sd.className = 'stock-display ' + stockClass(stock); sd.style.display = 'inline'; }
			const res = await post({ action: 'save_stock', id, stock });
			if (!res.ok) notifyErr(res.message);
		}
	}, true);

	function currentPrice(id) {
		const inp  = document.getElementById('price-edit-' + id);
		if (inp)  return parseFloat(inp.value || 0);
		const span = tbody.querySelector('.price-display[data-id="' + id + '"]');
		return span ? parseFloat(span.textContent.replace('$','') || 0) : 0;
	}
	function currentListPrice(id) {
		const inp  = document.getElementById('list-price-edit-' + id);
		if (inp)  return parseFloat(inp.value || 0);
		const span = tbody.querySelector('.list-price-display[data-id="' + id + '"]');
		return span ? parseFloat(span.textContent.replace('$','') || 0) : 0;
	}

	// ── Clone via hamburger ───────────────────────────────────────────────────
	tbody.addEventListener('click', async function (e) {
		const cloneBtn = e.target.closest('.clone-btn');
		if (!cloneBtn) return;
		document.querySelectorAll('.row-menu-drop').forEach(d => d.style.display = 'none');
		const id  = cloneBtn.dataset.id;
		const res = await post({ action: 'clone', id });
		if (!res.ok) { notifyErr(res.message); return; }
		emptyRow.style.display = 'none';
		const newTr = buildRow(res.row);
		if (newTr) tbody.appendChild(newTr);
		notifyOk(res.message);
		updateBulkBtn();
	});

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
		const res = await post({ action: 'quick_add_category', name });
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

	let pendingImages  = []; // { file, url, isPrimary, id? }
	let imgDragSrcIdx  = null;

	function renderImageGrid() {
		imgGrid.innerHTML = '';
		pendingImages.forEach(function (img, idx) {
			const div = document.createElement('div');
			div.className   = 'img-thumb' + (img.isPrimary ? ' is-primary' : '');
			div.dataset.idx = idx;
			div.draggable   = true;
			div.innerHTML =
				'<img src="' + img.url + '" alt="" draggable="false">' +
				(img.isPrimary ? '<div class="img-primary-label">Primary</div>' : '') +
				'<button type="button" class="img-thumb-del" data-idx="' + idx + '">&times;</button>';

			// Set primary on click (not delete button)
			div.addEventListener('click', function (e) {
				if (e.target.classList.contains('img-thumb-del')) return;
				pendingImages.forEach(function (i) { i.isPrimary = false; });
				img.isPrimary = true;
				renderImageGrid();
			});

			// Delete
			div.querySelector('.img-thumb-del').addEventListener('click', function (e) {
				e.stopPropagation();
				pendingImages.splice(idx, 1);
				if (pendingImages.length && !pendingImages.some(function (i) { return i.isPrimary; })) {
					pendingImages[0].isPrimary = true;
				}
				renderImageGrid();
			});

			// Drag reorder — index based
			div.addEventListener('dragstart', function (e) {
				imgDragSrcIdx = idx;
				this.classList.add('img-dragging');
				e.dataTransfer.effectAllowed = 'move';
			});
			div.addEventListener('dragend', function () {
				this.classList.remove('img-dragging');
				imgGrid.querySelectorAll('.img-thumb').forEach(function (t) { t.classList.remove('img-drag-over'); });
				imgDragSrcIdx = null;
			});
			div.addEventListener('dragover', function (e) {
				e.preventDefault();
				imgGrid.querySelectorAll('.img-thumb').forEach(function (t) { t.classList.remove('img-drag-over'); });
				if (imgDragSrcIdx !== null && idx !== imgDragSrcIdx) {
					this.classList.add('img-drag-over');
				}
			});
			div.addEventListener('drop', function (e) {
				e.preventDefault();
				this.classList.remove('img-drag-over');
				if (imgDragSrcIdx === null || idx === imgDragSrcIdx) return;
				// Reorder array
				const moved = pendingImages.splice(imgDragSrcIdx, 1)[0];
				pendingImages.splice(idx, 0, moved);
				imgDragSrcIdx = null;
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
	imgDropZone.addEventListener('click', function () { imgFileInput.click(); });
	imgFileInput.addEventListener('change', function () { addImageFiles(this.files); this.value = ''; });

	document.getElementById('btn-open-fm')?.addEventListener('click', function () {
		if (!window.openFilePicker) return;
		window.openFilePicker(function (items) {
			items.forEach(function (item) {
				// Avoid duplicates
				if (pendingImages.some(function (i) { return i.db_path === item.db_path; })) return;
				pendingImages.push({ url: item.url, db_path: item.db_path, isPrimary: pendingImages.length === 0 });
			});
			renderImageGrid();
		});
	});

	// Drag/drop files onto drop zone
	let imgDragCount = 0;
	imgDropZone.addEventListener('dragenter', function (e) {
		e.preventDefault(); imgDragCount++;
		this.classList.add('drag-over');
	});
	imgDropZone.addEventListener('dragleave', function () {
		imgDragCount--;
		if (imgDragCount <= 0) { imgDragCount = 0; this.classList.remove('drag-over'); }
	});
	imgDropZone.addEventListener('dragover', function (e) { e.preventDefault(); });
	imgDropZone.addEventListener('drop', function (e) {
		e.preventDefault(); imgDragCount = 0; this.classList.remove('drag-over');
		// Only handle file drops, not thumb reorders
		if (e.dataTransfer.files.length) addImageFiles(e.dataTransfer.files);
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
		document.getElementById('prod-id').value              = '';
		document.getElementById('prod-name').value            = '';
		document.getElementById('prod-sku').value             = '';
		document.getElementById('prod-price').value           = '';
		document.getElementById('prod-list-price').value      = '';
		document.getElementById('prod-stock').value           = '0';
		document.getElementById('prod-desc').value            = '';
		document.getElementById('prod-seo-title').value       = '';
		document.getElementById('prod-seo-keywords').value    = '';
		document.getElementById('prod-seo-description').value = '';
		document.getElementById('quick-cat-name').value       = '';
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

		document.getElementById('prod-id').value              = d.id;
		document.getElementById('prod-name').value            = d.name;
		document.getElementById('prod-sku').value             = (d.sku || '').toUpperCase();
		document.getElementById('prod-price').value           = parseFloat(d.price || 0).toFixed(2);
		document.getElementById('prod-list-price').value      = parseFloat(d.list_price || 0).toFixed(2);
		document.getElementById('prod-stock').value           = d.stock;
		document.getElementById('prod-desc').value            = d.description || '';
		document.getElementById('prod-seo-title').value       = d.seo_title || '';
		document.getElementById('prod-seo-keywords').value    = d.seo_keywords || '';
		document.getElementById('prod-seo-description').value = d.seo_description || '';

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
	debounceBtn(document.getElementById('btn-drawer-save'), async function () {
		const nameEl = document.getElementById('prod-name');
		if (!nameEl.reportValidity()) return;

		const descLong = window._trumbProdDone
			? jQuery('#prod-desc-long').trumbowyg('html')
			: document.getElementById('prod-desc-long').value;


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
			seo_title:        document.getElementById('prod-seo-title').value,
			seo_keywords:     document.getElementById('prod-seo-keywords').value,
			seo_description:  document.getElementById('prod-seo-description').value,
			category_ids:     JSON.stringify(getSelectedCatIds()),
		});

		if (!res.ok) {
			notifyErr(res.message);
			return;
		}

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
		const res = await post({ action: 'bulk_delete', ids: JSON.stringify(ids) });
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

	// ── Drawer scroll indicator ───────────────────────────────────────────────
	(function () {
		const drawerContent = document.getElementById('prod-drawer-content');
		const indicator     = document.getElementById('prod-scroll-indicator');
		if (!drawerContent || !indicator) return;

		function checkOverflow() {
			const detailsActive = document.getElementById('panel-details')?.classList.contains('active');
			const overflows = drawerContent.scrollHeight > drawerContent.clientHeight + 10;
			const atBottom  = drawerContent.scrollTop + drawerContent.clientHeight >= drawerContent.scrollHeight - 20;
			indicator.classList.toggle('hidden', !detailsActive || !overflows || atBottom);
		}

		const drawerEl = document.getElementById('prod-drawer');
		if (drawerEl && window.MutationObserver) {
			new MutationObserver(checkOverflow).observe(drawerEl, { attributes: true, attributeFilter: ['class'] });
		}
		drawerContent.addEventListener('scroll', checkOverflow);
		drawerContent.addEventListener('focusin', function (e) {
			if (e.target.tagName === 'TEXTAREA' || e.target.classList.contains('trumbowyg-editor')) {
				indicator.classList.add('hidden');
			}
		});
		// Re-check when tabs switch
		document.querySelectorAll('.drawer-tab').forEach(function (btn) {
			btn.addEventListener('click', function () { setTimeout(checkOverflow, 50); });
		});
		window.addEventListener('resize', checkOverflow);
	}());

	// ── AI helpers ────────────────────────────────────────────────────────────
	async function aiGenerate(prompt) {
		var key = (NC.deepaiKey || '').trim();
		if (!key) {
			notifyErr('DeepAI API key not set. Add it in Settings \u2192 Options \u2192 AI.');
			return null;
		}
		var fd = new FormData();
		fd.append('text', prompt);
		try {
			var res  = await fetch('https://api.deepai.org/api/text-generator', {
				method: 'POST',
				headers: { 'api-key': key },
				body: fd,
			});
			var data = await res.json();
			if (data.err) { notifyErr('AI error: ' + data.err); return null; }
			return (data.output || '').trim();
		} catch (e) {
			notifyErr('AI request failed: ' + e.message);
			return null;
		}
	}

	function prodName() {
		return document.getElementById('prod-name').value.trim();
	}

	// Short description
	document.getElementById('btn-prod-ai-short')?.addEventListener('click', async function () {
		var name = prodName();
		if (!name) { notifyErr('Enter a product name first.'); return; }
		this.disabled = true; this.textContent = '\u2728 Generating\u2026';
		var result = await aiGenerate(
			'Write a short, compelling 1-2 sentence product description (plain text, no HTML, no heading) for a product called "' + name + '".'
		);
		this.disabled = false; this.textContent = '\u2728 AI';
		if (result) document.getElementById('prod-desc').value = result;
	});

	// Long description
	document.getElementById('btn-prod-ai-long')?.addEventListener('click', async function () {
		var name  = prodName();
		var short = document.getElementById('prod-desc').value.trim();
		if (!name) { notifyErr('Enter a product name first.'); return; }
		this.disabled = true; this.textContent = '\u2728 Generating\u2026';
		var ctx    = short ? ' The short description is: ' + short : '';
		var result = await aiGenerate(
			'Write a detailed HTML product description (3-5 sentences, use <p> tags, no heading) for a product called "' + name + '".' + ctx
		);
		this.disabled = false; this.textContent = '\u2728 AI';
		if (!result) return;
		if (window._trumbProdDone) jQuery('#prod-desc-long').trumbowyg('html', result);
		else document.getElementById('prod-desc-long').value = result;
	});

	// All SEO fields
	document.getElementById('btn-prod-ai-seo')?.addEventListener('click', async function () {
		var name = prodName();
		if (!name) { notifyErr('Enter a product name first.'); return; }
		this.disabled = true; this.textContent = '\u2728 Generating\u2026';
		var result = await aiGenerate(
			'For a product named "' + name + '", provide ONLY a JSON object (no markdown, no explanation) with: ' +
			'seo_title (page title under 70 chars), ' +
			'seo_keywords (comma-separated, max 150 chars), ' +
			'seo_description (meta description under 160 chars).'
		);
		this.disabled = false; this.textContent = '\u2728 Generate All SEO';
		if (!result) return;
		try {
			var obj = JSON.parse(result.replace(/```json|```/g, '').trim());
			if (obj.seo_title)       document.getElementById('prod-seo-title').value       = obj.seo_title;
			if (obj.seo_keywords)    document.getElementById('prod-seo-keywords').value    = obj.seo_keywords;
			if (obj.seo_description) document.getElementById('prod-seo-description').value = obj.seo_description;
		} catch (e) {
			notifyErr('AI returned unexpected format. Try again.');
		}
	});

	// ── Product Options tab ─────────────────────────────────────────────────────
	const optSearchInput   = document.getElementById('opt-search-input');
	const optSearchResults = document.getElementById('opt-search-results');
	const poList           = document.getElementById('product-options-list');

	// Load options when Options tab is activated
	document.querySelector('.drawer-tab[data-panel="options"]')?.addEventListener('click', function () {
		const productId = document.getElementById('prod-id')?.value;
		if (productId) loadProductOptions(productId);
	});

	async function loadProductOptions(productId) {
		if (!poList) return;
		const res = await ajax({ action: 'list_options', product_id: productId });
		if (!res.ok) return;
		poList.innerHTML = '';
		(res.product_options || []).forEach(po => poList.appendChild(buildPoCard(po)));
	}

	function buildPoCard(po) {
		const card = document.createElement('div');
		card.className = 'po-card';
		card.dataset.poId = po.id;
		card.setAttribute('role', 'listitem');

		const CHOICE = ['select','radio','checkbox','toggle'];
		const isChoice = CHOICE.includes(po.type);

		// ── Head ──
		const head = document.createElement('div');
		head.className = 'po-card-head';
		head.setAttribute('aria-expanded', 'false');

		const toggle = document.createElement('span');
		toggle.className = 'po-card-toggle';
		toggle.textContent = '▶';
		toggle.setAttribute('aria-hidden', 'true');

		const nameEl = document.createElement('span');
		nameEl.className = 'po-card-name';
		nameEl.textContent = po.label || po.option_name;

		const typeEl = document.createElement('span');
		typeEl.className = 'po-card-type';
		typeEl.textContent = po.type;

		// Required toggle
		const reqWrap = document.createElement('label');
		reqWrap.className = 'po-card-required';
		reqWrap.innerHTML = '<ios-toggle size="sm" ' + (po.required ? 'checked' : '') + ' aria-label="Required"></ios-toggle><span>Required</span>';
		reqWrap.querySelector('ios-toggle')?.addEventListener('ios-toggle', async function (e) {
			e.stopPropagation();
			await ajax({ action: 'save_option', po_id: po.id, label: po.label || '', required: e.detail.checked ? 1 : 0 });
		});

		// Remove (delete-in-place)
		const dip = document.createElement('delete-in-place');
		dip.setAttribute('caption', '🗑');
		dip.setAttribute('confirm', 'Remove option?');
		dip.dataset.poId = po.id;
		dip.addEventListener('dip-confirm', async function () {
			card.style.transition = 'opacity .3s';
			card.style.opacity = '0';
			const res = await ajax({ action: 'remove_option', po_id: po.id });
			if (!res.ok) { card.style.opacity = '1'; notifyErr(res.message); return; }
			setTimeout(() => card.remove(), 320);
		});

		head.appendChild(toggle);
		head.appendChild(nameEl);
		head.appendChild(typeEl);
		head.appendChild(reqWrap);
		head.appendChild(dip);

		// ── Body ──
		const body = document.createElement('div');
		body.className = 'po-card-body';

		// Label override (edit-in-place)
		const labelWrap = document.createElement('div');
		labelWrap.className = 'po-label-field';
		labelWrap.innerHTML = '<label>Label (overrides "' + esc(po.option_name) + '" for this product)</label>';
		const labelInput = document.createElement('input');
		labelInput.type  = 'text';
		labelInput.value = po.label || '';
		labelInput.placeholder = po.option_name;
		labelInput.setAttribute('aria-label', 'Option label override');
		async function saveLabel() {
			po.label = labelInput.value.trim();
			nameEl.textContent = po.label || po.option_name;
			await ajax({ action: 'save_option', po_id: po.id, label: po.label, required: po.required || 0 });
		}
		labelInput.addEventListener('blur', saveLabel);
		labelInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveLabel(); } });
		labelWrap.appendChild(labelInput);
		body.appendChild(labelWrap);

		// Value rows (choice types only)
		if (isChoice && po.values) {
			po.values.forEach(v => body.appendChild(buildPovRow(v)));
		}

		// Toggle open/close
		head.addEventListener('click', function (e) {
			if (e.target.closest('delete-in-place') || e.target.closest('ios-toggle') || e.target.closest('label')) return;
			card.classList.toggle('open');
			toggle.textContent = card.classList.contains('open') ? '▼' : '▶';
			head.setAttribute('aria-expanded', String(card.classList.contains('open')));
		});

		card.appendChild(head);
		card.appendChild(body);
		return card;
	}

	function buildPovRow(v) {
		const row = document.createElement('div');
		row.className = 'po-value-row';
		row.dataset.povId = v.id;

		// Enabled toggle
		const enabledToggle = document.createElement('ios-toggle');
		enabledToggle.className = 'po-value-enabled';
		enabledToggle.setAttribute('size', 'sm');
		enabledToggle.setAttribute('aria-label', 'Enable ' + v.value_text);
		if (v.enabled == 1) enabledToggle.setAttribute('checked', '');

		// Name
		const nameEl = document.createElement('span');
		nameEl.className = 'po-value-name' + (v.enabled == 0 ? ' disabled' : '');
		nameEl.textContent = v.value_text;

		// Label override
		const labelIn = document.createElement('input');
		labelIn.type        = 'text';
		labelIn.className   = 'po-value-label-input';
		labelIn.value       = v.label || '';
		labelIn.placeholder = 'Label…';
		labelIn.setAttribute('aria-label', 'Value label override for ' + v.value_text);

		// Price modifier
		const prefixSel = document.createElement('select');
		prefixSel.className = 'po-value-prefix';
		prefixSel.setAttribute('aria-label', 'Price modifier sign');
		prefixSel.innerHTML = '<option value="+">+</option><option value="-">−</option>';
		prefixSel.value = v.price_prefix || '+';

		const priceIn = document.createElement('input');
		priceIn.type      = 'number';
		priceIn.className = 'po-value-price-input';
		priceIn.value     = parseFloat(v.price_modifier || 0).toFixed(2);
		priceIn.min       = '0';
		priceIn.step      = '0.01';
		priceIn.setAttribute('aria-label', 'Price modifier for ' + v.value_text);

		// Stock
		const stockIn = document.createElement('input');
		stockIn.type      = 'number';
		stockIn.className = 'po-value-stock-input';
		stockIn.value     = v.stock || 0;
		stockIn.min       = '0';
		stockIn.setAttribute('aria-label', 'Stock for ' + v.value_text);

		async function savePov() {
			await ajax({
				action:          'save_option_value',
				pov_id:          v.id,
				label:           labelIn.value.trim(),
				price_prefix:    prefixSel.value,
				price_modifier:  priceIn.value,
				weight_prefix:   '+',
				weight_modifier: 0,
				stock:           stockIn.value,
				subtract_stock:  0,
				enabled:         enabledToggle.checked ? 1 : 0,
			});
		}

		enabledToggle.addEventListener('ios-toggle', async function (e) {
			nameEl.classList.toggle('disabled', !e.detail.checked);
			await savePov();
		});
		[labelIn, priceIn, stockIn, prefixSel].forEach(el => {
			el.addEventListener('change', savePov);
			if (el.tagName === 'INPUT') el.addEventListener('blur', savePov);
		});

		const priceWrap = document.createElement('div');
		priceWrap.className = 'po-value-price';
		priceWrap.appendChild(prefixSel);
		priceWrap.appendChild(priceIn);

		row.appendChild(enabledToggle);
		row.appendChild(nameEl);
		row.appendChild(labelIn);
		row.appendChild(priceWrap);
		row.appendChild(stockIn);
		return row;
	}

	// ── Option autocomplete ─────────────────────────────────────────────────────
	let acTimer = null;

	async function showOptions(q) {
		const res = await ajax({ action: 'search_options', q: q || '' });
		if (!res.ok) return;
		optSearchResults.innerHTML = '';
		if (!res.rows.length) { optSearchResults.style.display = 'none'; return; }
		res.rows.forEach(function (r) {
			const li = document.createElement('li');
			li.setAttribute('role', 'option');
			li.innerHTML = esc(r.name) + '<span class="opt-autocomplete-type">' + esc(r.type) + '</span>';
			li.addEventListener('mousedown', async function (e) {
				e.preventDefault(); // prevent blur before click fires
				optSearchResults.style.display = 'none';
				optSearchInput.value = '';
				const productId = document.getElementById('prod-id')?.value;
				if (!productId) return;
				const addRes = await ajax({ action: 'add_option', product_id: productId, option_id: r.id });
				if (!addRes.ok) { notifyErr(addRes.message); return; }
				poList.appendChild(buildPoCard(addRes.product_option));
			});
			optSearchResults.appendChild(li);
		});
		optSearchResults.style.display = '';
	}

	// Show all on focus
	optSearchInput?.addEventListener('focus', function () { showOptions(''); });

	// Filter on type
	optSearchInput?.addEventListener('input', function () {
		clearTimeout(acTimer);
		const q = this.value.trim();
		acTimer = setTimeout(() => showOptions(q), 200);
	});

	optSearchInput?.addEventListener('blur', function () {
		setTimeout(() => { if (optSearchResults) optSearchResults.style.display = 'none'; }, 200);
	});

	function esc(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

})();
