<?php
declare(strict_types=1);

/**
 * Importador del banco de preguntas.
 *
 *   php db/importar.php              -> limpia TODO y reimporta las 400 preguntas
 *   php db/importar.php --sin-reset  -> conserva el historial y solo añade preguntas nuevas
 */

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta desde la linea de comandos:\n  php db/importar.php\n");
}

require __DIR__ . '/../config.php';

const BANCO_TXT = __DIR__ . '/banco.txt';
const SCHEMA_SQL = __DIR__ . '/schema.sql';

$sinReset = in_array('--sin-reset', $argv, true);

// ---------------------------------------------------------------
// 1. Conexión sin base de datos seleccionada + crear esquema
// ---------------------------------------------------------------
$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "==> Conectado a MariaDB " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

$pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `" . DB_NAME . "`");

$schema = file_get_contents(SCHEMA_SQL);
if ($schema === false) {
    exit("No se pudo leer schema.sql\n");
}
$schema = preg_replace('/^--.*$/m', '', $schema);
foreach (explode(';', $schema) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        $pdo->exec($stmt);
    }
}
echo "==> Esquema verificado/creado\n";

// Migracion: columnas que pueden faltar en bases creadas con versiones anteriores.
$migraciones = [
    'alias' => 'ALTER TABLE sesiones ADD COLUMN alias VARCHAR(24) NULL AFTER area_id',
    'semilla' => 'ALTER TABLE sesiones ADD COLUMN semilla INT UNSIGNED NOT NULL DEFAULT 0 AFTER alias',
];
foreach ($migraciones as $columna => $ddl) {
    if (!$pdo->query("SHOW COLUMNS FROM sesiones LIKE '$columna'")->fetch()) {
        $pdo->exec($ddl);
        echo "==> Columna sesiones.$columna anadida\n";
    }
}

// ---------------------------------------------------------------
// 2. Parseo del banco de texto
// ---------------------------------------------------------------
$crudo = @file_get_contents(BANCO_TXT);
if ($crudo === false || $crudo === '') {
    exit("No se encontro db/banco.txt\n");
}
$crudo = preg_replace('/^\xEF\xBB\xBF/', '', $crudo);
$lineas = preg_split('/\R/u', $crudo);

$areas = [];   // slug => [nombre, orden]
$preguntas = []; // [area_slug, numero, enunciado, explicacion, opciones[[letra,texto,correcta]]]

$areaActual = null;
$q = null;      // pregunta en construccion
$enExplicacion = false;

$marcador = '/\x{2190}\s*RESPUESTA\s+CORRECTA/u';
$esPie = static fn (string $l): bool => str_contains($l, 'Banco de preguntas');

$cerrarPregunta = static function () use (&$q, &$preguntas): void {
    if ($q === null) {
        return;
    }
    $preguntas[] = $q;
    $q = null;
};

foreach ($lineas as $cruda) {
    $linea = rtrim($cruda);
    if (trim($linea) === '' || $esPie($linea)) {
        continue;
    }

    // Cabecera de area
    if (preg_match('/^ÁREA\s*(\d+)\s*:\s*(.+)$/u', $linea, $m)) {
        $cerrarPregunta();
        $enExplicacion = false;
        $nombre = trim($m[2]);
        $sinTildes = strtr($nombre, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I',
            'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U',
        ]);
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $sinTildes), '-'));
        if (!isset($areas[$slug])) {
            $areas[$slug] = ['nombre' => $nombre, 'orden' => (int) $m[1]];
        }
        $areaActual = $slug;
        continue;
    }

    // Cabecera de pregunta
    if (preg_match('/^(\d{1,3})\.\s+(.+)$/u', $linea, $m)) {
        $cerrarPregunta();
        $enExplicacion = false;
        $q = [
            'area' => $areaActual,
            'numero' => (int) $m[1],
            'enunciado' => trim($m[2]),
            'explicacion' => '',
            'opciones' => [],
        ];
        continue;
    }

    if ($q === null) {
        continue; // texto suelto previo a la primera pregunta (portada)
    }

    // Opcion
    if (preg_match('/^([a-d])\)\s*(.*)$/ui', $linea, $m)) {
        $enExplicacion = false;
        $q['opciones'][] = ['letra' => strtolower($m[1]), 'texto' => trim($m[2]), 'correcta' => 0];
        continue;
    }

    // Explicacion
    if (preg_match('/^Explicaci[oó]n\s*:\s*(.*)$/ui', $linea, $m)) {
        $enExplicacion = true;
        $q['explicacion'] = trim($m[1]);
        continue;
    }

    // Linea de continuation (wrap de enunciado, de opcion o de explicacion)
    if ($enExplicacion) {
        $q['explicacion'] = trim($q['explicacion'] . ' ' . trim($linea));
    } elseif ($q['opciones'] !== []) {
        $i = count($q['opciones']) - 1;
        $q['opciones'][$i]['texto'] = trim($q['opciones'][$i]['texto'] . ' ' . trim($linea));
    } else {
        $q['enunciado'] = trim($q['enunciado'] . ' ' . trim($linea));
    }
}
$cerrarPregunta();

// ---------------------------------------------------------------
// 3. Validacion + deteccion de la respuesta correcta
// ---------------------------------------------------------------
$errores = [];
foreach ($preguntas as $i => &$p) {
    $ref = $p['area'] . ' #' . $p['numero'];

    if ($p['area'] === null) {
        $errores[] = "$ref: sin area asignada";
        continue;
    }
    if ($p['explicacion'] === '') {
        $errores[] = "$ref: sin explicacion";
    }

    $letras = array_column($p['opciones'], 'letra');
    sort($letras);
    if ($letras !== ['a', 'b', 'c', 'd']) {
        $errores[] = "$ref: letras invalidas (" . implode(',', $letras) . ")";
    }

    $aciertos = 0;
    foreach ($p['opciones'] as &$o) {
        if (preg_match($marcador, $o['texto'])) {
            $o['texto'] = trim(preg_replace($marcador, '', $o['texto']));
            $o['correcta'] = 1;
            $aciertos++;
        }
        if ($o['texto'] === '') {
            $errores[] = "$ref: opcion {$o['letra']} vacia";
        }
    }
    unset($o);

    if ($aciertos !== 1) {
        $errores[] = "$ref: $aciertos respuestas correctas detectadas (se esperaba 1)";
    }
}
unset($p);

if ($errores !== []) {
    echo "\nERRORES DE PARSEO (" . count($errores) . "):\n";
    foreach (array_slice($errores, 0, 30) as $e) {
        echo "  - $e\n";
    }
    exit(1);
}

$porArea = [];
foreach ($preguntas as $p) {
    $porArea[$p['area']] = ($porArea[$p['area']] ?? 0) + 1;
}
echo "==> Parseadas " . count($preguntas) . " preguntas en " . count($areas) . " areas\n";
foreach ($areas as $slug => $a) {
    echo "      - {$a['nombre']}: " . ($porArea[$slug] ?? 0) . "\n";
}
if (count($preguntas) !== 400) {
    exit("ERROR: se esperaban 400 preguntas y hay " . count($preguntas) . "\n");
}

// ---------------------------------------------------------------
// 4. Insercion en MySQL
// ---------------------------------------------------------------
if (!$sinReset) {
    // ATENCION: TRUNCATE es DDL y hace commit implicito, por eso va
    // fuera de la transaccion.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['respuestas', 'sesiones', 'opciones', 'preguntas', 'areas'] as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "==> Tablas limpiadas (historial reiniciado)\n";
}

$pdo->beginTransaction();

// Areas
$existeArea = $pdo->prepare('SELECT id FROM areas WHERE slug = ?');
$guardarArea = $pdo->prepare('INSERT INTO areas (nombre, slug) VALUES (?, ?)');
$idsAreas = [];
foreach ($areas as $slug => $a) {
    $existeArea->execute([$slug]);
    $id = $existeArea->fetchColumn();
    if ($id) {
        $idsAreas[$slug] = (int) $id;
    } else {
        $guardarArea->execute([$a['nombre'], $slug]);
        $idsAreas[$slug] = (int) $pdo->lastInsertId();
    }
}

// Preguntas
$existePreg = $pdo->prepare('SELECT id FROM preguntas WHERE area_id = ? AND numero = ?');
$insPreg = $pdo->prepare(
    'INSERT INTO preguntas (area_id, numero, enunciado, explicacion) VALUES (?, ?, ?, ?)'
);
$insOpc = $pdo->prepare(
    'INSERT INTO opciones (pregunta_id, letra, texto, es_correcta) VALUES (?, ?, ?, ?)'
);

$insertadas = 0;
$omitidas = 0;

foreach ($preguntas as $p) {
    $areaId = $idsAreas[$p['area']];

    $existePreg->execute([$areaId, $p['numero']]);
    $preguntaId = $existePreg->fetchColumn();

    if ($preguntaId) {
        if ($sinReset) {
            $omitidas++;
            continue;
        }
        $preguntaId = (int) $preguntaId;
    } else {
        $insPreg->execute([$areaId, $p['numero'], $p['enunciado'], $p['explicacion']]);
        $preguntaId = (int) $pdo->lastInsertId();
        $insertadas++;
    }

    foreach ($p['opciones'] as $o) {
        $insOpc->execute([$preguntaId, $o['letra'], $o['texto'], $o['correcta']]);
    }
}

$pdo->commit();

// ---------------------------------------------------------------
// 5. Verificacion final
// ---------------------------------------------------------------
$totales = [
    'areas' => (int) $pdo->query('SELECT COUNT(*) FROM areas')->fetchColumn(),
    'preguntas' => (int) $pdo->query('SELECT COUNT(*) FROM preguntas')->fetchColumn(),
    'opciones' => (int) $pdo->query('SELECT COUNT(*) FROM opciones')->fetchColumn(),
    'correctas' => (int) $pdo->query('SELECT COUNT(*) FROM opciones WHERE es_correcta = 1')->fetchColumn(),
    'con_explicacion' => (int) $pdo->query("SELECT COUNT(*) FROM preguntas WHERE explicacion <> ''")->fetchColumn(),
];

echo "\n==> Verificacion final en `" . DB_NAME . "`\n";
foreach ($totales as $k => $v) {
    echo "      $k: $v\n";
}
echo "      preguntas insertadas: $insertadas | omitidas: $omitidas\n";

$ok = $totales['areas'] === 4
    && $totales['preguntas'] === 400
    && $totales['opciones'] === 1600
    && $totales['correctas'] === 400
    && $totales['con_explicacion'] === 400;

echo $ok ? "\nIMPORTACION CORRECTA\n" : "\nIMPORTACION CON PROBLEMAS\n";
exit($ok ? 0 : 1);
