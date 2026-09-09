<div align="center">

# CONCIL

**by VIP Soft** · Premium Systems Solutions

*Conciliación bancaria · ¿en qué se fue el dinero?*

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![MySQL 5.7+](https://img.shields.io/badge/MySQL-5.7%2B-00758f)
![Sin dependencias](https://img.shields.io/badge/dependencias-ninguna-2e7d32)
![Hosting compartido](https://img.shields.io/badge/destino-cPanel%20compartido-8c6520)
![Interfaz en español](https://img.shields.io/badge/interfaz-espa%C3%B1ol-d4a857)

</div>

---

Sistema web para conciliar y justificar los movimientos bancarios de varias
cuentas. Toma los extractos que entrega cada banco, reconoce solo los pagos que
se repiten todos los meses y deja para revisión humana únicamente aquello que el
banco no explica.

---

## Índice

**Qué es**
[Qué problema resuelve](#qué-problema-resuelve) ·
[Cómo funciona](#cómo-funciona) ·
[Formatos de extracto](#formatos-de-extracto-soportados) ·
[Unidades de negocio](#unidades-de-negocio) ·
[Proveedores y facturas](#proveedores-y-facturas) ·
[Tasa del dólar](#tasa-del-dólar) ·
[Hora](#hora)

**Ponerlo a andar**
[Requisitos](#requisitos) ·
[Instalación](#instalación) ·
[Respaldo y restauración](#respaldo-y-restauración)

**Por dentro**
[Estructura del proyecto](#estructura-del-proyecto) ·
[Modelo de datos](#modelo-de-datos) ·
[Motor de reglas](#motor-de-reglas) ·
[Control de duplicados](#control-de-duplicados) ·
[Repetidos por fecha corrida](#repetidos-por-fecha-corrida) ·
[Saldos](#saldos) ·
[Registro de fallos](#registro-de-fallos) ·
[Rendimiento](#rendimiento)

**Quién lo usa**
[Usuarios y rastro](#usuarios-y-rastro) ·
[Seguridad](#seguridad) ·
[Interfaz y accesibilidad](#interfaz-y-accesibilidad) ·
[Visita guiada](#visita-guiada) ·
[Versión y pantalla de Mejoras](#versión-y-pantalla-de-mejoras)

**Lo que hay que saber antes de tocarlo**
[Decisiones de diseño](#decisiones-de-diseño) ·
[Limitaciones conocidas](#limitaciones-conocidas) ·
[Documentación](#documentación)

---

## Qué problema resuelve

Un extracto bancario dice cuánto salió y cuándo, pero no **para qué**. Con varias
cuentas activas se acumulan miles de líneas al mes y la explicación de cada
salida termina escrita a mano en una hoja de cálculo, sin forma de consultarla
después.

CONCIL convierte esos archivos en una base consultable:

- Reconoce el banco y la cuenta de cada archivo sin intervención.
- Clasifica automáticamente lo que llega siempre con el mismo texto: comisiones,
  impuestos, servicios, traspasos entre cuentas propias.
- Agrupa lo que queda por concepto, de modo que una decisión resuelve decenas de
  movimientos a la vez.
- Aprende de cada decisión: lo que se explica una vez llega ya clasificado en la
  siguiente carga.
- Exporta a Excel cualquier corte: por categoría, beneficiario, banco, mes o
  rango de fechas.

En la instalación que originó el sistema hay **22 cuentas** y el semestre
completo cargado —32.263 movimientos de julio en adelante—. Las reglas
clasifican por sí solas **nueve de cada diez** débitos; lo que queda es lo que
solo sabe quien hizo el gasto.

### En números

| | |
|---|:--|
| **10 bancos** | reconocidos por la estructura del archivo, sin configurar nada |
| **69 conceptos** | de comisión catalogados, de 11 bancos, cubiertos con 23 reglas |
| **0 dependencias** | ni Composer ni Node; el lector y el escritor de XLSX son propios |
| **29–40 ms** | lo que tarda una pantalla cualquiera |
| **500.000** | movimientos sintéticos con los que se probó el rendimiento |
| **35 pasos** | de visita guiada, que navega sola por las diez secciones |

## Cómo funciona

```
Extracto del banco (.xlsx / .csv)
        │
        ▼
  Detección de formato ──────► banco y cuenta, columnas por nombre
        │
        ▼
  Normalización ─────────────► fecha, referencia, concepto, débito/crédito
        │
        ▼
  Control de duplicados ─────► firma + ocurrencia (recarga segura)
        │
        ▼
  Motor de reglas ───────────► categoría + beneficiario automáticos
        │
        ├─ reconocido ────────► conciliado
        └─ no reconocido ─────► bandeja «Por justificar»
                                      │
                                      ▼
                            decisión humana (por grupo)
                                      │
                                      ├─► se guarda como regla nueva
                                      └─► el próximo extracto ya llega limpio
```

## Formatos de extracto soportados

El formato se reconoce **por la estructura del archivo**, nunca por su nombre ni
por el de la hoja: contabilidad los renombra y no prueban nada. La huella
(`lib/huella.php`) combina qué rótulos trae el encabezado y en qué columna cae
cada uno, en qué fila está, el ancho de los datos y la **forma de cada columna**
leída de los propios datos: `F` fecha · `N` número · `T` texto · `S` signo `+/-`
· `V` vacía.

Sobre 16 extractos reales dio 14 huellas únicas, y las dos repetidas eran de
verdad el mismo formato — entre ellas el Banco del Tesoro entregado en XLSX y en
HTML, reconocidos como uno solo.

| Banco | Encabezado | Particularidad |
|---|---|---|
| Bancamiga | `Nro · Fecha · Referencia · Concepto · Débito · Crédito · Saldo` | Trae número de cuenta y saldo inicial en la cabecera |
| Banco del Tesoro | `Nro · Fecha · Referencia · Código · Concepto · Débito · Crédito` | También se exporta como tabla HTML con extensión `.xls` |
| Bicentenario | Igual que Tesoro más `Saldo` | Pie con totales; saldo inicial en la cabecera |
| Banco de Venezuela | `fecha · referencia · concepto · saldo · monto · tipoMovimiento · rif · numeroCuenta` | Primera fila es `SALDO INICIAL`, no un movimiento |
| Banesco | `(sin rótulo) · Referencia · Descripción · Monto · Balance` | **La columna de fecha no tiene rótulo**: se deduce de los datos |
| Exterior | *ninguno* | Posicional, con el signo `+/-` en columna aparte |
| BNC | Encabezado en la fila 7 y con huecos | Trae número de cuenta y pie de totales |
| Banplus | `Fecha · Referencia · Cod_Transacc · Transaccion · Cod_Motivo · Motivo · Débito · Crédito · Saldo` | No entrega las filas en orden de saldo |
| Bancrecer | `FECHA · REFERENCIA · DESCRIPCION · DEBITOS · CREDITOS · SALDO` | Montos en formato `1.234,56` |
| Provincial | `Fecha · Referencia · Descripción · Importe · Saldo` | Orden cronológico invertido |

### Catálogo que aprende solo

Cuatro bancos —Bancrecer, Banesco, Banplus y Provincial— no dicen por dentro
quiénes son: no traen número de cuenta ni título, y Provincial no menciona
ningún banco en todo el archivo. La primera vez se elige la cuenta a mano y la
huella queda guardada en la tabla `formatos`; a partir de ahí se reconoce sola.

### La ficha de la cuenta

Conviene que cada cuenta tenga **número, titular y RIF**, pero no se exigen: una
cuenta sin ellos carga igual y solo se advierte, en la pantalla de Cuentas y en
el resultado de cada importación.

El motivo de pedirlos: el nombre no basta para distinguir una cuenta. Sale del
título que el banco imprime dentro del archivo, y ese título cambia de un mes a
otro — así fue como una misma cuenta del Banco de Venezuela acabó registrada dos
veces, con la protección contra duplicados inservible entre ambas porque la
firma incluye el id de la cuenta.

Lo que se pierde sin ellos es concreto: **el aviso de banco equivocado compara
el número del archivo contra el de la cuenta**, así que sin número esa
protección cae a comparar nombres de banco, que es más débil y no funciona en
las cuentas creadas sin banco reconocido.

Los tres datos se piden **una vez por cuenta**, no en cada carga, y aparecen ya
escritos cuando el extracto los trae: el número lo declaran Bancamiga,
Venezuela y Exterior; el RIF, Venezuela; el titular, BNC.

Si dos cuentas resultan ser la misma, **Cuentas → Unir dos cuentas en una**
traslada los movimientos y recalcula sus firmas con el id de destino, que si no
dejaría rota la detección de duplicados justo después de unirlas.

### Comprobaciones antes de guardar

Tres evidencias, todas sacadas del contenido, en orden de fuerza:

1. **El número de cuenta impreso en el archivo.** Sus cuatro primeros dígitos
   son el código Sudeban del banco (`0172` Bancamiga, `0102` Banco de Venezuela,
   `0115` Exterior). Es lo único que **bloquea** la importación si contradice la
   cuenta elegida, así que la tabla de `lib/huella.php` está contrastada contra
   dos listados comunitarios independientes que coinciden entre sí.

   No hay validación por dígito verificador: la cuenta venezolana tiene la misma
   forma que el CCC español —4 de banco, 4 de sucursal, 2 de control, 10 de
   cuenta— pero el algoritmo módulo 11 del CCC **no valida** ninguna de las
   cuentas reales de las muestras, así que aplicarlo rechazaría cuentas
   legítimas. En su lugar, el número solo se acepta si está en la cabecera del
   extracto o si una celda de veinte dígitos se repite en todas las filas.
2. **Los totales que el archivo declara en su pie.** Se comparan con los
   nuestros —que se calculan fila por fila— y **si difieren se avisa, nunca se
   rechaza**. Fue al revés hasta el 08/09/2026 y hubo que cambiarlo: hay
   extractos que llegan con su propio total mal sumado, y mientras ese pie
   mandaba, un archivo bueno se perdía entero. `comparar_totales()` devuelve lo
   nuestro y el aviso; las sumas quedan en `importaciones.suma_debito` y
   `suma_credito`, y la diferencia en `descuadre`. Cuando el archivo está bien,
   cuadran al céntimo.
3. **La cadena del saldo** (`saldo anterior − débito + crédito`). Con el mapeo
   correcto encadena el 100 % de las filas; con las columnas cruzadas, ninguna.
   Solo sirve como confirmación: Banplus no viene en orden de saldo y Provincial
   viene al revés, así que un resultado bajo nunca rechaza el archivo.

### Subir el extracto es cargarlo

No hay botón intermedio: al soltar el archivo se analiza, se decide a qué cuenta
va y, si no queda nada que preguntar, se importa **en la misma petición**. La
pantalla de confirmación aparece solo cuando falta algo — a qué cuenta va, de
qué banco es, o un dato de la ficha que ni la cuenta tiene ni el archivo trae.

Se pueden soltar los archivos de **todos los bancos a la vez**. Y una carga
entera se puede deshacer: `deshacer_importacion()` borra sus movimientos y su
línea de historial, avisando antes de cuántos repartos a facturas se van a
perder, porque eso sí es trabajo de una persona.

### Otros detalles que el lector resuelve

- **El tipo de archivo se decide olfateando el contenido**, no la extensión: un
  ZIP empieza por `PK`, una tabla HTML trae `<table>`. Por eso el `.xls` del
  Tesoro, que en realidad es HTML, se lee sin problemas.
- **Fechas** en serial de Excel (base 1899-12-30), `d/m/Y` o `d/m/y`.
- **Montos** en notación científica (`1.436003383E9`), con coma o punto decimal,
  entre paréntesis o con signo.
- **Saldo de arranque** tomado de la cabecera cuando el banco lo imprime.
- **Texto corrupto**: los archivos que llegan con acentos rotos se comparan con
  una clave normalizada sin acentos ni puntuación.

## Unidades de negocio

Un mismo CONCIL sirve a varias empresas o tiendas del consorcio. Cada **unidad
de negocio** («sede») lleva sus propias cuentas bancarias y sus propios
movimientos; el selector de la esquina superior izquierda decide con cuál se
está trabajando y todas las pantallas responden a esa elección.

El aislamiento es por cuenta: cada cuenta pertenece a una sede y los movimientos
heredan la suya de la cuenta, así que no hace falta repetir el dato en cada
movimiento. `filtro_sede()` (`lib/sedes.php`) devuelve el trozo de SQL que
restringe cualquier consulta, y `where_filtros()` lo aplica de forma automática
a todo lo que pasa por los filtros comunes.

**Las categorías y las reglas son comunes a todas las unidades.** Es una
decisión de producto: así una regla aprendida en una tienda clasifica sola en
las demás, y los informes de distintas unidades hablan el mismo idioma y se
pueden comparar. Solo se separan las cuentas y los movimientos.

Cada persona entra con **su propio PIN** y ve todas las unidades, cambiando
entre ellas con el selector; no hay permisos por sede. Quién hizo qué queda
firmado con su nombre — ver [Usuarios y rastro](#usuarios-y-rastro).

**En cada inicio de sesión se elige unidad antes de ver nada.** Es una pantalla
completa, no un aviso que se pueda saltar: mientras no se elija, el menú está
oculto y cualquier ruta rebota a ella. Ahí mismo se crea una unidad nueva. La
elección no se arrastra de un día para otro a propósito: quien concilia lleva
varias y empezar en la de ayer invita a equivocarse.

El aislamiento se aplica también a lo que llega por formulario: la cuenta
elegida al cargar un extracto y el movimiento al que se anota un proveedor se
comprueban contra la unidad activa antes de tocarlos, porque sus identificadores
viajan en el POST y podrían venir manipulados.

Al actualizar una instalación que ya tenía datos, la migración crea la sede
`ARMOR MARKET` y le adjudica todas las cuentas existentes, de modo que nada
queda huérfano. El nombre de una cuenta solo tiene que ser único **dentro de su
sede**, porque dos unidades pueden tener cada una su cuenta «BANESCO».

## Proveedores y facturas

Al justificar un pago se anota **a quién se le pagó** y **contra qué facturas
fue**. El proveedor se guarda en el propio movimiento, porque todo pago tiene
destinatario haya factura o no; las facturas van en su tabla y se enlazan al
pago a través de `pagos_factura`, que guarda el monto aplicado en cada enlace.

Esa separación es lo que permite los dos casos reales:

- **Un pago que cubre varias facturas.** Se marca cada una y se escribe cuánto
  de ese pago va a cada cual. El pie va diciendo cuánto queda sin repartir.
- **Una factura que se cubre con varios pagos.** Cada pago aporta su parte y la
  factura muestra lo que lleva y lo que le falta, con la fecha y el monto de los
  pagos anteriores a la vista.

Todo ocurre en la misma pantalla, sin salir de donde se justifica: la bandeja
**Por justificar** (modo «uno por uno») y el detalle de un movimiento comparten
el mismo panel, `views/_facturas.php`. Al elegir proveedor, sus facturas sin
cubrir se piden al servidor sin recargar la página (`?r=facturas_panel`); sin
JavaScript la pantalla sigue funcionando, solo que la lista se refresca al
guardar.

### Cuánto le falta a una factura

El saldo **se calcula, nunca se guarda**: es el monto menos lo retenido menos lo
aplicado. Un estado almacenado se desincroniza en cuanto alguien deshace un
pago; una suma no.

**Retener no es dejar de pagar.** En Venezuela el comprador retiene parte del
IVA y del ISLR y se los entrega al SENIAT en nombre del proveedor, así que del
banco sale menos de lo que dice la factura. La factura guarda su retención de
IVA, la de ISLR y la nota de crédito, y se da por cubierta cuando
`aplicado + retenido >= monto`. Sin eso, toda factura con retención se quedaría
eternamente «a medias».

### Facturas en dólares

Cada factura lleva su moneda. El saldo vive en la moneda de la factura y el
pago se reparte **en bolívares**, que es lo que de verdad salió del banco; si la
factura está en dólares se convierte con la **tasa del BCV del día del
movimiento** —no la de hoy— y esa tasa se guarda congelada en el reparto. Cuando
mañana el BCV cambie, lo anotado ayer no se mueve.

### Dos números por factura

En Venezuela una factura lleva **dos** números: el suyo y el «número de control»
pre-impreso que exige la Providencia Administrativa 00071 del SENIAT, que nunca
se reinicia durante la vida del contribuyente. Se piden los dos al anotarla,
junto con la fecha y el monto: sin monto no se puede saber cuánto queda por
cubrir, que es la pregunta que hace auditoría.

### Qué se comparte y qué no

Los proveedores son **comunes a todas las unidades de negocio**, igual que las
categorías, de modo que se puede preguntar cuánto le pagó el grupo entero a un
mismo proveedor. Las **facturas no**: la deuda la tiene una empresa concreta, así
que `facturas.sede_id` entra en la clave única y dos empresas del grupo pueden
recibir la factura número 1 del mismo proveedor sin chocar.

### Cargar el listado desde un archivo

En **Proveedores** se sube el listado que exporta el sistema de contabilidad —el
mismo Excel, o cualquiera con esa forma— y entran todos de una vez. Funciona
como la carga de extractos: primero se muestra qué haría con cada fila y solo al
confirmar se guarda algo.

El encabezado se localiza **por estructura**, exigiendo el nombre y el RIF en la
misma fila; si el archivo no trae rótulos reconocibles, se marca a mano qué es
cada columna. Se reconocen `CODIGO`, `PROVEEDOR(ES)`, `RIF`, `NIT` y `TELEFONO`.

Para no duplicar, se compara **primero por RIF y después por nombre
normalizado**, que es el orden que recomienda la práctica de auditoría: los
proveedores repetidos son la causa más frecuente de pagos duplicados. Y nunca se
funden dos fichas en silencio:

- El RIF llega sucio y a veces no es un RIF (un teléfono, una palabra). Solo se
  usa como clave si encaja en `^[JGVEP]\d{8,10}$`; si no, se guarda el texto tal
  cual y la ficha se marca **sin verificar**.
- Si en el archivo dos nombres distintos comparten un RIF, **entran los dos** y
  se avisa: uno de los dos está mal tecleado y perder un proveedor real sería
  peor. El segundo entra sin usar ese RIF como clave.
- Si el RIF apunta a una ficha existente y el nombre a otra, la fila no entra y
  se muestra el choque para que lo resuelva una persona.

A quien ya está en el listado no se le pisa ningún dato: solo se le completan
los campos que tenía vacíos. Volver a cargar el mismo archivo no crea nada.

Desde el listado se pueden **unir dos fichas** del mismo proveedor: los pagos y
las facturas pasan a la que se queda, las facturas repetidas se juntan en una, y
lo que a la de destino le faltara se completa con los datos de la otra.

El campo `beneficiario` se conserva: lo rellenan las reglas con etiquetas
gruesas («Banco», «SENIAT») y responde a otra pregunta.

## Tasa del dólar

Junto a cada operación se muestra la **tasa oficial del BCV del día en que
ocurrió**. Administración necesita leer un pago de julio con la tasa de julio,
no con la de hoy, así que la tasa se guarda por fecha y no se recalcula nunca.

La fecha que manda es la del movimiento —la que trae el extracto del banco—, no
la de la carga del archivo. Un extracto de julio subido en septiembre sigue
leyéndose con las tasas de julio.

Las tasas se traen de `https://bcv.today` (JSON, sin clave, tomado de
`bcv.org.ve`). La primera vez se descarga el histórico completo, cinco años en
una sola llamada; después se busca la del día, como mucho una vez al día, al
entrar al panel. En **Ajustes → Tasa del dólar** se ve qué hay guardado y se
pueden pedir las que falten.

Se guarda **una fila por día de calendario**, no por día bancario: el sábado y
el domingo llevan la tasa del viernes, que es la que rige. La fuente ya entrega
los días completos, así que no quedan huecos que rellenar.

Si la consulta falla, la aplicación sigue igual: donde no hay tasa se muestra un
guion. Es un dato de apoyo y nunca puede detener una carga ni una pantalla.

## Hora

Todo el sistema funciona en **hora de Venezuela** (`America/Caracas`): lo que se
ve en pantalla, lo que se guarda en la base y las marcas del registro de fallos.

El servidor está en otra zona horaria, así que se fija en dos sitios que tienen
que ir de acuerdo: `date_default_timezone_set()` al cargar la configuración, y
`SET time_zone` en cada conexión con la base. Sin lo segundo, `NOW()` y `date()`
daban horas distintas y cualquier resta entre ambas salía mal — así apareció un
«visto hace 420 minutos» para alguien que acababa de entrar.

Venezuela no adelanta la hora en verano, de modo que el desfase es siempre
`-04:00` y la conexión puede fijarse con ese número en vez de con reglas de
horario de verano.

## Requisitos

- PHP **8.2** o superior, con `pdo_mysql`, `zip`, `xml`, `mbstring`
- MySQL 5.7+ / MariaDB 10.3+
- Apache con `mod_rewrite` no es necesario; sí `AllowOverride` para el `.htaccess`

Sin Composer, sin Node, sin dependencias externas. El lector de XLSX y el
escritor de XLSX están implementados sobre `ZipArchive` y `XMLReader`.

## Instalación

**1. Clonar dentro del directorio público**

```bash
cd /ruta/a/public_html/tu-dominio
git clone git@github.com:neracosu/concil.git conciliacion
```

**2. Crear el directorio de datos FUERA de `public_html`**

```bash
mkdir -p ~/conciliacion_data/uploads
chmod 700 ~/conciliacion_data ~/conciliacion_data/uploads
```

**3. Crear la base de datos** y un usuario con permisos sobre ella.

**4. Escribir las credenciales** en `~/conciliacion_data/secrets.php`:

```php
<?php
return [
    'db_host' => 'localhost',
    'db_name' => 'mi_base',
    'db_user' => 'mi_usuario',
    'db_pass' => 'mi_contraseña',
    // Opcional: si se omite, se genera uno y se deja en PIN-INICIAL.txt
    'pin_inicial' => '482915',
];
```

```bash
chmod 600 ~/conciliacion_data/secrets.php
```

**5. Ajustar la ruta del directorio de datos** en `lib/config.php`:

```php
const DATA_DIR = '/home/USUARIO/conciliacion_data';
```

**6. Abrir la aplicación en el navegador.** El esquema y las categorías y reglas
iniciales se crean solos en la primera visita. El PIN de entrada estará en
`~/conciliacion_data/PIN-INICIAL.txt` si no se definió en `secrets.php`.

**7. Cambiar el PIN** desde Ajustes, y cargar el saldo de arranque de las
cuentas cuyo banco no envía saldo en el extracto.

> En hosting con PHP-FPM, los `php_value` de `.htaccess` se ignoran. Los límites
> de subida se ajustan en `.user.ini`, incluido en el repositorio (tarda hasta
> `user_ini.cache_ttl`, 300 s por defecto, en tomar efecto).

## Estructura del proyecto

```
index.php              Front controller: resuelve la ruta y pinta la vista
.user.ini              Límites de subida (PHP-FPM)
.htaccess              Cabeceras de seguridad y bloqueo de lib/ y views/

lib/
  config.php           Constantes, rutas, credenciales, límites reales
  db.php               Conexión PDO y esquema (migración idempotente)
  texto.php            Normalización de texto, fechas y montos
  xlsx.php             Lector XLSX en streaming, lector CSV y lector de tablas HTML
  huella.php           Reconocimiento de formatos por estructura y comprobaciones
  sedes.php            Unidades de negocio y filtrado de todas las consultas
  importador.php       Detección de formato, mapeo de columnas, importación
  reglas.php           Motor de mapeo automático
  consultas.php        Filtros, paginación, agregados, saldos
  tasas.php            Tasa oficial del BCV por día
  exportar.php         Escritura de CSV y XLSX
  seed.php             Categorías y reglas iniciales
  auth.php             Acceso por PIN, sesión, CSRF
  usuarios.php         Personas, presencia en vivo, bitácora y rastro
  proveedores.php      Fichas de proveedor, facturas, reparto de un pago
  guia.php             Textos de la visita guiada y ayuda de cada pantalla
  mejoras.php          El historial de mejoras, del que sale la versión
  registro.php         Registro de fallos: qué pasó, dónde y cómo
  carga.php            Incluidor de conveniencia para scripts CLI

views/
  _layout.php          Armazón, navegación, cinta de conciliación, paginación
  _facturas.php        Panel de reparto de un pago entre facturas (compartido)
  login.php            Acceso por PIN
  panel.php            Resumen del período y saldos por cuenta
  carga.php            Subida: analiza e importa; solo pregunta si falta algo
  pendientes.php       Bandeja de justificación, agrupada por patrón
  movimientos.php      Consulta con filtros y exportación
  movimiento.php       Detalle y corrección de un movimiento
  reportes.php         Agregados por categoría, beneficiario, cuenta, mes, grupo
  reglas.php           Alta y mantenimiento de reglas, sugerencias automáticas
  categorias.php       Catálogo de tipos de gasto
  proveedores.php      Listado de proveedores, alta manual y carga desde archivo
  proveedor.php        Ficha: sus facturas, lo que falta de cada una y sus pagos
  facturas_panel.php   Fragmento con las facturas de un proveedor (sin recargar)
  cuentas.php          Cuentas bancarias y saldo de arranque
  sede.php             Elegir, crear y renombrar unidades de negocio
  repetidos.php        Pagos que pueden haber llegado dos veces
  usuarios.php         Alta de personas, quién está trabajando y su último rastro
  auditoria.php        El rastro completo con filtros, para auditar a fondo
  persona.php          Ficha de una persona: desde dónde entra y todo lo que ha hecho
  presencia.php        Quién está dentro y dónde, en JSON, para el latido en vivo
  perfil.php           Su nombre, su PIN y lo que ha hecho
  mejoras.php          Historial de lo que ha ido recibiendo el sistema
  ajustes.php          PIN, estado del sistema, bitácora
  salir.php            Cierre de sesión

assets/
  app.css              Estilos (paleta de marca, tablas densas, guía)
  app.js               PIN, zona de carga, confirmaciones, reparto de pagos
  guia.js              Motor de la visita guiada entre secciones

docs/
  visita-guiada.md         Cómo está hecha la visita guiada y cómo replicarla
  versionado-y-mejoras.md  El historial como única fuente de la versión
```

`lib/carga.php` es un incluidor de conveniencia: un `require` de ese archivo deja
todo el núcleo disponible para un script de línea de comandos.

## Modelo de datos

| Tabla | Contenido |
|---|---|
| `usuarios` | Quién puede entrar, su PIN, si es maestro y dónde está ahora |
| `sedes` | Unidades de negocio del consorcio |
| `proveedores` | A quién se le paga: código, RIF, NIT, teléfono; comunes a todas las unidades |
| `facturas` | Factura por proveedor **y por sede**: sus dos números, fecha, monto, moneda y retenciones |
| `pagos_factura` | Qué movimiento cubrió qué factura y por cuánto, en bolívares y en la moneda de la factura, con la tasa congelada |
| `cuentas` | Cuentas bancarias: banco, número, titular, RIF, saldo de arranque y su sede |
| `formatos` | Huellas de formato aprendidas, con su mapeo de columnas |
| `categorias` | Tipos de gasto, agrupados, con color y **anidables**: `padre_id` cuelga una de otra |
| `reglas` | Patrones que asignan categoría y beneficiario automáticamente |
| `importaciones` | Historial de cargas con conteos de nuevos y repetidos |
| `movimientos` | Los movimientos, con su clasificación y justificación |
| `bitacora` | Accesos, importaciones, correcciones y exportaciones, con IP, navegador y huella de la sesión |
| `visitas` | El recorrido: una línea por pantalla abierta, para reconstruir una jornada |
| `tasas` | La tasa del BCV, una fila por día de calendario; `origen = 'manual'` no la pisa la sincronización |
| `ajustes` | Hash del PIN, intentos fallidos, bloqueo, versión del esquema |

El esquema se crea y se actualiza solo, en `migrar()` (`lib/db.php`). Las
columnas nuevas se añaden con `columna_si_falta()`, que consulta
`information_schema` antes de alterar la tabla; ejecutar la migración varias
veces no tiene efecto.

`migrar()` corre en **cada petición**, así que se salta entera cuando
`ajustes.esquema` ya dice la versión en curso: las 55 consultas a
`information_schema` costaban 80 ms por página y crecían con cada columna
nueva. La contrapartida es una regla que hay que respetar — **quien añada una
columna o un índice tiene que subir `ESQUEMA_VERSION`**, o su migración nunca
llega a correr en el servidor.

## Motor de reglas

Una regla asocia un patrón con una categoría y, opcionalmente, un beneficiario.

| Campo | Significado |
|---|---|
| `campo` | Dónde busca: `concepto`, `nota` (la nota del archivo) o `referencia` |
| `tipo` | `contiene`, `empieza`, `termina`, `igual`, `regex` o `proporcion` |
| `patron` | Texto ya normalizado, expresión regular, o el porcentaje si es `proporcion` |
| `prioridad` | Menor gana. Las de comisiones van en 10; el desglose por subcategoría, en 8 y 12; la comodín que se lleva todo lo que diga «COM», en 70 |
| `cuenta_id` | Restringe la regla a una sola cuenta, si hace falta |
| `activa` | Una regla se puede dejar apagada: se ve en la pantalla y se enciende de un clic. Se usa para las que están esperando que contabilidad las confirme |

`proporcion` es distinta de las demás: no mira el texto de un movimiento aislado
sino su monto frente al de otro con la misma referencia, así que no pasa por
`casar_regla()` sino por `aplicar_comisiones()`, en una pasada aparte. Existe
porque Banesco cobra la comisión del pago móvil **con el mismo concepto que el
pago**, y lo único que la distingue es ser el 0,3 % de él.

La comparación ocurre sobre el texto normalizado por `norm()`: mayúsculas, sin
acentos y sin puntuación. Por eso `COM/LIQ/TDD` se guarda como `COM LIQ TDD`, y
por eso las palabras acentuadas que llegan corruptas se escriben como expresión
regular con comodín:

```php
['Comisión crédito inmediato', 'concepto', 'regex',
 'COMISI.{0,3}N CR.{0,3}DITO INMEDIATO', 'Comisiones bancarias', 'Banco', 10],
```

Ese patrón acierta tanto con `COMISION CREDITO INMEDIATO` como con el texto
corrupto `COMISI N CR DITO INMEDIATO`.

Gana la primera regla que coincide, ordenando por prioridad y luego por id. Las
reglas solo se aplican a débitos; ver [Decisiones de diseño](#decisiones-de-diseño).

**Las categorías se anidan.** `categorias.padre_id` cuelga una categoría de
otra, y la madre sigue siendo una categoría normal: lo ya clasificado en ella no
se mueve. Se usa para el desglose de comisiones que lleva contabilidad —cobro
por servicios, punto de venta con su débito, crédito y electrónico, pago móvil,
traspasos, transferencias e intervención cambiaria— y el reporte trae un corte
«Categoría principal» que vuelve a sumar cada familia entera.

**Sugerencias automáticas.** La pantalla de reglas propone reglas a partir de las
notas que ya venían escritas en los propios extractos y siguen sin clasificar.
Asignarle categoría a una nota crea la regla y la aplica de inmediato.

### Comisiones bancarias

Cada banco cobra sus comisiones de una forma, pero todas van a la misma
categoría. Hay dos caminos y se complementan:

**Por el texto.** La mayoría las nombra: `COM.CREDITO INM.OB` en Bancrecer,
`COM PAGO OB JURIDICO` en Exterior, `COMIS.PAGO INMEDIATO` en el Tesoro,
`Comision Transferencia Inm` en la columna *Motivo* de Banplus. Una sola regla
las cubre todas sobre el texto ya normalizado, sin tragarse las compras: en
`COMPRA` no hay límite de palabra después de `COM`.

**Por la proporción**, para las que no dan ninguna pista. Banesco cobra la
comisión de un pago móvil con el mismo concepto que el pago —«Banesco Pago
Movil»— así que ninguna regla de texto puede separarlas. Lo que sí las
distingue: comparten la referencia con el movimiento que las causó y son un
porcentaje fijo de él.

Es un tipo de regla propio (`tipo = 'proporcion'`, patrón `0.3` para el 0,3 %) y
se aplica en una pasada aparte, porque necesita ver la pareja y no un movimiento
aislado. La tolerancia es de ±0,02 puntos, estrecha a propósito: así el 0,3 % de
la mayoría no se confunde con el 0,35 % de Bancrecer ni con el 0,62 % de
Bicentenario, que se pueden añadir como reglas propias desde la pantalla.

Medido sobre julio de 2026: de 163 parejas de Banesco, **162 están exactamente
en el 0,3 %**. La única que se salía era un cargo de Movistar al 14 %, que la
tolerancia descarta sola.

## Control de duplicados

Los extractos suelen ser acumulativos: el archivo de mañana repite lo de hoy.
Cada movimiento recibe una **firma**:

```
sha1(cuenta | fecha | referencia | concepto | débito | crédito)
```

Como un mismo día puede tener varias líneas idénticas y legítimas, la firma se
acompaña de una **ocurrencia**: el número de vez que esa firma aparece dentro del
archivo. La restricción `UNIQUE (firma, ocurrencia)` más `INSERT IGNORE` produce
el comportamiento correcto en los tres casos:

- Recargar el mismo archivo → 0 nuevos.
- Cargar un extracto acumulativo → solo entra lo que no estaba.
- Tres líneas idénticas hoy y cinco mañana → entran dos.

## Repetidos por fecha corrida

El control de duplicados no ve una operación que el banco vuelve a listar **con
otra fecha**, porque la fecha entra en la firma. Bicentenario y el Tesoro mueven
al mes siguiente operaciones de los últimos días del mes, y esa operación queda
dos veces en los totales.

No se rechaza ni se borra nada: `marcar_repetidos()` corre después de cada carga
y escribe `posible_repetido` y `repetido_de`, y la pantalla **Repetidos** —que
solo aparece en el menú cuando hay algo que revisar— los pone delante de una
persona para que decida.

Dos caminos, porque no todos los bancos dan una referencia que sirva:

| | Se compara | Ventana |
|---|---|---|
| Con referencia útil | referencia + monto | 31 días |
| Sin ella (el Tesoro trae 2.373 filas con un «0») | concepto + monto | 3 días |

Las ventanas están medidas sobre los movimientos reales, no elegidas a ojo: con
esos números no señala ni una fila de más. Aflojar la segunda llena la pantalla
de ruido, y una pantalla con ruido deja de mirarse.

## Saldos

Cuando el banco informa saldo en sus filas, ese es el saldo que se muestra: es
el dato del banco. Cuando no lo informa, se calcula como
`saldo_inicial + créditos − débitos` desde `saldo_fecha`, y si la cuenta no
tiene saldo de arranque la interfaz dice **«falta saldo inicial»** en vez de
inventar una cifra.

**El saldo del día no es «la última fila».** Costó tres avisos del equipo el
mismo día, todos distintos. `saldo_de_cierre()` no se queda con ninguna fila por
su posición: **encadena por el propio saldo**. A cada fila se le resta su
movimiento y sale el saldo con el que llegó; la de cierre es la única que no es
la llegada de ninguna otra. Así funciona venga el archivo como venga —Banplus lo
entrega al revés, con lo más reciente arriba— y sin depender del orden de nadie.

Tres reglas más, cada una de un caso real:

- **Que el banco informe saldo no significa que sea el de hoy.** El Banco del
  Tesoro no imprime saldo en ninguna fila, así que la última fecha *con* saldo
  era la del libro y el extracto del día siguiente no contaba. Si hay
  movimientos posteriores a esa fecha, se suman y la fuente pasa a `calculado`.
- **El tope por defecto es hoy.** Un extracto con una fecha mal tecleada —cinco
  cargos fechados en noviembre— hacía que la cuenta enseñara el saldo del
  futuro.
- **Si la cadena no resuelve, no se adivina.** Devuelve `null` y decide quien
  llama. Pasa de verdad cuando el mismo día está cargado dos veces con montos
  distintos, del libro y del extracto.

El saldo de todas las cuentas se resuelve **en una sola pasada**
(`saldos_de_cuentas()`), no con una consulta por cuenta: ver
[Rendimiento](#rendimiento).

## Registro de fallos

Cuando algo se rompe, quien usa el sistema no sabe explicar qué pasó. En vez de
una pantalla en blanco, aparece una tarjeta con un **código de seis caracteres**
que se puede dictar por teléfono, y en `DATA_DIR/registro/fallos-AAAA-MM.log`
queda una línea con **qué** ocurrió (tipo y mensaje), **dónde** (archivo, línea
y pantalla) y **cómo** se llegó hasta ahí (la cadena de llamadas).

Se enganchan los tres caminos por los que PHP falla: avisos, excepciones no
atrapadas y errores fatales. Los avisos no interrumpen nada, pero quedan
anotados: suelen ser el aviso previo de algo que se romperá del todo después.

**Es un archivo, no una tabla.** El fallo más probable y más grave es que la
base no responda, y entonces una tabla no serviría de nada. Por eso incluso el
error de conexión inicial deja su código.

**Nunca se guarda el contenido de los formularios** —por ahí viaja el PIN— ni
los argumentos de las funciones en la traza. El archivo se crea con permisos
`0600` fuera de `public_html`.

En **Ajustes** hay una tabla con los últimos fallos y un contador del mes, para
saber si algo va mal sin tener que entrar al servidor.

## Usuarios y rastro

Se entra solo con **seis dígitos**, y esos dígitos identifican a la persona: no
hay nombre de usuario que escribir. Todos pueden hacer lo mismo dentro de la
aplicación; la única diferencia es el **maestro**, que además da de alta a los
demás y ve la pantalla de Usuarios.

**Dos personas no pueden compartir PIN.** Si lo compartieran, no habría forma de
saber quién hizo qué, que es justo lo que se quiere saber.

Para no probar el PIN contra cada usuario en cada intento, se guarda una huella
HMAC del PIN con una sal propia del sistema, que localiza la fila de un salto.
La comprobación real sigue siendo `password_verify` sobre un hash lento.

**La bitácora firma cada acción con su autor.** El autor sale de la sesión
dentro de `bitacora()`, así que las llamadas repartidas por la aplicación no
tuvieron que cambiar para empezar a dejar rastro con nombre.

**Presencia en vivo.** Cada página deja una marca de quién es y en qué pantalla
está, y el navegador la refresca cada veinte segundos. Arriba de cada pantalla
aparece quién más está dentro, y en el menú se enciende un ojito en la sección
donde hay alguien; si esa persona está mirando lo mismo que usted, su nombre se
marca en verde. No late con la pestaña de fondo ni tras cinco minutos sin tocar
nada, así que una pestaña olvidada deja de contar como presente —y su sesión
caduca a su hora, como debe—.

**El rastro sirve para auditar.** Cada anotación guarda además desde qué IP, con
qué navegador y sistema, en qué pantalla y una huella de la sesión, que es lo
que permite seguir una visita de principio a fin. Con el rastro de navegación
encendido queda también una línea por pantalla abierta. Todo eso se consulta en
**Rastro y auditoría** (solo el maestro), con filtros y descarga a Excel; y
haciendo clic en cualquier nombre se abre **la ficha de esa persona**: desde qué
conexiones entra, con qué equipos trabaja, en qué se le va el tiempo y su
historial completo.

**Mi perfil** es de todos: el nombre con el que se firma y el PIN propio. Nadie
necesita al maestro para cambiar su clave.

### La suma del acceso

A partir del **tercer** intento fallido, la pantalla pide resolver una suma de
dos números de un dígito. Es para los robots que prueban PINs en serie, no para
quien se equivocó de tecla: dos intentos son gratis. La suma se comprueba
**antes** que el PIN — si fuera al revés, el mensaje delataría cuándo el PIN es
correcto aunque la suma falle. Al quinto fallo sigue el bloqueo de 15 minutos.

## Seguridad

- **Acceso por PIN** de 6 dígitos, guardado con `password_hash()`. Cinco intentos
  fallidos bloquean el acceso 15 minutos.
- **Nada sensible en el repositorio.** Credenciales, base de datos y archivos
  subidos viven en `DATA_DIR`, fuera de `public_html`. El PIN de instalación se
  genera al azar; no está en el código.
- **CSRF** en todas las acciones que escriben.
- **Sentencias preparadas** en todas las consultas; los identificadores que se
  interpolan están convertidos a entero o provienen de listas cerradas.
- **Escape de salida** con `htmlspecialchars` en todo el HTML.
- **Sesión** con cookie `HttpOnly`, `SameSite=Strict`, `Secure` bajo HTTPS y
  regeneración de identificador al entrar.
- **Subidas** validadas por extensión y tamaño real del servidor, guardadas con
  nombre aleatorio y permisos `0600` fuera del directorio público. Los archivos
  abandonados se purgan a las 6 horas.
- **Defensa ante ZIP bomba**: se rechaza el archivo si su contenido declarado
  supera 400 MB antes de leer nada.
- **`lib/` y `views/` no se sirven por web** (`.htaccess` propio en cada uno).
- **Bitácora** de accesos, cargas, correcciones y exportaciones. Cada anotación
  guarda además desde qué IP, con qué navegador y sistema, en qué pantalla y una
  huella de la sesión, que es lo que permite seguir una visita de principio a
  fin. De un intento fallido queda constancia de cuántos dígitos se teclearon y
  por qué intento iba; **nunca cuáles**. La huella es un resumen del
  identificador de sesión y no el identificador: con él, quien leyera el
  registro podría suplantar a esa persona.
- **La IP que se guarda como prueba es `REMOTE_ADDR`.** La que declara el
  cliente (`X-Forwarded-For` y parecidas) va a una columna aparte, porque la
  escribe quien quiera.
- **Rastro de navegación** opcional: una línea por pantalla abierta. Se enciende
  y se apaga en *Rastro y auditoría*, que solo ve el maestro; lo que alguien
  cambia se guarda siempre. Ahí mismo se limpia lo más viejo y se baja a Excel.

## Respaldo y restauración

Todo el estado está en MySQL. **Hay un respaldo automático diario** a las 06:45
(hora de Venezuela), en `DATA_DIR/respaldos/`, con 30 días de retención:

```
45 3 * * * /home/mardenli/conciliacion_data/respaldar.sh >> …/respaldos/registro.log 2>&1
```

El guion vive fuera de `public_html` —un `.sh` dentro de la aplicación se sirve
por HTTP— y lee las credenciales de `secrets.php`, nunca las escribe. Usa
`--single-transaction`, así que no bloquea a quien esté cargando un extracto.
Después comprueba que el archivo se descomprime y que trae las tablas
esperadas: un dump truncado pesa poco y pasa desapercibido durante meses. Si
algo falla, manda un correo; si sale bien, deja una línea en el registro.

**Lo que todavía no cubre:** la copia no sale del servidor, así que protege de
un borrado pero no de que se dañe el disco.

También se puede exportar a mano:

```bash
mysqldump -u USUARIO -p BASE | gzip > concil-$(date +%F).sql.gz
```

Restaurar en una instalación limpia: crear la base, importar el volcado y
escribir `secrets.php`. Los extractos originales no hacen falta: los movimientos
ya están dentro.

```bash
zcat concil-AAAAMMDD-HHMM.sql.gz | mysql -u USUARIO -p BASE
```

**El respaldo no se ha restaurado nunca.** Se comprueba en cada corrida que el
archivo abre y que trae las tablas esperadas, y se verificó una vez que sus
filas coinciden con las de la base viva — pero nadie lo ha devuelto todavía a un
MySQL. Un respaldo sin restaurar es una hipótesis. La forma de cerrarlo, cuando
haya un rato: crear una base aparte —`..._shadow`, como en los otros proyectos
del servidor—, importar ahí el último volcado, comprobar que llegan las 15
tablas con sus filas, y borrarla. Sin tocar producción.

## Rendimiento

Medido sobre 6.496 movimientos, en PHP 8.3 con OPcache activo:

| Operación | Tiempo |
|---|---|
| Importar 6.496 movimientos (5 archivos) | 0,56 s |
| Leer un XLSX de 4.667 filas | 4 MB de memoria |
| Cualquier pantalla de la aplicación | 29–40 ms |

Decisiones que sostienen esas cifras:

- Lectura de XLSX **en streaming** con `XMLReader`: la memoria no crece con el
  tamaño del archivo.
- Inserción **por lotes** de 400 filas en una sola sentencia, en lugar de un
  viaje por fila (3,2× más rápido que la versión inicial).
- Paginación y agregados **en el servidor**: la tabla nunca envía más de 60 filas
  al navegador.
- Índices pensados uno a uno, no por si acaso: ver abajo.

### Con cinco años de movimientos encima

Se probó con **500.000 movimientos** sintéticos —unos cinco años al ritmo
actual—, y lo que salió cambió cuatro cosas del código:

| Pantalla | Antes | Después |
|---|---|---|
| Panel | 18 s | 0,8 s |
| Cuentas | 15 s | 0,5 s |
| Lista de movimientos | — | 13× más rápida |

- **Un índice de más hace daño.** `idx_mov_estado (tipo, estado)` no lo usaba
  ninguna lectura —la columna `estado` se escribe y nunca se consulta; el filtro
  «pendiente/conciliado» mira `categoria_id`— y el optimizador lo prefería para
  `tipo='D' AND cuenta_id IN (…)`, leyendo 251.000 filas una a una. **Se quitó**,
  y esas consultas bajaron 15×. Antes de añadir un índice, comprobar con
  `EXPLAIN` que el que ya hay no se vuelve la mala opción.
- **Nada de una consulta por cuenta**: `saldos_de_cuentas()` resuelve en una
  pasada lo que un bucle de `saldo_cuenta()` tardaba 9 s en hacer.
- **Ordenar y unir no se mezclan**: la lista pide primero los ids de la página
  —sin una sola unión, todo dentro del índice— y después los datos.
- **Lo que no se puede reclamar, no se calcula**: el aviso de montos repetidos
  mira los últimos 180 días.

- **Lo que corre en cada petición se paga en cada petición.** `migrar()`
  comprobaba 55 columnas contra `information_schema` en cada página: 80 ms que
  crecían con el esquema. Ahora se salta entera cuando la versión guardada
  coincide con `ESQUEMA_VERSION`.

Al medir en hosting compartido hay que tomar la mediana de varias tomas: la
misma pantalla puede dar 1,1 s y 6,4 s seguidas. OPcache está activo desde el
31/08/2026 y reduce el render alrededor de un tercio.

## Interfaz y accesibilidad

La usa gente que lleva todo el día en la pantalla y que no necesariamente
trabaja con sistemas. Cuatro decisiones que salieron de verlos usarla:

**Toda la hoja de estilos va en `rem`, nunca en píxeles** — los 108 `font-size` y
también los blancos de lo que se pulsa. Es lo que hace que el **selector de
tamaño de letra** (tres pasos, en el menú) mueva la interfaz entera y no solo el
texto. Un `font-size` en píxeles se queda pequeño cuando alguien elige letra
grande, y se nota enseguida.

**44 px es el mínimo de lo que se pulsa**, que es lo que piden las guías de
accesibilidad (WCAG 2.5.5) y lo que usan Apple y Material. Los renglones del
menú van a 46 y la acción principal de cada pantalla a 48. Lo pidió el equipo:
les costaba ubicar los botones.

**Los botones de un renglón se quedan pegados al borde derecho** de la tabla,
con sombra para que se vea que hay más detrás. Sin eso, en una tabla más ancha
que la pantalla se van fuera y no se descubren: con 22 cuentas cargadas, el
usuario dio por hecho que no se podían editar.

**Fondo claro, oscuro o el de la computadora**, a elección de cada quien y
guardado con su usuario, así que le sigue a cualquier equipo donde entre.

Y una que no se ve pero se nota: **lo que la gente teclea se corrige, y se le
dice**. `normalizar_nombre()` arregla los acentos que se caen al escribir en
mayúsculas y las erratas de la casa, y la pantalla avisa de qué cambió. Corregir
en silencio no vale: quien escribió tiene que ver qué quedó guardado.

## Visita guiada

La aplicación incluye un recorrido de 35 pasos que **navega solo entre las diez
secciones**, señalando con un foco qué hace cada una. Está escrito para personas
que no trabajan con sistemas: sin jerga técnica y explicando qué gana quien lo
usa, no qué hace el programa.

Arranca sola la primera vez y solo en el panel, nunca encima de una pantalla en
la que alguien ya esté trabajando. Se repite cuando se quiera desde el menú.
Los textos están en `lib/guia.php`; cada paso apunta a un `data-guia` de la
vista correspondiente, y los pasos cuyo objetivo no esté presente se saltan.

Además, cada pantalla lleva una frase de ayuda fija bajo su título.

> Cómo está hecha por dentro y cómo replicarla en otro proyecto:
> [`docs/visita-guiada.md`](docs/visita-guiada.md). El sistema de versiones y la
> pantalla de Mejoras están documentados igual en
> [`docs/versionado-y-mejoras.md`](docs/versionado-y-mejoras.md).

## Versión y pantalla de Mejoras

**El número de versión no está escrito en ninguna parte.** Sale de la primera
entrada de `mejoras()` (`lib/mejoras.php`), que es el historial de todo lo que el
sistema ha aprendido a hacer, contado para quien lo usa. De ahí salen a la vez el
número que aparece en el menú, en la pantalla de acceso y dentro de los archivos
exportados, y la **pantalla de Mejoras** que ve el equipo.

Es una sola fuente a propósito: un número por un lado y un changelog por otro
terminan diciendo cosas distintas, y el changelog acaba escrito en un idioma que
solo entiende quien programa.

Se anota solo lo que alguien nota. Los acomodos internos, la documentación y los
cambios de forma del código viven en el git log, que es su sitio. Cuatro tipos
—**Nuevo**, **Mejora**, **Corrección** y **Protección**—, y el número sube en el
mismo gesto de escribir la entrada: `nuevo` sube el del medio, lo demás el
último.

Que el número del menú sea un **enlace** a esa pantalla es lo que convierte el
versionado en producto: se ve la cifra, se hace clic, y ahí está lo que trae. Y
el pie de la pantalla invita a pedir más — de ahí salen la mitad de las
peticiones.

> Cómo replicarlo en otro proyecto, con el código completo:
> [`docs/versionado-y-mejoras.md`](docs/versionado-y-mejoras.md).

## Decisiones de diseño

**Solo se clasifican los débitos.** Los créditos se importan y se consultan
(filtro *Tipo → Créditos*), pero no entran a la bandeja de pendientes ni exigen
categoría: en la operación que originó el sistema los ingresos son miles de
abonos de punto de venta cuya clasificación no aporta. La estructura los soporta;
activarlos es quitar el filtro `tipo = 'D'`.

**Un PIN por persona, y nada más que el PIN.** No hay nombre de usuario que
escribir: los seis dígitos identifican a quien entra. Es lo más corto que
permite seguir firmando cada acción con su autor, que es lo que pedía
auditoría. Empezó siendo un PIN compartido para todo el equipo y se cambió en
cuanto la pregunta dejó de ser «qué se hizo» y pasó a ser «quién lo hizo».

**PHP sin dependencias.** El destino es hosting cPanel compartido, donde no hay
Node persistente. El cuello de botella real es el parseo del XLSX y MySQL, iguales
en cualquier lenguaje; el trabajo de optimización rindió más en el lote de
inserción y en los índices que en la elección de plataforma.

**Las columnas se buscan por nombre.** Cada banco entrega el mismo dato con otro
encabezado y en otro orden. Mapear por posición habría obligado a un lector por
banco.

## Limitaciones conocidas

- **No es conciliación en el sentido contable.** Clasifica y justifica cada
  salida de dinero, pero no cruza el extracto contra los libros de la empresa.
  Anotar proveedor y factura es el primer paso hacia eso, no la meta.
- **Cinco bancos no dicen por dentro de qué cuenta son** —Bancrecer, Banesco,
  Banplus, BNC y Provincial—, así que la primera vez hay que elegir la cuenta a
  mano. Después el catálogo recuerda la estructura y no vuelve a preguntar.
- **Sin el número de cuenta en la ficha**, el aviso de banco equivocado cae a
  comparar nombres de banco, que es más débil.
- **Los créditos se guardan pero no se clasifican.** Se consultan con el filtro
  de tipo. Activarlos es quitar el filtro `tipo = 'D'`, sin volver a importar.
- **La copia de seguridad no sale del servidor.** Hay respaldo diario
  automático, pero vive en el mismo disco que la base: protege de un borrado, no
  de que se dañe el disco.
- **Borrar una unidad de negocio no arrastra sus cuentas**: `cuentas.sede_id` no
  tiene clave foránea. Hoy no se pueden borrar desde la interfaz; si algún día
  se añade ese botón, hay que resolverlo antes.
- **Los XLS de Excel 97-2003 no se leen.** Se avisa y se pide guardarlos como
  `.xlsx`. Sí se leen las tablas HTML con extensión `.xls`, que es lo que
  entrega el Banco del Tesoro.

## Documentación

| Documento | De qué trata |
|---|---|
| [`docs/visita-guiada.md`](docs/visita-guiada.md) | La visita guiada por dentro: el motor, los estilos, cómo se escriben los pasos y cómo llevarla a otro proyecto o a otro stack. |
| [`docs/versionado-y-mejoras.md`](docs/versionado-y-mejoras.md) | El historial como única fuente de la versión: qué significa cada número, cómo se anota una mejora y cómo replicarlo. |
| [`CLAUDE.md`](CLAUDE.md) | Convenciones del repositorio y las trampas conocidas, para quien vaya a tocar el código. |

## Créditos

**CONCIL** es un producto de **VIP Soft** — Premium Systems Solutions.

La marca aparece en la aplicación, en la visita guiada y en las propiedades de
todos los archivos que exporta: un XLSX generado por CONCIL se abre en Excel
mostrando `CONCIL by VIP Soft` como aplicación de origen.

---

<div align="center">

**CONCIL** *by* **VIP Soft**
Premium Systems Solutions · [vipsoft.cloud](https://vipsoft.cloud)

</div>
