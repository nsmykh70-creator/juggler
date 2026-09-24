<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/versioning.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash_message(t('invalid_id'), 'danger'); header('Location: news.php'); exit; }

$news_item = null; $news_lang = ADMIN_LANG;
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    foreach (read_content('news.json', [], $lang_code) as $n) {
        if ($n['id'] === $id) { $news_item = $n; $news_lang = $lang_code; break 2; }
    }
}
if (!$news_item) { set_flash_message(t('page_not_found'), 'danger'); header('Location: news.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $version = version_get($_POST['filename'] ?? '');
    if ($version) {
        version_save('news', $news_item, $news_lang);
        $all_news = read_content('news.json', [], $news_lang);
        foreach ($all_news as &$n) {
            if ($n['id'] === $id) {
                $n['title'] = $version['title'] ?? $n['title'];
                $n['content'] = $version['content'] ?? $n['content'];
                if (isset($version['date'])) $n['date'] = $version['date'];
                if (isset($version['category'])) $n['category'] = $version['category'];
                if (isset($version['tags'])) $n['tags'] = $version['tags'];
                break;
            }
        }
        unset($n);
        write_content('news.json', $all_news, $news_lang);
        audit_log('restore_version', "news_id={$id}");
        require_once __DIR__ . '/core/cache.php'; cache_clear();
        set_flash_message(t('version_restored'), 'success');
        header('Location: edit_news.php?id=' . $id); exit;
    }
}
$versions = version_list('news', $id, $news_lang);
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_page_history') ?> — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
    <style>
        .diff-modal { position:fixed; inset:0; z-index:100001; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.6); padding:20px; }
        .diff-modal.show { display:flex; }
        .diff-modal-box { background:#fff; border-radius:12px; max-width:95vw; max-height:90vh; width:1000px; display:flex; flex-direction:column; overflow:hidden; }
        .diff-header { display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid #e2e8f0; }
        .diff-body { flex:1; overflow:auto; padding:20px; background:#f8fafc; }
        .diff-content { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; font-family:'Fira Code',monospace; font-size:13px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
        .diff-line-add { background:#dcfce7; color:#166534; display:block; }
        .diff-line-del { background:#fee2e2; color:#991b1b; display:block; text-decoration:line-through; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_page_history');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('news'),'url'=>'news.php'],['label'=>h($news_item['title'])],['label'=>t('history_label')]];
        $action_panel = '<span class="text-sm text-slate-500 self-center">ID: '.$id.' · '.strtoupper($news_lang).'</span><a href="edit_news.php?id='.$id.'" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>
        <?php if (empty($versions)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('no_versions') ?></div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full">
            <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('date') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('title') ?></th><th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th></tr></thead>
            <tbody class="divide-y divide-slate-200">
            <?php foreach ($versions as $i => $v): $m = $v['__version_meta']; ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-sm"><strong class="text-slate-700"><?= h($m['saved_at']) ?></strong><?php if ($i === 0): ?><span class="ml-2 px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 rounded"><?= t('version_latest') ?></span><?php endif; ?></td>
                <td class="px-4 py-3 text-sm text-slate-600"><?= h($m['title']) ?></td>
                <td class="px-4 py-3"><div class="flex gap-1.5 justify-end">
                    <form method="POST" class="inline" onsubmit="return confirm('<?= t('restore_confirm') ?>')"><?= csrf_field() ?><input type="hidden" name="filename" value="<?= h($m['filename']) ?>"><button class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-undo"></i><span class="btn-label"><?= t('restore') ?></span></button></form>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
