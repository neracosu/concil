# Plan de construcción — las nueve del recorrido

Sale de `sugerencias-recorrido.md`, donde están las nueve tal como las pidieron.
Aquí está **en qué orden se construyen y por qué en ese orden**.

Total estimado: **siete a nueve días de trabajo**, repartidos en siete entregas
que se pueden usar en cuanto salen. Nada de esperar al final.

> **Terminado el 06/09/2026, el mismo día.** Las siete fases, de la v1.5 a la
> v2.1. La estimación se quedó larga porque las fichas 2, 3 y 4 resultaron ser
> **la misma pantalla vista desde tres lados** y salieron de una vez. Lo que
> quedó de cada una está en [el cuaderno](sugerencias-recorrido.md), ficha por
> ficha, con su commit.

---

## El reinicio manda el calendario

> **Ya pasó.** La base se vació el 31/08, se volvió a vaciar el 07/09 y se
> limpió por última vez el **08/09/2026 a las 14:51**, con las siete fases
> dentro y ya para el arranque real. Se conservaron usuarios, proveedores,
> categorías, reglas, tasas, la unidad de negocio y los formatos aprendidos.
> Lo que sigue abajo es cómo se razonó entonces.

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

## Fase 2 · Ver los conceptos sin cambiar de pantalla · nº 7 — HECHA el 06/09/2026

**Entregada como versión 1.6.**

En la bandeja, cada tarjeta de grupo lleva ahora un desplegable **«Ver los 8
conceptos»** con la fecha, la cuenta, el concepto tal como vino del banco, la
referencia y el monto de cada uno. Se abre sin salir de la pantalla y cada
fecha enlaza a su movimiento.

- Los movimientos de los grupos visibles se traen **en una sola consulta**, con
  `ROW_NUMBER()` de MariaDB, y como mucho 30 por grupo (`TOPE_CONCEPTOS`): así
  el corte lo hace la base y no se leen filas de más. Cuando hay más, la
  tarjeta lo dice y remite a «Ver uno por uno».
- Se añadió su paso en la visita guiada, con el ancla `data-guia="conceptos"`.
- Se quedó **en la bandeja y no en el panel**: es ahí donde se decide
  clasificar ocho de una vez, y era ahí donde había que ver los ocho.

## Fase 3 · La tasa a mano · nº 1 — HECHA el 06/09/2026

**Entregada como versión 1.7.** El usuario decidió: **la corrección vale para
el día entero**, no para un movimiento suelto. El BCV publica una tasa por día
y dos tasas distintas el mismo día es justo lo que nadie sabría explicar
después.

- Se corrige desde **Ajustes** (cualquier fecha) y desde el **propio pago** («La
  tasa de ese día no es la correcta»), que es donde se nota el error.
- La tasa escrita a mano queda marcada `origen = 'manual'` y **la
  sincronización no la pisa** — comprobado trayendo los 1.830 días del
  histórico encima de una corregida.
- Queda anotado **quién** la escribió (`tasas.usuario_id`, se ve en el detalle:
  «escrita a mano por Eurides») y **cuál era la anterior**, en la bitácora:
  `04/09/2026 · 807,39 → 810,50`.
- Se rechaza lo que no tiene sentido: fecha futura, fecha ilegible y tasa cero
  o negativa. El tope alto ataja el dedo que corre los decimales.
- Lo ya repartido entre facturas **no se mueve**: `pagos_factura.tasa` guarda la
  del momento del reparto a propósito, y así se dice en la pantalla.
- Paso nuevo en la visita guiada, con el ancla `data-guia="tasa-mano"`.

## Fase 4 · Alerta de pagos repetidos · nº 6 — HECHA el 06/09/2026

**Entregada como versión 1.8.** Margen elegido: **mismo proveedor, mismo monto
exacto, treinta días** (`DIAS_PAGO_REPETIDO` en `lib/proveedores.php`), mirando
**todas las cuentas** de la unidad, que es justo el caso que contaron.

Sale en tres sitios:
- **Al justificar**, encima de las facturas y antes de guardar, que es donde
  todavía se puede evitar. Se dibuja en `aviso_pagos_repetidos()` de
  `views/_facturas.php`, por donde pasan la bandeja, el detalle y el trozo que
  pide el navegador al escribir el proveedor: los tres avisan solos.
- **En la ficha del proveedor**, con cuántas veces y desde qué bancos.
- **En el panel**, una tarjeta fija que dice «ninguno» cuando no hay nada: la
  ausencia de aviso también es información.

Cuando el mismo monto salió de **dos bancos distintos**, el aviso lo dice en
rojo y con todas las letras. Paso nuevo en la visita guiada
(`data-guia="repetidos"`).

El aviso que funciona sin depender de que nadie anote facturas.

- Cuando a un mismo proveedor se le va **el mismo monto** dos veces en pocos
  días, sale un aviso: en la ficha del proveedor, en el panel y —lo importante—
  **en el momento de justificar**, antes de guardar.
- El aviso dice desde qué banco salió el otro pago y quién lo justificó, que es
  justo lo que hoy no se ve.
**Quedó listo**: el caso que contaron —dos personas pagando desde dos bancos—
deja de pasar desapercibido aunque no haya ni una factura cargada.

## Fase 5 · Que la factura no se esconda · nº 3, nº 2 y nº 4 — HECHA el 06/09/2026

**Entregada como versión 1.9.** Comprobada con el caso real: se justifica un
pago con dos facturas nuevas, y desde otro pago del mismo proveedor la segunda
persona **ve** que ya están pagadas y **no puede** volver a anotarlas.

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

**Sobre bloquear o avisar**: resultó que `repartir_pago()` **ya bloqueaba**
cargar más de lo que falta, desde la Fase 3 del proyecto anterior. Lo que
faltaba no era el bloqueo sino que la persona llegara hasta él: la factura no
se veía. Ahora el bloqueo además dice **quién la pagó y desde qué banco**, en
vez del seco «solo le faltan 0,00».

## Fase 6 · Modo oscuro y modo claro · nº 9 — HECHA el 06/09/2026

**Entregada como versión 2.0.**

- Los colores pasan a variables en `views/_layout.php`, un juego claro y otro
  oscuro, y un interruptor que recuerda la elección de cada persona.
- Se revisan **las diecisiete pantallas**, no solo el diseño base: las tablas,
  los avisos, los colores de las categorías y el XLSX exportado no heredan solos.

Cómo quedó:
- **Tres opciones, no un interruptor**: Automático, Claro y Oscuro. «Automático»
  es un estado de verdad —sigue a la computadora— y con dos botones no habría
  manera de volver a él.
- La elección **va en la ficha de la persona** (`usuarios.tema`), así que la
  lleva a cualquier computadora, **y en una galleta**, porque la pantalla de
  acceso se dibuja antes de saber quién entra.
- Los colores estaban casi todos en variables; hubo que sacar a variable los
  cuatro que quedaban crudos (líneas de tabla, velos, sombras) y **la letra que
  va encima del dorado**, que en claro se volvía ilegible.
- Las catorce pantallas comprobadas en claro: 200 y sin un aviso.

## Fase 7 · Los dos lados de un traspaso · nº 5 — HECHA el 06/09/2026

**Entregada como versión 2.1.**

Cómo quedó, y por qué así:
- Columna `movimientos.traspaso_id`, apuntada **en las dos filas**, cada una a
  la otra: desde cualquiera de las dos se llega a su pareja.
- `enlazar_traspasos()` en `lib/reglas.php` **solo ata lo que no admite
  discusión**: mismo monto, cuentas distintas de la misma unidad, hasta tres
  días de diferencia, y **un solo candidato de cada lado**. Con dos iguales no
  se elige a la suerte, porque un enlace equivocado esconde un pago de verdad.
- Lo que no se atreve a decidir **se lo pregunta a una persona**: en el detalle
  del movimiento aparece «¿Es un traspaso a otra cuenta suya?» con los
  candidatos y un botón. Y siempre se puede soltar: «No son el mismo dinero».
- La pasada corre **al terminar cada carga** —la pareja puede llevar meses
  esperando en la otra cuenta— y con un botón en Reglas.
- En el listado, los traspasos se marcan.
- Paso nuevo en la visita guiada (`data-guia="traspasos"`).

**Sobre los créditos**: se atan, se ven y se marcan, pero **siguen sin
clasificarse**. La decisión de producto no se levantó del todo, solo lo justo
para responder a lo que pidieron; el día que quieran clasificar créditos, es
quitar el filtro `tipo = 'D'`, no volver a importar.

**Lo que se vio en los datos antes de programar**: en el extracto de Banesco
hay 26 débitos con la forma `TRFOB 0163 J500198175 ARMORMARKET 2025 7492` —el
código del banco de destino (0163, el Tesoro) y **el RIF de la propia empresa**—.
Son traspasos a su propia cuenta del Tesoro, escritos por el banco que los
manda. El otro lado no se pudo comprobar contra la base porque lo cargado es de
meses distintos: Banesco de julio y Tesoro solo del 27 de agosto.

---

## Lo que necesitaba de ustedes — todo respondido

Las cuatro decisiones se tomaron y están construidas. Quedan aquí con su
respuesta; el detalle, en [el cuaderno](sugerencias-recorrido.md).

1. **(Fase 3)** La tasa corregida → **vale para el día entero**.
2. **(Fase 4)** Margen de la alerta → **mismo proveedor, monto exacto, 30 días,
   todas las cuentas**.
3. **(Fase 5)** Factura ya cubierta → **se avisa** enseñando quién la pagó y
   desde qué banco, y **se bloquea** repartir más de lo que salió del banco.
4. **(Fase 5)** Dos facturas son la misma → **por el número normalizado**, sin
   ceros a la izquierda ni signos.

Y las dos que no eran decisiones:

- **Qué se conservó en el reinicio**: los 8 usuarios, los 136 proveedores, las
  30 categorías, las 61 reglas, las 1.835 tasas del BCV, la unidad de negocio y
  los 6 formatos aprendidos. Las cuentas **no**: tenían la ficha vacía y el
  equipo las registra de nuevo con su número, su titular y su saldo de arranque.
- **Cuándo arrancan**: el reinicio definitivo fue el 08/09/2026. Todo el plan
  entró antes, así que no hizo falta el paquete de «sobre la marcha».

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
