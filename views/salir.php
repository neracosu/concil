<?php
bitacora('salida', 'Cierre de sesión');
cerrar_sesion();
redirigir('?r=login');
