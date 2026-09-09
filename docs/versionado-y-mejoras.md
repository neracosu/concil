<div align="center">

# Versionado y pantalla de Mejoras

**El historial de cambios es la versión, y lo lee el dueño del negocio**

*Cómo está hecho y cómo replicarlo en otro proyecto.*

</div>

---

## Índice

- [La idea en una frase](#la-idea-en-una-frase)
- [Qué significa cada número](#qué-significa-cada-número)
- [Una entrada del historial](#una-entrada-del-historial)
- [El archivo completo](#el-archivo-completo)
- [Dónde sale el número](#dónde-sale-el-número)
- [La pantalla](#la-pantalla)
- [Los estilos](#los-estilos)
- [Cómo se anota una mejora](#cómo-se-anota-una-mejora)
- [Cómo se escriben las entradas](#cómo-se-escriben-las-entradas)
- [Trampas que ya se pagaron](#trampas-que-ya-se-pagaron)
- [Llevarlo a otro stack](#llevarlo-a-otro-stack)
- [Lista de comprobación](#lista-de-comprobación)

---

## La idea en una frase

**El changelog no acompaña a la versión: el changelog *es* la versión.**

En casi todos los proyectos hay un número escrito a mano en algún sitio
(`package.json`, una constante, una etiqueta de git) y aparte un `CHANGELOG.md`
que alguien actualiza cuando se acuerda. Terminan diciendo cosas distintas
—siempre— y el changelog termina siendo ilegible para quien paga el sistema:
«fix: null check en el reducer de saldos».

Aquí hay **un solo archivo**: un array de entradas ordenado de lo más reciente a
lo más antiguo. La versión del sistema es, literalmente, la versión de la
primera entrada:

```php
function version_actual(): string
{
    return mejoras()[0]['version'] ?? '1.0';
}
```

De ahí salen las tres cosas a la vez:

1. El **número** que se ve en el menú, en la pantalla de acceso y dentro de los
   archivos exportados.
2. La **pantalla de Mejoras**, que es una línea de tiempo de todo lo que el
   sistema ha aprendido a hacer.
3. La **disciplina**: no se puede subir la versión sin escribir qué cambió, ni
   escribir qué cambió sin decidir cuánto sube la versión. Son el mismo gesto.

Consecuencia práctica que sorprende: el equipo entra a esa pantalla. Un
changelog escrito para programadores no lo abre nadie de fuera; escrito así, se
convierte en la prueba de que el sistema avanza, y en el sitio donde cada quien
reconoce lo que pidió.

---

## Qué significa cada número

Es semver por la forma —`MAYOR.MENOR.PARCHE`—, pero **no por el motivo**. El
semver clásico habla de compatibilidad para quien consume una API. Aquí no hay
API que consumir: hay una persona que quiere saber si el sistema cambió mucho o
poco. Así que los tres números se redefinen en esos términos:

| Número | Sube cuando | Quién lo decide |
|---|---|---|
| **Mayor** (`3`.0.0) | El sistema **cambia de cara**. Otra forma de trabajar, no una pantalla más. | Una persona. No hay regla automática. |
| **Menor** (2.`8`.0) | El sistema **aprende a hacer algo que antes no hacía**. Es el tipo `nuevo`. | La regla: si la entrada es `'tipo' => 'nuevo'`, sube este. |
| **Parche** (2.7.`11`) | Todo lo demás que la persona nota: mejoras, correcciones, protecciones. | La regla: sube este. |

Y la explicación que se le da a quien usa el sistema, tal como está escrita en
la propia pantalla:

> El número tiene dos partes, por ejemplo **v2.7.10**:
> - La **primera** cambia cuando el sistema cambia de cara. Ha pasado una vez.
> - La **segunda** sube cada vez que el sistema aprende a hacer algo que antes
>   no hacía.
>
> Sirve para una cosa muy concreta: si algo se ve distinto a como se lo
> explicaron, este número dice qué versión está usando.

Ese último párrafo es el que justifica todo el invento. El número no está para
los programadores: está para que una llamada de soporte empiece por «¿qué
número le sale abajo a la izquierda?».

### Los cuatro tipos

```php
/** Tipos de mejora, con el rótulo que ve la persona y su color. */
function tipos_mejora(): array
{
    return [
        'nuevo'      => ['rotulo' => 'Nuevo',      'color' => 'var(--entrada)'],   // verde
        'mejora'     => ['rotulo' => 'Mejora',     'color' => 'var(--cian)'],      // cian
        'correccion' => ['rotulo' => 'Corrección', 'color' => 'var(--pendiente)'], // ámbar
        'proteccion' => ['rotulo' => 'Protección', 'color' => 'var(--violeta)'],   // violeta
    ];
}
```

Cuatro, y ni uno más. Cada tipo tiene que responder a una pregunta distinta que
la persona se hace de verdad:

- **Nuevo** — ¿qué puedo hacer ahora que antes no podía?
- **Mejora** — ¿qué me va a costar menos trabajo?
- **Corrección** — ¿qué estaba mal y ya no lo está? *(Decirlo importa: quien vio
  el número malo necesita saber que se arregló, y cuándo.)*
- **Protección** — ¿qué se hizo para que no se pierda o no se cuele nada?

`refactor`, `chore`, `docs`, `style`, `perf` y compañía **no existen aquí**. Eso
es vocabulario de quien escribe el programa y vive en el git log, que es su
sitio.

---

## Una entrada del historial

```php
[
    'fecha' => '2026-09-08', 'version' => '2.7.8', 'tipo' => 'mejora',
    'titulo' => 'Su trabajo del semestre ya está adentro',
    'resumen' => 'Se cargó el libro de auditoría que lleva el departamento: 32.263 '
               . 'movimientos de julio al 7 de septiembre, en 22 cuentas, con la '
               . 'clasificación que ustedes ya le habían puesto a mano. No hubo que volver a '
               . 'explicar nada: nueve de cada diez pagos entraron ya justificados.',
    'detalles' => [
        'Las 22 cuentas se crearon con su número y su saldo de arranque, tal como están en '
        . 'el libro.',
        'IMPORTANTE: julio y agosto ya están cargados. No vuelvan a subir esos extractos, '
        . 'porque entrarían por segunda vez.',
        'Quedan 1.725 pagos por explicar, casi todos pagos sueltos a un proveedor. Se '
        . 'explican una vez y el sistema aprende.',
    ],
],
```

| Campo | Obligatorio | Qué es |
|---|---|---|
| `fecha` | sí | ISO `AAAA-MM-DD`. Agrupa la línea de tiempo por mes y se pinta como «8 de septiembre de 2026». |
| `version` | sí | La de esta entrega. La de la **primera** entrada es la del sistema. |
| `tipo` | sí | `nuevo`, `mejora`, `correccion` o `proteccion`. |
| `titulo` | sí | Una frase con sujeto y verbo. Es lo único que mucha gente lee. |
| `resumen` | sí | Tres o cuatro líneas: qué pasaba antes y qué pasa ahora. |
| `detalles` | no | Lista de puntos concretos. Aquí van las cifras y los avisos. |

**Los prefijos `IMPORTANTE:` y `REVISAR:` dentro de `detalles`** son una
convención que vale la pena copiar: es donde se le dice al equipo lo que tiene
que hacer o mirar por su cuenta. Quedan en el historial con fecha, así que
cuando alguien pregunta «¿por qué no subimos julio otra vez?», la respuesta está
escrita y fechada.

---

## El archivo completo

Cabecera del archivo. Estas instrucciones van **dentro** del archivo, no en la
documentación: quien va a añadir una entrada ya tiene el archivo abierto.

```php
<?php
/**
 * Las mejoras que ha ido recibiendo CONCIL, contadas para quien las usa.
 *
 * Este archivo es la única fuente: de aquí salen la pantalla de Mejoras y el
 * número de versión que aparece en el menú, en la pantalla de acceso y en los
 * archivos que se exportan. No hay tabla ni archivo aparte, a propósito: dos
 * sitios donde anotar lo mismo terminan diciendo cosas distintas.
 *
 * La entrada más reciente va PRIMERA, y su versión es la del sistema.
 *
 * Cómo anotar una mejora nueva:
 *   1. Añada la entrada al principio del array, con la fecha del día.
 *   2. Suba el número: 'nuevo' sube el del medio (2.1 → 2.2), lo demás sube el
 *      último (2.2 → 2.2.1). El primer número solo cambia cuando el sistema
 *      cambia de cara, y eso lo decide una persona, no una regla.
 *   3. Escríbalo como se lo contaría al dueño del negocio. Nada de «endpoint»,
 *      «migración» ni «índice»: qué gana quien lo usa.
 *
 * No se anota lo que nadie ve: acomodos por dentro, arreglos de textos de
 * documentación o cosas que solo cambian cómo está escrito el programa.
 */

function tipos_mejora(): array { /* … los cuatro tipos … */ }

/** El historial, de lo más reciente a lo más antiguo. */
function mejoras(): array
{
    return [
        /* … las entradas … */
    ];
}

/** La versión del sistema es la de la entrada más reciente. */
function version_actual(): string
{
    return mejoras()[0]['version'] ?? '1.0';
}

/** Cuántas mejoras hay de cada tipo, para las etiquetas de arriba. */
function cuenta_mejoras(): array
{
    $n = [];
    foreach (mejoras() as $m) {
        $n[$m['tipo']] = ($n[$m['tipo']] ?? 0) + 1;
    }
    return $n;
}

/** Agrupa por mes, conservando el orden, para los títulos de la línea de tiempo. */
function mejoras_por_mes(): array
{
    $meses = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
              '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
              '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];
    $grupos = [];
    foreach (mejoras() as $m) {
        [$anio, $mes] = explode('-', $m['fecha']);
        $clave = $anio . '-' . $mes;
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = ['rotulo' => $meses[$mes] . ' de ' . $anio, 'mejoras' => []];
        }
        $grupos[$clave]['mejoras'][] = $m;
    }
    return $grupos;
}

/** «6 de septiembre de 2026», que es como lo lee una persona. */
function fecha_mejora(string $iso): string
{
    $meses = ['01' => 'enero', '02' => 'febrero', /* … */];
    [$anio, $mes, $dia] = explode('-', $iso);
    return (int) $dia . ' de ' . $meses[$mes] . ' de ' . $anio;
}
```

**`mejoras_por_mes()` no ordena nada**: recorre el array tal como está y va
abriendo grupos. Ordenar por fecha sería redundante —el array ya está en orden—
y además tapa el error de haber metido una entrada en el sitio equivocado, que
es justo lo que se quiere ver.

**El `?? '1.0'`** es para que el sistema arranque aunque el historial esté vacío
el primer día. Sin él, un array vacío tumba la aplicación en la definición de la
constante, que es lo primero que corre.

---

## Dónde sale el número

Una sola constante, definida donde se define todo lo demás de la marca:

```php
// La versión no se escribe a mano: sale de la mejora más reciente
// anotada en lib/mejoras.php. Escrito en dos sitios acababa diciendo una cosa
// en el menú y otra en la pantalla de Mejoras.
require_once __DIR__ . '/mejoras.php';
define('APP_VERSION', version_actual());
```

Y de ahí a cuatro sitios, ninguno con el número escrito a mano:

| Dónde | Para qué |
|---|---|
| El pie del menú, **como enlace a Mejoras** | Es el camino de entrada: se ve el número, se hace clic, se lee qué trae. |
| La pantalla de acceso | Se ve antes de entrar, que es cuando se llama a soporte. |
| Propiedades del XLSX exportado (`docProps/app.xml`) | Un archivo que anda por ahí dice con qué versión salió. |
| Cabecera `User-Agent` de las llamadas salientes | Quien recibe la petición sabe qué versión la hizo. |

Que el número del menú **sea un enlace** es lo que convierte el versionado en
producto y no en trámite. Vale una regla de CSS propia:

```css
/* El número de versión del pie es un enlace a Mejoras. Necesita regla propia
   porque el pie del menú pinta sus enlaces en rojo al pasar por encima, que es
   el gesto de «cerrar sesión» y aquí diría otra cosa. */
.lateral-pie .credito-version{display:block;color:var(--tenue);margin:0 -5px 2px;padding:3px 5px;
  border-radius:var(--r-sm);transition:background var(--t),color var(--t)}
.lateral-pie .credito-version:hover{color:var(--suave);background:var(--fila)}
.lateral-pie .credito-version.on{color:var(--suave);background:var(--fila)}
.lateral-pie .credito-version:hover b,.lateral-pie .credito-version.on b{color:var(--oro-claro)}
```

---

## La pantalla

No consulta la base ni recibe formularios: lee el array y lo dibuja.

```php
<?php
/**
 * Mejoras: qué ha ido recibiendo el sistema, en orden, y en qué versión va.
 */
exigir_login();

$grupos = mejoras_por_mes();
$tipos  = tipos_mejora();
$cuenta = cuenta_mejoras();
$total  = count(mejoras());

encabezado_html('Mejoras', 'mejoras',
    $total . ' mejoras desde que arrancó · versión ' . APP_VERSION);
?>

<div class="mejoras-cabecera">
  <div class="mejoras-etiquetas">
    <span class="etq mejoras-total">Todas: <b><?= $total ?></b></span>
    <?php foreach ($tipos as $clave => $t): ?>
      <?php if (!empty($cuenta[$clave])): ?>
        <span class="etq"><i style="background:<?= e($t['color']) ?>"></i>
          <?= e($t['rotulo']) ?>: <b><?= (int) $cuenta[$clave] ?></b></span>
      <?php endif ?>
    <?php endforeach ?>
  </div>
  <div class="mejoras-version">
    <div class="rotulo">Versión de hoy</div>
    <div class="valor">v<?= e(APP_VERSION) ?></div>
  </div>
</div>

<details class="mejoras-que-es">
  <summary>¿Qué quiere decir ese número?</summary>
  <div>
    <p>El número tiene dos partes, por ejemplo <b>v<?= e(APP_VERSION) ?></b>:</p>
    <ul>
      <li>La <b>primera</b> cambia cuando el sistema cambia de cara. Ha pasado una vez.</li>
      <li>La <b>segunda</b> sube cada vez que el sistema aprende a hacer algo que antes no hacía.</li>
    </ul>
    <p>Sirve para una cosa muy concreta: si algo se ve distinto a como se lo explicaron,
       este número dice qué versión está usando.</p>
  </div>
</details>

<?php foreach ($grupos as $grupo): ?>
  <h2 class="mejoras-mes"><?= e($grupo['rotulo']) ?></h2>
  <div class="mejoras">
    <?php foreach ($grupo['mejoras'] as $m): ?>
      <?php $t = $tipos[$m['tipo']] ?? $tipos['mejora']; ?>
      <article class="mejora">
        <span class="mejora-punto" style="background:<?= e($t['color']) ?>"></span>
        <div class="mejora-alto">
          <span class="etq"><i style="background:<?= e($t['color']) ?>"></i><?= e($t['rotulo']) ?></span>
          <time><?= e(fecha_mejora($m['fecha'])) ?></time>
          <span class="mejora-version">v<?= e($m['version']) ?></span>
        </div>
        <h3><?= e($m['titulo']) ?></h3>
        <p><?= e($m['resumen']) ?></p>
        <?php if (!empty($m['detalles'])): ?>
          <ul>
            <?php foreach ($m['detalles'] as $d): ?>
              <li><?= e($d) ?></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </article>
    <?php endforeach ?>
  </div>
<?php endforeach ?>

<p class="mejoras-pie">
  ¿Le falta algo o se le ocurre cómo mejorarlo? Dígaselo al <b>soporte</b>.
  Casi todo lo de esta lista empezó con alguien que lo pidió.
</p>
```

Tres detalles que hacen el trabajo:

- **`$tipos[$m['tipo']] ?? $tipos['mejora']`.** Un tipo mal escrito no rompe la
  pantalla, cae en «Mejora». Un historial no puede tumbar la aplicación por una
  errata.
- **Las etiquetas de arriba solo salen si hay de ese tipo** (`!empty($cuenta[…])`).
  Un contador en cero es ruido.
- **El pie invita a pedir.** «Casi todo lo de esta lista empezó con alguien que
  lo pidió» convierte una pantalla de lectura en un canal de entrada. Funciona:
  es de donde salen la mitad de las peticiones.

---

## Los estilos

Una línea de tiempo, no una tabla. Aquí no se compara nada, se lee de arriba
abajo.

```css
/* ---------- Mejoras ----------
   Una línea de tiempo, no una tabla: aquí no se compara, se lee de arriba
   abajo. El punto de color vive sobre la línea y por eso la tarjeta va
   desplazada; en pantalla estrecha la línea y el punto desaparecen. */
.mejoras-cabecera{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;
  flex-wrap:wrap;margin-bottom:14px}
.mejoras-etiquetas{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.etq.mejoras-total{background:var(--panel-3);color:var(--texto);border-color:var(--linea-fuerte)}
.mejoras-version{background:var(--panel);border:1px solid var(--linea-fuerte);border-radius:var(--r);
  padding:11px 18px;text-align:right;flex:none}
.mejoras-version .rotulo{font-size:0.6562rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mudo)}
.mejoras-version .valor{font-family:var(--mono);font-size:1.5rem;line-height:1.15;margin-top:3px;
  background:var(--oro-texto);-webkit-background-clip:text;background-clip:text;color:transparent}

.mejoras-que-es{background:var(--panel);border:1px solid var(--linea);border-radius:var(--r-sm);
  padding:11px 15px;margin-bottom:26px;font-size:0.8438rem;color:var(--suave)}
.mejoras-que-es summary{cursor:pointer;color:var(--texto);font-size:0.8438rem;user-select:none}
.mejoras-que-es>div{margin-top:9px;line-height:1.6}
.mejoras-que-es p{margin:0 0 7px}
.mejoras-que-es ul{margin:0 0 7px;padding-left:18px}
.mejoras-que-es li{margin-bottom:4px}

.mejoras-mes{font-size:0.6875rem;letter-spacing:.2em;text-transform:uppercase;color:var(--mudo);
  font-weight:500;margin:0 0 14px}
.mejoras{position:relative;margin-bottom:34px}
.mejoras::before{content:"";position:absolute;left:5px;top:10px;bottom:10px;width:1px;
  background:var(--linea)}
.mejora{position:relative;margin-left:30px;margin-bottom:12px;padding:16px 18px;
  background:var(--panel);border:1px solid var(--linea);border-radius:var(--r)}
.mejora-punto{position:absolute;left:-29px;top:22px;width:11px;height:11px;border-radius:50%;
  border:3px solid var(--tinta)}
.mejora-alto{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px}
.mejora-alto time{font-size:0.75rem;color:var(--mudo)}
.mejora-version{font-family:var(--mono);font-size:0.6875rem;color:var(--tenue);
  background:var(--panel-3);border-radius:20px;padding:2px 8px}
.mejora h3{margin:0 0 6px;font-size:1rem;font-weight:600;line-height:1.35;color:var(--texto)}
.mejora p{margin:0;font-size:0.875rem;line-height:1.6;color:var(--suave)}
.mejora ul{margin:11px 0 0;padding-left:17px;font-size:0.8438rem;line-height:1.6;color:var(--suave)}
.mejora li{margin-bottom:5px}
.mejora li::marker{color:var(--tenue)}
.mejoras-pie{color:var(--mudo);font-size:0.8438rem;line-height:1.6;text-align:center;
  border-top:1px solid var(--linea-suave);padding-top:18px;margin:0}

@media (max-width:640px){
  .mejoras::before{display:none}
  .mejora{margin-left:0}
  .mejora-punto{display:none}
  .mejoras-version{width:100%;text-align:left}
}
```

**El punto de color lleva un borde del color del fondo** (`border:3px solid
var(--tinta)`), no un hueco: así se recorta limpio sobre la línea vertical sin
que haya que interrumpirla.

**En pantalla estrecha la línea y el punto desaparecen** y las tarjetas ocupan
todo el ancho. Una línea de tiempo de 30 px de sangría en un teléfono es solo
margen desperdiciado, y el color ya está en la etiqueta.

**El número grande va con el degradado de la marca** recortado sobre el texto
(`background-clip:text`). Es la única cifra de toda la aplicación que se pinta
así, y por eso se ve que es *la* cifra de la pantalla.

---

## Cómo se anota una mejora

El flujo entero, y es corto:

1. Se entrega algo que la gente nota.
2. Se añade la entrada **al principio** del array, con la fecha del día.
3. Se sube el número ahí mismo: `nuevo` → sube el del medio; lo demás → sube el
   último.
4. El commit puede llamarse igual que la entrada: `Versión 2.7.10: el saldo del
   Tesoro iba un día atrasado y el de Bancrecer venía del futuro`.

**Lo que no se anota**, y esto es la mitad del valor de la pantalla:

- Acomodos internos, cambios de nombre, reorganizar archivos.
- Documentación, comentarios, estilo del código.
- Rendimiento que nadie percibe. *(Si una pantalla pasa de 18 s a 0,8 s, eso sí
  se nota y sí va: la prueba es si alguien lo diría en voz alta.)*
- Cambios que se revirtieron el mismo día.

Todo eso vive en el git log, que es donde tiene que estar. **La regla para
decidir**: ¿alguien del equipo notaría la diferencia sin que se lo cuenten? Si
la respuesta es no, va al git log y la versión no se mueve.

---

## Cómo se escriben las entradas

Mismas reglas que la visita guiada —ver [`visita-guiada.md`](visita-guiada.md)—
y tres propias del historial:

**El título dice qué pasó, no qué se tocó.**

> ✗ «Refactor de `saldo_de_cierre()`»
> ✓ «El saldo del Tesoro y el de Bancrecer también estaban mal»

**En una corrección, cuente primero qué se veía mal.** Quien la lee llegó ahí
porque vio el número malo. Empezar por el síntoma es lo que hace que reconozca
su problema en la primera línea:

> «Decía "tiene 4 cuentas del mismo banco" como si eso fuera un problema, y no
> lo es: una empresa puede tener cuatro cuentas en el mismo banco. Encima no
> decía cuáles, y en las dos listas salía la misma. Ahora nombra las parejas que
> de verdad podrían ser la misma cuenta y explica por qué lo parecen.»

**Ponga las cifras.** «El Tesoro pasó de 715.244,64 a 10.480.610,14» convence;
«se corrigió el cálculo del saldo» no. La cifra es lo que permite comprobarlo
sin preguntar.

**Trato de usted, y en el idioma de quien lo usa.** Aquí también: «Su trabajo
del semestre ya está adentro», no «Tu trabajo».

---

## Trampas que ya se pagaron

**Nunca inserte una entrada en el medio.** El array es la historia, y su primera
posición es la versión de hoy. Una entrada metida en el medio con un número más
alto no cambia nada visible —el sistema sigue diciendo la versión de la
primera— pero deja el historial mintiendo. Si hay que anotar algo viejo que se
olvidó, va en su sitio cronológico **con la versión que tenía entonces**.

**Los textos se escapan con `e()`.** Al revés que en la visita guiada, aquí
**no** se admite HTML: nada de `<b>` en el resumen. Es un historial largo y
escrito deprisa, y un `<` sin cerrar en una entrada rompería la pantalla entera.
Si necesita énfasis, use comillas.

**Las fechas van absolutas, siempre.** `'2026-09-08'`, nunca «ayer» ni «esta
semana». El historial se lee meses después.

**Un cambio de esquema de base de datos no es una mejora**, pero casi siempre
viene con una. La mejora es lo que la persona nota; la migración es cómo se
hizo. Si el cambio de esquema no trajo nada visible, no hay entrada — y ojo, en
CONCIL eso además exige subir `ESQUEMA_VERSION`, que es otro número y otra cosa:
no los confunda.

**Si el archivo se hace enorme, no lo parta en dos.** Diez años de entradas
siguen siendo un array; lo que pesa es cargarlo en cada petición, y para eso ya
está el caché de código del servidor. Partirlo trae de vuelta el problema que
todo esto vino a resolver: dos sitios donde anotar lo mismo.

---

## Llevarlo a otro stack

Lo único específico de PHP es la sintaxis del array. La idea se traslada tal
cual.

### Node / JavaScript

```js
// mejoras.js — única fuente de la versión y del historial.
export const MEJORAS = [
  { fecha: '2026-09-09', version: '2.7.10', tipo: 'correccion',
    titulo: '…', resumen: '…', detalles: ['…'] },
];
export const VERSION = MEJORAS[0]?.version ?? '1.0';
```

Y si el proyecto necesita además que `package.json` diga la verdad, se genera
—nunca se escribe a mano— en el guion de construcción:

```js
// scripts/sincronizar-version.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { VERSION } from '../src/mejoras.js';
const pkg = JSON.parse(readFileSync('package.json', 'utf8'));
if (pkg.version !== VERSION) {
  pkg.version = VERSION;
  writeFileSync('package.json', JSON.stringify(pkg, null, 2) + '\n');
  console.log('package.json actualizado a ' + VERSION);
}
```

Póngalo en un `prebuild` o en un *hook* de pre-commit y la contradicción se
vuelve imposible por construcción, que es todo el objetivo.

### Si el proyecto sí es una librería con semver de verdad

Entonces la compatibilidad manda y no se puede redefinir mayor/menor. Se
resuelve con **dos lecturas del mismo dato**: cada entrada lleva su `version`
según las reglas de semver clásico, y el campo `tipo` sigue siendo el que decide
cómo se pinta y qué se le cuenta a quien lo usa. La disciplina —una sola fuente,
la entrada al principio, escrita para quien lo usa— no cambia.

### Datos en JSON en vez de en código

Funciona igual con un `mejoras.json` leído al arrancar. Se pierde una cosa: los
comentarios de sección y la cabecera con las instrucciones, que en la práctica
son lo que hace que el archivo se mantenga bien. Si va por JSON, ponga esas
instrucciones en un `README` al lado del archivo.

---

## Lista de comprobación

Antes de dar por buena la implantación:

- [ ] El número **no está escrito a mano** en ningún sitio: sale de la primera
      entrada del historial.
- [ ] Sale en el menú, en la pantalla de acceso y en lo que se exporta.
- [ ] El número del menú es un **enlace** al historial.
- [ ] La pantalla no consulta la base ni recibe formularios.
- [ ] Un `tipo` mal escrito no rompe nada.
- [ ] El historial se lee bien en un teléfono.
- [ ] La cabecera del archivo explica cómo añadir una entrada, y está dentro
      del archivo.
- [ ] Ninguna entrada habla de código: ni «refactor», ni «endpoint», ni
      «índice».
- [ ] Nadie tutea.
- [ ] Al añadir una entrada, subir el número es el mismo gesto — no un paso
      aparte que se pueda olvidar.

---

<div align="center">

*Documentado a partir de la implantación en CONCIL · v2.7.10*
**by VIP Soft**

</div>
