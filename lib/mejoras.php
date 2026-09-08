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
            'fecha' => '2026-09-08', 'version' => '2.7.4', 'tipo' => 'mejora',
            'titulo' => 'El sistema arregla lo que se escribe mal, y le dice qué arregló',
            'resumen' => 'Al escribir el nombre de una categoría, de una cuenta o de una unidad de '
                       . 'negocio, el sistema corrige los acentos que se pierden al teclear en '
                       . 'mayúsculas y las erratas de siempre. Y no lo hace callado: le dice qué le '
                       . 'cambió, para que usted vea lo que quedó guardado y pueda volver atrás.',
            'detalles' => [
                'Escriba «GASTOS DE COMISION Y NOMINA» y queda «Gastos de Comisión y Nómina», '
                . 'con el aviso de qué se corrigió.',
                'Las siglas se respetan: AMK, BNC, POS, IVSS y las demás no se tocan.',
                'Si usted ya lo escribió bien, no se le cambia nada.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.7.3', 'tipo' => 'mejora',
            'titulo' => 'El sistema le habla de usted en todas las pantallas',
            'resumen' => 'Unas pantallas trataban de usted y otras tuteaban, a veces en el mismo '
                       . 'renglón: la de cargar extractos decía «Arrastra los archivos» arriba y '
                       . '«Arrástrelo hasta este recuadro» dos líneas más abajo. Ya no: el trato es '
                       . 'el mismo de la pantalla de acceso a la última ayuda.',
            'detalles' => [
                'Son 21 textos en once pantallas, incluidas la de acceso y la visita guiada.',
                'No cambia nada de lo que el sistema hace: solo cómo se lo dice.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.7.2', 'tipo' => 'correccion',
            'titulo' => 'Al pasar de página ya no se repite ni se pierde nada',
            'resumen' => 'Cuando dos grupos de pagos sumaban exactamente lo mismo, o dos proveedores se '
                       . 'llamaban igual, no había nada que decidiera cuál iba primero. Al pasar a la '
                       . 'página siguiente, uno podía volver a salir y otro no aparecer nunca. Ahora el '
                       . 'orden es siempre el mismo, y lo que hay en la lista se ve completo.',
            'detalles' => [
                'Pasaba en «Por justificar» cuando se ve por grupos, y en el listado de proveedores.',
                'Si alguna vez le cuadró mal un conteo revisando por páginas, podía ser esto.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.7.1', 'tipo' => 'correccion',
            'titulo' => 'El reporte estaba juntando renglones que no van juntos',
            'resumen' => 'La pantalla de Reportes agrupaba mal: en vez de sumar por lo que usted elige '
                       . '—categoría, cuenta, mes— agrupaba por el proveedor, y cuando los pagos no '
                       . 'tenían proveedor los metía todos en un solo renglón. Los totales de abajo '
                       . 'siempre estuvieron bien; lo que estaba mal era cómo se repartían.',
            'detalles' => [
                'Afectaba a todos los cortes del reporte y venía de antes.',
                'Si sacó un reporte estos días y le extrañó ver un solo renglón, era esto.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.7', 'tipo' => 'nuevo',
            'titulo' => 'Las comisiones del banco, desglosadas como las lleva contabilidad',
            'resumen' => 'Hasta ahora todo lo que cobraba el banco caía en un solo renglón. Ahora se '
                       . 'separa en lo que contabilidad distingue: lo que cobran por servicios, lo del '
                       . 'punto de venta —y dentro, débito, crédito y electrónico—, el pago móvil, los '
                       . 'traspasos y la intervención cambiaria. El total de comisiones sigue siendo uno '
                       . 'solo; ahora se puede abrir.',
            'detalles' => [
                'Una categoría puede depender de otra. En Categorías se elige de cuál, y el listado '
                . 'las enseña colgadas de su madre.',
                'En Reportes hay un corte nuevo, «Categoría principal», que suma cada familia entera.',
                'Se reconocen los 69 conceptos del catálogo que pasó contabilidad, de los once bancos.',
                'Dos renglones quedaron apagados a la espera de que contabilidad los confirme: se ven '
                . 'en Reglas y se encienden con un clic.',
                'Lo que ya estaba clasificado no se movió de sitio. Para que las reglas nuevas alcancen '
                . 'lo viejo, use «Volver a aplicar las reglas» en la pantalla de Reglas.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.6.1', 'tipo' => 'mejora',
            'titulo' => 'Haga clic en un nombre y vea todo lo de esa persona',
            'resumen' => 'El registro decía qué se hizo pero no siempre quién. Ahora el nombre está en '
                       . 'todas las tablas y, al hacerle clic, se abre la ficha de esa persona: desde qué '
                       . 'conexiones entra, con qué computadoras trabaja, en qué se le va el tiempo y todo '
                       . 'lo que ha hecho, de lo más reciente a lo más viejo.',
            'detalles' => [
                'Cada visita suya se puede abrir y ver paso a paso, minuto a minuto.',
                'Si aparece una conexión que esa persona no reconoce, es la señal de que alguien más '
                . 'está usando su clave.',
                'La bitácora de Ajustes ya dice quién hizo cada cosa y con qué equipo.',
                'En Ajustes, el registro de fallos, la bitácora y las rutas del servidor los ve '
                . 'solo el maestro. Cada quien sigue teniendo ahí su clave, las tasas y el estado.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.6', 'tipo' => 'nuevo',
            'titulo' => 'Quién está trabajando, y todo lo que pasa queda anotado',
            'resumen' => 'Ahora se ve quién más está dentro y en qué pantalla está parado, con un ojito '
                       . 'en el menú que se enciende solo. Y por detrás, el sistema anota cada cosa que '
                       . 'se hace con su hora, la conexión desde donde se hizo y el equipo que se usó, '
                       . 'por si algún día hay que revisar a fondo.',
            'detalles' => [
                'Arriba de cada pantalla aparece quién más está trabajando en este momento.',
                'Si otra persona está mirando lo mismo que usted, su nombre se marca en verde: '
                . 'así no se hace dos veces el mismo trabajo.',
                'El menú enciende un ojito en la sección donde hay alguien parado.',
                'Lo que queda por justificar se actualiza solo, sin recargar la página.',
                'Nueva pantalla «Rastro y auditoría», solo para el maestro: quién, cuándo, desde qué '
                . 'conexión y con qué computadora, con filtros y para bajar a Excel.',
                'Un intento de entrada fallido ya no dice solo «PIN incorrecto»: deja constancia de '
                . 'por qué intento iba y desde dónde vino.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.5.2', 'tipo' => 'mejora',
            'titulo' => 'Preparado para cuando haya años de movimientos',
            'resumen' => 'Hoy el sistema va rápido porque lleva pocos meses cargados. Se probó con '
                       . '500.000 movimientos —unos cinco años al ritmo actual— y el panel tardaba 18 '
                       . 'segundos en abrir. Ya no: abre en menos de uno.',
            'detalles' => [
                'El panel pasó de 18 segundos a menos de 1.',
                'La pantalla de Cuentas, de 15 segundos a medio segundo.',
                'El aviso de pagos repetidos mira los últimos seis meses, que es lo que se puede reclamar.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.5.1', 'tipo' => 'mejora',
            'titulo' => 'El listado de proveedores ya no sale todo de una vez',
            'resumen' => 'Con 136 proveedores la página se hacía larga, y va a crecer. Ahora sale por '
                       . 'páginas, y el buscador sigue mirando el listado completo.',
            'detalles' => [
                'Cuarenta por página, con el total siempre a la vista.',
                'Unir dos fichas repetidas sigue viendo a todos los proveedores, no solo a los de la página.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.5', 'tipo' => 'nuevo',
            'titulo' => 'Si la letra le queda chica, agrándela',
            'resumen' => 'Al final del menú, junto a los colores, hay tres letras A. Elija la que le '
                       . 'resulte cómoda y crece todo: el texto, los botones y los renglones del menú. '
                       . 'Es cosa de cada quien y le sigue a cualquier computadora donde entre.',
            'detalles' => [
                'Tres tamaños: normal, grande y muy grande.',
                'Respeta además lo que usted tenga configurado en su navegador.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.4.2', 'tipo' => 'mejora',
            'titulo' => 'Encontrar una cuenta entre varias del mismo banco',
            'resumen' => 'Cuando una empresa tiene cuatro cuentas en el mismo banco, dar con la correcta '
                       . 'recorriendo la lista con la vista es lento y uno se equivoca. Ahora se busca '
                       . 'escribiendo, y se puede mirar un banco entero de una vez.',
            'detalles' => [
                'En Cuentas, un buscador que busca por banco, número, nombre o titular.',
                'En el panel, los movimientos, los pendientes y los reportes: filtro por banco.',
                'En las listas, cada cuenta muestra en qué números termina, para no confundirlas.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.4.1', 'tipo' => 'mejora',
            'titulo' => 'Letras y botones más grandes, y el pendiente se ve de lejos',
            'resumen' => 'El equipo avisó que las letras del menú eran muy chicas, que costaba ubicar los '
                       . 'botones y que el número de movimientos por justificar casi había que acercarse '
                       . 'a la pantalla para leerlo. Se rehizo todo eso.',
            'detalles' => [
                'El menú se lee más grande y cada opción es más fácil de acertar.',
                'Los botones son más grandes y están más separados entre sí.',
                'Lo que queda por justificar va en grande, con su renglón encendido mientras quede algo.',
                'Desde el panel se llega a justificar de un clic, y es lo primero que se ofrece.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.4', 'tipo' => 'nuevo',
            'titulo' => 'Subir el extracto es cargarlo, y si se equivocó se deshace',
            'resumen' => 'Ya no hay que elegir el archivo y después darle a un botón: apenas lo sube, se '
                       . 'carga. El sistema solo se detiene a preguntarle lo que el archivo no diga, como '
                       . 'de qué banco es o a cuál de sus cuentas va. Y si cargó el que no era, se deshace '
                       . 'entero desde la misma pantalla.',
            'detalles' => [
                'Lo que se pregunta se pregunta una vez: al mes siguiente ese extracto ya entra solo.',
                'Antes de deshacer, el sistema le dice cuántos movimientos se van a quitar.',
                'Si falta un dato, la pantalla se queda donde está y conserva el archivo: ya no hay que subirlo otra vez.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.3', 'tipo' => 'nuevo',
            'titulo' => 'Le avisamos cuando una operación parece estar cargada dos veces',
            'resumen' => 'Algunos bancos mueven al mes siguiente operaciones de los últimos días del mes. '
                       . 'Cuando eso pasa la misma operación llega dos veces con dos fechas distintas y '
                       . 'los totales la cuentan doble. Ahora se señalan y usted decide.',
            'detalles' => [
                'Aparecen en una pantalla nueva, la de Repetidos, con las dos operaciones una al lado de la otra.',
                'Quitar la repetida deja los totales como son de verdad.',
                'Si eran dos pagos iguales de verdad, se dejan y no se vuelven a señalar.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.2.4', 'tipo' => 'proteccion',
            'titulo' => 'Varias cuentas en el mismo banco, cada extracto en la suya',
            'resumen' => 'Una misma empresa puede tener varias cuentas en un mismo banco. El sistema solo '
                       . 'miraba de qué banco era el archivo, así que un extracto podía entrar en la cuenta '
                       . 'hermana sin que nadie lo notara. Ahora compara el número completo.',
            'detalles' => [
                'Cuando hay más de una cuenta suya en ese banco, la pantalla lo advierte en vez de adivinar.',
                'En la lista de cuentas se ve en cuáles termina cada número, para no confundirlas.',
                'Al crear una cuenta ya no se puede repetir un número que ya existe.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.2.3', 'tipo' => 'correccion',
            'titulo' => 'Un dato que falte ya no le hace volver a subir todo',
            'resumen' => 'Si la cuenta se quedaba sin nombre, la carga se caía, borraba los archivos ya '
                       . 'subidos y no dejaba botón para regresar. Había que empezar de cero.',
            'detalles' => [
                'Ahora se comprueba que todo tenga destino antes de tocar nada.',
                'Si algo falta, la pantalla se queda donde está y le señala cuál archivo es.',
            ],
        ],
        [
            'fecha' => '2026-09-08', 'version' => '2.2.2', 'tipo' => 'correccion',
            'titulo' => 'Los totales los pone el sistema, no el resumen del banco',
            'resumen' => 'Hay extractos que llegan con su propio resumen mal calculado. Mientras ese '
                       . 'resumen mandaba, un archivo bueno se rechazaba entero y no entraba nada. Ahora '
                       . 'el sistema suma las operaciones una por una y esa es la cifra que vale.',
            'detalles' => [
                'Si el resumen del banco no coincide, se le avisa, pero la carga entra igual.',
                'Al terminar la carga se muestran las salidas y las entradas sumadas por el sistema.',
            ],
        ],
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
