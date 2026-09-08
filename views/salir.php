<?php
bitacora('salida', 'Cierre de sesión');
// Quien se va deja de estar «trabajando ahora»: sin esto seguiría apareciendo
// en el menú de sus compañeros los cuatro minutos siguientes.
borrar_presencia();
cerrar_sesion();
redirigir('?r=login');
