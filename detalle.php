<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();
$sesionId = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM sesiones WHERE id = ?');
$st->execute([$sesionId]);
$sesion = $st->fetch();

if (!$sesion) {
    header('Location: resultados.php');
    exit;
}

// Publicar / cambiar / quitar el nombre opcional del ranking.
$avisoAlias = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Token de seguridad no válido.');
    }

    $nuevoAlias = null;
    if (!isset($_POST['quitar'])) {
        [$nuevoAlias, $avisoAlias] = normalizar_alias($_POST['alias'] ?? null);
    }

    if ($avisoAlias === null) {
        $pdo->prepare('UPDATE sesiones SET alias = ? WHERE id = ?')
            ->execute([$nuevoAlias, $sesionId]);
        $_SESSION['alias'] = (string) $nuevoAlias;
        header('Location: detalle.php?id=' . $sesionId);
        exit;
    }
}

$cola = json_decode((string) $sesion['cola'], true) ?: [];
$ids = array_map('intval', $cola);

$areaNombre = 'Todas las áreas';
if ($sesion['area_id']) {
    $st = $pdo->prepare('SELECT nombre FROM areas WHERE id = ?');
    $st->execute([(int) $sesion['area_id']]);
    $areaNombre = (string) ($st->fetchColumn() ?: 'Todas las áreas');
}

$preguntas = [];
$respuestas = [];

if ($ids !== []) {
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare(
        "SELECT p.id, p.numero, p.enunciado, p.explicacion, a.nombre AS area
           FROM preguntas p JOIN areas a ON a.id = p.area_id
          WHERE p.id IN ($marcas)"
    );
    $st->execute($ids);
    foreach ($st->fetchAll() as $fila) {
        $preguntas[(int) $fila['id']] = $fila;
    }

    $st = $pdo->prepare(
        "SELECT pregunta_id, letra, texto, es_correcta
           FROM opciones WHERE pregunta_id IN ($marcas) ORDER BY letra"
    );
    $st->execute($ids);
    $crudas = [];
    foreach ($st->fetchAll() as $fila) {
        $crudas[(int) $fila['pregunta_id']][] = $fila;
    }
    // Mismo orden aleatorio que se vio durante la ronda (misma semilla).
    $opcionesPor = [];
    foreach ($crudas as $pidCrudo => $lista) {
        $opcionesPor[$pidCrudo] = mezclar_opciones($lista, (int) $sesion['semilla'], $pidCrudo);
    }

    $st = $pdo->prepare(
        "SELECT pregunta_id, letra_elegida, correcta
           FROM respuestas WHERE sesion_id = ? AND pregunta_id IN ($marcas)"
    );
    $st->execute(array_merge([$sesionId], $ids));
    foreach ($st->fetchAll() as $fila) {
        $respuestas[(int) $fila['pregunta_id']] = $fila;
    }
} else {
    $opcionesPor = [];
}

$enCurso = $sesion['estado'] === 'jugando';
$aciertos = $enCurso
    ? count(array_filter($respuestas, fn ($r) => (int) $r['correcta'] === 1))
    : (int) $sesion['aciertos'];
$respondidas = count($respuestas);
$total = count($ids);
$fallos = $total - $aciertos;

$titulo = 'Detalle de la ronda';
require __DIR__ . '/vistas/header.php';
?>

<section class="tarjeta tarjeta-nota">
  <div class="nota-cabecera">
    <div>
      <span class="badge badge-<?= e($sesion['modo']) ?>"><?= e(modo_label($sesion['modo'])) ?></span>
      <span class="badge badge-plomo"><?= e($areaNombre) ?></span>
      <?php if ($sesion['alias']): ?>
        <span class="badge badge-nombre"><?= e($sesion['alias']) ?></span>
      <?php endif; ?>
      <?php if ($enCurso): ?>
        <span class="badge badge-aviso">Ronda en curso</span>
      <?php endif; ?>
      <h1><?= e(date('d/m/Y H:i', strtotime($sesion['finalizada_at'] ?? $sesion['iniciada_at']))) ?></h1>
    </div>

    <div class="nota-grande">
      <span class="numero"><?= $aciertos ?>/<?= $total ?></span>
      <span class="porcentaje"><?= pct($aciertos, $total) ?>%</span>
    </div>
  </div>

  <ul class="resumen">
    <li><strong><?= $aciertos ?></strong><span>aciertos</span></li>
    <li><strong><?= $fallos ?></strong><span>fallos</span></li>
    <li><strong><?= $total - $respondidas ?></strong><span>sin responder</span></li>
    <li><strong><?= $respondidas ?></strong><span>respondidas</span></li>
  </ul>

  <div class="nota-acciones">
    <?php if ($enCurso): ?>
      <a class="btn btn-primario" href="jugar.php?id=<?= $sesionId ?>">Continuar ronda</a>
    <?php endif; ?>
    <a class="btn btn-secundario" href="index.php">Nueva ronda</a>
    <a class="btn btn-secundario" href="estadisticas.php">Ver estadísticas</a>
  </div>

  <details class="caja-alias">
    <summary>
      <?php if ($sesion['alias']): ?>
        Tu nombre en el ranking: <strong><?= e($sesion['alias']) ?></strong> · cambiar
      <?php else: ?>
        Publicar este resultado en el ranking con tu nombre (opcional)
      <?php endif; ?>
    </summary>

    <div class="caja-alias-cuerpo">
      <form method="post" action="detalle.php?id=<?= $sesionId ?>" class="form-alias">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="text" name="alias" maxlength="24" placeholder="Tu nombre"
               value="<?= e($sesion['alias'] ?? ($_SESSION['alias'] ?? '')) ?>">
        <button class="btn btn-primario" type="submit">Guardar nombre</button>
      </form>

      <?php if ($sesion['alias']): ?>
        <form method="post" action="detalle.php?id=<?= $sesionId ?>">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn btn-secundario" type="submit" name="quitar" value="1">Quitar mi nombre</button>
        </form>
      <?php endif; ?>

      <p class="ayuda">Puedes dejarlo vacío y jugar anónimo: el ranking muestra esos casos como «Anónimo».</p>
      <?php if ($avisoAlias): ?>
        <p class="error-caja"><?= e($avisoAlias) ?></p>
      <?php endif; ?>
    </div>
  </details>
</section>

<section class="tarjeta">
  <div class="barra-filtro">
    <h2>Pregunta a pregunta</h2>
    <label class="check">
      <input type="checkbox" id="solo-fallos">
      <span>Ver solo mis fallos</span>
    </label>
  </div>

  <?php if ($ids === []): ?>
    <p class="vacio">Esta ronda no tiene preguntas.</p>
  <?php endif; ?>

  <ol class="listado-preguntas">
    <?php foreach ($ids as $pid):
        $p = $preguntas[$pid] ?? null;
        if (!$p) {
            continue;
        }
        $r = $respuestas[$pid] ?? null;
        $elegida = $r ? (string) $r['letra_elegida'] : '';
        $acerto = $r && (int) $r['correcta'] === 1;
        $claseItem = !$r ? 'sin-responder' : ($acerto ? 'acierto' : 'fallo');
        ?>
      <li class="item <?= $claseItem ?>" data-tipo="<?= $claseItem ?>">
        <div class="item-cabecera">
          <span class="estado estado-<?= $claseItem ?>">
            <?= !$r ? 'Sin responder' : ($acerto ? 'Acertaste' : 'Fallaste') ?>
          </span>
          <span class="item-num"><?= e($p['area']) ?> · #<?= (int) $p['numero'] ?></span>
        </div>

        <p class="item-enunciado"><?= e($p['enunciado']) ?></p>

        <ul class="item-opciones">
          <?php foreach ($opcionesPor[$pid] ?? [] as $o):
              $letra = (string) $o['letra'];
              $letraOriginal = (string) ($o['origen'] ?? $o['letra']);
              $esCorrecta = (int) $o['es_correcta'] === 1;
              $esElegida = $elegida === $letraOriginal;
              $clases = [];
              if ($esCorrecta) {
                  $clases[] = 'es-correcta';
              }
              if ($esElegida && !$esCorrecta) {
                  $clases[] = 'es-mala';
              }
              ?>
            <li class="<?= implode(' ', $clases) ?>">
              <span class="letra"><?= strtoupper(e($letra)) ?></span>
              <span class="texto"><?= e($o['texto']) ?></span>
              <span class="marca">
                <?php if ($esCorrecta): ?>Correcta<?php elseif ($esElegida): ?>Tu respuesta<?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>

        <details class="explicacion" <?= $claseItem === 'acierto' ? '' : 'open' ?>>
          <summary>Explicación</summary>
          <p><?= e($p['explicacion']) ?></p>
        </details>
      </li>
    <?php endforeach; ?>
  </ol>
</section>

<?php require __DIR__ . '/vistas/footer.php'; ?>
