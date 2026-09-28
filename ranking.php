<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();

$modo = (string) ($_GET['modo'] ?? '');
if (!isset(MODOS[$modo])) {
    $modo = '';
}
$areaId = (int) ($_GET['area'] ?? 0);
$conNombre = ($_GET['con_nombre'] ?? '') === '1';

$sql = 'SELECT s.id, s.modo, s.alias, s.total, s.aciertos, s.iniciada_at, s.finalizada_at, a.nombre AS area
          FROM sesiones s
          LEFT JOIN areas a ON a.id = s.area_id
         WHERE s.estado = "finalizada" AND s.total > 0';
$params = [];

if ($modo !== '') {
    $sql .= ' AND s.modo = ?';
    $params[] = $modo;
}
if ($areaId > 0) {
    $sql .= ' AND s.area_id = ?';
    $params[] = $areaId;
}
if ($conNombre) {
    $sql .= ' AND s.alias IS NOT NULL AND s.alias <> ""';
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
    'SELECT COUNT(*) AS rondas,
            SUM(CASE WHEN alias IS NOT NULL AND alias <> "" THEN 1 ELSE 0 END) AS con_nombre
       FROM sesiones WHERE estado = "finalizada" AND total > 0'
)->fetch();

$mejor = $pdo->query(
    'SELECT ROUND(100 * aciertos / total, 1) AS p
       FROM sesiones WHERE estado = "finalizada" AND total > 0
      ORDER BY p DESC, total DESC LIMIT 1'
)->fetchColumn();

$titulo = 'Ranking';
require __DIR__ . '/vistas/header.php';
?>

<section class="hero hero-corto">
  <h1>Ranking de notas</h1>
  <p>Ordenado por porcentaje de aciertos. Poner nombre es opcional: si lo dejas
     vacío tu ronda aparece como «Anónimo».</p>
</section>

<div class="rejilla-3">
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= $mejor !== false && $mejor !== null ? e((string) $mejor) : '—' ?>%</span>
    <span class="mini-lbl">mejor nota</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($global['rondas'] ?? 0) ?></span>
    <span class="mini-lbl">rondas en el ranking</span>
  </div>
  <div class="tarjeta tarjeta-mini">
    <span class="mini-num"><?= (int) ($global['con_nombre'] ?? 0) ?></span>
    <span class="mini-lbl">con nombre</span>
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

    <label class="check check-filtro">
      <input type="checkbox" name="con_nombre" value="1" <?= $conNombre ? 'checked' : '' ?>>
      <span>Solo con nombre</span>
    </label>

    <button class="btn btn-secundario" type="submit">Filtrar</button>
  </form>

  <?php if ($filas === []): ?>
    <p class="vacio">No hay rondas finalizadas con esos filtros. <a href="index.php">Juega una ronda</a>.</p>
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
              <td>
                <?php if ($f['alias']): ?>
                  <strong><?= e($f['alias']) ?></strong>
                <?php else: ?>
                  <span class="alias-anonimo">Anónimo</span>
                <?php endif; ?>
              </td>
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
    <p class="nota">Se muestran las 50 mejores rondas con los filtros actuales.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/vistas/footer.php'; ?>
