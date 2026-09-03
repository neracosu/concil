/* Interacciones mínimas: el trabajo real ocurre en el servidor. */
(function () {
  'use strict';

  /* --- PIN: avanzar y retroceder entre casillas --- */
  var pin = document.querySelectorAll('.pin-campos input');
  if (pin.length) {
    pin.forEach(function (campo, i) {
      campo.addEventListener('input', function () {
        campo.value = campo.value.replace(/\D/g, '').slice(0, 1);
        campo.classList.toggle('lleno', campo.value !== '');
        if (campo.value && i < pin.length - 1) pin[i + 1].focus();
        if (i === pin.length - 1 && [].every.call(pin, function (c) { return c.value; })) {
          campo.form.requestSubmit();
        }
      });
      campo.addEventListener('keydown', function (ev) {
        if (ev.key === 'Backspace' && !campo.value && i > 0) pin[i - 1].focus();
        if (ev.key === 'ArrowLeft' && i > 0) pin[i - 1].focus();
        if (ev.key === 'ArrowRight' && i < pin.length - 1) pin[i + 1].focus();
      });
      campo.addEventListener('paste', function (ev) {
        var t = (ev.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        if (!t) return;
        ev.preventDefault();
        for (var k = 0; k < pin.length; k++) {
          pin[k].value = t[k] || '';
          pin[k].classList.toggle('lleno', !!t[k]);
        }
        (pin[Math.min(t.length, pin.length - 1)]).focus();
        if (t.length >= pin.length) campo.form.requestSubmit();
      });
    });
  }

  /* --- Zona de carga: soltar archivos --- */
  var zona = document.getElementById('zonaSoltar');
  var entrada = document.getElementById('archivos');
  if (zona && entrada) {
    ['dragenter', 'dragover'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('encima'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('encima'); });
    });
    zona.addEventListener('drop', function (e) {
      entrada.files = e.dataTransfer.files;
      entrada.dispatchEvent(new Event('change'));
    });
    // Pulsar el recuadro también abre el buscador de archivos. Es una comodidad:
    // el botón «Elegir archivos» funciona aunque este script no llegue a correr.
    zona.addEventListener('click', function (ev) {
      if (ev.target.tagName !== 'LABEL' && ev.target.tagName !== 'INPUT') entrada.click();
    });

    entrada.addEventListener('change', function () {
      var lista = document.getElementById('listaArchivos');
      var aviso = document.getElementById('avisoArchivos');
      lista.innerHTML = '';
      [].forEach.call(entrada.files, function (f) {
        var li = document.createElement('li');
        li.innerHTML = '<span>' + f.name.replace(/[<>&]/g, '') + '</span>'
          + '<span class="peso">' + (f.size / 1024).toFixed(0) + ' KB</span>';
        lista.appendChild(li);
      });
      if (aviso) {
        var n = entrada.files.length;
        aviso.textContent = n === 0 ? '' : (n === 1 ? '1 archivo listo' : n + ' archivos listos');
      }
    });
  }

  /* --- Confirmar acciones destructivas --- */
  document.querySelectorAll('[data-confirmar]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirmar'))) ev.preventDefault();
    });
  });

  /* --- Enviar el formulario de filtros al cambiar un selector --- */
  document.querySelectorAll('[data-auto]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.requestSubmit(); });
  });

  /* --- Justificar en la propia fila, sin bajar hasta el final --- */
  function cerrarFilas(salvo) {
    document.querySelectorAll('.fila-justificar').forEach(function (fila) {
      if (fila === salvo) return;
      fila.hidden = true;
      var b = document.querySelector('[data-abrir="' + fila.id + '"]');
      if (b) { b.setAttribute('aria-expanded', 'false'); b.textContent = 'Justificar'; }
    });
  }

  document.querySelectorAll('[data-abrir]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var fila = document.getElementById(boton.getAttribute('data-abrir'));
      if (!fila) return;
      var abrir = fila.hidden;
      cerrarFilas(abrir ? fila : null);
      fila.hidden = !abrir;
      boton.setAttribute('aria-expanded', String(abrir));
      boton.textContent = abrir ? 'Cerrar' : 'Justificar';
      if (abrir) {
        traerPanel(fila);
        var primero = fila.querySelector('select, input, textarea');
        if (primero) primero.focus({ preventScroll: true });
      }
    });
  });

  document.querySelectorAll('[data-cerrar]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var fila = document.getElementById(boton.getAttribute('data-cerrar'));
      if (fila) fila.hidden = true;
      var abre = document.querySelector('[data-abrir="' + boton.getAttribute('data-cerrar') + '"]');
      if (abre) { abre.setAttribute('aria-expanded', 'false'); abre.textContent = 'Justificar'; abre.focus(); }
    });
  });

  /* --- Marcar todo en la bandeja de pendientes --- */
  var todos = document.getElementById('marcarTodos');
  if (todos) {
    todos.addEventListener('change', function () {
      document.querySelectorAll('input[name="ids[]"]').forEach(function (c) { c.checked = todos.checked; });
    });
  }

  /* --- Repartir un pago entre sus facturas ---
     El servidor vuelve a validar todo esto; aquí solo se hace visible mientras
     se escribe, para no descubrir al guardar que se repartió de más. --- */

  // Mismo criterio que a_monto() en PHP: el separador decimal es el que queda
  // más a la derecha, así da igual escribir 2.000,50 o 2000.50.
  function aMonto(t) {
    t = String(t == null ? '' : t).replace(/[^0-9,.\-]/g, '');
    if (!t) return 0;
    var coma = t.lastIndexOf(','), punto = t.lastIndexOf('.');
    if (coma > -1 && punto > -1) {
      t = coma > punto ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
    } else if (coma > -1) {
      t = (t.length - coma - 1) <= 2 ? t.replace(',', '.') : t.replace(/,/g, '');
    }
    var n = parseFloat(t);
    return isNaN(n) ? 0 : n;
  }

  function enBs(n) {
    try {
      return n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } catch (e) {
      return n.toFixed(2);
    }
  }

  function redondear(n) { return Math.round(n * 100) / 100; }

  function recalcular(panel) {
    var debito = parseFloat(panel.getAttribute('data-debito')) || 0;
    var total = 0;
    panel.querySelectorAll('.reparto-input').forEach(function (i) { total += aMonto(i.value); });
    total = redondear(total);
    var resto = redondear(debito - total);
    var hecho = panel.querySelector('[data-reparto-hecho]');
    var queda = panel.querySelector('[data-reparto-resto]');
    var pie = panel.querySelector('.reparto-pie');
    if (hecho) hecho.textContent = 'Bs ' + enBs(total);
    if (queda) queda.textContent = 'Bs ' + enBs(resto);
    if (pie) {
      pie.classList.toggle('pasado', resto < -0.005);
      pie.classList.toggle('justo', Math.abs(resto) < 0.005 && total > 0);
    }
  }

  function marcar(panel, check) {
    var fila = check.closest('.reparto-fila');
    var campo = fila && fila.querySelector('.reparto-input');
    if (!campo) return;
    if (!check.checked) {
      campo.value = '';
    } else {
      // Se propone lo que falte de esa factura, pero nunca más de lo que quede
      // sin repartir del propio pago.
      var tope = parseFloat(check.getAttribute('data-tope')) || 0;
      var debito = parseFloat(panel.getAttribute('data-debito')) || 0;
      var otros = 0;
      panel.querySelectorAll('.reparto-input').forEach(function (i) {
        if (i !== campo) otros += aMonto(i.value);
      });
      var libre = redondear(debito - otros);
      var v = Math.min(tope, libre);
      campo.value = v > 0 ? enBs(v) : '';
      campo.focus();
      campo.select();
    }
    fila.classList.toggle('puesta', check.checked);
    recalcular(panel);
  }

  // El panel de una fila de la bandeja se pide al abrirla: dibujar los cuarenta
  // de la página costaba cuatro veces el peso para enseñar uno.
  function traerPanel(fila) {
    var hueco = fila.querySelector('[data-reparto-aplazado]');
    if (!hueco || hueco.dataset.pedido) return;
    hueco.dataset.pedido = '1';
    var u = '?r=facturas_panel&panel=1&mov=' + encodeURIComponent(hueco.getAttribute('data-mov'))
          + '&prov=' + encodeURIComponent(hueco.getAttribute('data-prov') || '')
          + '&form=' + encodeURIComponent(hueco.getAttribute('data-form') || '');
    fetch(u, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (html === null) { delete hueco.dataset.pedido; return; }
        hueco.outerHTML = html;
        var panel = fila.querySelector('[data-reparto]');
        if (panel) recalcular(panel);
      })
      .catch(function () {
        delete hueco.dataset.pedido;
        hueco.innerHTML = '<p class="reparto-vacio">No se pudo cargar. Guarde igual: '
          + 'las facturas se anotan también desde la ficha del proveedor.</p>';
      });
  }

  function recargarPanel(panel, nombre) {
    var u = '?r=facturas_panel&mov=' + encodeURIComponent(panel.getAttribute('data-mov'))
          + '&prov=' + encodeURIComponent(nombre);
    var forma = panel.getAttribute('data-form');
    if (forma) u += '&form=' + encodeURIComponent(forma);
    fetch(u, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (html === null) return;
        var lista = panel.querySelector('[data-reparto-lista]');
        if (lista) { lista.innerHTML = html; recalcular(panel); }
      })
      .catch(function () { /* sin conexión: la pantalla sigue sirviendo al recargar */ });
  }

  // Delegación: las filas se vuelven a dibujar al cambiar de proveedor, así que
  // los oyentes no pueden colgar de cada casilla.
  document.addEventListener('input', function (ev) {
    var campo = ev.target.closest && ev.target.closest('.reparto-input');
    if (!campo) return;
    var panel = campo.closest('[data-reparto]');
    if (!panel) return;
    var fila = campo.closest('.reparto-fila');
    if (fila) {
      var check = fila.querySelector('.reparto-check');
      if (check) { check.checked = aMonto(campo.value) > 0; fila.classList.toggle('puesta', check.checked); }
    }
    recalcular(panel);
  });

  document.addEventListener('change', function (ev) {
    var check = ev.target.closest && ev.target.closest('.reparto-check');
    if (check) {
      var panel = check.closest('[data-reparto]');
      if (panel) marcar(panel, check);
      return;
    }
    var prov = ev.target.closest && ev.target.closest('[data-prov-de]');
    if (prov) {
      var suyo = document.querySelector('[data-reparto][data-mov="' + prov.getAttribute('data-prov-de') + '"]');
      if (suyo) recargarPanel(suyo, prov.value);
    }
  });

  document.querySelectorAll('[data-reparto]').forEach(recalcular);
})();
