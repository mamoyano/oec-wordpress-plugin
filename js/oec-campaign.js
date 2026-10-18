/* OEC — parámetros de campaña hacia onlineeducation.center
   El checkout (*.onlineeducation.center) atribuye la venta al anuncio solo si
   le llegan los parámetros con que la persona entró al sitio: utm_*, fbclid,
   gclid/gbraid/wbraid, msclkid, ttclid, li_fat_id. Se guardan al llegar a
   CUALQUIER página (la última campaña reemplaza a la anterior; 30 días, el
   fbclid 90) y se agregan a todo enlace a onlineeducation.center o sus
   subdominios, sin pisar los que el enlace ya trae (las UTM van en grupo).
   A los enlaces internos NO: GA4 y Meta mantienen la atribución mientras se
   navega, y una UTM en un enlace interno abre una sesión nueva en GA4.
   window.oecCampaign.get() devuelve los parámetros vigentes (los usan el
   formulario de consulta de la ficha y el chat).

   MISMO ARCHIVO en el tema (assets/js/campaign-links.js) y en el plugin
   (js/oec-campaign.js): el plugin a veces está solo, sin el tema. Si están
   los dos, corre una sola vez. Mantenerlos iguales. */
(function () {
	if (window.oecCampaign) return;
	var DOMAIN = 'onlineeducation.center', KEY = 'oec_campaign', DAY = 864e5;
	var CLICK_IDS = ['fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'li_fat_id'];
	function isCampaign(k) { return k.indexOf('utm_') === 0 || CLICK_IDS.indexOf(k) > -1; }
	function read(key) { try { return JSON.parse(localStorage.getItem(key)); } catch (e) { return null; } }

	// Lo que trae esta URL: si hay algo, es la campaña vigente.
	var fromUrl = [];
	new URLSearchParams(location.search).forEach(function (v, k) { if (v && isCampaign(k)) fromUrl.push([k, v]); });
	if (fromUrl.length) {
		try { localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), p: fromUrl })); } catch (e) {}
	}

	function getParams() {
		var out = {}, saved = read(KEY), age = saved && saved.t ? Date.now() - saved.t : Infinity;
		(fromUrl.length ? fromUrl : (saved && saved.p) || []).forEach(function (kv) {
			if (fromUrl.length || age < (kv[0] === 'fbclid' ? 90 : 30) * DAY) out[kv[0]] = kv[1];
		});
		if (!out.fbclid) {
			var m = document.cookie.match(/(?:^|; )_fbc=([^;]*)/), parts = m ? decodeURIComponent(m[1]).split('.') : [];
			if (parts.length >= 4) out.fbclid = parts.slice(3).join('.');
			else {
				var old = read('oec_fbclid'); // guardado por versiones anteriores
				if (old && old.id && Date.now() - old.t < 90 * DAY) out.fbclid = old.id;
			}
		}
		return out;
	}
	window.oecCampaign = { get: getParams };

	function decorate(a, params) {
		var h = a.hostname;
		if (!h || (h !== DOMAIN && h.slice(-DOMAIN.length - 1) !== '.' + DOMAIN)) return;
		try {
			var url = new URL(a.href), changed = false, ownUtm = false;
			url.searchParams.forEach(function (v, k) { if (k.indexOf('utm_') === 0) ownUtm = true; });
			Object.keys(params).forEach(function (k) {
				if (url.searchParams.has(k) || (ownUtm && k.indexOf('utm_') === 0)) return;
				url.searchParams.set(k, params[k]);
				changed = true;
			});
			if (changed) a.href = url.toString();
		} catch (e) {}
	}
	function decorateAll() {
		var params = getParams();
		if (!Object.keys(params).length) return;
		document.querySelectorAll('a[href*="' + DOMAIN + '"]').forEach(function (a) { decorate(a, params); });
	}
	// Enlaces creados después (listados por JS, chat, etc.): al hacer clic,
	// clic del medio o abrir el menú contextual ("abrir en otra pestaña").
	function onClick(e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (a) decorate(a, getParams());
	}
	['click', 'auxclick', 'contextmenu'].forEach(function (t) { document.addEventListener(t, onClick, true); });
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', decorateAll);
	else decorateAll();
})();
