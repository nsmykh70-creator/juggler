<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$bookings_file = __DIR__ . '/../data/backup_settings.json';
$backup_settings = file_exists($bookings_file) ? (json_decode(file_get_contents($bookings_file), true) ?: []) : ['enabled'=>false,'period'=>'daily'];
$backup_dir = __DIR__ . '/../backup/';
$backup_list = [];
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*.zip');
    foreach ($files as $f) { $backup_list[] = ['name'=>basename($f), 'date'=>date('Y-m-d H:i', filemtime($f)), 'size'=>round(filesize($f)/1024, 1)]; }
}
usort($backup_list, fn($a,$b) => strcmp($b['date'], $a['date']));

ob_start();
?>
<form action="backup.php" method="POST" class="inline"><?= csrf_field() ?><button type="submit" name="create" value="1" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-plus"></i><span class="btn-label"><?= t('create_backup') ?></span></button></form>
<span class="ml-auto text-sm text-slate-500 self-center"><?= t('backups_count') ?> <strong class="text-slate-700"><?= count($backup_list) ?></strong></span>
<?php $action_panel = ob_get_clean();
$page_title = t('h_backups');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('backups')]];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_backups') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        /* Backup action buttons — all identical size, grouped as one block,
           aligned to the right edge of the table cell. */
        .fm-backup-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            float: right;
        }
        .fm-backup-actions form {
            display: inline-flex;
            margin: 0;
            padding: 0;
        }
        .fm-backup-btn {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            min-height: 36px !important;
            max-width: 36px !important;
            max-height: 36px !important;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            text-decoration: none;
            padding: 0 !important;
            box-sizing: border-box;
            transition: transform 0.15s, box-shadow 0.15s, background-color 0.15s;
        }
        .fm-backup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.15);
        }
        .fm-backup-btn i { font-size: 14px; }
        .fm-backup-btn--download { background-color: #2563eb; color: #fff; }
        .fm-backup-btn--download:hover { background-color: #1d4ed8; }
        .fm-backup-btn--restore { background-color: #f59e0b; color: #fff; }
        .fm-backup-btn--restore:hover { background-color: #d97706; }
        .fm-backup-btn--delete { background-color: #dc2626; color: #fff; }
        .fm-backup-btn--delete:hover { background-color: #b91c1c; }

        /* === Auto-backup toggle switch ===
           Replaces Tailwind peer/after classes (which don't work with the
           precompiled Tailwind v2 CSS) with explicit CSS. */
        .auto-backup-toggle {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }
        .auto-backup-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }
        .auto-backup-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            border-radius: 24px;
            transition: background-color 0.2s;
        }
        .auto-backup-toggle-slider::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            left: 2px;
            top: 2px;
            background-color: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .auto-backup-toggle input:checked + .auto-backup-toggle-slider {
            background-color: #2563eb;
        }
        .auto-backup-toggle input:checked + .auto-backup-toggle-slider::before {
            transform: translateX(20px);
        }
        .auto-backup-toggle input:focus-visible + .auto-backup-toggle-slider {
            box-shadow: 0 0 0 2px rgba(37,99,235,0.3);
        }
        /* Dark theme */
        body.dark-theme .auto-backup-toggle-slider { background-color: #475569; }
        body.dark-theme .auto-backup-toggle input:checked + .auto-backup-toggle-slider { background-color: #3b82f6; }
        /* "Save settings" is a full-width form button — it must NOT behave
           like a rubber icon-button. The stretch coordinator already skips it
           (w-full + justify-center), but the base .aicms-btn:hover lifts via
           translateY, which makes the settings card "jump". Pin it flat. */
        .aicms-save-settings,
        .aicms-save-settings:hover { transform: none !important; box-shadow: none !important; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Backup list -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><i class="fas fa-archive mr-1"></i><?= t('backup_list') ?></div>
                <?php if (empty($backup_list)): ?>
                <div class="p-5 text-center text-slate-400"><?= t('no_backups') ?></div>
                <?php else: ?>
                <div class="overflow-x-auto"><table class="w-full">
                    <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('date') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('size_kb') ?></th><th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
                    <?php foreach ($backup_list as $b): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-sm text-slate-700"><?= h($b['date']) ?></td>
                        <td class="px-4 py-3 text-sm text-slate-500"><?= $b['size'] ?></td>
                        <td class="px-4 py-3 text-right">
                            <div class="fm-backup-actions">
                                <a href="backup.php?download=<?= h($b['name']) ?>" class="fm-backup-btn fm-backup-btn--download" title="<?= t('download') ?>"><i class="fas fa-download"></i></a>
                                <form method="post" action="restore.php" onsubmit="return confirm('<?= t('restore_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="file" value="<?= h($b['name']) ?>"><button type="submit" class="fm-backup-btn fm-backup-btn--restore" title="<?= t('restore') ?>"><i class="fas fa-history"></i></button></form>
                                <form method="post" action="backup.php" onsubmit="return confirm('<?= t('delete_backup_confirm') ?>');"><?= csrf_field() ?><input type="hidden" name="delete" value="<?= h($b['name']) ?>"><button type="submit" class="fm-backup-btn fm-backup-btn--delete" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button></form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>

            <!-- Auto backup settings -->
            <div class="bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><i class="fas fa-robot mr-1"></i><?= t('auto_backup_short') ?></div>
                <div class="p-5">
                    <form action="save_backup_settings.php" method="post"><?= csrf_field() ?>
                        <p class="text-xs text-slate-400 mb-3"><?= t('auto_backup_hint') ?></p>
                        <div class="flex items-center gap-3 mb-4">
                            <label class="auto-backup-toggle">
                                <input type="checkbox" name="enabled" id="autoBackupEnabled" <?= $backup_settings['enabled'] ? 'checked' : '' ?>>
                                <span class="auto-backup-toggle-slider"></span>
                            </label>
                            <label for="autoBackupEnabled" class="text-sm text-slate-700"><?= t('enable_auto_backup') ?></label>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('period_label') ?></label>
                            <select name="period" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none">
                                <option value="daily" <?= $backup_settings['period']==='daily'?'selected':'' ?>><?= t('daily') ?></option>
                                <option value="weekly" <?= $backup_settings['period']==='weekly'?'selected':'' ?>><?= t('weekly') ?></option>
                                <option value="monthly" <?= $backup_settings['period']==='monthly'?'selected':'' ?>><?= t('monthly') ?></option>
                            </select>
                        </div>
                        <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success aicms-save-settings w-full justify-center"><i class="fas fa-save"></i><span><?= t('save_settings') ?></span></button></div>
                    </form>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
