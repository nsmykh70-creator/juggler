<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$_ep_config = read_json(__DIR__ . '/../data/config.json', []);

$page = null;
$page_title = t('h_create_page_short');
$page_lang = ADMIN_LANG;

if (isset($_GET['id'])) {
    $id_to_edit = (int)$_GET['id'];
    $page_title = t('h_edit_page_short');
    foreach (SUPPORTED_LANGS as $lang_info) {
        $lang_code = $lang_info['code'];
        $pages_in_lang = read_content('pages.json', [], $lang_code);
        foreach ($pages_in_lang as $p) {
            if ($p['id'] === $id_to_edit) {
                $page = $p;
                $page_lang = $lang_code;
                break 2;
            }
        }
    }
}

// Load all pages for parent_id selector
$all_pages_for_select = read_content('pages.json', [], ADMIN_LANG);
if (empty($all_pages_for_select)) {
    foreach (SUPPORTED_LANGS as $li) {
        $all_pages_for_select = read_content('pages.json', [], $li['code']);
        if (!empty($all_pages_for_select)) break;
    }
}
// Load custom menu items for parent selector
$_ci_file = __DIR__ . '/../data/menu_items.json';
$_custom_menu_items = file_exists($_ci_file) ? (json_decode(@file_get_contents($_ci_file), true) ?: []) : [];

$available_themes = [];
$theme_dirs = array_filter(glob(__DIR__ . '/../themes/*'), 'is_dir');
foreach ($theme_dirs as $dir) {
    if (!str_starts_with(basename($dir), '_')) {
        $available_themes[] = basename($dir);
    }
}
$config = read_json(__DIR__ . '/../data/config.json');
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= h($page_title) ?> (<?= strtoupper($page_lang) ?>) — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .lumina-container { border: 1px solid #e2e8f0; border-radius: 0.5rem; }
        #content-editor { min-height: 450px; }
    </style>
</head>
<body class="bg-slate-100">
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $tp_title = ($page['title'] ?? 'New page');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('pages'),'url'=>'pages.php'],['label'=>h($tp_title)]];
        $action_panel = '<span class="px-2 py-0.5 text-xs font-semibold bg-slate-200 text-slate-700 rounded-full self-center">'.strtoupper($page_lang).'</span>';
        // Copy to other language dropdown
        $action_panel .= '<select id="copyLangSelect" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5" onchange="if(this.value)window.location.href=\'copy_page.php?id='.$page['id'].'&from_lang='.$page_lang.'&to_lang=\'+this.value">';
        $action_panel .= '<option value="">↪ Копировать на...</option>';
        foreach (SUPPORTED_LANGS as $li) {
            if ($li['code'] !== $page_lang) $action_panel .= '<option value="'.$li['code'].'">'.$li['name'].'</option>';
        }
        $action_panel .= '</select>';
        // Markdown export
        if (!empty($page['id'])) {
            $action_panel .= '<a href="markdown.php?action=export&id='.$page['id'].'&lang='.$page_lang.'" class="aicms-btn aicms-btn--ghost aicms-btn--sm" title="Экспорт в Markdown"><i class="fas fa-file-alt"></i> .md</a>';
        }
        $action_panel .= '<a href="pages.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <form action="save_page.php" method="POST">
            <input type="hidden" name="id" value="<?= $page['id'] ?? '' ?>">
            <input type="hidden" name="lang" value="<?= h($page_lang) ?>">
            <?= csrf_field() ?>

            <!-- Tabs with Alpine.js -->
            <div x-data="{tab:'content'}" class="bg-white rounded-xl shadow-sm overflow-clip">
                <!-- Tab buttons -->
                <div class="flex border-b border-slate-200">
                    <button type="button" @click="tab='content'" :class="tab==='content' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-5 py-3 text-sm font-medium border-b-2 transition-all">
                        <i class="fas fa-file-alt mr-1"></i> <?= t('tab_content') ?>
                    </button>
                    <button type="button" @click="tab='seo'" :class="tab==='seo' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-5 py-3 text-sm font-medium border-b-2 transition-all">
                        <i class="fas fa-search mr-1"></i> <?= t('tab_seo') ?>
                    </button>
                    <button type="button" @click="tab='custom'" :class="tab==='custom' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'" class="px-5 py-3 text-sm font-medium border-b-2 transition-all">
                        <i class="fas fa-sliders-h mr-1"></i> <?= t('tab_custom') ?>
                    </button>
                </div>

                <!-- Tab: Content -->
                <div x-show="tab==='content'" class="p-5">
                    <label class="block text-sm font-medium text-slate-700 mb-2"><?= t('content') ?></label>
                    <textarea id="content-editor" name="content" data-has-content="<?= !empty($page['content']) ? '1' : '0' ?>"><?= h($page['content'] ?? '') ?></textarea>
                </div>

                <!-- Tab: SEO -->
                <div x-show="tab==='seo'" x-cloak class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h5 class="text-base font-semibold text-slate-800 m-0"><?= t('seo_section') ?></h5>
                        <div class="flex gap-2">
                            <button type="button" class="px-3 py-1.5 text-sm border border-blue-300 text-blue-700 rounded-lg hover:bg-blue-50 transition-all" id="aiSeoBtn"><i class="fas fa-magic mr-1"></i>AI SEO</button>
                            <button type="button" class="px-3 py-1.5 text-sm border border-green-300 text-green-700 rounded-lg hover:bg-green-50 transition-all" id="aiTranslateBtn"><i class="fas fa-language mr-1"></i><?= t('ai_translate_btn') ?></button>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('title') ?></label>
                            <input type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" name="title" value="<?= h($page['title'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Slug</label>
                            <input type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" name="slug" value="<?= h($page['slug'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('custom_template_short') ?></label>
                            <select name="template" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                <option value=""><?= t('default_template') ?></option>
                                <?php foreach ($available_themes as $theme_name): ?>
                                <option value="<?= h($theme_name) ?>" <?= (isset($page['template']) && $page['template'] === $theme_name) ? 'selected' : '' ?>><?= h($theme_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('publish_status') ?></label>
                            <select name="status" id="page_status" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                <option value="published" <?= ($page['status'] ?? 'published') === 'published' ? 'selected' : '' ?>><?= t('published') ?></option>
                                <option value="draft" <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>><?= t('draft') ?></option>
                                <option value="scheduled" <?= ($page['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>><?= t('scheduled') ?></option>
                            </select>
                            <p class="text-xs text-slate-400 mt-1"><?= t('publish_help') ?></p>
                        </div>
                    </div>
                    <div id="publish_at_row" style="display:none;" class="mb-4">
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('publish_datetime') ?></label>
                        <input type="datetime-local" name="publish_at" class="w-full max-w-xs px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= isset($page['publish_at']) ? h(date('Y-m-d\TH:i', strtotime($page['publish_at']))) : '' ?>">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Родительский пункт меню</label>
                            <select name="parent_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                <option value="0">— Верхний уровень —</option>
                                <optgroup label="Страницы">
                                <?php foreach ($all_pages_for_select as $pp): ?>
                                    <?php if ($pp['id'] != ($page['id'] ?? 0)): ?>
                                    <option value="<?= (int)$pp['id'] ?>" <?= ((string)($page['parent_id'] ?? 0) === (string)$pp['id']) ? 'selected' : '' ?>><?= h($pp['title']) ?> (<?= h($pp['slug']) ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($_custom_menu_items)): ?>
                                <optgroup label="Пункты меню">
                                <?php foreach ($_custom_menu_items as $ci): ?>
                                <option value="custom_<?= (int)$ci['id'] ?>" <?= (($page['parent_id'] ?? '') === 'custom_' . $ci['id']) ? 'selected' : '' ?>>🔗 <?= h($ci['title']) ?></option>
                                <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Выберите родительский пункт для создания выпадающего подменю</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Показывать в меню</label>
                            <select name="show_in_menu" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                <option value="1" <?= !isset($page['show_in_menu']) || $page['show_in_menu'] ? 'selected' : '' ?>>Да, показывать в меню</option>
                                <option value="0" <?= isset($page['show_in_menu']) && !$page['show_in_menu'] ? 'selected' : '' ?>>Нет, скрыть из меню</option>
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Скрытая страница остаётся доступной по URL</p>
                        </div>
                    </div>
                    <hr class="border-slate-200 my-4">
                    <h6 class="text-sm font-semibold text-slate-600 mb-3"><?= t('seo_meta_tags') ?></h6>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Meta Title</label>
                        <input type="text" name="meta_title" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" value="<?= h($page['meta_title'] ?? '') ?>" placeholder="<?= t('meta_title_ph') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Meta Description</label>
                        <textarea name="meta_description" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" placeholder="<?= t('meta_description_ph') ?>"><?= h($page['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Tab: Custom fields -->
                <div x-show="tab==='custom'" x-cloak class="p-5">
                    <p class="text-sm text-slate-400 mb-3"><?= t('custom_fields_hint') ?></p>
                    <div id="custom-fields-container"></div>
                    <button type="button" class="aicms-act aicms-act--secondary mt-2" id="add-field-btn" title="<?= t('add_field_tip') ?>">
                        <i class="fa fa-plus"></i>
                    </button>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="mt-4 flex justify-end gap-2 legacy-action-footer">
                <a href="pages.php" class="aicms-act aicms-act--secondary aicms-act-lg" title="<?= t('back_to_list') ?>"><i class="fas fa-arrow-left"></i></a>
                <?php if (isset($page['id'])): ?>
                <a href="page_history.php?id=<?= $page['id'] ?>" class="aicms-act aicms-act--warning aicms-act-lg" title="<?= t('history_btn') ?>"><i class="fas fa-history"></i></a>
                <?php endif; ?>
                <?php if (!empty($_ep_config['features']['ai_translation'])): ?>
                <button type="button" id="aiTranslateBtn" class="aicms-icon-btn aicms-icon-btn--info"><i class="fas fa-language"></i><span class="btn-label"><?= t('translate_btn') ?></span></button>
                <?php endif; ?>
                <button type="submit" name="save_content" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-save"></i><span class="btn-label"><?= t('save_all') ?></span></button>
            </div>
        </form>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="assets/js/autosave.js?v=20260801"></script>
<script>
// Show/hide publish_at based on status
(function() {
    var s = document.getElementById('page_status');
    var r = document.getElementById('publish_at_row');
    if (s && r) { s.addEventListener('change', function(){ r.style.display = s.value === 'scheduled' ? 'block' : 'none'; }); r.style.display = s.value === 'scheduled' ? 'block' : 'none'; }
})();
// AI SEO
(function() {
    var b = document.getElementById('aiSeoBtn'); if (!b) return;
    b.addEventListener('click', async function() {
        var c = document.getElementById('content-editor').value || '';
        var t = document.querySelector('input[name="title"]')?.value || '';
        if (!c) { alert('Content is empty.'); return; }
        b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>...';
        try { var fd = new FormData(); fd.append('action','seo'); fd.append('content',c); fd.append('title',t); fd.append('csrf_token',window._luminaCsrfToken||'');
        var r = await fetch('api_generate_content.php',{method:'POST',body:fd}); var d = await r.json();
        if (d.status==='success'&&d.parsed&&d.content) { if(d.content.meta_title) document.querySelector('input[name="meta_title"]').value=d.content.meta_title; if(d.content.meta_description) document.querySelector('textarea[name="meta_description"]').value=d.content.meta_description; alert('SEO ready!'); }
        else { alert('Format error.'); } } catch(e) { alert('Error: '+e.message); }
        b.disabled = false; b.innerHTML = '<i class="fas fa-magic mr-1"></i>AI SEO';
    });
})();
// AI Translate
(function() {
    var b = document.getElementById('aiTranslateBtn'); if (!b) return;
    b.addEventListener('click', async function() {
        var langs = <?= json_encode(SUPPORTED_LANGS) ?>; var cl = '<?= h($page_lang) ?>';
        var tl = langs.filter(function(l){return l.code!==cl;});
        if (!tl.length) { alert('No other languages.'); return; }
        var target = prompt('Which language?\nAvailable: '+tl.map(function(l){return l.code+' ('+l.name+')';}).join(', ')+'\n\nCode:', tl[0].code);
        if (!target) return;
        var ti = tl.find(function(l){return l.code===target;}); if(!ti){alert('Language not found.');return;}
        b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>...';
        try { var fd = new FormData(); fd.append('action','translate'); fd.append('content',document.getElementById('content-editor').value||''); fd.append('title',document.querySelector('input[name="title"]')?.value||''); fd.append('target_lang',target); fd.append('target_lang_name',ti.name); fd.append('csrf_token',window._luminaCsrfToken||'');
        var r = await fetch('api_generate_content.php',{method:'POST',body:fd}); var d = await r.json();
        if (d.status==='success'&&d.content) { if(confirm('Translation ready! Title: "'+d.title+'". Open new page?')) { sessionStorage.setItem('ai_translate_title',d.title); sessionStorage.setItem('ai_translate_content',d.content); sessionStorage.setItem('ai_translate_target_lang',target); window.location.href='edit_page.php?ai_translate=1'; } }
        else { alert('Error: '+(d.message||'')); } } catch(e) { alert('Error: '+e.message); }
        b.disabled = false; b.innerHTML = '<i class="fas fa-language mr-1"></i><?= t('ai_translate_btn') ?>';
    });
})();
</script>
<?php require_once __DIR__ . '/lumina_integration.php'; ?>
<script type="module">
    import LuminaEditor from './assets/lumina/editor.js?v=20260901';
    const config = window._luminaEditorConfig || {};
    const editor = new LuminaEditor({
        selector: '#content-editor',
        placeholder: <?= json_encode(t('start_writing')) ?>,
        stickyToolbar: config.stickyToolbar || false,
        uploadUrl: config.uploadUrl || 'lumina_upload_image.php',
        cmsAiUrl: config.cmsAiUrl || 'api_generate_content.php',
        height: '450px',
        modules: config.modules || {},
        buttons: config.buttons || {},
        bubbleMenus: config.bubbleMenus || {},
        onChange: (html) => {
            // Convert admin-relative paths back to site-relative for storage
            html = html.replace(/\.\.\/uploads\//g, 'uploads/');
            document.getElementById('content-editor').value = html;
        }
    });
    // Make editor accessible to autosave.js for content restoration
    window._luminaEditorInstance = editor;
    // Initialize auto-save drafts
    document.addEventListener('DOMContentLoaded', function() {
        if (window.initAutoSave) {
            window.initAutoSave({
                formSelector: 'form[action="save_page.php"]',
                interval: 30,
                namespace: 'page_<?= (int)($page['id'] ?? 0) ?: 'new' ?>',
                luminaField: '#content-editor'
            });
        }
    });
</script>
<?php if (!empty($_ep_config['features']['ai_translation'])): ?>
<script>
document.getElementById('aiTranslateBtn')?.addEventListener('click', function() {
    var target = prompt('Translate to which language? (en, de, fr, es, zh, ...)', 'en');
    if (!target) return;
    var editor = document.getElementById('content-editor');
    var titleInput = document.querySelector('input[name="title"]');
    var text = (titleInput ? titleInput.value + '\n\n' : '') + (editor ? editor.value : '');
    if (!text || text.length < 5) { alert('No content to translate'); return; }
    var btn = this;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Translating...';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('text', text);
    fd.append('target_lang', target);
    fd.append('source_lang', '<?= ADMIN_LANG ?>');
    fd.append('csrf_token', '<?= csrf_field_value() ?>');
    fetch('api_translate.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = '<i class="fas fa-language"></i> <?= t('translate_btn') ?>';
        btn.disabled = false;
        if (data.error) { alert('Error: ' + data.error); return; }
        if (data.translated && editor) { editor.value = data.translated; if (editor.dispatchEvent) { editor.dispatchEvent(new Event('input')); } alert('Translated to ' + target + '. Review and save.'); }
    })
    .catch(e => { btn.innerHTML = '<i class="fas fa-language"></i> <?= t('translate_btn') ?>'; btn.disabled = false; alert('Error: ' + e.message); });
});
</script>
<?php endif; ?>

<!-- Custom Fields logic -->
<script>
(function() {
    var container = document.getElementById('custom-fields-container');
    var addBtn = document.getElementById('add-field-btn');
    if (!container || !addBtn) return;

    // Existing custom fields from PHP (passed as JSON)
    var existingFields = <?= json_encode($page['custom'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

    function createFieldRow(key, value) {
        var row = document.createElement('div');
        row.className = 'flex gap-2 mb-2 items-center';
        row.innerHTML =
            '<input type="text" name="custom_fields[key][]" value="' + escapeAttr(key || '') + '" placeholder="' + <?= json_encode(t('field_key_ph')) ?> + '" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">' +
            '<input type="text" name="custom_fields[value][]" value="' + escapeAttr(value || '') + '" placeholder="' + <?= json_encode(t('field_value_ph')) ?> + '" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-blue-500">' +
            '<button type="button" class="aicms-act-outline aicms-act-outline--danger" onclick="this.parentElement.remove()"><i class="fas fa-times"></i><span class="btn-label">' + <?= json_encode(t('remove_field')) ?> + '</span></button>';
        return row;
    }

    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Populate existing fields
    if (existingFields && typeof existingFields === 'object') {
        var keys = Object.keys(existingFields);
        if (keys.length > 0) {
            keys.forEach(function(k) {
                container.appendChild(createFieldRow(k, existingFields[k]));
            });
        } else {
            // Add one empty row by default
            container.appendChild(createFieldRow('', ''));
        }
    } else {
        container.appendChild(createFieldRow('', ''));
    }

    // Add button → new empty row
    addBtn.addEventListener('click', function() {
        container.appendChild(createFieldRow('', ''));
        // Focus the key input of the new row
        var inputs = container.querySelectorAll('input[name="custom_fields[key][]"]');
        if (inputs.length > 0) inputs[inputs.length - 1].focus();
    });
})();
</script>

<style>[x-cloak]{display:none!important}</style>
</body>
</html>
