<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$upload_dir = __DIR__ . '/../uploads/images/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

ob_start();
?>
<button id="btnUploadTop" class="aicms-icon-btn aicms-icon-btn--primary" onclick="document.getElementById('fileInput').click()"><i class="fas fa-upload"></i><span class="btn-label"><?= t('upload_file') ?></span></button>
<a href="filemanager.php" class="aicms-icon-btn aicms-icon-btn--ghost"><i class="fas fa-folder-tree"></i><span class="btn-label"><?= t('file_manager') ?? 'Файловый менеджер' ?></span></a>
<?php $action_panel = ob_get_clean();
$page_title = t('h_media');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('media')]];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_media') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        /* Media page — AICMS design language */
        .media-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
        }
        .media-search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 360px; }
        .media-search-wrap input {
            width: 100%; height: 36px; padding: 0 36px 0 12px;
            font-size: 13px; border: 1px solid #cbd5e1; border-radius: 8px;
            outline: none; transition: border-color 0.15s, box-shadow 0.15s; box-sizing: border-box;
            background: #fff; color: #1e293b;
        }
        .media-search-wrap input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .media-search-wrap i {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 13px; pointer-events: none;
        }
        .media-filter-select {
            height: 36px; padding: 0 28px 0 10px; font-size: 13px;
            border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
            color: #1e293b; outline: none; cursor: pointer; box-sizing: border-box;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .media-filter-select:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }

        .view-toggle {
            display: inline-flex; background: #f1f5f9; border-radius: 8px; padding: 3px;
        }
        .view-toggle button {
            width: 30px; height: 30px; border: none; background: transparent;
            border-radius: 5px; cursor: pointer; color: #64748b; font-size: 13px;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background-color 0.15s, color 0.15s;
        }
        .view-toggle button.is-active { background: #fff; color: #2563eb; box-shadow: 0 1px 2px rgba(0,0,0,0.08); }

        .media-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px;
            padding: 16px;
        }
        .media-grid.is-list {
            display: block; padding: 8px 16px;
        }
        .media-grid.is-list .media-item {
            display: flex; align-items: center; gap: 12px;
            aspect-ratio: auto; height: 64px; padding: 6px 10px;
            border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 6px;
        }
        .media-grid.is-list .media-item .media-thumb {
            width: 52px; height: 52px; flex-shrink: 0;
        }
        .media-grid.is-list .media-item .media-item-overlay { display: none !important; }
        .media-grid.is-list .media-item .media-item-info {
            display: flex; flex-direction: row; align-items: center; gap: 14px;
            flex: 1; min-width: 0;
        }
        .media-grid.is-list .media-item .media-item-info .file-name {
            font-size: 13px; font-weight: 500; color: #1e293b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 320px;
        }
        .media-grid.is-list .media-item .media-item-info .file-meta {
            font-size: 11px; color: #94a3b8;
        }
        .media-grid.is-list .media-item .media-item-actions {
            display: flex; gap: 4px; opacity: 1 !important;
        }
        .media-grid.is-list .media-item .media-item-actions button {
            width: 32px; height: 32px; border-radius: 6px;
        }
        .media-grid.is-list .media-item .select-checkbox {
            position: static; opacity: 1; width: 16px; height: 16px; cursor: pointer;
        }

        /* Folder cards in list view */
        .media-grid.is-list .media-folder-card {
            display: flex; flex-direction: row; align-items: center; gap: 12px;
            height: 64px; padding: 6px 10px;
            border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 6px;
        }
        .media-grid.is-list .media-folder-card .media-folder-thumb {
            width: 52px; height: 52px; aspect-ratio: auto; flex-shrink: 0; border-radius: 6px;
        }
        .media-grid.is-list .media-folder-card .media-folder-thumb i { font-size: 28px; }
        .media-grid.is-list .media-folder-card .media-folder-info { display: flex; flex-direction: row; align-items: center; gap: 14px; flex: 1; min-width: 0; }
        .media-grid.is-list .media-folder-card .media-folder-info .file-name { font-size: 13px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; }
        .media-grid.is-list .media-folder-card .media-folder-info .file-meta { font-size: 11px; }
        .media-grid.is-list .media-folder-card .media-folder-actions { display: flex; gap: 4px; opacity: 1 !important; position: static; background: none; border: none; padding: 0; margin-top: 0; }
        .media-grid.is-list .media-folder-card .media-folder-actions button { width: 32px; height: 32px; border-radius: 6px; }
        .media-grid.is-list .media-folder-card .select-checkbox { position: static; opacity: 1; width: 16px; height: 16px; cursor: pointer; }
        .media-grid.is-list .media-folder-card .type-badge { display: none; }

        /* Folder cards — shared styles (grid + list) */
        .media-folder-card {
            position: relative; cursor: pointer;
            /* Override .media-item constraints that break folder layout */
            aspect-ratio: auto !important;
            overflow: visible !important;
            background: #fff;
            display: flex;
            flex-direction: column;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            transition: border-color .12s, box-shadow .12s;
        }
        .media-folder-card:hover { border-color: #3b82f6; box-shadow: 0 4px 12px rgba(59,130,246,0.15); }
        .media-folder-card.is-selected { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.35); }
        .media-folder-card .media-folder-thumb {
            width: 100%; aspect-ratio: 1/1; background: linear-gradient(135deg,#fef3c7,#fde68a);
            display: flex; align-items: center; justify-content: center; overflow: hidden;
            border-radius: 6px 6px 0 0;
        }
        .media-folder-card .media-folder-thumb i { font-size: 48px; color: #d97706; }
        .media-folder-card .media-folder-info { padding: 6px 8px 2px; }
        .media-folder-card .media-folder-info .file-name { font-size: 12px; font-weight: 600; color: #1e293b; word-break: break-all; line-height: 1.2; max-height: 2.4em; overflow: hidden; }
        .media-folder-card .media-folder-info .file-meta { padding: 0 8px 4px; font-size: 10px; color: #94a3b8; }
        .media-folder-card .media-folder-actions {
            display: flex; gap: 3px; padding: 4px 8px 8px; border-top: 1px solid #f1f5f9; margin-top: auto;
        }
        .media-folder-card .media-folder-actions button {
            border: none; border-radius: 5px; cursor: pointer; font-size: 11px;
            display: inline-flex; align-items: center; justify-content: center; color: #fff;
            transition: filter .12s;
        }
        .media-folder-card .media-folder-actions button[data-folder-action="open"] { flex: 1; padding: 5px 8px; background: #0891b2; font-weight: 600; gap: 3px; }
        .media-folder-card .media-folder-actions button[data-folder-action="rename"] { padding: 5px 8px; background: #d97706; }
        .media-folder-card .media-folder-actions button[data-folder-action="delete"] { padding: 5px 8px; background: #dc2626; }
        .media-folder-card .media-folder-actions button:hover { filter: brightness(1.15); }
        /* Checkbox — visible on hover or when selected (same as file items) */
        .media-folder-card .select-checkbox {
            position: absolute; top: 8px; left: 8px; width: 20px; height: 20px;
            cursor: pointer; z-index: 2; opacity: 0;
            transition: opacity 0.15s; accent-color: #2563eb;
        }
        .media-folder-card:hover .select-checkbox,
        .media-folder-card.is-selected .select-checkbox { opacity: 1; }
        /* Type badge */
        .media-folder-card .type-badge {
            position: absolute; top: 8px; right: 8px;
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            padding: 2px 6px; border-radius: 4px; color: #fff;
            background: rgba(217,119,6,0.85); backdrop-filter: blur(4px);
        }

        .media-item {
            position: relative; border: 1px solid #e2e8f0; border-radius: 10px;
            overflow: hidden; aspect-ratio: 1 / 1; background: #f8fafc;
            transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
        }
        .media-item:hover {
            border-color: #93c5fd; box-shadow: 0 4px 12px rgba(37,99,235,0.15);
            transform: translateY(-2px);
        }
        .media-item.is-selected {
            border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.35);
        }
        .media-item.focused {
            outline: 3px solid #2563eb; outline-offset: -3px;
            border-color: #2563eb;
        }
        .media-item .media-thumb {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
            background: #f1f5f9;
        }
        .media-item .media-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .media-item .media-thumb .file-icon {
            font-size: 42px; color: #94a3b8;
        }
        .media-item-overlay {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            justify-content: flex-end; align-items: stretch; padding: 8px;
            background: linear-gradient(180deg, transparent 30%, rgba(0,0,0,0.75) 100%);
            opacity: 0; transition: opacity 0.2s;
        }
        .media-item:hover .media-item-overlay { opacity: 1; }
        .media-item .file-name {
            word-break: break-all; font-size: 11px; color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.6); margin-bottom: 6px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .media-item .media-item-actions {
            display: flex; gap: 4px; justify-content: flex-end;
        }
        .media-item .media-item-actions button {
            width: 30px; height: 30px; border: none; border-radius: 6px;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; color: #fff; font-size: 12px;
            background: rgba(255,255,255,0.18); backdrop-filter: blur(4px);
            transition: background-color 0.15s, transform 0.15s;
        }
        .media-item .media-item-actions button:hover { transform: translateY(-1px); }
        .media-item .media-item-actions button.act-copy { background: rgba(37,99,235,0.85); }
        .media-item .media-item-actions button.act-view  { background: rgba(15,23,42,0.7); }
        .media-item .media-item-actions button.act-del   { background: rgba(220,38,38,0.85); }

        .media-item .select-checkbox {
            position: absolute; top: 8px; left: 8px; width: 20px; height: 20px;
            cursor: pointer; z-index: 2; opacity: 0;
            transition: opacity 0.15s; accent-color: #2563eb;
        }
        .media-item:hover .select-checkbox,
        .media-item.is-selected .select-checkbox { opacity: 1; }

        .media-item .type-badge {
            position: absolute; top: 8px; right: 8px;
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            padding: 2px 6px; border-radius: 4px; color: #fff;
            background: rgba(15,23,42,0.6); backdrop-filter: blur(4px);
            letter-spacing: 0.04em;
        }
        .media-item .type-badge.t-image    { background: rgba(37,99,235,0.85); }
        .media-item .type-badge.t-document { background: rgba(220,38,38,0.85); }
        .media-item .type-badge.t-archive  { background: rgba(245,158,11,0.85); }
        .media-item .type-badge.t-video    { background: rgba(139,92,246,0.85); }
        .media-item .type-badge.t-audio    { background: rgba(16,163,74,0.85); }

        /* === Bulk action bar — FAB style, like the document editors ===
           Mirrors the .aicms-fab-bar floating pill from edit_page.php /
           edit_news.php: fixed BOTTOM-RIGHT, dark pill, vertical rubber
           buttons that stretch as ONE block on hover (data-stretch-block,
           handled by the stretch coordinator in aicms-ui.js). JS
           (media-library.js) toggles .is-visible and updates #bulkCount. */
        .media-bulk-bar {
            display: none;
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 1060; /* ниже модалок (1080) — folder picker открывается при активном выделении */
            flex-direction: column;
            align-items: flex-end; /* колонка прижата к правому краю, без подложки */
            gap: 6px;
        }
        .media-bulk-bar.is-visible { display: flex; }
        .media-bulk-bar .count {
            display: inline-flex; align-items: center; gap: 5px;
            color: #475569; font-weight: 500; font-size: 12px;
            padding: 2px 8px 4px; white-space: nowrap;
        }
        .media-bulk-bar .count strong {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 20px; padding: 0 6px;
            background: #2563eb; color: #fff; border-radius: 10px;
            font-weight: 700; font-size: 11px;
        }
        /* Rubber buttons — identical behaviour to .aicms-fab-btn */
        .media-bulk-bar .aicms-fab-btn {
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
        .media-bulk-bar .aicms-fab-btn:hover,
        .media-bulk-bar .aicms-fab-btn.aicms-stretch-keep {
            width: auto; min-width: 48px; max-width: 220px;
            padding: 0 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.28); z-index: 20;
        }
        .media-bulk-bar .aicms-fab-btn i {
            position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
            font-size: 20px; flex-shrink: 0;
            transition: opacity calc(var(--aicms-stretch-duration) * 0.7) var(--aicms-stretch-ease);
        }
        .media-bulk-bar .aicms-fab-btn .btn-label {
            display: block; min-width: 0; max-width: 0; opacity: 0;
            overflow: hidden; white-space: nowrap;
            transition: opacity calc(var(--aicms-stretch-duration) * 0.8) var(--aicms-stretch-label-delay) var(--aicms-stretch-ease),
                        max-width var(--aicms-stretch-duration) var(--aicms-stretch-ease),
                        min-width var(--aicms-stretch-duration) var(--aicms-stretch-ease);
            font-size: 13px; font-weight: 500; line-height: 1; flex-shrink: 1;
        }
        .media-bulk-bar .aicms-fab-btn:hover i,
        .media-bulk-bar .aicms-fab-btn.aicms-stretch-keep i { opacity: 0; }
        .media-bulk-bar .aicms-fab-btn:hover .btn-label,
        .media-bulk-bar .aicms-fab-btn.aicms-stretch-keep .btn-label { opacity: 1; max-width: 170px; }
        /* Variant colours (hover = darker shade, like the editor FAB) */
        .media-bulk-bar .aicms-fab-btn--copy   { background-color: #2563eb; }
        .media-bulk-bar .aicms-fab-btn--copy:hover,
        .media-bulk-bar .aicms-fab-btn--copy.aicms-stretch-keep   { background-color: #1d4ed8; }
        /* Контраст (белая иконка/подпись, WCAG): янтарный и бирюзовый
           затемнены до тёмных ступеней палитры, т.к. базовые #f59e0b
           (2.15:1) и #0891b2 (3.68:1) не дотягивали до 4.5:1 для текста
           подписи. Панель всегда тёмная — фикс актуален для обеих тем. */
        .media-bulk-bar .aicms-fab-btn--move   { background-color: #b45309; } /* amber-700, ≈4.9:1 */
        .media-bulk-bar .aicms-fab-btn--move:hover,
        .media-bulk-bar .aicms-fab-btn--move.aicms-stretch-keep   { background-color: #92400e; } /* amber-800 */
        .media-bulk-bar .aicms-fab-btn--duplicate { background-color: #0e7490; } /* cyan-700, ≈5.4:1 */
        .media-bulk-bar .aicms-fab-btn--duplicate:hover,
        .media-bulk-bar .aicms-fab-btn--duplicate.aicms-stretch-keep { background-color: #155e75; } /* cyan-800 */
        .media-bulk-bar .aicms-fab-btn--danger { background-color: #dc2626; }
        .media-bulk-bar .aicms-fab-btn--danger:hover,
        .media-bulk-bar .aicms-fab-btn--danger.aicms-stretch-keep { background-color: #b91c1c; }
        .media-bulk-bar .aicms-fab-btn--neutral { background-color: #475569; }
        .media-bulk-bar .aicms-fab-btn--neutral:hover,
        .media-bulk-bar .aicms-fab-btn--neutral.aicms-stretch-keep { background-color: #334155; }

        .preview-modal {
            position: fixed; inset: 0; background: rgba(0,0,0,0.8);
            display: none; align-items: center; justify-content: center;
            z-index: 1080; padding: 32px;
        }
        .preview-modal.is-open { display: flex; }
        .preview-modal-inner {
            background: #fff; border-radius: 12px; max-width: 90vw; max-height: 90vh;
            display: flex; flex-direction: column; overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
        }
        .preview-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 18px; border-bottom: 1px solid #e2e8f0;
        }
        .preview-modal-header h4 { margin: 0; font-size: 14px; font-weight: 600; color: #1e293b; }
        .preview-modal-close {
            width: 28px; height: 28px; border-radius: 6px; border: none;
            background: transparent; color: #64748b; cursor: pointer; font-size: 18px;
        }
        .preview-modal-close:hover { background: #f1f5f9; }
        .preview-modal-body {
            flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center;
            background: #f8fafc; min-height: 300px;
        }
        .preview-modal-body img { max-width: 100%; max-height: 70vh; object-fit: contain; }
        .preview-modal-footer {
            padding: 10px 18px; border-top: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            font-size: 12px; color: #64748b;
        }
        .preview-modal-footer .info-block { display: flex; flex-direction: column; gap: 1px; }
        .preview-modal-footer .info-block strong { color: #1e293b; font-size: 12px; }
        .preview-modal-footer .copy-url-input {
            flex: 1; min-width: 200px; height: 32px; padding: 0 10px;
            border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;
            font-family: 'Fira Code', 'Consolas', monospace; color: #475569;
            outline: none; background: #f8fafc;
        }

        body.dark-theme .media-search-wrap input,
        body.dark-theme .media-filter-select { background: #0f172a; color: #e2e8f0; border-color: #475569; }
        body.dark-theme .view-toggle { background: #0f172a; }
        body.dark-theme .view-toggle button { color: #94a3b8; }
        body.dark-theme .view-toggle button.is-active { background: #1e293b; color: #60a5fa; }
        body.dark-theme .media-item { background: #0f172a; border-color: #334155; }
        body.dark-theme .media-item:hover { border-color: #3b82f6; }
        body.dark-theme .media-item .media-thumb { background: #0f172a; }
        /* Folder cards in dark theme */
        body.dark-theme .media-folder-card { background: #1e293b; border-color: #334155; }
        body.dark-theme .media-folder-card:hover { border-color: #3b82f6; }
        body.dark-theme .media-folder-card.is-selected { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.35); }
        body.dark-theme .media-folder-thumb { background: linear-gradient(135deg,#422006,#713f12) !important; }
        body.dark-theme .media-folder-thumb i { color: #fbbf24 !important; }
        body.dark-theme .media-folder-info .file-name { color: #e2e8f0 !important; }
        body.dark-theme .media-folder-info .file-meta { color: #64748b; }
        body.dark-theme .media-folder-actions { border-top-color: #334155 !important; }
        /* Folder picker modal dark theme */
        body.dark-theme #folderPickerCrumbs,
        body.dark-theme #folderPickerList { color: #e2e8f0; }
        body.dark-theme #folderPickerCrumbs button { color: #cbd5e1; }
        body.dark-theme #folderPickerCrumbs button:hover { background: #334155; }
        body.dark-theme #folderPickerList button { color: #e2e8f0; }
        body.dark-theme #folderPickerList button:hover { background: #1e293b; }
        body.dark-theme #folderPickerNewName { background: #0f172a; color: #e2e8f0; border-color: #475569; }
        body.dark-theme #newFolderName { background: #0f172a; color: #e2e8f0; border-color: #475569; }
        body.dark-theme .preview-modal-inner { background: #1e293b; }
        body.dark-theme .preview-modal-header { border-bottom-color: #334155; }
        body.dark-theme .preview-modal-header h4 { color: #e2e8f0; }
        body.dark-theme .preview-modal-close { color: #94a3b8; }
        body.dark-theme .preview-modal-close:hover { background: #334155; }
        body.dark-theme .preview-modal-body { background: #0f172a; }
        body.dark-theme .preview-modal-footer { border-top-color: #334155; color: #94a3b8; }
        body.dark-theme .preview-modal-footer .info-block strong { color: #e2e8f0; }
        body.dark-theme .preview-modal-footer .copy-url-input { background: #0f172a; color: #cbd5e1; border-color: #475569; }
        body.dark-theme .media-grid.is-list .media-item .media-item-info .file-name { color: #e2e8f0; }
        body.dark-theme .media-bulk-bar .count { color: #cbd5e1; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5" id="mediaStats">
            <div class="aicms-stat-card flex items-center">
                <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mr-3 text-lg"><i class="fas fa-photo-film"></i></div>
                <div>
                    <h5 class="text-xl font-bold text-slate-800 m-0" data-stat="total">—</h5>
                    <p class="text-slate-500 text-xs m-0"><?= t('total_files') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-11 h-11 rounded-xl bg-green-50 text-green-600 flex items-center justify-center mr-3 text-lg"><i class="fas fa-image"></i></div>
                <div>
                    <h5 class="text-xl font-bold text-slate-800 m-0" data-stat="image">—</h5>
                    <p class="text-slate-500 text-xs m-0"><?= t('images_label') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center mr-3 text-lg"><i class="fas fa-file-archive"></i></div>
                <div>
                    <h5 class="text-xl font-bold text-slate-800 m-0" data-stat="other">—</h5>
                    <p class="text-slate-500 text-xs m-0"><?= t('other_files') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-11 h-11 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center mr-3 text-lg"><i class="fas fa-hdd"></i></div>
                <div>
                    <h5 class="text-lg font-bold text-slate-800 m-0" data-stat="total_size">—</h5>
                    <p class="text-slate-500 text-xs m-0"><?= t('total_size') ?></p>
                </div>
            </div>
        </div>

        <!-- Upload progress -->
        <div id="upload-progress-container" class="w-full bg-slate-200 rounded-full h-1.5 mb-4" style="display:none;">
            <div id="upload-progress-bar" class="bg-blue-600 h-full rounded-full transition-all" style="width:0%;"></div>
        </div>

        <!-- Media library card -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <!-- Breadcrumbs + folder actions -->
            <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-200 bg-slate-50 flex-wrap">
                <div id="mediaBreadcrumbs" class="flex items-center gap-1 text-sm flex-wrap">
                    <span class="text-slate-400"><i class="fas fa-folder-open"></i></span>
                    <span class="text-slate-400">Загрузка…</span>
                </div>
                <div class="ml-auto flex gap-1">
                    <button id="btnNewFolder" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-folder-plus"></i><span class="btn-label"><?= t('new_folder') ?? 'Новая папка' ?></span></button>
                    <button id="btnUploadUrl" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-link"></i><span class="btn-label"><?= t('upload_by_url') ?></span></button>
                    <button id="btnUpload" class="aicms-btn aicms-btn--primary aicms-btn--sm"><i class="fas fa-upload"></i><span class="btn-label"><?= t('upload') ?></span></button>
                    <input type="file" id="fileInput" multiple style="display:none;">
                </div>
            </div>
            <!-- Toolbar -->
            <div class="media-toolbar">
                <div class="media-search-wrap">
                    <input type="text" id="mediaSearch" placeholder="<?= t('search_files') ?>" autocomplete="off">
                    <i class="fas fa-search"></i>
                </div>
                <select id="mediaTypeFilter" class="media-filter-select" title="<?= t('type') ?>">
                    <option value=""><?= t('all_types') ?></option>
                    <option value="image"><?= t('images') ?></option>
                    <option value="document"><?= t('documents') ?></option>
                    <option value="archive"><?= t('archives') ?></option>
                    <option value="video"><?= t('videos') ?></option>
                    <option value="audio"><?= t('audio') ?></option>
                    <option value="other"><?= t('other') ?></option>
                </select>
                <select id="mediaSort" class="media-filter-select" title="<?= t('status') ?>">
                    <option value="modified-desc"><?= t('sort_newest') ?></option>
                    <option value="modified-asc"><?= t('sort_oldest') ?></option>
                    <option value="name-asc"><?= t('sort_name_asc') ?></option>
                    <option value="name-desc"><?= t('sort_name_desc') ?></option>
                    <option value="size-desc"><?= t('sort_size_desc') ?></option>
                    <option value="size-asc"><?= t('sort_size_asc') ?></option>
                </select>
                <div class="view-toggle ml-auto" role="group" aria-label="<?= t('status') ?>">
                    <button id="viewGrid" class="is-active" title="<?= t('view_grid') ?>"><i class="fas fa-th"></i></button>
                    <button id="viewList" title="<?= t('view_list') ?>"><i class="fas fa-list"></i></button>
                </div>
            </div>

            <!-- Grid -->
            <div id="mediaGrid" class="media-grid">
                <div class="col-span-full text-center py-8 text-slate-400">
                    <div class="inline-block w-8 h-8 border-4 border-slate-200 border-t-blue-600 rounded-full animate-spin"></div>
                    <p class="mt-2 text-sm"><?= t('upload_progress') ?></p>
                </div>
            </div>

            <!-- Bulk action bar — FAB style (bottom-right, like the editors) -->
            <div class="media-bulk-bar" id="mediaBulkBar" data-stretch-block="1">
                <span class="count"><i class="fas fa-check-square"></i> <?= t('selected_count') ?> <strong id="bulkCount">0</strong></span>
                <button type="button" class="aicms-fab-btn aicms-fab-btn--copy" id="bulkCopyUrl" aria-label="<?= h(t('copy_url')) ?>"><i class="fas fa-link"></i><span class="btn-label"><?= h(t('copy_url')) ?></span></button>
                <button type="button" class="aicms-fab-btn aicms-fab-btn--move" id="bulkMove" aria-label="<?= h(t('move') ?? 'Переместить') ?>"><i class="fas fa-arrows-alt"></i><span class="btn-label"><?= h(t('move') ?? 'Переместить') ?></span></button>
                <button type="button" class="aicms-fab-btn aicms-fab-btn--duplicate" id="bulkCopy" aria-label="<?= h(t('copy') ?? 'Копировать') ?>"><i class="fas fa-copy"></i><span class="btn-label"><?= h(t('copy') ?? 'Копировать') ?></span></button>
                <button type="button" class="aicms-fab-btn aicms-fab-btn--danger" id="bulkDelete" aria-label="<?= h(t('delete_selected_btn')) ?>"><i class="fas fa-trash"></i><span class="btn-label"><?= h(t('delete_selected_btn')) ?></span></button>
                <button type="button" class="aicms-fab-btn aicms-fab-btn--neutral" id="bulkClear" aria-label="<?= h(t('clear_selection')) ?>"><i class="fas fa-times"></i><span class="btn-label"><?= h(t('clear_selection')) ?></span></button>
            </div>
        </div>
        </div>
    </div>
</div>

<!-- Preview modal -->
<div class="preview-modal" id="previewModal">
    <div class="preview-modal-inner">
        <div class="preview-modal-header">
            <h4 id="previewTitle"><?= t('preview_title') ?></h4>
            <button class="preview-modal-close" id="previewClose">&times;</button>
        </div>
        <div class="preview-modal-body" id="previewBody"></div>
        <div class="preview-modal-footer" id="previewFooter"></div>
    </div>
</div>

<!-- URL upload modal -->
<div class="preview-modal" id="urlModal">
    <div class="preview-modal-inner" style="max-width: 480px;">
        <div class="preview-modal-header">
            <h4><?= t('upload_by_url') ?></h4>
            <button class="preview-modal-close" id="urlClose">&times;</button>
        </div>
        <div class="preview-modal-body" style="display: block; padding: 20px; min-height: auto;">
            <label class="block text-sm font-medium text-slate-700 mb-2"><?= t('url_label') ?></label>
            <input type="url" id="urlInput" placeholder="<?= t('url_ph') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" style="background:#fff; color:#1e293b;">
            <p class="mt-3 text-xs text-slate-400"><?= t('url_help') ?></p>
        </div>
        <div class="preview-modal-footer" style="justify-content: flex-end;">
            <button class="aicms-icon-btn aicms-icon-btn--secondary " id="urlCancel"><i class="fas fa-times"></i><span class="btn-label"><?= t('cancel') ?></span></button>
            <button class="aicms-icon-btn aicms-icon-btn--primary " id="urlSubmit"><i class="fas fa-download"></i><span class="btn-label"><?= t('upload') ?></span></button>
        </div>
    </div>
</div>

<!-- Folder picker modal (for move/copy) -->
<div class="preview-modal" id="folderPickerModal">
    <div class="preview-modal-inner" style="max-width: 500px;">
        <div class="preview-modal-header">
            <h4 id="folderPickerTitle"><?= t('select_folder') ?? 'Выберите папку' ?></h4>
            <button class="preview-modal-close" id="folderPickerClose">&times;</button>
        </div>
        <div class="preview-modal-body" style="display: block; padding: 16px; min-height: auto; max-height: 60vh; overflow-y: auto;">
            <!-- Breadcrumbs of target folder -->
            <div id="folderPickerCrumbs" class="flex items-center gap-1 text-sm mb-3 pb-3 border-b border-slate-200 flex-wrap">
                <span class="text-slate-400">Загрузка…</span>
            </div>
            <!-- Folder list -->
            <div id="folderPickerList" class="space-y-1">
                <div class="text-center text-slate-400 py-4 text-sm">Нет подпапок</div>
            </div>
            <!-- New folder input -->
            <div class="mt-3 pt-3 border-t border-slate-200 flex gap-2">
                <input type="text" id="folderPickerNewName" placeholder="<?= t('new_folder_name_ph') ?? 'Новая папка' ?>" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm" style="background:#fff; color:#1e293b;">
                <button id="folderPickerNewBtn" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-plus"></i></button>
            </div>
        </div>
        <div class="preview-modal-footer" style="justify-content: flex-end;">
            <button class="aicms-icon-btn aicms-icon-btn--secondary " id="folderPickerCancel"><i class="fas fa-times"></i><span class="btn-label"><?= t('cancel') ?></span></button>
            <button class="aicms-icon-btn aicms-icon-btn--primary " id="folderPickerConfirm"><i class="fas fa-check"></i><span class="btn-label"><?= t('confirm_btn') ?></span></button>
        </div>
    </div>
</div>

<!-- New folder modal -->
<div class="preview-modal" id="newFolderModal">
    <div class="preview-modal-inner" style="max-width: 420px;">
        <div class="preview-modal-header">
            <h4><?= t('new_folder') ?? 'Новая папка' ?></h4>
            <button class="preview-modal-close" id="newFolderClose">&times;</button>
        </div>
        <div class="preview-modal-body" style="display: block; padding: 20px; min-height: auto;">
            <label class="block text-sm font-medium text-slate-700 mb-2"><?= t('folder_name_label') ?? 'Имя папки' ?></label>
            <input type="text" id="newFolderName" placeholder="my-folder" class="w-full px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 text-sm" style="background:#fff; color:#1e293b;" autocomplete="off">
            <p class="mt-2 text-xs text-slate-400"><?= t('folder_name_help') ?? 'Только буквы, цифры, дефис и подчёркивание' ?></p>
        </div>
        <div class="preview-modal-footer" style="justify-content: flex-end;">
            <button class="aicms-icon-btn aicms-icon-btn--secondary " id="newFolderCancel"><i class="fas fa-times"></i><span class="btn-label"><?= t('cancel') ?></span></button>
            <button class="aicms-icon-btn aicms-icon-btn--primary " id="newFolderCreate"><i class="fas fa-folder-plus"></i><span class="btn-label"><?= t('create_btn') ?? 'Создать' ?></span></button>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script src="assets/js/media-library.js?v=20260731"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initMediaLibrary({
        container: '#mediaGrid',
        uploadButton: '#btnUpload',
        fileInput: '#fileInput',
        progressBarContainer: '#upload-progress-container', progressBar: '#upload-progress-bar',
        isModal: false,
        csrfToken: "<?= csrf_field_value() ?>",
        searchInput: '#mediaSearch',
        typeFilter: '#mediaTypeFilter',
        sortSelect: '#mediaSort',
        viewGridBtn: '#viewGrid',
        viewListBtn: '#viewList',
        bulkBar: '#mediaBulkBar',
        bulkCount: '#bulkCount',
        bulkCopyBtn: '#bulkCopyUrl',
        bulkDeleteBtn: '#bulkDelete',
        bulkClearBtn: '#bulkClear',
        bulkMoveBtn: '#bulkMove',
        bulkCopyFilesBtn: '#bulkCopy',
        newFolderBtn: '#btnNewFolder',
        createUrlBtn: '#btnUploadUrl',
        statsContainer: '#mediaStats',
        urlModal: '#urlModal',
        urlInput: '#urlInput',
        urlSubmit: '#urlSubmit',
        urlCancel: '#urlCancel',
        urlClose: '#urlClose',
        previewModal: '#previewModal',
        previewTitle: '#previewTitle',
        previewBody: '#previewBody',
        previewFooter: '#previewFooter',
        previewClose: '#previewClose'
    });
});
</script>
</body>
</html>
