<?php
/**
 * Visita guiada: un solo recorrido continuo que va llevando a la persona
 * por todas las secciones y señala para qué sirve cada una.
 *
 * Los textos están escritos para alguien que no trabaja con sistemas:
 * sin jerga, frases cortas, y siempre diciendo qué gana la persona.
 *
 * Cada paso lleva:
 *   ruta   → sección donde ocurre (la guía navega sola hasta allí)
 *   sel    → qué se resalta en pantalla; vacío = tarjeta centrada
 *   titulo → una frase, no una etiqueta
 *   texto  → dos o tres líneas como máximo
 */

function guia_pasos(): array
{
    return [
        // ---------------------------------------------------------- Apertura
        [
            'ruta' => 'panel', 'sel' => '',
            'titulo' => 'Bienvenido a CONCIL',
            'texto' => 'Cada mes salen cientos de pagos de las cuentas. El banco los entrega en un archivo, '
                     . 'pero ese archivo no dice <b>para qué</b> fue cada pago. '
                     . 'CONCIL responde esa pregunta: <b>¿en qué se fue el dinero?</b>',
            'nota' => 'La visita dura un minuto. Puede salirse cuando quiera y volver a verla después.',
        ],
        [
            'ruta' => 'panel', 'sel' => '.nav', 'lado' => 'derecha',
            'titulo' => 'Todo está en este menú',
            'texto' => 'Arriba, lo del día a día: <b>subir los archivos</b>, <b>explicar los pagos</b> y <b>consultar</b>. '
                     . 'Abajo, cosas que se configuran una vez y casi no se tocan.',
        ],

        // ------------------------------------------------------------- Panel
        [
            'ruta' => 'panel', 'sel' => '[data-guia="cifras"]',
            'titulo' => 'El resumen del mes, apenas entra',
            'texto' => 'Cuánto salió, qué parte ya está explicada y cuánto falta. '
                     . 'Si el número amarillo está en cero, no tiene nada pendiente.',
        ],
        [
            'ruta' => 'panel', 'sel' => '[data-guia="cinta"]',
            'titulo' => 'En qué se fue el dinero, de un vistazo',
            'texto' => 'Cada color es un tipo de gasto y el ancho es cuánto pesó. Así ve si el mes se le fue '
                     . 'en proveedores, en nómina o en comisiones. Pase el ratón por encima para ver el monto.',
        ],
        [
            'ruta' => 'panel', 'sel' => '[data-guia="saldos"]',
            'titulo' => 'Cuánto tiene en cada banco',
            'texto' => 'Lo que entró, lo que salió y con cuánto quedó cada cuenta.',
            'nota' => 'Si dice «falta saldo inicial», hay que cargarle el saldo de arranque una sola vez. Se lo muestro al final.',
        ],

        // ------------------------------------------------------------ Cargar
        [
            'ruta' => 'carga', 'sel' => '[data-guia="soltar"]',
            'titulo' => 'Aquí empieza todo: suelte los archivos',
            'texto' => 'Descarga el movimiento de cada banco como siempre, y lo arrastra hasta aquí. '
                     . 'Puede soltar los de <b>todos los bancos de una vez</b>.',
            'nota' => 'Si sube dos veces el mismo archivo no pasa nada: reconoce lo que ya tenía y no lo repite.',
        ],
        [
            'ruta' => 'carga', 'sel' => '[data-guia="formatos"]',
            'titulo' => 'Sabe solo de qué banco es cada archivo',
            'texto' => 'Usted no tiene que decirle nada. Reconoce estos cuatro bancos y a qué cuenta pertenece cada archivo. '
                     . 'Antes de guardar nada, le muestra lo que entendió para que usted confirme.',
        ],

        // ------------------------------------------------------- Por justificar
        [
            'ruta' => 'pendientes', 'sel' => '[data-guia="modo"]',
            'titulo' => 'Lo único que le va a pedir',
            'texto' => 'Las comisiones, los impuestos y los servicios se clasifican solos. Aquí queda lo que el sistema '
                     . 'no puede adivinar: las transferencias, donde solo usted sabe el motivo.',
        ],
        [
            'ruta' => 'pendientes', 'sel' => '[data-guia="grupo"]',
            'titulo' => 'Un renglón puede ser veinte pagos',
            'texto' => 'Los pagos parecidos vienen juntos. Arriba ve cuántos son y cuánto suman; abajo elige de qué se trata. '
                     . 'Al guardar, <b>se resuelven todos juntos</b>.',
        ],
        [
            'ruta' => 'pendientes', 'sel' => '[data-guia="regla"]',
            'titulo' => 'Esta casilla es la más importante',
            'texto' => 'Con ella marcada, el sistema <b>aprende</b>. Ese tipo de pago llegará ya clasificado el mes que viene '
                     . 'y no se lo volverá a preguntar nunca.',
            'nota' => 'Mientras más lo use, menos trabajo le da.',
        ],

        // ------------------------------------------------------- Movimientos
        [
            'ruta' => 'movimientos', 'sel' => '[data-guia="filtros"]',
            'titulo' => 'Aquí busca cualquier cosa',
            'texto' => 'Por fechas, por banco, por tipo de gasto, por monto o escribiendo un nombre. '
                     . 'Puede combinarlos todos a la vez.',
        ],
        [
            'ruta' => 'movimientos', 'sel' => '[data-guia="exportar"]',
            'titulo' => 'Y lo baja a Excel con un botón',
            'texto' => 'Se baja <b>exactamente lo que está viendo</b>. Si filtró solo los pagos de luz, eso es lo que baja.',
        ],
        [
            'ruta' => 'movimientos', 'sel' => '[data-guia="tabla"]',
            'titulo' => 'Si algo quedó mal, se corrige aquí',
            'texto' => 'Haga clic en la fecha o en el texto de cualquier renglón. Podrá cambiarle el tipo de gasto, '
                     . 'poner a quién se le pagó y escribir el motivo.',
        ],

        // ---------------------------------------------------------- Reportes
        [
            'ruta' => 'reportes', 'sel' => '[data-guia="corte"]',
            'titulo' => 'El resumen para llevar a una reunión',
            'texto' => 'La misma plata vista de cinco maneras: por tipo de gasto, por a quién se le pagó, '
                     . 'por banco, por mes o por área.',
        ],
        [
            'ruta' => 'reportes', 'sel' => '[data-guia="tabla"]',
            'titulo' => 'Siempre de lo más grande a lo más pequeño',
            'texto' => 'Lo importante queda arriba. El botón del final le abre los pagos que forman ese total, '
                     . 'por si quiere revisar de dónde sale la cifra.',
        ],

        // ------------------------------------------------------------ Reglas
        [
            'ruta' => 'reglas', 'sel' => '[data-guia="sugerencias"]',
            'titulo' => 'Un atajo para el primer día',
            'texto' => 'Son las notas que ustedes ya escribían a mano en los archivos de Excel. '
                     . 'Póngale a cada una su tipo de gasto y quedan resueltos <b>cientos de pagos de golpe</b>.',
        ],
        [
            'ruta' => 'reglas', 'sel' => '[data-guia="listareglas"]',
            'titulo' => 'Todo lo que el sistema ya sabe',
            'texto' => 'Cada renglón es algo que aprendió. «Aciertos» es cuántos pagos le ha resuelto. '
                     . 'Si alguna clasifica mal, se puede pausar sin borrarla.',
        ],

        // ------------------------------------------------ Ajustes de una vez
        [
            'ruta' => 'categorias', 'sel' => '[data-guia="lista"]',
            'titulo' => 'Los tipos de gasto de la empresa',
            'texto' => 'Es la lista con la que se explica cada salida. Ya vienen los más comunes; '
                     . 'agregue los suyos cuando le hagan falta.',
        ],
        [
            'ruta' => 'cuentas', 'sel' => '[data-guia="saldoinicial"]',
            'titulo' => 'Esto sí conviene hacerlo hoy',
            'texto' => 'Algunos bancos no mandan el saldo en su archivo. Escriba aquí cuánto tenía la cuenta '
                     . 'el día antes del primer movimiento cargado. <b>Se hace una sola vez</b> y desde ahí el saldo sale bien siempre.',
        ],
        [
            'ruta' => 'ajustes', 'sel' => '[data-guia="pin"]',
            'titulo' => 'Y cambie su clave apenas termine',
            'texto' => 'Son seis números, los que usted quiera. No la comparta por escrito.',
        ],

        // ------------------------------------------------------------ Cierre
        [
            'ruta' => 'panel', 'sel' => '',
            'titulo' => 'Eso es todo. Su día a día son tres pasos',
            'texto' => '<b>1.</b> Sube los archivos del banco. &nbsp; <b>2.</b> Explica lo poco que quedó pendiente. '
                     . '&nbsp; <b>3.</b> Saca el reporte cuando lo necesite.',
            'nota' => 'Puede repetir esta visita cuando quiera, con el botón «Visita guiada» del menú. '
                    . 'CONCIL <i>by</i> VIP Soft.',
        ],
    ];
}

/** Frase de ayuda fija bajo el título de cada pantalla. */
function ayuda_pantalla(string $ruta): string
{
    return [
        'panel'       => 'Esta es la foto del mes: <b>cuánto salió y en qué se fue</b>. Haga clic en cualquier renglón para ver los pagos que lo componen.',
        'carga'       => 'Suelte aquí los archivos que le manda el banco. <b>Puede subir varios de una vez</b>, y si repite un archivo no se duplica nada.',
        'pendientes'  => 'Estos son los pagos que el sistema <b>no puede adivinar solo</b>. Explique de qué se trata cada grupo y no se lo volverá a preguntar.',
        'movimientos' => 'La lista completa de todo lo que pasó por las cuentas. <b>Filtre lo que necesite y bájelo a Excel.</b>',
        'reportes'    => 'El resumen de <b>cuánto se gastó en cada cosa</b>. Cambie el agrupamiento para ver la misma plata desde otro ángulo.',
        'reglas'      => 'Aquí está <b>todo lo que el sistema ya aprendió</b>. Lo que usted explica una vez queda guardado y se aplica solo de ahora en adelante.',
        'categorias'  => 'Los <b>tipos de gasto</b> con los que se explica cada salida de dinero.',
        'cuentas'     => 'Sus cuentas de banco y <b>cuánto tiene en cada una</b>.',
        'ajustes'     => 'Su clave de entrada y el estado del sistema.',
    ][$ruta] ?? '';
}
