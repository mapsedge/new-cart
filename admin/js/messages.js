(function () {
'use strict';
const AJAX = NC.adminUrl + '?route=messages/ajax';
function ajax(data) {
	const fd = new FormData();
	for (const [k,v] of Object.entries(data)) fd.append(k,v);
	return fetch(AJAX,{method:'POST',body:fd}).then(r=>r.json());
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

const tbody  = document.getElementById('messages-tbody');
const table  = document.getElementById('messages-table');
const empty  = document.getElementById('messages-empty');
const detail = document.getElementById('message-detail');

load();

async function load() {
	const res = await ajax({action:'list'});
	if (!res.ok) return;
	tbody.innerHTML = '';
	if (!res.rows.length) { empty.style.display=''; table.style.display='none'; return; }
	empty.style.display='none'; table.style.display='';
	res.rows.forEach(r => {
		const tr = document.createElement('tr');
		tr.className = r.read_at ? '' : 'msg-unread';
		// Summary: first value of data
		const first = Object.values(r.data||{})[0] || '—';
		tr.innerHTML =
			'<td>' + esc(r.created_at) + '</td>' +
			'<td>' + esc(r.form_name||'—') + '</td>' +
			'<td class="msg-summary">' + esc(String(first).substring(0,60)) + '</td>' +
			'<td><button class="btn btn-secondary btn-sm btn-view" data-id="' + r.id + '" data-form="' + esc(r.form_name||'Message') + '">View</button></td>' +
			'<td><delete-in-place caption="🗑" confirm="Delete message?" data-id="' + r.id + '"></delete-in-place></td>';
		tbody.appendChild(tr);

		tr.querySelector('.btn-view').addEventListener('click', function() {
			showDetail(r);
			if (!r.read_at) ajax({action:'mark_read',id:r.id});
			tr.classList.remove('msg-unread');
		});
	});

	tbody.addEventListener('dip-confirm', async function(e) {
		const id = e.target.dataset.id;
		const tr = e.target.closest('tr');
		if (tr) { tr.style.transition='opacity .3s'; tr.style.opacity='0'; }
		await ajax({action:'delete',id});
		setTimeout(()=>{ tr?.remove(); if(!tbody.children.length) load(); },320);
	});
}

function showDetail(r) {
	table.style.display = 'none';
	empty.style.display = 'none';
	detail.style.display = '';
	document.getElementById('message-detail-title').textContent = r.form_name || 'Message';
	const body = document.getElementById('message-detail-body');
	body.innerHTML = '';
	const data = r.data || {};
	Object.entries(data).forEach(([k,v]) => {
		const row = document.createElement('div');
		row.className = 'msg-field';
		row.innerHTML = '<span class="msg-field-label">' + esc(k) + '</span><span class="msg-field-value">' + esc(v) + '</span>';
		body.appendChild(row);
	});
	// Meta
	const meta = document.createElement('div');
	meta.className = 'msg-meta';
	meta.innerHTML = '<span>Received: ' + esc(r.created_at) + '</span><span>IP: ' + esc(r.ip) + '</span>';
	body.appendChild(meta);
}

document.getElementById('btn-back-messages').addEventListener('click', function() {
	detail.style.display='none';
	table.style.display='';
});
})();
