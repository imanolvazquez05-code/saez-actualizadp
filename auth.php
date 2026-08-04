<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// POST /auth.php?action=register
// POST /auth.php?action=login
// POST /auth.php?action=logout

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── REGISTER ─────────────────────────────────────────────────
if ($action === 'register') {
    $username = trim($body['username'] ?? '');
    $name     = trim($body['name']     ?? '');
    $email    = trim($body['email']    ?? '');
    $password =       $body['password']  ?? '';
    $bio      = trim($body['bio']      ?? '');
    $avColor  = intval($body['av_color'] ?? 0);

    if (!$username || !$name || !$email || !$password)
        jsonError('Completá todos los campos obligatorios');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonError('El email no es válido');
    if (strlen($password) < 6)
        jsonError('La contraseña debe tener al menos 6 caracteres');
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username))
        jsonError('El usuario solo puede tener letras, números, puntos y guiones bajos');

    $db  = getDB();
    $chk = $db->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
    $chk->execute([$username, $email]);
    if ($chk->fetch()) jsonError('El usuario o email ya existe');

    $hash     = password_hash($password, PASSWORD_BCRYPT);
    $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', $name))));

    $db->prepare('INSERT INTO users (username,name,email,password,bio,av_color) VALUES (?,?,?,?,?,?)')
       ->execute([$username, $name, $email, $hash, $bio, $avColor]);
    $userId = (int)$db->lastInsertId();

    $token = makeSession($db, $userId);
    jsonOk(['token' => $token, 'user' => fetchUser($db, $userId)], 'Cuenta creada');
}

// ── LOGIN ─────────────────────────────────────────────────────
if ($action === 'login') {
    $login    = trim($body['login']    ?? '');
    $password =       $body['password']  ?? '';

    if (!$login || !$password) jsonError('Ingresá usuario y contraseña');

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password']))
        jsonError('Usuario o contraseña incorrectos', 401);

    $token = makeSession($db, $user['id']);
    unset($user['password']);
    jsonOk(['token' => $token, 'user' => $user], 'Sesión iniciada');
}

// ── LOGOUT ────────────────────────────────────────────────────
if ($action === 'logout') {
    $h = getallheaders();
    $t = $h['X-Token'] ?? $h['x-token'] ?? '';
    if ($t) getDB()->prepare('DELETE FROM sessions WHERE token=?')->execute([$t]);
    jsonOk([], 'Sesión cerrada');
}

jsonError('Acción no válida');

// ── Helpers ───────────────────────────────────────────────────
function makeSession(PDO $db, int $uid): string {
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_HOURS * 3600);
    $db->prepare('INSERT INTO sessions (user_id,token,expires_at) VALUES (?,?,?)')
       ->execute([$uid, $token, $expires]);
    return $token;
}

function fetchUser(PDO $db, int $id): array {
    $s = $db->prepare('SELECT id,username,name,email,bio,website,avatar_url,av_color,created_at FROM users WHERE id=?');
    $s->execute([$id]);
    return $s->fetch();
}
