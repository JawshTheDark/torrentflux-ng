/**
 * Dark-mode toggle.
 *
 * Applies class "dark" to <html> from localStorage (falling back to the
 * OS prefers-color-scheme), and wires any element with id "darkmode-toggle"
 * to switch and persist the choice. Include this script early in <head>
 * to avoid a flash of the wrong theme.
 */
(function () {
	var KEY = 'tf-dark';
	function prefersDark() {
		try {
			var stored = localStorage.getItem(KEY);
			if (stored === '1') return true;
			if (stored === '0') return false;
		} catch (e) {}
		return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
	}
	function apply(dark) {
		document.documentElement.classList.toggle('dark', dark);
		var t = document.getElementById('darkmode-toggle');
		if (t) t.textContent = dark ? '☀ Light' : '☾ Dark';
	}
	apply(prefersDark());
	document.addEventListener('DOMContentLoaded', function () {
		apply(prefersDark());
		var t = document.getElementById('darkmode-toggle');
		if (t) t.addEventListener('click', function (ev) {
			ev.preventDefault();
			var dark = !document.documentElement.classList.contains('dark');
			try { localStorage.setItem(KEY, dark ? '1' : '0'); } catch (e) {}
			apply(dark);
		});
	});
})();
