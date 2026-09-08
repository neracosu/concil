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
            'ruta' => 'panel', 'sel' => '[data-guia="sede"]', 'lado' => 'derecha',
            'titulo' => 'Primero, de qué empresa hablamos',
            'texto' => 'Si el grupo tiene varias empresas o tiendas, cada una lleva sus cuentas y sus pagos '
                     . '<b>por separado</b>. Aquí elige con cuál está trabajando, y todo lo que vea a la '
                     . 'derecha será solo de ella.',
            'nota' => 'Los tipos de gasto sí se comparten, así que lo que enseñe en una le sirve a las demás.',
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
                     . 'en proveedores, en nómina o en comisiones. Pase el mouse por encima para ver el monto.',
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
            'texto' => 'Usted no tiene que decirle nada. Mira el archivo por dentro y sabe de qué banco es, '
                     . 'aunque le hayan cambiado el nombre. Si el archivo no coincide con la cuenta elegida, avisa. '
                     . 'Antes de guardar nada, le muestra lo que entendió para que usted confirme.',
        ],

        // ------------------------------------------------------- Por justificar
        [
            'ruta' => 'panel', 'sel' => '[data-guia="repetidos"]',
            'titulo' => 'Que no se pague dos veces lo mismo',
            'texto' => 'Si a una misma persona se le fue el mismo monto dos veces en menos de un mes, '
                     . 'aquí aparece. Pasa cuando <b>dos personas pagan desde bancos distintos</b> y ninguna '
                     . 'sabe lo de la otra.',
            'nota' => 'Que salga no quiere decir que esté mal; quiere decir que vale la pena mirarlo.',
        ],
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
            'ruta' => 'pendientes', 'sel' => '[data-guia="conceptos"]',
            'titulo' => 'Antes de decidir, mire los que son',
            'texto' => 'Si le parecen muchos para resolverlos de un golpe, abra aquí y <b>vea uno por uno</b> '
                     . 'lo que el banco escribió en cada pago, con su fecha y su monto.',
            'nota' => 'Nadie tiene que clasificar a ciegas.',
        ],
        [
            'ruta' => 'pendientes', 'sel' => '[data-guia="factura-nueva"]',
            'titulo' => 'Las facturas de ese pago',
            'texto' => 'Si el pago cubre facturas, anótelas aquí: <b>las que hagan falta</b>, no solo una. '
                     . 'Y si alguna ya estaba pagada, el sistema se lo dice antes de que la pague otra vez.',
            'nota' => 'Las que ya están cubiertas aparecen abajo, para que se vea quién las pagó.',
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
            'ruta' => 'pendientes', 'sel' => '[data-guia="modo"]',
            'titulo' => 'Y de qué factura era cada pago',
            'texto' => 'Al explicar un pago de a uno, debajo aparecen <b>las facturas de ese proveedor '
                     . 'que todavía no están cubiertas</b>. Marque las que cubre este pago y escriba cuánto '
                     . 'va a cada una.',
            'nota' => 'Un pago puede cubrir tres facturas, y una factura puede irse cubriendo con tres pagos. '
                    . 'Las dos cosas se hacen desde la misma pantalla.',
        ],
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
            'ruta' => 'proveedores', 'sel' => '[data-guia="prov-archivo"]',
            'titulo' => 'A quién le compra la empresa',
            'texto' => 'Si contabilidad ya tiene la lista de proveedores en un archivo, súbala aquí y entran '
                     . 'todos de una vez. También puede escribirlos uno a uno.',
            'nota' => 'La lista es la misma para todas las empresas del grupo. Lo que cada una debe, no: '
                    . 'las facturas son de quien las debe.',
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
        [
            'ruta' => 'ajustes', 'sel' => '[data-guia="tasas"]',
            'titulo' => 'A cuánto estaba el dólar ese día',
            'texto' => 'Al lado de cada operación verá la tasa oficial del BCV <b>del día en que se hizo</b>, '
                     . 'no la de hoy. Es la que necesita administración para sacar sus cuentas.',
            'nota' => 'Se busca sola una vez al día. Si algún día faltara, con este botón las trae todas.',
        ],
        [
            'ruta' => 'ajustes', 'sel' => '[data-guia="tasa-mano"]',
            'titulo' => 'Y si ese día se usó otra',
            'texto' => 'Cuando el valor que trajo el sistema no es con el que se trabajó, aquí lo cambia. '
                     . 'Lo que escriba vale para <b>todas las operaciones de ese día</b> y ya no se lo vuelven a pisar.',
            'nota' => 'También puede corregirlo desde el propio pago, sin venir hasta aquí.',
        ],

        [
            'ruta' => 'panel', 'sel' => '[data-guia="cifras"]',
            'titulo' => 'Cuando una operación llega dos veces',
            'texto' => 'Algunos bancos mueven al mes siguiente operaciones de los últimos días del mes. '
                     . 'Cuando eso pasa, la misma operación entra dos veces con dos fechas y los totales '
                     . 'la cuentan doble. El sistema las <b>señala</b> y aparece <b>Repetidos</b> en el menú.',
            'nota' => 'Solo sale cuando hay algo que revisar. Quitar la repetida deja los totales como son.',
        ],
        [
            'ruta' => 'reglas', 'sel' => '[data-guia="traspasos"]',
            'titulo' => 'El dinero que se mueve entre sus cuentas',
            'texto' => 'Cuando pasa plata de una cuenta suya a otra salen <b>dos apuntes</b>: uno que sale '
                     . 'y otro que entra. Con este botón el sistema busca las parejas y las une, para que '
                     . 'no se cuenten como un gasto y un ingreso que no fueron.',
            'nota' => 'Cuando no está seguro no adivina: se lo pregunta a usted en el detalle del pago.',
        ],
        [
            'ruta' => 'ajustes', 'sel' => '[data-guia="tema"]', 'lado' => 'derecha',
            'titulo' => 'Con fondo claro o con fondo oscuro',
            'texto' => 'Aquí elige cómo quiere ver la pantalla. <b>Automático</b> se pone como esté '
                     . 'su computadora; los otros dos mandan siempre.',
            'nota' => 'Lo que elija le sigue a cualquier computadora donde entre.',
        ],
        [
            'ruta' => 'ajustes', 'sel' => '[data-guia="escala"]', 'lado' => 'derecha',
            'titulo' => 'Si la letra le queda chica',
            'texto' => 'Estas tres letras cambian el tamaño de todo: no solo el texto, también los '
                     . 'botones y los renglones del menú. <b>Elija la que le resulte cómoda</b> y '
                     . 'trabaje sin acercarse a la pantalla.',
            'nota' => 'Es cosa de cada quien: cambiarlo no le mueve nada a sus compañeros.',
        ],

        [
            'ruta' => 'panel', 'sel' => '[data-presentes]',
            'titulo' => 'Quién más está trabajando',
            'texto' => 'Aquí aparece quien esté dentro en este momento, y en el menú se enciende un '
                     . '<b>ojito</b> en la pantalla donde está parado. Si alguien está en lo mismo que usted, '
                     . 'su nombre se marca en verde.',
            'nota' => 'Sirve para no hacer dos veces el mismo trabajo sin darse cuenta.',
        ],

        [
            'ruta' => 'panel', 'sel' => '[data-guia="mejoras"]', 'lado' => 'derecha',
            'titulo' => 'Y qué se le ha ido añadiendo',
            'texto' => 'Al final del menú está el número de la versión que usa. <b>Haga clic ahí</b> y verá, '
                     . 'en orden, todo lo que el sistema ha aprendido a hacer desde que arrancó.',
            'nota' => 'Casi todo lo que hay en esa lista lo pidió alguien del equipo. Si le falta algo, pídalo.',
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
        'proveedores' => 'A quién le compra la empresa. <b>La lista la ven todas las unidades</b>, así se puede '
                       . 'saber cuánto le pagó el grupo entero a alguien. Lo que se le debe sí es de cada una.',
        'proveedor'   => 'Todo lo de este proveedor en esta unidad: <b>sus facturas, cuánto queda por cubrir '
                       . 'de cada una y con qué pagos se cubrieron</b>.',
        'cuentas'     => 'Sus cuentas de banco y <b>cuánto tiene en cada una</b>.',
        'sede'        => 'Cada empresa o tienda del grupo lleva sus cuentas y sus movimientos <b>por separado</b>. '
                       . 'Arriba a la izquierda elige con cuál está trabajando. Las categorías y las reglas '
                       . 'son las mismas para todas, así lo aprendido en una sirve en las demás.',
        'ajustes'     => 'Su clave de entrada y el estado del sistema.',
        'usuarios'    => 'Quién puede entrar y <b>qué está haciendo cada quien ahora mismo</b>. '
                       . 'Cada persona entra con sus propios seis dígitos, así todo lo que se hace queda a su nombre.',
        'persona'     => 'Todo lo de esta persona: <b>desde dónde entra, con qué trabaja y todo lo que '
                       . 'ha hecho</b>, de lo más reciente a lo más antiguo. Si aquí ve una conexión que no '
                       . 'reconoce, es señal de que alguien más está usando su clave.',
        'auditoria'   => 'Todo lo que ha pasado en el sistema, paso a paso: <b>quién, cuándo, desde qué '
                       . 'computadora y desde qué conexión</b>. Es para el día que haya que revisar algo a fondo; '
                       . 'para el día a día basta con la pantalla de Usuarios.',
        'mejoras'     => 'Todo lo que el sistema ha ido aprendiendo a hacer, desde que arrancó hasta hoy. '
                       . '<b>Lo más reciente, arriba.</b>',
    ][$ruta] ?? '';
}
