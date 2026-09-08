<?php
/**
 * Operaciones que parecen ser una que ya estaba, con la fecha corrida.
 *
 * Bicentenario y el Tesoro mueven al mes siguiente operaciones de los últimos
 * días del mes. Como la fecha forma parte de la firma que evita duplicados, la
 * misma operación entra dos veces sin que nadie se entere. Aquí se ven las dos,
 * una al lado de la otra, y alguien decide.
 */
exigir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'resolver') {
        $que  = ($_POST['que'] ?? '') === 'quitar' ? 'quitar' : 'dejar';
        $ids  = array_map('intval', (array) ($_POST['id'] ?? []));
        $n = 0;
        foreach ($ids as $id) {
            if ($id > 0 && resolver_repetido($id, $que)) {
                $n++;
            }
        }
        if ($n > 0) {
            bitacora('repetidos', ($que === 'quitar' ? 'Quitados ' : 'Dados por buenos ') . $n);
            flash('ok', $que === 'quitar'
                ? "Se quitaron $n movimientos repetidos. Los totales ya no los cuentan dos veces."
                : "$n movimientos dados por buenos. No se vuelven a señalar.");
        } else {
            flash('mal', 'No se marcó ninguna operación.');
        }
        redirigir('?r=repetidos');
    }
}

$lista = repetidos_pendientes();
encabezado_html('Repetidos por revisar', 'repetidos',
    'Operaciones que parecen ser una que ya estaba, cargada otra vez con distinta fecha.');
?>

<div class="tarjeta">
  <h2>Por qué aparecen aquí</h2>
  <p class="nota" style="margin:0">
    Algunos bancos mueven al mes siguiente operaciones de los últimos días del mes. Cuando eso pasa,
    la misma operación llega dos veces con dos fechas y el sistema no puede darla por repetida él solo:
    podrían ser dos pagos iguales de verdad. Por eso se cargan todas y se señalan aquí.
    <b>Quitar una hace que los totales dejen de contarla dos veces.</b>
  </p>
</div>

<?php if ($lista === []): ?>
  <div class="tarjeta" style="margin-top:16px">
    <p style="margin:0;color:var(--entrada)"><b>No hay nada que revisar.</b>
       Ninguna de las operaciones cargadas parece estar repetida.</p>
  </div>
<?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="accion" value="resolver">
    <div class="marco-tabla" style="margin-top:16px">
      <div class="tabla-scroll">
        <table>
          <thead><tr>
            <th style="width:34px"><input type="checkbox" id="todosRep" style="width:auto"></th>
            <th>La que llegó ahora</th><th>La que ya estaba</th>
            <th class="der">Monto</th><th>Cuenta</th><th>Vino en</th>
          </tr></thead>
          <tbody>
          <?php foreach ($lista as $r): ?>
            <tr>
              <td><input type="checkbox" name="id[]" value="<?= (int) $r['id'] ?>" style="width:auto"></td>
              <td>
                <b><?= e(date('d/m/Y', strtotime($r['fecha']))) ?></b>
                <span class="nota" style="display:block"><?= e(mb_strimwidth($r['concepto'], 0, 60, '…')) ?></span>
                <?php if ($r['referencia'] !== '' && $r['referencia'] !== '0'): ?>
                  <span class="ref">ref. <?= e($r['referencia']) ?></span>
                <?php endif ?>
                <?php if ($r['categoria_id'] !== null): ?>
                  <span class="etq" style="margin-top:4px">Ya está clasificada</span>
                <?php endif ?>
              </td>
              <td>
                <?php if ($r['vid'] !== null): ?>
                  <a href="?r=movimiento&id=<?= (int) $r['vid'] ?>"><?= e(date('d/m/Y', strtotime((string) $r['vfecha']))) ?></a>
                  <span class="nota" style="display:block"><?= e(mb_strimwidth((string) $r['vconcepto'], 0, 60, '…')) ?></span>
                <?php else: ?>
                  <span class="nota">ya no está</span>
                <?php endif ?>
              </td>
              <td class="der num <?= $r['tipo'] === 'D' ? 'salida' : 'entrada' ?>">
                <?= bs($r['tipo'] === 'D' ? $r['debito'] : $r['credito']) ?>
              </td>
              <td><?= e($r['cuenta']) ?></td>
              <td class="ref"><?= e(mb_strimwidth((string) $r['archivo'], 0, 28, '…')) ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-oro btn-grande" name="que" value="quitar"
              data-confirmar="Se van a borrar las operaciones marcadas. Es lo correcto si de verdad estaban repetidas. ¿Continuar?">
        Quitar las marcadas
      </button>
      <button class="btn btn-grande" name="que" value="dejar">Dejarlas, no están repetidas</button>
    </div>
  </form>
<?php endif ?>

<?php pie_html(); ?>
