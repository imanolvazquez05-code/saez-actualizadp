<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';

// GET  stories.php?action=list         Historias activas (mías + de quienes sigo)
// POST stories.php?action=create       Crear historia (multipart: image, caption opcional)
// POST stories.php?action=view&id=X    Marcar historia como vista

$action = $_GET['action'] ?? '';
$me     = requireAuth();
$db     = getDB();

// ── LISTAR HISTORIAS ───────────────────────────────────────────
if ($action === 'list') {
    $meId = (int)$me['id'];

    // Usuarios a mostrar: yo + a quienes sigo
    $followStmt = $db->prepare('SELECT following_id FROM follows WHERE follower_id = ?');
    $followStmt->execute([$meId]);
    $followingIds = array_column($followStmt->fetchAll(), 'following_id');
    $userIds = array_unique(array_merge([$meId], array_map('intval', $followingIds)));

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $stmt = $db->prepare("
        SELECT s.*, u.username, u.name, u.avatar_url, u.av_color
        FROM stories s
        JOIN users u ON u.id = s.user_id
        WHERE s.user_id IN ($placeholders) AND s.expires_at > NOW()
        ORDER BY s.user_id, s.created_at ASC
    ");
    $stmt->execute($userIds);
    $rows = $stmt->fetchAll();

    // Vistas del usuario actual, para marcar seen_by_me
    $seenStmt = $db->prepare('SELECT story_id FROM story_views WHERE user_id = ?');
    $seenStmt->execute([$meId]);
    $seenIds = array_flip(array_column($seenStmt->fetchAll(), 'story_id'));

    // Agrupar por usuario
    $groupsByUser = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($groupsByUser[$uid])) {
            $groupsByUser[$uid] = [
                'user_id'    => $uid,
                'username'   => $r['username'],
                'name'       => $r['name'],
                'avatar_url' => $r['avatar_url'] ? BASE_URL . '/' . $r['avatar_url'] : null,
                'av_color'   => $r['av_color'],
                'stories'    => [],
            ];
        }

        $isMine = $uid === $meId;
        $seen   = isset($seenIds[$r['id']]);

        $viewsCount = 0;
        if ($isMine) {
            $vc = $db->prepare('SELECT COUNT(*) FROM story_views WHERE story_id = ?');
            $vc->execute([$r['id']]);
            $viewsCount = (int)$vc->fetchColumn();
        }

        $groupsByUser[$uid]['stories'][] = [
            'id'          => (int)$r['id'],
            'image_url'   => BASE_URL . '/' . $r['image_url'],
            'caption'     => $r['caption'],
            'created_at'  => $r['created_at'],
            'expires_at'  => $r['expires_at'],
            'is_mine'     => $isMine,
            'seen_by_me'  => $isMine ? true : $seen,
            'views_count' => $viewsCount,
        ];
    }

    $groups = [];
    foreach ($groupsByUser as $g) {
        $g['all_seen'] = !array_filter($g['stories'], fn($s) => !$s['seen_by_me']);
        $groups[] = $g;
    }

    // Orden: yo primero (si tengo historia), luego el resto
    usort($groups, function($a, $b) use ($meId) {
        if ($a['user_id'] === $meId) return -1;
        if ($b['user_id'] === $meId) return 1;
        return 0;
    });

    jsonOk(['groups' => $groups]);
}

// ── CREAR HISTORIA ──────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['image']['name'])) jsonError('La historia necesita una imagen');

    $imgUrl  = saveUploadedImage($_FILES['image'], 'stories');
    $caption = trim($_POST['caption'] ?? '');

    $db->prepare('
        INSERT INTO stories (user_id, image_url, caption, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ')->execute([$me['id'], $imgUrl, $caption ?: null]);

    jsonOk(['id' => (int)$db->lastInsertId()], 'Historia publicada');
}

// ── MARCAR VISTA ─────────────────────────────────────────────────
if ($action === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = intval($_GET['id'] ?? 0);
    if (!$sid) jsonError('Historia inválida');

    $chk = $db->prepare('SELECT id FROM stories WHERE id = ? AND expires_at > NOW()');
    $chk->execute([$sid]);
    if (!$chk->fetch()) jsonError('Historia no encontrada o expirada', 404);

    $db->prepare('INSERT IGNORE INTO story_views (story_id, user_id) VALUES (?, ?)')
       ->execute([$sid, $me['id']]);

    jsonOk([], 'Vista registrada');
}

jsonError('Acción no válida');
