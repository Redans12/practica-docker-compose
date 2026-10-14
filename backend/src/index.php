<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/correo.php';

const CACHE_KEY = 'reservaciones:lista';
const CACHE_TTL = 30;
const ESTADOS = ['pendiente', 'confirmada', 'cancelada'];
const CAMPOS = ['espacio', 'solicitante', 'correo', 'fecha', 'hora_inicio', 'hora_fin'];

function responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function conectarDb(): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')
    );
    return new PDO($dsn, getenv('DB_USER'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function conectarRedis(): Redis {
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'), 2);
    return $redis;
}

function invalidarCache(): void {
    try {
        conectarRedis()->del(CACHE_KEY);
    } catch (Throwable $e) {
        // Si Redis falla, la app sigue funcionando sin caché
    }
}

function leerJson(): array {
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) {
        responder(400, ['error' => 'El cuerpo debe ser un JSON válido']);
    }
    return $datos;
}

function validar(array $d): array {
    $e = [];
    foreach (CAMPOS as $c) {
        if (!isset($d[$c]) || !is_scalar($d[$c]) || trim((string) $d[$c]) === '') {
            $e[$c] = 'Campo obligatorio';
        }
    }

    if (!isset($e['espacio'])) {
        $espacio = trim((string) $d['espacio']);
        $largo = mb_strlen($espacio);
        if ($largo < 3 || $largo > 80) {
            $e['espacio'] = 'Debe tener entre 3 y 80 caracteres';
        } elseif (!preg_match('/^[\p{L}\p{N} .,#\-]+$/u', $espacio)) {
            $e['espacio'] = 'Solo se permiten letras, números, espacios y . , # -';
        }
    }

    if (!isset($e['solicitante'])) {
        $nombre = trim((string) $d['solicitante']);
        $largo = mb_strlen($nombre);
        if ($largo < 3 || $largo > 100) {
            $e['solicitante'] = 'Debe tener entre 3 y 100 caracteres';
        } elseif (!preg_match('/^[\p{L} .\'\-]+$/u', $nombre)) {
            $e['solicitante'] = 'Solo se permiten letras, espacios y . \' -';
        }
    }

    if (!isset($e['correo'])) {
        $correo = trim((string) $d['correo']);
        if (mb_strlen($correo) > 120) {
            $e['correo'] = 'Máximo 120 caracteres';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $e['correo'] = 'Correo no válido';
        }
    }

    if (!isset($e['fecha'])) {
        $p = explode('-', (string) $d['fecha']);
        if (count($p) !== 3 || !checkdate((int) $p[1], (int) $p[2], (int) $p[0])) {
            $e['fecha'] = 'Fecha no válida (use AAAA-MM-DD)';
        }
    }

    foreach (['hora_inicio', 'hora_fin'] as $h) {
        if (!isset($e[$h]) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $d[$h])) {
            $e[$h] = 'Hora no válida (use HH:MM)';
        }
    }
    if (!isset($e['hora_inicio']) && !isset($e['hora_fin'])
        && substr((string) $d['hora_fin'], 0, 5) <= substr((string) $d['hora_inicio'], 0, 5)) {
        $e['hora_fin'] = 'La hora de fin debe ser posterior a la de inicio';
    }

    if (isset($d['estado']) && !in_array($d['estado'], ESTADOS, true)) {
        $e['estado'] = 'Estado no válido';
    }
    return $e;
}

function verificarTraslape(PDO $pdo, array $d, ?int $excluirId): void {
    if (($d['estado'] ?? 'pendiente') === 'cancelada') {
        return;
    }
    $sql = "SELECT hora_inicio, hora_fin FROM reservaciones
            WHERE espacio = :espacio AND fecha = :fecha AND estado <> 'cancelada'
              AND hora_inicio < :fin AND hora_fin > :inicio";
    $params = [
        ':espacio' => trim((string) $d['espacio']),
        ':fecha' => $d['fecha'],
        ':fin' => $d['hora_fin'],
        ':inicio' => $d['hora_inicio'],
    ];
    if ($excluirId !== null) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $excluirId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $choque = $st->fetch();
    if ($choque) {
        $mensaje = sprintf(
            'El espacio ya está reservado de %s a %s ese día',
            substr($choque['hora_inicio'], 0, 5),
            substr($choque['hora_fin'], 0, 5)
        );
        responder(409, ['error' => $mensaje, 'campos' => ['hora_inicio' => $mensaje]]);
    }
}

function obtener(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM reservaciones WHERE id = :id');
    $st->execute([':id' => $id]);
    $fila = $st->fetch();
    return $fila ?: null;
}

function parametros(array $d): array {
    return [
        ':espacio' => trim((string) $d['espacio']),
        ':solicitante' => trim((string) $d['solicitante']),
        ':correo' => trim((string) $d['correo']),
        ':fecha' => $d['fecha'],
        ':hora_inicio' => $d['hora_inicio'],
        ':hora_fin' => $d['hora_fin'],
        ':estado' => $d['estado'] ?? 'pendiente',
    ];
}

function listar(): void {
    $redis = null;
    try {
        $redis = conectarRedis();
        $redis->incr('contador_consultas');
        $cache = $redis->get(CACHE_KEY);
        if ($cache !== false) {
            header('X-Cache: HIT');
            echo $cache;
            exit;
        }
    } catch (Throwable $e) {
        $redis = null;
    }

    $filas = conectarDb()
        ->query('SELECT * FROM reservaciones ORDER BY fecha, hora_inicio')
        ->fetchAll();
    $json = json_encode($filas, JSON_UNESCAPED_UNICODE);

    if ($redis) {
        try {
            $redis->setex(CACHE_KEY, CACHE_TTL, $json);
        } catch (Throwable $e) {
        }
    }
    header('X-Cache: MISS');
    echo $json;
    exit;
}

function crear(): void {
    $d = leerJson();
    $errores = validar($d);
    if ($errores) {
        responder(422, ['error' => 'Datos no válidos', 'campos' => $errores]);
    }
    $pdo = conectarDb();
    verificarTraslape($pdo, $d, null);
    $st = $pdo->prepare(
        'INSERT INTO reservaciones (espacio, solicitante, correo, fecha, hora_inicio, hora_fin, estado)
         VALUES (:espacio, :solicitante, :correo, :fecha, :hora_inicio, :hora_fin, :estado)'
    );
    $st->execute(parametros($d));
    $id = (int) $pdo->lastInsertId();
    invalidarCache();
    $reserva = obtener($pdo, $id);
    try {
        enviarCorreoCreacion($reserva);
    } catch (Throwable $e) {
        error_log('No se pudo enviar el correo: ' . $e->getMessage());
    }
    responder(201, $reserva);
}

function actualizar(int $id): void {
    $pdo = conectarDb();
    if (!obtener($pdo, $id)) {
        responder(404, ['error' => 'Reservación no encontrada']);
    }
    $d = leerJson();
    $errores = validar($d);
    if ($errores) {
        responder(422, ['error' => 'Datos no válidos', 'campos' => $errores]);
    }
    verificarTraslape($pdo, $d, $id);
    $st = $pdo->prepare(
        'UPDATE reservaciones SET espacio = :espacio, solicitante = :solicitante, correo = :correo,
         fecha = :fecha, hora_inicio = :hora_inicio, hora_fin = :hora_fin, estado = :estado
         WHERE id = :id'
    );
    $st->execute(parametros($d) + [':id' => $id]);
    invalidarCache();
    responder(200, obtener($pdo, $id));
}

function eliminar(int $id): void {
    $pdo = conectarDb();
    if (!obtener($pdo, $id)) {
        responder(404, ['error' => 'Reservación no encontrada']);
    }
    $pdo->prepare('DELETE FROM reservaciones WHERE id = :id')->execute([':id' => $id]);
    invalidarCache();
    responder(200, ['mensaje' => 'Reservación eliminada']);
}

function health(): void {
    try {
        conectarDb()->query('SELECT 1');
        conectarRedis()->ping();
        responder(200, ['status' => 'ok', 'mariadb' => 'ok', 'redis' => 'ok']);
    } catch (Throwable $e) {
        responder(503, ['status' => 'error', 'detalle' => $e->getMessage()]);
    }
}

$ruta = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
$partes = $ruta === '' ? [] : explode('/', $ruta);
$recurso = $partes[0] ?? '';
$id = null;
if (isset($partes[1])) {
    if (!ctype_digit($partes[1]) || count($partes) > 2) {
        responder(404, ['error' => 'Ruta no encontrada']);
    }
    $id = (int) $partes[1];
}

try {
    if ($recurso === 'health' && $metodo === 'GET' && $id === null) {
        health();
    }
    if ($recurso === 'reservaciones') {
        if ($id === null && $metodo === 'GET') listar();
        if ($id === null && $metodo === 'POST') crear();
        if ($id !== null && $metodo === 'PUT') actualizar($id);
        if ($id !== null && $metodo === 'DELETE') eliminar($id);
        responder(405, ['error' => 'Método no permitido']);
    }
    responder(404, ['error' => 'Ruta no encontrada']);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        responder(409, ['error' => 'Ya existe una reservación (activa o cancelada) con ese espacio, fecha y hora de inicio']);
    }
    error_log($e->getMessage());
    responder(500, ['error' => 'Error interno del servidor']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    responder(500, ['error' => 'Error interno del servidor']);
}