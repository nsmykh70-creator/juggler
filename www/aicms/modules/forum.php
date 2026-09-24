<?php
/**
 * Module: Professional Forum
 * Features: categories, topics, replies, edit/delete, search, pagination,
 *           BBCode, pin/close, admin moderation, roles (admin/moderator/user)
 */

require_once __DIR__ . '/../admin/core/db.php';
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/user_auth.php';
require_once __DIR__ . '/../admin/core/extensions.php';

if (!extension_enabled('forum')) {
    echo '<div style="padding:2rem; text-align:center; background:#f1f5f9; color:#64748b; border-radius:12px; margin:2rem 0;">Форум временно отключён администратором.</div>';
    return;
}

$s = module_get_settings('forum', [
    'title' => 'Форум', 'posts_per_page' => 20, 'topics_per_page' => 20, 'allow_guest' => true,
]);
$forum_cu = current_user();
$forum_action = $_GET['faction'] ?? '';
$forum_error = '';
$forum_success = '';

// Public-site CSRF token (same session key as user.php / shop.php / auth_block)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$forum_csrf = $_SESSION['site_csrf'];

if (!function_exists('forum_csrf_ok')) {
    function forum_csrf_ok() {
        return isset($_POST['csrf_token']) && hash_equals($_SESSION['site_csrf'] ?? '', (string)$_POST['csrf_token']);
    }
}

// ===== Role helpers =====
function forum_user_role() {
    $cu = current_user();
    if (!$cu) return 'guest';
    return $cu['role'] ?? 'user';
}

function forum_is_admin() {
    return forum_user_role() === 'admin';
}

function forum_is_moderator() {
    $r = forum_user_role();
    return $r === 'admin' || $r === 'moderator';
}

function forum_can_edit($author_email, $author_name) {
    if (forum_is_moderator()) return true;
    $cu = current_user();
    if ($cu) {
        $email = $cu['email'] ?? '';
        $name = $cu['display_name'] ?? ($cu['username'] ?? '');
        if ($email && $email === $author_email) return true;
        if ($name && $name === $author_name) return true;
    }
    return false;
}

require_once __DIR__ . '/forum_format.php';

// ===== Handle actions =====

// Create topic
if ($forum_action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $title = trim($_POST['topic_title'] ?? '');
    $content = trim($_POST['topic_content'] ?? '');
    $cat_id = (int)($_POST['topic_category'] ?? 0);
    if ($forum_cu) {
        $author = $forum_cu['display_name'] ?: $forum_cu['username'];
        $email = $forum_cu['email'];
    } else {
        $author = trim($_POST['topic_author'] ?? '');
        $email = trim($_POST['topic_email'] ?? '');
    }
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } elseif (empty($title) || empty($content)) {
        $forum_error = 'Заполните заголовок и текст темы.';
    } elseif (!$forum_cu && empty($author)) {
        $forum_error = 'Укажите имя или войдите в аккаунт.';
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)) . '-' . substr(md5(uniqid()), 0, 4);
        db_insert('forum_topics', [
            'title' => $title, 'slug' => $slug, 'category_id' => $cat_id,
            'author_name' => $author, 'author_email' => $email, 'content' => $content,
        ]);
        if ($forum_cu) {
            db_query('UPDATE ' . db_table('users') . ' SET posts_count = posts_count + 1 WHERE id = ?', [(int)$forum_cu['id']]);
        }
        header('Location: ?page=forum&faction=topic&id=' . db()->lastInsertId());
        exit;
    }
}

// Update topic
if ($forum_action === 'update_topic' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $topic = db_find('forum_topics', $topic_id);
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } elseif ($topic && forum_can_edit($topic['author_email'], $topic['author_name'])) {
        $title = trim($_POST['topic_title'] ?? '');
        $content = trim($_POST['topic_content'] ?? '');
        $cat_id = (int)($_POST['topic_category'] ?? 0);
        if (!empty($title) && !empty($content)) {
            db_update('forum_topics', ['title' => $title, 'content' => $content, 'category_id' => $cat_id], ['id' => $topic_id]);
        }
        header('Location: ?page=forum&faction=topic&id=' . $topic_id);
        exit;
    } else {
        $forum_error = 'Нет прав на редактирование.';
    }
}

// Delete topic
if ($forum_action === 'delete_topic' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $topic_id = (int)($_POST['id'] ?? 0);
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $topic = db_find('forum_topics', $topic_id);
        if ($topic && forum_can_edit($topic['author_email'], $topic['author_name'])) {
            db_delete('forum_topics', $topic_id);
            db_query('DELETE FROM ' . db_table('forum_posts') . ' WHERE topic_id = ?', [$topic_id]);
            header('Location: ?page=forum');
            exit;
        }
        $forum_error = 'Нет прав на удаление.';
    }
}

// Reply
if ($forum_action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $content = trim($_POST['reply_content'] ?? '');
    if ($forum_cu) {
        $author = $forum_cu['display_name'] ?: $forum_cu['username'];
        $email = $forum_cu['email'];
    } else {
        $author = trim($_POST['reply_author'] ?? '');
        $email = trim($_POST['reply_email'] ?? '');
    }
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } elseif (empty($content) || $topic_id <= 0) {
        $forum_error = 'Текст ответа обязателен.';
    } elseif (!$forum_cu && empty($author)) {
        $forum_error = 'Укажите имя или войдите в аккаунт.';
    } else {
        db_insert('forum_posts', [
            'topic_id' => $topic_id, 'author_name' => $author,
            'author_email' => $email, 'content' => $content,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $topic = db_find('forum_topics', $topic_id);
        if ($topic) {
            db_update('forum_topics', [
                'replies_count' => (int)$topic['replies_count'] + 1,
                'last_reply_at' => date('Y-m-d H:i:s'),
            ], ['id' => $topic_id]);
        }
        if ($forum_cu) {
            db_query('UPDATE ' . db_table('users') . ' SET posts_count = posts_count + 1 WHERE id = ?', [(int)$forum_cu['id']]);
        }
        header('Location: ?page=forum&faction=topic&id=' . $topic_id . '#bottom');
        exit;
    }
}

// Update post
if ($forum_action === 'update_post' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $post_id = (int)($_POST['post_id'] ?? 0);
    $post = db_find('forum_posts', $post_id);
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } elseif ($post && forum_can_edit($post['author_email'], $post['author_name'])) {
        $content = trim($_POST['post_content'] ?? '');
        if (!empty($content)) {
            db_update('forum_posts', ['content' => $content], ['id' => $post_id]);
        }
        header('Location: ?page=forum&faction=topic&id=' . $post['topic_id']);
        exit;
    } else {
        $forum_error = 'Нет прав на редактирование.';
    }
}

// Delete post
if ($forum_action === 'delete_post' && $_SERVER['REQUEST_METHOD'] === 'POST' && db_available()) {
    $post_id = (int)($_POST['id'] ?? 0);
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $post = db_find('forum_posts', $post_id);
        if ($post && forum_can_edit($post['author_email'], $post['author_name'])) {
            db_delete('forum_posts', $post_id);
            $topic = db_find('forum_topics', $post['topic_id']);
            if ($topic) {
                db_update('forum_topics', ['replies_count' => max(0, (int)$topic['replies_count'] - 1)], ['id' => $post['topic_id']]);
            }
            header('Location: ?page=forum&faction=topic&id=' . $post['topic_id']);
            exit;
        }
        $forum_error = 'Нет прав на удаление.';
    }
}

// Admin/moderator: toggle pin/close
if (($forum_action === 'pin' || $forum_action === 'close') && $_SERVER['REQUEST_METHOD'] === 'POST' && forum_is_moderator() && db_available()) {
    $topic_id = (int)($_POST['id'] ?? 0);
    if (!forum_csrf_ok()) {
        $forum_error = 'Ошибка безопасности. Обновите страницу и попробуйте снова.';
    } else {
        $topic = db_find('forum_topics', $topic_id);
        if ($topic) {
            if ($forum_action === 'pin') {
                db_update('forum_topics', ['is_pinned' => $topic['is_pinned'] ? 0 : 1], ['id' => $topic_id]);
            } else {
                db_update('forum_topics', ['is_closed' => $topic['is_closed'] ? 0 : 1], ['id' => $topic_id]);
            }
        }
        header('Location: ?page=forum&faction=topic&id=' . $topic_id);
        exit;
    }
}

// ===== Render =====
if (!db_available()) {
    echo '<div style="padding:2rem; background:#fff3cd; color:#856404; border-radius:12px; text-align:center; margin:2rem 0;">⚠️ Форум требует подключения базы данных (MySQL или SQLite).</div>';
    return;
}

$categories = db_select('forum_categories', '*', '', [], 'position ASC');
$cat_map = [];
foreach ($categories as $cat) { $cat_map[$cat['id']] = $cat['title']; }

// CSS (output once)
echo '<style>
/* Forum module — uses AICMS design system (same as admin) */
.forum-module { max-width:1000px; margin:0 auto; padding:1rem; font-family:"Inter",system-ui,sans-serif; color:#0f172a; }
.forum-module * { box-sizing:border-box; }
.forum-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem; }
.forum-header h2 { margin:0; font-size:1.75rem; font-weight:700; color:#0f172a; line-height:1.25; }
.forum-actions { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; }

/* Buttons — EXACT copy of AICMS .aicms-btn classes */
.forum-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 40px;
    padding: 0 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s, background-color 0.15s;
    text-decoration: none;
    border: 1px solid transparent;
    line-height: 1;
    white-space: nowrap;
    box-sizing: border-box;
    font-family: "Inter", system-ui, sans-serif;
}
.forum-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.forum-btn i { font-size: 14px; }
.forum-btn-primary { background-color: #2563eb; color: #ffffff !important; font-weight: 700; }
.forum-btn-primary:hover { background-color: #1d4ed8; color: #ffffff !important; }
.forum-btn-success { background-color: #16a34a; color: #ffffff !important; font-weight: 700; }
.forum-btn-success:hover { background-color: #15803d; color: #ffffff !important; }
.forum-btn-danger { background-color: #dc2626; color: #ffffff !important; font-weight: 700; }
.forum-btn-danger:hover { background-color: #b91c1c; color: #ffffff !important; }
.forum-btn-secondary { background-color: #2563eb; color: #ffffff !important; font-weight: 600; border: 1px solid #1d4ed8; }
.forum-btn-secondary:hover { background-color: #1d4ed8; color: #ffffff !important; }
.forum-btn-ghost { background-color: #16a34a; color: #ffffff !important; font-weight: 600; border: 1px solid #15803d; }
.forum-btn-ghost:hover { background-color: #15803d; color: #ffffff !important; }
.forum-btn-sm { height: 32px; padding: 0 12px; font-size: 13px; font-weight: 700; }

/* Search — AICMS input style */
.forum-search { display:flex; gap:0.5rem; margin-bottom:1rem; }
.forum-search input {
    flex:1; height:40px; padding:0 14px;
    border:1px solid #cbd5e1; border-radius:8px;
    font-size:14px; font-family:"Inter",system-ui,sans-serif;
    transition:border-color 0.15s,box-shadow 0.15s;
    color:#0f172a; background:#fff; box-sizing:border-box;
}
.forum-search input:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
.forum-search input::placeholder { color:#94a3b8; }

/* Categories — AICMS pill style */
.forum-categories { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:1rem; }
.forum-cat {
    padding:8px 14px; border-radius:8px;
    font-size:14px; font-weight:500;
    color:#334155; text-decoration:none;
    transition:background-color 0.15s,color 0.15s;
    font-family:"Inter",system-ui,sans-serif;
}
.forum-cat:hover { background-color:#e2e8f0; color:#0f172a; }
.forum-cat.active { background-color:#2563eb; color:#fff; }

/* Topic list — AICMS card style */
.forum-topic-list { display:flex; flex-direction:column; gap:0.5rem; }
.forum-topic-item {
    display:flex; justify-content:space-between; align-items:center;
    padding:14px 20px; background:#fff;
    border:1px solid #e2e8f0; border-radius:8px;
    transition:transform 0.15s,box-shadow 0.15s,border-color 0.15s;
    text-decoration:none; color:inherit;
}
.forum-topic-item:hover {
    transform:translateY(-2px);
    box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);
    border-color:#2563eb;
}
.forum-topic-title { font-weight:600; font-size:15px; color:#0f172a; font-family:"Inter",system-ui,sans-serif; }
.forum-topic-meta { font-size:12px; color:#64748b; margin-top:2px; font-family:"Inter",system-ui,sans-serif; }
.forum-topic-stats { text-align:center; font-size:13px; color:#64748b; flex-shrink:0; min-width:90px; font-family:"Inter",system-ui,sans-serif; }
.forum-topic-stats strong { font-size:15px; color:#0f172a; display:block; font-weight:700; }

/* Posts — AICMS card style */
.forum-post {
    padding:20px; background:#fff;
    border:1px solid #e2e8f0; border-radius:8px;
    margin-bottom:0.8rem;
    box-shadow:0 1px 3px rgba(0,0,0,0.06);
}
.forum-post:first-of-type { border-left:4px solid #2563eb; }
.forum-post-header { display:flex; gap:12px; align-items:flex-start; margin-bottom:8px; }
.forum-avatar {
    width:40px; height:40px;
    background:linear-gradient(135deg,#2563eb,#7c3aed);
    color:#fff; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-weight:700; flex-shrink:0; font-size:16px;
    font-family:"Inter",system-ui,sans-serif;
}
.forum-avatar-sm { width:32px; height:32px; font-size:13px; background:linear-gradient(135deg,#64748b,#475569); }
.forum-post-author { font-weight:600; color:#0f172a; font-size:15px; font-family:"Inter",system-ui,sans-serif; }
.forum-post-date { color:#64748b; font-size:12px; margin-left:8px; font-family:"Inter",system-ui,sans-serif; }
.forum-post-content { line-height:1.6; color:#334155; margin-top:8px; font-family:"Inter",system-ui,sans-serif; font-size:14px; }
.forum-post-content code { background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:"Fira Code",monospace; font-size:0.9em; color:#db2777; }
.forum-post-content blockquote { border-left:4px solid #2563eb; padding:8px 16px; margin:12px 0; background:#f8fafc; border-radius:0 8px 8px 0; color:#475569; font-style:italic; }
.forum-post-actions { margin-top:12px; display:flex; gap:4px; flex-wrap:wrap; }

/* Forms — AICMS input style */
.forum-form { max-width:600px; margin:1rem 0; display:flex; flex-direction:column; gap:12px; }
.forum-form label { font-weight:500; margin-bottom:4px; display:block; font-size:14px; color:#0f172a; font-family:"Inter",system-ui,sans-serif; }
.forum-form input, .forum-form textarea, .forum-form select {
    width:100%; height:40px; padding:0 14px;
    border:1px solid #cbd5e1; border-radius:8px;
    font-size:14px; font-family:"Inter",system-ui,sans-serif;
    transition:border-color 0.15s,box-shadow 0.15s;
    color:#0f172a; background:#fff; box-sizing:border-box;
}
.forum-form textarea { height:auto; padding:10px 14px; resize:vertical; line-height:1.6; }
.forum-form input:focus, .forum-form textarea:focus, .forum-form select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
.forum-form input::placeholder, .forum-form textarea::placeholder { color:#94a3b8; }

/* Misc */
.forum-back { color:#334155; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:4px; margin-bottom:1rem; font-weight:500; font-family:"Inter",system-ui,sans-serif; transition:color 0.15s; }
.forum-back:hover { color:#2563eb; }
.forum-alert { padding:12px 16px; border-radius:8px; margin-bottom:1rem; font-size:14px; font-family:"Inter",system-ui,sans-serif; }
.forum-alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.forum-alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.forum-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; font-family:"Inter",system-ui,sans-serif; }
.forum-badge-pinned { background:#fef3c7; color:#92400e; }
.forum-badge-closed { background:#fee2e2; color:#991b1b; }
.forum-badge-admin { background:#7c3aed; color:#fff; }
.forum-badge-mod { background:#0891b2; color:#fff; }
.forum-badge-cat { background:#eff6ff; color:#2563eb; }
.forum-empty { text-align:center; padding:3rem; color:#64748b; font-family:"Inter",system-ui,sans-serif; }

/* Pagination — AICMS style */
.forum-pagination { display:flex; gap:4px; justify-content:center; margin-top:1.5rem; }
.forum-pagination a, .forum-pagination span {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:36px; height:36px; padding:0 10px;
    border:1px solid #cbd5e1; border-radius:8px;
    text-decoration:none; color:#334155;
    font-size:14px; font-weight:500;
    transition:background-color 0.15s,color 0.15s,border-color 0.15s;
    font-family:"Inter",system-ui,sans-serif;
}
.forum-pagination a:hover { background-color:#e2e8f0; color:#0f172a; }
.forum-pagination .current { background-color:#2563eb; color:#fff; border-color:#2563eb; }

@media (max-width:640px) { .forum-topic-item { flex-direction:column; align-items:flex-start; gap:0.5rem; } .forum-topic-stats { align-self:flex-end; } }
</style>';

echo '<div class="forum-module">';

if ($forum_error) {
    echo '<div class="forum-alert forum-alert-error">' . h($forum_error) . '</div>';
}

// ===== VIEW TOPIC =====
if ($forum_action === 'topic' && ($topic_id = (int)($_GET['id'] ?? 0)) > 0) {
    $topic = db_find('forum_topics', $topic_id);
    if ($topic) {
        db_update('forum_topics', ['views' => (int)$topic['views'] + 1], ['id' => $topic_id]);
        $page_num = max(1, (int)($_GET['p'] ?? 1));
        $per_page = (int)$s['posts_per_page'];
        $offset = ($page_num - 1) * $per_page;
        $total_posts = db_count('forum_posts', 'topic_id = ? AND status = ?', [$topic_id, 'visible']);
        $total_pages = max(1, ceil($total_posts / $per_page));
        $posts = db_select('forum_posts', '*', 'topic_id = ? AND status = ?', [$topic_id, 'visible'], 'created_at ASC', $per_page, $offset);
        $can_edit_topic = forum_can_edit($topic['author_email'], $topic['author_name']);
        $is_closed = (int)$topic['is_closed'];

        echo '<a href="?page=forum" class="forum-back">← К списку тем</a>';
        echo '<div style="margin-bottom:1rem;">';
        echo '<div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.5rem;">';
        echo '<h2 style="margin:0; font-size:1.5rem; font-weight:800; color:#0f172a;">' . h($topic['title']) . '</h2>';
        if ($topic['is_pinned']) { echo '<span class="forum-badge forum-badge-pinned">📌 Закреплена</span>'; }
        if ($is_closed) { echo '<span class="forum-badge forum-badge-closed">🔒 Закрыта</span>'; }
        echo '</div>';
        echo '<small style="color:#94a3b8;">Автор: ' . h($topic['author_name']);
        // Show role badge for author
        if ($forum_cu && $forum_cu['email'] === $topic['author_email']) {
            $role = forum_user_role();
            if ($role === 'admin') echo ' <span class="forum-badge forum-badge-admin">Админ</span>';
            elseif ($role === 'moderator') echo ' <span class="forum-badge forum-badge-mod">Модератор</span>';
        }
        echo ' · Создано: ' . h($topic['created_at']) . ' · Просмотров: ' . ((int)$topic['views'] + 1);
        if (isset($cat_map[$topic['category_id']])) { echo ' · Категория: ' . h($cat_map[$topic['category_id']]); }
        echo '</small></div>';

        // Action buttons
        if ($can_edit_topic || forum_is_moderator()) {
            echo '<div class="forum-post-actions" style="margin-bottom:1rem;">';
            if ($can_edit_topic) {
                echo '<a href="?page=forum&faction=edit_topic&id=' . $topic_id . '" class="forum-btn forum-btn-secondary forum-btn-sm">✏️ Редактировать</a>';
                echo '<form method="POST" action="?page=forum&faction=delete_topic" style="display:inline; margin:0;" onsubmit="return confirm(\'Удалить тему со всеми ответами?\')">';
                echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
                echo '<input type="hidden" name="id" value="' . $topic_id . '">';
                echo '<button type="submit" class="forum-btn forum-btn-danger forum-btn-sm">🗑 Удалить</button>';
                echo '</form>';
            }
            if (forum_is_moderator()) {
                echo '<form method="POST" action="?page=forum&faction=pin" style="display:inline; margin:0;">';
                echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
                echo '<input type="hidden" name="id" value="' . $topic_id . '">';
                echo '<button type="submit" class="forum-btn forum-btn-secondary forum-btn-sm">' . ($topic['is_pinned'] ? '📌 Открепить' : '📌 Закрепить') . '</button>';
                echo '</form>';
                echo '<form method="POST" action="?page=forum&faction=close" style="display:inline; margin:0;">';
                echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
                echo '<input type="hidden" name="id" value="' . $topic_id . '">';
                echo '<button type="submit" class="forum-btn forum-btn-secondary forum-btn-sm">' . ($is_closed ? '🔓 Открыть' : '🔒 Закрыть') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        }

        // Topic content
        echo '<div class="forum-post" style="border-left:4px solid #2563eb;">';
        echo '<div class="forum-post-header">';
        echo '<div class="forum-avatar">' . h(strtoupper(mb_substr($topic['author_name'], 0, 1, 'UTF-8'))) . '</div>';
        echo '<div style="flex:1;"><div><span class="forum-post-author">' . h($topic['author_name']) . '</span><span class="forum-post-date">' . h($topic['created_at']) . '</span></div>';
        echo '<div class="forum-post-content">' . forum_format($topic['content']) . '</div></div></div></div>';

        // Replies
        if (!empty($posts)) {
            echo '<h4 style="margin:1.5rem 0 0.8rem; color:#1e293b;">Ответы (' . $total_posts . ')</h4>';
            foreach ($posts as $p) {
                $can_edit_post = forum_can_edit($p['author_email'], $p['author_name']);
                echo '<div class="forum-post">';
                echo '<div class="forum-post-header">';
                echo '<div class="forum-avatar forum-avatar-sm" style="background:#94a3b8;">' . h(strtoupper(mb_substr($p['author_name'], 0, 1, 'UTF-8'))) . '</div>';
                echo '<div style="flex:1;"><div><span class="forum-post-author">' . h($p['author_name']) . '</span><span class="forum-post-date">' . h($p['created_at']) . '</span></div>';
                echo '<div class="forum-post-content">' . forum_format($p['content']) . '</div>';
                if ($can_edit_post) {
                    echo '<div class="forum-post-actions">';
                    echo '<a href="?page=forum&faction=edit_post&id=' . $p['id'] . '" class="forum-btn forum-btn-secondary forum-btn-sm">✏️ Редактировать</a>';
                    echo '<form method="POST" action="?page=forum&faction=delete_post" style="display:inline; margin:0;" onsubmit="return confirm(\'Удалить ответ?\')">';
                    echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
                    echo '<input type="hidden" name="id" value="' . $p['id'] . '">';
                    echo '<button type="submit" class="forum-btn forum-btn-danger forum-btn-sm">🗑 Удалить</button>';
                    echo '</form>';
                    echo '</div>';
                }
                echo '</div></div></div>';
            }

            if ($total_pages > 1) {
                echo '<div class="forum-pagination">';
                for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == $page_num) {
                        echo '<span class="current">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=forum&faction=topic&id=' . $topic_id . '&p=' . $i . '">' . $i . '</a>';
                    }
                }
                echo '</div>';
            }
        }

        // Reply form
        if (!$is_closed) {
            echo '<form method="POST" action="?page=forum&faction=reply" class="forum-form" style="margin-top:1.5rem; padding:1.2rem; background:#f8fafc; border-radius:10px; max-width:100%;">';
            echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
            echo '<input type="hidden" name="topic_id" value="' . $topic_id . '">';
            if ($forum_cu) {
                echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:0.8rem; padding:8px 12px; background:#eff6ff; border-radius:8px; border:1px solid #dbeafe;">';
                echo '<div style="width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px;">' . strtoupper(mb_substr($forum_cu['display_name'] ?? $forum_cu['username'] ?? '', 0, 1, 'UTF-8')) . '</div>';
                echo '<strong style="font-size:14px;">' . h($forum_cu['display_name'] ?? $forum_cu['username']) . '</strong>';
                $role = forum_user_role();
                if ($role === 'admin') echo '<span class="forum-badge forum-badge-admin">Админ</span>';
                elseif ($role === 'moderator') echo '<span class="forum-badge forum-badge-mod">Модератор</span>';
                echo '<small style="color:#64748b;">@' . h($forum_cu['username']) . '</small>';
                echo '</div>';
            } else {
                echo '<div style="margin-bottom:0.8rem; padding:8px 12px; background:#fef3c7; border:1px solid #fde68a; border-radius:6px; font-size:13px; color:#92400e;">';
                echo '<a href="user.php?action=login&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" style="color:#92400e; font-weight:600;">Войдите</a> или <a href="user.php?action=register&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" style="color:#92400e; font-weight:600;">зарегистрируйтесь</a>';
                echo '</div>';
                echo '<div style="display:grid; grid-template-columns:1fr 1fr; gap:0.8rem; margin-bottom:0.8rem;">';
                echo '<input type="text" name="reply_author" placeholder="Ваше имя *" required style="padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px;">';
                echo '<input type="email" name="reply_email" placeholder="Email" style="padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px;">';
                echo '</div>';
            }
            echo '<textarea name="reply_content" placeholder="Ваш ответ... (BBCode: [b]жирный[/b] [i]курсив[/i] [code]код[/code] [quote]цитата[/quote])" required rows="4" style="width:100%; padding:0.5rem 0.8rem; border:1px solid #cbd5e1; border-radius:6px; resize:vertical;"></textarea>';
            echo '<button type="submit" class="forum-btn forum-btn-primary">Ответить</button>';
            echo '</form>';
            echo '<a id="bottom"></a>';
        } else {
            echo '<div class="forum-alert forum-alert-error" style="margin-top:1rem;">🔒 Тема закрыта для новых ответов.</div>';
        }
    } else {
        echo '<p>Тема не найдена.</p>';
    }
}
// ===== EDIT TOPIC =====
elseif ($forum_action === 'edit_topic' && ($topic_id = (int)($_GET['id'] ?? 0)) > 0) {
    $topic = db_find('forum_topics', $topic_id);
    if ($topic && forum_can_edit($topic['author_email'], $topic['author_name'])) {
        echo '<a href="?page=forum&faction=topic&id=' . $topic_id . '" class="forum-back">← Назад к теме</a>';
        echo '<h2 style="margin-bottom:1rem;">Редактировать тему</h2>';
        echo '<form method="POST" action="?page=forum&faction=update_topic" class="forum-form">';
        echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
        echo '<input type="hidden" name="topic_id" value="' . $topic_id . '">';
        echo '<div><label>Заголовок *</label><input type="text" name="topic_title" value="' . h($topic['title']) . '" required></div>';
        if (!empty($categories)) {
            echo '<div><label>Категория</label><select name="topic_category"><option value="0">— Без категории —</option>';
            foreach ($categories as $cat) {
                echo '<option value="' . $cat['id'] . '"' . ($topic['category_id'] == $cat['id'] ? ' selected' : '') . '>' . h($cat['title']) . '</option>';
            }
            echo '</select></div>';
        }
        echo '<div><label>Текст *</label><textarea name="topic_content" required rows="10">' . h($topic['content']) . '</textarea></div>';
        echo '<button type="submit" class="forum-btn forum-btn-primary">Сохранить</button>';
        echo '<a href="?page=forum&faction=topic&id=' . $topic_id . '" class="forum-btn forum-btn-secondary">Отмена</a>';
        echo '</form>';
    } else {
        echo '<div class="forum-alert forum-alert-error">Нет прав на редактирование.</div>';
        echo '<a href="?page=forum" class="forum-back">← К списку тем</a>';
    }
}
// ===== EDIT POST =====
elseif ($forum_action === 'edit_post' && ($post_id = (int)($_GET['id'] ?? 0)) > 0) {
    $post = db_find('forum_posts', $post_id);
    if ($post && forum_can_edit($post['author_email'], $post['author_name'])) {
        echo '<a href="?page=forum&faction=topic&id=' . $post['topic_id'] . '" class="forum-back">← Назад к теме</a>';
        echo '<h2 style="margin-bottom:1rem;">Редактировать ответ</h2>';
        echo '<form method="POST" action="?page=forum&faction=update_post" class="forum-form">';
        echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
        echo '<input type="hidden" name="post_id" value="' . $post_id . '">';
        echo '<div><label>Текст *</label><textarea name="post_content" required rows="8">' . h($post['content']) . '</textarea></div>';
        echo '<button type="submit" class="forum-btn forum-btn-primary">Сохранить</button>';
        echo '<a href="?page=forum&faction=topic&id=' . $post['topic_id'] . '" class="forum-btn forum-btn-secondary">Отмена</a>';
        echo '</form>';
    } else {
        echo '<div class="forum-alert forum-alert-error">Нет прав на редактирование.</div>';
        echo '<a href="?page=forum" class="forum-back">← К списку тем</a>';
    }
}
// ===== NEW TOPIC =====
elseif ($forum_action === 'new') {
    echo '<a href="?page=forum" class="forum-back">← К списку тем</a>';
    echo '<h2 style="margin-bottom:1rem;">Создать новую тему</h2>';
    if ($forum_cu) {
        echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:1rem; padding:8px 12px; background:#eff6ff; border-radius:8px; border:1px solid #dbeafe; max-width:600px;">';
        echo '<div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700;">' . strtoupper(mb_substr($forum_cu['display_name'] ?? $forum_cu['username'] ?? '', 0, 1, 'UTF-8')) . '</div>';
        echo '<div><strong>' . h($forum_cu['display_name'] ?? $forum_cu['username']) . '</strong><br><small style="color:#64748b;">@' . h($forum_cu['username']) . '</small></div>';
        echo '</div>';
    } else {
        echo '<div style="margin-bottom:1rem; padding:10px 14px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; font-size:14px; color:#92400e; max-width:600px;">';
        echo '<a href="user.php?action=login&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" style="color:#92400e; font-weight:600;">Войдите</a> или <a href="user.php?action=register&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" style="color:#92400e; font-weight:600;">зарегистрируйтесь</a>';
        echo '</div>';
    }
    echo '<form method="POST" action="?page=forum&faction=create" class="forum-form">';
    echo '<input type="hidden" name="csrf_token" value="' . h($forum_csrf) . '">';
    echo '<div><label>Заголовок *</label><input type="text" name="topic_title" required placeholder="О чём тема?"></div>';
    if (!empty($categories)) {
        echo '<div><label>Категория</label><select name="topic_category"><option value="0">— Без категории —</option>';
        foreach ($categories as $cat) {
            echo '<option value="' . $cat['id'] . '">' . h($cat['title']) . '</option>';
        }
        echo '</select></div>';
    }
    if (!$forum_cu) {
        echo '<div><label>Имя *</label><input type="text" name="topic_author" required placeholder="Ваше имя"></div>';
        echo '<div><label>Email</label><input type="email" name="topic_email" placeholder="email@example.com"></div>';
    }
    echo '<div><label>Текст *</label><textarea name="topic_content" required rows="8" placeholder="BBCode: [b]жирный[/b] [i]курсив[/i] [code]код[/code] [quote]цитата[/quote] [url=ссылка]текст[/url]"></textarea></div>';
    echo '<button type="submit" class="forum-btn forum-btn-primary">Создать тему</button>';
    echo '</form>';
}
// ===== FORUM INDEX =====
else {
    $search_q = trim($_GET['q'] ?? '');
    $cat_filter = (int)($_GET['cat'] ?? 0);
    $page_num = max(1, (int)($_GET['p'] ?? 1));
    $per_page = (int)$s['topics_per_page'];
    $offset = ($page_num - 1) * $per_page;

    $where = '';
    $params = [];
    if ($search_q) {
        $where = 'title LIKE ?';
        $params[] = '%' . $search_q . '%';
    }
    if ($cat_filter > 0) {
        $where = $where ? $where . ' AND category_id = ?' : 'category_id = ?';
        $params[] = $cat_filter;
    }

    $total_topics = db_count('forum_topics', $where, $params);
    $total_pages = max(1, ceil($total_topics / $per_page));
    $topics = db_select('forum_topics', '*', $where, $params, 'is_pinned DESC, last_reply_at DESC', $per_page, $offset);

    echo '<div class="forum-header">';
    echo '<h2>' . h($s['title']) . '</h2>';
    echo '<div class="forum-actions">';
    if ($forum_cu) {
        echo '<a href="user.php?action=profile" class="forum-btn forum-btn-secondary forum-btn-sm">' . h($forum_cu['display_name'] ?? $forum_cu['username']);
        $role = forum_user_role();
        if ($role === 'admin') echo ' <span class="forum-badge forum-badge-admin">Админ</span>';
        elseif ($role === 'moderator') echo ' <span class="forum-badge forum-badge-mod">Модератор</span>';
        echo '</a>';
    } else {
        echo '<a href="user.php?action=login&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" class="forum-btn forum-btn-secondary forum-btn-sm">Войти</a>';
        echo '<a href="user.php?action=register&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '" class="forum-btn forum-btn-secondary forum-btn-sm">Регистрация</a>';
    }
    echo '<a href="?page=forum&faction=new" class="forum-btn forum-btn-success">+ Новая тема</a>';
    echo '</div></div>';

    // Search
    echo '<form class="forum-search" method="GET" action="">';
    echo '<input type="hidden" name="page" value="forum">';
    echo '<input type="text" name="q" value="' . h($search_q) . '" placeholder="Поиск по темам...">';
    echo '<button type="submit" class="forum-btn forum-btn-primary">Найти</button>';
    if ($search_q || $cat_filter) {
        echo '<a href="?page=forum" class="forum-btn forum-btn-secondary">Сбросить</a>';
    }
    echo '</form>';

    // Categories
    if (!empty($categories)) {
        echo '<div class="forum-categories">';
        echo '<a href="?page=forum" class="forum-cat' . ($cat_filter === 0 ? ' active' : '') . '">Все</a>';
        foreach ($categories as $cat) {
            echo '<a href="?page=forum&cat=' . $cat['id'] . '" class="forum-cat' . ($cat_filter === (int)$cat['id'] ? ' active' : '') . '">' . h($cat['title']) . '</a>';
        }
        echo '</div>';
    }

    if (empty($topics)) {
        echo '<div class="forum-empty"><p style="font-size:1.1rem; margin-bottom:0.5rem;">Тем пока нет.</p><a href="?page=forum&faction=new" class="forum-btn forum-btn-success">Создайте первую тему</a></div>';
    } else {
        echo '<div class="forum-topic-list">';
        foreach ($topics as $t) {
            echo '<a href="?page=forum&faction=topic&id=' . $t['id'] . '" class="forum-topic-item">';
            echo '<div>';
            if ($t['is_pinned']) { echo '<span style="margin-right:4px;">📌</span>'; }
            echo '<span class="forum-topic-title">' . h($t['title']) . '</span>';
            if ($t['is_closed']) { echo '<span class="forum-badge forum-badge-closed" style="margin-left:6px;">🔒</span>'; }
            if (isset($cat_map[$t['category_id']]) && $t['category_id']) {
                echo '<span class="forum-badge" style="background:#eff6ff; color:#2563eb; margin-left:6px; font-size:11px;">' . h($cat_map[$t['category_id']]) . '</span>';
            }
            echo '<div class="forum-topic-meta">Автор: ' . h($t['author_name']) . ' · ' . h($t['created_at']);
            if ($t['last_reply_at'] && $t['last_reply_at'] !== $t['created_at']) { echo ' · Последний ответ: ' . h($t['last_reply_at']); }
            echo '</div></div>';
            echo '<div class="forum-topic-stats"><div><strong>' . (int)$t['replies_count'] . '</strong> ответов</div><div><small>' . (int)$t['views'] . ' просмотров</small></div></div>';
            echo '</a>';
        }
        echo '</div>';

        if ($total_pages > 1) {
            echo '<div class="forum-pagination">';
            if ($page_num > 1) {
                echo '<a href="?page=forum&p=' . ($page_num - 1) . ($search_q ? '&q=' . urlencode($search_q) : '') . ($cat_filter ? '&cat=' . $cat_filter : '') . '">← Назад</a>';
            }
            $start = max(1, $page_num - 2);
            $end = min($total_pages, $page_num + 2);
            for ($i = $start; $i <= $end; $i++) {
                if ($i == $page_num) {
                    echo '<span class="current">' . $i . '</span>';
                } else {
                    echo '<a href="?page=forum&p=' . $i . ($search_q ? '&q=' . urlencode($search_q) : '') . ($cat_filter ? '&cat=' . $cat_filter : '') . '">' . $i . '</a>';
                }
            }
            if ($page_num < $total_pages) {
                echo '<a href="?page=forum&p=' . ($page_num + 1) . ($search_q ? '&q=' . urlencode($search_q) : '') . ($cat_filter ? '&cat=' . $cat_filter : '') . '">Вперёд →</a>';
            }
            echo '</div>';
        }
    }
}

echo '</div>';
