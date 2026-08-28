# Notas para Claude Code

Contexto y convenciones de este repositorio. Lo que ya está explicado en el
[README](README.md) no se repite aquí; esto recoge lo que no se deduce leyendo
el código.

## Qué es

Sistema de conciliación bancaria en PHP 8.2 + MySQL, **sin dependencias**: ni
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

Los extractos reales de prueba están en `../assets/AGOSTO 2026*.xlsx` (fuera del
repositorio). Cubren los cuatro formatos y contienen los casos raros: notación
científica en las referencias, texto con acentos corruptos, columna sin
encabezado y columna única de monto con signo.

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
