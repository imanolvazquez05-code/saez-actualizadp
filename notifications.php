<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// GET  notifications.php?action=list       Mis notificaciones
// POST notifications.php?action=read&id=X  Marcar leída
// POST notifications.php?action=readall    Marcar todas leídas

$action = $_GET['action'] ?? '';
$me     = requireAuth();
$db     = getDB();

if ($action === 'list') {
    $stmt = $db->prepare('
        SELECT n.*,
               u.username AS from_username, u.name AS from_name,
               u.avatar_url AS from_avatar, u.av_color AS from_av_color,
               p.image_url AS post_image
        FROM notifications n
        JOIN users u ON u.id = n.from_user
        LEFT JOIN posts p ON p.id = n.post_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 60
    ');
    $stmt->execute([$me['id']]);

    $notifs = array_map(function($n) {
        if ($n['from_avatar']) $n['from_avatar'] = BASE_URL . '/' . $n['from_avatar'];
        if ($n['post_image'])  $n['post_image']  = BASE_URL . '/' . $n['post_image'];
        $n['is_read'] = (bool)$n['is_read'];
        return $n;
    }, $stmt->fetchAll());

    $unread = count(array_filter($notifs, fn($n) => !$n['is_read']));
    jsonOk(['notifications' => $notifs, 'unread' => $unread]);
}

if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_GET['id'] ?? 0);
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, $me['id']]);
    jsonOk([], 'Leída');
}

if ($action === 'readall' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$me['id']]);
    jsonOk([], 'Todas leídas');
}

jsonError('Acción no válida');
