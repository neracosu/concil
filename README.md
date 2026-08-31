<div align="center">

# CONCIL

**by VIP Soft** · Premium Systems Solutions

*Conciliación bancaria · ¿en qué se fue el dinero?*

</div>

---

Sistema web para conciliar y justificar los movimientos bancarios de varias
cuentas. Toma los extractos que entrega cada banco, reconoce solo los pagos que
se repiten todos los meses y deja para revisión humana únicamente aquello que el
banco no explica.

---

## Índice

- [Qué problema resuelve](#qué-problema-resuelve)
- [Cómo funciona](#cómo-funciona)
- [Formatos de extracto soportados](#formatos-de-extracto-soportados)
- [Unidades de negocio](#unidades-de-negocio)
- [Proveedores y facturas](#proveedores-y-facturas)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Modelo de datos](#modelo-de-datos)
- [Motor de reglas](#motor-de-reglas)
- [Control de duplicados](#control-de-duplicados)
- [Saldos](#saldos)
- [Seguridad](#seguridad)
- [Respaldo y restauración](#respaldo-y-restauración)
- [Rendimiento](#rendimiento)
- [Visita guiada](#visita-guiada)
- [Decisiones de diseño](#decisiones-de-diseño)
- [Limitaciones conocidas](#limitaciones-conocidas)

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

En una instalación con once bancos y ~13.000 movimientos cargados, las reglas
clasifican por sí solas la mayoría de los débitos; lo que queda es lo que solo
sabe quien hizo el gasto.

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
2. **Los totales que el archivo declara en su pie.** Cuadran al céntimo: el
   extracto de Bicentenario dice 2.599 débitos por 167.634.508,43 y eso es
   exactamente lo que entra. Si no cuadran, `importar()` deshace la transacción
   completa y no guarda nada.
3. **La cadena del saldo** (`saldo anterior − débito + crédito`). Con el mapeo
   correcto encadena el 100 % de las filas; con las columnas cruzadas, ninguna.
   Solo sirve como confirmación: Banplus no viene en orden de saldo y Provincial
   viene al revés, así que un resultado bajo nunca rechaza el archivo.

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

El acceso sigue siendo un **único PIN** que ve todas las unidades y cambia entre
ellas con el selector. No hay usuarios ni permisos por sede.

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

Al justificar un pago se puede anotar **a quién se le pagó** y **el número de
factura**. El proveedor se guarda en el propio movimiento, porque todo pago
tiene destinatario haya factura o no; las facturas van en su tabla y se enlazan
al pago a través de `pagos_factura`.

Esa separación es deliberada. En la práctica una factura se paga en varias
partes, o un solo pago cubre varias facturas: con el número de factura metido en
una columna del movimiento, ninguno de los dos casos cabría. Hoy la pantalla
solo deja anotar una factura por movimiento —y solo cuando se justifica de uno
en uno, porque ponerle la misma factura a un grupo de cincuenta movimientos no
significa nada—, pero el dato no habrá que rehacerlo.

En Venezuela una factura lleva **dos** números: el suyo y el «número de control»
pre-impreso que exige la Providencia Administrativa 00071 del SENIAT, que nunca
se reinicia durante la vida del contribuyente. La tabla guarda los dos, junto
con el RIF del proveedor, aunque al justificar solo se pida el número de
factura: lo demás se completa después sin frenar el trabajo diario.

Los proveedores son **comunes a todas las unidades de negocio**, igual que las
categorías, de modo que se puede preguntar cuánto le pagó el grupo entero a un
mismo proveedor. Reportes ofrece un corte por proveedor.

El campo `beneficiario` se conserva: lo rellenan las reglas con etiquetas
gruesas («Banco», «SENIAT») y responde a otra pregunta.

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
  exportar.php         Escritura de CSV y XLSX
  seed.php             Categorías y reglas iniciales
  auth.php             Acceso por PIN, sesión, CSRF
  guia.php             Textos de la visita guiada
  registro.php         Registro de fallos: qué pasó, dónde y cómo
  carga.php            Incluidor de conveniencia para scripts CLI

views/
  _layout.php          Armazón, navegación, cinta de conciliación, paginación
  login.php            Acceso por PIN
  panel.php            Resumen del período y saldos por cuenta
  carga.php            Subida en dos pasos con confirmación de cuenta
  pendientes.php       Bandeja de justificación, agrupada por patrón
  movimientos.php      Consulta con filtros y exportación
  movimiento.php       Detalle y corrección de un movimiento
  reportes.php         Agregados por categoría, beneficiario, cuenta, mes, grupo
  reglas.php           Alta y mantenimiento de reglas, sugerencias automáticas
  categorias.php       Catálogo de tipos de gasto
  cuentas.php          Cuentas bancarias y saldo de arranque
  sede.php             Elegir, crear y renombrar unidades de negocio
  ajustes.php          PIN, estado del sistema, bitácora

assets/
  app.css              Estilos (paleta de marca, tablas densas, guía)
  app.js               PIN, zona de carga, confirmaciones
  guia.js              Motor de la visita guiada entre secciones
```

## Modelo de datos

| Tabla | Contenido |
|---|---|
| `usuarios` | Quién puede entrar, su PIN, si es maestro y dónde está ahora |
| `sedes` | Unidades de negocio del consorcio |
| `proveedores` | A quién se le paga, con su RIF; comunes a todas las unidades |
| `facturas` | Número de factura y número de control, por proveedor |
| `pagos_factura` | Qué movimiento pagó qué factura, y por cuánto |
| `cuentas` | Cuentas bancarias: banco, número, titular, RIF, saldo de arranque y su sede |
| `formatos` | Huellas de formato aprendidas, con su mapeo de columnas |
| `categorias` | Tipos de gasto, agrupados y con color |
| `reglas` | Patrones que asignan categoría y beneficiario automáticamente |
| `importaciones` | Historial de cargas con conteos de nuevos y repetidos |
| `movimientos` | Los movimientos, con su clasificación y justificación |
| `bitacora` | Accesos, importaciones, correcciones y exportaciones |
| `ajustes` | Hash del PIN, intentos fallidos, bloqueo |

El esquema se crea y se actualiza solo, en `migrar()` (`lib/db.php`). Las
columnas nuevas se añaden con `columna_si_falta()`, que consulta
`information_schema` antes de alterar la tabla; ejecutar la migración varias
veces no tiene efecto.

## Motor de reglas

Una regla asocia un patrón con una categoría y, opcionalmente, un beneficiario.

| Campo | Significado |
|---|---|
| `campo` | Dónde busca: `concepto`, `nota` (la nota del archivo) o `referencia` |
| `tipo` | `contiene`, `empieza`, `termina`, `igual` o `regex` |
| `patron` | Texto ya normalizado, o expresión regular |
| `prioridad` | Menor gana; las de comisiones usan 10 para vencer a las generales |
| `cuenta_id` | Restringe la regla a una sola cuenta, si hace falta |

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

## Saldos

Cuando el extracto trae columna de saldo (Bancamiga), ese es el saldo que se
muestra: es el que informa el banco. Cuando no la trae, el saldo se calcula como
`saldo_inicial + créditos − débitos` desde `saldo_fecha`.

Si la cuenta no tiene saldo de arranque cargado, la interfaz lo indica con
**«falta saldo inicial»** en lugar de mostrar una cifra que no puede conocer.

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
está. En Usuarios se ve quién está trabajando ahora y en qué, para saber sobre
qué está cada quien sin tener que preguntar.

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
- **Bitácora** de accesos, cargas, correcciones y exportaciones.

## Respaldo y restauración

Todo el estado está en MySQL. Respaldar es exportar la base:

```bash
mysqldump -u USUARIO -p BASE | gzip > concil-$(date +%F).sql.gz
```

Restaurar en una instalación limpia: crear la base, importar el volcado y
escribir `secrets.php`. Los extractos originales no hacen falta: los movimientos
ya están dentro.

## Rendimiento

Medido sobre 6.496 movimientos, en PHP 8.3 con OPcache activo:

| Operación | Tiempo |
|---|---|
| Importar 6.496 movimientos (5 archivos) | 0,56 s |
| Leer un XLSX de 4.667 filas | 4 MB de memoria |
| Cualquier pantalla de la aplicación | 40–130 ms |

Decisiones que sostienen esas cifras:

- Lectura de XLSX **en streaming** con `XMLReader`: la memoria no crece con el
  tamaño del archivo.
- Inserción **por lotes** de 400 filas en una sola sentencia, en lugar de un
  viaje por fila (3,2× más rápido que la versión inicial).
- Paginación y agregados **en el servidor**: la tabla nunca envía más de 60 filas
  al navegador.
- Índices sobre `fecha`, `(cuenta_id, fecha)`, `(tipo, estado)` y `categoria_id`.

Activar OPcache, donde esté disponible, reduce el tiempo de render alrededor de
un tercio.

## Visita guiada

La aplicación incluye un recorrido de 22 pasos que **navega solo entre las diez
secciones**, señalando con un foco qué hace cada una. Está escrito para personas
que no trabajan con sistemas: sin jerga técnica y explicando qué gana quien lo
usa, no qué hace el programa.

Arranca sola la primera vez y solo en el panel, nunca encima de una pantalla en
la que alguien ya esté trabajando. Se repite cuando se quiera desde el menú.
Los textos están en `lib/guia.php`; cada paso apunta a un `data-guia` de la
vista correspondiente, y los pasos cuyo objetivo no esté presente se saltan.

Además, cada pantalla lleva una frase de ayuda fija bajo su título.

## Decisiones de diseño

**Solo se clasifican los débitos.** Los créditos se importan y se consultan
(filtro *Tipo → Créditos*), pero no entran a la bandeja de pendientes ni exigen
categoría: en la operación que originó el sistema los ingresos son miles de
abonos de punto de venta cuya clasificación no aporta. La estructura los soporta;
activarlos es quitar el filtro `tipo = 'D'`.

**Un solo PIN compartido.** El sistema lo usan pocas personas de confianza. No
hay usuarios individuales, y por eso la bitácora registra acciones, no autores.

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
- **Un solo PIN, sin usuarios.** La bitácora registra acciones, no autores.
- **Borrar una unidad de negocio no arrastra sus cuentas**: `cuentas.sede_id` no
  tiene clave foránea. Hoy no se pueden borrar desde la interfaz; si algún día
  se añade ese botón, hay que resolverlo antes.
- **Los XLS de Excel 97-2003 no se leen.** Se avisa y se pide guardarlos como
  `.xlsx`. Sí se leen las tablas HTML con extensión `.xls`, que es lo que
  entrega el Banco del Tesoro.

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
