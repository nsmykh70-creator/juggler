<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/versioning.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash_message(t('invalid_id'), 'danger'); header('Location: pages.php'); exit; }

$page = null; $page_lang = ADMIN_LANG;
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_code = $lang_info['code'];
    foreach (read_content('pages.json', [], $lang_code) as $p) {
        if ($p['id'] === $id) { $page = $p; $page_lang = $lang_code; break 2; }
    }
}
if (!$page) { set_flash_message(t('page_not_found'), 'danger'); header('Location: pages.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $version = version_get($_POST['filename'] ?? '');
    if ($version) {
        version_save('page', $page, $page_lang);
        $pages = read_content('pages.json', [], $page_lang);
        foreach ($pages as &$p) {
            if ($p['id'] === $id) {
                $p['title']=$version['title']; $p['content']=$version['content']; $p['slug']=$version['slug'];
                if (isset($version['template'])) $p['template']=$version['template'];
                if (isset($version['meta_title'])) $p['meta_title']=$version['meta_title'];
                if (isset($version['meta_description'])) $p['meta_description']=$version['meta_description'];
                break;
            }
        }
        write_content('pages.json', $pages, $page_lang);
        audit_log('restore_version', "page_id={$id}");
        require_once __DIR__ . '/core/cache.php'; cache_clear();
        set_flash_message(t('version_restored'), 'success');
        header('Location: edit_page.php?id=' . $id); exit;
    }
}
$versions = version_list('page', $id, $page_lang);

// Load version data for diff comparison (current page vs each version)
$versions_with_data = [];
foreach ($versions as $i => $v) {
    $meta = $v['__version_meta'];
    $data = version_get($meta['filename']);
    if ($data) {
        $versions_with_data[] = [
            'meta' => $meta,
            'data' => $data,
            'is_latest' => $i === 0,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_page_history') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .diff-modal { position:fixed; inset:0; z-index:100001; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.6); padding:20px; }
        .diff-modal.show { display:flex; }
        .diff-modal-box { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,.25); max-width:95vw; max-height:90vh; width:1000px; display:flex; flex-direction:column; overflow:hidden; }
        .diff-header { display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid #e2e8f0; }
        .diff-header h4 { margin:0; font-size:15px; font-weight:600; color:#1e293b; }
        .diff-controls { display:flex; gap:8px; align-items:center; }
        .diff-body { flex:1; overflow:auto; padding:20px; background:#f8fafc; }
        .diff-content { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; font-family:'Fira Code','Consolas',monospace; font-size:13px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
        .diff-line-add { background:#dcfce7; color:#166534; padding:1px 4px; border-radius:2px; display:block; }
        .diff-line-del { background:#fee2e2; color:#991b1b; padding:1px 4px; border-radius:2px; display:block; text-decoration:line-through; }
        .diff-line-ctx { display:block; }
        .diff-toggle { display:flex; gap:6px; margin-bottom:12px; }
        .diff-toggle button { padding:6px 12px; border:1px solid #cbd5e1; background:#fff; color:#475569; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; }
        .diff-toggle button.active { background:#2563eb; color:#fff; border-color:#2563eb; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_page_history');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('pages'),'url'=>'pages.php'],['label'=>h($page['title'])],['label'=>t('history_label')]];
        $action_panel = '<span class="text-sm text-slate-500 self-center">ID: '.$id.' · '.strtoupper($page_lang).'</span><a href="edit_page.php?id='.$id.'" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>
        <?php if (empty($versions_with_data)): ?>
        <div class="bg-blue-50 text-blue-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('no_versions') ?></div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full">
            <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('date') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('title') ?></th><th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th></tr></thead>
            <tbody class="divide-y divide-slate-200">
            <?php foreach ($versions_with_data as $i => $v): ?>
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-sm"><strong class="text-slate-700"><?= h($v['meta']['saved_at']) ?></strong><?php if ($v['is_latest']): ?><span class="ml-2 px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 rounded"><?= t('version_latest') ?></span><?php endif; ?></td>
                <td class="px-4 py-3 text-sm text-slate-600"><?= h($v['meta']['title']) ?></td>
                <td class="px-4 py-3"><div class="flex gap-1.5 justify-end">
                    <button class="aicms-act-outline aicms-act-outline--info" onclick="showDiff(<?= $i ?>)"><i class="fas fa-code-compare"></i><span class="btn-label"><?= t('view') ?></span></button>
                    <form method="POST" class="inline" onsubmit="return confirm('<?= t('restore_confirm') ?>')"><?= csrf_field() ?><input type="hidden" name="filename" value="<?= h($v['meta']['filename']) ?>"><button class="aicms-act-outline aicms-act-outline--success"><i class="fas fa-undo"></i><span class="btn-label"><?= t('restore') ?></span></button></form>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Diff modal -->
<div class="diff-modal" id="diffModal" role="dialog" aria-modal="true" aria-labelledby="diffTitle" onclick="if(event.target===this)closeDiff()">
    <div class="diff-modal-box">
        <div class="diff-header">
            <h4 id="diffTitle"><i class="fas fa-code-compare mr-1"></i><?= t('h_page_history') ?></h4>
            <div class="diff-controls">
                <div class="diff-toggle">
                    <button id="diffToggleInline" class="active" onclick="setDiffMode('inline')">Inline</button>
                    <button id="diffToggleSide" onclick="setDiffMode('side')">Side-by-side</button>
                </div>
                <button onclick="closeDiff()" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><?= t('close') ?></button>
            </div>
        </div>
        <div class="diff-body" id="diffBody">
            <div class="diff-content" id="diffContent">Loading…</div>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
<script>
// Version data injected from PHP
var VERSIONS = <?= json_encode(array_map(function($v) use ($page) {
    return [
        'title' => $v['data']['title'] ?? '',
        'content' => $v['data']['content'] ?? '',
        'saved_at' => $v['meta']['saved_at'],
        'is_latest' => $v['is_latest'],
    ];
}, $versions_with_data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var CURRENT_TITLE = <?= json_encode($page['title'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
var CURRENT_CONTENT = <?= json_encode($page['content'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
var diffMode = 'inline';

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function showDiff(versionIdx) {
    var v = VERSIONS[versionIdx];
    if (!v) return;
    var titleDiff = window.Diff ? window.Diff.diffWords(CURRENT_TITLE, v.title) : [];
    var contentDiff = window.Diff ? window.Diff.diffLines(CURRENT_CONTENT, v.content) : [];
    var html = '';
    // Title diff
    html += '<h5 style="font-size:13px;font-weight:600;color:#475569;margin:0 0 8px 0;">Title</h5>';
    html += '<div style="margin-bottom:16px;">';
    titleDiff.forEach(function(part) {
        var cls = part.added ? 'diff-line-add' : part.removed ? 'diff-line-del' : 'diff-line-ctx';
        html += '<span class="' + cls + '">' + escapeHtml(part.value) + '</span>';
    });
    html += '</div>';
    // Content diff
    html += '<h5 style="font-size:13px;font-weight:600;color:#475569;margin:0 0 8px 0;">Content</h5>';
    html += '<div>';
    if (diffMode === 'inline') {
        contentDiff.forEach(function(part) {
            var cls = part.added ? 'diff-line-add' : part.removed ? 'diff-line-del' : 'diff-line-ctx';
            // Each part may contain multiple lines — render each line as block
            var lines = part.value.split('\n');
            lines.forEach(function(line, i) {
                if (i < lines.length - 1 || line) {
                    html += '<span class="' + cls + '">' + escapeHtml(line) + '</span>';
                }
            });
        });
    } else {
        // Side-by-side: render removed and added separately in two columns
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">';
        html += '<div><strong style="display:block;margin-bottom:4px;font-size:11px;color:#991b1b;">Current (latest)</strong>';
        contentDiff.forEach(function(part) {
            if (!part.added) {
                html += '<span class="' + (part.removed ? 'diff-line-del' : 'diff-line-ctx') + '">' + escapeHtml(part.value) + '</span>';
            }
        });
        html += '</div>';
        html += '<div><strong style="display:block;margin-bottom:4px;font-size:11px;color:#166534;">Version from ' + escapeHtml(v.saved_at) + '</strong>';
        contentDiff.forEach(function(part) {
            if (!part.removed) {
                html += '<span class="' + (part.added ? 'diff-line-add' : 'diff-line-ctx') + '">' + escapeHtml(part.value) + '</span>';
            }
        });
        html += '</div></div>';
    }
    html += '</div>';
    document.getElementById('diffContent').innerHTML = html;
    document.getElementById('diffTitle').innerHTML = '<i class="fas fa-code-compare mr-1"></i><?= t('h_page_history') ?> — ' + escapeHtml(v.saved_at) + (v.is_latest ? ' (latest)' : '');
    document.getElementById('diffModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeDiff() {
    document.getElementById('diffModal').classList.remove('show');
    document.body.style.overflow = '';
}

function setDiffMode(mode) {
    diffMode = mode;
    document.getElementById('diffToggleInline').classList.toggle('active', mode === 'inline');
    document.getElementById('diffToggleSide').classList.toggle('active', mode === 'side');
    // Re-render with new mode if modal is open
    if (document.getElementById('diffModal').classList.contains('show')) {
        // Find current version index — simplest: just re-show last viewed
        // For simplicity, store last index
        if (window._lastDiffIdx !== undefined) showDiff(window._lastDiffIdx);
    }
}

// Track last shown index
var origShowDiff = showDiff;
showDiff = function(idx) {
    window._lastDiffIdx = idx;
    origShowDiff(idx);
};

// ESC to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('diffModal').classList.contains('show')) {
        closeDiff();
    }
});
</script>
</body>
</html>
