<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$themes_dir = __DIR__ . '/../themes/';
$all_themes = array_filter(glob($themes_dir . '*'), 'is_dir');
$config = read_json(__DIR__ . '/../data/config.json');
$active_theme = $config['theme'] ?? 'helios';
$page_title = t('themes_management');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('themes')]];
ob_start();
?>
<button class="aicms-icon-btn aicms-icon-btn--primary" onclick="window.dispatchEvent(new CustomEvent('open-generate-theme'))"><i class="fas fa-magic"></i><span class="btn-label"><?= t('generate') ?></span></button>
<button class="aicms-icon-btn aicms-icon-btn--primary" onclick="window.dispatchEvent(new CustomEvent('open-create-theme'))"><i class="fas fa-plus"></i><span class="btn-label"><?= t('create_empty') ?></span></button>
<span class="ml-auto text-sm text-slate-500 self-center"><?= t('themes_count') ?> <strong class="text-slate-700"><?= count(array_filter($all_themes, fn($p) => !str_starts_with(basename($p), '_'))) ?></strong></span>
<?php $action_panel = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_themes') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        /* Theme preview thumbnail — full page screenshot style */
        .theme-preview-thumb {
            width: 100%;
            aspect-ratio: 16/10;
            overflow: hidden;
            position: relative;
            background: #f1f5f9;
            cursor: pointer;
            border-bottom: 1px solid #e2e8f0;
        }
        .theme-preview-thumb iframe {
            width: 1280px;
            /* Height set dynamically to match full page content */
            border: none;
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: top left;
            pointer-events: none;
        }
        .theme-preview-thumb iframe::-webkit-scrollbar { display: none; }
        .theme-preview-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .theme-preview-thumb:hover .theme-preview-overlay { opacity: 1; }
        .theme-preview-overlay button {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .theme-preview-loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 13px;
        }
        .theme-preview-loading .spin {
            width: 24px; height: 24px;
            border: 3px solid #e2e8f0;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: theme-spin 0.8s linear infinite;
            margin-right: 8px;
        }
        @keyframes theme-spin { to { transform: rotate(360deg); } }

        /* Full preview modal */
        .theme-preview-modal {
            position: fixed; inset: 0; z-index: 100001;
            display: none; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.7);
        }
        .theme-preview-modal.show { display: flex; }
        .theme-preview-modal-box {
            background: #fff; border-radius: 12px; overflow: hidden;
            max-width: 95vw; max-height: 95vh;
            display: flex; flex-direction: column;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
        .theme-preview-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 20px; border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .theme-preview-modal-header h4 { margin:0; font-size:16px; font-weight:600; color:#1e293b; }
        .theme-preview-modal-header .controls { display: flex; gap: 8px; align-items: center; }
        .theme-preview-modal-header .controls button {
            padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
            background: #fff; color: #475569; cursor: pointer; font-size: 12px; font-weight: 600;
            display: flex; align-items: center; gap: 4px;
        }
        .theme-preview-modal-header .controls button.active {
            background: #2563eb; color: #fff; border-color: #2563eb;
        }
        .theme-preview-modal-body {
            flex: 1; overflow: hidden; background: #e2e8f0; display: flex;
            justify-content: center; align-items: flex-start; padding: 20px;
        }
        .theme-preview-modal-body iframe {
            border: none; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            transition: width 0.3s, height 0.3s;
        }
    </style>
</head>
<body class="bg-slate-100" x-data="{createModal:false, generateModal:false}" @open-create-theme.window="createModal=true" @open-generate-theme.window="generateModal=true">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($all_themes as $path): $name = basename($path); if (str_starts_with($name, '_')) continue; ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden hover:shadow-md transition-shadow">
                <!-- Live preview thumbnail -->
                <div class="theme-preview-thumb" onclick="openThemePreview('<?= h($name) ?>')">
                    <div class="theme-preview-loading" id="loading-<?= h($name) ?>">
                        <div class="spin"></div> Загрузка превью...
                    </div>
                    <iframe
                        src="about:blank"
                        data-src="../?theme_preview=<?= h($name) ?>"
                        onload="document.getElementById('loading-<?= h($name) ?>').style.display='none'"
                        sandbox="allow-same-origin"
                        loading="lazy"
                        id="thumb-<?= h($name) ?>"
                    ></iframe>
                    <div class="theme-preview-overlay">
                        <button onclick="event.stopPropagation(); openThemePreview('<?= h($name) ?>')">
                            <i class="fas fa-expand"></i> Просмотр
                        </button>
                    </div>
                </div>
                <!-- Theme info -->
                <div class="px-4 py-3 font-bold text-slate-800 flex justify-between items-center">
                    <?= h($name) ?> <?php if ($name === $active_theme): ?><span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-800 rounded-full"><i class="fas fa-circle text-[6px]"></i> <?= t('active_theme') ?></span><?php endif; ?>
                </div>
                <div class="px-4 pb-3"><p class="text-xs text-slate-400 m-0 font-mono">themes/<?= h($name) ?></p></div>
                <div class="px-4 py-3 border-t border-slate-100 flex justify-between items-center">
                    <?php if ($name !== $active_theme): ?>
                    <form action="activate_theme.php" method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="theme_name" value="<?= h($name) ?>"><button class="aicms-act-outline aicms-act-outline--success" data-stretch-independent><i class="fas fa-check"></i><span class="btn-label"><?= t('activate_tip') ?></span></button></form>
                    <?php else: ?><span></span><?php endif; ?>
                    <div class="flex gap-1.5">
                        <a href="../?theme_preview=<?= h($name) ?>" target="_blank" class="aicms-act-outline aicms-act-outline--info" ><i class="fas fa-external-link-alt"></i><span class="btn-label">Открыть на сайте</span></a>
                        <a href="templates.php?theme=<?= h($name) ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fas fa-code"></i><span class="btn-label"><?= t('editor_tip') ?></span></a>
                        <?php if ($name !== $active_theme): ?>
                        <form action="delete_theme.php" method="post" class="inline" onsubmit="return confirm('<?= t('confirm_delete_theme') ?>');"><?= csrf_field() ?><input type="hidden" name="theme_name" value="<?= h($name) ?>"><button class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete_tip') ?></span></button></form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
</div>

<!-- Full Preview Modal -->
<div class="theme-preview-modal" id="themePreviewModal" onclick="if(event.target===this)closeThemePreview()">
    <div class="theme-preview-modal-box" style="width:90vw;height:90vh;">
        <div class="theme-preview-modal-header">
            <h4 id="themePreviewTitle">Предпросмотр темы</h4>
            <div class="controls">
                <button id="tpDesktop" class="active" onclick="setPreviewDevice('desktop')"><i class="fas fa-desktop"></i> Desktop</button>
                <button id="tpTablet" onclick="setPreviewDevice('tablet')"><i class="fas fa-tablet-alt"></i> Tablet</button>
                <button id="tpMobile" onclick="setPreviewDevice('mobile')"><i class="fas fa-mobile-alt"></i> Mobile</button>
                <a id="tpOpenSite" href="#" target="_blank" class="aicms-icon-btn aicms-icon-btn--ghost" style="text-decoration:none;"><i class="fas fa-external-link-alt"></i><span class="btn-label">Открыть</span></a>
                <button onclick="closeThemePreview()" class="aicms-btn aicms-btn--danger aicms-btn--sm"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="theme-preview-modal-body" id="themePreviewBody">
            <iframe id="themePreviewIframe" style="width:100%;height:100%;"></iframe>
        </div>
    </div>
</div>

<!-- Create Theme Modal -->
<div id="createThemeModal" x-show="createModal" x-cloak @keydown.escape.window="createModal=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" @click.outside="createModal=false">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center"><h5 class="font-semibold text-slate-800 m-0"><?= t('create_empty_modal') ?></h5><button @click="createModal=false" class="text-slate-400 text-xl">&times;</button></div>
        <form action="create_theme.php" method="post" class="p-5"><?= csrf_field() ?>
            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('theme_name_label') ?></label><input type="text" name="theme_name" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" required pattern="[a-z0-9_]+">
            <div class="mt-4 text-right"><button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-check"></i><span class="btn-label"><?= t('create_btn') ?></span></button></div>
        </form>
    </div>
</div>

<!-- Generate Theme Modal -->
<div id="generateThemeModal" x-show="generateModal" x-cloak @keydown.escape.window="generateModal=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto" @click.outside="generateModal=false">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center"><h5 class="font-semibold text-slate-800 m-0"><i class="fas fa-magic mr-1"></i><?= t('theme_constructor') ?></h5><button @click="generateModal=false" class="text-slate-400 text-xl">&times;</button></div>
        <form action="generate_theme.php" method="post" class="p-5"><?= csrf_field() ?>
            <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1"><?= t('name_label') ?></label><input type="text" name="theme_name" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none" required pattern="[a-z0-9_]+"></div>
            <hr class="border-slate-200 my-3">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div><h6 class="text-sm font-semibold text-slate-600 mb-2"><i class="fas fa-columns mr-1"></i><?= t('layout') ?></h6>
                    <div class="space-y-1"><label class="flex items-center text-sm"><input type="radio" name="layout" value="single_column" checked class="mr-2"> <?= t('layout_single') ?></label><label class="flex items-center text-sm"><input type="radio" name="layout" value="sidebar_right" class="mr-2"> <?= t('layout_sidebar') ?></label><label class="flex items-center text-sm"><input type="radio" name="layout" value="hero_centered" class="mr-2"> <?= t('layout_hero') ?></label></div>
                </div>
                <div><h6 class="text-sm font-semibold text-slate-600 mb-2"><i class="fas fa-palette mr-1"></i><?= t('colors') ?></h6>
                    <div class="space-y-1"><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_light" checked class="mr-2"> ☀️ <?= t('scheme_light') ?></label><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_dark" class="mr-2"> 🌙 <?= t('scheme_dark') ?></label><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_ocean" class="mr-2"> 🌊 <?= t('scheme_ocean') ?></label><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_forest" class="mr-2"> 🌲 <?= t('scheme_forest') ?></label><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_sunset" class="mr-2"> 🌅 <?= t('scheme_sunset') ?></label><label class="flex items-center text-sm"><input type="radio" name="color" value="scheme_royal" class="mr-2"> 👑 <?= t('scheme_royal') ?></label></div>
                </div>
                <div><h6 class="text-sm font-semibold text-slate-600 mb-2"><i class="fas fa-font mr-1"></i><?= t('fonts') ?></h6>
                    <div class="space-y-1"><label class="flex items-center text-sm"><input type="radio" name="typo" value="fonts_roboto" checked class="mr-2"> Roboto</label><label class="flex items-center text-sm"><input type="radio" name="typo" value="fonts_montserrat" class="mr-2"> Montserrat</label><label class="flex items-center text-sm"><input type="radio" name="typo" value="fonts_inter" class="mr-2"> Inter</label><label class="flex items-center text-sm"><input type="radio" name="typo" value="fonts_playfair" class="mr-2"> Playfair</label><label class="flex items-center text-sm"><input type="radio" name="typo" value="fonts_lora" class="mr-2"> Lora</label></div>
                </div>
            </div>
            <div class="mt-4 text-right"><button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-magic"></i><span class="btn-label"><?= t('generate') ?></span></button></div>
        </form>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
// === Theme preview modal ===
var currentPreviewTheme = '';
var currentPreviewDevice = 'desktop';

function openThemePreview(themeName) {
    currentPreviewTheme = themeName;
    document.getElementById('themePreviewTitle').textContent = 'Предпросмотр: ' + themeName;
    document.getElementById('themePreviewIframe').src = '../?theme_preview=' + themeName;
    document.getElementById('tpOpenSite').href = '../?theme_preview=' + themeName;
    document.getElementById('themePreviewModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    setPreviewDevice('desktop');
}

function closeThemePreview() {
    document.getElementById('themePreviewModal').classList.remove('show');
    document.getElementById('themePreviewIframe').src = '';
    document.body.style.overflow = '';
}

function setPreviewDevice(device) {
    currentPreviewDevice = device;
    var iframe = document.getElementById('themePreviewIframe');
    var body = document.getElementById('themePreviewBody');
    // Update active button
    ['tpDesktop', 'tpTablet', 'tpMobile'].forEach(function(id) {
        document.getElementById(id).classList.toggle('active', id === 'tp' + device.charAt(0).toUpperCase() + device.slice(1));
    });
    // Set iframe size
    if (device === 'desktop') {
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        body.style.justifyContent = 'center';
    } else if (device === 'tablet') {
        iframe.style.width = '768px';
        iframe.style.height = '1024px';
        body.style.justifyContent = 'center';
    } else if (device === 'mobile') {
        iframe.style.width = '375px';
        iframe.style.height = '812px';
        body.style.justifyContent = 'center';
    }
}

// ESC to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('themePreviewModal').classList.contains('show')) {
        closeThemePreview();
    }
});

// Lazy load iframes — only load visible ones, stagger loading
window.addEventListener('load', function() {
    function scaleThumb(iframe) {
        var container = iframe.parentElement;
        var containerWidth = container.offsetWidth;
        
        var pageHeight = 800;
        try {
            pageHeight = iframe.contentWindow.document.body.scrollHeight;
            if (!pageHeight || pageHeight < 100) pageHeight = 800;
        } catch(e) {}
        
        iframe.style.width = '1280px';
        iframe.style.height = pageHeight + 'px';
        var scale = containerWidth / 1280;
        iframe.style.transform = 'scale(' + scale + ')';
        iframe.style.left = '0';
        iframe.style.top = '0';
    }
    
    // Lazy load: set src from data-src when iframe is near viewport
    var iframes = document.querySelectorAll('.theme-preview-thumb iframe[data-src]');
    var loadedCount = 0;
    
    function loadIframe(iframe) {
        if (iframe.src === 'about:blank' || iframe.getAttribute('src') === 'about:blank') {
            iframe.src = iframe.dataset.src;
        }
    }
    
    // Load first 6 iframes immediately, rest on scroll
    iframes.forEach(function(iframe, i) {
        if (i < 6) {
            setTimeout(function() { loadIframe(iframe); }, i * 300);
        }
    });
    
    // Load remaining on scroll
    var scrollTimer;
    window.addEventListener('scroll', function() {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function() {
            iframes.forEach(function(iframe) {
                if (iframe.src === 'about:blank' || iframe.getAttribute('src') === 'about:blank') {
                    var rect = iframe.parentElement.getBoundingClientRect();
                    if (rect.top < window.innerHeight + 200 && rect.bottom > -200) {
                        loadIframe(iframe);
                    }
                }
            });
        }, 150);
    });
    
    // Scale after each iframe loads
    document.querySelectorAll('.theme-preview-thumb iframe').forEach(function(iframe) {
        iframe.addEventListener('load', function() {
            if (iframe.src !== 'about:blank') {
                setTimeout(function() { scaleThumb(iframe); }, 200);
            }
        });
    });
    
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            document.querySelectorAll('.theme-preview-thumb iframe').forEach(function(iframe) {
                if (iframe.src !== 'about:blank') scaleThumb(iframe);
            });
        }, 200);
    });
});
</script>
</body>
</html>
