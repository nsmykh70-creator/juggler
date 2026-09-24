<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/user_auth.php';
require_auth();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['admin_action'] ?? '';

    // ===== Create new user from admin =====
    if ($action === 'create' && db_available()) {
        $username = trim($_POST['new_username'] ?? '');
        $email = trim(strtolower($_POST['new_email'] ?? ''));
        $password = $_POST['new_password'] ?? '';
        $display_name = trim($_POST['new_display_name'] ?? '');
        $role = $_POST['new_role'] ?? 'user';
        if (!in_array($role, ['user','moderator','seller','admin'])) $role = 'user';

        $errors = [];
        if (strlen($username) < 3) $errors[] = t('err_username_short');
        if (!preg_match('/^[a-zA-Z0-9_\-а-яА-ЯёЁ]+$/u', $username)) $errors[] = t('err_username_chars');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('err_email_invalid');
        if (strlen($password) < 6) $errors[] = t('err_password_short');

        if (empty($errors)) {
            $exists = db_row('SELECT id FROM ' . db_table('users') . ' WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
            if ($exists) {
                set_flash_message(t('err_user_exists'), 'danger');
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $id = db_insert('users', [
                    'username'     => $username,
                    'email'        => $email,
                    'password'     => $hash,
                    'display_name' => $display_name ?: $username,
                    'role'         => $role,
                    'is_banned'    => 0,
                    'posts_count'  => 0,
                    'comments_count' => 0,
                ]);
                set_flash_message(str_replace(['{$username}','{$role}'], [$username, $role], t('msg_user_created')), 'success');
            }
        } else {
            set_flash_message(str_replace('{$errors}', implode(' ', $errors), t('msg_user_errors')), 'danger');
        }
        header('Location: users.php'); exit;
    }

    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0 && db_available()) {
        if ($action === 'ban') {
            db_update('users', ['is_banned' => 1], ['id' => $uid]);
            set_flash_message(t('msg_user_banned'), 'success');
        } elseif ($action === 'unban') {
            db_update('users', ['is_banned' => 0], ['id' => $uid]);
            set_flash_message(t('msg_user_unbanned'), 'success');
        } elseif ($action === 'make_seller') {
        db_update('users', ['role' => 'seller'], ['id' => $uid]);
        set_flash_message("Пользователь назначен продавцом.", "success");
    } elseif ($action === 'make_moderator') {
            db_update('users', ['role' => 'moderator'], ['id' => $uid]);
            set_flash_message(t('msg_user_made_mod'), 'success');
        } elseif ($action === 'make_admin') {
            db_update('users', ['role' => 'admin'], ['id' => $uid]);
            set_flash_message(t('msg_user_made_admin'), 'success');
        } elseif ($action === 'make_user') {
            db_update('users', ['role' => 'user'], ['id' => $uid]);
            set_flash_message(t('msg_user_role_reset'), 'success');
        } elseif ($action === 'delete') {
            db_delete('users', $uid);
            set_flash_message(t('msg_user_deleted'), 'success');
        }
    }
    header('Location: users.php'); exit;
}

$users_list = [];
$users_count = 0;
$banned_count = 0;
if (db_available()) {
    $users_list = db_select('users', '*', '', [], 'created_at DESC', 200);
    $users_count = db_count('users');
    $banned_count = db_count('users', 'is_banned = ?', [1]);
}

ob_start();
?>
<span class="text-sm text-slate-500 self-center">
    <?= t('users_count') ?> <strong class="text-slate-700"><?= $users_count ?></strong> · <?= t('banned_count') ?> <strong class="text-red-600"><?= $banned_count ?></strong>
</span>
<button class="aicms-icon-btn aicms-icon-btn--primary" onclick="document.getElementById('createUserModal').classList.remove('hidden')"><i class="fas fa-user-plus"></i><span class="btn-label"><?= t('add_user') ?></span></button>
<?php $action_panel = ob_get_clean();
$page_title = t('h_users');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('h_users')]];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_users') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <?php display_flash_message(); ?>

            <?php if (!db_available()): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-800">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong><?= t('db_not_connected') ?></strong>
                <a href="settings.php?tab=database" class="underline"><?= t('configure_db_hint') ?></a>.
            </div>
            <?php elseif (empty($users_list)): ?>
            <div class="bg-white rounded-xl shadow-sm p-12 text-center text-slate-400">
                <i class="fas fa-users text-5xl text-slate-300 mb-4 block"></i>
                <?= t('no_users') ?><br>
                <span class="text-sm"><?= t('no_users_hint') ?></span>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('user') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('email') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('role') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('statistics') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('registration') ?></th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php foreach ($users_list as $u): ?>
                            <tr class="hover:bg-slate-50 aicms-row-actions <?= !empty($u['is_banned']) ? 'bg-red-50' : '' ?>">
                                <td class="px-4 py-3 text-sm text-slate-500 font-mono"><?= (int)$u['id'] ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 text-white flex items-center justify-center font-bold text-sm"><?= h(mb_substr($u['display_name'] ?: $u['username'], 0, 1, 'UTF-8')) ?></div>
                                        <div>
                                            <div class="font-medium text-slate-800"><?= h($u['display_name'] ?: $u['username']) ?></div>
                                            <div class="text-xs text-slate-400">@<?= h($u['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600"><?= h($u['email']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-purple-100 text-purple-800"><i class="fas fa-circle text-[6px]"></i> admin</span>
                                    <?php elseif ($u['role'] === 'moderator'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800"><i class="fas fa-circle text-[6px]"></i> moderator</span>
                                    <?php elseif ($u['role'] === 'seller'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-circle text-[6px]"></i> seller</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-slate-100 text-slate-600"><i class="fas fa-circle text-[6px]"></i> user</span>
                                    <?php endif; ?>
                                    <?php if (!empty($u['is_banned'])): ?>
                                        <span class="ml-1 inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-ban text-[10px]"></i> <?= t('banned') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500">
                                    <span title="<?= t('comments_tip') ?>">💬 <?= (int)$u['comments_count'] ?></span>
                                    <span class="ml-2" title="<?= t('forum_posts_tip') ?>">📝 <?= (int)$u['posts_count'] ?></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500"><?= h(date('d.m.Y H:i', strtotime($u['created_at']))) ?></td>
                                <td class="px-4 py-3">
                                    <div class="aicms-table-actions">
                                        <?php if (!empty($u['is_banned'])): ?>
                                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="admin_action" value="unban"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-unlock"></i><span class="btn-label"><?= t('unban_tip') ?></span></button></form>
                                        <?php else: ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('ban_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="admin_action" value="ban"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--warning"><i class="fas fa-ban"></i><span class="btn-label"><?= t('ban_tip') ?></span></button></form>
                                        <?php endif; ?>
                                        <?php if ($u['role'] !== 'moderator' && $u['role'] !== 'admin'): ?>
                                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_moderator"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--info"><i class="fas fa-user-shield"></i><span class="btn-label"><?= t('make_mod_tip') ?></span></button></form>
                                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_seller"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-store"></i><span class="btn-label">Назначить продавцом</span></button></form>
                                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('make_admin_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_admin"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--primary"><i class="fas fa-crown"></i><span class="btn-label"><?= t('make_admin_tip') ?></span></button></form>
                                        <?php elseif ($u['role'] === 'moderator'): ?>
                                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-user"></i><span class="btn-label"><?= t('remove_mod_tip') ?></span></button></form>
                                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('make_admin_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_admin"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--primary"><i class="fas fa-crown"></i><span class="btn-label"><?= t('make_admin_tip') ?></span></button></form>
                                        <?php elseif ($u['role'] === 'admin'): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('remove_admin_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="admin_action" value="make_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-user"></i><span class="btn-label"><?= t('remove_admin_tip') ?></span></button></form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('<?= t('delete_user_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="admin_action" value="delete"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button></form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- Create User Modal -->
<div id="createUserModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" style="display:flex;">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="font-semibold text-slate-800 m-0"><?= t('add_user') ?></h5>
            <button onclick="document.getElementById('createUserModal').classList.add('hidden')" class="text-slate-400 text-xl">&times;</button>
        </div>
        <form method="POST" class="p-5">
            <?= csrf_field() ?>
            <input type="hidden" name="admin_action" value="create">
            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('username_label_req') ?></label>
                <input type="text" name="new_username" required pattern="[a-zA-Z0-9_\-а-яА-ЯёЁ]+" minlength="3" maxlength="50" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="ivan_petrov">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('display_name_label') ?></label>
                <input type="text" name="new_display_name" maxlength="150" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="<?= t('display_name_ph') ?>">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('email') ?> *</label>
                <input type="email" name="new_email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="ivan@example.com">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('password_req') ?> <span class="text-xs text-slate-400"><?= t('password_min') ?></span></label>
                <input type="password" name="new_password" required minlength="6" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none font-mono" placeholder="<?= t('password_ph') ?>">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('role') ?></label>
                <select name="new_role" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none">
                    <option value="user"><?= t('role_user') ?></option>
                    <option value="moderator"><?= t('role_moderator') ?></option>
                    <option value="admin"><?= t('role_admin') ?></option>
                </select>
            </div>
            <div class="text-right">
                <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-check"></i><span class="btn-label"><?= t('create') ?></span></button>
            </div>
        </form>
    </div>
</div>
<script>
// Hide modal initially (we used display:flex inline above to avoid FOUC, now hide)
document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('createUserModal');
    if (m && !m.dataset.shown) { m.classList.add('hidden'); m.style.display=''; }
});
</script>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
