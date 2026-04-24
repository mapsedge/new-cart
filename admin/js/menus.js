(function () {
'use strict';

const AJAX = NC.adminUrl + '?route=menus/ajax';
function ajax(data) {
	const fd = new FormData();
	for (const [k,v] of Object.entries(data)) fd.append(k, String(v ?? ''));
	return fetch(AJAX, { method:'POST', body:fd }).then(r => r.json());
}
function notifyErr(m) { SimpleNotification.error({ text: m }); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

const NAMES = ['otter','dolphin','raccoon','fox','squirrel','rabbit','lemur','raven','seal','wombat'];
const ADJS  = ['nimble','deft','agile','clever','deft','dexterous','spry','skilled','adroit','crafty'];
function randomName() {
	return ADJS[Math.floor(Math.random()*ADJS.length)] + ' ' + NAMES[Math.floor(Math.random()*NAMES.length)];
}

let menus      = [];
let curMenu    = null;
let curItems   = [];
let pickerData = { pages:[], categories:[], menus:[] };
let dragSrc    = null;

const menuListEl   = document.getElementById('menu-list');
const menuEmpty    = document.getElementById('menu-list-empty');
const menuEditor   = document.getElementById('menu-editor');
const itemsListEl  = document.getElementById('menu-items-list');
const itemPanel    = document.getElementById('item-editor-panel');
const secCatList   = document.getElementById('section-category-list');
const secLinks     = document.getElementById('section-links-pages');
const secRelated   = document.getElementById('section-related-products');
const catChecks    = document.getElementById('cat-list-checks');

// ── Init ──────────────────────────────────────────────────────────────────────
async function init() {
	const [listRes, pickerRes] = await Promise.all([
		ajax({ action:'list' }),
		ajax({ action:'picker_data' }),
	]);
	if (pickerRes.ok) pickerData = pickerRes;
	populatePageSelect();
	if (listRes.ok) { menus = listRes.rows || []; renderMenuList(); }
}

function populatePageSelect() {
	const el = document.getElementById('item-page');
	el.innerHTML = '<option value="">— Select page —</option>' +
		(pickerData.pages||[]).map(p => `<option value="${p.id}">${esc(p.title)}</option>`).join('');
}

// ── Menu list ─────────────────────────────────────────────────────────────────
function roleLabel(r) { return r === 'menu1' ? 'Horizontal' : r === 'menu2' ? 'Vertical' : ''; }
function typeLabel(t) { return t === 'category_list' ? 'Cat. List' : t === 'related_products' ? 'Related' : 'Links'; }

function renderMenuList() {
	menuListEl.innerHTML = '';
	if (!menus.length) { menuEmpty.style.display=''; return; }
	menuEmpty.style.display = 'none';
	menus.forEach(m => {
		const li = document.createElement('li');
		li.className = 'menu-list-item' + (curMenu?.id == m.id ? ' active' : '');
		li.dataset.id = m.id;

		const nameBtn = document.createElement('button');
		nameBtn.className = 'menu-list-name';
		nameBtn.textContent = m.name;
		nameBtn.setAttribute('aria-label', 'Open ' + m.name);
		nameBtn.addEventListener('click', () => openMenu(m.id));
		nameBtn.addEventListener('dblclick', e => { e.stopPropagation(); startNameEdit(li, m); });

		const badges = document.createElement('span');
		badges.className = 'menu-list-badges';
		if (m.menu_type) badges.innerHTML += `<span class="menu-role-badge">${typeLabel(m.menu_type)}</span>`;
		if (m.menu_role) badges.innerHTML += `<span class="menu-role-badge">${roleLabel(m.menu_role)}</span>`;

		const dip = document.createElement('delete-in-place');
		dip.setAttribute('caption', '🗑');
		dip.setAttribute('confirm', 'Delete "' + m.name + '" and all its items?');
		dip.classList.add('menu-list-dip');
		dip.addEventListener('dip-confirm', async () => {
			li.style.transition='opacity .3s'; li.style.opacity='0';
			await ajax({ action:'delete_menu', id:m.id });
			setTimeout(() => {
				menus = menus.filter(x => x.id != m.id);
				if (curMenu?.id == m.id) { curMenu=null; curItems=[]; menuEditor.style.display='none'; itemPanel.style.display='none'; }
				renderMenuList();
			}, 320);
		});

		li.appendChild(nameBtn);
		li.appendChild(badges);
		li.appendChild(dip);
		menuListEl.appendChild(li);
	});
}

function startNameEdit(li, m) {
	const nameBtn = li.querySelector('.menu-list-name');
	if (li.querySelector('.menu-list-name-input')) return;
	const inp = document.createElement('input');
	inp.type = 'text'; inp.value = m.name; inp.className = 'menu-list-name-input';
	inp.addEventListener('click', e => e.stopPropagation());
	inp.addEventListener('keydown', async e => {
		if (e.key === 'Enter')  { e.preventDefault(); await commitMenuName(inp, li, m); }
		if (e.key === 'Escape') { inp.replaceWith(nameBtn); }
	});
	inp.addEventListener('blur', async () => commitMenuName(inp, li, m));
	nameBtn.replaceWith(inp);
	inp.select();
}

async function commitMenuName(inp, li, m) {
	const newName = inp.value.trim();
	const btn = makeName(m);
	if (!newName || newName === m.name) { inp.replaceWith(btn); return; }
	const res = await ajax({ action:'save_menu', id:m.id, name:newName, menu_role:m.menu_role||'', menu_type:m.menu_type||'links_pages' });
	if (res.ok) {
		m.name = newName;
		btn.textContent = newName;
		if (curMenu?.id == m.id) {
			curMenu.name = newName;
			document.getElementById('edit-menu-name').value = newName;
		}
	}
	inp.replaceWith(btn);
}

function makeName(m) {
	const btn = document.createElement('button');
	btn.className = 'menu-list-name';
	btn.textContent = m.name;
	btn.addEventListener('click', () => openMenu(m.id));
	btn.addEventListener('dblclick', e => { e.stopPropagation(); startNameEdit(btn.closest('.menu-list-item'), m); });
	return btn;
}

// ── Open menu ─────────────────────────────────────────────────────────────────
async function openMenu(id) {
	const res = await ajax({ action:'get', id });
	if (!res.ok) { notifyErr(res.message); return; }
	curMenu  = res.menu;
	curItems = res.items || [];

	document.querySelectorAll('.menu-list-item').forEach(li =>
		li.classList.toggle('active', li.dataset.id == id));

	document.getElementById('edit-menu-id').value   = curMenu.id;
	document.getElementById('edit-menu-name').value = curMenu.name;

	document.querySelectorAll('input[name="menu-role"]').forEach(r =>
		r.checked = r.value === (curMenu.menu_role || ''));
	document.querySelectorAll('input[name="menu-type"]').forEach(r =>
		r.checked = r.value === (curMenu.menu_type || 'links_pages'));

	menuEditor.style.display = '';
	applyMenuType(curMenu.menu_type || 'links_pages');
}

function applyMenuType(type) {
	if (type === 'category_list') {
		secCatList.style.display = '';
		secLinks.style.display   = 'none';
		if (secRelated) secRelated.style.display = 'none';
		renderCatChecks();
	} else if (type === 'related_products') {
		secCatList.style.display = 'none';
		secLinks.style.display   = 'none';
		if (secRelated) secRelated.style.display = '';
	} else {
		secCatList.style.display = 'none';
		secLinks.style.display   = '';
		if (secRelated) secRelated.style.display = 'none';
		renderItems();
	}
}

document.querySelectorAll('input[name="menu-type"]').forEach(r => {
	r.addEventListener('change', function() {
		applyMenuType(this.value);
	});
});

// ── Category list section ─────────────────────────────────────────────────────
function getCatListItem() {
	return curItems.find(i => i.item_type === 'category_list') || null;
}

function renderCatChecks() {
	const item     = getCatListItem();
	const excluded = item?.settings?.excluded_cats || [];
	catChecks.innerHTML = '';
	(pickerData.categories||[]).forEach(cat => {
		const lbl = document.createElement('label');
		lbl.className = 'item-cat-check';
		const cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.value = cat.id;
		cb.checked = !excluded.includes(String(cat.id)) && !excluded.includes(cat.id);
		lbl.appendChild(cb);
		lbl.appendChild(document.createTextNode(' ' + cat.name));
		catChecks.appendChild(lbl);
	});
}

document.getElementById('btn-save-cat-list').addEventListener('click', async function() {
	if (!curMenu) return;
	const item = getCatListItem();
	const allCats  = pickerData.categories || [];
	const checked  = Array.from(catChecks.querySelectorAll('input[type=checkbox]:checked')).map(cb => String(cb.value));
	const excluded = allCats.map(c => String(c.id)).filter(id => !checked.includes(id));
	const settings = JSON.stringify({ excluded_cats: excluded });

	const res = await ajax({
		action:    'save_item',
		id:        item ? item.id : '',
		menu_id:   curMenu.id,
		label:     '',
		item_type: 'category_list',
		url:       '', page_id:'', target:'', js_code:'',
		settings,
	});
	if (!res.ok) { notifyErr(res.message); return; }
	// Refresh items silently
	const full = await ajax({ action:'get', id:curMenu.id });
	if (full.ok) curItems = full.items || [];
});

// ── Items list (links/pages) ──────────────────────────────────────────────────
function renderItems() {
	itemsListEl.innerHTML = '';
	if (!curItems.length) {
		itemsListEl.innerHTML = '<p class="items-empty">No items yet.</p>';
		return;
	}
	curItems.forEach((item, idx) => {
		const row = document.createElement('div');
		row.className = 'menu-item-row';
		row.dataset.id = item.id; row.dataset.idx = idx;
		row.draggable  = true;

		const tl = { url:'URL', page:'Page', javascript:'JS' }[item.item_type] || item.item_type;
		const target = item.target === '_blank' ? ' ↗' : '';
		row.innerHTML =
			`<span class="drag-handle" aria-hidden="true">⠿</span>` +
			`<span class="menu-item-label">${esc(item.label||'(no label)')}</span>` +
			`<span class="menu-item-type">${tl}${target}</span>` +
			`<button class="btn btn-secondary btn-sm btn-edit-item">Edit</button>` +
			`<delete-in-place caption="🗑" confirm="Remove item?"></delete-in-place>`;

		row.querySelector('.btn-edit-item').addEventListener('click', () => openItemEditor(idx));
		row.querySelector('delete-in-place').addEventListener('dip-confirm', async () => {
			row.style.transition='opacity .3s'; row.style.opacity='0';
			await ajax({ action:'delete_item', id:item.id });
			setTimeout(() => { curItems.splice(idx,1); renderItems(); }, 320);
		});

		row.addEventListener('dragstart', e => { dragSrc=row; e.dataTransfer.effectAllowed='move'; });
		row.addEventListener('dragover',  e => { e.preventDefault(); row.classList.add('drag-over'); });
		row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
		row.addEventListener('drop', async e => {
			e.preventDefault(); row.classList.remove('drag-over');
			const si = curItems.findIndex(i => i.id == dragSrc.dataset.id);
			const di = idx;
			if (si < 0 || si === di) return;
			curItems.splice(di, 0, curItems.splice(si, 1)[0]);
			renderItems();
			await ajax({ action:'reorder_items', ids:JSON.stringify(curItems.map(i=>i.id)) });
		});

		itemsListEl.appendChild(row);
	});
}

// ── Item editor ───────────────────────────────────────────────────────────────
function updateItemTypeFields(type) {
	document.getElementById('item-url-row').style.display  = type === 'url'        ? '' : 'none';
	document.getElementById('item-page-row').style.display = type === 'page'       ? '' : 'none';
	document.getElementById('item-js-row').style.display   = type === 'javascript' ? '' : 'none';
}

document.getElementById('item-type').addEventListener('change', function() {
	updateItemTypeFields(this.value);
	// Auto-fill label from page title when switching to page type
	if (this.value === 'page') {
		const sel = document.getElementById('item-page');
		const opt = sel.options[sel.selectedIndex];
		if (opt?.value && !document.getElementById('item-label').value) {
			document.getElementById('item-label').value = opt.text;
		}
	}
});

document.getElementById('item-page').addEventListener('change', function() {
	const opt = this.options[this.selectedIndex];
	if (opt?.value) {
		const lbl = document.getElementById('item-label');
		// Only auto-fill if label is blank or matches another page title
		if (!lbl.value || (pickerData.pages||[]).some(p => p.title === lbl.value)) {
			lbl.value = opt.text;
		}
	}
});

function openItemEditor(idx) {
	const item = curItems[idx];
	document.getElementById('item-id').value      = item.id;
	document.getElementById('item-menu-id').value = curMenu.id;
	document.getElementById('item-label').value   = item.label || '';
	document.getElementById('item-type').value    = item.item_type || 'url';
	document.getElementById('item-url').value     = item.url || '';
	document.getElementById('item-page').value    = item.page_id || '';
	document.getElementById('item-js').value      = item.js_code || '';
	document.getElementById('item-target').value  = item.target || '';
	document.getElementById('item-editor-title').textContent = 'Edit Item';
	updateItemTypeFields(item.item_type || 'url');
	itemPanel.style.display = '';
}

function openNewItemEditor() {
	document.getElementById('item-id').value      = '';
	document.getElementById('item-menu-id').value = curMenu.id;
	document.getElementById('item-label').value   = '';
	document.getElementById('item-type').value    = 'url';
	document.getElementById('item-url').value     = '';
	document.getElementById('item-page').value    = '';
	document.getElementById('item-js').value      = '';
	document.getElementById('item-target').value  = '';
	document.getElementById('item-editor-title').textContent = 'Add Item';
	updateItemTypeFields('url');
	itemPanel.style.display = '';
}

// Enter in URL/label fields triggers save
['item-label','item-url'].forEach(id => {
	document.getElementById(id)?.addEventListener('keydown', e => {
		if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn-save-item').click(); }
	});
});

// ── Save menu ─────────────────────────────────────────────────────────────────
document.getElementById('btn-save-menu').addEventListener('click', async function() {
	const name = document.getElementById('edit-menu-name').value.trim();
	if (!name) { notifyErr('Name required.'); return; }
	const role = document.querySelector('input[name="menu-role"]:checked')?.value || '';
	const type = document.querySelector('input[name="menu-type"]:checked')?.value || 'links_pages';
	const res  = await ajax({ action:'save_menu', id:document.getElementById('edit-menu-id').value, name, menu_role:role, menu_type:type });
	if (!res.ok) { notifyErr(res.message); return; }
	curMenu = res.menu;
	const existing = menus.find(m => m.id == curMenu.id);
	if (existing) Object.assign(existing, curMenu);
	else menus.push(curMenu);
	renderMenuList();
	if (res.type_changed) {
		// Reload items after type change wipe
		const full = await ajax({ action:'get', id:curMenu.id });
		if (full.ok) { curItems = full.items || []; applyMenuType(type); }
	}
});

// ── Add menu ──────────────────────────────────────────────────────────────────
document.getElementById('btn-add-menu').addEventListener('click', async () => {
	const name = randomName();
	const res  = await ajax({ action:'save_menu', id:0, name, menu_role:'', menu_type:'links_pages' });
	if (!res.ok) { notifyErr(res.message); return; }
	menus.push(res.menu);
	renderMenuList();
	await openMenu(res.menu.id);
	const nameInput = document.getElementById('edit-menu-name');
	nameInput?.focus(); nameInput?.select();
});

// ── Add item ──────────────────────────────────────────────────────────────────
document.getElementById('btn-add-item').addEventListener('click', openNewItemEditor);

// ── Save item ─────────────────────────────────────────────────────────────────
document.getElementById('btn-save-item').addEventListener('click', async function() {
	const type  = document.getElementById('item-type').value;
	const label = document.getElementById('item-label').value.trim();

	const res = await ajax({
		action:    'save_item',
		id:        document.getElementById('item-id').value,
		menu_id:   document.getElementById('item-menu-id').value,
		label,
		item_type: type,
		url:       type === 'url'        ? document.getElementById('item-url').value : '',
		page_id:   type === 'page'       ? document.getElementById('item-page').value : '',
		js_code:   type === 'javascript' ? document.getElementById('item-js').value : '',
		target:    document.getElementById('item-target').value,
		settings:  '{}',
	});
	if (!res.ok) { notifyErr(res.message); return; }
	const full = await ajax({ action:'get', id:curMenu.id });
	if (full.ok) { curItems = full.items || []; renderItems(); }
	itemPanel.style.display = 'none';
});

// ── Cancel ────────────────────────────────────────────────────────────────────
[document.getElementById('btn-item-cancel'), document.getElementById('btn-item-cancel2')].forEach(btn => {
	btn?.addEventListener('click', () => { itemPanel.style.display = 'none'; });
});

init();
})();
