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
})();
