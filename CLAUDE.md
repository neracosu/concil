# Notas para Claude Code — CONCIL

Contexto y convenciones de este repositorio. Lo que ya está explicado en el
[README](README.md) no se repite aquí; esto recoge lo que no se deduce leyendo
el código.

## Qué es

**CONCIL by VIP Soft**: sistema de conciliación bancaria en PHP 8.3 + MySQL, **sin dependencias**: ni
Composer ni Node. El lector y el escritor de XLSX están implementados a mano
sobre `ZipArchive` y `XMLReader`. No introduzcas librerías externas: el destino
es hosting cPanel compartido y esa restricción es deliberada.

## Entorno de este servidor

- La instalación de producción vive en `public_html/vipsoft.cloud/conciliacion`.
  **Editar un archivo es desplegarlo**: no hay build ni paso de publicación. Si
  vas a tocar varios archivos que dependen entre sí (por ejemplo `lib/guia.php`
  y `views/_layout.php`), hazlo rápido y verifica enseguida — durante esos
  segundos la aplicación puede quedar rota para quien la esté usando.
- Los datos y credenciales están en `/home/mardenli/conciliacion_data`
  (`secrets.php`, `uploads/`, `PIN-INICIAL.txt`), fuera de `public_html`.
- SAPI **fpm-fcgi**: los `php_value` del `.htaccess` se ignoran. Los límites de
  subida se cambian en `.user.ini`, con hasta 300 s de retardo.
- **OPcache está activo** desde el 31/08/2026, cuando el usuario actualizó el
  servidor desde WHM (tiene acceso; no hay que mandarlo al proveedor). Ojo:
  `opcache.enable_cli` está en Off, así que `php -m` en la shell no lo lista
  aunque esté cargado en el SAPI web.
- El dominio corre **ea-php83**, fijado en el `.htaccess` de
  `public_html/vipsoft.cloud`, no en el de la aplicación.
- Hay claves SSH por proyecto en `~/.ssh/config` con `IdentitiesOnly`. Son user
  keys de la cuenta `neracosu`, no deploy keys.

## Cómo probar

No hay framework de tests. Para verificar cambios:

```bash
# Sintaxis de todo el proyecto
for f in index.php lib/*.php views/*.php; do php -l "$f" > /dev/null || echo "ERROR $f"; done

# Servidor local, sin tocar producción
php -S 127.0.0.1:8787 -t .

# Recorrer las rutas buscando errores de PHP
curl -s -b cookies.txt "http://127.0.0.1:8787/index.php?r=panel" | grep -c "Fatal error\|Warning:"
```

`lib/carga.php` es un incluidor de conveniencia: `require` ese único archivo y
tienes todo el núcleo disponible para un script CLI.

Los extractos reales de prueba están en `/home/mardenli/conciliacion_data/muestras/`
(fuera de `public_html`, permisos 600). Son 16 y cubren los once bancos con sus
casos raros: notación científica en las referencias, acentos corruptos, la
columna de fecha sin rótulo de Banesco, el Exterior sin encabezado alguno y el
Tesoro entregado como tabla HTML con extensión `.xls`.

No los dejes dentro de `public_html`: se sirven por HTTP aunque el listado de
directorio esté cerrado.

## Convenciones del código

- **Todo en español**: nombres de funciones, variables, comentarios, tablas y
  columnas. Mantenlo así.
- **Comentarios que explican el porqué**, no el qué. Si un comentario se limita a
  repetir lo que hace la línea siguiente, sobra.
- Sin framework y sin clases salvo donde aportan (`XlsxLector`). Funciones
  sueltas agrupadas por archivo.
- Cada vista es un archivo en `views/` que se incluye desde `index.php` y se
  envuelve entre `encabezado_html()` y `pie_html()`.
- Las acciones POST se procesan al principio de la vista, terminan en
  `redirigir()` y dejan el mensaje con `flash()` (patrón POST-redirect-GET).
- Escape de salida con `e()` siempre. Las excepciones son deliberadas y están
  construidas en el propio código (`$acciones`, `$subtitulo`, `ayuda_pantalla()`).

## Cuando algo falla

`lib/registro.php` engancha avisos, excepciones y errores fatales, y escribe
JSON por líneas en `DATA_DIR/registro/fallos-AAAA-MM.log`. La persona ve un
código de seis caracteres; ese código es la forma de encontrar la entrada.

Va a un **archivo y no a una tabla** a propósito: el fallo más grave es que la
base no responda. No metas el contenido de `$_POST` ni los argumentos de la
traza: por ahí viaja el PIN.

## Trampas conocidas

**Todo va en hora de Venezuela.** `ZONA_HORARIA` en `lib/config.php` la fija
para PHP, y `db()` hace `SET time_zone = '-04:00'` en cada conexión. Las dos
cosas tienen que ir de acuerdo: el servidor está en otra zona, y mezclar
`time()` de PHP con un `DATETIME` de la base da diferencias de horas. Si
necesitas «hace cuánto», calcúlalo en SQL con `TIMESTAMPDIFF`, no restando en
PHP.

**El formato se reconoce por estructura, nunca por el nombre.** Ni el del
archivo ni el de la hoja: contabilidad los renombra. `huella()` en
`lib/huella.php` combina rótulos con su columna, fila del encabezado, ancho y la
forma de cada columna (`F` fecha `N` número `T` texto `S` signo `V` vacía). El
título que el banco imprime *dentro* del archivo sí vale, porque es contenido.
Si añades un banco, no toques `detectar_banco()` esperando que resuelva: lo
normal es que el catálogo `formatos` lo aprenda solo al confirmar la carga.

**El número de cuenta del archivo no es el de la contraparte.** En el extracto
del BNC hay cinco filas seguidas con la cuenta del Tesoro (a quien se transfirió)
y una con la suya propia. `cuenta_declarada()` solo acepta el número si está en
la cabecera, antes del encabezado, o si una celda con exactamente 20 dígitos se
repite en **todas** las filas de la muestra (así se detecta Venezuela, que trae
`numeroCuenta` por fila). Aflojar ese criterio hace que el sistema bloquee
importaciones correctas.

**La ficha de la cuenta se advierte, no se exige.** Fue al revés durante unas
horas del 31/08/2026 y el usuario lo cambió: bloquear dejaba las cinco cuentas
existentes sin poder cargar nada. Lo único imprescindible al crear una cuenta es
el nombre.

**El bloqueo compara número contra número, no nombres.** Los cuatro primeros
dígitos de la cuenta del archivo contra los de `cuentas.numero`. Comparar por el
nombre del banco dejaba pasar los archivos cuando la cuenta se creó sin él —el
caso de los cinco bancos que no dicen quiénes son—, y así se coló un extracto de
Venezuela en una cuenta del BNC durante el recorrido de prueba.

**Solo bloquea lo concluyente.** Únicamente el código de banco del número de
cuenta (primeros 4 dígitos) detiene la importación. La cadena del saldo solo
confirma: Banplus no entrega las filas en orden de saldo y Provincial las
entrega al revés, así que **nunca** conviertas esa comprobación en un rechazo.

**Los totales del pie del archivo no mandan.** Totalizamos nosotros, fila por
fila, y esa es la cifra que se guarda y se muestra. El resumen que el banco
imprime al pie se compara y, si difiere, se avisa —nunca se rechaza la carga—.
Lo decidió el equipo el 08/09/2026: hay historial de extractos que llegan con su
propio total mal calculado, y mientras ese pie mandaba, un archivo bueno se
perdía entero. `comparar_totales()` devuelve `propio` (lo nuestro) y `discrepa`
(el aviso); las sumas quedan en `importaciones.suma_debito/suma_credito` y la
diferencia en `descuadre`.

**`sede_elegida()` no es lo mismo que `sede_actual()`.** La primera dice si se
eligió unidad en esta sesión; la segunda devuelve una igualmente —la única que
haya— para que ninguna consulta reviente mientras se está eligiendo. El front
controller mira `sede_elegida()`, y las consultas usan `sede_actual()`.

**Todo se filtra por sede.** `filtro_sede()` de `lib/sedes.php` ya va dentro de
`where_filtros()`, así que cualquier consulta que pase por ahí queda cubierta.
Si escribes una consulta cruda contra `movimientos`, añádelo a mano o estarás
mostrando datos de otra unidad de negocio. Devuelve `'0'` cuando la sede no
tiene cuentas, para no generar un `IN ()` vacío que no es SQL válido.

No basta con mirar las consultas a `movimientos`: las que van contra `cuentas`,
`importaciones`, `facturas` o `pagos_factura` también se escapan, y así se
colaron el historial de cargas del panel y el contador de Ajustes. Los
**proveedores** sí son del grupo entero a propósito; las **facturas no**, porque
la deuda la tiene una empresa concreta: `facturas.sede_id` va en la clave única
y todo `factura_id` que llegue de un formulario se comprueba con
`factura_de_sede()`. Y **cualquier id que llegue de un formulario hay
que comprobarlo contra la sede** antes de usarlo: el `cuenta_id` de la carga y
el `movimiento_id` al anotar un proveedor permitían tocar otra unidad.

**Las comisiones tienen dos caminos y hay que respetar los dos.** La mayoría de
los bancos las nombra y basta una regla de texto; Banesco cobra la comisión del
pago móvil con el mismo concepto que el pago, así que solo se distingue por ser
el 0,3 % de un movimiento con su misma referencia. Ese tipo de regla
(`proporcion`) no pasa por `casar_regla()` —que mira un movimiento aislado— sino
por `aplicar_comisiones()`, una pasada aparte. Si añades tipos de regla nuevos,
acuérdate de excluirlos en `casar_regla()` o el `default` de `coincide()` los
tratará como «contiene» y casarán con cualquier cosa.

**La normalización manda.** `norm()` pasa a mayúsculas, quita acentos y sustituye
todo lo que no sea alfanumérico por un espacio. Los patrones de las reglas se
guardan ya normalizados (salvo los `regex`, que se aplican sobre el texto
normalizado). Si comparas texto de banco sin pasarlo por `norm()`, no coincidirá
nada.

**Acentos corruptos.** Algunos extractos llegan con el carácter de reemplazo
U+FFFD donde iba un acento. `norm()` lo convierte en espacio, así que
`COMISIÓN` y su versión corrupta no son iguales. Por eso esas reglas usan
expresiones regulares con comodín: `COMISI.{0,3}N CR.{0,3}DITO`.

**El signo del monto.** Cuando el extracto trae una sola columna `Monto`
(Banesco), negativo es débito. Cuando trae `Débito` y `Crédito` separadas, se
toma el valor absoluto de cada una.

**Subir el extracto es cargarlo.** Desde el 08/09/2026 no hay botón intermedio:
`analizar` calcula `cuenta_sugerida()` y `preguntas_de()`, y si no queda nada
que preguntar llama a `procesar_lote()` en la misma petición. La pantalla de
confirmar solo aparece cuando falta algo —a qué cuenta va, de qué banco es, o
un dato de la ficha que ni la cuenta tiene ni el archivo trae—. Si tocas una de
esas dos funciones, acuérdate de que la vista y la decisión automática tienen
que proponer lo mismo o el archivo entrará en un sitio distinto al que se
enseña.

**Se puede deshacer una carga entera** con `deshacer_importacion()`. Limpia a
mano el `traspaso_id` de las parejas antes de borrar, porque esa columna no
tiene clave foránea. Los `pagos_factura` sí caen por FK: eso es trabajo de una
persona que se pierde, así que la pantalla lo avisa contándolo antes.

**Los repetidos por fecha corrida se marcan, no se rechazan.** Bicentenario y el
Tesoro mueven al mes siguiente operaciones de fin de mes; como la fecha entra en
la firma, el control de duplicados no las ve. `marcar_repetidos()` corre después
de la carga y escribe `posible_repetido` y `repetido_de`. Dos caminos: con
referencia útil basta con ella y el monto, con ventana de 31 días —medido sobre
los movimientos reales, no marca ni una fila de más—; sin ella (el Tesoro trae
2.373 filas con un «0») se compara el concepto y la ventana baja a 3 días. Si
aflojas esa segunda ventana, la pantalla de Repetidos se llena de ruido y deja
de mirarse.

**Duplicados.** La clave es `UNIQUE (firma, ocurrencia)` con `INSERT IGNORE`. La
ocurrencia es el número de vez que esa firma aparece **dentro del archivo que se
está importando**. No la cambies por un contador global: rompería la carga de
extractos acumulativos.

**El reparto de un pago se rehace entero.** `repartir_pago()` borra los enlaces
de ese movimiento y los vuelve a escribir, así que quitar una factura es no
mandarla. Por eso `lista_facturas()` tiene que seguir dibujando las facturas que
ese pago ya cubre aunque estén saldadas: si desaparecieran de la pantalla, el
siguiente guardado borraría el reparto sin que nadie se entere. Y el saldo de una
factura **se calcula, nunca se guarda**: retener no es dejar de pagar, así que
está cubierta cuando `aplicado + retenido >= monto`.

**La tasa se congela en el reparto.** Una factura en dólares pagada en bolívares
guarda en `pagos_factura.tasa` la del BCV del día del **movimiento**. Si mañana
cambia, lo anotado ayer no se mueve.

**Migraciones.** `migrar()` corre en cada petición y debe ser idempotente. Para
añadir una columna usa `columna_si_falta()`, nunca un `ALTER TABLE` directo. Si
tienes que cambiar una clave única, crea antes el índice suelto de la columna que
sostiene la foránea: MySQL no deja soltar el índice del que depende una FK.

**Y si tocas el esquema, sube `ESQUEMA_VERSION`.** Desde el 08/09/2026 `migrar()`
se salta entero cuando `ajustes.esquema` ya dice esa versión: las 55 consultas a
`information_schema` costaban 80 ms en **cada** petición y crecían con cada
columna. **Si añades una columna o un índice y no subes el número, tu migración
no llega a correr en el servidor.**

**Solo débitos.** Casi todas las consultas filtran `tipo = 'D'` por decisión de
producto, no por omisión. Los créditos se guardan completos. Si te piden
activarlos, es quitar ese filtro, no volver a importar.

**La tasa del BCV va por día de calendario.** `lib/tasas.php` guarda una fila
por fecha y la ata a `movimientos.fecha` —la del extracto—, nunca a la de la
carga. La fuente (`bcv.today`) trae dos campos de fecha y solo uno sirve como
clave: `date` es el día de calendario y `effective_date` el día en que el BCV la
valoró, así que el sábado y el domingo comparten el `effective_date` del viernes.
Guardar por `effective_date` deja el fin de semana sin fila; se guarda por
`date`. Una tasa con `origen = 'manual'` no la pisa la sincronización.

**La visita guiada.** Los pasos de `lib/guia.php` apuntan a atributos
`data-guia="..."` de las vistas. Si renombras o quitas uno de esos elementos, el
paso correspondiente se salta en silencio. Al añadir una sección nueva, añade su
ancla y su paso.

**Cada nombre del rastro lleva a su ficha.** `?r=persona&id=N`, solo para el
maestro (quien no lo es, y pide la suya, cae en Mi perfil). `persona_enlace()`
en `_layout.php` decide si pinta enlace o nombre pelado, así que úsala en vez
de escribir el nombre a mano en una tabla nueva del rastro. Las líneas viejas
sin `usuario_id` —anotadas antes de que hubiera usuarios— salen con una raya.

**`EMULATE_PREPARES` está en false.** Un parámetro con nombre **no se puede
repetir** en varios sitios de la misma consulta: MySQL responde «Invalid
parameter number». Si el mismo valor va cinco veces, van cinco `?` y
`array_fill()`. Pasó estrenando `resumen_persona()`.

**El rastro no guarda nunca el PIN, ni su forma.** `bitacora()` anota acción,
autor, IP, navegador, ruta, método, sede y una huella de la sesión. De un
intento fallido se anota **cuántos dígitos llegaron y por qué intento iba**,
nunca cuáles. La huella de la sesión es `sha256(session_id())` recortada: con
el identificador entero, quien leyera el registro podría suplantar a esa
persona.

**`ip` es prueba; `via` es pista.** `ip_cliente()` devuelve `REMOTE_ADDR`, lo
único que no puede falsear quien llama. `X-Forwarded-For` y compañía las
escribe el propio cliente, así que van a la columna `via` y se enseñan aparte.
Hoy el servidor es Apache sin proxy delante y `via` va vacía; el día que entre
un CDN, no hay que tocar nada.

**La presencia se pregunta, no se empuja.** El navegador pide `?r=presencia`
cada 20 s y el servidor contesta JSON. Nada de websockets: el hosting es
compartido. El latido **no se manda** si la pestaña está de fondo o si hace
más de cinco minutos que nadie toca nada — y eso no es solo ahorro: sin ello
una pestaña olvidada renovaría `$_SESSION['visto']` para siempre y la sesión
de ocho horas no caducaría nunca. `marcar_presencia()` distingue el latido de
una visita real: por la ruta `presencia` conserva la pantalla que manda el
navegador en `en` y **no** anota la visita.

**`visitas` recibe una escritura por página.** Es la tabla que más rápido va a
crecer, así que cada índice se paga en cada página. Medido con 100.000 filas:
mientras la única pantalla fue la auditoría, toda consulta partía del rango de
fechas y ganaba `idx_vis_fecha`, así que el índice `(usuario_id, id)` no lo
usaba nadie y se quitó. La **ficha de una persona** trajo después una forma que
antes no existía —filtrar por alguien *sin* rango de fechas— y ahí sí hace
falta: `idx_vis_persona (usuario_id, creado_en)`, con la fecha dentro para que
sirva también de orden; agrupar sus visitas pasó de 160 ms a 55, y la auditoría
filtrando por persona mira 533 filas en vez de 3.200. La moraleja no es «índice
sí» o «índice no», es que **el índice lo decide la forma de la consulta, y esa
cambia cuando añades una pantalla**. Filtrar por una visita concreta **no lleva
fechas**: la huella ya es estrecha y va por `idx_vis_sesion`; si le pusieras el
rango por defecto, «ver esta visita» de algo de hace un mes no enseñaría nada.
Se puede apagar entero desde Auditoría (`ajustes.rastro_navegacion`); lo que
alguien **cambia** se guarda siempre y eso no se apaga.

## Tamaños y accesibilidad

**Toda la hoja de estilos va en `rem`, no en píxeles.** Los 108 `font-size` se
convirtieron el 08/09/2026, y los blancos de lo que se pulsa (botones, campos,
renglones del menú) también. Es lo que hace que el selector de tamaño de letra
—`:root[data-escala]`, tres pasos— mueva de verdad la interfaz entera y no solo
el texto. **Si añades un `font-size` en píxeles, ese trozo se queda pequeño
cuando alguien elija letra grande.**

**`hidden` pierde contra cualquier clase con `display`.** La hoja del navegador
tiene menos prioridad que la nuestra, así que `.nav{display:flex}` anulaba el
atributo `hidden` y el menú se seguía viendo mientras se elige unidad. Está
resuelto con un `[hidden]{display:none !important}` al principio de `app.css`;
si escribes `display` en una clase que también se oculta por atributo, ya está
cubierto.

**44 px es el mínimo de lo que se pulsa.** Es lo que piden las guías de
accesibilidad (WCAG 2.5.5) y lo que usan Apple y Material. Los renglones del
menú van a 46 y `.btn-grande` a 48, para la acción principal de cada pantalla.
Lo pidió el equipo: a la gente del departamento le costaba ubicar los botones.

**El número de pendientes es el corazón de la pantalla.** Va en `.nav .cuenta`
como pastilla sólida y enciende su renglón con `.tiene-pendientes` mientras
quede algo. Antes eran 11 px sobre un fondo casi transparente y había que
acercarse a leerlo. Si tocas eso, acuérdate de por qué está así.

## Cuando la tabla crezca

Medido el 08/09/2026 con **500.000 movimientos** sintéticos —unos cinco años al
ritmo actual— en una sede de usar y tirar. El panel tardaba **18 s**; quedó en
0,8. Lo que se aprendió, por si vuelve a pasar:

- **Un índice de más hace daño.** `idx_mov_estado (tipo, estado)` no lo usaba
  ninguna lectura —la columna `estado` se escribe y nunca se consulta; el filtro
  «pendiente/conciliado» mira `categoria_id`— y el optimizador lo prefería para
  `tipo='D' AND cuenta_id IN (...)`, leyendo 251.000 filas una a una. Quitándolo,
  esas consultas bajaron 15×. **Antes de añadir un índice, comprueba con
  `EXPLAIN` que el que ya hay no se vuelve la mala opción.**
- **Nada de una consulta por cuenta.** `saldo_cuenta()` en un bucle costaba 9 s;
  `saldos_de_cuentas()` hace lo mismo en una pasada y en 0,4. Lo mismo en la
  lista de Cuentas y en el reporte por cuenta.
- **Ordenar y unir no se mezclan.** `listar_movimientos()` juntaba cuatro tablas
  y ordenaba 300.000 filas para enseñar 60. Ahora pide primero los ids de la
  página —sin una sola unión, todo dentro del índice— y después los datos: 13×.
- **`saldo IS NOT NULL` con `ORDER BY ... LIMIT 1` es una trampa.** Si ninguna
  fila cumple, MySQL recorre el índice entero convencido de que va a encontrar
  algo enseguida. Solo Bancamiga y Bicentenario traen saldo, así que era el caso
  normal. Se resuelve preguntando antes, en la pasada agrupada, si esa cuenta
  tiene alguno.
- **Lo que no se puede reclamar, no se calcula.** `montos_repetidos()` mira los
  últimos 180 días en la vista general: 2,1 s → 0,16.
- **Cuidado al medir.** El hosting compartido mete varios segundos de ruido: la
  misma pantalla dio 1,1 s y 6,4 s seguidas. Toma la mediana de varias, y
  distingue frío de caliente.

## La marca

El producto se llama **CONCIL** y el crédito es **by VIP Soft**. Se escribe
`CONCIL` en mayúsculas y `VIP Soft` con espacio. Las constantes están en
`lib/config.php` (`APP_NOMBRE`, `APP_MARCA`, `APP_LEMA`, `APP_CREDITO`): úsalas,
no escribas la marca a mano en las vistas.

Aparece en el menú, en la pantalla de acceso, en los títulos del navegador, en
el primer y el último paso de la visita guiada, en el nombre de los archivos
exportados y en las propiedades de los XLSX (`docProps/app.xml`).

## La versión y la pantalla de Mejoras

**`APP_VERSION` no se escribe a mano.** Sale de la primera entrada de
`mejoras()` en `lib/mejoras.php`, que `config.php` incluye para definirla. El
número del menú y lo que cuenta la pantalla de Mejoras no pueden discrepar
porque son el mismo dato.

Al entregar algo que la gente nota, se añade su entrada **al principio** del
array, con la fecha del día, y el número sube ahí: `nuevo` sube el del medio
(2.1 → 2.2), lo demás el último (2.2 → 2.2.1). El primer número solo cambia
cuando el sistema cambia de cara, y eso lo decide una persona.

No se anota lo que nadie ve: acomodos internos, cambios de documentación o de
cómo está escrito el programa. Eso vive en el git log, que es su sitio. El
registro de cambios **no va en este archivo**.

El texto se escribe como se lo contarías al dueño del negocio, con las reglas
de «Al escribir textos de interfaz». Si al añadir la entrada el número sube,
el commit puede seguir llamándose «Versión X.Y: …» como hasta ahora.

## Al escribir textos de interfaz

El público incluye personas que no trabajan con sistemas. Evita «conciliar»,
«mapear», «regla», «patrón», «importar» o «filtro» sin explicarlos, sobre todo
en la visita guiada y en la ayuda de cada pantalla. Di qué gana quien lo usa, no
qué hace el programa. Frases cortas.

**Español de Venezuela, no de España.** Un texto que suena importado desentona
y le quita autoridad al producto delante de quien lo va a aprobar. No: «echar en
falta», «abajo del todo», «de sitio», «el ratón», «pulsar», «ordenador»,
«fichero». Sí: «faltar algo», «al final de», «de lugar», «el mouse», «hacer
clic», «computadora», «archivo». Relee lo que escribas pensando en si alguien de
Caracas lo diría así.

## Probar de punta a punta

Se puede recorrer la aplicación por HTTP sin conocer el PIN: crear la sesión con
las propias funciones de `lib/auth.php` desde un script CLI, con
`session_save_path('/var/cpanel/php/sessions/ea-php83')` y el nombre de cookie
**`CONCILSESS`** (no `PHPSESSID`), y luego `curl -b "CONCILSESS=<id>"`.

Dos trampas al hacerlo:
- `curl -X POST` junto con `-L` reenvía el POST a la redirección y produce un
  419 que no es de la aplicación. Usa `--data` sin `-X`, o no sigas redirecciones.
- Haz las pruebas destructivas dentro de una **unidad de negocio de usar y
  tirar**. `cuentas.sede_id` no tiene clave foránea, así que borrar la sede deja
  cuentas y movimientos huérfanos: hay que borrar las cuentas (sus movimientos
  caen por FK) y las filas de `importaciones` que quedan con `cuenta_id` a NULL.

## Antes de subir cambios

- **El repositorio es público.** `neracosu/concil` se lee sin identificarse, así
  que todo lo que escribas en el código queda en internet para siempre, también
  en el historial. No solo credenciales: tampoco teléfonos, direcciones ni
  nombres de proveedores. Eso vive en `secrets.php` y se lee con una función,
  como `soporte_telefono()`.
- Ningún secreto en el repositorio. El PIN de instalación se genera solo y vive
  en `DATA_DIR/PIN-INICIAL.txt`; no vuelvas a escribirlo en el código.
- Ningún extracto bancario ni volcado de base: `.gitignore` los excluye, pero
  confírmalo con `git status` antes de confirmar.
- Los extractos que pasa el usuario suelen llegar a `conciliacion/assets/`, que
  se sirve por web. Muévelos a `DATA_DIR/muestras/`. El `.htaccess` ya deniega
  `.xlsx/.xls/.csv` en toda la aplicación como red de seguridad, pero el sitio
  correcto sigue siendo fuera de `public_html`.
