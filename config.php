<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// ============================================================
//  config.php  —  Configuración
//  Ubicación: C:/xampp/htdocs/photogram/config.php
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'photogram');
define('DB_USER',    'root');
define('DB_PASS',    '');          // vacío por defecto en XAMPP
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL',      'http://localhost/photogram');
define('UPLOADS_URL',   BASE_URL . '/uploads');
define('UPLOADS_DIR',   __DIR__ . '/uploads/');
define('SESSION_HOURS', 72);
define('MAX_IMG_SIZE',  5 * 1024 * 1024);
define('ALLOWED_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);

// ── Conexión PDO ──────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Respuestas JSON ───────────────────────────────────────────
function jsonOk(array $data = [], string $msg = 'ok'): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'message' => $msg, 'data' => $data]);
    exit;
}

function jsonError(string $msg, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// ── Auth por token ────────────────────────────────────────────
function requireAuth(): array {
    $headers = getallheaders();
    $token   = $headers['X-Token'] ?? $headers['x-token'] ?? '';
    if (!$token) jsonError('Token requerido', 401);

    $db   = getDB();
    $stmt = $db->prepare('
        SELECT u.* FROM users u
        JOIN sessions s ON s.user_id = u.id
        WHERE s.token = ? AND s.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Sesión inválida o expirada', 401);
    return $user;
}

// ── Upload de imagen ──────────────────────────────────────────
function saveUploadedImage(array $file, string $subdir): string {
    if ($file['error'] !== UPLOAD_ERR_OK)        jsonError('Error al subir la imagen (código '.$file['error'].')');
    if ($file['size'] > MAX_IMG_SIZE)             jsonError('Imagen muy grande (máx 5 MB)');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES))          jsonError('Tipo de imagen no permitido: '.$mime);

    $ext      = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif'  => 'gif', 'image/webp' => 'webp', default => 'jpg'
    };
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dir      = UPLOADS_DIR . $subdir . '/';

    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename))
        jsonError('No se pudo guardar la imagen en el servidor');

    return 'uploads/' . $subdir . '/' . $filename;
}

// ── CORS para desarrollo ──────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
