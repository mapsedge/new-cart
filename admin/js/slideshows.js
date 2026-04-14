(function () {
'use strict';
const AJAX = NC.adminUrl + '?route=slideshows/ajax';
function ajax(data) {
	const fd = new FormData();
	for (const [k,v] of Object.entries(data)) fd.append(k,v);
	return fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json());
}
function notifyOk(m)  { SimpleNotification.success({ text: m }); }
function notifyErr(m) { SimpleNotification.error({ text: m }); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const ssList   = document.getElementById('ss-list');
const ssEmpty  = document.getElementById('ss-empty');
const drawer   = document.getElementById('ss-drawer');
const overlay  = document.getElementById('drawer-overlay');
let currentSlides = [];
let dragSrc = null;

load();

async function load() {
	const res = await ajax({ action:'list' });
	if (!res.ok) return;
	ssList.innerHTML = '';
	if (!res.rows.length) { ssEmpty.style.display=''; ssList.style.display='none'; return; }
	ssEmpty.style.display='none'; ssList.style.display='';
	res.rows.forEach(r => {
		const card = document.createElement('div');
		card.className = 'slideshow-card';
		card.innerHTML =
			'<span class="slideshow-card-name">' + esc(r.name) + '</span>' +
			'<span class="slideshow-card-meta">' + r.slide_count + ' slides · ' + r.transition + ' · ' + (r.interval/1000).toFixed(1) + 's</span>' +
			'<delete-in-place caption="🗑" confirm="Delete slideshow?" data-id="' + r.id + '"></delete-in-place>';
		card.addEventListener('click', e => {
			if (e.target.closest('delete-in-place')) return;
			openDrawer(r.id);
		});
		ssList.appendChild(card);
	});
	ssList.addEventListener('dip-confirm', async function(e) {
		const id   = e.target.dataset.id;
		const card = e.target.closest('.slideshow-card');
		if (card) { card.style.transition='opacity .3s'; card.style.opacity='0'; }
		await ajax({ action:'delete', id });
		setTimeout(() => { card?.remove(); if (!ssList.children.length) load(); }, 320);
	});
}

async function openDrawer(id) {
	currentSlides = [];
	document.getElementById('ss-id').value = id || '';
	document.getElementById('ss-name').value = '';
	document.getElementById('ss-transition').value = 'fade';
	document.getElementById('ss-interval').value = '5000';
	document.getElementById('ss-status').checked = true;
	document.getElementById('slides-list').innerHTML = '';
	document.getElementById('drawer-title').textContent = id ? 'Edit Slideshow' : 'Add Slideshow';

	if (id) {
		const res = await ajax({ action:'get', id });
		if (!res.ok) { notifyErr(res.message); return; }
		document.getElementById('ss-id').value         = res.slideshow.id;
		document.getElementById('ss-name').value       = res.slideshow.name;
		document.getElementById('ss-transition').value = res.slideshow.transition;
		document.getElementById('ss-interval').value   = res.slideshow.interval;
		document.getElementById('ss-status').checked   = res.slideshow.status == 1;
		currentSlides = res.slides || [];
		renderSlides();
	}

	drawer.classList.add('open');
	overlay.classList.add('show');
}

function closeDrawer() { drawer.classList.remove('open'); overlay.classList.remove('show'); }

function renderSlides() {
	const list = document.getElementById('slides-list');
	list.innerHTML = '';
	currentSlides.forEach((s, idx) => list.appendChild(buildSlideRow(s, idx)));
}

function buildSlideRow(s, idx) {
	const row = document.createElement('div');
	row.className = 'slide-row';
	row.dataset.idx = idx;
	row.draggable = true;
	row.setAttribute('role', 'listitem');

	const thumb = s.image
		? Object.assign(document.createElement('img'), { src: s.image, alt: s.heading||'Slide', className:'slide-thumb' })
		: Object.assign(document.createElement('div'), { className:'slide-thumb-placeholder', textContent:'IMG' });

	// Make thumb clickable to pick image
	thumb.style.cursor = 'pointer';
	thumb.title = 'Click to change image';
	thumb.addEventListener('click', () => {
		if (!window.openFilePicker) return;
		window.openFilePicker(items => {
			if (!items.length) return;
			s.image = items[0].url;
			if (s.id) ajax({ action:'save_slide', id:s.id, slideshow_id:document.getElementById('ss-id').value, image:s.image, heading:s.heading||'', subtext:s.subtext||'', btn_label:s.btn_label||'', btn_url:s.btn_url||'', enabled:s.enabled??1 });
			renderSlides();
		});
	});

	const info = document.createElement('div');
	info.className = 'slide-info';

	function eip(key, placeholder) {
		const span = document.createElement('div');
		span.className = key === 'heading' ? 'slide-heading' : 'slide-url';
		span.textContent = s[key] || '';
		span.style.cursor = 'text';
		span.title = 'Click to edit ' + key;
		span.addEventListener('click', () => {
			const inp = document.createElement('input');
			inp.type = 'text';
			inp.value = s[key] || '';
			inp.placeholder = placeholder;
			inp.style.cssText = 'width:100%;padding:.2rem .35rem;border:1px solid var(--nc-primary);border-radius:3px;font-size:.85rem';
			span.replaceWith(inp);
			inp.focus();
			async function save() {
				s[key] = inp.value.trim();
				if (s.id) await ajax({ action:'save_slide', id:s.id, slideshow_id:document.getElementById('ss-id').value, image:s.image||'', heading:s.heading||'', subtext:s.subtext||'', btn_label:s.btn_label||'', btn_url:s.btn_url||'', enabled:s.enabled??1 });
				renderSlides();
			}
			inp.addEventListener('blur', save);
			inp.addEventListener('keydown', e => { if (e.key==='Enter') { e.preventDefault(); save(); } if (e.key==='Escape') renderSlides(); });
		});
		return span;
	}

	info.appendChild(eip('heading', 'Heading…'));
	info.appendChild(eip('btn_url', 'Button URL…'));

	const enableToggle = document.createElement('ios-toggle');
	enableToggle.setAttribute('size','sm');
	enableToggle.setAttribute('aria-label','Enable slide');
	if (s.enabled != 0) enableToggle.setAttribute('checked','');
	enableToggle.addEventListener('ios-toggle', async e => {
		s.enabled = e.detail.checked ? 1 : 0;
		if (s.id) await ajax({ action:'save_slide', id:s.id, slideshow_id:document.getElementById('ss-id').value, image:s.image||'', heading:s.heading||'', subtext:s.subtext||'', btn_label:s.btn_label||'', btn_url:s.btn_url||'', enabled:s.enabled });
	});

	const dip = document.createElement('delete-in-place');
	dip.setAttribute('caption','🗑');
	dip.setAttribute('confirm','Remove slide?');
	dip.addEventListener('dip-confirm', async () => {
		row.style.transition='opacity .3s'; row.style.opacity='0';
		if (s.id) await ajax({ action:'delete_slide', id:s.id });
		setTimeout(() => { currentSlides.splice(idx,1); renderSlides(); }, 320);
	});

	// Drag reorder
	row.addEventListener('dragstart', e => { dragSrc=row; e.dataTransfer.effectAllowed='move'; });
	row.addEventListener('dragover',  e => { e.preventDefault(); row.classList.add('drag-over'); });
	row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
	row.addEventListener('drop', async e => {
		e.preventDefault(); row.classList.remove('drag-over');
		const si = parseInt(dragSrc.dataset.idx);
		const di = idx;
		if (si===di) return;
		const moved = currentSlides.splice(si,1)[0];
		currentSlides.splice(di,0,moved);
		renderSlides();
		const ids = currentSlides.filter(s=>s.id).map(s=>s.id);
		if (ids.length) await ajax({ action:'reorder_slides', ids:JSON.stringify(ids) });
	});

	row.appendChild(thumb);
	row.appendChild(info);
	row.appendChild(enableToggle);
	row.appendChild(dip);
	return row;
}

// Add slide
document.getElementById('btn-add-slide').addEventListener('click', async function() {
	const ssId = document.getElementById('ss-id').value;
	if (!ssId) { notifyErr('Save the slideshow first.'); return; }
	const res = await ajax({ action:'save_slide', id:0, slideshow_id:ssId, image:'', heading:'New Slide', subtext:'', btn_label:'', btn_url:'', enabled:1 });
	if (!res.ok) { notifyErr(res.message); return; }
	currentSlides.push(res.slide);
	renderSlides();
});

// Save slideshow
debounceBtn(document.getElementById('btn-drawer-save'), async function() {
	const name = document.getElementById('ss-name').value.trim();
	if (!name) { notifyErr('Name is required.'); return; }
	const res = await ajax({
		action:     'save',
		id:         document.getElementById('ss-id').value,
		name,
		transition: document.getElementById('ss-transition').value,
		interval:   document.getElementById('ss-interval').value,
		status:     document.getElementById('ss-status')?.checked ? 1 : 0,
	});
	if (!res.ok) { notifyErr(res.message); return; }
	document.getElementById('ss-id').value = res.id;
	notifyOk('Slideshow saved.');
	closeDrawer();
	load();
});

document.getElementById('btn-add-slideshow').addEventListener('click', () => openDrawer(null));
document.getElementById('btn-add-first')?.addEventListener('click', () => openDrawer(null));
document.getElementById('drawer-close').addEventListener('click', closeDrawer);
document.getElementById('btn-drawer-cancel').addEventListener('click', closeDrawer);
overlay.addEventListener('click', closeDrawer);
})();
