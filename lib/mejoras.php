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

/** Tipos de mejora, con el rótulo que ve la persona y su color. */
function tipos_mejora(): array
{
    return [
        'nuevo'      => ['rotulo' => 'Nuevo',      'color' => 'var(--entrada)'],
        'mejora'     => ['rotulo' => 'Mejora',     'color' => 'var(--cian)'],
        'correccion' => ['rotulo' => 'Corrección', 'color' => 'var(--pendiente)'],
        'proteccion' => ['rotulo' => 'Protección', 'color' => 'var(--violeta)'],
    ];
}

/**
 * El historial, de lo más reciente a lo más antiguo.
 * Las versiones anteriores a la 1.5 se reconstruyeron el 07/09/2026 a partir
 * de lo que se fue entregando cada día.
 */
function mejoras(): array
{
    return [
        [
            'fecha' => '2026-09-07', 'version' => '2.2.1', 'tipo' => 'correccion',
            'titulo' => 'La visita guiada con fondo claro ya deja ver el resto de la pantalla',
            'resumen' => 'Con el fondo claro, la visita guiada tapaba todo lo que no estaba explicando: '
                       . 'no se veía sobre qué parte del sistema le estaban hablando. Ahora el resto se '
                       . 'apaga, pero se sigue viendo.',
            'detalles' => [
                'Lo señalado se ve con su color de siempre; lo demás, atenuado.',
            ],
        ],
        [
            'fecha' => '2026-09-07', 'version' => '2.2', 'tipo' => 'nuevo',
            'titulo' => 'Esta misma pantalla: saber qué se ha hecho',
            'resumen' => 'Ahora puede ver, sin preguntarle a nadie, todo lo que el sistema '
                       . 'ha ido recibiendo desde que arrancó y en qué versión va.',
            'detalles' => [
                'Cada mejora dice qué día entró y qué cambia para usted.',
                'El número de versión del menú ya no es un dato suelto: aquí abajo está lo que trae.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '2.1', 'tipo' => 'nuevo',
            'titulo' => 'Pasar dinero de una cuenta suya a otra deja de contarse dos veces',
            'resumen' => 'Cuando mueve dinero entre dos cuentas del grupo, antes salían dos apuntes sueltos '
                       . 'y parecían un gasto y un ingreso. Ahora el sistema los reconoce como lo que son: '
                       . 'el mismo dinero cambiándose de lugar.',
            'detalles' => [
                'Solo une lo que no deja lugar a dudas: mismo monto, cuentas distintas y tres días de margen.',
                'Si hay más de un candidato posible, se lo pregunta en vez de adivinar.',
                'Siempre puede soltar una pareja mal unida.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '2.0', 'tipo' => 'mejora',
            'titulo' => 'Modo claro, además del oscuro',
            'resumen' => 'El sistema era solo oscuro. Ahora cada quien elige cómo lo quiere ver, '
                       . 'y su elección se recuerda la próxima vez que entre.',
            'detalles' => [
                'Tres opciones en el menú: automático, claro y oscuro.',
                '«Automático» sigue lo que tenga puesto su computadora.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '1.9', 'tipo' => 'mejora',
            'titulo' => 'La factura ya pagada deja de esconderse',
            'resumen' => 'Antes, en cuanto alguien pagaba una factura, esa factura desaparecía de la pantalla '
                       . 'de los demás. La siguiente persona no la veía, la anotaba otra vez y la volvía a pagar. '
                       . 'Ahora se siguen viendo, y dicen quién las pagó y desde qué banco.',
            'detalles' => [
                'Un apartado «Ya pagadas» con la fecha, la cuenta y el nombre de quien la anotó.',
                'El sistema reconoce que «0001», «1» y «F-0001» son la misma factura.',
                'Un solo pago puede repartirse entre varias facturas de una sola vez.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '1.8', 'tipo' => 'nuevo',
            'titulo' => 'Aviso cuando a un proveedor se le repite el mismo monto',
            'resumen' => 'Si a un mismo proveedor se le paga dos veces la misma cantidad en poco tiempo, '
                       . 'el sistema lo dice en voz alta. Es la forma de atajar un pago hecho dos veces '
                       . 'por dos personas distintas.',
            'detalles' => [
                'Salta con el monto exacto, el mismo proveedor y treinta días de margen.',
                'Mira todas las cuentas, no solo aquella en la que está trabajando.',
                'Aparece al justificar, en la ficha del proveedor y en el panel.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '1.7', 'tipo' => 'nuevo',
            'titulo' => 'Corregir a mano la tasa del dólar de un día',
            'resumen' => 'Si la tasa que bajó el sistema no es la que corresponde, ahora se puede escribir '
                       . 'la correcta. Vale para todo ese día y no la pisa la próxima actualización.',
            'detalles' => [
                'Se corrige desde Ajustes o desde el propio pago.',
                'Queda anotado quién la escribió: una tasa puesta a mano es la palabra de alguien.',
                'Lo que ya se repartió con la tasa anterior no se mueve.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '1.6', 'tipo' => 'mejora',
            'titulo' => 'Ver qué hay dentro de un grupo antes de explicarlo entero',
            'resumen' => 'Cuando el sistema propone explicar ocho pagos de una vez, ahora puede abrir el grupo '
                       . 'y ver los ocho antes de decidir. Antes había que fiarse.',
            'detalles' => [
                'Muestra fecha, cuenta, lo que dice el banco, la referencia y el monto de cada uno.',
            ],
        ],
        [
            'fecha' => '2026-09-06', 'version' => '1.5', 'tipo' => 'nuevo',
            'titulo' => 'El reporte dice quién explicó cada pago',
            'resumen' => 'Hasta ahora se sabía que un pago se había explicado a mano, pero no quién lo hizo. '
                       . 'Ahora el nombre aparece en la lista, en el detalle y en el archivo que se baja a Excel.',
            'detalles' => [
                'Se guarda de aquí en adelante; lo anterior se queda en blanco antes que inventar un nombre.',
                'Si lo explicó el sistema por su cuenta, no se le atribuye a nadie.',
            ],
        ],
        [
            'fecha' => '2026-09-03', 'version' => '1.4', 'tipo' => 'nuevo',
            'titulo' => 'De qué factura era cada pago',
            'resumen' => 'Lo que pidió auditoría: poder decir, pago por pago, a qué proveedor fue y qué facturas '
                       . 'cubrió. Una factura puede pagarse en partes y un pago puede cubrir varias.',
            'detalles' => [
                'Pantalla de proveedores, con alta a mano o cargando el listado del sistema contable.',
                'Se cargaron 136 proveedores del listado que pasó contabilidad.',
                'Contempla retenciones y notas de crédito: retener no es dejar de pagar.',
                'Una factura en dólares pagada en bolívares congela la tasa del día del pago.',
            ],
        ],
        [
            'fecha' => '2026-09-03', 'version' => '1.3', 'tipo' => 'nuevo',
            'titulo' => 'La tasa del dólar del día, junto a cada operación',
            'resumen' => 'Cada pago se puede ver también en dólares, con la tasa del Banco Central del día '
                       . 'en que ocurrió, no la de hoy.',
            'detalles' => [
                'Se guarda una tasa por día de calendario, fines de semana incluidos.',
            ],
        ],
        [
            'fecha' => '2026-08-31', 'version' => '1.2', 'tipo' => 'nuevo',
            'titulo' => 'Varias empresas, varias personas y todo en hora de Venezuela',
            'resumen' => 'La segunda tanda del mismo día: el sistema deja de ser de una sola empresa '
                       . 'y de una sola persona.',
            'detalles' => [
                'Cada empresa o tienda del grupo lleva sus cuentas y sus pagos por separado.',
                'Al entrar se elige con cuál se va a trabajar, y solo se ve lo de ella.',
                'Cada quien entra con su propio PIN y queda constancia de lo que hizo.',
                'Se ve quién está trabajando en el sistema en este momento.',
                'Todas las horas y las fechas, en hora de Venezuela.',
                'Cuando algo falla, queda constancia sola y la persona recibe un código para reportarlo.',
            ],
        ],
        [
            'fecha' => '2026-08-31', 'version' => '1.1', 'tipo' => 'nuevo',
            'titulo' => 'Los archivos entran tal como los manda el banco',
            'resumen' => 'Ya no hay que preparar nada. El sistema reconoce el archivo de los once bancos '
                       . 'por cómo está armado por dentro, aunque contabilidad le cambie el nombre.',
            'detalles' => [
                'Los once bancos, incluidos los que no dicen de quién es la cuenta.',
                'Aprende solo: el mes siguiente ese mismo archivo ya no hay que deducirlo.',
                'Avisa si el archivo no es de la cuenta que eligió, comparando el número de cuenta.',
                'Reconoce las comisiones del banco, que cada uno cobra a su manera.',
                'Explicar un pago se hace en la misma fila, sin cambiar de pantalla.',
            ],
        ],
        [
            'fecha' => '2026-08-28', 'version' => '1.0', 'tipo' => 'nuevo',
            'titulo' => 'Arranca CONCIL',
            'resumen' => 'La primera versión en uso. El banco entrega un archivo con cientos de salidas de dinero '
                       . 'que no dice para qué fue cada una; CONCIL responde esa pregunta.',
            'detalles' => [
                'Se sube el archivo del banco y el sistema explica solo lo que ya conoce.',
                'Lo que no puede adivinar lo pregunta agrupado, para resolver muchos de una vez.',
                'Lo que usted explica una vez queda aprendido para siempre.',
                'Reporte de en qué se fue el dinero, y todo se baja a Excel.',
            ],
        ],
    ];
}

/** La versión del sistema es la de la última mejora anotada. */
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

/**
 * Agrupadas por mes, en el orden en que se van a dibujar.
 * El mes se arma con la fecha en claro y no con strftime(), que ya no existe.
 */
function mejoras_por_mes(): array
{
    $meses = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
              '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
              '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
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
    $meses = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
              '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
              '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
    [$anio, $mes, $dia] = explode('-', $iso);
    return (int) $dia . ' de ' . $meses[$mes] . ' de ' . $anio;
}
