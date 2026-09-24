<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

$news_item = null;
$page_title = t('h_add_news_short');
$news_lang = ADMIN_LANG;

if (isset($_GET['id'])) {
    $id_to_edit = (int)$_GET['id'];
    $page_title = t('h_edit_news_short');
    foreach (SUPPORTED_LANGS as $lang_info) {
        $lang_code = $lang_info['code'];
        $news_in_lang = read_content('news.json', [], $lang_code);
        foreach ($news_in_lang as $item) {
            if ($item['id'] === $id_to_edit) {
                $news_item = $item;
                $news_lang = $lang_code;
                break 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= h($page_title) ?> (<?= strtoupper($news_lang) ?>) — AICMS</title>
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .lumina-container { border: 1px solid #e2e8f0; border-radius: 0.5rem; }
        #content-editor { min-height: 350px; }

        /* AICMS toggle switch — explicit colours for on/off state.
           Works in both light and dark themes (uses !important to override
           the global dark-theme .bg-slate-200 rule). */
        .aicms-toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .aicms-toggle input {
            position: absolute; opacity: 0; width: 0; height: 0; margin: 0;
        }
        .aicms-toggle .aicms-toggle-track {
            position: absolute; inset: 0;
            background-color: #cbd5e1 !important; /* slate-300 — OFF */
            border-radius: 9999px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.08);
        }
        .aicms-toggle .aicms-toggle-track::before {
            content: '';
            position: absolute;
            top: 2px; left: 2px;
            width: 20px; height: 20px;
            background: #fff;
            border-radius: 9999px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
            transition: transform 0.2s ease;
        }
        /* ON state — blue track + knob slides right */
        .aicms-toggle input:checked + .aicms-toggle-track {
            background-color: #2563eb !important; /* blue-600 — ON */
        }
        .aicms-toggle input:checked + .aicms-toggle-track::before {
            transform: translateX(20px);
        }
        /* Focus ring for accessibility */
        .aicms-toggle input:focus-visible + .aicms-toggle-track {
            box-shadow: 0 0 0 3px rgba(37,99,235,0.35), inset 0 1px 2px rgba(0,0,0,0.08);
        }
        /* Dark theme — keep OFF state distinguishable (slate-600, not pure grey) */
        body.dark-theme .aicms-toggle .aicms-toggle-track {
            background-color: #475569 !important; /* slate-600 — OFF in dark */
        }
        body.dark-theme .aicms-toggle input:checked + .aicms-toggle-track {
            background-color: #2563eb !important; /* blue-600 — ON in dark */
        }
    </style>
</head>
<body class="bg-slate-100">
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('news'),'url'=>'news.php'],['label'=>h($page_title)]];
        $action_panel = '<span class="px-2 py-0.5 text-xs font-semibold bg-slate-200 text-slate-700 rounded-full self-center">'.strtoupper($news_lang).'</span>';
        if (!empty($news_item['id'])) {
            $action_panel .= '<a href="news_history.php?id='.$news_item['id'].'" class="aicms-btn aicms-btn--ghost aicms-btn--sm" title="'.t('history_label').'"><i class="fas fa-history"></i></a>';
        }
        $action_panel .= '<a href="news.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <form action="save_news.php" method="POST">
            <input type="hidden" name="id" value="<?= $news_item['id'] ?? '' ?>">
            <input type="hidden" name="lang" value="<?= h($news_lang) ?>">
            <?= csrf_field() ?>

            <div class="bg-white rounded-xl shadow-sm p-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('title') ?></label>
                        <input type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" name="title" value="<?= h($news_item['title'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('date') ?></label>
                        <input type="date" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" name="date" value="<?= h($news_item['date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('category') ?></label>
                        <select name="category" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                            <option value=""><?= t('no_category') ?></option>
                            <?php
                            // Load news categories from DB if available
                            require_once __DIR__ . '/core/db.php';
                            $news_cats = [];
                            if (db_available()) {
                                try { $news_cats = db_select('news_categories', '*', 'lang = ? OR lang = ?', [ADMIN_LANG, 'ru'], 'position ASC, title ASC'); } catch (Exception $e) {}
                            }
                            foreach ($news_cats as $nc):
                            ?>
                            <option value="<?= h($nc['slug']) ?>" <?= ($news_item['category'] ?? '')===$nc['slug']?'selected':'' ?>><?= h($nc['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('tags') ?> <span class="text-xs text-slate-400"><?= t('tags_hint') ?></span></label>
                        <input type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" name="tags" value="<?= h($news_item['tags'] ?? '') ?>" placeholder="<?= t('tags_ph') ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <div class="flex items-center gap-3 mb-2">
                        <label class="aicms-toggle" for="news_published">
                            <input type="checkbox" name="published" id="news_published" value="1" <?= (!isset($news_item) || !isset($news_item['published']) || $news_item['published'] === true) ? 'checked' : '' ?> aria-label="<?= t('show_on_site_aria') ?>">
                            <span class="aicms-toggle-track"></span>
                        </label>
                        <label for="news_published" class="text-sm font-medium text-slate-700 cursor-pointer select-none"><?= t('show_on_site') ?></label>
                    </div>
                    <p class="text-xs text-slate-400"><?= t('show_on_site_hint') ?></p>
                </div>

                <!-- Scheduled publication -->
                <div class="mb-4 p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-clock text-slate-400 text-sm"></i>
                        <span class="text-sm font-medium text-slate-700"><?= t('scheduled_publish') ?></span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <select name="status" class="px-3 py-2 border border-slate-300 rounded-lg outline-none text-sm">
                            <option value="published" <?= ($news_item['status'] ?? 'published')==='published'?'selected':'' ?>><?= t('status_published') ?></option>
                            <option value="draft" <?= ($news_item['status'] ?? '')==='draft'?'selected':'' ?>><?= t('status_draft') ?></option>
                            <option value="scheduled" <?= ($news_item['status'] ?? '')==='scheduled'?'selected':'' ?>><?= t('status_scheduled') ?></option>
                        </select>
                        <input type="datetime-local" name="publish_at" value="<?= h($news_item['publish_at'] ?? '') ?>" class="px-3 py-2 border border-slate-300 rounded-lg outline-none text-sm" placeholder="<?= t('publish_at_ph') ?>">
                    </div>
                    <p class="text-xs text-slate-400 mt-1"><?= t('scheduled_help') ?></p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-2"><?= t('content') ?></label>
                    <textarea id="content-editor" name="content" data-has-content="<?= !empty($news_item['content']) ? '1' : '0' ?>"><?= h($news_item['content'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 flex justify-end gap-2 legacy-action-footer">
                <button type="submit" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-save"></i><span class="btn-label"><?= t('save_news') ?></span></button>
            </div>
        </form>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="assets/js/autosave.js?v=20260801"></script>
<?php require_once __DIR__ . '/lumina_integration.php'; ?>
<script type="module">
    import LuminaEditor from './assets/lumina/editor.js?v=20260901';
    const config = window._luminaEditorConfig || {};
    const editor = new LuminaEditor({
        selector: '#content-editor',
        placeholder: '<?= t('news_editor_ph') ?>',
        stickyToolbar: config.stickyToolbar || false,
        uploadUrl: config.uploadUrl || 'lumina_upload_image.php',
        cmsAiUrl: config.cmsAiUrl || 'api_generate_content.php',
        height: '350px',
        modules: config.modules || {},
        buttons: config.buttons || {},
        bubbleMenus: config.bubbleMenus || {},
        onChange: (html) => {
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
                formSelector: 'form[action="save_news.php"]',
                interval: 30,
                namespace: 'news_<?= (int)($news_item['id'] ?? 0) ?: 'new' ?>_<?= h($news_lang) ?>',
                luminaField: '#content-editor'
            });
        }
    });
</script>
</body>
</html>
