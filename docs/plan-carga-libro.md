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
| **Lo que se carga** | **32.319** | | **24** |

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

## Consultado con el departamento y cerrado (08/09/2026)

Las dos preguntas están respondidas por auditoría. **Todo entra en la unidad
que ya existe, ARMOR MARKET**: 24 cuentas y 32.319 movimientos.

1. ~~¿ARMOR PETS tiene su propio RIF?~~ **RESPONDIDA el 08/09/2026: usa el
   mismo RIF que Armor Market. Es una sección dentro de la tienda**, no una
   empresa aparte. Va dentro, en la unidad que ya existe.

   La pregunta era esa y no «de quién es», porque lo que decide en CONCIL es de
   quién es la deuda: `facturas.sede_id` va en la clave única porque una factura
   se le debe a un contribuyente concreto. Mismo RIF, misma unidad, y no hay
   nada que separar después.
2. ~~¿A nombre de quién están las dos cuentas de CASHEA?~~ **RESPONDIDA el
   08/09/2026: son de Armor Market y sí entran.** Auditoría lo explicó así: la
   **cuota inicial** que paga el cliente cae en las cuentas de siempre; **las
   cuotas que el cliente le paga a CASHEA** caen en estas dos —Banesco y BNC,
   «dos por ahora»—. Y lo pidieron con todas sus letras: *«esto también hay que
   meterlo, para nosotros verificar allí todo lo que entra por banco de
   CASHEA»*. No es un tercero: es **un canal de cobro** de la empresa.
   Los números ya lo decían:

   ```
   BNC     Armor Market   0191-0166-54-2100058875
   BNC     CASHEA         0191-0166-54-2100060271   ← mismo banco, misma oficina
   Banesco Armor Market   0134-0363-51-3631307492
   Banesco CASHEA         0134-0363-59-3631311858   ← mismo banco, misma oficina
   ```

   Y sus movimientos vienen marcados con **las sucursales de Armor Market**
   —`AMKCH`, `AMKB`, `AMKLG`—, las mismas de la hoja 1.

   **Ojo con lo que van a ver.** De los 436 movimientos de CASHEA, **274 son
   ingresos (63 %)**: entran Bs 7.611.474,72 y salen Bs 2.603.819,88. Lo que
   ellos quieren verificar —«todo lo que entra»— es justamente **la mitad que
   CONCIL no clasifica todavía**. Las cuentas se cargan completas y el dinero
   queda registrado, pero mientras los créditos no se activen, esas dos cuentas
   se van a ver casi vacías en el panel. Hay que decírselo antes, no después.

Mismo razonamiento para Pets: `TESORO 0163-0903-65-…` (Market) y
`0163-0903-63-…` (Pets) comparten banco y oficina, igual que las dos del
Bicentenario.

---

## Lo decidido

**Armor Pets y CASHEA van dentro de ARMOR MARKET**, como cuentas más de la
misma unidad. Ni una ni otra es empresa aparte: Pets es una sección de la
tienda con el mismo RIF, y CASHEA es un canal de cobro.

| | Cuentas | Movimientos |
|---|---|---|
| Armor Market (hoja 1) | 18 | 30.126 |
| Armor Pets (hoja 2) | 4 | 1.755 |
| CASHEA (canal de cobro de AMK) | 2 | 438 |
| **Total en la unidad AMK** | **24** | **32.319** |

Una consecuencia que conviene tener presente:

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
