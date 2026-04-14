/**
 * new-cart catalog — shared utilities
 */

// Debounced button: never disables, ignores clicks within ms of last click
function debounceBtn(btn, handler, ms) {
	ms = ms || 1000;
	var last = 0;
	btn.addEventListener('click', function (e) {
		var now = Date.now();
		if (now - last < ms) return;
		last = now;
		handler.call(this, e);
	});
}
