<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$snippets = require __DIR__ . '/core/snippets.php';

$theme_name = $_GET['theme'] ?? '';
if (empty($theme_name) || !preg_match('/^[a-z0-9_]+$/', $theme_name)) {
    set_flash_message('Тема не выбрана или имя некорректно.', 'danger');
    header('Location: themes.php');
    exit;
}
$theme_dir = __DIR__ . '/../themes/' . $theme_name . '/';
if (!is_dir($theme_dir)) {
    set_flash_message("Тема '".h($theme_name)."' не найдена.", 'danger');
    header('Location: themes.php');
    exit;
}

$theme_files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, FilesystemIterator::SKIP_DOTS));
$allowed_extensions = ['html', 'css', 'js'];
foreach ($iterator as $file) {
    if ($file->isFile() && in_array(strtolower($file->getExtension()), $allowed_extensions)) {
        $theme_files[] = basename($file->getPathname());
    }
}
sort($theme_files);

$current_file_basename = $_GET['file'] ?? ($theme_files[0] ?? null);
$current_file_path_relative = '';
$file_content = "<!-- Выберите файл для редактирования -->";
if ($current_file_basename && in_array($current_file_basename, $theme_files)) {
    $current_file_path_relative = "themes/{$theme_name}/{$current_file_basename}";
    $absolute_path = realpath(__DIR__ . '/../' . $current_file_path_relative);
    if ($absolute_path && file_exists($absolute_path)) {
        $file_content = file_get_contents($absolute_path);
    }
} else {
    $current_file_basename = null;
}
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('templates') ?: 'Конструктор' ?>: <?= h($theme_name) ?> — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . "/core/tailwind_head.php"; ?>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=JetBrains+Mono:wght@400;500&family=Source+Code+Pro:wght@400;500&display=swap" rel="stylesheet">
    <style>
        #editor-container, #preview-frame { height: calc(100vh - 280px); }
        .monaco-fullscreen { position: fixed !important; inset: 0 !important; z-index: 99999 !important; background: #1e1e1e !important; overflow-y: auto !important; }
        .monaco-fullscreen #editor-container { height: calc(100vh - 60px) !important; }
        .monaco-fullscreen .editor-toolbar { position: sticky !important; top: 0 !important; z-index: 100000 !important; background: #fff !important; }
        /* Monaco color picker — make sure it's above all admin UI */
        .monaco-editor .colorpicker { z-index: 100000 !important; }
        .monaco-editor .colorpicker-widget { z-index: 100000 !important; }
        .monaco-editor .suggest-widget { z-index: 100000 !important; }
        .monaco-editor .editor-widget { z-index: 100000 !important; }
        .monaco-editor .monaco-hover { z-index: 100000 !important; }
        [x-cloak]{display:none!important}
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = 'Конструктор шаблона';
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('themes'),'url'=>'themes.php'],['label'=>h($theme_name)]];
        ob_start();
        ?>
        <a href="themes.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label"><?= t('back_to_list') ?></span></a>
        <span class="text-sm text-slate-500 self-center">Тема: <strong class="text-slate-700"><?= h($theme_name) ?></strong></span>
        <?php $action_panel = ob_get_clean();
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-4 flex-1 flex flex-col" x-data="{editor:'code'}">

        <!-- File selector (moved to TOP) -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-3 p-3">
            <div class="flex items-center gap-2 mb-2">
                <i class="fas fa-folder-open text-amber-500"></i>
                <h6 class="text-xs font-semibold text-slate-500 uppercase tracking-wide m-0">Файлы темы</h6>
            </div>
            <div class="flex flex-wrap gap-1">
                <?php foreach ($theme_files as $file): ?>
                    <a href="?theme=<?=h($theme_name)?>&file=<?= h($file) ?>" class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $file === $current_file_basename ? 'bg-blue-600 text-white' : 'text-slate-600 bg-slate-100 hover:bg-slate-200' ?>">
                        <i class="fas fa-file-code mr-1 text-xs"></i><?= h($file) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Editor toolbar -->
        <div class="editor-toolbar bg-white rounded-xl shadow-sm border border-slate-200 mb-3 p-2 flex flex-wrap items-center gap-2" id="editorToolbar">
            <div class="flex gap-1">
                <button id="undo-button" class="aicms-act-outline aicms-act-outline--secondary" <?=!$current_file_basename?'disabled':''?>><i class="fas fa-undo"></i><span class="btn-label"><?= t('undo_tip') ?></span></button>
                <button id="redo-button" class="aicms-act-outline aicms-act-outline--secondary" <?=!$current_file_basename?'disabled':''?>><i class="fas fa-redo"></i><span class="btn-label"><?= t('redo_tip') ?></span></button>
            </div>
            <div class="inline-flex items-center border border-slate-300 rounded-lg overflow-hidden">
                <label class="px-2 py-1.5 text-xs text-slate-500 bg-slate-50 border-r border-slate-300"><?= t('font') ?></label>
                <select id="fontFamilySelect" class="px-2 py-1.5 text-sm border-0 focus:ring-0 bg-white" <?=!$current_file_basename?'disabled':''?>>
                    <option value="'Fira Code', 'Consolas', monospace">Fira Code</option>
                    <option value="'JetBrains Mono', 'Consolas', monospace">JetBrains Mono</option>
                    <option value="'Source Code Pro', 'Consolas', monospace">Source Code Pro</option>
                    <option value="Consolas, 'Courier New', monospace">Consolas</option>
                    <option value="'Courier New', monospace">Courier New</option>
                    <option value="monospace">Monospace</option>
                </select>
            </div>
            <div class="inline-flex items-center border border-slate-300 rounded-lg overflow-hidden">
                <label class="px-2 py-1.5 text-xs text-slate-500 bg-slate-50 border-r border-slate-300"><?= t('font_size') ?></label>
                <select id="fontSizeSelect" class="px-2 py-1.5 text-sm border-0 focus:ring-0 bg-white" <?=!$current_file_basename?'disabled':''?>>
                    <option value="12">12px</option>
                    <option value="14" selected>14px</option>
                    <option value="16">16px</option>
                    <option value="18">18px</option>
                    <option value="20">20px</option>
                </select>
            </div>
            <!-- Tab switcher -->
            <div class="inline-flex rounded-lg overflow-hidden border border-slate-300">
                <button @click="editor='code'" :class="editor==='code'?'bg-blue-600 text-white':'bg-white text-slate-600 hover:bg-slate-100'" class="px-3 py-1.5 text-sm transition border-r border-slate-300" <?=!$current_file_basename?'disabled':''?>><i class="fas fa-code mr-1"></i><?= t('tab_editor') ?></button>
                <button @click="editor='preview'; if(canEdit) saveFile().then(refreshPreview)" :class="editor==='preview'?'bg-blue-600 text-white':'bg-white text-slate-600 hover:bg-slate-100'" class="px-3 py-1.5 text-sm transition" <?=!$current_file_basename?'disabled':''?>><i class="fas fa-eye mr-1"></i><?= t('tab_preview') ?></button>
            </div>
            <button id="fullscreen-btn" class="aicms-btn aicms-btn--ghost aicms-btn--sm ml-auto" <?=!$current_file_basename?'disabled':''?>><i class="fas fa-expand"></i><span class="btn-label">Полный экран</span></button>
        </div>

        <!-- Editor area — unified width, no side panel -->
        <div class="flex-1">
            <div class="bg-white rounded-lg shadow-sm border border-slate-200 h-full overflow-visible flex flex-col">
                <div class="border-b border-slate-200 px-4 py-2 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm">
                        <i class="fas fa-file-code text-slate-400"></i>
                        <span class="font-mono text-slate-700"><?= h($current_file_basename ?: '— не выбран —') ?></span>
                    </div>
                    <div class="text-xs text-slate-400">
                        <span x-show="editor==='code'"><i class="fas fa-code mr-1"></i><?= t('tab_editor') ?></span>
                        <span x-show="editor==='preview'" x-cloak><i class="fas fa-eye mr-1"></i><?= t('tab_preview') ?></span>
                    </div>
                </div>
                <div class="flex-1 p-0 relative">
                    <div x-show="editor==='code'" id="editor-pane" class="h-full">
                        <div id="editor-container"></div>
                    </div>
                    <div x-show="editor==='preview'" x-cloak id="preview-pane" class="h-full">
                        <iframe id="preview-frame" class="w-full h-full border-0" src="preview_template.php?theme=<?=h($theme_name)?>&file=<?=h($current_file_basename)?>"></iframe>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Floating Action Bar (FAB) — unified with edit_page/edit_news -->
<div class="aicms-fab-bar" id="fabBar" data-stretch-block>
    <div class="aicms-fab-actions">
        <button class="aicms-fab-btn aicms-fab-btn--save" onclick="saveFile()"><i class="fas fa-save"></i><span class="btn-label"><?= t('save') ?></span></button>
        <button class="aicms-fab-btn aicms-fab-btn--back" onclick="window.location.href='themes.php'"><i class="fas fa-arrow-left"></i><span class="btn-label"><?= t('back_to_list') ?></span></button>
    </div>
    <button class="aicms-fab-btn aicms-fab-btn--toggle" onclick="var bar=document.getElementById('fabBar');bar.classList.toggle('is-collapsed');"><i class="fas fa-ellipsis-v"></i><span class="btn-label">Действия</span></button>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs/loader.js"></script>
<script src="assets/js/code-editor-helpers.js"></script>
<script>
    let editor;
    let currentCsrfToken = "<?= csrf_field_value() ?>";
    const canEdit = <?= $current_file_basename ? 'true' : 'false' ?>;
    let isFullscreen = false;

    if (canEdit) {
        require.config({paths:{'vs':'https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs'}});
        require(['vs/editor/editor.main'],function(){
            const langMap = {html:'html', css:'css', js:'javascript'};
            const ext = '<?=pathinfo($current_file_basename,PATHINFO_EXTENSION)?>';
            editor = monaco.editor.create(document.getElementById('editor-container'), { value: <?=json_encode($file_content, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>, language: langMap[ext] || 'plaintext', theme:'vs-dark', automaticLayout:true, wordWrap:'on', fontFamily: "'Fira Code', 'Consolas', monospace", fontLigatures: true, fontSize: 14, fixedOverflowWidgets: true});

            if (typeof initializeEditorHelpers === 'function') {
                initializeEditorHelpers(editor);
            }

            document.getElementById('undo-button').addEventListener('click', () => editor.trigger('undo', 'undo'));
            document.getElementById('redo-button').addEventListener('click', () => editor.trigger('redo', 'redo'));
            editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => saveFile());
        });
    } else {
        document.getElementById('editor-container').innerHTML = '<div class="p-4 m-3 bg-blue-50 text-blue-700 rounded-lg text-sm"><i class="fas fa-info-circle mr-1"></i>Выберите файл, чтобы начать.</div>';
    }

    // Font controls — attached OUTSIDE require() so they work immediately
    document.getElementById('fontFamilySelect').addEventListener('change', function(e) {
        if (editor) editor.updateOptions({ fontFamily: e.target.value });
    });
    document.getElementById('fontSizeSelect').addEventListener('change', function(e) {
        if (editor) editor.updateOptions({ fontSize: parseInt(e.target.value, 10) });
    });

    function refreshPreview(){
        const iframe = document.getElementById('preview-frame');
        iframe.src = `preview_template.php?theme=<?=h($theme_name)?>&file=<?=h($current_file_basename)?>&t=${Date.now()}`;
    }

    async function saveFile(){
        if (!canEdit) return;
        const formData = new FormData();
        formData.append('file', '<?= h($current_file_path_relative) ?>');
        formData.append('content', editor.getValue());
        formData.append('csrf_token', currentCsrfToken);
        try {
            const response = await fetch('save_file.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                if (result.new_csrf_token) currentCsrfToken = result.new_csrf_token;
                if (window.AicmsToast) AicmsToast.show(result.message, 'success');
            } else { throw new Error(result.message || 'Ошибка'); }
        } catch (error) { if (window.AicmsToast) AicmsToast.show(error.message, 'error'); }
    }

    // Fullscreen toggle
    document.getElementById('fullscreen-btn').addEventListener('click', function() {
        if (!canEdit) return;
        var wrapper = document.querySelector('.p-4.flex-1');
        var toolbar = document.getElementById('editorToolbar');
        isFullscreen = !isFullscreen;
        if (isFullscreen) {
            // Move toolbar inside the editor pane for fullscreen
            var editorPane = document.getElementById('editor-pane');
            wrapper.classList.add('monaco-fullscreen');
            // Insert toolbar at the top of editor-pane
            editorPane.insertBefore(toolbar, editorPane.firstChild);
            toolbar.style.marginBottom = '0';
            toolbar.style.borderRadius = '0';
            this.innerHTML = '<i class="fas fa-compress"></i>';
            document.body.style.overflow = 'hidden';
        } else {
            // Move toolbar back to its original position
            var wrapper2 = document.querySelector('.p-4.flex-1');
            var fileSelector = wrapper2.querySelector('.bg-white.rounded-xl.shadow-sm.border');
            wrapper2.classList.remove('monaco-fullscreen');
            // Insert toolbar after file selector
            fileSelector.parentNode.insertBefore(toolbar, fileSelector.nextSibling);
            toolbar.style.marginBottom = '';
            toolbar.style.borderRadius = '';
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
