<?php
require_once __DIR__ . '/config.php';

arrancar_sesion();

if (!csrf_valido($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Token de seguridad no válido. Vuelve a la página de inicio.');
}

$modo = (string) ($_POST['modo'] ?? '');
if (!isset(MODOS[$modo])) {
    http_response_code(400);
    exit('Modo no válido.');
}

$areaId = (int) ($_POST['area'] ?? 0);
$cantidad = (int) ($_POST['cantidad'] ?? 20);
if ($cantidad <= 0) {
    $cantidad = 1000; // "todas"
}

// Nombre opcional para el ranking (vacio = anonimo).
[$alias, $errorAlias] = normalizar_alias($_POST['alias'] ?? null);
if ($errorAlias !== null) {
    $_SESSION['aviso'] = $errorAlias;
    header('Location: index.php');
    exit;
}
$_SESSION['alias'] = (string) $alias;

$pdo = db();

// Al empezar una ronda nueva, solo se cierra la anterior DE ESTE navegador.
// Las rondas de los demás jugadores siguen abiertas: cada uno juega a su vez.
$st = $pdo->prepare("SELECT id FROM sesiones WHERE estado = 'jugando' AND jugador = ?");
$st->execute([jugador_token()]);
$previa = $st->fetch();
if ($previa) {
    finalizar_sesion((int) $previa['id']);
}

// ---------------------------------------------------------------
// Selección de preguntas
// ---------------------------------------------------------------
$sql = 'SELECT p.id, p.veces_fallada FROM preguntas p WHERE p.activa = 1';
$params = [];

if ($areaId > 0) {
    $sql .= ' AND p.area_id = ?';
    $params[] = $areaId;
}

if ($modo === 'errores') {
    // Solo preguntas falladas POR ESTE jugador (no las de los demás).
    $sql .= ' AND p.id IN (
                SELECT r.pregunta_id
                  FROM respuestas r
                  JOIN sesiones s ON s.id = r.sesion_id
                 WHERE r.correcta = 0 AND ' . filtro_jugador() . '
              )';
    $params[] = jugador_token();
    // Muestreo ponderado: cuanto más veces fallada, más probabilidad de salir.
    $sql .= ' ORDER BY POW(RAND(), 1 / p.veces_fallada) DESC';
} else {
    $sql .= ' ORDER BY RAND()';
}

$sql .= ' LIMIT ' . $cantidad;

$st = $pdo->prepare($sql);
$st->execute($params);
$filas = $st->fetchAll();

if ($filas === []) {
    header('Location: index.php?aviso=' . ($modo === 'errores' ? 'errores-vacios' : 'sin-preguntas'));
    exit;
}

$cola = array_map('intval', array_column($filas, 'id'));

$pdo->prepare(
    'INSERT INTO sesiones (modo, area_id, alias, semilla, jugador, total, posicion, respondidas, aciertos, estado, cola, iniciada_at)
     VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, "jugando", ?, NOW())'
)->execute([
    $modo,
    $areaId > 0 ? $areaId : null,
    $alias,
    random_int(1, 2147483647),
    jugador_token(),
    count($cola),
    json_encode($cola),
]);

$sesionId = (int) $pdo->lastInsertId();
$_SESSION['sesion_id'] = $sesionId;

header('Location: jugar.php?id=' . $sesionId);
exit;
