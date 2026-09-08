# Recorrido del 04/09/2026 — sugerencias recogidas

Cuaderno de campo del recorrido: aquí se anota **lo que pide la gente**, tal
como lo dice, mientras lo dice. No es un plan todavía. Cuando la lluvia de
ideas termine, de aquí sale el plan de construcción.

Se escribe en el momento y se guarda enseguida: si se cae la conexión, lo
anotado sigue aquí y en el historial de git.

> **Cerrado el 08/09/2026.** Las nueve se construyeron el **06/09/2026**, de la
> versión 1.5 a la 2.1, en el orden del [plan](plan-recorrido.md). Cada ficha
> dice abajo cómo quedó y qué se decidió por el camino. Las preguntas que
> quedaban abiertas están respondidas al final, con su respuesta: las decisiones
> valen más que las preguntas cuando dentro de un año alguien pregunte por qué
> el sistema hace lo que hace.

**Cómo leer cada ficha**

- **Lo que dijeron** — sus palabras, sin traducir. Es lo que vale cuando dentro
  de dos semanas haya dudas de qué se pidió.
- **Qué significa** — la traducción a lo que habría que construir.
- **Dónde toca** — archivos y tablas que se moverían.
- **Tamaño** — S (unas horas), M (un día), L (varios días).
- **Estado** — `recogida` · `entendida` · `en el plan` · `hecha` · `descartada`.

---

## Resumen

| Nº | Sugerencia | Tamaño | Estado | Entregada en |
|----|------------|--------|--------|--------------|
| 1 | Poder corregir a mano la tasa del día de un movimiento | S–M | `hecha` | Fase 3 · v1.7 · `f592255` |
| 2 | Avisar de facturas repetidas que ya se habían registrado | M | `hecha` | Fase 5 · v1.9 · `4e74689` |
| 3 | Reconocer la misma factura pagada desde cuentas distintas | M | `hecha` | Fase 5 · v1.9 · `4e74689` |
| 4 | Añadir facturas nuevas sin salir de la pantalla de justificar | S–M | `hecha` | Fase 5 · v1.9 · `4e74689` |
| 5 | Enlazar el débito de una cuenta con el crédito de la otra en los traspasos | L | `hecha` | Fase 7 · v2.1 · `cc3a8fe` |
| 6 | Alertar cuando a un proveedor se le repite el mismo monto | M | `hecha` | Fase 4 · v1.8 · `ca08d6f` |
| 7 | Desplegar los conceptos detrás de «justificar 8 movimientos» | S | `hecha` | Fase 2 · v1.6 · `a82ce67` |
| 8 | Que el reporte diga **quién** hizo el cambio manual, no solo que fue manual | M | `hecha` | Fase 1 · v1.5 · `ab2e7b5` |
| 9 | Modo oscuro y modo claro en toda la plataforma | M | `hecha` | Fase 6 · v2.0 · `4bd7444` |

Las nueve, el mismo día. El tamaño estimado (siete a nueve días) se quedó largo
porque las fichas 2, 3 y 4 resultaron ser **la misma pantalla** vista desde tres
lados, y se construyeron de una vez.

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
- **Estado**: `hecha` — Fase 3, **v1.7**, commit `f592255` (06/09/2026).
- **Cómo quedó**: se corrige **la tasa del día entero**, no la de un movimiento
  suelto — lo decidió el usuario, y evita tener que explicar por qué dos pagos
  del mismo día usan tasas distintas. Se hace desde Ajustes y desde el propio
  pago. Funciones `corregir_tasa()` y `tasa_del_dia()` en `lib/tasas.php`,
  columna `tasas.usuario_id`, paso de guía `data-guia="tasa-mano"`.
  **Lo ya repartido no se rehace**: `pagos_factura.tasa` congela la del día del
  movimiento, y lo anotado ayer no se mueve porque hoy cambie la tasa.

### 2 · Avisar de facturas repetidas que ya se habían registrado

- **Lo que dijeron**: «revisar facturas duplicadas ya reportadas.»
- **Qué significa**: que el sistema avise cuando la factura que se está
  anotando ya estaba registrada antes, para no pagarla dos veces.
- **El caso real, aclarado el 06/09**: «los duplicados salen cuando las
  personas encargadas de pagar a proveedores puede que le paguen a un mismo
  proveedor la misma factura desde dos bancos distintos». O sea: **no es un
  error de tecleo, es que son varias personas pagando** y cada una mira su
  banco. Las fichas 2, 3 y 6 son tres caras de este mismo caso y hay que
  planificarlas juntas.
- **Dónde toca**: `lib/proveedores.php`, `views/facturas_panel.php`,
  `views/_facturas.php`, tabla `facturas`.
- **Tamaño**: M
- **Estado**: `hecha` — Fase 5, **v1.9**, commit `4e74689` (06/09/2026).
- **Cómo quedó**: `clave_factura()` normaliza el número —quita todo lo que no
  sea letra o número y los ceros de la izquierda de cada tramo, así que «0001»,
  «1» y «F-0001» son la misma factura, y «1000» sigue siendo mil—. Se guarda en
  `facturas.numero_clave` con índice suelto; **la clave única no se tocó**.
- **(nota del recogido)** Faltaba saber qué hace «la misma factura» cuando
  el número se teclea distinto (ver preguntas).

### 3 · Reconocer la misma factura pagada desde cuentas distintas

- **Lo que dijeron**: «identificar facturas también entre cuentas para evitar
  pagos duplicados.»
- **Qué significa**: la misma factura se paga desde el Tesoro y desde Banesco,
  por dos personas distintas, sin que ninguna se entere. Piden que el sistema
  reconozca la factura mire quien mire y desde el banco que sea.
- **Comprobado el 06/09: hoy el sistema no solo lo permite, empuja al error.**
  Al justificar un pago, `lista_facturas()` (en `views/_facturas.php`) enseña
  **solo las facturas abiertas**, más las que ese mismo pago ya cubre. En
  cuanto la primera persona la paga desde su banco, la factura **desaparece de
  la pantalla** de la segunda. La segunda no la ve, da por hecho que no está
  anotada, la anota otra vez y la paga. La factura sí es de la unidad entera y
  no de la cuenta —ese lado está bien—; el problema es que quedan escondidas.
- **La única red que hay hoy es frágil**: la clave única
  `uq_factura_sede (sede_id, proveedor_id, numero)` avisa «ya hay una factura
  número X de ese proveedor», pero solo si la segunda persona teclea el número
  **exactamente igual**. Para la base, «0001», «1» y «F-0001» son tres facturas
  distintas.
- **Dónde toca**: `views/_facturas.php` (que las cubiertas se sigan viendo,
  marcadas y con quién las pagó), `lib/proveedores.php` (`saldo_factura()` ya
  contempla el estado `excedida`, hoy no se avisa de él), `lib/consultas.php`.
- **Tamaño**: M
- **Estado**: `hecha` — Fase 5, **v1.9**, commit `4e74689` (06/09/2026).
- **Cómo quedó**: las facturas **ya cubiertas se siguen viendo** al justificar,
  en un desplegable «Ya pagadas: N», con `quien_pago_factura()` diciendo «La
  pagaron el 27/08/2026 desde TESORO, lo anotó Maestro». Esconderlas era lo que
  provocaba el pago repetido. **Se avisa y además se bloquea el exceso**:
  `repartir_pago()` no deja repartir más de lo que salió del banco, y ahora el
  mensaje dice quién la pagó y desde dónde.

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
- **Estado**: `hecha` — Fase 5, **v1.9**, commit `4e74689` (06/09/2026).
- **Cómo quedó**: un pago puede anotar **varias facturas de un tirón** (campos
  `nf_*[]` y el botón «Anotar otra factura» en `assets/app.js`), sin salir de la
  pantalla. Paso de guía `data-guia="factura-nueva"`, cuya nota es la que
  explica también las ya pagadas. El ancla `data-guia="pagadas"` está puesta en
  `views/_facturas.php` pero **sin paso propio, a propósito**: ese bloque solo
  existe cuando hay facturas cubiertas y llega plegado, así que un paso suyo se
  saltaría casi siempre.

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
- **Estado**: `hecha` — Fase 7, **v2.1**, commit `cc3a8fe` (06/09/2026).
- **Cómo quedó**: `movimientos.traspaso_id` ata las dos caras, cada fila
  apuntando a la otra. `enlazar_traspasos()` **solo ata lo inequívoco** —mismo
  monto, cuentas distintas, ±3 días (`DIAS_TRASPASO`) y un solo candidato de
  cada lado—; lo dudoso se pregunta en el detalle con `traspasos_posibles()`, y
  siempre se puede soltar. Corre al terminar cada carga y con un botón en
  Reglas. Paso de guía `data-guia="traspasos"`.

### 6 · Alertar cuando a un proveedor se le repite el mismo monto

- **Lo que dijeron**: «alerta cuando un proveedor repite el mismo monto de pago
  o patrones similares asociados a pagos duplicados.»
- **Qué significa**: un aviso automático cuando dos pagos al mismo proveedor se
  parecen demasiado —mismo monto, fechas cercanas, bancos distintos— porque
  suele ser el mismo pago hecho dos veces por dos personas.
- **Es la red de seguridad de la nº 3**: la nº 3 solo protege si la factura
  está anotada. Cuando el pago se justifica sin factura —que es la mayoría
  hoy—, lo único que queda es notar que a ese proveedor se le fue el mismo
  monto dos veces. Por eso conviene que el aviso mire el **proveedor y el
  monto**, no la factura.
- **Dónde toca**: `lib/consultas.php`, `views/proveedor.php`,
  `views/panel.php`.
- **Tamaño**: M
- **Estado**: `hecha` — Fase 4, **v1.8**, commit `ca08d6f` (06/09/2026).
- **Cómo quedó**: el margen lo eligió el usuario — **mismo proveedor, monto
  exacto, 30 días** (`DIAS_PAGO_REPETIDO`) y mirando **todas las cuentas**, que
  es lo que hace falta cuando cada persona paga desde un banco distinto.
  `pagos_repetidos()` y `montos_repetidos()` en `lib/proveedores.php`; el aviso
  se dibuja una sola vez en `aviso_pagos_repetidos()` y por ahí pasan la
  bandeja, el detalle y el fragmento AJAX. Paso de guía `data-guia="repetidos"`.
- **(nota del recogido)** Faltaba saber qué margen los hace sospechosos (ver
  preguntas).

### 7 · Desplegar los conceptos detrás de «justificar 8 movimientos»

- **Lo que dijeron**: «cuando salen por ejemplo justiciar 8 movimientos un
  botón desplegables para mostrar los 8 conceptos encontrad[os].»
- **Qué significa**: donde el sistema dice cuántos quedan por justificar, poder
  abrir ahí mismo la lista de los conceptos, sin cambiar de pantalla.
- **Dónde toca**: `views/panel.php`, `views/pendientes.php`.
- **Tamaño**: S
- **Estado**: `hecha` — Fase 2, **v1.6**, commit `a82ce67` (06/09/2026).
- **Cómo quedó**: cada tarjeta de grupo lleva un desplegable «Ver los 8
  conceptos» con fecha, cuenta, concepto del banco, referencia y monto. Todo en
  una sola consulta con `ROW_NUMBER()` de MariaDB y tope de 30 por grupo
  (`TOPE_CONCEPTOS`). Se puso en la bandeja y no en el panel porque es ahí donde
  se decide clasificar ocho de un golpe. Paso de guía `data-guia="conceptos"`.

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
- **Estado**: `hecha` — Fase 1, **v1.5**, commits `4c37cee` y `ab2e7b5` (06/09/2026).
- **Cómo quedó**: `movimientos.usuario_id` guarda quién dejó puesta la
  clasificación, y el nombre sale en la lista, en el detalle y en la columna
  «Quién lo hizo» del archivo exportado. Dos reglas de la casa que quedaron
  dentro: **una pasada automática de reglas borra el autor** —atribuirle a
  alguien lo que decidió una regla sería peor que no decir nada— y **quitar la
  clasificación también**. Ayudante nuevo: `usuario_id_actual()`.
  Fue la primera de las siete **porque el autor solo se guarda hacia adelante**:
  lo clasificado sin la columna puesta habría quedado sin nombre para siempre.

### 9 · Modo oscuro y modo claro

- **Lo que dijeron**: «aplicar modo oscuro y modo claro a toda la plataforma.»
- **Qué significa**: que cada persona elija cómo ve la aplicación, y que la
  elección se recuerde.
- **Dónde toca**: `views/_layout.php` (los estilos están todos ahí), y dónde se
  guarde la preferencia: `usuarios.pantalla` no sirve, haría falta una columna
  o una cookie. Toca revisar las diecisiete pantallas, no solo el diseño base.
- **Tamaño**: M
- **Estado**: `hecha` — Fase 6, **v2.0**, commit `4bd7444` (06/09/2026).
- **Cómo quedó**: tres opciones —Automático, Claro, Oscuro— al final del menú;
  «automático» sigue a la computadora (`prefers-color-scheme`). La elección se
  guarda en `usuarios.tema` **y** en la galleta `CONCILTEMA`, porque la pantalla
  de acceso se dibuja antes de saber quién entra. `tema()` y `fijar_tema()` en
  `lib/usuarios.php`; el POST se atiende en `index.php` antes de la vista. Paso
  de guía `data-guia="tema"`. (Después, en la v2.5, se le sumó el selector de
  tamaño de letra, que no salió del recorrido sino del uso diario.)

---

## Las preguntas, con su respuesta

Estaban todas abiertas cuando se cerró el recorrido. Se respondieron
construyendo, entre el 06 y el 08/09. Se guardan con la respuesta al lado:
dentro de un año lo que hará falta no es la pregunta, es por qué el sistema
hace lo que hace.

1. **(nº 1)** La tasa corregida, ¿vale para todo ese día o solo para el
   movimiento que se está mirando?
   → **Para el día entero.** Lo decidió el usuario. Dos pagos del mismo día con
   tasas distintas no habría cómo explicárselos a nadie.
2. **(nº 1)** Si se corrige una tasa después de haber repartido pagos con la
   anterior, ¿se rehace lo repartido?
   → **No.** `pagos_factura.tasa` congela la del día del movimiento: lo que se
   anotó ayer no se mueve porque hoy cambie la tasa.
3. **(nº 2)** ¿«Duplicadas ya reportadas» son las de aquí o las del ERP?
   → **Respondida el 06/09**: son las de aquí. El caso es que dos personas
   paguen la misma factura del mismo proveedor desde dos bancos distintos.
4. **(nº 2 y 3)** ¿Qué hace repetida a una factura cuando el número no se
   teclea igual?
   → **El número normalizado**, con `clave_factura()`: fuera todo lo que no sea
   letra o número, y fuera los ceros de la izquierda de cada tramo. «0001», «1»
   y «F-0001» son la misma; «1000» sigue siendo mil. No se mira ni el monto ni
   la fecha: bastó con el número.
5. **(nº 3)** Cuando alguien intente pagar una factura ya cubierta desde otro
   banco, ¿se bloquea o solo se avisa?
   → **Las dos cosas, cada una donde toca.** Se **avisa** enseñando las ya
   pagadas con quién y desde qué banco —esconderlas era lo que provocaba el
   pago repetido— y se **bloquea** repartir más de lo que salió del banco, que
   es lo único que sí es un error de aritmética y no un criterio.
6. **(nº 6)** ¿Con qué margen salta la alerta de monto repetido?
   → **Mismo proveedor, monto exacto, 30 días, todas las cuentas.** Lo eligió el
   usuario. Mirar todas las cuentas es el punto: cada persona paga desde un
   banco distinto.
7. **(nº 5)** ¿Cómo se justifican hoy los traspasos entre cuentas propias?
   → Con los extractos reales de agosto se vio el patrón y se automatizó lo
   inequívoco: mismo monto, cuentas distintas, ±3 días y un solo candidato de
   cada lado. Lo dudoso se pregunta; nada se ata a la fuerza.
8. **(nº 8)** De lo clasificado antes del cambio no se sabe el autor. ¿Se deja
   en blanco o se reconstruye de la bitácora?
   → **En blanco, y no había nada que reconstruir**: lo que estaba clasificado
   entonces eran las pruebas de quien lo construyó, y se borró antes del
   arranque real.

---

## Decisiones ya tomadas en el recorrido

**06/09 · Qué es un pago duplicado aquí.** No es un error de tecleo ni un
archivo cargado dos veces: **son varias personas las que pagan a proveedores**,
cada una desde un banco distinto, y puede que dos paguen la misma factura del
mismo proveedor sin cruzarse. Todo lo que se construya contra los duplicados
—fichas 2, 3 y 6— tiene que funcionar **entre cuentas y entre personas**, no
dentro de una sola cuenta.

---

## Descartadas y por qué

_(se guardan: dentro de un mes alguien vuelve a proponerlas)_

Ninguna. Las nueve se construyeron.

---

## Lo que quedó abierto, y no es de este cuaderno

**Los créditos siguen sin clasificarse.** La ficha 5 ata las dos caras de un
traspaso y ambas se ven, pero casi todas las consultas filtran `tipo = 'D'`:
los créditos se guardan completos y no se explican. Es una decisión de producto
que no se levantó del todo en el recorrido, y activarla es quitar ese filtro,
no volver a importar nada.

**Lo que vino después no salió de aquí.** El selector de tamaño de letra (v2.5),
el desglose de comisiones que pidió contabilidad (v2.7) y el rastro con la
presencia en vivo (v2.6) se pidieron por separado, cada uno por su cuenta. Este
cuaderno cubre el recorrido del 04/09 y nada más: cuando haya otro recorrido,
va en su propio cuaderno.
