<?php
/**
 * AICMS Admin — Forum Management & Moderation
 * ============================================
 * Manage forum topics, posts, categories.
 * Moderate: pin, close, delete, hide topics and posts.
 */

require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/cache.php';

// Redirect if forum extension is disabled
if (!extension_enabled('forum') || !db_available()) {
    set_flash_message('Форум отключён или БД недоступна.', 'warning');
    header('Location: index.php');
    exit;
}

$page_title = 'Форум — управление';
$breadcrumb = [
    ['label' => t('dashboard'), 'url' => 'index.php'],
    ['label' => 'Форум']
];
$action_panel = '<a href="index.php" class="aicms-btn aicms-btn--secondary"><i class="fas fa-arrow-left"></i> ' . t('back_to_list') . '</a>';
include __DIR__ . '/core/topbar.php';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash_message('Ошибка безопасности: неверный CSRF токен.', 'danger');
        cache_clear();
        header('Location: forum_admin.php');
        exit;
    }

    $admin_action = $_POST['admin_action'] ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);

    if ($admin_action === 'delete_topic' && $item_id > 0) {
        db_delete('forum_topics', $item_id);
        db_query('DELETE FROM ' . db_table('forum_posts') . ' WHERE topic_id = ?', [$item_id]);
        set_flash_message('Тема удалена.', 'success');
    } elseif ($admin_action === 'pin_topic' && $item_id > 0) {
        $topic = db_find('forum_topics', $item_id);
        if ($topic) {
            db_update('forum_topics', ['is_pinned' => $topic['is_pinned'] ? 0 : 1], ['id' => $item_id]);
            set_flash_message($topic['is_pinned'] ? 'Тема откреплена.' : 'Тема закреплена.', 'success');
        }
    } elseif ($admin_action === 'close_topic' && $item_id > 0) {
        $topic = db_find('forum_topics', $item_id);
        if ($topic) {
            db_update('forum_topics', ['is_closed' => $topic['is_closed'] ? 0 : 1], ['id' => $item_id]);
            set_flash_message($topic['is_closed'] ? 'Тема открыта.' : 'Тема закрыта.', 'success');
        }
    } elseif ($admin_action === 'delete_post' && $item_id > 0) {
        $post = db_find('forum_posts', $item_id);
        if ($post) {
            db_delete('forum_posts', $item_id);
            $topic = db_find('forum_topics', $post['topic_id']);
            if ($topic) {
                db_update('forum_topics', ['replies_count' => max(0, (int)$topic['replies_count'] - 1)], ['id' => $post['topic_id']]);
            }
            set_flash_message('Ответ удалён.', 'success');
        }
    } elseif ($admin_action === 'hide_post' && $item_id > 0) {
        $post = db_find('forum_posts', $item_id);
        if ($post) {
            $new_status = $post['status'] === 'visible' ? 'hidden' : 'visible';
            db_update('forum_posts', ['status' => $new_status], ['id' => $item_id]);
            set_flash_message($new_status === 'hidden' ? 'Ответ скрыт.' : 'Ответ показан.', 'success');
        }
    } elseif ($admin_action === 'add_category') {
        $cat_title = trim($_POST['cat_title'] ?? '');
        $cat_desc = trim($_POST['cat_desc'] ?? '');
        if ($cat_title) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat_title));
            $max_pos = db_query('SELECT MAX(position) as mx FROM ' . db_table('forum_categories'))->fetch()['mx'] ?? 0;
            db_insert('forum_categories', [
                'title' => $cat_title, 'slug' => $slug,
                'description' => $cat_desc, 'position' => (int)$max_pos + 1,
            ]);
            set_flash_message('Категория создана.', 'success');
        }
    } elseif ($admin_action === 'delete_category' && $item_id > 0) {
        db_delete('forum_categories', $item_id);
        set_flash_message('Категория удалена.', 'success');
    } elseif ($admin_action === 'edit_topic' && $item_id > 0) {
        $title = trim($_POST['topic_title'] ?? '');
        $content = trim($_POST['topic_content'] ?? '');
        if ($title && $content) {
            db_update('forum_topics', ['title' => $title, 'content' => $content], ['id' => $item_id]);
            set_flash_message('Тема обновлена.', 'success');
        }
    }

    cache_clear();
    header('Location: forum_admin.php' . (isset($_POST['tab']) ? '?tab=' . $_POST['tab'] : ''));
    exit;
}

// Get tab
$current_tab = $_GET['tab'] ?? 'topics';

// Stats
$stats = [
    'topics' => db_count('forum_topics'),
    'posts' => db_count('forum_posts'),
    'categories' => db_count('forum_categories'),
    'hidden_posts' => db_count('forum_posts', 'status = ?', ['hidden']),
];
?>
<style>
.forum-tab-btn { padding: 10px 20px; border-bottom: 2px solid transparent; color: #64748b; font-weight: 500; text-decoration: none; transition: all 0.15s; }
.forum-tab-btn:hover { color: #2563eb; }
.forum-tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; }
.forum-stat-card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
</style>

<div class="p-6 flex-1">
    <?php display_flash_message(); ?>

    <h1 class="text-2xl font-bold text-slate-800 mb-1">Форум — управление</h1>
    <p class="text-sm text-slate-500 mb-6">Модерация тем, ответов и категорий форума</p>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="forum-stat-card flex items-center">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-list"></i></div>
            <div><h3 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['topics'] ?></h3><p class="text-sm text-slate-500 m-0">Тем</p></div>
        </div>
        <div class="forum-stat-card flex items-center">
            <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-comments"></i></div>
            <div><h3 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['posts'] ?></h3><p class="text-sm text-slate-500 m-0">Ответов</p></div>
        </div>
        <div class="forum-stat-card flex items-center">
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-folder"></i></div>
            <div><h3 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['categories'] ?></h3><p class="text-sm text-slate-500 m-0">Категорий</p></div>
        </div>
        <div class="forum-stat-card flex items-center">
            <div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-eye-slash"></i></div>
            <div><h3 class="text-2xl font-bold text-slate-800 m-0"><?= $stats['hidden_posts'] ?></h3><p class="text-sm text-slate-500 m-0">Скрытых ответов</p></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-4">
        <div class="flex border-b border-slate-200">
            <a href="?tab=topics" class="forum-tab-btn <?= $current_tab === 'topics' ? 'active' : '' ?>"><i class="fas fa-list mr-1"></i> Темы</a>
            <a href="?tab=posts" class="forum-tab-btn <?= $current_tab === 'posts' ? 'active' : '' ?>"><i class="fas fa-comments mr-1"></i> Ответы</a>
            <a href="?tab=categories" class="forum-tab-btn <?= $current_tab === 'categories' ? 'active' : '' ?>"><i class="fas fa-folder mr-1"></i> Категории</a>
        </div>
        <div class="p-5">
            <?php if ($current_tab === 'topics'): ?>
            <!-- ===== TOPICS TAB ===== -->
            <?php
            $page_num = max(1, (int)($_GET['p'] ?? 1));
            $per_page = 20;
            $offset = ($page_num - 1) * $per_page;
            $search = trim($_GET['q'] ?? '');
            $where = $search ? 'title LIKE ? OR author_name LIKE ?' : '';
            $params = $search ? ['%' . $search . '%', '%' . $search . '%'] : [];
            $total = db_count('forum_topics', $where, $params);
            $topics = db_select('forum_topics', '*', $where, $params, 'is_pinned DESC, created_at DESC', $per_page, $offset);
            $categories = db_select('forum_categories', '*', '', [], 'position ASC');
            $cat_map = [];
            foreach ($categories as $c) { $cat_map[$c['id']] = $c['title']; }
            ?>
            <form method="GET" class="mb-4 flex gap-2">
                <input type="hidden" name="tab" value="topics">
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Поиск по темам..." class="flex-1 px-3 py-2 border border-slate-300 rounded-lg">
                <button type="submit" class="aicms-btn aicms-btn--primary"><i class="fas fa-search"></i> Найти</button>
                <?php if ($search): ?>
                <a href="?tab=topics" class="aicms-btn aicms-btn--secondary">Сбросить</a>
                <?php endif; ?>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Тема</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Автор</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Ответы</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Просмотры</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topics)): ?>
                        <tr><td colspan="6" class="text-center py-8 text-slate-400">Тем нет</td></tr>
                        <?php else: foreach ($topics as $t): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <?php if ($t['is_pinned']): ?><span class="text-amber-500" title="Закреплена"><i class="fas fa-thumbtack"></i></span><?php endif; ?>
                                    <?php if ($t['is_closed']): ?><span class="text-red-500" title="Закрыта"><i class="fas fa-lock"></i></span><?php endif; ?>
                                    <a href="../?page=forum&faction=topic&id=<?= $t['id'] ?>" target="_blank" class="font-medium text-slate-800 hover:text-blue-600"><?= h($t['title']) ?></a>
                                    <?php if (isset($cat_map[$t['category_id']]) && $t['category_id']): ?>
                                        <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full"><?= h($cat_map[$t['category_id']]) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm text-slate-600"><?= h($t['author_name']) ?></td>
                            <td class="px-3 py-3 text-center text-sm text-slate-600"><?= (int)$t['replies_count'] ?></td>
                            <td class="px-3 py-3 text-center text-sm text-slate-600"><?= (int)$t['views'] ?></td>
                            <td class="px-3 py-3 text-sm text-slate-500"><?= h(date('d.m.Y H:i', strtotime($t['created_at']))) ?></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <form method="POST" class="inline"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="pin_topic">
                                        <input type="hidden" name="item_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="tab" value="topics">
                                        <button class="aicms-act-outline <?= $t['is_pinned'] ? 'aicms-act-outline--warning' : '' ?>"><i class="fas fa-thumbtack"></i><span class="btn-label"><?= $t['is_pinned'] ? 'Открепить' : 'Закрепить' ?></span></button>
                                    </form>
                                    <form method="POST" class="inline"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="close_topic">
                                        <input type="hidden" name="item_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="tab" value="topics">
                                        <button class="aicms-act-outline <?= $t['is_closed'] ? 'aicms-act-outline--danger' : '' ?>"><i class="fas fa-<?= $t['is_closed'] ? 'lock-open' : 'lock' ?>"></i><span class="btn-label"><?= $t['is_closed'] ? 'Открыть' : 'Закрыть' ?></span>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить тему со всеми ответами?')"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="delete_topic">
                                        <input type="hidden" name="item_id" value="<?= $t['id'] ?>">
                                        <input type="hidden" name="tab" value="topics">
                                        <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $total_pages = max(1, ceil($total / $per_page));
            if ($total_pages > 1):
            ?>
            <div class="flex justify-center gap-2 mt-4">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?tab=topics&p=<?= $i ?><?= $search ? '&q=' . urlencode($search) : '' ?>" class="px-3 py-1.5 border rounded-lg text-sm <?= $i == $page_num ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($current_tab === 'posts'): ?>
            <!-- ===== POSTS TAB ===== -->
            <?php
            $page_num = max(1, (int)($_GET['p'] ?? 1));
            $per_page = 20;
            $offset = ($page_num - 1) * $per_page;
            $filter_status = $_GET['status'] ?? '';
            $where = $filter_status ? 'status = ?' : '';
            $params = $filter_status ? [$filter_status] : [];
            $total = db_count('forum_posts', $where, $params);
            $posts = db_select('forum_posts', '*', $where, $params, 'created_at DESC', $per_page, $offset);
            // Build topic map
            $topic_ids = array_unique(array_column($posts, 'topic_id'));
            $topic_map = [];
            if (!empty($topic_ids)) {
                $placeholders = implode(',', array_fill(0, count($topic_ids), '?'));
                $topics_data = db_query('SELECT id, title FROM ' . db_table('forum_topics') . ' WHERE id IN (' . $placeholders . ')', $topic_ids);
                foreach ($topics_data as $td) { $topic_map[$td['id']] = $td['title']; }
            }
            ?>
            <form method="GET" class="mb-4 flex gap-2 items-center">
                <input type="hidden" name="tab" value="posts">
                <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg" onchange="this.form.submit()">
                    <option value="">Все ответы</option>
                    <option value="visible" <?= $filter_status === 'visible' ? 'selected' : '' ?>>Видимые</option>
                    <option value="hidden" <?= $filter_status === 'hidden' ? 'selected' : '' ?>>Скрытые</option>
                </select>
                <?php if ($filter_status): ?>
                <a href="?tab=posts" class="aicms-btn aicms-btn--secondary">Сбросить</a>
                <?php endif; ?>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Текст</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Автор</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Тема</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($posts)): ?>
                        <tr><td colspan="6" class="text-center py-8 text-slate-400">Ответов нет</td></tr>
                        <?php else: foreach ($posts as $p): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-3 text-sm text-slate-700 max-w-xs truncate"><?= h(mb_substr($p['content'], 0, 80)) ?><?= mb_strlen($p['content']) > 80 ? '...' : '' ?></td>
                            <td class="px-3 py-3 text-sm text-slate-600"><?= h($p['author_name']) ?></td>
                            <td class="px-3 py-3 text-sm">
                                <?php if (isset($topic_map[$p['topic_id']])): ?>
                                <a href="../?page=forum&faction=topic&id=<?= $p['topic_id'] ?>" target="_blank" class="text-blue-600 hover:underline"><?= h($topic_map[$p['topic_id']]) ?></a>
                                <?php else: ?>
                                <span class="text-slate-400">— удалена —</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3">
                                <?php if ($p['status'] === 'visible'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-circle text-[6px]"></i> Видим</span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-circle text-[6px]"></i> Скрыт</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-slate-500"><?= h(date('d.m.Y H:i', strtotime($p['created_at']))) ?></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <form method="POST" class="inline"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="hide_post">
                                        <input type="hidden" name="item_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="tab" value="posts">
                                        <button class="aicms-act-outline"><i class="fas fa-<?= $p['status'] === 'visible' ? 'eye-slash' : 'eye' ?>"></i><span class="btn-label"><?= $p['status'] === 'visible' ? 'Скрыть' : 'Показать' ?></span>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить ответ?')"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="delete_post">
                                        <input type="hidden" name="item_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="tab" value="posts">
                                        <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $total_pages = max(1, ceil($total / $per_page));
            if ($total_pages > 1):
            ?>
            <div class="flex justify-center gap-2 mt-4">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?tab=posts&p=<?= $i ?><?= $filter_status ? '&status=' . $filter_status : '' ?>" class="px-3 py-1.5 border rounded-lg text-sm <?= $i == $page_num ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($current_tab === 'categories'): ?>
            <!-- ===== CATEGORIES TAB ===== -->
            <?php $categories = db_select('forum_categories', '*', '', [], 'position ASC'); ?>

            <div class="mb-6 p-5 bg-slate-50 rounded-xl border border-slate-200">
                <h3 class="font-semibold text-slate-800 mb-3">Создать категорию</h3>
                <form method="POST" class="flex flex-col md:flex-row gap-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="admin_action" value="add_category">
                    <input type="text" name="cat_title" placeholder="Название категории" required class="flex-1 px-3 py-2 border border-slate-300 rounded-lg">
                    <input type="text" name="cat_desc" placeholder="Описание (необязательно)" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg">
                    <button type="submit" class="aicms-btn aicms-btn--primary"><i class="fas fa-plus"></i> Создать</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Название</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-slate-500 uppercase">Описание</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Тем</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Позиция</th>
                            <th class="px-3 py-2 text-center text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                        <tr><td colspan="5" class="text-center py-8 text-slate-400">Категорий нет</td></tr>
                        <?php else: foreach ($categories as $cat):
                            $cat_topics = db_count('forum_topics', 'category_id = ?', [$cat['id']]);
                        ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50">
                            <td class="px-3 py-3 font-medium text-slate-800"><?= h($cat['title']) ?></td>
                            <td class="px-3 py-3 text-sm text-slate-600"><?= h($cat['description'] ?? '—') ?></td>
                            <td class="px-3 py-3 text-center text-sm text-slate-600"><?= $cat_topics ?></td>
                            <td class="px-3 py-3 text-center text-sm text-slate-600"><?= (int)$cat['position'] ?></td>
                            <td class="px-3 py-3">
                                <div class="flex justify-center">
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить категорию? Темы не будут удалены.')"><?= csrf_field() ?>
                                        <input type="hidden" name="admin_action" value="delete_category">
                                        <input type="hidden" name="item_id" value="<?= $cat['id'] ?>">
                                        <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/core/sidebar.php'; ?>
</div>
</div>
</body>
</html>
