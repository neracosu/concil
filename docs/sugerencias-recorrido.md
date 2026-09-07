# Recorrido del 04/09/2026 — sugerencias recogidas

Cuaderno de campo del recorrido: aquí se anota **lo que pide la gente**, tal
como lo dice, mientras lo dice. No es un plan todavía. Cuando la lluvia de
ideas termine, de aquí sale el plan de construcción.

Se escribe en el momento y se guarda enseguida: si se cae la conexión, lo
anotado sigue aquí y en el historial de git.

**Cómo leer cada ficha**

- **Lo que dijeron** — sus palabras, sin traducir. Es lo que vale cuando dentro
  de dos semanas haya dudas de qué se pidió.
- **Qué significa** — la traducción a lo que habría que construir.
- **Dónde toca** — archivos y tablas que se moverían.
- **Tamaño** — S (unas horas), M (un día), L (varios días).
- **Estado** — `recogida` · `entendida` · `en el plan` · `descartada`.

---

## Resumen

| Nº | Sugerencia | Tamaño | Estado |
|----|------------|--------|--------|
| 1 | Poder corregir a mano la tasa del día de un movimiento | S–M | recogida |
| 2 | Avisar de facturas repetidas que ya se habían registrado | M | recogida |
| 3 | Reconocer la misma factura pagada desde cuentas distintas | M | recogida |
| 4 | Añadir facturas nuevas sin salir de la pantalla de justificar | S–M | recogida |
| 5 | Enlazar el débito de una cuenta con el crédito de la otra en los traspasos | L | recogida |
| 6 | Alertar cuando a un proveedor se le repite el mismo monto | M | recogida |
| 7 | Desplegar los conceptos detrás de «justificar 8 movimientos» | S | recogida |
| 8 | Que el reporte diga **quién** hizo el cambio manual, no solo que fue manual | M | recogida |
| 9 | Modo oscuro y modo claro en toda la plataforma | M | recogida |

---

## Fichas

### 1 · Corregir a mano la tasa del día de un movimiento

- **Lo que dijeron**: «permitir modificar la tasa del día de cada movimiento.»
- **Qué significa**: hoy la tasa la trae sola el BCV, una fila por día de
  calendario, y el movimiento usa la de su fecha sin que nadie pueda tocarla.
  Piden poder poner otra a mano cuando la del BCV no sea la que se aplicó.
- **Dónde toca**: `lib/tasas.php` (ya existe `origen = 'manual'`, que la
  sincronización respeta y no pisa), `views/movimiento.php`, y la tabla
  `tasas`. Si la corrección tiene que ser de un solo movimiento y no del día
  entero, hace falta además una columna de tasa propia en `movimientos`.
- **Ojo**: lo ya repartido entre facturas **no se mueve**, porque
  `pagos_factura.tasa` congela la del día del movimiento. Hay que decidir si
  cambiar la tasa rehace esos repartos o los deja como estaban.
- **Tamaño**: S si es la del día; M si es por movimiento.
- **Estado**: `recogida`

### 2 · Avisar de facturas repetidas que ya se habían registrado

- **Lo que dijeron**: «revisar facturas duplicadas ya reportadas.»
- **Qué significa**: que el sistema avise cuando la factura que se está
  anotando ya estaba registrada antes, para no pagarla dos veces.
- **Dónde toca**: `lib/proveedores.php`, `views/facturas_panel.php`,
  `views/_facturas.php`, tabla `facturas`.
- **Tamaño**: M
- **Estado**: `recogida` — falta aclarar qué cuenta como repetida (ver
  preguntas).

### 3 · Reconocer la misma factura pagada desde cuentas distintas

- **Lo que dijeron**: «identificar facturas también entre cuentas para evitar
  pagos duplicados.»
- **Qué significa**: la misma factura se puede pagar desde el Tesoro y desde
  Banesco sin que nadie lo note, porque cada cuenta se mira por separado. Piden
  que la búsqueda de facturas cruce **todas** las cuentas.
- **Dónde toca**: `lib/consultas.php` (la factura hoy ya es de la sede, no de
  la cuenta: hay que comprobar dónde se está estrechando la búsqueda),
  `lib/proveedores.php`, `views/_facturas.php`.
- **Tamaño**: M
- **Estado**: `recogida`

### 4 · Añadir facturas nuevas sin salir de la pantalla de justificar

- **Lo que dijeron**: «opción para añadir mas facturas al momento de
  justificar.»
- **Qué significa**: cuando se está justificando un pago y la factura todavía
  no está cargada, poder crearla ahí mismo en lugar de irse a la pantalla de
  proveedores y volver.
- **Dónde toca**: `views/_facturas.php`, `views/pendientes.php`,
  `views/movimiento.php`.
- **Ojo**: el reparto de un pago se rehace entero cada vez que se guarda, así
  que la factura nueva tiene que quedar creada **y** marcada en el mismo envío,
  o se pierde.
- **Tamaño**: S–M
- **Estado**: `recogida`

### 5 · Enlazar los dos lados de un traspaso entre cuentas propias

- **Lo que dijeron**: «relacionar débitos desde una cuenta a crédito a otra
  cuenta en los traspasos.»
- **Qué significa**: cuando se mueve dinero de una cuenta propia a otra, hoy
  salen dos movimientos sueltos —el débito en una y el crédito en la otra— y
  parecen un gasto y un ingreso. Piden que el sistema los reconozca como las
  dos caras de lo mismo.
- **Dónde toca**: `lib/reglas.php`, `lib/consultas.php`, tabla `movimientos`
  (haría falta guardar el enlace entre los dos), y las pantallas de pendientes
  y detalle.
- **Ojo**: es la primera petición que **obliga a trabajar con los créditos**,
  que hoy se guardan pero no se clasifican a propósito. No es un cambio de
  pantalla, es levantar esa decisión.
- **Tamaño**: L
- **Estado**: `recogida`

### 6 · Alertar cuando a un proveedor se le repite el mismo monto

- **Lo que dijeron**: «alerta cuando un proveedor repite el mismo monto de pago
  o patrones similares asociados a pagos duplicados.»
- **Qué significa**: un aviso automático cuando dos pagos al mismo proveedor se
  parecen demasiado —mismo monto, fechas cercanas— porque suele ser el mismo
  pago hecho dos veces.
- **Dónde toca**: `lib/consultas.php`, `views/proveedor.php`,
  `views/panel.php`.
- **Tamaño**: M
- **Estado**: `recogida` — falta saber qué margen los hace sospechosos (ver
  preguntas).

### 7 · Desplegar los conceptos detrás de «justificar 8 movimientos»

- **Lo que dijeron**: «cuando salen por ejemplo justiciar 8 movimientos un
  botón desplegables para mostrar los 8 conceptos encontrad[os].»
- **Qué significa**: donde el sistema dice cuántos quedan por justificar, poder
  abrir ahí mismo la lista de los conceptos, sin cambiar de pantalla.
- **Dónde toca**: `views/panel.php`, `views/pendientes.php`.
- **Tamaño**: S
- **Estado**: `recogida`

### 8 · Que el reporte diga quién hizo el cambio manual

- **Lo que dijeron**: «en el reporte fíjate que aparece que el cambio o ajuste
  de categoría fue manual, en este caso debería reflejar quien realizo el
  cambio o modificación.» Con este ejemplo:

  | Fecha | Cuenta | Concepto | Referencia | Categoría | Débito Bs | Tasa BCV |
  |---|---|---|---|---|---|---|
  | 27/08/26 | TESORO | TRFOTG200077727 SERVICIO A | 215023063 | Comisiones bancarias · **manual** | 94.958,40 | 791,32 |
  | 27/08/26 | TESORO | COM.P2P APP-0134 4123867105 | Banco · 365455365 | Comisiones bancarias · **automático** | | |

- **Qué significa**: la columna ya distingue si la categoría la puso una regla
  o una persona, pero no dice **cuál** persona. Ahora que son tres usuarios,
  auditoría quiere el nombre.
- **Dónde toca**: `movimientos` no guarda el autor (tiene `origen` y
  `regla_id`, no `usuario_id`); habría que añadirlo con `columna_si_falta()`,
  escribirlo al clasificar en `views/pendientes.php` y `views/movimiento.php`,
  y mostrarlo en `views/movimientos.php`, `views/reportes.php` y
  `lib/exportar.php`.
- **Ojo**: de lo ya clasificado antes de este cambio no hay autor guardado en
  el movimiento. Parte se puede rescatar de la tabla `bitacora` (84 filas, con
  `usuario_id`); el resto quedaría en blanco.
- **Tamaño**: M
- **Estado**: `recogida`

### 9 · Modo oscuro y modo claro

- **Lo que dijeron**: «aplicar modo oscuro y modo claro a toda la plataforma.»
- **Qué significa**: que cada persona elija cómo ve la aplicación, y que la
  elección se recuerde.
- **Dónde toca**: `views/_layout.php` (los estilos están todos ahí), y dónde se
  guarde la preferencia: `usuarios.pantalla` no sirve, haría falta una columna
  o una cookie. Toca revisar las diecisiete pantallas, no solo el diseño base.
- **Tamaño**: M
- **Estado**: `recogida`

---

## Preguntas que hay que devolverles

1. **(nº 1)** La tasa corregida, ¿vale para **todo ese día** —y entonces cambia
   todos los movimientos de esa fecha— o solo para el movimiento que se está
   mirando?
2. **(nº 1)** Si se corrige una tasa después de haber repartido pagos entre
   facturas con la anterior, ¿se rehace lo repartido o se respeta lo ya
   guardado?
3. **(nº 2)** «Duplicadas ya reportadas»: ¿reportadas por el ERP o por
   auditoría, en una lista que nos van a pasar? ¿O se refieren a las que ya
   están cargadas en CONCIL?
4. **(nº 2)** ¿Qué hace repetida a una factura: mismo proveedor y mismo número,
   o también mismo monto y misma fecha aunque el número cambie?
5. **(nº 6)** ¿Con qué margen salta la alerta? ¿Monto exacto, dentro de cuántos
   días, y solo del mismo proveedor?
6. **(nº 5)** ¿Cómo se están justificando hoy los traspasos entre cuentas
   propias? Hace falta un caso real de los de agosto para reconocerlos.
7. **(nº 8)** De lo clasificado antes de este cambio no siempre se sabe el
   autor. ¿Vale con dejarlo en blanco, o hay que reconstruir lo que se pueda de
   la bitácora?

---

## Decisiones ya tomadas en el recorrido

_(cuando alguien zanja algo en la propia reunión, se anota aquí para no
volver a discutirlo)_

---

## Descartadas y por qué

_(se guardan: dentro de un mes alguien vuelve a proponerlas)_
