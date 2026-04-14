/**
 * new-cart — Full-screen page editor
 */
(function() {
'use strict';

const AJAX = NC.adminUrl + '?route=pages/ajax';
function ajax(data) {
	const fd = new FormData();
	for (const [k,v] of Object.entries(data)) fd.append(k, String(v ?? ''));
	return fetch(AJAX, { method:'POST', body:fd }).then(r => r.json());
}
function notifyErr(m) { SimpleNotification.error({ text: m }); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

const BLOCK_LABELS = {
	rich_text:'Rich Text', html:'Custom HTML', slideshow:'Slideshow',
	featured_products:'Featured Products', best_sellers:'Best Sellers',
	best_sellers_category:'Best Sellers by Category', new_arrivals:'New Arrivals',
	contact_form:'Contact Form', sitemap:'Site Map',
};

let blocks       = [];    // [{id, block_type, settings:{}, enabled, cols}]
let selectedIdx  = null;
let dragSrc      = null;
let colDragging  = null;

const canvas     = document.getElementById('pe-canvas');
const emptyMsg   = document.getElementById('pe-canvas-empty');
const propsEmpty = document.getElementById('pe-props-empty');
const propsContent = document.getElementById('pe-props-content');
const pageIdEl   = document.getElementById('pe-page-id');

// Parse picker data from hidden inputs
function parsePipeData(id) {
	const raw = document.getElementById(id)?.value || '';
	return raw.split('|').filter(Boolean).map(s => {
		const idx = s.indexOf(':');
		return { id: s.slice(0, idx), name: s.slice(idx+1) };
	});
}
const slideshows = parsePipeData('pe-slideshows-data');
const categories = parsePipeData('pe-categories-data');

// ── Load existing blocks ──────────────────────────────────────────────────────
async function load() {
	const id = parseInt(pageIdEl.value);
	if (!id) return;
	const res = await ajax({ action:'get', id });
	if (!res.ok) return;
	blocks = (res.blocks || []).map(b => ({
		...b,
		settings: b.settings || {},
		cols: b.cols || 4,
	}));
	renderCanvas();
}

// ── Render canvas ─────────────────────────────────────────────────────────────
function renderCanvas() {
	canvas.innerHTML = '';
	if (!blocks.length) {
		canvas.appendChild(emptyMsg);
		emptyMsg.style.display = '';
		return;
	}
	emptyMsg.style.display = 'none';
	blocks.forEach((b, idx) => {
		const card = buildCard(b, idx);
		canvas.appendChild(card);
	});
	// Re-init Trumbowyg for rich_text blocks
	initTrumbowyg();
}

function buildCard(b, idx) {
	const card = document.createElement('div');
	card.className = 'pe-block-card' + (b.enabled ? '' : ' pe-disabled');
	card.setAttribute('data-cols', b.cols || 4);
	card.setAttribute('data-idx', idx);
	card.setAttribute('role', 'listitem');
	card.draggable = true;

	if (selectedIdx === idx) card.classList.add('selected');

	card.innerHTML =
		`<div class="pe-block-card-head">` +
		`<span class="drag-handle" aria-hidden="true">⠿</span>` +
		`<span class="pe-block-label">${esc(BLOCK_LABELS[b.block_type] || b.block_type)}</span>` +
		`<span class="pe-block-cols-badge" aria-label="Column span">${b.cols || 4}/4</span>` +
		`</div>` +
		`<div class="pe-block-preview">${blockPreview(b)}</div>` +
		`<div class="pe-col-handle" title="Drag to resize columns" aria-hidden="true"></div>`;

	// Select block
	card.addEventListener('click', e => {
		if (e.target.closest('.pe-col-handle') || e.target.closest('.drag-handle')) return;
		selectBlock(idx);
	});

	// Drag to reorder
	card.addEventListener('dragstart', e => {
		if (e.target.closest('.pe-col-handle')) { e.preventDefault(); return; }
		dragSrc = idx;
		card.classList.add('pe-drag-source');
		e.dataTransfer.effectAllowed = 'move';
	});
	card.addEventListener('dragend', () => card.classList.remove('pe-drag-source'));
	card.addEventListener('dragover', e => { e.preventDefault(); card.classList.add('drag-over'); });
	card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
	card.addEventListener('drop', async e => {
		e.preventDefault();
		card.classList.remove('drag-over');
		if (dragSrc === null || dragSrc === idx) return;
		const moved = blocks.splice(dragSrc, 1)[0];
		blocks.splice(idx, 0, moved);
		if (selectedIdx === dragSrc) selectedIdx = idx;
		else if (selectedIdx !== null) {
			if (dragSrc < selectedIdx && idx >= selectedIdx) selectedIdx--;
			else if (dragSrc > selectedIdx && idx <= selectedIdx) selectedIdx++;
		}
		dragSrc = null;
		renderCanvas();
		await saveOrder();
	});

	// Column resize handle
	const handle = card.querySelector('.pe-col-handle');
	handle.addEventListener('mousedown', e => {
		e.preventDefault();
		e.stopPropagation();
		colDragging = { idx, startX: e.clientX, startCols: b.cols || 4 };
		const gridWidth = canvas.getBoundingClientRect().width;
		const colWidth  = gridWidth / 4;

		function onMove(me) {
			const dx   = me.clientX - colDragging.startX;
			const newC = Math.max(1, Math.min(4, Math.round(colDragging.startCols + dx / colWidth)));
			if (newC !== blocks[colDragging.idx].cols) {
				blocks[colDragging.idx].cols = newC;
				card.setAttribute('data-cols', newC);
				card.querySelector('.pe-block-cols-badge').textContent = newC + '/4';
				if (selectedIdx === idx) renderProps(idx);
			}
		}
		async function onUp() {
			document.removeEventListener('mousemove', onMove);
			document.removeEventListener('mouseup', onUp);
			colDragging = null;
			await saveBlock(idx);
		}
		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup', onUp);
	});

	return card;
}

function blockPreview(b) {
	const s = b.settings || {};
	switch (b.block_type) {
		case 'rich_text':
			return `<p>${s.content ? s.content.replace(/<[^>]+>/g,'').slice(0,80) : '(empty)'}</p>`;
		case 'html':
			return `<p>${s.html ? s.html.replace(/<[^>]+>/g,'').slice(0,80) : '(empty)'}</p>`;
		case 'slideshow':
			return `<p>Slideshow${s.slideshow_id ? ' #'+s.slideshow_id : ' (none selected)'}</p>`;
		case 'featured_products': case 'best_sellers': case 'new_arrivals':
			return `<p>${s.heading || BLOCK_LABELS[b.block_type]} &mdash; ${s.count||6} items</p>`;
		case 'best_sellers_category':
			return `<p>Best Sellers by Category${s.category_id ? ' #'+s.category_id : ''}</p>`;
		case 'contact_form': return `<p>Contact Form</p>`;
		case 'sitemap':      return `<p>Site Map</p>`;
		default:             return `<p>(${b.block_type})</p>`;
	}
}

// ── Select block & show props ─────────────────────────────────────────────────
function selectBlock(idx) {
	selectedIdx = idx;
	document.querySelectorAll('.pe-block-card').forEach((c, i) =>
		c.classList.toggle('selected', i === idx));
	renderProps(idx);
}

function renderProps(idx) {
	const b = blocks[idx];
	if (!b) { propsContent.style.display='none'; propsEmpty.style.display=''; return; }
	propsEmpty.style.display   = 'none';
	propsContent.style.display = '';

	const s = b.settings || {};
	let html = '';

	// Column width
	html += `<div class="pe-props-section-head">Width</div>`;
	html += `<div class="pe-props-col-btns">`;
	for (let c=1; c<=4; c++) {
		html += `<button class="pe-col-btn${(b.cols||4)===c?' active':''}" data-cols="${c}">${c}</button>`;
	}
	html += `</div>`;

	// Enabled toggle
	html += `<div class="df-toggle" style="margin-bottom:.6rem">` +
		`<ios-toggle id="prop-enabled" size="sm" ${b.enabled?'checked':''}></ios-toggle>` +
		`<label for="prop-enabled">Enabled</label></div>`;

	// Block-specific fields
	html += `<div class="pe-props-section-head">Content</div>`;
	switch (b.block_type) {
		case 'rich_text':
			html += `<div class="df"><label>Content</label>` +
				`<textarea id="prop-content" rows="6">${esc(s.content||'')}</textarea></div>`;
			break;
		case 'html':
			html += `<div class="df"><label>HTML</label>` +
				`<textarea id="prop-html" rows="6" style="font-family:monospace">${esc(s.html||'')}</textarea></div>`;
			break;
		case 'slideshow':
			html += `<div class="df"><label>Slideshow</label><select id="prop-slideshow">` +
				`<option value="">— Select —</option>` +
				slideshows.map(s2 => `<option value="${s2.id}"${s.slideshow_id==s2.id?' selected':''}>${esc(s2.name)}</option>`).join('') +
				`</select></div>`;
			break;
		case 'featured_products': case 'best_sellers': case 'new_arrivals':
			html += `<div class="df"><label>Heading</label><input type="text" id="prop-heading" value="${esc(s.heading||'')}"></div>` +
				`<div class="df"><label>Count</label><input type="number" id="prop-count" value="${s.count||6}" min="1" max="24"></div>`;
			break;
		case 'best_sellers_category':
			html += `<div class="df"><label>Category</label><select id="prop-category">` +
				`<option value="">— Select —</option>` +
				categories.map(c2 => `<option value="${c2.id}"${s.category_id==c2.id?' selected':''}>${esc(c2.name)}</option>`).join('') +
				`</select></div>` +
				`<div class="df"><label>Count</label><input type="number" id="prop-count" value="${s.count||6}" min="1" max="24"></div>`;
			break;
	}

	// Delete
	html += `<button class="pe-delete-btn" id="prop-delete">Remove Block</button>`;

	propsContent.innerHTML = html;

	// Wire props
	propsContent.querySelectorAll('.pe-col-btn').forEach(btn => {
		btn.addEventListener('click', async function() {
			const cols = parseInt(this.dataset.cols);
			blocks[idx].cols = cols;
			renderCanvas();
			selectBlock(idx);
			await saveBlock(idx);
		});
	});

	const enabledTog = document.getElementById('prop-enabled');
	if (enabledTog) {
		enabledTog.addEventListener('ios-toggle', async e => {
			blocks[idx].enabled = e.detail.checked ? 1 : 0;
			document.querySelectorAll('.pe-block-card')[idx]?.classList.toggle('pe-disabled', !e.detail.checked);
			await saveBlock(idx);
		});
	}

	// Content field auto-save
	const autoSaveFields = {
		'prop-content':   'content',
		'prop-html':      'html',
		'prop-heading':   'heading',
	};
	Object.entries(autoSaveFields).forEach(([id, key]) => {
		const el = document.getElementById(id);
		if (!el) return;
		let t;
		el.addEventListener('input', () => {
			clearTimeout(t);
			t = setTimeout(async () => {
				if (!blocks[idx].settings) blocks[idx].settings = {};
				blocks[idx].settings[key] = el.value;
				updatePreview(idx);
				await saveBlock(idx);
			}, 600);
		});
		el.addEventListener('blur', async () => {
			clearTimeout(t);
			if (!blocks[idx].settings) blocks[idx].settings = {};
			blocks[idx].settings[key] = el.value;
			updatePreview(idx);
			await saveBlock(idx);
		});
	});

	const selects = { 'prop-slideshow':'slideshow_id', 'prop-category':'category_id', 'prop-count':'count' };
	Object.entries(selects).forEach(([id, key]) => {
		const el = document.getElementById(id);
		if (!el) return;
		el.addEventListener('change', async () => {
			if (!blocks[idx].settings) blocks[idx].settings = {};
			blocks[idx].settings[key] = el.value;
			updatePreview(idx);
			await saveBlock(idx);
		});
	});

	document.getElementById('prop-delete')?.addEventListener('click', async () => {
		const card = document.querySelectorAll('.pe-block-card')[idx];
		if (card) { card.style.transition='opacity .3s'; card.style.opacity='0'; }
		if (blocks[idx].id) await ajax({ action:'delete_block', id:blocks[idx].id });
		setTimeout(() => {
			blocks.splice(idx, 1);
			selectedIdx = null;
			propsContent.style.display = 'none';
			propsEmpty.style.display   = '';
			renderCanvas();
		}, 320);
	});

	// Init Trumbowyg for rich_text in props
	if (b.block_type === 'rich_text' && window.jQuery) {
		const $ta = jQuery('#prop-content');
		if ($ta.length && !$ta.data('trumbowyg')) {
			$ta.trumbowyg({
				svgPath: NC.rootUrl + 'js/vendor/trumbowyg/src/ui/icons.svg',
				btns: [['bold','italic','underline'],['link'],['unorderedList','orderedList'],['indent','outdent'],['viewHTML']]
			});
			$ta.on('tbwchange tbwblur', async function() {
				if (!blocks[idx].settings) blocks[idx].settings = {};
				blocks[idx].settings.content = $ta.trumbowyg('html');
				updatePreview(idx);
				await saveBlock(idx);
			});
		}
	}
}

function updatePreview(idx) {
	const cards = document.querySelectorAll('.pe-block-card');
	const preview = cards[idx]?.querySelector('.pe-block-preview');
	if (preview) preview.innerHTML = blockPreview(blocks[idx]);
}

// ── Add block ─────────────────────────────────────────────────────────────────
document.querySelectorAll('.pe-block-type-btn').forEach(btn => {
	btn.addEventListener('click', async function() {
		const type   = this.dataset.type;
		const pageId = pageIdEl.value;
		if (!pageId) {
			// Save page first
			const saved = await savePage();
			if (!saved) return;
		}
		const res = await ajax({
			action:     'save_block',
			id:         0,
			page_id:    pageIdEl.value,
			block_type: type,
			settings:   '{}',
			enabled:    1,
			cols:       4,
		});
		if (!res.ok) { notifyErr(res.message || 'Could not add block.'); return; }
		const b = { ...res.block, settings: res.block.settings || {}, cols: 4 };
		blocks.push(b);
		renderCanvas();
		selectBlock(blocks.length - 1);
		// Scroll to new block
		const cards = document.querySelectorAll('.pe-block-card');
		cards[cards.length-1]?.scrollIntoView({ behavior:'smooth', block:'nearest' });
	});
});

// ── Save block ────────────────────────────────────────────────────────────────
async function saveBlock(idx) {
	const b      = blocks[idx];
	const pageId = pageIdEl.value;
	if (!b?.id || !pageId) return;
	// Flush Trumbowyg if active
	if (b.block_type === 'rich_text' && window.jQuery) {
		const $ta = jQuery('#prop-content');
		if ($ta.length && $ta.data('trumbowyg')) {
			b.settings.content = $ta.trumbowyg('html');
		}
	}
	await ajax({
		action:     'save_block',
		id:         b.id,
		page_id:    pageId,
		block_type: b.block_type,
		settings:   JSON.stringify(b.settings || {}),
		enabled:    b.enabled ?? 1,
		cols:       b.cols || 4,
	});
}

async function saveOrder() {
	const ids = blocks.filter(b => b.id).map(b => b.id);
	if (ids.length) await ajax({ action:'reorder_blocks', ids:JSON.stringify(ids) });
}

// ── Save page ─────────────────────────────────────────────────────────────────
async function savePage() {
	const title = document.getElementById('pe-title').value.trim();
	if (!title) { notifyErr('Title is required.'); return false; }
	const res = await ajax({
		action:          'save',
		id:              pageIdEl.value,
		title,
		slug:            document.getElementById('pe-slug').value.trim(),
		status:          document.getElementById('pe-status').value,
		seo_title:       document.getElementById('pe-seo-title').value,
		seo_keywords:    document.getElementById('pe-seo-keywords').value,
		seo_description: document.getElementById('pe-seo-desc').value,
	});
	if (!res.ok) { notifyErr(res.message); return false; }
	if (res.page?.id) {
		pageIdEl.value = res.page.id;
		document.getElementById('pe-preview-btn').style.display = '';
		// Update URL without reload
		const url = new URL(window.location.href);
		url.searchParams.set('id', res.page.id);
		history.replaceState({}, '', url);
	}
	return true;
}

document.getElementById('pe-save-btn').addEventListener('click', savePage);

document.getElementById('pe-preview-btn')?.addEventListener('click', async function() {
	const id = pageIdEl.value;
	if (!id) return;
	const res = await ajax({ action:'preview_token', id });
	if (!res.ok) { notifyErr(res.message || 'Could not generate preview.'); return; }
	window.open(NC.rootUrl + 'page-preview/' + res.token, '_blank', 'noopener');
});

// ── Trumbowyg init ────────────────────────────────────────────────────────────
function initTrumbowyg() {
	// Handled in renderProps when a rich_text block is selected
}

// ── Init ──────────────────────────────────────────────────────────────────────
load();

})();
