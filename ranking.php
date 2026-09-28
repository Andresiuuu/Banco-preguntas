<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();

$modo = (string) ($_GET['modo'] ?? '');
if (!isset(MODOS[$modo])) {
    $modo = '';
}
$areaId = (int) ($_GET['area'] ?? 0);

$sql = 'SELECT s.id, s.modo, s.alias, s.total, s.aciertos, s.iniciada_at, s.finalizada_at, a.nombre AS area
          FROM sesiones s
          LEFT JOIN areas a ON a.id = s.area_id
         WHERE s.estado = "finalizada" AND s.total > 0
           AND ' . solo_con_nombre() . ' ';
$params = [];

if ($modo !== '') {
    $sql .= ' AND s.modo = ?';
    $params[] = $modo;
}
if ($areaId > 0) {
    $sql .= ' AND s.area_id = ?';
    $params[] = $areaId;
}

$sql .= ' ORDER BY (s.aciertos / s.total) DESC,
                    s.total DESC,
                    COALESCE(s.finalizada_at, s.iniciada_at) DESC
          LIMIT 50';

$st = $pdo->prepare($sql);
$st->execute($params);
$filas = $st->fetchAll();

$areas = $pdo->query('SELECT id, nombre FROM areas ORDER BY id')->fetchAll();

$global = $pdo->query(
    'SELECT SUM(CASE WHEN ' . solo_con_nombre() . ' THEN 1 ELSE 0 END) AS con_nombre,
            SUM(CASE WHEN NOT (' . solo_con_nombre() . ') THEN 1 ELSE 0 END) AS anonimas
       FROM sesiones s WHERE s.estado = "finalizada" AND s.total > 0'
)->fetch();

$mejor = $pdo->query(
    'SELECT ROUND(100 * s.aciertos / s.total, 1) AS p
       FROM sesiones s WHERE s.estado = "finalizada" AND s.total > 0
         AND ' . solo_con_nombre() . '
      ORDER BY p DESC, s.total DESC LIMIT 1'
)->fetchColumn();

$titulo = 'Ranking';
require __DIR__ . '/vistas/header.php';
?>

<section class="hero hero-corto">
  <h1>Ranking de notas</h1>
  <p>Ordenado por porcentaje de aciertos. Aquí solo entran las rondas de quienes
     pusieron su nombre: las anónimas no se muestran.</p>
</section>

<div class="rejilla-3">
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= $mejor !== false && $mejor !== null ? e((string) $mejor) : '—' ?>%</span>
    <span class="mini-lbl">mejor nota con nombre</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($global['con_nombre'] ?? 0) ?></span>
    <span class="mini-lbl">rondas en el ranking</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($global['anonimas'] ?? 0) ?></span>
    <span class="mini-lbl">anónimas (ocultas)</span>
  </div>
</div>

<section class="tarjeta">
  <div class="barra-filtro">
    <h2>Clasificación</h2>
    <a class="enlace" href="ranking.php">Quitar filtros</a>
  </div>

  <form method="get" action="ranking.php" class="form-filtros">
    <label class="campo">
      <span>Modo</span>
      <select name="modo">
        <option value="">Todos</option>
        <?php foreach (MODOS as $clave => $etiqueta): ?>
          <option value="<?= e($clave) ?>" <?= $modo === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="campo">
      <span>Área</span>
      <select name="area">
        <option value="0">Todas</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= $areaId === (int) $a['id'] ? 'selected' : '' ?>>
            <?= e($a['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <button class="btn btn-secundario" type="submit">Filtrar</button>
  </form>

  <?php if ($filas === []): ?>
    <p class="vacio">Nadie ha jugado todavía con nombre. <a href="index.php">Escribe el tuyo y juega una ronda</a>.</p>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla tabla-ranking">
        <thead>
          <tr>
            <th>Puesto</th>
            <th>Nombre</th>
            <th>Nota</th>
            <th>%</th>
            <th>Modo</th>
            <th>Área</th>
            <th>Fecha</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $i => $f):
              $tot = (int) $f['total'];
              $aci = (int) $f['aciertos'];
              $p = pct($aci, $tot);
              $puesto = $i + 1;
              ?>
            <tr class="<?= $puesto <= 3 ? 'fila-destacada' : '' ?>">
              <td class="celda-puesto">
                <span class="puesto puesto-<?= $puesto <= 3 ? $puesto : 'n' ?>"><?= $puesto ?></span>
              </td>
              <td><strong><?= e($f['alias']) ?></strong></td>
              <td class="celda-nota"><?= $aci ?>/<?= $tot ?></td>
              <td>
                <span class="mini-barra"><span style="width:<?= $p ?>%"></span></span>
                <?= $p ?>%
              </td>
              <td><span class="badge badge-<?= e($f['modo']) ?>"><?= e(modo_label($f['modo'])) ?></span></td>
              <td><?= e($f['area'] ?? 'Todas') ?></td>
              <td><?= e(date('d/m/Y H:i', strtotime($f['finalizada_at'] ?? $f['iniciada_at']))) ?></td>
              <td><a class="enlace" href="detalle.php?id=<?= (int) $f['id'] ?>">Detalle</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="nota">Se muestran las 50 mejores rondas con nombre y con los filtros actuales.
       Las rondas anónimas quedan fuera de la clasificación.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/vistas/footer.php'; ?>
