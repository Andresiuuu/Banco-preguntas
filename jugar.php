<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$pdo = db();
$sesionId = (int) ($_GET['id'] ?? $_SESSION['sesion_id'] ?? 0);

if ($sesionId <= 0) {
    header('Location: index.php');
    exit;
}

$st = $pdo->prepare('SELECT * FROM sesiones WHERE id = ?');
$st->execute([$sesionId]);
$sesion = $st->fetch();

if (!$sesion) {
    header('Location: index.php');
    exit;
}

$_SESSION['sesion_id'] = $sesionId;

if ($sesion['estado'] === 'finalizada') {
    header('Location: detalle.php?id=' . $sesionId);
    exit;
}

$cola = json_decode((string) $sesion['cola'], true);
if (!is_array($cola) || $cola === []) {
    header('Location: index.php?aviso=sin-preguntas');
    exit;
}

$pos = (int) $sesion['posicion'];

// Ronda ya contestada por completo: se cierra y se pasa al detalle.
if ($pos >= count($cola)) {
    finalizar_sesion($sesionId);
    header('Location: detalle.php?id=' . $sesionId);
    exit;
}

$preguntaId = (int) $cola[$pos];

$st = $pdo->prepare(
    'SELECT p.id, p.numero, p.enunciado, p.explicacion, a.nombre AS area
       FROM preguntas p JOIN areas a ON a.id = p.area_id
      WHERE p.id = ?'
);
$st->execute([$preguntaId]);
$pregunta = $st->fetch();

if (!$pregunta) {
    http_response_code(500);
    exit('La pregunta de esta ronda ya no existe. Vuelve al inicio.');
}

$st = $pdo->prepare(
    'SELECT letra, texto, es_correcta FROM opciones WHERE pregunta_id = ? ORDER BY letra'
);
$st->execute([$preguntaId]);
// Orden aleatorio de las opciones: la letra visible no es la de la BD,
// por eso cada boton lleva tambien data-original.
$opciones = mezclar_opciones($st->fetchAll(), (int) $sesion['semilla'], $preguntaId);

$mostrarFeedback = $sesion['modo'] !== 'examen';

$aciertosParciales = 0;
if ($mostrarFeedback) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM respuestas WHERE sesion_id = ? AND correcta = 1');
    $st->execute([$sesionId]);
    $aciertosParciales = (int) $st->fetchColumn();
}

$titulo = 'Ronda en curso';
require __DIR__ . '/vistas/header.php';

$total = count($cola);
$avance = min(100, round(($pos) * 100 / $total));
?>
<section class="cabecera-ronda">
  <div class="ronda-meta">
    <span class="badge badge-<?= e($sesion['modo']) ?>"><?= e(modo_label($sesion['modo'])) ?></span>
    <span class="badge badge-plomo"><?= e($pregunta['area']) ?></span>
    <span class="progreso-texto">Pregunta <strong><?= $pos + 1 ?></strong> de <?= $total ?></span>
    <?php if ($mostrarFeedback): ?>
      <span class="contador-aciertos"><?= $aciertosParciales ?> correctas</span>
    <?php endif; ?>
  </div>

  <div class="barra"><span style="width:<?= $avance ?>%"></span></div>

  <div class="ronda-acciones">
    <button type="button" class="btn btn-fantasma" id="btn-terminar">Terminar ronda</button>
  </div>
</section>

<section class="tarjeta tarjeta-pregunta">
  <p class="pregunta-numero">Pregunta <?= (int) $pregunta['numero'] ?></p>
  <h1 class="pregunta-enunciado"><?= e($pregunta['enunciado']) ?></h1>

  <div class="opciones" id="opciones">
    <?php foreach ($opciones as $o): ?>
      <button type="button" class="opcion"
              data-letra="<?= e($o['letra']) ?>"
              data-original="<?= e($o['origen']) ?>">
        <span class="letra"><?= strtoupper(e($o['letra'])) ?></span>
        <span class="texto"><?= e($o['texto']) ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <div class="feedback" id="feedback" hidden>
    <p class="feedback-titulo" id="feedback-titulo"></p>
    <p class="feedback-texto" id="feedback-texto"></p>
    <button type="button" class="btn btn-primario btn-ancho" id="btn-siguiente">Siguiente pregunta</button>
  </div>

  <p class="error-caja" id="error-caja" hidden></p>
</section>

<script>
window.__JUGAR__ = <?= json_encode([
    'sesionId' => $sesionId,
    'preguntaId' => $preguntaId,
    'csrf' => csrf_token(),
    'modo' => $sesion['modo'],
    'mostrarFeedback' => $mostrarFeedback,
    'esUltima' => ($pos + 1) >= $total,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php require __DIR__ . '/vistas/footer.php'; ?>
