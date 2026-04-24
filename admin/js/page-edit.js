/**
 * new-cart — Full-screen page editor v2
 * Inline block editing, 4-col grid with column bar, image properties panel
 */
(function() {
'use strict';

const AJAX = NC.adminUrl + '?route=pages/ajax';
const AJAX_LIB = NC.adminUrl + '?route=block-library/ajax';

function ajax(url, data) {
	const fd = new FormData();
	for (const [k,v] of Object.entries(data)) fd.append(k, String(v ?? ''));
	return fetch(url, { method:'POST', body:fd }).then(r => r.json());
}
function post(data)    { return ajax(AJAX, data); }
function notifyErr(m)  { SimpleNotification.error({ text: m }); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

const BLOCK_LABELS = {
	rich_text:'Rich Text', html:'HTML', slideshow:'Slideshow',
	menu:'Menu',
	featured_products:'Featured', best_sellers:'Best Sellers',
	best_sellers_category:'By Category', new_arrivals:'New Arrivals',
	related_products:'Related Products',
	contact_form:'Form', sitemap:'Site Map',
	cart_contents:'Cart', checkout_form:'Checkout Form',
};

// State
let blocks   = [];   // [{id, block_type, settings:{}, enabled, col_start, col_span}]
let pageId   = parseInt(document.getElementById('pe-page-id').value) || 0;
let dragType = null;    // type being dragged from palette
let dragSrcBlock = null; // block object being reordered

function parsePipe(id) {
	const raw = document.getElementById(id)?.value || '';
	return raw.split('|').filter(Boolean).map(s => {
		const i = s.indexOf(':');
		return { id: s.slice(0, i), name: s.slice(i+1) };
	});
}
const slideshows = parsePipe('pe-slideshows-data');
const categories = parsePipe('pe-categories-data');
const menus      = parsePipe('pe-menus-data');

// ── Load ─────────────────────────────────────────────────────────────────────
async function load() {
	if (!pageId) return renderCanvas();
	const res = await post({ action:'get', id:pageId });
	if (!res.ok) return;
	blocks = (res.blocks||[]).map(b => ({
		...b,
		settings:  b.settings  || {},
		col_start: parseInt(b.col_start) || 1,
		col_span:  parseInt(b.col_span)  || 4,
		row:       parseInt(b.row)       || 0,
		row_span:  parseInt(b.row_span)  || 1,
		is_core:   !!(b.settings?.is_core),
	}));
	renderCanvas();
	loadLibrary();
}

// ── Canvas ────────────────────────────────────────────────────────────────────
const canvas = document.getElementById('pe-canvas');
const emptyEl = document.getElementById('pe-canvas-empty');

function renderCanvas() {
	// Destroy Trumbowyg instances before clearing DOM
	if (window.jQuery) jQuery('.trumbowyg-editor').each(function() {
		try { jQuery(this).closest('.pe-block-body').find('textarea').trumbowyg('destroy'); } catch(e) {}
	});

	canvas.innerHTML = '';
	if (!blocks.length) { canvas.appendChild(emptyEl); emptyEl.style.display=''; return; }
	emptyEl.style.display = 'none';

	// Lay blocks into rows, filling gaps with skeletons,
	// then append a full-width skeleton row at the bottom for adding more blocks.
	const rows = layoutRows(blocks);
	rows.forEach((row, rowIdx) => {
		const gridRow = String(rowIdx + 1);
		let col = 1;
		row.forEach(item => {
			while (col < item.col_start) {
				const sk = makeSkeleton(col, 1, rowIdx);
				sk.style.gridRow = gridRow;
				canvas.appendChild(sk);
				col++;
			}
			const card = buildCard(item);
			card.style.gridRow = gridRow;
			canvas.appendChild(card);
			col = item.col_start + item.col_span;
		});
		while (col <= 4) {
			const sk = makeSkeleton(col, 1, rowIdx);
			sk.style.gridRow = gridRow;
			canvas.appendChild(sk);
			col++;
		}
	});

	// Always add a full-width skeleton row at the bottom
	const bottomRowIdx = rows.length; // 0-based index for the bottom row
	const bottomRow = String(rows.length + 1);
	for (let col = 1; col <= 4; col++) {
		const sk = makeSkeleton(col, 1, bottomRowIdx);
		sk.style.gridRow = bottomRow;
		sk.dataset.bottom = '1'; // marks this as a bottom-append skeleton
		canvas.appendChild(sk);
	}

	initAllTrumbowyg();
}

// Row layout: groups blocks by their b.row value (explicit, from DB).
// If b.row is not set (0 for all), falls back to sequence inference.
function layoutRows(blocks) {
	// Check if row data is meaningful (any block with row > 0, or blocks differ)
	const hasRowData = blocks.some((b, i) => i > 0 && (b.row || 0) !== (blocks[i-1].row || 0));
	
	if (hasRowData) {
		// Group by row number, sort within each row by col_start
		const rowMap = {};
		blocks.forEach(b => {
			const r = b.row || 0;
			if (!rowMap[r]) rowMap[r] = [];
			rowMap[r].push(b);
		});
		return Object.keys(rowMap)
			.map(Number).sort((a,b) => a - b)
			.map(r => rowMap[r].slice().sort((a,b) => (a.col_start||1) - (b.col_start||1)));
	}

	// Fallback: infer rows from sequence and col positions
	const rows = [[]];
	let colEnd = 0;
	blocks.forEach(b => {
		let start = Math.max(1, Math.min(4, b.col_start || 1));
		let span  = Math.max(1, Math.min(5 - start, b.col_span || 4));
		b.col_start = start; b.col_span = span;
		if (start <= colEnd) { rows.push([]); colEnd = 0; }
		rows[rows.length-1].push(b);
		colEnd = start + span - 1;
		if (colEnd >= 4) { rows.push([]); colEnd = 0; }
	});
	return rows.filter(r => r.length > 0);
}

// Assign b.row to all blocks based on current array order.
// Clears existing row values first so layoutRows uses sequence inference.
function assignAndSaveRows() {
	// Clear row values so layoutRows falls back to sequence-based inference
	blocks.forEach(b => { b.row = 0; });
	const rows = layoutRows(blocks.slice());
	rows.forEach((row, ri) => {
		row.forEach(b => {
			b.row = ri;
			saveBlock(b);
		});
	});
}

// When a block expands right, compress the next block in the same row to fit.
function applyExpansion(b) {
	const rows = layoutRows(blocks.slice());
	for (const row of rows) {
		const idx = row.indexOf(b);
		if (idx < 0) continue;
		const next = row[idx + 1];
		if (!next) break;
		const bEnd = b.col_start + b.col_span - 1;
		if (next.col_start <= bEnd) {
			// Compress next: push start right, reduce span
			const newStart = bEnd + 1;
			const newSpan  = (next.col_start + next.col_span - 1) - bEnd;
			if (newStart > 4 || newSpan < 1) {
				// No room — remove next block from this row (push to next)
				// by giving it col_start 1 and wrapping
				next.col_start = 1;
			} else {
				next.col_start = newStart;
				next.col_span  = newSpan;
			}
			saveBlock(next);
		}
		break;
	}
}

function makeSkeleton(colStart, colSpan, rowIdx) {
	const el = document.createElement('div');
	el.className = 'pe-skeleton';
	el.style.gridColumn = colStart + ' / span ' + colSpan;
	el.dataset.col = colStart;
	el.dataset.row = rowIdx !== undefined ? rowIdx : 0;

	// Drop target for palette drags
	el.addEventListener('dragover', e => {
		e.preventDefault(); // must always prevent to allow drop
		el.classList.add('drop-over');
	});
	el.addEventListener('dragleave', () => el.classList.remove('drop-over'));
	el.addEventListener('drop', async e => {
		e.preventDefault(); el.classList.remove('drop-over');
		const targetCol    = parseInt(el.dataset.col);
		const isBottomSkel = el.dataset.bottom === '1';
		// For palette drops: use live row calc. For block reorders: use data-row
		// (live recalc is wrong for reorders because removing the src block
		// makes previously-occupied cols appear free in wrong rows).
		const dataRow   = parseInt(el.dataset.row);
		const liveRows  = layoutRows(blocks.slice());
		let targetRow   = isBottomSkel ? liveRows.length : dataRow;

		// For palette drops recalculate — no src removal distorts the picture
		const raw = e.dataTransfer.getData('text/plain') || '';
		const dropType = dragType || (!raw.startsWith('block:') ? raw : '');
		if (dropType) {
			// Palette: recalculate live to find first row with col free
			if (!isBottomSkel) {
				targetRow = liveRows.length;
				for (let ri = 0; ri < liveRows.length; ri++) {
					const occ = new Set();
					liveRows[ri].forEach(b => {
						for (let cc = b.col_start; cc < b.col_start + b.col_span; cc++) occ.add(cc);
					});
					if (!occ.has(targetCol)) { targetRow = ri; break; }
				}
			}
			await addBlockAtRow(dropType, targetCol, targetRow);
		} else if (raw && raw.startsWith('block:')) {
			const srcId = parseInt(raw.slice(6));
			const src   = blocks.find(x => x.id === srcId);
			if (src) {
				// Remove src from blocks array
				const srcI = blocks.indexOf(src);
				blocks.splice(srcI, 1);
				src.col_start = targetCol;
				src.col_span  = 1;
				// targetRow from data-row is the visual row in the pre-removal layout.
				// After removal, find the correct insert index in the remaining blocks.
				const rowsAfter = layoutRows(blocks.slice());
				// Map pre-removal targetRow to post-removal row index
				// by finding the row in rowsAfter that contains the same blocks
				// that were in targetRow before removal.
				let insRow = isBottomSkel ? rowsAfter.length : rowsAfter.length;
				if (!isBottomSkel) {
					// Find which rowsAfter index corresponds to dataRow by row value
					for (let ri = 0; ri < rowsAfter.length; ri++) {
						const rowVal = rowsAfter[ri][0]?.row;
						if (rowVal === dataRow) { insRow = ri; break; }
					}
				}
				const insIdx = _rowInsertIdx(insRow, rowsAfter, targetCol);
				blocks.splice(insIdx, 0, src);
				assignAndSaveRows();
				const ids = blocks.filter(x => x.id).map(x => x.id);
				if (ids.length) await post({ action:'reorder_blocks', ids:JSON.stringify(ids) });
				renderCanvas();
			}
		}
	});
	// Click on skeleton to add last-used or prompt
	el.title = 'Empty column — drag a block type here';
	return el;
}

// ── Rebuild skeletons in place (called on col bar click, no full re-render) ──
function rebuildSkeletons() {
	// Full re-render is the only reliable way to get grid-row right
	renderCanvas();
}

// ── Build block card ──────────────────────────────────────────────────────────
function buildCard(b) {
	const idx = blocks.indexOf(b);
	const card = document.createElement('div');
	card.className = 'pe-block-card' + (b.enabled ? '' : ' pe-disabled') + (b.is_core ? ' pe-core' : '');
	card.setAttribute('data-col-start', b.col_start || 1);
	card.setAttribute('data-col-span',  b.col_span  || 4);
	card.setAttribute('data-idx', idx);
	card.setAttribute('role', 'listitem');
	card.style.gridColumn = (b.col_start||1) + ' / span ' + (b.col_span||4);

	// ── Head ──
	const head = document.createElement('div');
	head.className = 'pe-block-head';

	// Drag handle (reorder within canvas)
	const handle = document.createElement('span');
	handle.className = 'pe-drag-handle';
	handle.textContent = '⠿';
	handle.setAttribute('aria-hidden','true');

	// Label
	const label = document.createElement('span');
	label.className = 'pe-block-label';
	label.textContent = BLOCK_LABELS[b.block_type] || b.block_type;

	// Name input
	const nameInp = document.createElement('input');
	nameInp.type = 'text';
	nameInp.className = 'pe-block-name-input';
	nameInp.value = b.name || '';
	nameInp.placeholder = 'name…';
	nameInp.title = 'Give this block a name to save it to the library';
	let nameTimer;
	nameInp.addEventListener('input', () => {
		clearTimeout(nameTimer);
		nameTimer = setTimeout(() => saveBlockName(b, nameInp.value.trim()), 800);
	});
	nameInp.addEventListener('blur', () => {
		clearTimeout(nameTimer);
		saveBlockName(b, nameInp.value.trim());
	});

	// Delete-in-place (suppressed for core blocks)
	if (b.is_core) {
		const coreBadge = document.createElement('span');
		coreBadge.className = 'pe-core-badge';
		coreBadge.textContent = 'Core';
		coreBadge.title = 'This block is required and cannot be removed';
		head.appendChild(handle);
		head.appendChild(label);
		head.appendChild(nameInp);
		head.appendChild(coreBadge);
	} else {
		const dip = document.createElement('delete-in-place');
		dip.setAttribute('caption', '✕');
		dip.setAttribute('confirm', 'OK?');
		dip.className = 'pe-block-delete-btn';
		dip.addEventListener('dip-confirm', async () => {
			card.style.transition = 'opacity .3s';
			card.style.opacity = '0';
			if (b.id) await post({ action:'delete_block', id:b.id });
			setTimeout(() => {
				const i = blocks.indexOf(b);
				if (i > -1) blocks.splice(i, 1);
				renderCanvas();
			}, 320);
		});
		head.appendChild(handle);
		head.appendChild(label);
		head.appendChild(nameInp);
		head.appendChild(dip);
	}

	// Drag handle only — store block id in dataTransfer so drop handler
	// can identify the source reliably without depending on dragSrcBlock timing.
	handle.setAttribute('draggable', 'true');
	handle.addEventListener('dragstart', e => {
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData('text/plain', 'block:' + (b.id || ''));
		setTimeout(() => { card.classList.add('pe-drag-source'); }, 0);
	});
	handle.addEventListener('dragend', () => {
		card.classList.remove('pe-drag-source');
	});
	card.dataset.blockId = b.id;

	// ── Body ──
	const body = document.createElement('div');
	body.className = 'pe-block-body';
	buildBlockFields(b, body);

	// Left/right resize handles
	const resizeL = makeResizeHandle('left',  b, card);
	const resizeR = makeResizeHandle('right', b, card);
	const resizeT = makeRowResizeHandle('top',    b, card);
	const resizeB = makeRowResizeHandle('bottom', b, card);

	card.appendChild(head);
	card.appendChild(body);
	card.appendChild(resizeL);
	card.appendChild(resizeR);
	card.appendChild(resizeT);
	card.appendChild(resizeB);
	return card;
}

function makeResizeHandle(side, b, card) {
	const handle = document.createElement('div');
	handle.className = 'pe-resize-handle pe-resize-' + side;
	handle.setAttribute('aria-hidden', 'true');

	handle.addEventListener('mousedown', function(e) {
		e.preventDefault();
		e.stopPropagation();
		const canvasRect = canvas.getBoundingClientRect();
		const colW = canvasRect.width / 4;
		const origStart = b.col_start;
		const origSpan  = b.col_span;
		const origEnd   = origStart + origSpan - 1;

		function onMove(me) {
			const x    = me.clientX - canvasRect.left;
			const rawCol = x / colW; // 0-based fractional column
			const snapCol = Math.round(rawCol); // snap to nearest column boundary
			const col1 = Math.max(1, Math.min(4, snapCol + 1)); // 1-based snapped col

			if (side === 'right') {
				// snapCol is the column boundary (0..4); right edge of col N = boundary N
				const newEnd  = Math.max(b.col_start, Math.min(4, snapCol));
				const newSpan = Math.max(1, newEnd - b.col_start + 1);
				if (newSpan !== b.col_span) {
					b.col_span = newSpan;
					card.style.gridColumn = b.col_start + ' / span ' + b.col_span;
				}
			} else {
				// Left edge of col N = boundary N-1; snapCol+1 gives the col we're entering
				const newStart = Math.max(1, Math.min(origEnd, snapCol + 1));
				const newSpan  = Math.max(1, origEnd - newStart + 1);
				if (newStart !== b.col_start || newSpan !== b.col_span) {
					b.col_start = newStart;
					b.col_span  = newSpan;
					card.style.gridColumn = b.col_start + ' / span ' + b.col_span;
				}
			}
		}

		async function onUp() {
			document.removeEventListener('mousemove', onMove);
			document.removeEventListener('mouseup', onUp);
			card.setAttribute('data-col-start', b.col_start);
			card.setAttribute('data-col-span',  b.col_span);
			applyExpansion(b);
			assignAndSaveRows();
			rebuildSkeletons();
			await saveBlock(b);
		}
		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup', onUp);
	});
	return handle;
}



function makeRowResizeHandle(side, b, card) {
	const handle = document.createElement('div');
	handle.className = 'pe-resize-handle pe-resize-' + side;
	handle.setAttribute('aria-hidden', 'true');
	handle.setAttribute('draggable', 'false');

	// Only bottom edge resizes row_span; top edge is decorative for now
	if (side !== 'bottom') return handle;

	handle.addEventListener('mousedown', function(e) {
		e.preventDefault(); e.stopPropagation();

		const startY   = e.clientY;
		const origSpan = b.row_span || 1;
		// Measure the actual grid row height from the canvas computed style
		const canvasEl  = document.getElementById('pe-canvas');
		const rowSizes  = getComputedStyle(canvasEl).gridTemplateRows.split(' ');
		// gridTemplateRows lists explicit rows; use the first value or fall back to auto-row height
		let rowH = 120;
		if (rowSizes.length && rowSizes[0] !== 'none') {
			rowH = parseFloat(rowSizes[0]) || 120;
		} else {
			// Fall back: canvas height / number of visual rows
			const rows = layoutRows(blocks.slice());
			const totalRows = rows.length + 1; // +1 for bottom skeleton row
			const gap = parseFloat(getComputedStyle(canvasEl).gap) || 10;
			rowH = Math.max(80, (canvasEl.getBoundingClientRect().height - gap * (totalRows - 1)) / totalRows);
		}

		function onMove(me) {
			const dy      = me.clientY - startY;
			const newSpan = Math.max(1, origSpan + Math.round(dy / rowH));
			if (newSpan !== (b.row_span || 1)) {
				b.row_span = newSpan;
				card.style.gridRowEnd = 'span ' + newSpan;
			}
		}

		async function onUp() {
			document.removeEventListener('mousemove', onMove);
			document.removeEventListener('mouseup',   onUp);
			await saveBlock(b);
		}

		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup',   onUp);
	});
	return handle;
}

// ── Product block fields (shared by all product block subtypes) ───────────────
function buildProductFields(b, body) {
	const s = b.settings || {};
	const subtypes = [
		{ value:'featured_products',    label:'Featured' },
		{ value:'best_sellers',         label:'Best Sellers' },
		{ value:'best_sellers_category',label:'By Category' },
		{ value:'new_arrivals',         label:'New Arrivals' },
		{ value:'related_products',     label:'Related Products' },
	];
	const typeSel = document.createElement('select');
	typeSel.innerHTML = subtypes.map(t =>
		`<option value="${t.value}"${b.block_type === t.value ? ' selected' : ''}>${esc(t.label)}</option>`
	).join('');
	typeSel.addEventListener('change', async () => {
		b.block_type = typeSel.value;
		const card = body.closest('.pe-block-card');
		if (card) {
			const labelEl = card.querySelector('.pe-block-label');
			if (labelEl) labelEl.textContent = BLOCK_LABELS[b.block_type] || b.block_type;
		}
		await saveBlock(b);
		body.innerHTML = '';
		buildProductFields(b, body);
	});
	body.appendChild(labelWrap('Type', typeSel));

	if (b.block_type === 'best_sellers_category') {
		const catSel = document.createElement('select');
		catSel.innerHTML = '<option value="">— Select category —</option>' +
			categories.map(x => `<option value="${x.id}"${s.category_id==x.id?' selected':''}>${esc(x.name)}</option>`).join('');
		catSel.addEventListener('change', async () => { b.settings.category_id = catSel.value; await saveBlock(b); });
		body.appendChild(labelWrap('Category', catSel));
	}
	body.appendChild(makeField('Heading', 'text',   { value:s.heading||'', key:'heading' }, b));
	body.appendChild(makeField('Count',   'number', { value:s.count||6, key:'count', min:1, max:24 }, b));
}

// ── Block fields (inline editing) ─────────────────────────────────────────────
function buildBlockFields(b, body) {
	const s = b.settings || {};
	switch (b.block_type) {
		case 'rich_text': {
			const ta = document.createElement('textarea');
			ta.className = 'pe-rte';
			ta.value = s.content || '';
			body.appendChild(ta);
			// Trumbowyg init deferred via initAllTrumbowyg()
			break;
		}
		case 'html':
			body.appendChild(makeField('HTML', 'textarea', { value:s.html||'', key:'html', rows:6, mono:true }, b));
			break;
		case 'slideshow': {
			const sel = document.createElement('select');
			sel.innerHTML = '<option value="">— Select slideshow —</option>' +
				slideshows.map(x => `<option value="${x.id}"${s.slideshow_id==x.id?' selected':''}>${esc(x.name)}</option>`).join('');
			sel.addEventListener('change', async () => { b.settings.slideshow_id = sel.value; await saveBlock(b); });
			body.appendChild(labelWrap('Slideshow', sel));
			break;
		}
		case 'featured_products': case 'best_sellers': case 'new_arrivals':
		case 'best_sellers_category': case 'related_products':
			buildProductFields(b, body);
			break;
		case 'menu': {
			const sel = document.createElement('select');
			sel.innerHTML = '<option value="">— Select menu —</option>' +
				menus.map(x => `<option value="${x.id}"${s.menu_id==x.id?' selected':''}>${esc(x.name)}</option>`).join('');
			sel.addEventListener('change', async () => { b.settings.menu_id = sel.value; await saveBlock(b); });
			body.appendChild(labelWrap('Menu', sel));
			break;
		}
		case 'cart_contents': case 'checkout_form': {
			const note = document.createElement('p');
			note.style.cssText = 'font-size:.8rem;color:var(--nc-text-dim);padding:.25rem 0';
			note.textContent = b.block_type === 'cart_contents'
				? 'Displays the shopping cart table and checkout button.'
				: 'Displays the checkout address and payment form.';
			body.appendChild(note);
			break;
		}
		case 'contact_form': case 'sitemap': {
			const note = document.createElement('p');
			note.style.cssText = 'font-size:.8rem;color:var(--nc-text-dim);padding:.25rem 0';
			note.textContent = b.block_type === 'sitemap' ? 'Renders site map automatically.' : 'Renders contact form.';
			body.appendChild(note);
			break;
		}
	}
}

function makeField(labelText, type, opts, b) {
	const wrap = document.createElement('div');
	wrap.className = 'pe-field';
	const lbl = document.createElement('label');
	lbl.textContent = labelText;

	let el;
	if (type === 'textarea') {
		el = document.createElement('textarea');
		el.rows = opts.rows || 4;
		if (opts.mono) el.style.fontFamily = 'monospace';
		el.value = opts.value || '';
	} else {
		el = document.createElement('input');
		el.type = type;
		el.value = opts.value || '';
		if (opts.min !== undefined) el.min = opts.min;
		if (opts.max !== undefined) el.max = opts.max;
	}

	let t;
	const doSave = async () => {
		if (!b.settings) b.settings = {};
		b.settings[opts.key] = el.value;
		await saveBlock(b);
	};
	el.addEventListener('input',  () => { clearTimeout(t); t = setTimeout(doSave, 600); });
	el.addEventListener('blur',   () => { clearTimeout(t); doSave(); });
	el.addEventListener('change', () => { clearTimeout(t); doSave(); }); // for selects/number

	wrap.appendChild(lbl);
	wrap.appendChild(el);
	return wrap;
}

function labelWrap(labelText, el) {
	const wrap = document.createElement('div');
	wrap.className = 'pe-field';
	const lbl = document.createElement('label');
	lbl.textContent = labelText;
	wrap.appendChild(lbl);
	wrap.appendChild(el);
	return wrap;
}

// ── Trumbowyg ────────────────────────────────────────────────────────────────
function initAllTrumbowyg() {
	if (!window.jQuery || !jQuery.fn.trumbowyg) return;
	document.querySelectorAll('.pe-rte').forEach(ta => {
		const $ta = jQuery(ta);
		if ($ta.data('trumbowyg')) return;
		const idx = parseInt(ta.closest('.pe-block-card')?.dataset.idx);
		$ta.trumbowyg({
			svgPath: NC.rootUrl + 'js/vendor/trumbowyg/src/ui/icons.svg',
			btns: [
				['bold','italic','underline'],
				['link'],
				['unorderedList','orderedList'],
				['indent','outdent'],
				['viewHTML']
			].concat(window.ncTrumbowygExtraBtns || [])
		});
		// Add FM image button
		setTimeout(() => {
			const toolbar = $ta.closest('.trumbowyg-box')[0]?.querySelector('.trumbowyg-button-pane');
			if (!toolbar || toolbar.querySelector('.nc-fm-img-btn')) return;
			const fmBtn = document.createElement('button');
			fmBtn.type = 'button';
			fmBtn.className = 'nc-fm-img-btn';
			fmBtn.style.cssText = 'padding:2px 6px;cursor:pointer;border:none;background:none;font-size:14px;height:35px;color:var(--nc-text,#333)';
			fmBtn.title = 'Insert image';
			fmBtn.textContent = '🖼';
			fmBtn.addEventListener('mousedown', e => e.preventDefault());
			fmBtn.addEventListener('click', e => {
				e.preventDefault();
				if (!window.openFilePicker) return;
				openFilePicker(items => {
					if (!items.length) return;
					const ed = $ta.closest('.trumbowyg-box')[0]?.querySelector('.trumbowyg-editor');
					if (ed) ed.focus();
					var img = '<img src="' + items[0].url + '" alt="" style="max-width:100%">';
					$ta.trumbowyg('execCmd', { cmd:'insertHTML', param:img, forceCss:false });
				});
			});
			toolbar.appendChild(fmBtn);
		}, 0);

		// Wire image click → properties panel
		const editor = $ta.closest('.trumbowyg-box')[0]?.querySelector('.trumbowyg-editor');
		if (editor) {
			editor.addEventListener('click', e => {
				if (e.target.tagName === 'IMG') openImgPanel(e.target, e);
			});
		}

		// Save on change
		$ta.on('tbwchange tbwblur', async function() {
			if (isNaN(idx) || !blocks[idx]) return;
			if (!blocks[idx].settings) blocks[idx].settings = {};
			blocks[idx].settings.content = $ta.trumbowyg('html');
			await saveBlock(blocks[idx]);
		});
	});
}

// ── Image properties panel ────────────────────────────────────────────────────
const imgPanel  = document.getElementById('pe-img-panel');
let   imgTarget = null;

function openImgPanel(img, e) {
	imgTarget = img;
	const style = img.style;
	document.getElementById('pi-border').value  = parseInt(style.borderWidth) || img.getAttribute('border') || 0;
	document.getElementById('pi-align').value   = img.getAttribute('align') || style.float || '';
	document.getElementById('pi-hspace').value  = parseInt(style.marginLeft) || img.getAttribute('hspace') || 0;
	document.getElementById('pi-vspace').value  = parseInt(style.marginTop)  || img.getAttribute('vspace') || 0;
	const link = img.closest('a');
	document.getElementById('pi-link').value    = link ? link.href : '';
	document.getElementById('pi-target').value  = link ? (link.target || '') : '';

	// Position near image
	const rect = img.getBoundingClientRect();
	imgPanel.style.display = '';
	imgPanel.style.top  = Math.min(rect.bottom + 8, window.innerHeight - 380) + 'px';
	imgPanel.style.left = Math.min(rect.left, window.innerWidth - 280) + 'px';
	document.getElementById('pi-border').focus();
}

document.getElementById('pe-img-panel-close').addEventListener('click', () => {
	imgPanel.style.display = 'none'; imgTarget = null;
});

document.addEventListener('mousedown', e => {
	if (imgPanel.style.display !== 'none' && !imgPanel.contains(e.target) && e.target !== imgTarget) {
		imgPanel.style.display = 'none'; imgTarget = null;
	}
});

document.getElementById('pe-img-panel-save').addEventListener('click', () => {
	if (!imgTarget) return;
	const border  = parseInt(document.getElementById('pi-border').value) || 0;
	const align   = document.getElementById('pi-align').value;
	const hspace  = parseInt(document.getElementById('pi-hspace').value) || 0;
	const vspace  = parseInt(document.getElementById('pi-vspace').value) || 0;
	const linkUrl = document.getElementById('pi-link').value.trim();
	const target  = document.getElementById('pi-target').value;

	// Apply styles
	imgTarget.style.borderWidth = border ? border + 'px' : '';
	imgTarget.style.borderStyle = border ? 'solid' : '';
	imgTarget.style.float       = (align === 'left' || align === 'right') ? align : '';
	imgTarget.style.display     = align === 'center' ? 'block' : '';
	imgTarget.style.margin      = align === 'center' ? '0 auto' : '';
	imgTarget.style.marginLeft  = hspace ? hspace + 'px' : '';
	imgTarget.style.marginRight = hspace ? hspace + 'px' : '';
	imgTarget.style.marginTop   = vspace ? vspace + 'px' : '';
	imgTarget.style.marginBottom= vspace ? vspace + 'px' : '';

	// Wrap/unwrap link
	const existingLink = imgTarget.closest('a');
	if (linkUrl) {
		if (existingLink) {
			existingLink.href   = linkUrl;
			existingLink.target = target;
		} else {
			const a = document.createElement('a');
			a.href   = linkUrl;
			a.target = target;
			imgTarget.parentNode.insertBefore(a, imgTarget);
			a.appendChild(imgTarget);
		}
	} else if (existingLink) {
		existingLink.parentNode.insertBefore(imgTarget, existingLink);
		existingLink.remove();
	}

	// Trigger save on the containing block
	const card = imgTarget.closest('.pe-block-card');
	if (card) {
		const idx = parseInt(card.dataset.idx);
		const $ta = jQuery(card).find('.pe-rte');
		if ($ta.length && $ta.data('trumbowyg')) {
			blocks[idx].settings.content = $ta.trumbowyg('html');
			saveBlock(blocks[idx]);
		}
	}
	imgPanel.style.display = 'none'; imgTarget = null;
});

// Canvas-level drag delegation — handles both skeleton and card drops
document.getElementById('pe-canvas')?.addEventListener('dragover', e => {
	e.preventDefault();
	// Highlight card under cursor
	const card = e.target.closest('.pe-block-card');
	document.querySelectorAll('.pe-block-card.drag-over').forEach(c2 => {
		if (c2 !== card) c2.classList.remove('drag-over');
	});
	if (card) card.classList.add('drag-over');
});

document.getElementById('pe-canvas')?.addEventListener('dragleave', e => {
	if (!e.relatedTarget || !e.relatedTarget.closest('#pe-canvas')) {
		document.querySelectorAll('.pe-block-card.drag-over').forEach(c2 => c2.classList.remove('drag-over'));
	}
});

document.getElementById('pe-canvas')?.addEventListener('drop', async e => {
	e.preventDefault();
	document.querySelectorAll('.pe-block-card.drag-over').forEach(c2 => c2.classList.remove('drag-over'));

	const raw = e.dataTransfer.getData('text/plain') || '';

	// Check if dropped on a card
	const cardEl = e.target.closest('.pe-block-card');
	if (cardEl && cardEl.dataset.blockId) {
		const b = blocks.find(x => x.id === parseInt(cardEl.dataset.blockId));
		if (!b) return;

		// Palette drop onto card
		if (raw && !raw.startsWith('block:')) {
			const rows = layoutRows(blocks.slice());
			let dropRowIdx = rows.length;
			for (let ri = 0; ri < rows.length; ri++) {
				if (rows[ri].includes(b)) { dropRowIdx = ri; break; }
			}
			await addBlockAtRow(raw, b.col_start, dropRowIdx);
			return;
		}

		// Block reorder onto card
		if (raw.startsWith('block:')) {
			const srcId = parseInt(raw.slice(6));
			const srcI  = blocks.findIndex(x => x.id === srcId);
			const dstI  = blocks.indexOf(b);
			if (srcI < 0 || dstI < 0 || srcI === dstI) return;
			const moved = blocks.splice(srcI, 1)[0];
			const adjustedDst = srcI < dstI ? dstI - 1 : dstI;
			blocks.splice(adjustedDst, 0, moved);
			assignAndSaveRows();
			const ids = blocks.filter(x => x.id).map(x => x.id);
			if (ids.length) await post({ action:'reorder_blocks', ids:JSON.stringify(ids) });
			renderCanvas();
			return;
		}
	}
	// Dropped on skeleton — handled by skeleton's own drop listener
});

// ── Add block ─────────────────────────────────────────────────────────────────
// ── Place block at a specific row position ────────────────────────────────────
// Figures out the right insertion index in blocks[] for the given rowIdx,
// then calls addBlock with the resolved col_start.
async function addBlockAtRow(type, targetCol, rowIdx) {
	const rows = layoutRows(blocks.slice()); // non-mutating snapshot
	const isBottomRow = rowIdx >= rows.length;

	if (isBottomRow) {
		// Bottom skeleton row — just append
		await addBlock(type, targetCol, 1);
		return;
	}

	const row = rows[rowIdx];
	// Which blocks are in this row?
	const rowBlocks = row; // already the block objects

	// Determine occupied columns
	const occupied = new Set();
	rowBlocks.forEach(b => {
		for (let col = b.col_start; col < b.col_start + b.col_span; col++) occupied.add(col);
	});

	// targetCol is empty — use it directly
	if (!occupied.has(targetCol)) {
		const insertAfterIdx = _rowInsertIdx(rowIdx, rows, targetCol);
		await addBlockAt(type, targetCol, 1, insertAfterIdx);
		return;
	}

	// targetCol is occupied — find nearest empty col in this row
	let nearest = null, nearestDist = 999;
	for (let col = 1; col <= 4; col++) {
		if (!occupied.has(col)) {
			const dist = Math.abs(col - targetCol);
			if (dist < nearestDist) { nearest = col; nearestDist = dist; }
		}
	}
	if (nearest !== null) {
		const insertAfterIdx = _rowInsertIdx(rowIdx, rows, nearest);
		await addBlockAt(type, nearest, 1, insertAfterIdx);
		return;
	}

	// Row is completely full — shift everything right from col 1,
	// compress the last block by 1 col_span, new block gets col 1 span 1
	const lastInRow = rowBlocks[rowBlocks.length - 1];
	if (lastInRow.col_span > 1) {
		lastInRow.col_span -= 1;
		await saveBlock(lastInRow);
	}
	// Shift all blocks in the row right by 1
	for (const rb of rowBlocks) {
		if (rb !== lastInRow || lastInRow.col_span >= 1) {
			rb.col_start = Math.min(4, rb.col_start + 1);
			await saveBlock(rb);
		}
	}
	const insertAfterIdx = _rowInsertIdx(rowIdx, rows, 1);
	await addBlockAt(type, 1, 1, insertAfterIdx);
}

// Find the blocks[] insertion index for a new block at targetCol in a given row.
// Inserts before any existing block in the row with a higher col_start,
// or after all existing blocks if the new block goes at the end.
function _rowInsertIdx(rowIdx, rows, targetCol) {
	if (rowIdx >= rows.length) return blocks.length;
	const rowBlocks = rows[rowIdx];
	// Find first block in this row whose col_start > targetCol
	for (const rb of rowBlocks) {
		if (targetCol !== undefined && rb.col_start > targetCol) {
			return blocks.indexOf(rb); // insert before this block
		}
	}
	// targetCol is after all existing blocks — insert after the last one
	const lastBlockInRow = rowBlocks[rowBlocks.length - 1];
	return blocks.indexOf(lastBlockInRow) + 1;
}

// Add a new block at a specific position in blocks[]
async function addBlockAt(type, colStart, colSpan, insertIdx) {
	if (!pageId) { const ok = await savePage(); if (!ok) return; }
	const res = await post({
		action:'save_block', id:0, page_id:pageId,
		block_type:type, settings:'{}', enabled:1,
		cols:colSpan||1, col_start:colStart||1, col_span:colSpan||1, row:0,
	});
	if (!res.ok) { notifyErr(res.message || 'Could not add block.'); return; }
	const b = { ...res.block, settings:res.block.settings||{}, col_start:colStart||1, col_span:colSpan||1, row:0, row_span:1 };
	blocks.splice(insertIdx, 0, b);
	// Re-save display_order and row for all blocks
	assignAndSaveRows();
	const ids = blocks.filter(x => x.id).map(x => x.id);
	if (ids.length) await post({ action:'reorder_blocks', ids:JSON.stringify(ids) });
	renderCanvas();
}

async function addBlock(type, colStart, colSpan) {
	if (!pageId) {
		const ok = await savePage();
		if (!ok) return;
	}
	const nextRow = blocks.length ? Math.max(...blocks.map(b => b.row || 0)) + 1 : 0;
	const res = await post({
		action:     'save_block',
		id:         0,
		page_id:    pageId,
		block_type: type,
		settings:   '{}',
		enabled:    1,
		cols:       colSpan || 4,
		col_start:  colStart || 1,
		col_span:   colSpan  || 4,
		row:        nextRow,
		row_span:   1,
	});
	if (!res.ok) { notifyErr(res.message || 'Could not add block.'); return; }
	const b = { ...res.block, settings:res.block.settings||{}, col_start:colStart||1, col_span:colSpan||4, row:nextRow, row_span:1 };
	blocks.push(b);
	renderCanvas();
	// Scroll to new card
	const cards = document.querySelectorAll('.pe-block-card');
	cards[cards.length-1]?.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

// Palette clicks + drag
// Setting draggable on <button> via JS property is unreliable in some browsers.
// We use mousedown to track which button is being dragged, then handle
// dragstart on document to capture it regardless of element type.
document.querySelectorAll('.pe-pal-btn').forEach(btn => {
	if (!btn.disabled && !btn.dataset.nodrag) {
		btn.setAttribute('draggable', 'true');
		btn.addEventListener('dragstart', e => {
			dragType = btn.dataset.type;
			e.dataTransfer.effectAllowed = 'copy';
			try { e.dataTransfer.setData('text/plain', btn.dataset.type); } catch(_) {}
		});
		btn.addEventListener('dragend', () => { dragType = null; });
	}
	btn.addEventListener('click', () => {
		if (!btn.disabled) addBlock(btn.dataset.type, 1, 4);
	});
});

// ── Save block ────────────────────────────────────────────────────────────────
async function saveBlock(b) {
	if (!b?.id || !pageId) return;
	await post({
		action:     'save_block',
		id:         b.id,
		page_id:    pageId,
		block_type: b.block_type,
		settings:   JSON.stringify(b.settings || {}),
		enabled:    b.enabled ?? 1,
		cols:       b.col_span  || 4,
		col_start:  b.col_start || 1,
		col_span:   b.col_span  || 4,
		row:        b.row       || 0,
		row_span:   b.row_span  || 1,
	});
}

// ── Save block name / library ─────────────────────────────────────────────────
async function saveBlockName(b, name) {
	b.name = name;
	await saveBlock(b);
	if (name) {
		// Save to library
		await ajax(AJAX_LIB, { action:'save', block_id:b.id, name });
		loadLibrary();
	}
}

async function loadLibrary() {
	const list = document.getElementById('pe-library-list');
	if (!list) return;
	const res = await ajax(AJAX_LIB, { action:'list' });
	if (!res.ok || !res.items?.length) {
		list.innerHTML = '<p class="pe-library-empty">No saved blocks yet.</p>';
		return;
	}
	list.innerHTML = '';
	res.items.forEach(item => {
		const el = document.createElement('div');
		el.className = 'pe-library-item';
		el.textContent = item.name;
		el.title = 'Click to insert';
		el.addEventListener('click', () => insertLibraryBlock(item));
		list.appendChild(el);
	});
}

async function insertLibraryBlock(item) {
	if (!pageId) { const ok = await savePage(); if (!ok) return; }
	// Insert a reference block (block_type = 'library_ref', settings.library_id)
	const res = await post({
		action:'save_block', id:0, page_id:pageId,
		block_type:'library_ref', settings:JSON.stringify({library_id:item.id}),
		enabled:1, cols:4, col_start:1, col_span:4,
	});
	if (!res.ok) { notifyErr('Could not insert block.'); return; }
	blocks.push({ ...res.block, settings:res.block.settings||{}, col_start:1, col_span:4 });
	renderCanvas();
}

// ── Save page ─────────────────────────────────────────────────────────────────
async function savePage() {
	const title = document.getElementById('pe-title').value.trim();
	if (!title) { notifyErr('Title is required.'); return false; }

	// Flush Trumbowyg
	if (window.jQuery) {
		document.querySelectorAll('.pe-rte').forEach(ta => {
			const $ta = jQuery(ta);
			if ($ta.data('trumbowyg')) {
				const idx = parseInt(ta.closest('.pe-block-card')?.dataset.idx);
				if (!isNaN(idx) && blocks[idx]) {
					if (!blocks[idx].settings) blocks[idx].settings = {};
					blocks[idx].settings.content = $ta.trumbowyg('html');
				}
			}
		});
	}

	const res = await post({
		action:          'save',
		id:              pageId,
		title,
		slug:            document.getElementById('pe-slug').value.trim(),
		status:          document.getElementById('pe-status').value,
		is_home:         (document.getElementById('pe-is-home')?.checked) ? 1 : 0,
		seo_title:       document.getElementById('pe-seo-title').value,
		seo_keywords:    document.getElementById('pe-seo-keywords').value,
		seo_description: document.getElementById('pe-seo-desc').value,
	});
	if (!res.ok) { notifyErr(res.message); return false; }
	if (res.page?.id && res.page.id != pageId) {
		pageId = res.page.id;
		document.getElementById('pe-page-id').value = pageId;
		document.getElementById('pe-preview-btn').style.display = '';
		const url = new URL(window.location.href);
		url.searchParams.set('id', pageId);
		history.replaceState({}, '', url);
	}
	SimpleNotification.success({ text: 'Page saved.' });
	return true;
}

document.getElementById('pe-save-btn').addEventListener('click', savePage);

(function() {
	const btn = document.getElementById('pe-clear-btn');
	if (!btn) return;
	let armed = false, armTimer;
	btn.addEventListener('click', async function() {
		if (!blocks.length) return;
		if (!armed) {
			armed = true;
			btn.textContent = 'Confirm clear';
			btn.classList.add('pe-btn-danger');
			armTimer = setTimeout(() => {
				armed = false;
				btn.textContent = 'Clear Canvas';
				btn.classList.remove('pe-btn-danger');
			}, 3000);
			return;
		}
		clearTimeout(armTimer);
		armed = false;
		btn.textContent = 'Clear Canvas';
		btn.classList.remove('pe-btn-danger');

		const toDelete = blocks.filter(b => !b.is_core);
		const toKeep   = blocks.filter(b => b.is_core);
		for (const b of toDelete) {
			if (b.id) await post({ action:'delete_block', id:b.id });
		}
		blocks = toKeep;
		renderCanvas();

		// Flash core cards to indicate they were preserved
		if (toKeep.length) {
			setTimeout(() => {
				document.querySelectorAll('.pe-block-card.pe-core').forEach(card => {
					card.classList.add('pe-core-flash');
					card.addEventListener('animationend', () => card.classList.remove('pe-core-flash'), { once:true });
				});
			}, 0);
		}
	});
}());

// ── Properties drawer ──────────────────────────────────────────────────────
// me-drawer.close() wipes innerHTML so we manage CSS directly to keep static content.
const propsDrawer  = document.getElementById('pe-props-drawer');
const propsOverlay = (function() {
	// Reuse the overlay me-drawer would create, or make our own
	let ov = document.getElementById('pe-props-overlay');
	if (!ov) {
		ov = document.createElement('div');
		ov.id = 'pe-props-overlay';
		ov.style.cssText = 'display:none;position:fixed;inset:0;z-index:499;background:rgba(0,0,0,0.4);';
		document.body.appendChild(ov);
	}
	return ov;
}());

function openPropsDrawer() {
	if (!propsDrawer) return;
	propsDrawer.classList.add('open', 'slideInRight');
	propsOverlay.style.display = 'block';
}
function closePropsDrawer() {
	if (!propsDrawer) return;
	propsDrawer.classList.remove('open', 'slideInRight');
	propsOverlay.style.display = 'none';
}

document.getElementById('pe-props-btn')?.addEventListener('click', openPropsDrawer);
document.getElementById('pe-props-close')?.addEventListener('click', closePropsDrawer);
document.getElementById('pe-props-save')?.addEventListener('click', async () => {
	await savePage();
	closePropsDrawer();
});
propsOverlay.addEventListener('click', closePropsDrawer);

document.getElementById('pe-preview-btn')?.addEventListener('click', async () => {
	if (!pageId) return;
	const res = await post({ action:'preview_token', id:pageId });
	if (!res.ok) { notifyErr(res.message || 'Could not generate preview.'); return; }
	window.open(NC.rootUrl + 'page-preview/' + res.token, '_blank', 'noopener');
});

// ── Page switcher ─────────────────────────────────────────────────────────────
document.getElementById('pe-page-switcher')?.addEventListener('change', function() {
	if (this.value) window.location.href = NC.adminUrl + '?route=page-edit&id=' + this.value;
});

// ── Init ──────────────────────────────────────────────────────────────────────
load();

})();
