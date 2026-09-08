# Plan de carga · el libro de auditoría del 2.º semestre

El departamento pasó `AUDITORIA AMK Bs 2026 2DO. SEMESTRE.xlsx` el 08/09/2026:
tres meses de contabilidad hecha a mano. No es un extracto de banco, es **su
trabajo**, y la idea es no perderlo: cargar lo que trae, y de paso enseñarle al
sistema lo que ellos ya saben.

El archivo llegó a `vipsoft.cloud/assets`, donde **se servía por HTTP a
cualquiera**. Está en `DATA_DIR/muestras/` con permisos 600.

---

## Qué trae

| | Movimientos | Período | Cuentas |
|---|---|---|---|
| Hoja «AMK» | 30.126 | 01/07 – 07/09/2026 | 18 bloques |
| Hoja «PETS/CASHEA» | 2.193 | 01/07 – 07/09/2026 | 7 bloques |
| **Total del archivo** | **32.319** | | **25** |
| **Lo que se carga** (sin CASHEA) | **31.881** | | **22** |

No son hojas por cuenta: cada cuenta es un **bloque de columnas** al lado del
anterior. Cada bloque trae fecha, referencia, descripción del banco, sucursal,
**su clasificación**, la tasa del BCV que aplicaron, el monto en bolívares y en
dólares, y el saldo.

**El 90 % ya está clasificado por ellos** — 27.226 de 30.126.

- Débitos: 19.205, de los cuales **18.132 clasificados** (94 %)
- Créditos: 13.114, de los cuales 11.052 clasificados (84 %)

---

## La decisión que ya está tomada

**El libro entra completo, hasta el 07/09.** El departamento arranca a cargar
extractos **desde el 08/09**. Así no hay solape.

Y hace falta que sea así, porque **el control de duplicados no los reconocería**.
Comprobado contra el extracto real de Bancamiga:

| Fecha | El libro dice | El banco dice |
|---|---|---|
| 03/07 | `ARMORMARKET 2025, C.A` | `NC Transf. Distinto cliente Internet` |
| 04/07 | `GRUPO ARMOR` | `ND Credito Inmediato Dist Cliente` |
| 04/07 | `Comisi<?>n Cr<?>dito Inmediato` | `Comisión Crédito Inmediato` |

En «descripción banco» ellos escriben a veces **a quién le pagaron**, y los
acentos llegaron corruptos. La firma antiduplicados se calcula con el concepto,
así que no casarían: subir julio o agosto otra vez **duplicaría todo en
silencio**. Hay que decírselo al departamento con esas palabras: *julio y agosto
ya están cargados, no se vuelven a subir.*

Esa misma columna es un regalo: donde dice `GRUPO ARMOR` estamos leyendo el
**beneficiario**, que es lo que CONCIL pide a mano en la bandeja.

---

## Los créditos, no

Se cargan y se guardan completos, pero **no se clasifican**: eso es la fase
siguiente y está sin cotizar. El filtro `tipo = 'D'` se queda donde está.

Lo único que sí conviene hacer ahora, porque es gratis: **guardar también la
categoría de los créditos**, aunque ninguna pantalla la enseñe. La columna ya
existe en cada movimiento. El día que se active la fase, no hay que construir
nada: se quita el filtro y aparecen 11.052 ingresos ya clasificados.

---

## Las cuentas

**21 de 25 traen su número de 20 dígitos completo**, así que el banco sale solo
de los primeros cuatro dígitos. Y **16 traen su saldo de apertura al 01/07**,
que resuelve el otro pendiente del arranque. El de Bancamiga (51.069,14)
coincide exacto con el «Saldo Inicial» de su extracto real: el libro es fiel.

Faltan cuatro, y las tiene que completar el departamento:

| Cuenta | Movs | Qué falta |
|---|---|---|
| `BICENTENARIO ARMORMARKET 2025 0175-****-**-*****0541` | 0 | número enmascarado, y sin movimientos |
| `BANCRECER 1856` | 201 | solo cuatro dígitos |
| `BANCO DE VENEZUELA (AMKPETS)` | 238 | número enmascarado |
| `BANESCO 0134***1312248` | 3 | número enmascarado |

---

## Cómo se traducen sus etiquetas

811 etiquetas distintas que en realidad son unas quince: le pegan la fecha al
concepto (`VENTAS 12/08`, `VENTA 04/08`, `VENTAS 06/07/2026`) y escriben la
misma cosa de dos formas (`COMISION BANCARIA` 5.783 veces, `COMISIONES
BANCARIAS` 3.460).

| Lo que ellos escriben | Movs | Categoría de CONCIL |
|---|---|---|
| COMISION BANCARIA · COMISIONES BANCARIAS | 9.243 | Comisiones bancarias, y su subcategoría según el concepto del banco |
| TARIFA POR PDV · PUNTO DE VENTAS | 945 | Punto de venta › Mantenimiento de plataforma POS |
| NOMINA · NOMINA MTTO | 457 | Nómina y personal |
| TRASPASOS CUENTAS PROPIAS y sus ~20 variantes | ~250 | Traspaso entre cuentas propias |
| GASTOS DE VEHICULO | 56 | Combustible y transporte |
| GASTOS ADUANALES | 20 | Aduana y logística |
| IMPUESTO NACIONALES | 10 | Impuestos y tributos |
| COMPRA DIVISAS POR SUBASTA | 5 | Compra de divisas |
| VENTAS y sus ~60 variantes con fecha | ~5.000 | son **créditos**: se guardan sin clasificar |

**Cinco no tienen dónde caer** y hay que crearlas o decidir a dónde van:
`MATERIA PRIMA` (66) · `MATERIALES DE ALMACEN` (9) · `LICENCIAS/PERMISOLOGIA`
(12) · `EXPACION Y MEJORAS` (10, y está mal escrito) · `HHPP` (15).

**Dos que no se tocan:**
- `GASTOS NO IDENTIFICADOS` (148) → **se dejan sin clasificar**. Si ellos no
  supieron qué eran, el sistema tampoco lo sabe, y ponerles una categoría es
  inventar.
- `TRASPASOS VIP PLAY · ELEMENTECH · CPWC · HOTEL VIP · BRANIC · TERRAZAS ·
  OJO · DESARROLLO T.` (~100) → son traspasos a **otras empresas del grupo**,
  no a cuentas propias de Armor Market. Merecen su propia categoría.

---

## El orden de la carga

1. **Respaldo** antes de tocar nada (`respaldar.sh`).
2. **Unidades de negocio**, según lo que se decida (ver abajo).
3. **Crear las 21 cuentas** con nombre, número, banco y saldo de apertura.
4. **Cargar los movimientos** de la hoja «AMK», bloque por bloque, con su
   clasificación puesta y `origen = 'manual'` — así una pasada de reglas **no
   las pisa** y queda claro que la decisión fue de una persona, no del sistema.
5. **Verificar cuenta por cuenta**: que el número de movimientos, la suma de
   débitos y el saldo final cuadren con lo que dice el libro.
6. **Sacar las reglas nuevas** de los pares concepto→categoría que quedaron, y
   proponerlas para que alguien las apruebe. No se activan solas.

Todo en un guion de una sola pasada, que se pueda correr en seco primero.

---

## Decidido el 08/09/2026

**ARMOR PETS va dentro de ARMOR MARKET** — una sola unidad de negocio, la que
ya existe. **CASHEA no entra.**

| | Cuentas | Movimientos |
|---|---|---|
| Armor Market (hoja 1) | 18 | 30.126 |
| Armor Pets (hoja 2) | 4 | 1.755 |
| **Total en la unidad AMK** | **22** | **31.881** |
| ~~CASHEA~~ (fuera) | ~~2~~ | ~~438~~ |

Dos consecuencias que conviene tener presentes:

- **Las cifras de Pets y de Market quedan sumadas** en el panel y en los
  reportes. Se pueden separar filtrando por cuenta, pero el total de arriba es
  el de las dos operaciones juntas. Separarlas después sería volver a cargar.
- El Tesoro y el Bicentenario aparecen **dos veces cada uno**, una cuenta de
  Market y otra de Pets. El sistema lo soporta desde la v2.4.2, pero los
  nombres tienen que dejar claro cuál es cuál: se usan los del libro
  (`TESORO ARMORPETS`, `BICENTENARIO ARMORPETS`).

De las 22 cuentas, **18 traen su número completo** y 4 hay que completarlas. La
`BICENTENARIO ARMORMARKET 2025` no se crea: no tiene número ni movimientos.

---

## Lo que todavía falta decidir

1. **Las cinco categorías que faltan**: `MATERIA PRIMA` (66),
   `LICENCIAS/PERMISOLOGIA` (12), `EXPACION Y MEJORAS` (10),
   `MATERIALES DE ALMACEN` (9), `HHPP` (15).
2. **A dónde van los ~100 traspasos a otras empresas del grupo** (VIP PLAY,
   ELEMENTECH, CPWC, HOTEL VIP, BRANIC, TERRAZAS, OJO, DESARROLLO T.). No son
   cuentas propias de Armor Market, así que «Traspaso entre cuentas propias» no
   les sirve.
3. **Los cuatro números de cuenta** enmascarados o incompletos:
   `BANCRECER 1856` (201 movs), `BANCO DE VENEZUELA AMKPETS` (238),
   `BANESCO PETS` (3), y la de Bicentenario 2025 que no se va a crear.
