# Notas para Claude Code — CONCIL

Contexto y convenciones de este repositorio. Lo que ya está explicado en el
[README](README.md) no se repite aquí; esto recoge lo que no se deduce leyendo
el código.

## Qué es

**CONCIL by VIP Soft**: sistema de conciliación bancaria en PHP 8.2 + MySQL, **sin dependencias**: ni
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
- **OPcache no está instalado** para ea-php82 y no se puede habilitar desde la
  cuenta; requiere WHM.
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

## Trampas conocidas

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

**Solo bloquea lo concluyente.** El código de banco del número de cuenta
(primeros 4 dígitos) y los totales del pie del archivo detienen la importación;
la cadena del saldo solo confirma. Banplus no entrega las filas en orden de
saldo y Provincial las entrega al revés, así que **nunca** conviertas esa
comprobación en un rechazo.

**Todo se filtra por sede.** `filtro_sede()` de `lib/sedes.php` ya va dentro de
`where_filtros()`, así que cualquier consulta que pase por ahí queda cubierta.
Si escribes una consulta cruda contra `movimientos`, añádelo a mano o estarás
mostrando datos de otra unidad de negocio. Devuelve `'0'` cuando la sede no
tiene cuentas, para no generar un `IN ()` vacío que no es SQL válido.

No basta con mirar las consultas a `movimientos`: las que van contra `cuentas` o
`importaciones` también se escapan, y así se colaron el historial de cargas del
panel y el contador de Ajustes. Y **cualquier id que llegue de un formulario hay
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

**Duplicados.** La clave es `UNIQUE (firma, ocurrencia)` con `INSERT IGNORE`. La
ocurrencia es el número de vez que esa firma aparece **dentro del archivo que se
está importando**. No la cambies por un contador global: rompería la carga de
extractos acumulativos.

**Migraciones.** `migrar()` corre en cada petición y debe ser idempotente. Para
añadir una columna usa `columna_si_falta()`, nunca un `ALTER TABLE` directo.

**Solo débitos.** Casi todas las consultas filtran `tipo = 'D'` por decisión de
producto, no por omisión. Los créditos se guardan completos. Si te piden
activarlos, es quitar ese filtro, no volver a importar.

**La visita guiada.** Los pasos de `lib/guia.php` apuntan a atributos
`data-guia="..."` de las vistas. Si renombras o quitas uno de esos elementos, el
paso correspondiente se salta en silencio. Al añadir una sección nueva, añade su
ancla y su paso.

## La marca

El producto se llama **CONCIL** y el crédito es **by VIP Soft**. Se escribe
`CONCIL` en mayúsculas y `VIP Soft` con espacio. Las constantes están en
`lib/config.php` (`APP_NOMBRE`, `APP_MARCA`, `APP_LEMA`, `APP_CREDITO`,
`APP_VERSION`): úsalas, no escribas la marca a mano en las vistas.

Aparece en el menú, en la pantalla de acceso, en los títulos del navegador, en
el primer y el último paso de la visita guiada, en el nombre de los archivos
exportados y en las propiedades de los XLSX (`docProps/app.xml`).

## Al escribir textos de interfaz

El público incluye personas que no trabajan con sistemas. Evita «conciliar»,
«mapear», «regla», «patrón», «importar» o «filtro» sin explicarlos, sobre todo
en la visita guiada y en la ayuda de cada pantalla. Di qué gana quien lo usa, no
qué hace el programa. Frases cortas.

## Antes de subir cambios

- Ningún secreto en el repositorio. El PIN de instalación se genera solo y vive
  en `DATA_DIR/PIN-INICIAL.txt`; no vuelvas a escribirlo en el código.
- Ningún extracto bancario ni volcado de base: `.gitignore` los excluye, pero
  confírmalo con `git status` antes de confirmar.
- Los extractos que pasa el usuario suelen llegar a `conciliacion/assets/`, que
  se sirve por web. Muévelos a `DATA_DIR/muestras/`. El `.htaccess` ya deniega
  `.xlsx/.xls/.csv` en toda la aplicación como red de seguridad, pero el sitio
  correcto sigue siendo fuera de `public_html`.
