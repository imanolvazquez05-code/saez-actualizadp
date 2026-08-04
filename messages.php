<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// GET  messages.php?action=conversations   Lista de chats
// GET  messages.php?action=chat&uid=X      Mensajes con usuario X
// POST messages.php?action=send&uid=X      Enviar mensaje

$action = $_GET['action'] ?? '';
$me     = requireAuth();
$db     = getDB();

// ── CONVERSACIONES ────────────────────────────────────────────
// Estrategia: buscar todos los usuarios con quienes YO tengo mensajes,
// usando UNION para evitar subconsultas complejas que fallan en algunos MySQL.
if ($action === 'conversations') {
    $meId = (int)$me['id'];

    // 1. Obtener todos los user IDs con quienes hay conversación
    $stmtPartners = $db->prepare('
        SELECT DISTINCT
            CASE WHEN from_user = :me1 THEN to_user ELSE from_user END AS partner_id
        FROM messages
        WHERE from_user = :me2 OR to_user = :me3
    ');
    $stmtPartners->bindValue(':me1', $meId, PDO::PARAM_INT);
    $stmtPartners->bindValue(':me2', $meId, PDO::PARAM_INT);
    $stmtPartners->bindValue(':me3', $meId, PDO::PARAM_INT);
    $stmtPartners->execute();
    $partnerIds = array_column($stmtPartners->fetchAll(), 'partner_id');

    if (empty($partnerIds)) {
        jsonOk(['conversations' => []]);
    }

    $convs = [];
    foreach ($partnerIds as $pid) {
        $pid = (int)$pid;

        // Get partner info
        $uStmt = $db->prepare('SELECT id, username, name, avatar_url, av_color FROM users WHERE id = ?');
        $uStmt->execute([$pid]);
        $partner = $uStmt->fetch();
        if (!$partner) continue;

        // Get last message between me and this partner
        $lStmt = $db->prepare('
            SELECT id, text, created_at, from_user
            FROM messages
            WHERE (from_user = ? AND to_user = ?)
               OR (from_user = ? AND to_user = ?)
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $lStmt->execute([$meId, $pid, $pid, $meId]);
        $last = $lStmt->fetch();

        // Count unread (messages FROM partner TO me that are unread)
        $uCnt = $db->prepare('SELECT COUNT(*) FROM messages WHERE from_user = ? AND to_user = ? AND is_read = 0');
        $uCnt->execute([$pid, $meId]);
        $unread = (int)$uCnt->fetchColumn();

        if ($partner['avatar_url']) $partner['avatar_url'] = BASE_URL . '/' . $partner['avatar_url'];

        $convs[] = [
            'id'         => $partner['id'],
            'username'   => $partner['username'],
            'name'       => $partner['name'],
            'avatar_url' => $partner['avatar_url'],
            'av_color'   => $partner['av_color'],
            'last_text'  => $last ? $last['text'] : null,
            'last_ts'    => $last ? $last['created_at'] : null,
            'last_from'  => $last ? $last['from_user'] : null,
            'unread'     => $unread,
        ];
    }

    // Sort by last message time desc
    usort($convs, fn($a, $b) => strcmp($b['last_ts'] ?? '', $a['last_ts'] ?? ''));

    jsonOk(['conversations' => $convs]);
}

// ── MENSAJES DE UN CHAT ───────────────────────────────────────
if ($action === 'chat') {
    $uid  = intval($_GET['uid'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $lim  = 40;
    $off  = ($page - 1) * $lim;

    // Marcar como leídos
    $db->prepare('UPDATE messages SET is_read=1 WHERE from_user=? AND to_user=? AND is_read=0')
       ->execute([$uid, $me['id']]);

    $stmt = $db->prepare('
        SELECT m.*, u.username, u.name, u.avatar_url, u.av_color
        FROM messages m
        JOIN users u ON u.id = m.from_user
        WHERE (m.from_user = :me AND m.to_user = :uid)
           OR (m.from_user = :uid2 AND m.to_user = :me2)
        ORDER BY m.created_at DESC
        LIMIT :lim OFFSET :off
    ');
    $stmt->bindValue(':me',  $me['id'], PDO::PARAM_INT);
    $stmt->bindValue(':uid', $uid,      PDO::PARAM_INT);
    $stmt->bindValue(':uid2',$uid,      PDO::PARAM_INT);
    $stmt->bindValue(':me2', $me['id'], PDO::PARAM_INT);
    $stmt->bindValue(':lim', $lim,      PDO::PARAM_INT);
    $stmt->bindValue(':off', $off,      PDO::PARAM_INT);
    $stmt->execute();

    $msgs = array_map(function($m) {
        if ($m['avatar_url']) $m['avatar_url'] = BASE_URL . '/' . $m['avatar_url'];
        if ($m['image_url'])  $m['image_url']  = BASE_URL . '/' . $m['image_url'];
        return $m;
    }, array_reverse($stmt->fetchAll()));

    jsonOk(['messages' => $msgs, 'page' => $page]);
}

// ── ENVIAR MENSAJE ────────────────────────────────────────────
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = intval($_GET['uid'] ?? 0);
    if (!$uid || $uid === (int)$me['id']) jsonError('Destinatario inválido');

    // Verificar que el destinatario existe
    $chk = $db->prepare('SELECT id FROM users WHERE id = ?');
    $chk->execute([$uid]);
    if (!$chk->fetch()) jsonError('El usuario destinatario no existe');

    $text   = null;
    $imgUrl = null;

    if (!empty($_FILES['image']['name'])) {
        $imgUrl = saveUploadedImage($_FILES['image'], 'messages');
    } else {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $text = trim($body['text'] ?? '');
    }

    if (!$text && !$imgUrl) jsonError('El mensaje no puede estar vacío');

    $db->prepare('INSERT INTO messages (from_user, to_user, text, image_url) VALUES (?,?,?,?)')
       ->execute([$me['id'], $uid, $text, $imgUrl]);
    $mid = (int)$db->lastInsertId();

    $msg = $db->prepare('
        SELECT m.*, u.username, u.name, u.avatar_url, u.av_color
        FROM messages m
        JOIN users u ON u.id = m.from_user
        WHERE m.id = ?
    ');
    $msg->execute([$mid]);
    $m = $msg->fetch();
    if ($m['avatar_url']) $m['avatar_url'] = BASE_URL . '/' . $m['avatar_url'];
    if ($m['image_url'])  $m['image_url']  = BASE_URL . '/' . $m['image_url'];

    jsonOk(['message' => $m], 'Mensaje enviado');
}

jsonError('Acción no válida');
