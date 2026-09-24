<?php
/**
 * AICMS Admin — Cafe Bookings Management
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/extensions.php';
require_once __DIR__ . '/core/cache.php';

if (!extension_enabled('cafe')) {
    set_flash_message('Кафе отключено.', 'warning');
    header('Location: index.php'); exit;
}

$file = __DIR__ . '/../data/cafe_bookings.json';
$bookings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? 'status';
    $status = $_POST['status'] ?? '';

    if ($action === 'delete' && $id > 0) {
        $bookings = array_values(array_filter($bookings, fn($b) => ($b['id'] ?? 0) !== $id));
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message('Бронирование удалено.', 'success');
    } elseif ($id > 0 && in_array($status, ['new','confirmed','cancelled','done'])) {
        foreach ($bookings as &$b) { if (($b['id'] ?? 0) === $id) { $b['status'] = $status; break; } }
        unset($b);
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message('Статус обновлён.', 'success');
    }
    cache_clear();
    header('Location: cafe_bookings.php'); exit;
}

usort($bookings, fn($a,$b) => strtotime($b['created'] ?? '') <=> strtotime($a['created'] ?? ''));
$st_labels = [
    'new'       => ['Новая',        'bg-blue-100 text-blue-800'],
    'confirmed' => ['Подтверждена',  'bg-green-100 text-green-800'],
    'cancelled' => ['Отменена',      'bg-red-100 text-red-800'],
    'done'      => ['Завершена',     'bg-slate-200 text-slate-600'],
];

$page_title = 'Заявки кафе';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>'Заявки кафе']];
$action_panel = '<a href="extensions.php?tab=cafe" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-utensils"></i><span class="btn-label">Столики</span></a>';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title>Заявки кафе — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <h1 class="text-2xl font-bold text-slate-800 mb-1">Заявки кафе</h1>
        <p class="text-sm text-slate-500 mb-6">Бронирования столиков</p>

        <?php if (empty($bookings)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2">
            <i class="fas fa-info-circle"></i> Заявок пока нет
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Имя</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Контакты</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Столик</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Дата</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Время</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Гостей</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Статус</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                    <?php foreach ($bookings as $b):
                        $st = $st_labels[$b['status'] ?? 'new'] ?? $st_labels['new'];
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm text-slate-500"><?= $b['id'] ?? '' ?></td>
                            <td class="px-4 py-3">
                                <strong class="text-sm text-slate-800"><?= h($b['name'] ?? '') ?></strong>
                                <?php if(!empty($b['notes'])): ?>
                                <br><small class="text-slate-400"><?= h(mb_substr($b['notes'],0,50)) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <a href="mailto:<?= h($b['email'] ?? '') ?>" class="text-slate-600 no-underline">📧 <?= h($b['email'] ?? '') ?></a>
                                <?php if(!empty($b['phone'])): ?>
                                <br><a href="tel:<?= h($b['phone']) ?>" class="text-slate-600 no-underline">📞 <?= h($b['phone']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?php if (!empty($b['table_title'])): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-orange-50 text-orange-700 rounded-full text-xs font-semibold">🍽 <?= h($b['table_title']) ?></span>
                                <?php else: ?>
                                <span class="text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm"><strong class="text-slate-700">📅 <?= h($b['date'] ?? '') ?></strong></td>
                            <td class="px-4 py-3 text-sm"><strong class="text-slate-700">🕐 <?= h($b['time'] ?? '') ?></strong></td>
                            <td class="px-4 py-3 text-sm text-slate-500"><?= h($b['guests'] ?? 2) ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $st[1] ?>"><?= $st[0] ?></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <form method="POST" class="inline"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $b['id'] ?? 0 ?>">
                                        <select name="status" class="text-sm border border-slate-300 rounded-lg px-2 py-1" onchange="this.form.submit()">
                                            <option value="new" <?= ($b['status']??'new')==='new'?'selected':'' ?>>Новая</option>
                                            <option value="confirmed" <?= ($b['status']??'')==='confirmed'?'selected':'' ?>>Подтвердить</option>
                                            <option value="done" <?= ($b['status']??'')==='done'?'selected':'' ?>>Завершена</option>
                                            <option value="cancelled" <?= ($b['status']??'')==='cancelled'?'selected':'' ?>>Отменить</option>
                                        </select>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Удалить бронирование?')"><?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $b['id'] ?? 0 ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button>
                                    </form>
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
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
