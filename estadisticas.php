<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();

$totales = $pdo->query(
    'SELECT (SELECT COUNT(*) FROM preguntas) AS preguntas,
            (SELECT COUNT(*) FROM sesiones WHERE estado = "finalizada") AS rondas,
            (SELECT COUNT(*) FROM respuestas) AS respondidas,
            (SELECT COALESCE(SUM(correcta), 0) FROM respuestas) AS aciertos,
            (SELECT COUNT(DISTINCT pregunta_id) FROM respuestas) AS distintas,
            (SELECT COUNT(*) FROM preguntas WHERE veces_fallada > 0) AS con_fallos'
)->fetch();

$areas = $pdo->query(
    'SELECT a.id, a.nombre, COUNT(r.id) AS vistas, COALESCE(SUM(r.correcta), 0) AS aciertos
       FROM areas a
       LEFT JOIN preguntas p ON p.area_id = a.id
       LEFT JOIN respuestas r ON r.pregunta_id = p.id
      GROUP BY a.id, a.nombre
      ORDER BY a.id'
)->fetchAll();

$topFalladas = $pdo->query(
    'SELECT p.numero, p.enunciado, p.veces_vista, p.veces_fallada, a.nombre AS area
       FROM preguntas p JOIN areas a ON a.id = p.area_id
      WHERE p.veces_fallada > 0
      ORDER BY p.veces_fallada DESC, p.veces_vista DESC
      LIMIT 15'
)->fetchAll();

$evolucion = $pdo->query(
    'SELECT id, modo, aciertos, total, finalizada_at
       FROM sesiones
      WHERE estado = "finalizada" AND total > 0
      ORDER BY id DESC
      LIMIT 12'
)->fetchAll();
$evolucion = array_reverse($evolucion);

$titulo = 'Estadísticas';
require __DIR__ . '/vistas/header.php';

$aciertoGlobal = pct((int) $totales['aciertos'], (int) $totales['respondidas']);
?>

<section class="hero hero-corto">
  <h1>Dónde estoy flojo</h1>
  <p>Dominio por área, preguntas que más se te resisten y tu evolución ronda a ronda.</p>
</section>

<div class="rejilla-3">
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= $aciertoGlobal ?>%</span>
    <span class="mini-lbl">acierto global</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) $totales['respondidas'] ?></span>
    <span class="mini-lbl">respuestas registradas</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) $totales['distintas'] ?>/<?= (int) $totales['preguntas'] ?></span>
    <span class="mini-lbl">preguntas distintas vistas</span>
  </div>
</div>

<section class="tarjeta">
  <h2>Dominio por área</h2>
  <?php foreach ($areas as $a):
      $v = (int) $a['vistas'];
      $ac = (int) $a['aciertos'];
      $p = pct($ac, $v);
      ?>
    <div class="barra-area">
      <div class="barra-area-top">
        <span class="barra-area-nombre"><?= e($a['nombre']) ?></span>
        <span class="barra-area-datos">
          <?php if ($v > 0): ?>
            <?= $ac ?>/<?= $v ?> respuestas · <strong><?= $p ?>%</strong>
          <?php else: ?>
            sin respuestas todavía
          <?php endif; ?>
        </span>
      </div>
      <div class="barra"><span class="<?= $p >= 70 ? 'verde' : ($p >= 40 ? 'ambar' : 'rojo') ?>" style="width:<?= $v > 0 ? $p : 0 ?>%"></span></div>
    </div>
  <?php endforeach; ?>
</section>

<div class="rejilla-2">
  <section class="tarjeta">
    <h2>Preguntas que más fallo</h2>
    <?php if ($topFalladas === []): ?>
      <p class="vacio">Aún no has fallado ninguna pregunta. Cuando falle, aparecerá aquí ordenada por número de fallos.</p>
    <?php else: ?>
      <ol class="listado-fallos">
        <?php foreach ($topFalladas as $f):
            $v = (int) $f['veces_vista'];
            $fl = (int) $f['veces_fallada'];
            $p = $v > 0 ? round($fl * 100 / $v) : 0;
            ?>
          <li>
            <div class="fallos-top">
              <span class="badge badge-plomo"><?= e($f['area']) ?> · #<?= (int) $f['numero'] ?></span>
              <span class="fallos-num"><?= $fl ?> <?= $fl === 1 ? 'fallo' : 'fallos' ?></span>
            </div>
            <p class="fallos-enunciado"><?= e($f['enunciado']) ?></p>
            <div class="barra"><span class="rojo" style="width:<?= $p ?>%"></span></div>
            <small class="fallos-pie"><?= $fl ?> de <?= $v ?> intentos fallados (<?= $p ?>%)</small>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <section class="tarjeta">
    <h2>Evolución</h2>
    <?php if ($evolucion === []): ?>
      <p class="vacio">Termina una ronda y aquí verás cómo evoluciona tu nota.</p>
    <?php else: ?>
      <div class="evolucion">
        <?php foreach ($evolucion as $s):
            $tot = (int) $s['total'];
            $aci = (int) $s['aciertos'];
            $p = pct($aci, $tot);
            ?>
          <a class="evol-col" href="detalle.php?id=<?= (int) $s['id'] ?>" title="<?= e(modo_label($s['modo'])) ?> · <?= $aci ?>/<?= $tot ?>">
            <span class="evol-valor"><?= $p ?>%</span>
            <span class="evol-barra"><span class="<?= $p >= 70 ? 'verde' : ($p >= 40 ? 'ambar' : 'rojo') ?>" style="height:<?= $p ?>%"></span></span>
            <span class="evol-fecha"><?= e(date('d/m', strtotime($s['finalizada_at'] ?? $s['iniciada_at']))) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <p class="nota">Cada barra es una ronda; pulsa para ver el detalle de fallos.</p>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/vistas/footer.php'; ?>
