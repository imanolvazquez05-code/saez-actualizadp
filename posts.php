<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// GET    posts.php?action=feed           Feed ordenado por fecha desc
// GET    posts.php?action=user&uid=X     Posts de un usuario
// POST   posts.php?action=create         Crear post (multipart, acepta tagged_users JSON)
// DELETE posts.php?action=delete&id=X   Borrar post propio
// POST   posts.php?action=like&id=X     Like/unlike
// POST   posts.php?action=save&id=X     Guardar/quitar
// GET    posts.php?action=comments&id=X Comentarios
// POST   posts.php?action=comment&id=X  Agregar comentario
// DELETE posts.php?action=delcomment&id=X Borrar comentario propio
// GET    posts.php?action=search&q=X    Buscar posts
// GET    posts.php?action=single&id=X   Un post por ID

$action = $_GET['action'] ?? '';
$me     = requireAuth();
$db     = getDB();

// ── FEED ─────────────────────────────────────────────────────
if ($action === 'feed') {
    $page  = max(1, intval($_GET['page'] ?? 1));
    $limit = 15;
    $off   = ($page - 1) * $limit;

    // Collect all user IDs to show in feed: me + everyone I follow
    $followStmt = $db->prepare('SELECT following_id FROM follows WHERE follower_id = ?');
    $followStmt->execute([$me['id']]);
    $followingIds = array_column($followStmt->fetchAll(), 'following_id');
    $feedUserIds  = array_merge([(int)$me['id']], array_map('intval', $followingIds));
    $feedUserIds  = array_unique($feedUserIds);

    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($feedUserIds), '?'));

    // Bind params: me1, me2 for subqueries, then feedUserIds for IN, then limit/offset
    $params = [];
    $params[] = (int)$me['id']; // liked subquery
    $params[] = (int)$me['id']; // saved subquery
    foreach ($feedUserIds as $uid) $params[] = $uid;
    $params[] = $limit;
    $params[] = $off;

    $stmt = $db->prepare("
        SELECT p.*,
               u.username, u.name, u.avatar_url, u.av_color,
               (SELECT COUNT(*) FROM likes    WHERE post_id = p.id) AS likes_count,
               (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS comments_count,
               EXISTS(SELECT 1 FROM likes       WHERE post_id=p.id AND user_id=?) AS liked,
               EXISTS(SELECT 1 FROM saved_posts WHERE post_id=p.id AND user_id=?) AS saved
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE p.user_id IN ($placeholders)
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    $posts = array_map('enrichPost', $stmt->fetchAll());
    foreach ($posts as &$p) $p['tags'] = getPostTags($db, $p['id']);
    jsonOk(['posts' => $posts, 'page' => $page]);
}

// ── SINGLE POST ───────────────────────────────────────────────
if ($action === 'single') {
    $pid  = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare('
        SELECT p.*, u.username, u.name, u.avatar_url, u.av_color,
               (SELECT COUNT(*) FROM likes    WHERE post_id=p.id) AS likes_count,
               (SELECT COUNT(*) FROM comments WHERE post_id=p.id) AS comments_count,
               EXISTS(SELECT 1 FROM likes       WHERE post_id=p.id AND user_id=?) AS liked,
               EXISTS(SELECT 1 FROM saved_posts WHERE post_id=p.id AND user_id=?) AS saved
        FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=?
    ');
    $stmt->execute([$me['id'], $me['id'], $pid]);
    $p = $stmt->fetch();
    if (!$p) jsonError('Post no encontrado', 404);
    $p = enrichPost($p);
    $p['tags'] = getPostTags($db, $pid);
    jsonOk(['post' => $p]);
}

// ── POSTS DE UN USUARIO ───────────────────────────────────────
if ($action === 'user') {
    $uid  = intval($_GET['uid'] ?? 0);
    $stmt = $db->prepare('
        SELECT p.*, u.username, u.name, u.avatar_url, u.av_color,
               (SELECT COUNT(*) FROM likes    WHERE post_id=p.id) AS likes_count,
               (SELECT COUNT(*) FROM comments WHERE post_id=p.id) AS comments_count,
               EXISTS(SELECT 1 FROM likes       WHERE post_id=p.id AND user_id=?) AS liked,
               EXISTS(SELECT 1 FROM saved_posts WHERE post_id=p.id AND user_id=?) AS saved
        FROM posts p JOIN users u ON u.id=p.user_id
        WHERE p.user_id=?
        ORDER BY p.created_at DESC
    ');
    $stmt->execute([$me['id'], $me['id'], $uid]);
    $posts = array_map('enrichPost', $stmt->fetchAll());
    foreach ($posts as &$p) $p['tags'] = getPostTags($db, $p['id']);
    jsonOk(['posts' => $posts]);
}

// ── BUSCAR POSTS ──────────────────────────────────────────────
if ($action === 'search') {
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare('
        SELECT p.*, u.username, u.name, u.avatar_url, u.av_color,
               (SELECT COUNT(*) FROM likes WHERE post_id=p.id) AS likes_count,
               (SELECT COUNT(*) FROM comments WHERE post_id=p.id) AS comments_count,
               EXISTS(SELECT 1 FROM likes WHERE post_id=p.id AND user_id=?) AS liked,
               EXISTS(SELECT 1 FROM saved_posts WHERE post_id=p.id AND user_id=?) AS saved
        FROM posts p JOIN users u ON u.id=p.user_id
        WHERE p.caption LIKE ? OR p.location LIKE ?
        ORDER BY p.created_at DESC LIMIT 30
    ');
    $stmt->execute([$me['id'], $me['id'], $q, $q]);
    $posts = array_map('enrichPost', $stmt->fetchAll());
    foreach ($posts as &$p) $p['tags'] = getPostTags($db, $p['id']);
    jsonOk(['posts' => $posts]);
}

// ── CREAR POST ────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $caption     = trim($_POST['caption']  ?? '');
    $location    = trim($_POST['location'] ?? '');
    $taggedRaw   = trim($_POST['tagged_users'] ?? '[]');
    $taggedUsers = json_decode($taggedRaw, true) ?: [];
    $imgUrl      = null;

    if (!empty($_FILES['image']['name'])) {
        $imgUrl = saveUploadedImage($_FILES['image'], 'posts');
    }
    if (!$imgUrl && !$caption) jsonError('El post necesita al menos una imagen o un caption');

    $db->prepare('INSERT INTO posts (user_id,image_url,caption,location) VALUES (?,?,?,?)')
       ->execute([$me['id'], $imgUrl, $caption ?: null, $location ?: null]);
    $pid = (int)$db->lastInsertId();

    // Save tags
    if (!empty($taggedUsers)) {
        $tagStmt = $db->prepare('INSERT IGNORE INTO post_tags (post_id, user_id) VALUES (?,?)');
        $notifStmt = $db->prepare('INSERT INTO notifications (user_id,from_user,type,post_id) VALUES (?,?,?,?)');
        foreach ($taggedUsers as $uid) {
            $uid = intval($uid);
            if ($uid && $uid !== (int)$me['id']) {
                $tagStmt->execute([$pid, $uid]);
                $notifStmt->execute([$uid, $me['id'], 'mention', $pid]);
            }
        }
    }

    $post = $db->prepare('
        SELECT p.*, u.username, u.name, u.avatar_url, u.av_color,
               0 AS likes_count, 0 AS comments_count, 0 AS liked, 0 AS saved
        FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=?
    ');
    $post->execute([$pid]);
    $p = enrichPost($post->fetch());
    $p['tags'] = getPostTags($db, $pid);
    jsonOk(['post' => $p], 'Post publicado');
}

// ── BORRAR POST ───────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pid = intval($_GET['id'] ?? 0);
    $chk = $db->prepare('SELECT id,image_url FROM posts WHERE id=? AND user_id=?');
    $chk->execute([$pid, $me['id']]);
    $p = $chk->fetch();
    if (!$p) jsonError('Post no encontrado', 404);
    if ($p['image_url'] && file_exists(__DIR__.'/'.$p['image_url'])) unlink(__DIR__.'/'.$p['image_url']);
    $db->prepare('DELETE FROM posts WHERE id=?')->execute([$pid]);
    jsonOk([], 'Post eliminado');
}

// ── LIKE / UNLIKE ─────────────────────────────────────────────
if ($action === 'like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid  = intval($_GET['id'] ?? 0);
    $post = $db->prepare('SELECT id,user_id FROM posts WHERE id=?');
    $post->execute([$pid]); $p = $post->fetch();
    if (!$p) jsonError('Post no encontrado', 404);

    $has = $db->prepare('SELECT 1 FROM likes WHERE user_id=? AND post_id=?');
    $has->execute([$me['id'], $pid]);
    if ($has->fetch()) {
        $db->prepare('DELETE FROM likes WHERE user_id=? AND post_id=?')->execute([$me['id'], $pid]);
        $liked = false;
    } else {
        $db->prepare('INSERT IGNORE INTO likes (user_id,post_id) VALUES (?,?)')->execute([$me['id'], $pid]);
        $liked = true;
        if ($p['user_id'] != $me['id'])
            $db->prepare('INSERT INTO notifications (user_id,from_user,type,post_id) VALUES (?,?,?,?)')
               ->execute([$p['user_id'], $me['id'], 'like', $pid]);
    }
    $r = $db->prepare('SELECT COUNT(*) FROM likes WHERE post_id=?'); $r->execute([$pid]);
    jsonOk(['liked' => $liked, 'likes' => (int)$r->fetchColumn()]);
}

// ── GUARDAR / QUITAR ─────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = intval($_GET['id'] ?? 0);
    $has = $db->prepare('SELECT 1 FROM saved_posts WHERE user_id=? AND post_id=?');
    $has->execute([$me['id'], $pid]);
    if ($has->fetch()) {
        $db->prepare('DELETE FROM saved_posts WHERE user_id=? AND post_id=?')->execute([$me['id'], $pid]);
        jsonOk(['saved' => false], 'Removido');
    }
    $db->prepare('INSERT IGNORE INTO saved_posts (user_id,post_id) VALUES (?,?)')->execute([$me['id'], $pid]);
    jsonOk(['saved' => true], 'Guardado');
}

// ── COMENTARIOS ───────────────────────────────────────────────
if ($action === 'comments') {
    $pid  = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare('
        SELECT c.*, u.username, u.name, u.avatar_url, u.av_color
        FROM comments c JOIN users u ON u.id=c.user_id
        WHERE c.post_id=? ORDER BY c.created_at ASC
    ');
    $stmt->execute([$pid]);
    $cmts = array_map(function($c) {
        if ($c['avatar_url']) $c['avatar_url'] = BASE_URL . '/' . $c['avatar_url'];
        return $c;
    }, $stmt->fetchAll());
    jsonOk(['comments' => $cmts]);
}

if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid  = intval($_GET['id'] ?? 0);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $text = trim($body['text'] ?? '');
    if (!$text) jsonError('El comentario no puede estar vacío');

    $db->prepare('INSERT INTO comments (post_id,user_id,text) VALUES (?,?,?)')->execute([$pid, $me['id'], $text]);
    $cid = (int)$db->lastInsertId();

    $own = $db->prepare('SELECT user_id FROM posts WHERE id=?'); $own->execute([$pid]);
    $oid = (int)$own->fetchColumn();
    if ($oid && $oid !== (int)$me['id'])
        $db->prepare('INSERT INTO notifications (user_id,from_user,type,post_id) VALUES (?,?,?,?)')
           ->execute([$oid, $me['id'], 'comment', $pid]);

    $c = $db->prepare('SELECT c.*,u.username,u.name,u.avatar_url,u.av_color FROM comments c JOIN users u ON u.id=c.user_id WHERE c.id=?');
    $c->execute([$cid]); $cmt = $c->fetch();
    if ($cmt['avatar_url']) $cmt['avatar_url'] = BASE_URL . '/' . $cmt['avatar_url'];
    jsonOk(['comment' => $cmt], 'Comentario publicado');
}

if ($action === 'delcomment' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $cid = intval($_GET['id'] ?? 0);
    $db->prepare('DELETE FROM comments WHERE id=? AND user_id=?')->execute([$cid, $me['id']]);
    jsonOk([], 'Comentario eliminado');
}

// ── BUSCAR USUARIOS PARA ETIQUETAS ───────────────────────────
if ($action === 'searchtag') {
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare('
        SELECT id, username, name, avatar_url, av_color
        FROM users WHERE id != ? AND (username LIKE ? OR name LIKE ?)
        LIMIT 8
    ');
    $stmt->execute([$me['id'], $q, $q]);
    $users = array_map(function($u) {
        if ($u['avatar_url']) $u['avatar_url'] = BASE_URL . '/' . $u['avatar_url'];
        return $u;
    }, $stmt->fetchAll());
    jsonOk(['users' => $users]);
}

jsonError('Acción no válida');

// ── HELPERS ───────────────────────────────────────────────────
function enrichPost(array $p): array {
    $p['liked']          = (bool)$p['liked'];
    $p['saved']          = (bool)$p['saved'];
    $p['likes_count']    = (int)$p['likes_count'];
    $p['comments_count'] = (int)$p['comments_count'];
    if ($p['image_url'])  $p['image_url']  = BASE_URL . '/' . $p['image_url'];
    if ($p['avatar_url']) $p['avatar_url'] = BASE_URL . '/' . $p['avatar_url'];
    return $p;
}

function getPostTags(PDO $db, int $pid): array {
    try {
        $s = $db->prepare('
            SELECT u.id, u.username, u.name, u.avatar_url, u.av_color
            FROM post_tags pt JOIN users u ON u.id = pt.user_id
            WHERE pt.post_id = ?
        ');
        $s->execute([$pid]);
        return array_map(function($u) {
            if ($u['avatar_url']) $u['avatar_url'] = BASE_URL . '/' . $u['avatar_url'];
            return $u;
        }, $s->fetchAll());
    } catch (Exception $e) {
        return []; // table may not exist yet
    }
}
