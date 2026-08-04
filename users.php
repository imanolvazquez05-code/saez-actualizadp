<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// GET  users.php?action=me              Mi perfil
// GET  users.php?action=profile&uid=X   Perfil de otro usuario
// POST users.php?action=update          Editar mi perfil
// POST users.php?action=follow&uid=X   Seguir / dejar de seguir
// GET  users.php?action=search&q=texto  Buscar usuarios

$action = $_GET['action'] ?? '';
$me     = requireAuth();
$db     = getDB();

// ── MI PERFIL ─────────────────────────────────────────────────
if ($action === 'me') {
    jsonOk(['user' => fetchProfile($db, $me['id'], $me['id'])]);
}

// ── PERFIL DE OTRO USUARIO ────────────────────────────────────
if ($action === 'profile') {
    $uid = intval($_GET['uid'] ?? 0);
    $u   = fetchProfile($db, $uid, $me['id']);
    if (!$u) jsonError('Usuario no encontrado', 404);
    jsonOk(['user' => $u]);
}

// ── ACTUALIZAR MI PERFIL ──────────────────────────────────────
if ($action === 'update' && in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT'])) {
    $body = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

    $sets = []; $params = [];

    if (!empty($body['name'])) {
        $sets[] = 'name=?'; $params[] = trim($body['name']);
    }
    if (isset($body['bio']))     { $sets[] = 'bio=?';     $params[] = trim($body['bio']); }
    if (isset($body['website'])) { $sets[] = 'website=?'; $params[] = trim($body['website']); }
    if (isset($body['av_color'])){ $sets[] = 'av_color=?';$params[] = intval($body['av_color']); }

    if (!empty($body['password']) && strlen($body['password']) >= 6) {
        $sets[] = 'password=?';
        $params[] = password_hash($body['password'], PASSWORD_BCRYPT);
    }

    if (!empty($_FILES['avatar']['name'])) {
        $url = saveUploadedImage($_FILES['avatar'], 'avatars');
        // Borrar avatar anterior
        $old = $db->prepare('SELECT avatar_url FROM users WHERE id=?'); $old->execute([$me['id']]);
        $oldUrl = $old->fetchColumn();
        if ($oldUrl && file_exists(__DIR__.'/'.$oldUrl)) unlink(__DIR__.'/'.$oldUrl);
        $sets[] = 'avatar_url=?'; $params[] = $url;
    }

    if (!empty($sets)) {
        $params[] = $me['id'];
        $db->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($params);
    }

    jsonOk(['user' => fetchProfile($db, $me['id'], $me['id'])], 'Perfil actualizado');
}

// ── SEGUIR / DEJAR DE SEGUIR ──────────────────────────────────
if ($action === 'follow' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = intval($_GET['uid'] ?? 0);
    if ($uid === $me['id']) jsonError('No podés seguirte a vos mismo');

    $has = $db->prepare('SELECT 1 FROM follows WHERE follower_id=? AND following_id=?');
    $has->execute([$me['id'], $uid]);

    if ($has->fetch()) {
        $db->prepare('DELETE FROM follows WHERE follower_id=? AND following_id=?')->execute([$me['id'], $uid]);
        jsonOk(['following' => false], 'Dejaste de seguir');
    }
    $db->prepare('INSERT IGNORE INTO follows (follower_id,following_id) VALUES (?,?)')->execute([$me['id'], $uid]);
    $db->prepare('INSERT INTO notifications (user_id,from_user,type) VALUES (?,?,?)')->execute([$uid, $me['id'], 'follow']);
    jsonOk(['following' => true], 'Siguiendo');
}

// ── BUSCAR USUARIOS ───────────────────────────────────────────
if ($action === 'search') {
    $q    = '%'.trim($_GET['q'] ?? '').'%';
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.name, u.bio, u.avatar_url, u.av_color,
               (SELECT COUNT(*) FROM follows WHERE following_id=u.id) AS followers_count,
               EXISTS(SELECT 1 FROM follows WHERE follower_id=? AND following_id=u.id) AS is_following
        FROM users u
        WHERE u.id != ? AND (u.username LIKE ? OR u.name LIKE ?)
        ORDER BY followers_count DESC LIMIT 20
    ');
    $stmt->execute([$me['id'], $me['id'], $q, $q]);
    $users = array_map(function($u){
        $u['is_following'] = (bool)$u['is_following'];
        $u['followers_count'] = (int)$u['followers_count'];
        if ($u['avatar_url']) $u['avatar_url'] = BASE_URL.'/'.$u['avatar_url'];
        return $u;
    }, $stmt->fetchAll());
    jsonOk(['users' => $users]);
}

jsonError('Acción no válida');

function fetchProfile(PDO $db, int $uid, int $meId): array|false {
    $s = $db->prepare('
        SELECT u.id, u.username, u.name, u.bio, u.website, u.avatar_url, u.av_color, u.created_at,
               (SELECT COUNT(*) FROM follows WHERE following_id=u.id) AS followers_count,
               (SELECT COUNT(*) FROM follows WHERE follower_id=u.id)  AS following_count,
               (SELECT COUNT(*) FROM posts   WHERE user_id=u.id)      AS posts_count,
               EXISTS(SELECT 1 FROM follows WHERE follower_id=? AND following_id=u.id) AS is_following
        FROM users u WHERE u.id=?
    ');
    $s->execute([$meId, $uid]);
    $u = $s->fetch();
    if (!$u) return false;
    $u['is_following']    = (bool)$u['is_following'];
    $u['followers_count'] = (int)$u['followers_count'];
    $u['following_count'] = (int)$u['following_count'];
    $u['posts_count']     = (int)$u['posts_count'];
    if ($u['avatar_url']) $u['avatar_url'] = BASE_URL . '/' . $u['avatar_url'];
    return $u;
}
