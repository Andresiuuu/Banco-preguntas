<?php
declare(strict_types=1);

/* ============================================================
 *  Entorno: credenciales de la base de datos desde /.env
 * ============================================================ */

/**
 * Lee un fichero .env sencillo (CLAVE=valor) sin dependencias externas.
 * Admite comentarios (# o ;), lineas en blanco y valores entre comillas.
 */
function cargar_env(string $ruta): array
{
    $valores = [];
    $texto = @file_get_contents($ruta);
    if ($texto === false) {
        return $valores;
    }

    foreach (preg_split('/\R/u', $texto) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || $linea[0] === ';') {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $linea, $m)) {
            continue;
        }

        $valor = trim($m[2]);
        $longitud = strlen($valor);
        if ($longitud >= 2) {
            $primero = $valor[0];
            $ultimo = $valor[$longitud - 1];
            if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                $valor = substr($valor, 1, -1);
                if ($primero === '"') {
                    $valor = str_replace(['\\n', '\\r', '\\t', '\\"'], ["\n", "\r", "\t", '"'], $valor);
                }
            }
        }
        $valores[$m[1]] = $valor;
    }

    return $valores;
}

$ENTORNO = cargar_env(__DIR__ . '/.env');

function env(string $clave, ?string $porDefecto = null): ?string
{
    global $ENTORNO;
    return $ENTORNO[$clave] ?? $porDefecto;
}

/* Credenciales: si falta .env se usan los valores por defecto de XAMPP */
define('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', (string) env('DB_NAME', 'banco_preguntas'));
define('DB_USER', (string) env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));

/* ============================================================
 *  Ajustes de la aplicacion
 * ============================================================ */

const APP_NOMBRE = 'Banco de Preguntas';
const APP_SUBTITULO = 'Desarrollo de Software · 400 preguntas';

const MODOS = [
    'estudio' => 'Modo Estudio',
    'examen' => 'Modo Examen',
    'errores' => 'Modo Errores',
];

/* ============================================================
 *  Utilidades
 * ============================================================ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('No se pudo conectar a la base de datos. Comprueba que MySQL de XAMPP este '
                . 'arrancado y que se haya importado el esquema (db/schema.sql + db/importar.php).');
        }
    }
    return $pdo;
}

function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function arrancar_sesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function csrf_token(): string
{
    arrancar_sesion();
    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    arrancar_sesion();
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Respuesta JSON y fin de la ejecucion.
 */
function json_out(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function modo_label(?string $modo): string
{
    return MODOS[$modo] ?? 'Desconocido';
}

function pct(int $aciertos, int $total): float
{
    return $total > 0 ? round($aciertos * 100 / $total, 1) : 0.0;
}

/**
 * Normaliza el nombre opcional para el ranking.
 * Devuelve [alias, error]. alias es null cuando se quiere jugar anonimo.
 */
function normalizar_alias(?string $crudo): array
{
    $alias = trim((string) $crudo);
    $alias = (string) preg_replace('/\s+/u', ' ', $alias);

    if ($alias === '') {
        return [null, null];
    }
    if (mb_strlen($alias) < 2) {
        return [null, 'El nombre necesita al menos 2 caracteres.'];
    }
    if (mb_strlen($alias) > 24) {
        return [null, 'El nombre puede tener como máximo 24 caracteres.'];
    }
    if (!preg_match('/^[\p{L}\p{N} ._\-]+$/u', $alias)) {
        return [null, 'Usa solo letras, números, espacios, puntos, guiones o guiones bajos.'];
    }
    return [$alias, null];
}

/**
 * Baraja las opciones de una pregunta de forma ALEATORIA PERO ESTABLE.
 *
 * La ordenación depende de la semilla de la ronda + el id de la pregunta,
 * así que al recargar la página las opciones no cambian de sitio y la
 * revisión (detalle.php) muestra exactamente lo que se vio al jugar.
 *
 * Devuelve las opciones con la letra REASIGNADA según la posición visible
 * (1ª opción = A, 2ª = B, ...) y conserva la letra original en 'origen',
 * que es la que se guarda en la base de datos.
 */
function mezclar_opciones(array $opciones, int $semilla, int $preguntaId): array
{
    $opciones = array_values($opciones);

    // xorshift32: PRNG determinista, sin tocar el estado global de PHP.
    $estado = (crc32($semilla . '|' . $preguntaId) | 1) & 0xFFFFFFFF;
    $paso = static function () use (&$estado): float {
        $estado = ($estado ^ (($estado << 13) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $estado = $estado ^ ($estado >> 17);
        $estado = ($estado ^ (($estado << 5) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        return $estado / 4294967296;
    };

    for ($i = count($opciones) - 1; $i > 0; $i--) {
        $j = (int) floor($paso() * ($i + 1));
        [$opciones[$i], $opciones[$j]] = [$opciones[$j], $opciones[$i]];
    }

    $letras = ['a', 'b', 'c', 'd'];
    foreach ($opciones as $k => $o) {
        $opciones[$k]['origen'] = $o['letra'];
        $opciones[$k]['letra'] = $letras[$k] ?? $o['letra'];
    }

    return $opciones;
}

/**
 * Cierra una sesion de estudio/examen y calcula su nota (aciertos/total).
 * Es idempotente: si ya esta finalizada solo devuelve sus datos.
 */
function finalizar_sesion(int $sesionId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM sesiones WHERE id = ?');
    $st->execute([$sesionId]);
    $s = $st->fetch();
    if (!$s) {
        return [];
    }
    if ($s['estado'] === 'jugando') {
        $pdo->prepare(
            'UPDATE sesiones
                SET aciertos = (SELECT COUNT(*) FROM respuestas WHERE sesion_id = ? AND correcta = 1),
                    respondidas = (SELECT COUNT(*) FROM respuestas WHERE sesion_id = ?),
                    estado = "finalizada",
                    finalizada_at = NOW()
              WHERE id = ?'
        )->execute([$sesionId, $sesionId, $sesionId]);
        $st->execute([$sesionId]);
        $s = $st->fetch();
    }
    return $s;
}
