<?php
require_once __DIR__ . '/config.php';
arrancar_sesion();

$avisoSesion = $_SESSION['aviso'] ?? null;
unset($_SESSION['aviso']);

$pdo = db();

$totalPreguntas = (int) $pdo->query('SELECT COUNT(*) FROM preguntas WHERE activa = 1')->fetchColumn();

$areas = $pdo->query(
    'SELECT a.id, a.nombre, COUNT(p.id) AS n
       FROM areas a LEFT JOIN preguntas p ON p.area_id = a.id
      GROUP BY a.id, a.nombre ORDER BY a.id'
)->fetchAll();

$sesionActiva = $pdo->query(
    "SELECT * FROM sesiones WHERE estado = 'jugando' ORDER BY id DESC LIMIT 1"
)->fetch();

$ultima = $pdo->query(
    "SELECT * FROM sesiones WHERE estado = 'finalizada' ORDER BY id DESC LIMIT 1"
)->fetch();

$estadistica = [
    'sesiones' => (int) $pdo->query("SELECT COUNT(*) FROM sesiones WHERE estado = 'finalizada'")->fetchColumn(),
    'respondidas' => (int) $pdo->query('SELECT COUNT(*) FROM respuestas')->fetchColumn(),
    'fallos' => (int) $pdo->query('SELECT COUNT(*) FROM respuestas WHERE correcta = 0')->fetchColumn(),
];

$titulo = 'Inicio';
require __DIR__ . '/vistas/header.php';
?>

<?php if ($avisoSesion): ?>
<div class="aviso aviso-rojo"><?= e($avisoSesion) ?></div>
<?php endif; ?>

<section class="hero">
  <h1>Prepárate con el banco de 400 preguntas</h1>
  <p>Cuatro áreas, respuesta correcta marcada y explicación de cada pregunta.
     Cada ronda que juegas queda guardada para que veas exactamente dónde te equivocaste.</p>

  <div class="chips">
    <span class="chip"><?= (int) $totalPreguntas ?> preguntas</span>
    <span class="chip"><?= count($areas) ?> áreas</span>
    <span class="chip"><?= (int) $estadistica['sesiones'] ?> rondas jugadas</span>
    <span class="chip"><?= (int) $estadistica['fallos'] ?> fallos registrados</span>
  </div>
</section>

<?php if ($sesionActiva): ?>
<div class="aviso">
  <div>
    <strong>Tienes una ronda sin terminar:</strong>
    <?= e(modo_label($sesionActiva['modo'])) ?> ·
    <?= (int) $sesionActiva['respondidas'] ?>/<?= (int) $sesionActiva['total'] ?> preguntas respondidas.
  </div>
  <a class="btn btn-primario" href="jugar.php?id=<?= (int) $sesionActiva['id'] ?>">Continuar</a>
</div>
<?php endif; ?>

<div class="rejilla-2">
  <section class="tarjeta">
    <h2>Nueva ronda</h2>
    <form method="post" action="iniciar.php" id="form-nueva" data-fallos="<?= (int) $estadistica['fallos'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <fieldset class="campo">
        <legend>Modo de práctica</legend>

        <label class="opcion-radio">
          <input type="radio" name="modo" value="estudio" checked>
          <span>
            <strong>Estudio</strong>
            <small>Respuesta inmediata + explicación tras cada pregunta.</small>
          </span>
        </label>

        <label class="opcion-radio">
          <input type="radio" name="modo" value="examen">
          <span>
            <strong>Examen</strong>
            <small>Sin pista hasta el final: contestas todo y luego ves la nota.</small>
          </span>
        </label>

        <label class="opcion-radio">
          <input type="radio" name="modo" value="errores">
          <span>
            <strong>Errores</strong>
            <small>Solo preguntas que ya has fallado alguna vez.</small>
          </span>
        </label>
      </fieldset>

      <div class="campo-fila">
        <label class="campo">
          <span>Área</span>
          <select name="area">
            <option value="0">Todas las áreas</option>
            <?php foreach ($areas as $a): ?>
              <option value="<?= (int) $a['id'] ?>"><?= e($a['nombre']) ?> (<?= (int) $a['n'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="campo">
          <span>Preguntas</span>
          <select name="cantidad">
            <option value="10">10</option>
            <option value="20" selected>20</option>
            <option value="40">40</option>
            <option value="100">100</option>
            <option value="0">Todas (400)</option>
          </select>
        </label>
      </div>

      <label class="campo">
        <span>Tu nombre para el ranking <em class="opcional">opcional</em></span>
        <input type="text" name="alias" id="alias" maxlength="24"
               placeholder="Déjalo vacío para jugar anónimo"
               value="<?= e($_SESSION['alias'] ?? '') ?>"
               autocomplete="nickname">
        <small class="ayuda">Si lo pones, tu nota aparecerá con ese nombre en la página de Ranking.</small>
      </label>

      <button class="btn btn-primario btn-ancho" type="submit">Empezar ronda</button>
      <p class="nota" id="nota-errores" hidden> Todavía no has fallado ninguna pregunta:
        juega primero una ronda en <em>Estudio</em> o <em>Examen</em>.</p>
    </form>
  </section>

  <section class="tarjeta">
    <h2>Último resultado</h2>
    <?php if ($ultima): ?>
      <?php $nota = (int) $ultima['aciertos']; $tot = (int) $ultima['total']; ?>
      <div class="nota-grande">
        <span class="numero"><?= $nota ?>/<?= $tot ?></span>
        <span class="porcentaje"><?= pct($nota, $tot) ?>%</span>
      </div>
      <ul class="lista-datos">
        <li><span>Modo</span><strong><?= e(modo_label($ultima['modo'])) ?></strong></li>
        <li><span>Fecha</span><strong><?= e(date('d/m/Y H:i', strtotime($ultima['finalizada_at'] ?? $ultima['iniciada_at']))) ?></strong></li>
        <li><span>Fallos</span><strong><?= $tot - $nota ?></strong></li>
      </ul>
      <a class="btn btn-secundario btn-ancho" href="detalle.php?id=<?= (int) $ultima['id'] ?>">Ver en qué fallé</a>
    <?php else: ?>
      <p class="vacio">Aún no has terminado ninguna ronda. Empieza una y aquí aparecerá tu nota.</p>
    <?php endif; ?>

    <hr class="separador">
    <a class="enlace-block" href="resultados.php">Historial completo de rondas →</a>
    <a class="enlace-block" href="estadisticas.php">Estadísticas por área y preguntas débiles →</a>
    <a class="enlace-block" href="ranking.php">Ranking de notas →</a>
  </section>
</div>

<?php require __DIR__ . '/vistas/footer.php'; ?>
