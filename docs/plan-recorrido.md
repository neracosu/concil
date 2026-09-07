# Plan de construcción — las nueve del recorrido

Sale de `sugerencias-recorrido.md`, donde están las nueve tal como las pidieron.
Aquí está **en qué orden se construyen y por qué en ese orden**.

Total estimado: **siete a nueve días de trabajo**, repartidos en siete entregas
que se pueden usar en cuanto salen. Nada de esperar al final.

---

## El reinicio manda el calendario

**Lo que hay cargado hoy son pruebas.** Los 1.100 movimientos, las dos
importaciones y lo poco justificado a mano se van a **borrar antes de que el
equipo empiece a trabajar de verdad**. Eso quita una urgencia y crea otra, más
clara: no hay que correr detrás de datos que ya existen, hay que llegar
**antes del primer día real**.

Por eso el trabajo se parte en dos paquetes:

- **Antes de que arranquen** — Fases 1, 2 y 3. Unos **dos días**. Es lo que, si
  no está puesto el primer día, deja un agujero que después no se rellena.
- **Sobre la marcha** — Fases 4, 5, 6 y 7. Entran mientras ellos ya usan el
  sistema, sin frenar el arranque.

## Las tres razones que mandan en el orden

**1. El autor solo se puede guardar hacia adelante.** El sistema anota si una
categoría la puso una regla o una persona, pero nunca guardó **qué** persona.
No se puede reconstruir después: lo que se clasifique sin la columna puesta
queda sin nombre para siempre. Como la base arranca vacía, **no hay nada que
rescatar hacia atrás** —lo que hay en `bitacora` es de las pruebas y se va con
ellas—, pero por lo mismo la columna tiene que estar **el día uno**. Es un día
de trabajo y es la única fase que no admite llegar tarde.

**2. La nº 6 protege desde el primer pago; la 2 y la 3 no.** Las tres son
contra los pagos duplicados, pero la 2 y la 3 solo funcionan **si las facturas
están anotadas**, y anotar facturas es un hábito que el equipo va a tardar en
tomar: van a justificar pagos mucho antes de empezar a cargar facturas. La nº 6
—avisar cuando a un proveedor se le repite el mismo monto— funciona con solo
los movimientos del banco. Va antes.

**3. La nº 1 es más barata antes del arranque que después.** Corregir la tasa
de un día choca con que el reparto de un pago congela la tasa del momento.
Mientras no haya un solo pago repartido no hay nada que invalidar: la decisión
se toma en frío. En cuanto auditoría empiece a repartir pagos —día uno de su
trabajo— la misma decisión se toma con dinero anotado encima.

---

## Fase 1 · Quién hizo cada cambio · nº 8 — HECHA el 06/09/2026

**Entregada como versión 1.5**, commit `4c37cee`. Comprobada por HTTP de punta
a punta: se justifica un movimiento desde la bandeja y el nombre aparece en la
lista, en el detalle y en la columna **«Quién lo hizo»** del archivo exportado.
La pasada automática de reglas **borra** el autor, y quitar la clasificación
también. Las diecisiete rutas siguen respondiendo 200 sin un aviso.

Lo que pidió auditoría con el reporte en la mano: que donde dice «manual»
aparezca el nombre de quien lo hizo.

- Columna `usuario_id` en `movimientos`, con `columna_si_falta()`.
- Se escribe al clasificar y al corregir, en `views/pendientes.php` y
  `views/movimiento.php`.
- Se muestra en la lista, en el detalle, en el reporte y en el XLSX exportado
  (`views/movimientos.php`, `views/reportes.php`, `lib/exportar.php`).
- **Sin rescate de lo viejo**: lo que hay clasificado a mano son 2 movimientos
  de prueba que se borran en el reinicio. Esto abarata la fase: la columna
  nace vacía y se llena sola desde el primer día real.

**Queda listo**: el reporte de auditoría contesta «quién» sin preguntarle a
nadie, desde el primer movimiento que justifiquen.

## Fase 2 · Ver los conceptos sin cambiar de pantalla · nº 7

**Unas horas. Sale en el mismo despliegue que la Fase 1.**

Donde dice «justificar 8 movimientos», un botón que despliega ahí mismo los 8
conceptos. Toca `views/panel.php` y `views/pendientes.php`. Es pequeña y va
pegada a la anterior porque cae en las mismas pantallas: un solo despliegue en
vez de dos.

## Fase 3 · La tasa a mano · nº 1

**Medio día. Versión 1.6.**

Poder corregir la tasa del BCV de un día cuando la que trajo la fuente no es la
que se aplicó. La pieza ya existe a medias: una tasa con `origen = 'manual'` la
sincronización **no la pisa**.

- Pantalla para corregirla desde el detalle del movimiento y desde Ajustes.
- Queda anotado quién la cambió y cuál era la anterior.
- **Depende de una respuesta suya**: si la corrección vale para el día entero
  o solo para ese movimiento. Con la respuesta «el día entero» es medio día de
  trabajo; «solo ese movimiento» es un día y una columna más.

## Fase 4 · Alerta de pagos repetidos · nº 6

**Un día. Versión 1.7.**

El aviso que funciona sin depender de que nadie anote facturas.

- Cuando a un mismo proveedor se le va **el mismo monto** dos veces en pocos
  días, sale un aviso: en la ficha del proveedor, en el panel y —lo importante—
  **en el momento de justificar**, antes de guardar.
- El aviso dice desde qué banco salió el otro pago y quién lo justificó, que es
  justo lo que hoy no se ve.
- **Depende de una respuesta suya**: con qué margen salta (monto exacto o
  parecido, y dentro de cuántos días).

**Queda listo**: el caso que contaron —dos personas pagando desde dos bancos—
deja de pasar desapercibido aunque no haya ni una factura cargada.

## Fase 5 · Que la factura no se esconda · nº 3, nº 2 y nº 4

**Dos a tres días. Versión 1.8.**

Las tres van juntas porque son la misma pantalla y la misma tabla.

- **La causa primero**: hoy, al justificar, solo se ven las facturas
  **abiertas**. En cuanto la primera persona la paga, desaparece de la pantalla
  de la segunda, que la anota de nuevo y la vuelve a pagar. Se cambia para que
  las cubiertas **se sigan viendo**, marcadas: «ya pagada el 27/08 desde TESORO
  por Eurides».
- **Aviso al guardar** si el reparto deja la factura pagada de más. El cálculo
  ya existe (`saldo_factura()` devuelve el estado `excedida`), hoy nadie lo
  enseña.
- **Número de factura comparado sin ceros, guiones ni espacios**, en una
  columna aparte con su índice. Así «0001», «1» y «F-0001» se reconocen como la
  misma y el sistema ofrece la que ya está en vez de crear otra. La clave única
  actual **no se toca**: cambiarla es la clase de migración que rompe cosas.
- **Anotar una factura sin salir de la pantalla de justificar** (nº 4), que es
  lo que hoy empuja a la gente a improvisar.

**Depende de ustedes**: esta fase protege de verdad cuando empiecen a anotar
facturas. No hace falta que esté el primer día —nadie anota facturas la primera
semana—, pero sí **antes de que auditoría tome el hábito**, o el hábito se toma
con la pantalla que esconde las facturas pagadas.

**Depende de una respuesta suya**: si pagar una factura ya cubierta se
**bloquea** o solo se **avisa**. La costumbre de la casa es advertir y dejar
pasar; esta puede ser la excepción, porque cuesta dinero.

## Fase 6 · Modo oscuro y modo claro · nº 9

**Un día. Versión 1.9.**

- Los colores pasan a variables en `views/_layout.php`, un juego claro y otro
  oscuro, y un interruptor que recuerda la elección de cada persona.
- Se revisan **las diecisiete pantallas**, no solo el diseño base: las tablas,
  los avisos, los colores de las categorías y el XLSX exportado no heredan solos.

Va después de las fases de contenido a propósito, pero **desde la Fase 1 todo
lo nuevo se escribe ya con variables de color**, para no tener que repintarlo
dos veces.

## Fase 7 · Los dos lados de un traspaso · nº 5

**Varios días. Versión 2.0. Conversación aparte antes de empezar.**

Es la única que no es una mejora sino un cambio de alcance: hoy los **371
créditos** —403 millones de bolívares— se guardan enteros pero no se clasifican
por decisión de producto. Enlazar el débito de una cuenta con el crédito de la
otra obliga a levantar esa decisión y arrastra el panel, los reportes, las
reglas y la visita guiada.

Antes de tocar código hace falta **un traspaso real de agosto** para ver cómo se
reconoce: si comparten referencia, si el monto calza exacto, cuántos días de
diferencia hay entre los dos apuntes. No hay que esperar a que carguen nada:
los extractos de `DATA_DIR/muestras/` sobreviven al reinicio y ahí están los
traspasos de verdad.

---

## Lo que necesito de ustedes

Cuatro decisiones. Ninguna urgente hoy, pero cada una frena su fase:

1. **(Fase 3)** La tasa corregida, ¿vale para el día entero o solo para ese
   movimiento?
2. **(Fase 4)** ¿Con qué margen salta la alerta de pago repetido? ¿Monto
   exacto? ¿Dentro de cuántos días?
3. **(Fase 5)** Pagar una factura que ya está cubierta: ¿se bloquea o se avisa?
4. **(Fase 5)** ¿Qué hace que dos facturas sean la misma cuando el número está
   tecleado distinto?

Y dos cosas que no son decisiones:

- **Qué se conserva en el reinicio.** Se borran movimientos e importaciones.
  Deberían quedarse las 5 cuentas, las 20 categorías, las 37 reglas, los 136
  proveedores y las 1.831 tasas del BCV: volver a cargarlos es trabajo tirado.
  Confírmenlo antes de borrar.
- **Cuándo arrancan de verdad.** De esa fecha depende cuánto del plan entra en
  el paquete de antes y cuánto sobre la marcha.

---

## Cómo se despliega sin romper lo que está en uso

Editar un archivo aquí **es** desplegarlo: no hay paso intermedio y hay tres
personas usando el sistema. Por eso:

- Cada fase se despliega de una vez, no a medias, y se comprueban las
  diecisiete rutas antes de dar por buena la entrega.
- Las migraciones siempre con `columna_si_falta()`, que se puede correr mil
  veces sin daño. Nada de tocar la clave única de `facturas`.
- Cada pantalla nueva lleva su ancla `data-guia` y su paso en la visita
  guiada, o el paso se salta en silencio y nadie se entera.
- Antes de cada fase con migración, respaldo de la base en
  `DATA_DIR/respaldos/`.
- **El reinicio es la única ocasión de comprobar que el sistema se levanta
  limpio**: con la base vacía, `migrar()` tiene que crear cada tabla y cada
  columna nueva y sembrar categorías y reglas sin ayuda. Se prueba en una base
  aparte **antes** de vaciar la de verdad, no después.
