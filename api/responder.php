<?php
require_once __DIR__ . '/../config.php';
arrancar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'Método no permitido'], 405);
}
if (!csrf_valido($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'Sesión caducada. Recarga la página.'], 403);
}

$sesionId = (int) ($_POST['sesion_id'] ?? 0);
$preguntaId = (int) ($_POST['pregunta_id'] ?? 0);
$letra = strtolower(trim((string) ($_POST['letra'] ?? '')));

if ($sesionId <= 0 || $preguntaId <= 0 || !in_array($letra, ['a', 'b', 'c', 'd'], true)) {
    json_out(['ok' => false, 'error' => 'Datos de respuesta incompletos'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // Fila bloqueada: serializa los clicks repetidos.
    $st = $pdo->prepare('SELECT * FROM sesiones WHERE id = ? FOR UPDATE');
    $st->execute([$sesionId]);
    $sesion = $st->fetch();

    if (!$sesion) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'La ronda no existe'], 404);
    }
    if ($sesion['estado'] !== 'jugando') {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'La ronda ya está cerrada'], 409);
    }

    $cola = json_decode((string) $sesion['cola'], true) ?: [];
    $pos = (int) $sesion['posicion'];

    if ($pos >= count($cola)) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'La ronda ya está completa'], 409);
    }
    if ((int) $cola[$pos] !== $preguntaId) {
        // El cliente tenía una pregunta vieja (recarga en otra pestaña).
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'desactualizada', 'recargar' => true], 409);
    }

    $st = $pdo->prepare('SELECT es_correcta FROM opciones WHERE pregunta_id = ? AND letra = ?');
    $st->execute([$preguntaId, $letra]);
    $opcion = $st->fetch();

    if (!$opcion) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Opción no válida'], 422);
    }

    $correcta = (int) $opcion['es_correcta'] === 1 ? 1 : 0;
    $yaRespondida = false;

    $st = $pdo->prepare('SELECT correcta, letra_elegida FROM respuestas WHERE sesion_id = ? AND pregunta_id = ?');
    $st->execute([$sesionId, $preguntaId]);
    $previa = $st->fetch();

    if ($previa) {
        // Doble clic: no se vuelve a contar.
        $yaRespondida = true;
        $correcta = (int) $previa['correcta'];
    } else {
        $pdo->prepare(
            'INSERT INTO respuestas (sesion_id, pregunta_id, letra_elegida, correcta, respondida_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$sesionId, $preguntaId, $letra, $correcta]);

        $pdo->prepare('UPDATE sesiones SET posicion = posicion + 1, respondidas = respondidas + 1 WHERE id = ?')
            ->execute([$sesionId]);

        $pdo->prepare(
            'UPDATE preguntas
                SET veces_vista = veces_vista + 1,
                    veces_fallada = veces_fallada + ?
              WHERE id = ?'
        )->execute([$correcta ? 0 : 1, $preguntaId]);
    }

    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['ok' => false, 'error' => 'Error interno al guardar la respuesta'], 500);
}

$posFinal = (int) $sesion['posicion'] + ($yaRespondida ? 0 : 1);
$fin = $posFinal >= count($cola);

$datos = [
    'ok' => true,
    'yaRespondida' => $yaRespondida,
    'fin' => $fin,
    'respondidas' => (int) $sesion['respondidas'] + ($yaRespondida ? 0 : 1),
    'total' => count($cola),
];

// En modo examen no se filtra nada: el alumno no sabe si acertó.
if ($sesion['modo'] !== 'examen') {
    $st = $pdo->prepare('SELECT letra FROM opciones WHERE pregunta_id = ? AND es_correcta = 1');
    $st->execute([$preguntaId]);
    $correctaLetra = (string) $st->fetchColumn();

    $st = $pdo->prepare('SELECT explicacion FROM preguntas WHERE id = ?');
    $st->execute([$preguntaId]);

    $datos['correcta'] = $correcta === 1;
    $datos['letra_correcta'] = $correctaLetra;
    $datos['explicacion'] = (string) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM respuestas WHERE sesion_id = ? AND correcta = 1');
    $st->execute([$sesionId]);
    $datos['aciertos'] = (int) $st->fetchColumn();
}

json_out($datos);
