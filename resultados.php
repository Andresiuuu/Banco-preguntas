<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();

$aviso = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);

// Cambio de nickname del jugador actual (sin empezar ronda).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Token de seguridad no válido. Vuelve a la página de inicio.');
    }
    if (isset($_POST['salir'])) {
        $_SESSION['alias'] = '';
        header('Location: resultados.php');
        exit;
    }

    [$nuevo, $error] = normalizar_alias($_POST['alias'] ?? null);
    if ($error !== null) {
        $_SESSION['aviso'] = $error;
    } elseif ($nuevo === null) {
        $_SESSION['aviso'] = 'Escribe tu nickname para ver sus resultados.';
    } else {
        $_SESSION['alias'] = $nuevo;
    }
    header('Location: resultados.php');
    exit;
}

$miAlias = trim((string) ($_SESSION['alias'] ?? ''));

// Con nickname registrado se filtra por él; sin nickname, por las rondas
// de este navegador (así cada jugador también ve lo suyo).
$conNickname = $miAlias !== '';
$where = $conNickname ? 's.alias = ?' : filtro_jugador();
$valor = $conNickname ? $miAlias : jugador_token();
$titular = $conNickname ? $miAlias : 'este navegador';

$sesiones = [];
$resumen = ['rondas' => 0, 'aciertos' => 0, 'total' => 0];
$mejor = null;

$st = $pdo->prepare(
    "SELECT s.*, a.nombre AS area
       FROM sesiones s LEFT JOIN areas a ON a.id = s.area_id
      WHERE $where
      ORDER BY s.id DESC
      LIMIT 100"
);
$st->execute([$valor]);
$sesiones = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT COUNT(*) AS rondas,
            COALESCE(SUM(s.aciertos), 0) AS aciertos,
            COALESCE(SUM(s.total), 0) AS total
       FROM sesiones s
      WHERE s.estado = 'finalizada' AND $where"
);
$st->execute([$valor]);
$resumen = $st->fetch();

$st = $pdo->prepare(
    "SELECT ROUND(100 * s.aciertos / NULLIF(s.total, 0), 1) AS p
       FROM sesiones s
      WHERE s.estado = 'finalizada' AND s.total > 0 AND $where
      ORDER BY p DESC, s.aciertos DESC
      LIMIT 1"
);
$st->execute([$valor]);
$mejor = $st->fetchColumn();

$titulo = 'Resultados';
require __DIR__ . '/vistas/header.php';
?>

<section class="hero hero-corto">
  <h1>Últimos resultados</h1>
  <p>Sin nickname se muestran las rondas de este navegador. Si escribes un nombre,
     verás las rondas de esa persona en cualquier equipo.</p>
</section>

<?php if ($aviso): ?>
<div class="aviso aviso-rojo"><?= e($aviso) ?></div>
<?php endif; ?>

<section class="tarjeta">
  <form method="post" action="resultados.php" class="form-filtros">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label class="campo">
      <span>Mi nickname</span>
      <input type="text" name="alias" id="alias" maxlength="24"
             placeholder="Ej. Andres Macias"
             value="<?= e($miAlias) ?>" autocomplete="nickname">
    </label>

    <button class="btn btn-secundario" type="submit">Ver resultados</button>

    <?php if ($miAlias !== ''): ?>
      <button class="btn btn-secundario" type="submit" name="salir" value="1">Cambiar de jugador</button>
    <?php endif; ?>
  </form>
</section>

<div class="rejilla-3">
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($resumen['rondas'] ?? 0) ?></span>
    <span class="mini-lbl">rondas de <?= e($titular) ?></span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= pct((int) ($resumen['aciertos'] ?? 0), (int) ($resumen['total'] ?? 0)) ?>%</span>
    <span class="mini-lbl">acierto global</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= $mejor !== false && $mejor !== null ? e((string) $mejor) : '—' ?>%</span>
    <span class="mini-lbl">mejor ronda</span>
  </div>
</div>

<section class="tarjeta">
  <h2>Rondas de <?= e($titular) ?></h2>

  <?php if ($sesiones === []): ?>
    <p class="vacio">
      <?php if ($conNickname): ?>
        «<?= e($miAlias) ?>» todavía no tiene ninguna ronda.
        <a href="index.php">Empieza una</a> con ese nickname y aparecerá aquí.
      <?php else: ?>
        Todavía no hay rondas en este navegador.
        <a href="index.php">Empieza la primera</a>.
      <?php endif; ?>
    </p>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead>
          <tr>
            <th>#</th>
            <th>Fecha</th>
            <th>Modo</th>
            <th>Área</th>
            <th>Nota</th>
            <th>%</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sesiones as $s):
              $tot = (int) $s['total'];
              $aci = (int) $s['aciertos'];
              $p = pct($aci, $tot);
              $enCurso = $s['estado'] === 'jugando';
              ?>
            <tr class="<?= $enCurso ? 'fila-aviso' : '' ?>">
              <td><?= (int) $s['id'] ?></td>
              <td><?= e(date('d/m/Y H:i', strtotime($s['finalizada_at'] ?? $s['iniciada_at']))) ?></td>
              <td><span class="badge badge-<?= e($s['modo']) ?>"><?= e(modo_label($s['modo'])) ?></span></td>
              <td><?= e($s['area'] ?? 'Todas') ?></td>
              <td class="celda-nota"><?= $aci ?>/<?= $tot ?></td>
              <td>
                <span class="mini-barra"><span style="width:<?= $p ?>%"></span></span>
                <?= $p ?>%
              </td>
              <td><?= $enCurso ? '<span class="badge badge-aviso">En curso</span>' : 'Finalizada' ?></td>
              <td>
                <?php if ($enCurso): ?>
                  <a class="enlace" href="jugar.php?id=<?= (int) $s['id'] ?>">Continuar</a>
                <?php else: ?>
                  <a class="enlace" href="detalle.php?id=<?= (int) $s['id'] ?>">Ver fallos</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="nota">
      <?php if ($conNickname): ?>
        Solo se listan las rondas de «<?= e($miAlias) ?>»; las demás no aparecen aquí.
      <?php else: ?>
        Solo se listan las rondas de este navegador. Escribe un nickname arriba para
        ver las de otra persona del equipo.
      <?php endif; ?>
    </p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/vistas/footer.php'; ?>
