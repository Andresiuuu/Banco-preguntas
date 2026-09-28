<?php
require_once __DIR__ . '/../config.php';
arrancar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
}
if (!csrf_valido($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'Sesión caducada. Recarga la página.'], 403);
}

$sesionId = (int) ($_POST['sesion_id'] ?? $_SESSION['sesion_id'] ?? 0);
if ($sesionId <= 0) {
    json_out(['ok' => false, 'error' => 'No hay ronda activa'], 404);
}

$sesion = finalizar_sesion($sesionId);
if (!$sesion) {
    json_out(['ok' => false, 'error' => 'La ronda no existe'], 404);
}

json_out([
    'ok' => true,
    'redirect' => 'detalle.php?id=' . $sesionId,
    'aciertos' => (int) $sesion['aciertos'],
    'respondidas' => (int) $sesion['respondidas'],
    'total' => (int) $sesion['total'],
]);
