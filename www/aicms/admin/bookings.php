<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

$bookings_file = __DIR__ . '/../data/bookings.json';
$bookings = file_exists($bookings_file) ? (json_decode(file_get_contents($bookings_file), true) ?: []) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? 'status';
    $status = $_POST['status'] ?? '';

    // Delete booking
    if ($action === 'delete' && $id > 0) {
        $bookings = array_values(array_filter($bookings, fn($b) => ($b['id'] ?? 0) !== $id));
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message('Бронирование удалено.', 'success');
        header('Location: bookings.php'); exit;
    }

    // Update status
    if ($id > 0 && in_array($status, ['new','confirmed','cancelled','done'])) {
        foreach ($bookings as &$b) { if (($b['id'] ?? 0) === $id) { $b['status'] = $status; break; } }
        unset($b);
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message(t('msg_booking_status_updated'), 'success');
    }
    header('Location: bookings.php'); exit;
}
usort($bookings, fn($a,$b) => strtotime($b['created'] ?? '') <=> strtotime($a['created'] ?? ''));
$st_labels = ['new'=>[t('status_new'),'bg-blue-100 text-blue-800'], 'confirmed'=>[t('status_confirmed'),'bg-green-100 text-green-800'], 'cancelled'=>[t('status_cancelled'),'bg-red-100 text-red-800'], 'done'=>[t('status_done'),'bg-slate-200 text-slate-600']];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_bookings') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_bookings');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('h_bookings')]];
        $action_panel = '<span class="text-sm text-slate-500 self-center">'.t('bookings_count').' <strong class="text-slate-700">'.count($bookings).'</strong></span>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>
        <?php if (empty($bookings)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('no_bookings') ?></div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden"><div class="overflow-x-auto"><table class="w-full">
            <thead><tr class="border-b border-slate-200 bg-slate-50">
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('name') ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('contacts') ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Номер</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Заезд</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Выезд</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Ночей</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('guests') ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('status') ?></th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-200">
            <?php foreach ($bookings as $b): $st = $st_labels[$b['status'] ?? 'new'] ?? $st_labels['new'];
                // Support both old format (date/time) and new format (check_in/check_out)
                $check_in = $b['check_in'] ?? $b['date'] ?? '';
                $check_out = $b['check_out'] ?? '';
                $nights = $b['nights'] ?? 0;
                if (!$nights && $check_in && $check_out) {
                    $nights = (int)round((strtotime($check_out) - strtotime($check_in)) / 86400);
                }
                // Format dates as dd.mm.yyyy
                $ci_formatted = $check_in ? date('d.m.Y', strtotime($check_in)) : '—';
                $co_formatted = $check_out ? date('d.m.Y', strtotime($check_out)) : ($b['time'] ?? '—');
            ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-sm text-slate-500"><?= $b['id'] ?? '' ?></td>
                <td class="px-4 py-3"><strong class="text-sm text-slate-800"><?= h($b['name'] ?? '') ?></strong><?php if(!empty($b['notes'])): ?><br><small class="text-slate-400"><?= h(mb_substr($b['notes'],0,50)) ?></small><?php endif; ?></td>
                <td class="px-4 py-3 text-sm"><a href="mailto:<?= h($b['email'] ?? '') ?>" class="text-slate-600 no-underline">📧 <?= h($b['email'] ?? '') ?></a><?php if(!empty($b['phone'])): ?><br><a href="tel:<?= h($b['phone']) ?>" class="text-slate-600 no-underline">📞 <?= h($b['phone']) ?></a><?php endif; ?></td>
                <td class="px-4 py-3 text-sm"><?php if (!empty($b['room_title'])): ?><span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">🛏 <?= h($b['room_title']) ?></span><?php else: ?><span class="text-slate-300">—</span><?php endif; ?></td>
                <td class="px-4 py-3 text-sm"><strong class="text-slate-700">📅 <?= h($ci_formatted) ?></strong></td>
                <td class="px-4 py-3 text-sm"><strong class="text-slate-700">📅 <?= h($co_formatted) ?></strong></td>
                <td class="px-4 py-3 text-sm"><span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-50 text-blue-700 rounded-full text-xs font-semibold">🌙 <?= (int)$nights ?></span></td>
                <td class="px-4 py-3 text-sm text-slate-500"><?= h($b['guests'] ?? 1) ?></td>
                <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $st[1] ?>"><?= $st[0] ?></span></td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2 justify-end">
                        <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?? 0 ?>"><select name="status" class="text-sm border border-slate-300 rounded-lg px-2 py-1" onchange="this.form.submit()"><option value="new" <?= ($b['status'] ?? 'new')==='new'?'selected':'' ?>><?= t('status_new') ?></option><option value="confirmed" <?= ($b['status'] ?? '')==='confirmed'?'selected':'' ?>><?= t('confirm_booking') ?></option><option value="done" <?= ($b['status'] ?? '')==='done'?'selected':'' ?>><?= t('complete_booking') ?></option><option value="cancelled" <?= ($b['status'] ?? '')==='cancelled'?'selected':'' ?>><?= t('cancel_booking') ?></option></select></form>
                        <form method="POST" class="inline" onsubmit="return confirm('Удалить бронирование #<?= $b['id'] ?? 0 ?>?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?? 0 ?>"><input type="hidden" name="action" value="delete"><button type="submit" class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label">Удалить</span></button></form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
