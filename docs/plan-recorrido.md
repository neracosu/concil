# Plan de construcción — las nueve del recorrido

Sale de `sugerencias-recorrido.md`, donde están las nueve tal como las pidieron.
Aquí está **en qué orden se construyen y por qué en ese orden**.

Total estimado: **siete a nueve días de trabajo**, repartidos en siete entregas
que se pueden usar en cuanto salen. Nada de esperar al final.

---

## Las tres razones que mandan en el orden

**1. Hay un reloj corriendo con la nº 8.** El sistema guarda si una categoría
la puso una regla o una persona, pero nunca guardó **qué** persona. Hoy hay
**444 movimientos clasificados por regla, solo 2 a mano y 283 sin justificar**.
Esos 283 los van a justificar personas, una por una, en los próximos días. Cada
uno que se justifique antes de añadir la columna del autor **queda sin autor
para siempre**. Por eso la nº 8 va primera aunque no sea la más vistosa.

**2. La nº 6 protege hoy; la 2 y la 3 todavía no.** Las tres son contra los
pagos duplicados, pero la 2 y la 3 solo funcionan **si las facturas están
anotadas**, y la tabla `facturas` está vacía. La nº 6 —avisar cuando a un
proveedor se le repite el mismo monto— funciona con lo que ya hay cargado, sin
que nadie tenga que anotar nada. Va antes.

**3. La nº 1 es más barata hoy que dentro de un mes.** Corregir la tasa de un
día choca con que el reparto de un pago congela la tasa del momento. Como
`pagos_factura` está **vacía**, ahora mismo no hay nada que invalidar: la
decisión se toma en frío y sin víctimas. Si se deja para después de empezar a
repartir pagos, hay que decidirla con dinero ya anotado encima.

---

## Fase 1 · Quién hizo cada cambio · nº 8

**Un día. Versión 1.5.**

Lo que pidió auditoría con el reporte en la mano: que donde dice «manual»
aparezca el nombre de quien lo hizo.

- Columna `usuario_id` en `movimientos`, con `columna_si_falta()`.
- Se escribe al clasificar y al corregir, en `views/pendientes.php` y
  `views/movimiento.php`.
- Se muestra en la lista, en el detalle, en el reporte y en el XLSX exportado
  (`views/movimientos.php`, `views/reportes.php`, `lib/exportar.php`).
- **Rescate de lo viejo**: la tabla `bitacora` guarda 8 justificaciones y 12
  correcciones con su autor. De ahí se rellena lo poco que hay hecho a mano.
  Lo que no aparezca queda en blanco, sin inventar.

**Queda listo**: el reporte de auditoría contesta «quién» sin preguntarle a
nadie.

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
facturas. Mientras `facturas` siga vacía, es una red tendida bajo un trapecio
donde nadie ha subido todavía.

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
diferencia hay entre los dos apuntes.

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

- **Empezar a anotar facturas.** Sin eso, las fases 5 protegen en el papel.
- **Un traspaso real de agosto** entre dos cuentas propias, para la Fase 7.

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
