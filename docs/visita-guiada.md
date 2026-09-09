<div align="center">

# La visita guiada

**Cómo está hecha y cómo replicarla en otro proyecto**

*Un recorrido continuo que lleva a la persona por toda la aplicación,
señala cada cosa en su sitio y le dice para qué le sirve.*

</div>

---

## Índice

- [Qué es y por qué así](#qué-es-y-por-qué-así)
- [Las cuatro piezas](#las-cuatro-piezas)
- [Implantarla en un proyecto nuevo](#implantarla-en-un-proyecto-nuevo)
- [1 · El catálogo de pasos](#1--el-catálogo-de-pasos)
- [2 · Las anclas en las vistas](#2--las-anclas-en-las-vistas)
- [3 · El motor](#3--el-motor)
- [4 · Los estilos](#4--los-estilos)
- [5 · El enganche en el armazón](#5--el-enganche-en-el-armazón)
- [6 · La ayuda fija de cada pantalla](#6--la-ayuda-fija-de-cada-pantalla)
- [Cómo se escriben los textos](#cómo-se-escriben-los-textos)
- [Trampas que ya se pagaron](#trampas-que-ya-se-pagaron)
- [Llevarla a otro stack](#llevarla-a-otro-stack)
- [Lista de comprobación](#lista-de-comprobación)

---

## Qué es y por qué así

La mayoría de los *tours* de producto son una cadena de globos sobre **una sola
pantalla**. Este es distinto en tres cosas, y las tres son la razón de que
guste:

1. **Es un solo recorrido continuo por toda la aplicación.** El paso 7 vive en
   la pantalla de carga y el paso 12 en la de pendientes: la visita **navega
   sola** hasta cada sección y sigue donde iba. La persona no recorre un menú,
   recorre su trabajo de principio a fin.
2. **Señala el elemento real, con su color real.** No hay una copia dibujada de
   la interfaz: se oscurece todo menos un recorte, y dentro del recorte está el
   botón de verdad, en su sitio, tal como se va a ver mañana.
3. **No cuenta lo que hace el programa, cuenta lo que gana quien lo usa.** Cada
   paso es un titular en una frase y dos líneas de texto. El que no sabe de
   sistemas entiende igual.

Y tres decisiones de fondo que conviene copiar tal cual:

- **Sin dependencias.** Son ~230 líneas de JavaScript sin librería, ~90 de CSS y
  un array de datos. Nada de Shepherd, Intro.js ni Driver.js: cuando el tour es
  esto de simple, la librería pesa más que el problema.
- **Los textos son datos, no marcado.** Viven en un solo archivo, en un array.
  Quien redacta —que puede no ser quien programa— toca un archivo y nada más.
- **El estado del recorrido viaja en la dirección** (`?guia=N`). Así sobrevive a
  la recarga completa de página que impone navegar de una sección a otra en una
  aplicación servida por el servidor. Sin eso, cada salto reiniciaba la visita.

---

## Las cuatro piezas

| Pieza | Archivo en CONCIL | Qué contiene |
|---|---|---|
| **Catálogo de pasos** | `lib/guia.php` | El array de pasos y la ayuda fija de cada pantalla. Solo datos. |
| **Anclas** | `views/*.php` | Un atributo `data-guia="..."` en cada elemento que se señala. |
| **Motor** | `assets/guia.js` | Velo, recorte, tarjeta, navegación entre secciones, teclado. |
| **Estilos** | `assets/app.css` | Un bloque de ~90 líneas al final. |

Más **un enganche** de seis líneas en el armazón compartido (`views/_layout.php`):
publicar los pasos en `window.GUIA`, cargar el script y poner el botón que la
lanza.

Diagrama de lo que ocurre:

```
            ┌───────────────────────────────────────────┐
            │  lib/guia.php  →  guia_pasos(): array     │
            └────────────────────┬──────────────────────┘
                                 │ json_encode
                                 ▼
            ┌───────────────────────────────────────────┐
            │  window.GUIA = { ruta: 'panel', pasos:[…] }│
            └────────────────────┬──────────────────────┘
                                 ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  assets/guia.js                                              │
   │                                                              │
   │  ¿?guia=N en la URL? ──sí──► arrancar(N)                      │
   │  ¿primera visita y estoy en el panel? ──sí──► arrancar(0)     │
   │  clic en [data-guia-abrir] ──────────────────► arrancar(0)    │
   │                                                              │
   │  pintar(n) ─► ¿PASOS[n].ruta === ruta actual?                 │
   │                 sí → colocar velo + foco + tarjeta            │
   │                 no → location.href = '?r=RUTA&guia=n'         │
   └──────────────────────────────────────────────────────────────┘
```

---

## Implantarla en un proyecto nuevo

El orden importa: **primero los datos, después las anclas, después el motor**.
Si pone el motor antes que las anclas, el primer paso no encuentra a quién
señalar y se salta en silencio, y va a perder media hora buscando por qué.

1. Copie el bloque de CSS y compruebe que existen los tokens que usa.
2. Copie `guia.js` tal cual y cambie solo la función `direccion()`.
3. Cree el archivo de pasos con **tres pasos** de prueba: apertura, uno
   señalando algo del menú, y cierre.
4. Ponga las dos anclas.
5. Enganche `window.GUIA` y el `<script>` en el armazón.
6. Recorra la visita entera con el teclado (←, →, Esc) antes de escribir el
   resto de los pasos.

---

## 1 · El catálogo de pasos

Un array de arrays. Nada más. Cada paso lleva cinco campos, tres obligatorios:

| Campo | Obligatorio | Qué es |
|---|---|---|
| `ruta` | sí | La sección donde ocurre el paso. La visita navega sola hasta allí. |
| `sel` | sí (puede ir vacío) | Selector CSS de lo que se resalta. Vacío = tarjeta centrada, sin recorte. |
| `titulo` | sí | Una frase, no una etiqueta. «Aquí empieza todo: suelte los archivos», no «Carga». |
| `texto` | sí | Dos o tres líneas. Admite `<b>` y `<i>`. |
| `nota` | no | El apunte al margen, en su recuadro con filo dorado. Lo que tranquiliza o avisa. |
| `lado` | no | `'derecha'` fuerza la tarjeta a la derecha del elemento. Para lo que está pegado al borde izquierdo (el menú, el selector de empresa). |

```php
<?php
/**
 * Visita guiada: un solo recorrido continuo que va llevando a la persona
 * por todas las secciones y señala para qué sirve cada una.
 */
function guia_pasos(): array
{
    return [
        // ---------------------------------------------------------- Apertura
        [
            'ruta' => 'panel', 'sel' => '',
            'titulo' => 'Bienvenido a CONCIL',
            'texto' => 'Cada mes salen cientos de pagos de las cuentas. El banco los entrega en un archivo, '
                     . 'pero ese archivo no dice <b>para qué</b> fue cada pago.',
            'nota' => 'La visita dura un minuto. Puede salirse cuando quiera y volver a verla después.',
        ],
        [
            'ruta' => 'panel', 'sel' => '.nav', 'lado' => 'derecha',
            'titulo' => 'Todo está en este menú',
            'texto' => 'Arriba, lo del día a día. Abajo, cosas que se configuran una vez y casi no se tocan.',
        ],
        [
            'ruta' => 'carga', 'sel' => '[data-guia="soltar"]',
            'titulo' => 'Aquí empieza todo: suelte los archivos',
            'texto' => 'Descargue el movimiento de cada banco como siempre y arrástrelo hasta aquí.',
            'nota' => 'Si sube dos veces el mismo archivo no pasa nada: reconoce lo que ya tenía.',
        ],
        // ------------------------------------------------------------ Cierre
        [
            'ruta' => 'panel', 'sel' => '',
            'titulo' => 'Eso es todo. Su día a día son tres pasos',
            'texto' => '<b>1.</b> Suba los archivos. <b>2.</b> Explique lo poco que quedó pendiente. '
                     . '<b>3.</b> Saque el reporte cuando lo necesite.',
            'nota' => 'Puede repetir esta visita cuando quiera, con el botón «Visita guiada» del menú.',
        ],
    ];
}
```

**Reglas del catálogo, aprendidas a golpes:**

- **Agrupe los pasos por sección y en el orden en que se trabaja**, no en el
  orden del menú. Cada salto de sección es una recarga de página: siete pasos
  seguidos en la misma pantalla se sienten fluidos, y alternar
  panel → carga → panel → carga parpadea.
- **Abra y cierre con un paso centrado** (`'sel' => ''`). El primero dice de qué
  va el producto en dos frases; el último resume el día a día en tres pasos
  numerados y firma con la marca. Son los dos pasos que más se recuerdan.
- **Deje comentarios de sección** (`// ----- Cargar`) dentro del array. Con
  treinta y tantos pasos es lo único que lo mantiene navegable.
- **Un paso puede repetir ancla.** En CONCIL, `[data-guia="modo"]` sale dos
  veces con textos distintos: la segunda vez cuenta otra cosa de la misma
  pantalla. No es un error, es un recurso.

---

## 2 · Las anclas en las vistas

Un atributo, y siempre el mismo:

```html
<div class="soltar" data-guia="soltar"> … </div>
<table data-guia="tabla"> … </table>
<div class="cifras" data-guia="cifras"> … </div>
```

**Por qué un atributo propio y no la clase CSS.** Porque la clase es del diseño
y cambia cuando se rediseña. `data-guia` es un contrato explícito: quien lo ve
en el marcado sabe que hay un paso apuntando ahí y que si lo quita, lo rompe.
Apuntar a `.tabla` significa que el día que esa tabla pase a `.listado`, el paso
se salta **en silencio** y nadie se entera hasta que un usuario lo dice.

**Dónde poner el ancla.** En el contenedor, no en el elemento suelto. Si señala
un `<input>`, el recorte queda del tamaño de una ranura y el texto que lo
explica no se relaciona con nada. Señale el bloque completo —el campo con su
rótulo y su ayuda— y se entiende de un vistazo.

**Un ancla es un compromiso.** Al añadir una sección nueva a la aplicación,
añada su ancla y su paso en el mismo commit. Es la única forma de que la visita
no envejezca.

---

## 3 · El motor

Copie este archivo tal cual. Lo único que hay que tocar para otro proyecto es
`direccion()`, que es donde se decide cómo se llega a una sección.

```js
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

  /* Un ancla que existe pero está escondida —la barra de quién está trabajando
     cuando no hay nadie más— no sirve para señalar: se salta igual que si no
     estuviera. getClientRects() da cero cuando el elemento no se ve. */
  function ancla(sel) {
    var el = document.querySelector(sel);
    return el && el.getClientRects().length ? el : null;
  }

  function arrancar(desde) {
    // Si el paso de entrada no tiene a quién señalar, se avanza al siguiente,
    // pero solo dentro de esta misma sección: los de otras se resuelven al navegar.
    while (desde < PASOS.length - 1 && PASOS[desde].ruta === RUTA
           && PASOS[desde].sel && !ancla(PASOS[desde].sel)) {
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
      var el = p.sel ? ancla(p.sel) : null;

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
             && PASOS[n].sel && !ancla(PASOS[n].sel)) {
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

      var el = p.sel ? ancla(p.sel) : null;
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
```

### Lo que hay que entender del motor

**El recorte no es un agujero, es una sombra gigante.** No se recorta nada:
`.guia-foco` es un `div` transparente con
`box-shadow: 0 0 0 9999px var(--velo-fuerte)`. La sombra de 9999 px tapa toda la
pantalla **menos** el rectángulo del propio div, que queda limpio. Por eso el
elemento señalado se ve con su color real y no atenuado, y por eso el recorte
**se desliza** de un paso al siguiente: son cuatro propiedades animables
(`top`, `left`, `width`, `height`), no un redibujo.

**El velo es otra capa y tiene dos estados.** `.guia-velo` está por debajo del
foco y solo recoge los clics para salir. Cuando el paso **no** señala nada
(apertura y cierre) se le pone `.opaco` y entonces sí pinta fondo y desenfoque,
porque en ese momento no hay recorte que oscurezca la pantalla.

**Un ancla escondida se salta.** `ancla()` no se conforma con
`querySelector`: exige `getClientRects().length`. Un elemento que existe en el
DOM pero está oculto —la barra de «quién está trabajando» cuando no hay nadie
más— devolvería un rectángulo en el origen y el recorte se iría a la esquina
superior izquierda a señalar la nada. Con esa comprobación, el paso se salta
igual que si el elemento no existiera.

**El salto de pasos vacíos solo mira la sección actual.** En `mover()`, el bucle
que salta pasos sin ancla lleva la condición `PASOS[n].ruta === RUTA`. Los pasos
de **otras** secciones no se pueden comprobar desde aquí —su marcado no está
cargado—, así que se navega hasta allá y se resuelve al llegar, en `arrancar()`.
Sin esa condición, la visita se saltaría todo lo que no está en pantalla y
terminaría en el primer salto.

**Tres estados en `localStorage`, no un booleano.** `en-curso` (viajando entre
secciones), `salida` (se fue a mitad) y `completa` (llegó al final). La visita
solo se ofrece sola cuando **no hay nada guardado** y además se está en la
pantalla de entrada: nunca encima de alguien que ya está trabajando. Los tres
accesos van envueltos en `try/catch` porque en ventana privada `localStorage`
lanza excepción al leerlo, y una visita guiada no puede tumbar la aplicación.

**La clave lleva versión** (`_v2`). El día que cambie el recorrido de arriba
abajo y quiera que se vuelva a ofrecer a todo el mundo, sube el número. Sin eso,
quien ya la vio no verá nunca la nueva.

**La dirección se limpia al salir.** `history.replaceState` quita el `?guia=N`.
Si no, recargar la página —o compartir el enlace— relanza la visita en mitad del
recorrido.

**El foco del teclado va al botón «Siguiente»** en cada paso, con
`preventScroll: true` para que darle el foco no mueva la página que
`scrollIntoView` acaba de colocar. Así la visita se recorre entera a golpe de
Enter, y quien navega con teclado no queda atrapado detrás del velo.

**`prefers-reduced-motion` se respeta en las dos capas**: en CSS se anulan las
transiciones, y en JS la variable `suave` quita el desplazamiento animado y el
`setTimeout` de 300 ms que lo espera. Solo con el CSS, la tarjeta se colocaría
tarde sobre una página que ya no se está moviendo.

**La tarjeta se coloca sola, con tres reglas.** Debajo del elemento si cabe;
encima si no cabe debajo pero sí encima; a la derecha cuando el paso lo pide
(`'lado' => 'derecha'`, para lo que vive pegado al borde izquierdo). Y siempre
un `Math.max/Math.min` final que la mantiene dentro de la ventana. La flechita
que apunta al elemento es un cuadrado girado 45° cuyo lado se elige con
`data-flecha`.

---

## 4 · Los estilos

```css
/* ==========================================================================
   Visita guiada
   Un recorrido continuo que va señalando cada sección. El oscurecido lo hace
   el recorte del foco, así el elemento señalado se ve con su color real.
   ========================================================================== */

body.guia-activa{overflow-x:hidden}

.guia-velo{
  position:fixed;inset:0;z-index:80;background:transparent;cursor:pointer;
  opacity:0;transition:opacity 200ms ease,background 240ms ease;
}
.guia-velo.visible{opacity:1}
.guia-velo.opaco{background:var(--velo-fuerte);backdrop-filter:blur(3px)}

.guia-foco{
  position:absolute;z-index:85;border-radius:var(--r);pointer-events:none;
  box-shadow:0 0 0 9999px var(--velo-fuerte), 0 0 0 2px var(--oro), 0 0 34px rgba(212,168,87,.30);
  transition:top 300ms cubic-bezier(.2,.7,.2,1),left 300ms cubic-bezier(.2,.7,.2,1),
             width 300ms cubic-bezier(.2,.7,.2,1),height 300ms cubic-bezier(.2,.7,.2,1),opacity 200ms ease;
}

.guia-carta{
  position:absolute;z-index:95;width:min(384px,calc(100vw - 28px));
  background:var(--panel);border:1px solid var(--linea-fuerte);border-radius:var(--r);
  padding:20px 22px 18px;box-shadow:0 24px 70px var(--sombra);
  transition:top 300ms cubic-bezier(.2,.7,.2,1),left 300ms cubic-bezier(.2,.7,.2,1),opacity 160ms ease;
}
.guia-carta.saliendo{opacity:0}
.guia-carta::before{
  content:"";position:absolute;width:12px;height:12px;background:var(--panel);
  border:1px solid var(--linea-fuerte);transform:rotate(45deg);
}
.guia-carta[data-flecha="arriba"]::before{top:-7px;left:28px;border-right:none;border-bottom:none}
.guia-carta[data-flecha="abajo"]::before{bottom:-7px;left:28px;border-left:none;border-top:none}
.guia-carta[data-flecha="izquierda"]::before{left:-7px;top:26px;border-right:none;border-top:none}
.guia-carta[data-flecha="ninguna"]::before{display:none}

/* Los pasos de apertura y cierre van centrados, sin nada que señalar. */
.guia-carta.centrada{
  position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
  width:min(500px,calc(100vw - 28px));padding:32px 34px 24px;
  animation:guia-entra 300ms cubic-bezier(.2,.7,.2,1);
}
.guia-carta.centrada .guia-carta__titulo{font-size:1.5rem;line-height:1.28}
.guia-carta.centrada .guia-carta__texto{font-size:0.9688rem}
@keyframes guia-entra{from{opacity:0;transform:translate(-50%,-46%)}to{opacity:1;transform:translate(-50%,-50%)}}

.guia-carta__kicker{
  display:flex;align-items:center;gap:9px;font-size:0.6562rem;letter-spacing:.19em;
  text-transform:uppercase;color:var(--oro);margin-bottom:11px;
}
.guia-carta__kicker-num{
  font-family:var(--mono);letter-spacing:0;font-size:0.6875rem;color:var(--mudo);
  padding:1px 8px;border-radius:20px;background:var(--panel-2);border:1px solid var(--linea);
}
.guia-carta__titulo{margin:0 0 9px;font-size:1.0938rem;font-weight:600;letter-spacing:-.012em;line-height:1.32;color:var(--texto)}
.guia-carta__texto{margin:0;color:var(--suave);font-size:0.9062rem;line-height:1.62}
.guia-carta__texto b,.guia-carta__nota b{color:var(--texto);font-weight:600}
.guia-carta__nota{
  margin-top:13px;padding:10px 13px;border-radius:var(--r-sm);font-size:0.8438rem;line-height:1.55;
  background:var(--panel-2);border-left:3px solid var(--oro);color:var(--suave);
}

.guia-carta__progreso{height:2px;background:var(--linea);border-radius:2px;margin:18px 0 14px;overflow:hidden}
.guia-carta__progreso i{display:block;height:100%;background:var(--oro);transition:width 300ms cubic-bezier(.2,.7,.2,1)}

.guia-carta__controles{display:flex;align-items:center;gap:12px}
.guia-carta__botones{display:flex;gap:8px;margin-left:auto}
.guia-carta__salir{background:none;border:none;color:var(--mudo);font-size:0.8125rem;padding:6px 2px}
.guia-carta__salir:hover{color:var(--texto);text-decoration:underline}

/* Lanzador permanente en el menú */
.nav a.guia-abrir{
  color:var(--oro);margin-top:6px;border:1px dashed rgba(212,168,87,.3);justify-content:center;
}
.nav a.guia-abrir:hover{background:rgba(212,168,87,.1);border-style:solid}

@media (max-width:640px){
  .guia-carta{width:calc(100vw - 24px)}
  .guia-carta.centrada{padding:24px 22px 18px}
  .guia-carta.centrada .guia-carta__titulo{font-size:1.25rem}
}
@media (prefers-reduced-motion:reduce){
  .guia-foco,.guia-carta,.guia-carta__progreso i{transition:none}
  .guia-carta.centrada{animation:none}
}
```

### Tokens que da por supuestos

Si el proyecto de destino no los tiene, defínalos o sustitúyalos. Estos son los
de CONCIL en tema oscuro:

```css
:root{
  --panel:#0b1026;               /* fondo de la tarjeta */
  --panel-2:#111838;             /* fondo de la nota y de la pastilla del número */
  --linea:rgba(255,255,255,.08); /* filos suaves */
  --linea-fuerte:rgba(212,168,87,.28);
  --velo-fuerte:rgba(3,5,15,.86);/* el oscurecido */
  --sombra:rgba(0,0,0,.62);
  --texto:#f3eedd; --suave:rgba(243,238,221,.72); --mudo:rgba(243,238,221,.46);
  --oro:#d4a857;                 /* el color de acento de toda la visita */
  --r:10px; --r-sm:6px;
  --mono:ui-monospace,Menlo,Consolas,monospace;
}
```

**El velo NO sigue al tema.** En tema claro sigue siendo oscuro, solo que menos:
`rgba(46,36,14,.44)`. Un velo casi blanco sobre una interfaz clara no atenúa
nada, la borra, y el elemento señalado deja de destacar porque todo lo demás
también se ve. Si su proyecto tiene tema claro, redefina `--velo-fuerte` a un
oscuro translúcido y no a un claro.

**Los botones de la tarjeta usan las clases del proyecto** (`.btn`, `.btn-sm`,
`.btn-oro`). Es a propósito: los botones de la visita tienen que verse como
todos los demás botones, porque enseñan a usarlos. Si su proyecto los llama de
otra forma, cambie las tres cadenas en `pintar()`.

**Todo en `rem`, nunca en píxeles, para lo que es texto.** En CONCIL hay un
selector de tamaño de letra (`:root[data-escala]`) y la visita tiene que crecer
con él: si un `font-size` de la tarjeta va en píxeles, ese trozo se queda
pequeño cuando alguien elige letra grande. Las medidas de **posición** (padding,
gap, offsets del recorte) sí van en píxeles: no dependen del tamaño de letra.

---

## 5 · El enganche en el armazón

Tres cosas en la plantilla compartida. **El botón que la lanza:**

```html
<a href="#" class="guia-abrir" data-guia-abrir>Visita guiada</a>
```

Puede haber varios en la aplicación: el motor engancha **todos** los
`[data-guia-abrir]`. En CONCIL hay uno permanente al final del menú, para que
volver a verla no cueste buscar en ajustes.

**Los datos, justo antes del script:**

```php
<script>
window.GUIA = <?= json_encode([
    'ruta'  => $ruta,
    'pasos' => guia_pasos(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/guia.js?v=12"></script>
```

Las cuatro banderas `JSON_HEX_*` **no son opcionales**: los textos llevan HTML
(`<b>`, `<i>`) y sin ellas un `</` dentro de una cadena cierra el `<script>` y
rompe la página entera. `JSON_UNESCAPED_UNICODE` es para que los acentos viajen
como acentos y no como `ó`.

El `?v=12` del final es el reventador de caché. **Súbalo cada vez que toque el
JS**: sin eso, el navegador de quien ya entró sigue con la versión vieja y
usted persigue un fantasma. Lo mismo con el CSS.

**El script va al final del `<body>`**, después del marcado. Si va en el
`<head>`, `document.querySelector` no encuentra ninguna ancla y todos los pasos
se saltan.

---

## 6 · La ayuda fija de cada pantalla

La visita es un minuto, una vez. La **ayuda de pantalla** es la frase que queda
para siempre bajo el título de cada sección, y en la práctica se lee más que la
visita. Van juntas porque se escriben con el mismo criterio y conviene tenerlas
en el mismo archivo, una debajo de la otra: así no se contradicen.

```php
/** Frase de ayuda fija bajo el título de cada pantalla. */
function ayuda_pantalla(string $ruta): string
{
    return [
        'panel'      => 'Esta es la foto del mes: <b>cuánto salió y en qué se fue</b>. '
                      . 'Haga clic en cualquier renglón para ver los pagos que lo componen.',
        'carga'      => 'Suelte aquí los archivos que le manda el banco. <b>Puede subir varios de una vez</b>, '
                      . 'y si repite un archivo no se duplica nada.',
        'pendientes' => 'Estos son los pagos que el sistema <b>no puede adivinar solo</b>. '
                      . 'Explique de qué se trata cada grupo y no se lo volverá a preguntar.',
    ][$ruta] ?? '';
}
```

Y en el armazón, después del título y antes del contenido:

```php
<?php $ayuda = ayuda_pantalla($ruta); if ($ayuda !== ''): ?>
  <div class="ayuda-pantalla">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".7" fill="currentColor" stroke="none"/></svg>
    <div><?= $ayuda ?></div>
  </div>
<?php endif ?>
```

```css
.ayuda-pantalla{
  display:flex;gap:11px;align-items:flex-start;margin:-6px 0 20px;padding:12px 15px;
  background:var(--panel);border:1px solid var(--linea);border-left:3px solid var(--oro);
  border-radius:var(--r-sm);color:var(--suave);font-size:0.875rem;line-height:1.55;
}
.ayuda-pantalla svg{width:17px;height:17px;flex:none;margin-top:2px;stroke:var(--oro);fill:none;stroke-width:1.7}
.ayuda-pantalla b{color:var(--texto);font-weight:600}
```

Fíjese en que **el filo dorado a la izquierda es el mismo** que el de la nota de
la tarjeta. Es lo que hace que las dos cosas se lean como la misma voz.

> Estos textos **no** pasan por la función de escape (`e()`) porque llevan
> `<b>`. Es una excepción deliberada y solo vale porque las cadenas están
> escritas en el propio código y nunca vienen de fuera. Si algún día un texto de
> ayuda saliera de la base de datos, hay que escaparlo.

---

## Cómo se escriben los textos

Esta es la mitad del trabajo, y la que hace que guste. El público incluye
personas que no trabajan con sistemas.

**Diga qué gana la persona, no qué hace el programa.**

> ✗ «El sistema aplica reglas de mapeo sobre los movimientos importados.»
> ✓ «Con esta casilla marcada, el sistema **aprende**. Ese tipo de pago llegará
>    ya clasificado el mes que viene y no se lo volverá a preguntar nunca.»

**El título es una frase, no una etiqueta.** «Cuentas» no dice nada. «Cuánto
tiene en cada banco» sí.

**Nada de jerga sin explicar**: conciliar, mapear, importar, filtro, patrón,
regla, registro. Si tiene que usar la palabra, explíquela en la misma frase.

**Dos o tres líneas por paso. Punto.** Lo que no cabe, o va en la `nota`, o no
era importante.

**La `nota` es para lo que tranquiliza.** «Si sube dos veces el mismo archivo no
pasa nada.» «Que salga no quiere decir que esté mal; quiere decir que vale la
pena mirarlo.» «Es cosa de cada quien: cambiarlo no le mueve nada a sus
compañeros.» Ese recuadro es el que quita el miedo a tocar.

**El negrita se gana.** Una o dos palabras por paso, las que se recuerdan si
solo se lee eso.

**Trato de usted, siempre, y en toda la aplicación.** Imperativo en usted:
«haga», «elija», «vuelva», «escriba», «revise». Nunca «haz», «elige», «vuelve».
Mezclarlo se nota aunque nadie sepa decir por qué. Ojo con los mensajes de error
y los avisos, que es donde se cuela el tuteo.

**Español del país de quien lo usa.** En Venezuela: «el mouse», «hacer clic»,
«computadora», «archivo», «de lugar», «al final de». No: «el ratón», «pulsar»,
«ordenador», «fichero», «de sitio», «abajo del todo». Un texto que suena
importado le quita autoridad al producto delante de quien lo va a aprobar.

**Cierre con el día a día en tres pasos numerados.** Es lo único que la gente
recuerda al día siguiente, y es lo que hace que la visita se sienta corta
aunque tenga treinta pasos.

---

## Trampas que ya se pagaron

**`hidden` pierde contra cualquier clase con `display`.** La hoja del navegador
tiene menos prioridad que la del proyecto, así que un `.nav{display:flex}` anula
el atributo `hidden` y el elemento se sigue viendo. Ponga
`[hidden]{display:none!important}` al principio de la hoja. Si no,
`getClientRects()` devuelve un rectángulo para algo que la persona no ve, y el
recorte señala el aire.

**Renombrar un ancla rompe un paso en silencio.** No hay error, no hay consola:
el paso simplemente se salta. Al renombrar o quitar un elemento con `data-guia`,
busque el paso que lo apunta. Un `grep` de tres segundos:

```bash
# Anclas que existen en las vistas, contra las que el catálogo dice señalar.
grep -rho 'data-guia="[^"]*' views/     | sed 's/.*"//' | sort -u > /tmp/anclas
grep -o   'data-guia="[^"]*' lib/guia.php | sed 's/.*"//' | sort -u > /tmp/pasos
comm -13 /tmp/anclas /tmp/pasos   # pasos que apuntan a un ancla que ya no existe
comm -23 /tmp/anclas /tmp/pasos   # anclas puestas y nunca usadas por ningún paso
```

**El primer paso de una sección puede no existir todavía.** Un ancla que solo
aparece cuando hay datos —una tabla vacía, un aviso que solo sale a veces— hace
que la visita llegue a esa sección y no tenga qué señalar. Por eso `arrancar()`
adelanta al siguiente paso al llegar. Compruébelo **con la base vacía**: es
como la va a ver quien la estrena, y es justo cuando más falta le hace.

**Recorra la visita con dos usuarios distintos**, uno con todos los permisos y
otro sin ellos. Media aplicación cambia según quién mire, y los pasos que
apuntan a lo que solo ve el administrador se saltan para los demás — que es lo
correcto, pero hay que verlo funcionar.

**No la lance encima de alguien que está trabajando.** La condición
`RUTA === 'panel'` del arranque automático no es cosmética: aparecer sola sobre
una pantalla a medio llenar es la forma más rápida de que la primera reacción a
su producto sea cerrar algo.

**Compruébela con el zoom del navegador y con la letra grande.** Es donde se
descubre el `font-size` en píxeles que se le escapó, y donde la tarjeta se sale
de la pantalla si olvidó el `Math.min` final.

---

## Llevarla a otro stack

El motor solo toca el DOM y la barra de direcciones. Lo único que cambia es
**cómo se llega a una sección** y **de dónde salen los pasos**.

### Otro backend servido por el servidor (Node, Python, Go…)

Cambie `direccion()` por el esquema de rutas del proyecto y publique los pasos
como JSON:

```js
function direccion(ruta, paso) {
  return '/' + ruta + '?guia=' + paso;      // rutas por camino en vez de ?r=
}
```

```html
<script>window.GUIA = {"ruta": "{{ ruta }}", "pasos": {{ pasos | tojson }}};</script>
<script src="/static/guia.js?v=1"></script>
```

Los pasos pueden vivir igual de bien en un `guia.json`, un módulo JS o una
función del lenguaje que sea. Lo importante es que sean **datos en un solo
sitio**, no marcado repartido.

### Una SPA (React, Vue, Svelte…)

Aquí no hay recarga, así que **sobra la mitad del mecanismo**: no hace falta
`?guia=N`, ni `location.href`, ni `localStorage` para sobrevivir al salto.

- `direccion()` desaparece; en `mover()`, donde hoy hay `location.href = …`,
  llame al enrutador (`navigate('/' + PASOS[n].ruta)`) y **espere a que la vista
  nueva esté montada** antes de `pintar(n)`. Un `requestAnimationFrame` doble
  suele bastar; si la vista carga datos, mejor un `MutationObserver` o un
  pequeño reintento hasta que `ancla()` conteste.
- `RUTA` deja de ser una constante: léala del enrutador en cada `mover()`.
- El resto —velo, recorte, colocación de la tarjeta, teclado, saltos de anclas
  ausentes— vale tal cual.
- `localStorage` se queda solo para «esta persona ya la vio».

**El aviso que importa en SPA:** si los componentes se remontan, el elemento que
tenía en `ancla()` deja de estar en el documento y el recorte se queda clavado.
Vuelva a resolver el selector en cada `recolocar()` —que es lo que ya hace
`colocar()`— y no guarde el nodo en una variable de larga vida.

### Sin backend (una página estática)

Funciona con una sola sección: ponga todos los pasos con la misma `ruta` y el
motor nunca navegará. Es el modo más sencillo y sigue quedando bien.

---

## Lista de comprobación

Antes de dar la visita por buena:

- [ ] Se recorre entera con **→** y se sale con **Esc**.
- [ ] El botón «Siguiente» tiene el foco en cada paso; se puede hacer todo a
      golpe de Enter.
- [ ] Ningún paso señala el aire (recorte en la esquina superior izquierda).
- [ ] Cada `sel` del catálogo tiene su `data-guia` en las vistas.
- [ ] Funciona **con la base vacía** y con la base llena.
- [ ] Se recorre con un usuario sin permisos y no se rompe.
- [ ] En una ventana de 400 px de ancho la tarjeta no se sale ni tapa lo que
      señala.
- [ ] Con letra grande / zoom al 150 % todo crece, incluida la tarjeta.
- [ ] Con `prefers-reduced-motion` activo no hay desplazamiento animado ni
      transiciones.
- [ ] Al salir, la dirección queda limpia (sin `?guia=`).
- [ ] Al recargar en mitad del recorrido no vuelve a empezar sola.
- [ ] La versión del `?v=` del `<script>` y del CSS subió.
- [ ] Nadie tutea en ningún texto.
- [ ] El último paso resume el día a día y firma con la marca.

---

<div align="center">

*Documentado a partir de la implantación en CONCIL · v2.7.10*
Su hermano: [versionado y pantalla de Mejoras](versionado-y-mejoras.md)

**by VIP Soft**

</div>
