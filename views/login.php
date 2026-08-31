<?php
$error = '';
$espera = bloqueado();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($espera > 0) {
        $error = 'Acceso bloqueado. Vuelve a intentar en ' . ceil($espera / 60) . ' minutos.';
    } else {
        $pin = preg_replace('/\D/', '', implode('', (array) ($_POST['d'] ?? [])));
        // La suma se comprueba antes que el PIN: si no, un robot sabría por el
        // mensaje si acertó el PIN aunque fallara la suma.
        if (captcha_necesario() && !captcha_correcto((string) ($_POST['suma'] ?? ''))) {
            $error = 'La suma no es correcta. Inténtalo otra vez.';
        } else {
            $usuario = verificar_pin((string) $pin);
            if ($usuario !== null) {
                entrar($usuario);
                redirigir('?r=panel');
            }
            bitacora('acceso_fallido', 'PIN incorrecto');
            $espera = bloqueado();
            $error = $espera > 0
                ? 'Demasiados intentos. Acceso bloqueado por ' . ceil($espera / 60) . ' minutos.'
                : 'PIN incorrecto. Te quedan ' . (MAX_INTENTOS - (int) ajuste('intentos', '0')) . ' intentos.';
        }
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Acceso · <?= e(APP_CREDITO) ?></title>
<link rel="icon" type="image/png" href="/icon.png">
<link rel="stylesheet" href="assets/app.css?v=13">
</head>
<body>
<div class="acceso">
  <div class="acceso-caja">
    <h1 class="acceso-logo"><?= e(APP_NOMBRE) ?></h1>
    <div class="acceso-marca">by <?= e(APP_MARCA) ?></div>
    <p class="acceso-lema"><?= e(APP_LEMA) ?></p>
    <p>Escribe tu PIN de 6 dígitos.</p>

    <?php if ($error !== ''): ?>
      <div class="aviso aviso-mal"><?= e($error) ?></div>
    <?php endif ?>

    <form method="post" id="formPin" autocomplete="off">
      <div class="pin-campos">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="password" name="d[]" inputmode="numeric" pattern="\d" maxlength="1"
                 aria-label="Dígito <?= $i + 1 ?>" <?= $i === 0 && $espera <= 0 ? 'autofocus' : '' ?>
                 <?= $espera > 0 ? 'disabled' : '' ?>>
        <?php endfor ?>
      </div>
      <?php if (captcha_necesario() && $espera <= 0): [$ca, $cb] = captcha_nuevo(); ?>
        <div class="acceso-suma">
          <label for="suma">Para comprobar que no es un programa: ¿cuánto es <b><?= $ca ?> + <?= $cb ?></b>?</label>
          <input type="text" name="suma" id="suma" inputmode="numeric" pattern="\d*" maxlength="3"
                 autocomplete="off" required>
        </div>
      <?php endif ?>
      <button class="btn btn-oro" style="width:100%;justify-content:center" <?= $espera > 0 ? 'disabled' : '' ?>>Entrar</button>
    </form>

    <?php if (ajuste('pin_inicial_pendiente') === '1' && $espera <= 0): ?>
      <div class="aviso aviso-nota" style="margin-top:20px;text-align:left">
        Sigue activo el PIN de instalación. Está en el archivo
        <b class="num" style="font-size:12px">PIN-INICIAL.txt</b> del servidor. Cámbialo en Mi perfil al entrar.
      </div>
    <?php endif ?>

    <div class="acceso-pie"><?= e(APP_CREDITO) ?> · v<?= e(APP_VERSION) ?></div>
  </div>
</div>
<script src="assets/app.js?v=8"></script>
</body>
</html>
