<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

$page_title = t('h_filemanager');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('files')]];
$action_panel_collapsed = false;
$action_panel = '';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_filemanager') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        /* === Panel layout === */
        .fm-wrapper { display:flex; gap:2px; height:calc(100vh - 200px); min-height:400px; }
        .fm-wrapper.fm-single .fm-panel { flex:1; }
        .fm-wrapper.fm-single #fmPanel2 { display:none; }
        .fm-wrapper.fm-dual .fm-panel { flex:1; min-width:0; }
        .fm-panel { display:flex; flex-direction:column; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#fff; }
        .fm-panel.active { border-color:#2563eb; box-shadow:0 0 0 2px rgba(37,99,235,.2); }
        .fm-panel.drag-target { border-color:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,.3); background:#f0fdf4; }

        /* === Panel header (path bar + root selector) === */
        .fm-panel-header { display:flex; align-items:center; gap:4px; padding:6px 8px; background:#1e293b; color:#fff; font-size:13px; }
        .fm-panel-header select { background:#334155; color:#fff; border:1px solid #475569; border-radius:4px; padding:3px 6px; font-size:12px; outline:none; }
        .fm-breadcrumbs { flex:1; display:flex; flex-wrap:wrap; align-items:center; gap:2px; overflow:hidden; }
        .fm-crumb { color:#93c5fd; cursor:pointer; padding:2px 4px; border-radius:3px; white-space:nowrap; }
        .fm-crumb:hover { background:#334155; }
        .fm-crumb-current { color:#fff; font-weight:600; }
        .fm-back-btn { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:6px; background:#334155; color:#93c5fd; border:1px solid #475569; cursor:pointer; transition:background .12s; flex-shrink:0; }
        .fm-back-btn:hover { background:#475569; color:#fff; }
        .fm-back-btn:disabled { opacity:0.35; cursor:not-allowed; }
        .fm-back-btn:disabled:hover { background:#334155; color:#93c5fd; }

        /* === Panel content === */
        .fm-container { flex:1; overflow-y:auto; padding:4px; }

        /* === File grid === */
        .fm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:8px; padding:4px; }
        .fm-card { position:relative; display:flex; flex-direction:column; background:#fff; border:2px solid #e2e8f0; border-radius:8px; overflow:hidden; cursor:pointer; transition:border-color .12s; }
        .fm-card:hover { border-color:#93c5fd; }
        .fm-card.selected { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 2px #2563eb; }
        .fm-card.drag-over { border-color:#16a34a; background:#f0fdf4; }
        .fm-card.dragging { opacity:0.4; }
        .fm-thumb { width:100%; aspect-ratio:1/1; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .fm-thumb img { width:100%; height:100%; object-fit:cover; }
        .fm-icon { font-size:36px; }
        .fm-icon-folder { color:#f59e0b; }
        .fm-icon-zip { color:#8b5cf6; }
        .fm-icon-img { color:#10b981; }
        .fm-icon-code { color:#3b82f6; }
        .fm-name { padding:4px 6px 0; font-size:12px; font-weight:500; color:#1e293b; word-break:break-all; line-height:1.2; max-height:2.4em; overflow:hidden; }
        .fm-meta { padding:0 6px 4px; font-size:10px; color:#94a3b8; }

        /* === Card checkbox (multi-select) === */
        .fm-card-checkbox {
            position:absolute; top:6px; left:6px; z-index:2;
            width:24px; height:24px; border-radius:6px;
            background:rgba(255,255,255,0.92); color:#2563eb;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; opacity:0; transition:opacity .12s, background-color .12s;
            box-shadow:0 1px 3px rgba(0,0,0,0.15);
            font-size:14px;
        }
        .fm-card:hover .fm-card-checkbox,
        .fm-card.selected .fm-card-checkbox { opacity:1; }
        .fm-card.selected .fm-card-checkbox { background:#2563eb; color:#fff; }
        .fm-card-checkbox:hover { background:#fff; color:#1d4ed8; }
        .fm-card.selected .fm-card-checkbox:hover { background:#1d4ed8; color:#fff; }

        /* === Bulk action bar — FAB style, like the editors and media.php ===
           Mirrors the .aicms-fab-bar floating pill: fixed BOTTOM-RIGHT, dark
           pill, vertical rubber buttons that stretch as ONE block on hover
           (data-stretch-block, handled by the stretch coordinator in
           aicms-ui.js). Only the ACTIVE panel's bar may be visible (guard
           below), so the two panels' bars never overlap in dual-panel mode. */
        .fm-bulk-bar {
            display: none;
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 1060; /* ниже модалок (100001+) */
            flex-direction: column;
            align-items: flex-end; /* колонка прижата к правому краю, без подложки */
            gap: 6px;
        }
        .fm-panel.active .fm-bulk-bar.is-visible { display: flex; }
        .fm-bulk-bar .fm-bulk-label {
            display: inline-flex; align-items: center; gap: 5px;
            color: #475569; font-weight: 500; font-size: 12px;
            padding: 2px 8px 4px; white-space: nowrap;
        }
        .fm-bulk-bar .fm-bulk-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 20px; padding: 0 6px;
            background: #2563eb; color: #fff; border-radius: 10px;
            font-weight: 700; font-size: 11px;
        }
        /* Rubber buttons — identical behaviour to .aicms-fab-btn (media.php) */
        .fm-bulk-bar .aicms-fab-btn {
            width: 48px; height: 48px; min-width: 48px; max-width: 48px;
            border-radius: 12px; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; color: #fff; font-size: 14px;
            padding: 0; gap: 0;
            overflow: hidden; white-space: nowrap; position: relative;
            transition: width var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        min-width var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        max-width var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        padding var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        background-color 0.15s, box-shadow 0.15s;
        }
        .fm-bulk-bar .aicms-fab-btn:hover,
        .fm-bulk-bar .aicms-fab-btn.aicms-stretch-keep {
            width: auto; min-width: 48px; max-width: 220px;
            padding: 0 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.28); z-index: 20;
        }
        .fm-bulk-bar .aicms-fab-btn i {
            position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
            font-size: 20px; flex-shrink: 0;
            transition: opacity calc(var(--aicms-stretch-duration) * 0.7) var(--aicms-stretch-ease);
        }
        .fm-bulk-bar .aicms-fab-btn .btn-label {
            display: block; min-width: 0; max-width: 0; opacity: 0;
            overflow: hidden; white-space: nowrap;
            transition: opacity calc(var(--aicms-stretch-duration) * 0.8) var(--aicms-stretch-label-delay) var(--aicms-stretch-ease),
                        max-width var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        min-width var(--aicms-stretch-duration) var(--aicms-stretch-ease);
            font-size: 13px; font-weight: 500; line-height: 1; flex-shrink: 1;
        }
        .fm-bulk-bar .aicms-fab-btn:hover i,
        .fm-bulk-bar .aicms-fab-btn.aicms-stretch-keep i { opacity: 0; }
        .fm-bulk-bar .aicms-fab-btn:hover .btn-label,
        .fm-bulk-bar .aicms-fab-btn.aicms-stretch-keep .btn-label { opacity: 1; max-width: 170px; }
        /* Variant colours — контрастные ступени палитры (белая иконка ≥4.5:1) */
        .fm-bulk-bar .aicms-fab-btn--zip      { background-color: #7c3aed; } /* violet-600 ≈5.7:1 */
        .fm-bulk-bar .aicms-fab-btn--zip:hover,
        .fm-bulk-bar .aicms-fab-btn--zip.aicms-stretch-keep      { background-color: #6d28d9; }
        .fm-bulk-bar .aicms-fab-btn--download { background-color: #15803d; } /* green-700 ≈5.0:1 */
        .fm-bulk-bar .aicms-fab-btn--download:hover,
        .fm-bulk-bar .aicms-fab-btn--download.aicms-stretch-keep { background-color: #166534; }
        .fm-bulk-bar .aicms-fab-btn--duplicate { background-color: #0e7490; } /* cyan-700 ≈5.4:1 */
        .fm-bulk-bar .aicms-fab-btn--duplicate:hover,
        .fm-bulk-bar .aicms-fab-btn--duplicate.aicms-stretch-keep { background-color: #155e75; }
        .fm-bulk-bar .aicms-fab-btn--move     { background-color: #b45309; } /* amber-700 ≈5.0:1 */
        .fm-bulk-bar .aicms-fab-btn--move:hover,
        .fm-bulk-bar .aicms-fab-btn--move.aicms-stretch-keep     { background-color: #92400e; }
        .fm-bulk-bar .aicms-fab-btn--danger   { background-color: #dc2626; } /* red-600 ≈4.8:1 */
        .fm-bulk-bar .aicms-fab-btn--danger:hover,
        .fm-bulk-bar .aicms-fab-btn--danger.aicms-stretch-keep   { background-color: #b91c1c; }
        .fm-bulk-bar .aicms-fab-btn--neutral  { background-color: #475569; } /* slate-600 ≈7.6:1 */
        .fm-bulk-bar .aicms-fab-btn--neutral:hover,
        .fm-bulk-bar .aicms-fab-btn--neutral.aicms-stretch-keep  { background-color: #334155; }

        /* === Function button select-all === */
        .fm-fn-selectall { background:#0f766e; }
        .fm-fn-selectall:hover { background:#115e59; }
        .fm-fn-download { background:#0891b2; }
        .fm-fn-download:hover { background:#0e7490; }

        /* === Function buttons (Total Commander style) ===
           fm-fn-btn uses the AICMS stretch system: collapsed = icon-only
           38×38 (uniform with .aicms-act-outline / .aicms-icon-btn),
           expands to reveal label on hover. The aicms-ui.js coordinator
           auto-applies .fm-fn-btn--stretch + wraps loose text into
           <span class="btn-label"> (using the title attribute as the
           label source, since these buttons don't have visible text).
           Direction-aware hand-off + random 650–900ms duration are
           inherited from the global coordinator. */
        .fm-fn-bar { display:flex; gap:4px; flex-wrap:nowrap; padding:8px; background:#f1f5f9; border-bottom:1px solid #cbd5e1; align-items:center; overflow-x:auto; overflow-y:hidden; }
        /* Inner block of function buttons: `margin-left:auto` anchors it to
           the RIGHT edge of the bar (Total Commander style — the buttons
           form a right-aligned block). Unlike `justify-content:flex-end`,
           overflow stays on the RIGHT side (scrollLeft is reachable), so on
           narrow screens the leftmost buttons stay visible and the bar
           scrolls normally instead of clipping the start of the block. */
        .fm-fn-btns { display:flex; gap:4px; width:auto; min-width:0; margin-left:auto; flex:0 1 auto; }
        /* Hide scrollbar but keep scrollable — the function bar should look
           clean even when it overflows on narrow screens. */
        .fm-fn-bar::-webkit-scrollbar { height:6px; }
        .fm-fn-bar::-webkit-scrollbar-track { background:transparent; }
        .fm-fn-bar::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:3px; }
        .fm-fn-bar { scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent; }
        .fm-fn-btn {
            display:inline-flex; align-items:center; justify-content:center;
            width:38px; height:38px; min-width:38px; max-width:38px;
            padding:0; border:none; border-radius:8px;
            font-size:14px; font-weight:600; cursor:pointer;
            color:#fff; position:relative; overflow:hidden; white-space:nowrap;
            gap:0;
            transition: width var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        min-width var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        max-width var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        padding var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        filter .12s, transform .15s, box-shadow .15s;
            box-sizing: border-box;
            flex-shrink: 0;
        }
        .fm-fn-btn:disabled { opacity:0.4; cursor:default; }
        .fm-fn-btn i { font-size:14px; flex-shrink:0; }
        /* Search "button" is a special container — keep it non-stretchy */
        .fm-fn-btn.fm-fn-search { width:auto; min-width:0; max-width:none; padding:0 8px; gap:5px; overflow:visible; }
        /* Stretch variant: applied by aicms-ui.js. When hovered (or kept
           expanded by the coordinator), the button reveals its label. */
        .fm-fn-btn--stretch:hover,
        .fm-fn-btn--stretch.aicms-stretch-keep {
            width: auto; min-width: 38px; max-width: 220px;
            padding: 0 12px; gap: 6px;
        }
        .fm-fn-btn--stretch:hover { filter: brightness(1.15); transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2); z-index: 20; }
        .fm-fn-btn--stretch:active { transform: translateY(1px); }
        .fm-fn-btn--stretch .btn-label {
            display:block; min-width:0; max-width:0; opacity:0;
            overflow:hidden; white-space:nowrap; font-size:12px; font-weight:500;
            transition: opacity calc(var(--aicms-stretch-duration, 0.75s) * 0.8) var(--aicms-stretch-label-delay, 0.05s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        max-width var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1)),
                        min-width var(--aicms-stretch-duration, 0.75s) var(--aicms-stretch-ease, cubic-bezier(0.22,1,0.36,1));
            flex-shrink: 1;
        }
        .fm-fn-btn--stretch:hover .btn-label,
        .fm-fn-btn--stretch.aicms-stretch-keep .btn-label {
            opacity: 1; max-width: 180px;
        }
        /* Tooltip on hover — only show when the button is NOT yet stretched
           (i.e. on touch devices or before JS has run). Once stretched, the
           label is visible inside the button and the tooltip would duplicate. */
        .fm-fn-btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            top: -32px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 100;
            pointer-events: none;
            font-weight: 500;
        }
        .fm-fn-search { background:#64748b; width:auto; padding:0 8px; gap:5px; flex:0 1 auto; min-width:0; }
        .fm-fn-search input { background:#fff; color:#000; border:none; border-radius:4px; padding:3px 8px; font-size:13px; width:120px; flex:1 1 auto; min-width:0; max-width:160px; outline:none; }
        .fm-fn-new   { background:#16a34a; }
        .fm-fn-mkdir { background:#0891b2; }
        .fm-fn-upload{ background:#2563eb; }
        .fm-fn-rename{ background:#d97706; }
        .fm-fn-copy  { background:#7c3aed; }
        .fm-fn-move  { background:#db2777; }
        .fm-fn-delete{ background:#dc2626; }
        .fm-fn-zip   { background:#475569; }
        .fm-fn-unzip { background:#059669; }
        .fm-fn-edit  { background:#1d4ed8; }
        .fm-fn-dual  { background:#334155; }
        .fm-fn-download { background:#0891b2; }
        /* Copy-URL button — needs an explicit light-theme colour: without it
           the white icon sits on the white page background and is unreadable.
           (Dark theme has its own rule below.) */
        .fm-fn-url   { background:#7c3aed; }
        .fm-fn-url:hover { background:#6d28d9; }

        /* === Toast === */
        .fm-toast { position:fixed; bottom:20px; right:20px; z-index:100000; padding:10px 16px; border-radius:8px; font-size:14px; font-weight:500; box-shadow:0 8px 24px rgba(0,0,0,.15); max-width:380px; opacity:0; transform:translateY(20px); transition:all .3s; }
        .fm-toast.visible { opacity:1; transform:translateY(0); }
        .fm-toast-success { background:#16a34a; color:#fff; }
        .fm-toast-error { background:#dc2626; color:#fff; }
        .fm-toast-info { background:#2563eb; color:#fff; }

        /* === Modal === */
        .fm-modal { position:fixed; inset:0; z-index:100001; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.5); }
        .fm-modal.show { display:flex; }
        .fm-modal-box { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,.25); max-width:90vw; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; }

        /* === Lightbox === */
        .fm-lightbox { position:fixed; inset:0; z-index:100002; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.9); cursor:zoom-out; }
        .fm-lightbox.show { display:flex; }
        .fm-lightbox img { max-width:95vw; max-height:95vh; border-radius:8px; }

        .fm-empty { grid-column:1/-1; text-align:center; padding:40px 20px; color:#94a3b8; }
        [x-cloak]{display:none!important}
        .hidden { display:none !important; }

        /* === Dark theme support === */
        body.dark-theme { background:#0f172a; color:#e2e8f0; }
        body.dark-theme .fm-panel { background:#1e293b; border-color:#334155; }
        body.dark-theme .fm-panel.active { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.3); }
        body.dark-theme .fm-panel.drag-target { border-color:#22c55e; background:#052e16; }
        body.dark-theme .fm-panel-header { background:#0f172a; }
        body.dark-theme .fm-panel-header select { background:#1e293b; color:#e2e8f0; border-color:#475569; }
        body.dark-theme .fm-crumb { color:#60a5fa; }
        body.dark-theme .fm-crumb:hover { background:#334155; }
        body.dark-theme .fm-crumb-current { color:#f1f5f9; }
        body.dark-theme .fm-card { background:#1e293b; border-color:#334155; }
        body.dark-theme .fm-card:hover { border-color:#3b82f6; }
        body.dark-theme .fm-card.selected { border-color:#3b82f6; background:#1e3a5f; box-shadow:0 0 0 2px #3b82f6; }
        body.dark-theme .fm-card.drag-over { border-color:#22c55e; background:#052e16; }
        body.dark-theme .fm-thumb { background:#0f172a; }
        body.dark-theme .fm-name { color:#e2e8f0; }
        body.dark-theme .fm-meta { color:#64748b; }
        body.dark-theme .fm-card-checkbox { background:rgba(30,41,59,0.92); color:#60a5fa; }
        body.dark-theme .fm-card.selected .fm-card-checkbox { background:#3b82f6; color:#fff; }
        body.dark-theme .fm-card-checkbox:hover { background:#334155; color:#93c5fd; }
        body.dark-theme .fm-card.selected .fm-card-checkbox:hover { background:#2563eb; color:#fff; }
        body.dark-theme .fm-bulk-bar .fm-bulk-label { color:#cbd5e1; }
        body.dark-theme .fm-fn-bar { background:#1e293b; border-bottom-color:#334155; }
        body.dark-theme .fm-fn-btn { color:#fff; }
        body.dark-theme .fm-fn-search input { background:#0f172a; color:#e2e8f0; border:1px solid #475569; }
        body.dark-theme .fm-modal-box { background:#1e293b; color:#e2e8f0; }
        body.dark-theme .fm-modal-box h5,
        body.dark-theme .fm-modal-box label,
        body.dark-theme .fm-modal-box p,
        body.dark-theme .fm-modal-box span { color:#e2e8f0; }
        body.dark-theme .fm-modal-box input[type="text"],
        body.dark-theme .fm-modal-box select,
        body.dark-theme .fm-modal-box textarea { background:#0f172a; color:#e2e8f0; border-color:#475569; }
        body.dark-theme .fm-empty { color:#475569; }
        body.dark-theme .fm-toast { box-shadow:0 8px 24px rgba(0,0,0,.5); }
        /* Editor modal */
        body.dark-theme #fmEditorModal .fm-modal-box,
        body.dark-theme #fmMoveModal .fm-modal-box,
        body.dark-theme #fmBulkMoveModal .fm-modal-box { background:#1e293b; }
        body.dark-theme #fmEditorTextarea { background:#0f172a; color:#e2e8f0; }
        /* Function bar buttons keep their colors but slightly muted */
        body.dark-theme .fm-fn-new   { background:#15803d; }
        body.dark-theme .fm-fn-mkdir { background:#0e7490; }
        body.dark-theme .fm-fn-upload{ background:#1d4ed8; }
        body.dark-theme .fm-fn-rename{ background:#b45309; }
        body.dark-theme .fm-fn-copy  { background:#6d28d9; }
        body.dark-theme .fm-fn-move  { background:#be185d; }
        body.dark-theme .fm-fn-delete{ background:#b91c1c; }
        body.dark-theme .fm-fn-zip   { background:#334155; }
        body.dark-theme .fm-fn-unzip { background:#047857; }
        body.dark-theme .fm-fn-edit   { background:#1e40af; }
        body.dark-theme .fm-fn-dual  { background:#1e293b; }
        body.dark-theme .fm-fn-search{ background:#475569; }
        body.dark-theme .fm-fn-url   { background:#6d28d9; }
    </style>
</head>
<body class="bg-slate-100" <?php if (isset($_COOKIE['aicms_dark']) && $_COOKIE['aicms_dark'] === '1') echo 'class="bg-slate-100 dark-theme"'; ?>>
<script>
// Apply dark theme early to avoid flash
try {
    if (localStorage.getItem('aicms_dark') === '1') {
        document.body.classList.add('dark-theme');
    }
} catch(e) {}
</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-4 flex-1 flex flex-col">
        <?php display_flash_message(); ?>

        <!-- Function button bar (Total Commander style) -->
        <!-- data-stretch-window="5": expand 5 buttons at a time (instead of the
             default 3) when hovering in this bar, since the bar has ~15 buttons
             and a wider window feels more natural here. -->
        <div class="fm-fn-bar">
            <!-- data-stretch-window="5": the stretch coordinator reads this
                 from the GROUP container (findGroupContainer → .fm-fn-btns),
                 so it lives on the inner block, not the outer bar. -->
            <div class="fm-fn-btns" data-stretch-window="5">
            <button class="fm-fn-btn fm-fn-new" id="fmNewFile" title="<?= t('new') ?>"><i class="fas fa-file-circle-plus"></i></button>
            <button class="fm-fn-btn fm-fn-mkdir" id="fmNewFolder" title="<?= t('folder') ?>"><i class="fas fa-folder-plus"></i></button>
            <button class="fm-fn-btn fm-fn-upload" id="fmUpload" title="<?= t('upload') ?>"><i class="fas fa-upload"></i></button>
            <input type="file" id="fmFileInput" multiple style="display:none;">
            <button class="fm-fn-btn fm-fn-selectall" id="fmSelectAll" title="<?= t('select_all') ?>"><i class="fas fa-check-double"></i></button>
            <button class="fm-fn-btn fm-fn-url" id="fmUrl" title="<?= t('copy_url') ?? 'Копировать URL' ?>"><i class="fas fa-link"></i></button>
            <button class="fm-fn-btn fm-fn-download" id="fmDownloadFile" title="<?= t('download_file') ?? 'Скачать файл' ?>"><i class="fas fa-download"></i></button>
            <button class="fm-fn-btn fm-fn-rename" id="fmRename" title="<?= t('rename') ?>"><i class="fas fa-i-cursor"></i></button>
            <button class="fm-fn-btn fm-fn-copy" id="fmCopy" title="<?= t('copy') ?>"><i class="fas fa-copy"></i></button>
            <button class="fm-fn-btn fm-fn-move" id="fmMove" title="<?= t('move') ?>"><i class="fas fa-arrows-alt"></i></button>
            <button class="fm-fn-btn fm-fn-delete" id="fmDelete" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button>
            <button class="fm-fn-btn fm-fn-zip" id="fmZip" title="ZIP"><i class="fas fa-file-archive"></i></button>
            <button class="fm-fn-btn fm-fn-download" id="fmDownload" title="Download as ZIP"><i class="fas fa-download"></i></button>
            <button class="fm-fn-btn fm-fn-unzip" id="fmUnzip" title="<?= t('extract') ?>"><i class="fas fa-box-open"></i></button>
            <button class="fm-fn-btn fm-fn-edit" id="fmEdit" title="<?= t('editor') ?>"><i class="fas fa-code"></i></button>
            <button class="fm-fn-btn fm-fn-dual" id="fmTogglePanels" title="<?= t('two_panels') ?>"><i class="fas fa-columns"></i></button>
            <button class="fm-fn-btn" id="fmTrashBtn" style="background:#f59e0b;" title="Корзина (.trash/)"><i class="fas fa-trash-restore"></i></button>
            <div class="fm-fn-btn fm-fn-search"><i class="fas fa-search"></i><input type="text" id="fmSearch" placeholder="<?= t('search_placeholder') ?>"></div>
            </div>
        </div>

        <div id="fmWrapper" class="fm-wrapper fm-single" style="margin-top:8px;">
            <div class="fm-panel active" id="fmPanel1" data-panel="1">
                <div class="fm-panel-header">
                    <button class="fm-back-btn" id="fmBack1" title="<?= t('fm_go_up') ?? '↑ Вверх' ?>" disabled><i class="fas fa-arrow-left"></i></button>
                    <select id="fmRoot1" class="fm-root-select">
                        <option value="uploads">uploads/</option>
                        <option value="data">data/</option>
                        <option value="themes">themes/</option>
                        <option value="modules">modules/</option>
                        <option value="assets">assets/</option>
                        <option value="widgets">widgets/</option>
                        <option value="admin">admin/</option>
                        <option value="api">api/</option>
                        <option value="aicms_root"><?= t('filemanager_root') ?></option>
                    </select>
                    <div class="fm-breadcrumbs" id="fmCrumbs1"></div>
                </div>
                <div class="fm-container" id="fmContainer1"></div>
                <div class="fm-bulk-bar" id="fmBulkBar1" data-stretch-block="1">
                    <span class="fm-bulk-label"><i class="fas fa-check-square"></i> <?= t('selected_count') ?> <span class="fm-bulk-count">0</span></span>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--zip" data-bulk-action="zip" aria-label="ZIP"><i class="fas fa-file-archive"></i><span class="btn-label">ZIP</span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--download" data-bulk-action="download" aria-label="<?= h(t('download')) ?>"><i class="fas fa-download"></i><span class="btn-label"><?= h(t('download')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--duplicate" data-bulk-action="copy" aria-label="<?= h(t('copy')) ?>"><i class="fas fa-copy"></i><span class="btn-label"><?= h(t('copy')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--move" data-bulk-action="move" aria-label="<?= h(t('move')) ?>"><i class="fas fa-arrows-alt"></i><span class="btn-label"><?= h(t('move')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--danger" data-bulk-action="delete" aria-label="<?= h(t('delete')) ?>"><i class="fas fa-trash"></i><span class="btn-label"><?= h(t('delete')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--neutral" data-bulk-action="clear" aria-label="<?= h(t('clear_selection')) ?>"><i class="fas fa-times"></i><span class="btn-label"><?= h(t('clear_selection')) ?></span></button>
                </div>
            </div>
            <div class="fm-panel hidden" id="fmPanel2" data-panel="2">
                <div class="fm-panel-header">
                    <button class="fm-back-btn" id="fmBack2" title="<?= t('fm_go_up') ?? '↑ Вверх' ?>" disabled><i class="fas fa-arrow-left"></i></button>
                    <select id="fmRoot2" class="fm-root-select">
                        <option value="uploads">uploads/</option>
                        <option value="data">data/</option>
                        <option value="themes">themes/</option>
                        <option value="modules">modules/</option>
                        <option value="assets">assets/</option>
                        <option value="widgets">widgets/</option>
                        <option value="admin">admin/</option>
                        <option value="api">api/</option>
                        <option value="aicms_root"><?= t('filemanager_root') ?></option>
                    </select>
                    <div class="fm-breadcrumbs" id="fmCrumbs2"></div>
                </div>
                <div class="fm-container" id="fmContainer2"></div>
                <div class="fm-bulk-bar" id="fmBulkBar2" data-stretch-block="1">
                    <span class="fm-bulk-label"><i class="fas fa-check-square"></i> <?= t('selected_count') ?> <span class="fm-bulk-count">0</span></span>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--zip" data-bulk-action="zip" aria-label="ZIP"><i class="fas fa-file-archive"></i><span class="btn-label">ZIP</span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--download" data-bulk-action="download" aria-label="<?= h(t('download')) ?>"><i class="fas fa-download"></i><span class="btn-label"><?= h(t('download')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--duplicate" data-bulk-action="copy" aria-label="<?= h(t('copy')) ?>"><i class="fas fa-copy"></i><span class="btn-label"><?= h(t('copy')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--move" data-bulk-action="move" aria-label="<?= h(t('move')) ?>"><i class="fas fa-arrows-alt"></i><span class="btn-label"><?= h(t('move')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--danger" data-bulk-action="delete" aria-label="<?= h(t('delete')) ?>"><i class="fas fa-trash"></i><span class="btn-label"><?= h(t('delete')) ?></span></button>
                    <button type="button" class="aicms-fab-btn aicms-fab-btn--neutral" data-bulk-action="clear" aria-label="<?= h(t('clear_selection')) ?>"><i class="fas fa-times"></i><span class="btn-label"><?= h(t('clear_selection')) ?></span></button>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Code Editor Modal (Monaco Editor) -->
<div class="fm-modal" id="fmEditorModal" onclick="if(event.target===this)fmCloseEditor()">
    <div class="fm-modal-box w-full max-w-4xl" style="height:85vh;">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="font-semibold text-slate-800 m-0" id="fmEditorTitle"><?= t('editor') ?></h5>
            <button onclick="fmCloseEditor()" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
        </div>
        <div class="flex-1 overflow-hidden" style="height:calc(100% - 110px);">
            <div id="fmMonacoEditor" style="width:100%;height:100%;"></div>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 flex justify-end gap-2">
            <button onclick="fmCloseEditor()" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-times"></i><span class="btn-label"><?= t('close') ?></span></button>
            <button onclick="fmSaveFile()" class="aicms-icon-btn aicms-icon-btn--success"><i class="fas fa-save"></i><span class="btn-label"><?= t('save') ?></span></button>
        </div>
    </div>
</div>

<!-- Lightbox -->
<div class="fm-lightbox" id="fmLightbox" onclick="this.classList.remove('show')">
    <img id="fmLightboxImg" src="" alt="">
</div>

<!-- Move/Copy Modal -->
<div class="fm-modal" id="fmMoveModal" onclick="if(event.target===this)document.getElementById('fmMoveModal').classList.remove('show')">
    <div class="fm-modal-box w-full max-w-md">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="font-semibold text-slate-800 m-0" id="fmMoveTitle"><?= t('move') ?></h5>
            <button onclick="document.getElementById('fmMoveModal').classList.remove('show')" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
        </div>
        <div class="p-5">
            <p class="text-sm text-slate-600 mb-3" id="fmMoveDesc"></p>
            <select id="fmMoveTarget" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" style="background:#fff;color:#000;"></select>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 flex justify-end gap-2">
            <button onclick="document.getElementById('fmMoveModal').classList.remove('show')" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-times"></i><span class="btn-label"><?= t('cancel') ?></span></button>
            <button onclick="fmExecuteMove()" class="aicms-icon-btn aicms-icon-btn--primary" id="fmMoveBtn"><i class="fas fa-check"></i><span class="btn-label"><?= t('confirm_btn') ?></span></button>
        </div>
    </div>
</div>

<!-- Bulk Move/Copy Modal -->
<div class="fm-modal" id="fmBulkMoveModal" onclick="if(event.target===this)document.getElementById('fmBulkMoveModal').classList.remove('show')">
    <div class="fm-modal-box w-full max-w-md">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="font-semibold text-slate-800 m-0" id="fmBulkMoveTitle"><?= t('move') ?></h5>
            <button onclick="document.getElementById('fmBulkMoveModal').classList.remove('show')" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
        </div>
        <div class="p-5">
            <p class="text-sm text-slate-600 mb-3" id="fmBulkMoveDesc"></p>
            <select id="fmBulkMoveTarget" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500" style="background:#fff;color:#000;"></select>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 flex justify-end gap-2">
            <button onclick="document.getElementById('fmBulkMoveModal').classList.remove('show')" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-times"></i><span class="btn-label"><?= t('cancel') ?></span></button>
            <button onclick="fmExecuteBulkMove()" class="aicms-icon-btn aicms-icon-btn--primary" id="fmBulkMoveBtn"><i class="fas fa-check"></i><span class="btn-label"><?= t('confirm_btn') ?></span></button>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="assets/js/filemanager.js?v=20260801"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fm1 = initFileManager({
        container: '#fmContainer1', panel: '#fmPanel1', crumbs: '#fmCrumbs1',
        rootSelect: '#fmRoot1', backBtn: '#fmBack1', csrfToken: "<?= csrf_field_value() ?>"
    });
    var fm2 = initFileManager({
        container: '#fmContainer2', panel: '#fmPanel2', crumbs: '#fmCrumbs2',
        rootSelect: '#fmRoot2', backBtn: '#fmBack2', csrfToken: "<?= csrf_field_value() ?>"
    });

    // Function buttons — operate on active panel's selected item
    function getActiveFM() {
        var p1 = document.getElementById('fmPanel1');
        var p2 = document.getElementById('fmPanel2');
        if (p2.classList.contains('active') && !p2.classList.contains('hidden')) return fm2;
        return fm1;
    }

    document.getElementById('fmNewFolder').addEventListener('click', function() { getActiveFM().newFolder(); });
    document.getElementById('fmUpload').addEventListener('click', function() { getActiveFM().uploadClick(); });
    document.getElementById('fmFileInput').addEventListener('change', function(e) { getActiveFM().uploadFiles(e.target.files); e.target.value = ''; });
    document.getElementById('fmRename').addEventListener('click', function() { getActiveFM().renameSelected(); });
    document.getElementById('fmCopy').addEventListener('click', function() { getActiveFM().copySelected(); });
    document.getElementById('fmMove').addEventListener('click', function() { getActiveFM().moveSelected(); });
    document.getElementById('fmDelete').addEventListener('click', function() { getActiveFM().deleteSelected(); });
    document.getElementById('fmZip').addEventListener('click', function() { getActiveFM().zipSelected(); });
    document.getElementById('fmDownload').addEventListener('click', function() { getActiveFM().bulkDownload(); });
    document.getElementById('fmUnzip').addEventListener('click', function() { getActiveFM().unzipSelected(); });
    document.getElementById('fmEdit').addEventListener('click', function() { getActiveFM().editSelected(); });
    document.getElementById('fmNewFile').addEventListener('click', function() { getActiveFM().newFile(); });
    document.getElementById('fmSelectAll').addEventListener('click', function() { getActiveFM().selectAll(); });
    document.getElementById('fmUrl').addEventListener('click', function() { getActiveFM().getUrl(); });
    document.getElementById('fmDownloadFile').addEventListener('click', function() { getActiveFM().downloadSingle(); });

    // Bulk action bar handlers (per-panel)
    function bindBulkBar(barId) {
        var bar = document.getElementById(barId);
        if (!bar) return;
        bar.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-bulk-action]');
            if (!btn) return;
            // Find which FM instance owns this bar
            var panel = bar.closest('.fm-panel');
            var fmInstance = null;
            if (panel.id === 'fmPanel1') fmInstance = fm1;
            else if (panel.id === 'fmPanel2') fmInstance = fm2;
            if (!fmInstance) return;
            var action = btn.getAttribute('data-bulk-action');
            if (action === 'delete') fmInstance.bulkDelete();
            else if (action === 'zip') fmInstance.bulkZip();
            else if (action === 'download') fmInstance.bulkDownload();
            else if (action === 'copy') fmInstance.bulkCopy();
            else if (action === 'move') fmInstance.bulkMove();
            else if (action === 'clear') fmInstance.clearSelection();
        });
    }
    bindBulkBar('fmBulkBar1');
    bindBulkBar('fmBulkBar2');

    // Search — applies to active panel
    document.getElementById('fmSearch').addEventListener('input', function(e) {
        getActiveFM().search(e.target.value);
    });

    // Toggle dual-panel
    function toggleDualPanel() {
        var wrapper = document.getElementById('fmWrapper');
        var panel2 = document.getElementById('fmPanel2');
        var btn = document.getElementById('fmTogglePanels');
        if (wrapper.classList.contains('fm-single')) {
            wrapper.classList.remove('fm-single'); wrapper.classList.add('fm-dual');
            panel2.classList.remove('hidden');
            btn.style.background = '#2563eb';
            try { localStorage.setItem('fm_dual', '1'); } catch(e) {}
            if (fm2 && !fm2._loaded) { fm2.loadFolder(''); fm2._loaded = true; }
        } else {
            wrapper.classList.add('fm-single'); wrapper.classList.remove('fm-dual');
            panel2.classList.add('hidden');
            btn.style.background = '#334155';
            try { localStorage.setItem('fm_dual', '0'); } catch(e) {}
        }
    }
    document.getElementById('fmTogglePanels').addEventListener('click', toggleDualPanel);

    // Trash button — navigate to .trash/ folder
    document.getElementById('fmTrashBtn').addEventListener('click', function() {
        var fm = getActiveFM();
        if (!fm) return;
        // List trash contents via API
        var path = fm.getCurrentPath();
        var root = fm.getRoot();
        var url = 'api_filemanager.php?action=list_trash&csrf_token=' + encodeURIComponent(csrfToken) + '&root=' + encodeURIComponent(root) + '&path=' + encodeURIComponent(path);
        fetch(url).then(function(r) { return r.json(); }).then(function(d) {
            if (d.status === 'success') {
                showTrashModal(d.items, path, root, fm);
            } else {
                if (window.AicmsToast) AicmsToast.show(d.message || 'Error', 'error');
            }
        });
    });

    function showTrashModal(items, path, root, fm) {
        // Remove existing modal
        var existing = document.getElementById('fmTrashModal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'fmTrashModal';
        modal.className = 'fm-modal show';
        modal.style.cssText = 'display:flex !important;';
        modal.innerHTML = `
            <div class="fm-modal-box" style="width:500px;max-width:90vw;">
                <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
                    <h5 class="font-semibold text-slate-800 m-0"><i class="fas fa-trash-restore text-amber-500 mr-1"></i>Корзина (.trash/)</h5>
                    <button onclick="document.getElementById('fmTrashModal').remove()" class="text-slate-400 text-xl">&times;</button>
                </div>
                <div class="p-4 max-h-[60vh] overflow-y-auto" id="trashList">
                    ${items.length === 0 ? '<p class="text-center text-slate-400 py-8">Корзина пуста</p>' :
                        items.map(function(item) {
                            return '<div class="flex items-center justify-between py-2 px-3 border-b border-slate-100 last:border-0">' +
                                '<div class="flex items-center gap-2"><i class="fas ' + (item.is_dir ? 'fa-folder' : 'fa-file') + ' text-slate-400"></i>' +
                                '<span class="text-sm text-slate-700">' + item.name + '</span>' +
                                '<span class="text-xs text-slate-400">' + (item.is_dir ? '' : (Math.round(item.size/1024) + ' KB')) + '</span></div>' +
                                '<button class="aicms-icon-btn aicms-icon-btn--success" onclick="restoreFromTrash(\'' + item.name.replace(/'/g, "\\'") + '\',\'' + path + '\',\'' + root + '\')"><i class="fas fa-undo"></i><span class="btn-label">Восстановить</span></button>' +
                                '</div>';
                        }).join('')
                    }
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
    }

    window.restoreFromTrash = function(name, path, root) {
        var fd = new FormData();
        fd.append('action', 'restore');
        fd.append('path', path);
        fd.append('name', name);
        fd.append('root', root);
        fd.append('csrf_token', csrfToken);
        fetch('api_filemanager.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 'success') {
                    if (window.AicmsToast) AicmsToast.show(d.message, 'success');
                    var modal = document.getElementById('fmTrashModal');
                    if (modal) modal.remove();
                    getActiveFM().reload();
                } else {
                    if (window.AicmsToast) AicmsToast.show(d.message, 'error');
                }
            });
    };
    try {
        if (localStorage.getItem('fm_dual') === '1') {
            document.getElementById('fmWrapper').classList.remove('fm-single');
            document.getElementById('fmWrapper').classList.add('fm-dual');
            document.getElementById('fmPanel2').classList.remove('hidden');
            document.getElementById('fmTogglePanels').style.background = '#2563eb';
            if (fm2 && !fm2._loaded) { fm2.loadFolder(''); fm2._loaded = true; }
        }
    } catch(e) {}

    // Active panel tracking
    document.addEventListener('click', function(e) {
        var panel = e.target.closest('.fm-panel');
        if (panel) {
            document.querySelectorAll('.fm-panel').forEach(function(p) { p.classList.remove('active'); });
            panel.classList.add('active');
        }
    });

    // Keyboard: F5 copy, F6 move, F8 delete, F2 rename (Total Commander style)
    //           Arrow keys move the cursor in the grid (Shift+Arrow extends selection)
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
        var fm = getActiveFM();
        if (e.key === 'F2') { e.preventDefault(); fm.renameSelected(); }
        else if (e.key === 'F5') { e.preventDefault(); fm.copySelected(); }
        else if (e.key === 'F6') { e.preventDefault(); fm.moveSelected(); }
        else if (e.key === 'F8' || e.key === 'Delete') { e.preventDefault(); fm.deleteSelected(); }
        else if (e.key === 'F7') { e.preventDefault(); fm.newFolder(); }
        else if (e.key === 'F4') { e.preventDefault(); fm.getUrl(); }
        else if (e.key === 'Insert') { e.preventDefault(); fm.toggleSelectUnderCursor(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); if (fm.arrowNavigate) fm.arrowNavigate('left', e.shiftKey); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); if (fm.arrowNavigate) fm.arrowNavigate('right', e.shiftKey); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (fm.arrowNavigate) fm.arrowNavigate('up', e.shiftKey); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); if (fm.arrowNavigate) fm.arrowNavigate('down', e.shiftKey); }
        else if (e.key === 'Enter' && fm._getActiveCardName) {
            // Enter on cursor card = open it (folder → enter; file → edit/preview)
            var name = fm._getActiveCardName();
            if (name) {
                e.preventDefault();
                var card = document.querySelector('#' + getActivePanelId() + ' .fm-card[data-name="' + CSS.escape(name) + '"]');
                if (card) card.click();
            }
        }
    });
});
</script>
<style>@keyframes fm-spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
