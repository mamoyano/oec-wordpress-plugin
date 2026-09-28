/* JS de [oec-list]: flechas de las tiras horizontales (layout="scroll") y cuenta
   regresiva de las tarjetas (countdown="yes"). Sin dependencias (ni jQuery). Se encola
   desde class-oec-shortcodes.php (enqueue_list_assets()), diferido, con versión por
   filemtime(). oec-wp-theme también lo usa para sus propias tiras y tarjetas (mismas
   clases). Cada bloque se autoprotege: si no hay nada que enganchar, no hace nada. */

// Tiras horizontales con flechas — puede haber varias en una misma página, así que se
// enganchan TODAS. Cada flecha desaparece sola en el extremo correspondiente.
(function(){
    function engancharTiras(root){
        var wrappers = root.querySelectorAll('.oec-scroll-wrapper');
        Array.prototype.forEach.call(wrappers, function(wrapper){
            var track = wrapper.querySelector('.oec-scroll-track');
            var left  = wrapper.querySelector('.oec-scroll-arrow-left');
            var right = wrapper.querySelector('.oec-scroll-arrow-right');
            if (!track || !left || !right) return;
            function actualizarFlechas(){
                left.disabled  = track.scrollLeft <= 4;
                right.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 4;
            }
            function saltar(dir){
                var card = track.querySelector('.oec-card');
                var paso = card ? card.getBoundingClientRect().width + 20 : track.clientWidth * 0.8;
                track.scrollBy({ left: dir * paso * 2, behavior: 'smooth' });
            }
            left.addEventListener('click', function(){ saltar(-1); });
            right.addEventListener('click', function(){ saltar(1); });
            track.addEventListener('scroll', actualizarFlechas);
            window.addEventListener('resize', actualizarFlechas);
            actualizarFlechas();
        });
    }
    engancharTiras(document);
})();

// Countdown en tarjeta (.oec-countdown, ver oec-formaciones.css) — MISMO
// código que updateCountdowns() en oec-formacion.js (ficha de
// formación), portado tal cual a propósito para que se vea y anime
// igual en las dos pantallas. Única diferencia real: acá puede haber
// varios countdowns en la misma pantalla (una tarjeta por formación),
// así que se recorren todos los que haya en vez de uno solo.
(function(){
    if (!document.querySelector('.oec-countdown')) return;
    var pad = function(n){ return n.toString().padStart(2, '0'); };
    function updateCountdowns(){
        var now = Date.now() / 1000;
        document.querySelectorAll('.oec-countdown').forEach(function(el){
            var end = Date.parse(el.getAttribute('date')) / 1000;
            var left = Math.max(0, end - now);
            var d = Math.floor(left / 86400), h = Math.floor((left % 86400) / 3600), m = Math.floor((left % 3600) / 60), s = Math.floor(left % 60);
            if (d <= 15) el.style.display = 'flex';

            var daysUnit = el.querySelector('.oec-cd-days');
            if (daysUnit) daysUnit.classList.toggle('oec-cd-hide', d <= 0);

            [['d', d], ['h', h], ['m', m], ['s', s]].forEach(function(pair){
                var unit = pair[0], val = pair[1];
                var span = el.querySelector('.oec-cd-num[data-unit="' + unit + '"]');
                if (!span) return;
                var text = pad(val);
                if (span.textContent !== text) {
                    span.textContent = text;
                    span.classList.remove('oec-cd-pulse');
                    void span.offsetWidth;
                    span.classList.add('oec-cd-pulse');
                }
            });
        });
    }
    updateCountdowns();
    setInterval(updateCountdowns, 1000);
})();
