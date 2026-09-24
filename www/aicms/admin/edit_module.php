<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$module_name = $_GET['module'] ?? '';
if (empty($module_name) || !preg_match('/^[a-z0-9_]+$/', $module_name)) { 
    header('Location: modules.php'); exit; 
}
$module_path = __DIR__ . '/../modules/' . $module_name . '.php';
$page_title = file_exists($module_path) ? 'Edit: ' . $module_name : 'Create: ' . $module_name;
$module_content = file_exists($module_path) ? file_get_contents($module_path) : "<?php\n// New module\n";
$encoded_content = json_encode($module_content, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= h($page_title) ?> — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=JetBrains+Mono:wght@400;500&family=Source+Code+Pro:wght@400;500&display=swap" rel="stylesheet">
    <style>
        #editor-container { height: calc(100vh - 200px); }
        .monaco-fullscreen { position: fixed !important; inset: 0 !important; z-index: 99999 !important; background: #1e1e1e !important; }
        .monaco-fullscreen #editor-container { height: calc(100vh - 60px) !important; }
        .monaco-fullscreen .editor-toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 100000; }
        /* Monaco color picker — make sure it's above all admin UI */
        .monaco-editor .colorpicker { z-index: 100000 !important; }
        .monaco-editor .colorpicker-widget { z-index: 100000 !important; }
        .monaco-editor .suggest-widget { z-index: 100000 !important; }
        .monaco-editor .editor-widget { z-index: 100000 !important; }
        .monaco-editor .monaco-hover { z-index: 100000 !important; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('modules'),'url'=>'modules.php'],['label'=>h($module_name)]];
        $action_panel = '<a href="modules.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fa fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a><span class="text-sm text-slate-500 self-center">'.t('shortcode').': <code class="px-1.5 py-0.5 bg-slate-100 rounded text-blue-600 font-mono text-xs">{{module:'.h($module_name).'}}</code></span>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-4 flex-1 flex flex-col" id="editor-wrapper">
        <!-- Editor toolbar -->
        <div class="editor-toolbar bg-white rounded-xl shadow-sm border border-slate-200 mb-3 p-2 flex flex-wrap items-center gap-2">
            <div class="flex gap-1">
                <button id="undo-button" class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-undo"></i><span class="btn-label"><?= t('undo_tip') ?></span></button>
                <button id="redo-button" class="aicms-act-outline aicms-act-outline--secondary"><i class="fas fa-redo"></i><span class="btn-label"><?= t('redo_tip') ?></span></button>
            </div>
            <div class="flex items-center gap-1">
                <label class="text-xs text-slate-500"><?= t('font') ?></label>
                <select id="fontFamilySelect" class="text-sm border border-slate-300 rounded-lg px-2 py-1.5">
                    <option value="'Fira Code', 'Consolas', monospace">Fira Code</option>
                    <option value="'JetBrains Mono', 'Consolas', monospace">JetBrains Mono</option>
                    <option value="'Source Code Pro', 'Consolas', monospace">Source Code Pro</option>
                    <option value="Consolas, 'Courier New', monospace">Consolas</option>
                    <option value="'Courier New', monospace">Courier New</option>
                    <option value="monospace">Monospace</option>
                </select>
            </div>
            <div class="flex items-center gap-1">
                <label class="text-xs text-slate-500"><?= t('font_size') ?></label>
                <select id="fontSizeSelect" class="text-sm border border-slate-300 rounded-lg px-2 py-1.5">
                    <option value="12">12px</option>
                    <option value="14" selected>14px</option>
                    <option value="16">16px</option>
                    <option value="18">18px</option>
                    <option value="20">20px</option>
                </select>
            </div>
            <button id="fullscreen-btn" class="aicms-btn aicms-btn--ghost aicms-btn--sm ml-auto"><i class="fas fa-expand"></i><span class="btn-label">Полный экран</span></button>
        </div>
        <div class="flex-1 bg-white rounded-xl shadow-sm overflow-visible">
            <div id="editor-container"></div>
        </div>
        </div>
    </div>
</div>

<!-- Floating Action Bar (FAB) -->
<div class="aicms-fab-bar" id="fabBar" data-stretch-block>
    <div class="aicms-fab-actions">
        <button class="aicms-fab-btn aicms-fab-btn--save" onclick="saveModule()"><i class="fas fa-save"></i><span class="btn-label"><?= t('save') ?></span></button>
        <button class="aicms-fab-btn aicms-fab-btn--back" onclick="window.location.href='modules.php'"><i class="fas fa-arrow-left"></i><span class="btn-label"><?= t('back_to_list') ?></span></button>
    </div>
    <button class="aicms-fab-btn aicms-fab-btn--toggle" onclick="var b=document.getElementById('fabBar');b.classList.toggle('is-collapsed');"><i class="fas fa-ellipsis-v"></i><span class="btn-label">Действия</span></button>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs/loader.js"></script>
<script>
var editor;
var csrf = "<?= csrf_field_value() ?>";
var isFullscreen = false;

require.config({paths:{'vs':'https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs'}});
require(['vs/editor/editor.main'], function() {
    editor = monaco.editor.create(document.getElementById('editor-container'), {
        value: <?= $encoded_content ?>,
        language: 'php',
        theme: 'vs-dark',
        automaticLayout: true,
        wordWrap: 'on',
        fontSize: 14,
        fontFamily: "'Fira Code', 'Consolas', monospace",
        fontLigatures: true,
        fixedOverflowWidgets: true
    });
    
    document.getElementById('undo-button').addEventListener('click', function() { if(editor) editor.trigger('undo', 'undo'); });
    document.getElementById('redo-button').addEventListener('click', function() { if(editor) editor.trigger('redo', 'redo'); });
    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function() { saveModule(); });
});

// Font controls — attached OUTSIDE require() so they work immediately
document.getElementById('fontFamilySelect').addEventListener('change', function(e) {
    if (editor) editor.updateOptions({ fontFamily: e.target.value });
});
document.getElementById('fontSizeSelect').addEventListener('change', function(e) {
    if (editor) editor.updateOptions({ fontSize: parseInt(e.target.value, 10) });
});

function saveModule() {
    var fd = new FormData();
    fd.append('module_name', '<?= h($module_name) ?>');
    fd.append('content', editor.getValue());
    fd.append('csrf_token', csrf);
    fetch('save_module.php', {method:'POST', body:fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status === 'success') {
                if (d.new_csrf_token) csrf = d.new_csrf_token;
                if (window.AicmsToast) AicmsToast.show(d.message, 'success');
            } else {
                if (window.AicmsToast) AicmsToast.show(d.message || 'Error', 'error');
            }
        })
        .catch(function(e) {
            if (window.AicmsToast) AicmsToast.show(e.message, 'error');
        });
}

// Fullscreen toggle
document.getElementById('fullscreen-btn').addEventListener('click', function() {
    var wrapper = document.getElementById('editor-wrapper');
    isFullscreen = !isFullscreen;
    if (isFullscreen) {
        wrapper.classList.add('monaco-fullscreen');
        this.innerHTML = '<i class="fas fa-compress"></i>';
        document.body.style.overflow = 'hidden';
    } else {
        wrapper.classList.remove('monaco-fullscreen');
        this.innerHTML = '<i class="fas fa-expand"></i>';
        document.body.style.overflow = '';
    }
    setTimeout(function() { if (editor) editor.layout(); }, 100);
});

// ESC to exit fullscreen
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && isFullscreen) {
        document.getElementById('fullscreen-btn').click();
    }
});
</script>
</body>
</html>
