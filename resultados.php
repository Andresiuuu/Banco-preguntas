<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();

$sesiones = $pdo->query(
    'SELECT s.*, a.nombre AS area
       FROM sesiones s LEFT JOIN areas a ON a.id = s.area_id
      ORDER BY s.id DESC
      LIMIT 100'
)->fetchAll();

$resumen = $pdo->query(
    "SELECT COUNT(*) AS rondas,
            COALESCE(SUM(aciertos), 0) AS aciertos,
            COALESCE(SUM(total), 0) AS total
       FROM sesiones WHERE estado = 'finalizada'"
)->fetch();

$mejor = $pdo->query(
    "SELECT ROUND(100 * aciertos / NULLIF(total,0), 1) AS p
       FROM sesiones WHERE estado = 'finalizada' AND total > 0
      ORDER BY p DESC, aciertos DESC LIMIT 1"
)->fetchColumn();

$titulo = 'Resultados';
require __DIR__ . '/vistas/header.php';
?>

<section class="hero hero-corto">
  <h1>Historial de rondas</h1>
  <p>Cada ronda queda guardada. Entra al detalle para ver pregunta a pregunta qué respondiste y cuál era la correcta.</p>
</section>

<div class="rejilla-3">
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($resumen['rondas'] ?? 0) ?></span>
    <span class="mini-lbl">rondas terminadas</span>
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
  <h2>Rondas</h2>

  <?php if ($sesiones === []): ?>
    <p class="vacio">Todavía no hay rondas. <a href="index.php">Empieza la primera</a>.</p>
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
  <?php endif; ?>
</section>

<?php require __DIR__ . '/vistas/footer.php'; ?>
