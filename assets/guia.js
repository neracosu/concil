/**
 * Visita guiada.
 *
 * Un solo recorrido continuo por todas las secciones. Cuando el siguiente paso
 * está en otra pantalla, la guía navega sola hasta allí y sigue donde iba: el
 * número del paso viaja en la dirección (?guia=N), así sobrevive a la recarga.
 */
(function () {
  'use strict';
  if (!window.GUIA || !window.GUIA.pasos || !window.GUIA.pasos.length) return;

  var PASOS = window.GUIA.pasos;
  var RUTA = window.GUIA.ruta || 'panel';
  var VISTA = 'vipsoft_visita_guiada_v2';
  var suave = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function guardar(v) { try { localStorage.setItem(VISTA, v); } catch (e) {} }
  function guardado() { try { return localStorage.getItem(VISTA); } catch (e) { return null; } }

  function nodo(tag, clase, html) {
    var n = document.createElement(tag);
    if (clase) n.className = clase;
    if (html != null) n.innerHTML = html;
    return n;
  }

  /** Dirección de una sección conservando el paso en curso. */
  function direccion(ruta, paso) {
    return '?r=' + encodeURIComponent(ruta) + '&guia=' + paso;
  }

  function arrancar(desde) {
    // Si el paso de entrada no tiene a quién señalar, se avanza al siguiente,
    // pero solo dentro de esta misma sección: los de otras se resuelven al navegar.
    while (desde < PASOS.length - 1 && PASOS[desde].ruta === RUTA
           && PASOS[desde].sel && !document.querySelector(PASOS[desde].sel)) {
      desde++;
    }
    if (PASOS[desde].ruta !== RUTA) {          // el salto nos dejó en otra sección
      location.href = direccion(PASOS[desde].ruta, desde);
      return;
    }
    var i = desde;
    var velo = nodo('div', 'guia-velo');
    var foco = nodo('div', 'guia-foco');
    var carta = nodo('div', 'guia-carta');
    document.body.appendChild(velo);
    document.body.appendChild(foco);
    document.body.appendChild(carta);
    requestAnimationFrame(function () { velo.classList.add('visible'); });

    function salir(completa) {
      guardar(completa ? 'completa' : 'salida');
      document.removeEventListener('keydown', teclas);
      window.removeEventListener('resize', recolocar);
      window.removeEventListener('scroll', recolocar);
      velo.remove(); foco.remove(); carta.remove();
      document.body.classList.remove('guia-activa');
      // La dirección queda limpia para que al recargar no vuelva a empezar.
      if (history.replaceState) {
        var u = new URL(location.href);
        u.searchParams.delete('guia');
        history.replaceState({}, '', u);
      }
    }

    function teclas(ev) {
      if (ev.key === 'Escape') salir(false);
      else if (ev.key === 'ArrowRight') mover(1);
      else if (ev.key === 'ArrowLeft') mover(-1);
    }
    document.addEventListener('keydown', teclas);
    window.addEventListener('resize', recolocar);
    window.addEventListener('scroll', recolocar, { passive: true });
    velo.addEventListener('click', function () { salir(false); });
    document.body.classList.add('guia-activa');

    function recolocar() { if (carta.isConnected) colocar(PASOS[i]); }

    /** Sitúa el recorte y la tarjeta respecto al elemento señalado. */
    function colocar(p) {
      var el = p.sel ? document.querySelector(p.sel) : null;

      if (!el) {                       // paso sin objetivo: tarjeta al centro
        foco.style.opacity = '0';
        carta.classList.add('centrada');
        carta.style.top = '';
        carta.style.left = '';
        carta.setAttribute('data-flecha', 'ninguna');
        velo.classList.add('opaco');
        return;
      }

      var apareciendo = foco.style.opacity !== '1';
      if (apareciendo) foco.style.transition = 'none';   // sin viaje desde la esquina
      foco.style.opacity = '1';
      carta.classList.remove('centrada');
      velo.classList.remove('opaco');

      var r = el.getBoundingClientRect();
      var m = 8;
      var top = r.top + window.scrollY - m;
      var izq = r.left + window.scrollX - m;
      foco.style.top = top + 'px';
      foco.style.left = izq + 'px';
      foco.style.width = (r.width + m * 2) + 'px';
      foco.style.height = (r.height + m * 2) + 'px';

      var ancho = carta.offsetWidth || 372;
      var alto = carta.offsetHeight || 190;
      var lado = p.lado || '';
      var cTop, cIzq, flecha;

      if (lado === 'derecha') {
        cTop = Math.max(window.scrollY + 16, top);
        cIzq = izq + r.width + m * 2 + 16;
        flecha = 'izquierda';
      } else if (r.bottom + alto + 34 < window.innerHeight || r.top < alto + 34) {
        cTop = top + r.height + m * 2 + 16;
        cIzq = izq;
        flecha = 'arriba';
      } else {
        cTop = top - alto - 16;
        cIzq = izq;
        flecha = 'abajo';
      }
      cIzq = Math.max(14, Math.min(cIzq, window.scrollX + window.innerWidth - ancho - 14));
      carta.style.top = cTop + 'px';
      carta.style.left = cIzq + 'px';
      carta.setAttribute('data-flecha', flecha);
      if (apareciendo) requestAnimationFrame(function () { foco.style.transition = ''; });
    }

    /** Avanza o retrocede; si el paso vive en otra sección, navega hasta ella. */
    function mover(paso) {
      var n = i + paso;
      // Salta los pasos de esta misma sección cuyo objetivo no está presente.
      while (n >= 0 && n < PASOS.length && PASOS[n].ruta === RUTA
             && PASOS[n].sel && !document.querySelector(PASOS[n].sel)) {
        n += (paso >= 0 ? 1 : -1);
      }
      if (n >= PASOS.length) { salir(true); return; }
      if (n < 0) n = 0;
      if (PASOS[n].ruta !== RUTA) {
        guardar('en-curso');
        carta.classList.add('saliendo');
        location.href = direccion(PASOS[n].ruta, n);
        return;
      }
      pintar(n);
    }

    function pintar(n) {
      i = n;
      var p = PASOS[i];
      var ultimo = i === PASOS.length - 1;

      carta.innerHTML = '';
      carta.appendChild(nodo('div', 'guia-carta__kicker',
        'Visita guiada <span class="guia-carta__kicker-num">' + (i + 1) + ' / ' + PASOS.length + '</span>'));
      carta.appendChild(nodo('h3', 'guia-carta__titulo', p.titulo));
      carta.appendChild(nodo('p', 'guia-carta__texto', p.texto));
      if (p.nota) carta.appendChild(nodo('div', 'guia-carta__nota', p.nota));

      var barra = nodo('div', 'guia-carta__progreso');
      barra.appendChild(nodo('i', null, ''));
      barra.firstChild.style.width = ((i + 1) / PASOS.length * 100) + '%';
      carta.appendChild(barra);

      var pie = nodo('div', 'guia-carta__controles');
      var salida = nodo('button', 'guia-carta__salir', ultimo ? '' : 'Salir de la visita');
      salida.type = 'button';
      if (!ultimo) { salida.addEventListener('click', function () { salir(false); }); pie.appendChild(salida); }

      var botones = nodo('div', 'guia-carta__botones');
      if (i > 0) {
        var atras = nodo('button', 'btn btn-sm', 'Atrás');
        atras.type = 'button';
        atras.addEventListener('click', function () { mover(-1); });
        botones.appendChild(atras);
      }
      var seguir = nodo('button', 'btn btn-sm btn-oro', ultimo ? 'Empezar a usarlo' : 'Siguiente');
      seguir.type = 'button';
      seguir.addEventListener('click', function () { mover(1); });
      botones.appendChild(seguir);
      pie.appendChild(botones);
      carta.appendChild(pie);

      var el = p.sel ? document.querySelector(p.sel) : null;
      if (el) {
        el.scrollIntoView({ behavior: suave ? 'smooth' : 'auto', block: 'center' });
        setTimeout(function () { colocar(p); seguir.focus({ preventScroll: true }); }, suave ? 300 : 0);
      } else {
        colocar(p);
        seguir.focus({ preventScroll: true });
      }
    }

    pintar(i);
  }

  /** Primer paso de la visita que corresponde a la sección indicada. */
  function primerPasoDe(ruta) {
    for (var k = 0; k < PASOS.length; k++) if (PASOS[k].ruta === ruta) return k;
    return 0;
  }

  window.Guia = {
    abrir: function () {
      // Siempre desde el principio, aunque se lance desde otra sección.
      if (PASOS[0].ruta !== RUTA) { guardar('en-curso'); location.href = direccion(PASOS[0].ruta, 0); return; }
      arrancar(0);
    },
    aqui: function () { arrancar(primerPasoDe(RUTA)); }
  };

  document.querySelectorAll('[data-guia-abrir]').forEach(function (b) {
    b.addEventListener('click', function (ev) { ev.preventDefault(); window.Guia.abrir(); });
  });

  // Retomar tras cambiar de sección, o arrancar en la primera visita.
  var param = new URLSearchParams(location.search).get('guia');
  if (param !== null && PASOS[+param]) {
    arrancar(+param);
  } else if (!guardado() && RUTA === 'panel') {
    // Solo se ofrece sola al entrar, nunca encima de una pantalla en la que
    // la persona ya está trabajando.
    arrancar(0);
  }
})();
