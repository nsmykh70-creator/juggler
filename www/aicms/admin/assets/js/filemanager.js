/**
 * File Manager — Total Commander style
 * =====================================
 * Features:
 *   - Two independent panels, each with its own root selector + breadcrumbs
 *   - Selection-based operations (click to select, then F-keys or buttons)
 *   - Function button bar: New, Mkdir, Upload, Rename, Copy, Move, Delete, ZIP, Unzip, Edit
 *   - Drag-drop: file→folder (same panel), file→panel (cross-panel move)
 *   - Keyboard: F2 rename, F5 copy, F6 move, F8/Delete delete, F7 mkdir, Insert select
 *   - Monaco code editor for text files
 *   - Image lightbox
 *   - Search filter per panel
 *   - Cross-panel copy/move (source = active panel, target = other panel)
 */

function initFileManager(opts) {
    var container = document.querySelector(opts.container);
    if (!container) return null;
    var csrf = opts.csrfToken;
    var I = window.I18N || {};
    function tr(key, fallback) { return I[key] || fallback || key; }
    function trf(key) {
        var s = I[key] || key;
        if (arguments.length < 2) return s;
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return s.replace(/%s/g, function() { return args[i++] !== undefined ? args[i-1] : '%s'; });
    }
    var currentPath = '';
    var currentRoot = 'uploads';
    var allFolders = [];
    var allFiles = [];
    var selectedCard = null;
    var selectedNames = new Set();      // multi-select set of names
    var lastSelectedName = null;         // for Shift-click range select
    var editorFile = { name: '', path: '' };
    var moveOp = { type: '', name: '', fromPath: '', fromRoot: '' };
    var bulkMoveOp = { fromPath: '', fromRoot: '', names: [] };
    var panelEl = opts.panel ? document.querySelector(opts.panel) : null;
    var crumbsEl = opts.crumbs ? document.querySelector(opts.crumbs) : null;
    var rootSelectEl = opts.rootSelect ? document.querySelector(opts.rootSelect) : null;
    var backBtnEl = opts.backBtn ? document.querySelector(opts.backBtn) : null;

    // File input reference (shared)
    var fileInputEl = document.getElementById('fmFileInput');

    var path_ = window.location.pathname;
    var lastSlash = path_.lastIndexOf('/');
    var adminBase = path_.substring(0, lastSlash + 1);

    function apiGet(action, params) {
        var url = adminBase + 'api_filemanager.php?action=' + action + '&csrf_token=' + encodeURIComponent(csrf) + '&root=' + encodeURIComponent(currentRoot);
        if (params) { Object.keys(params).forEach(function(k) {
            if (Array.isArray(params[k])) {
                params[k].forEach(function(v) { url += '&' + k + '[]=' + encodeURIComponent(v); });
            } else {
                url += '&' + k + '=' + encodeURIComponent(params[k]);
            }
        }); }
        return fetch(url).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text().then(function(text) { if (!text) throw new Error('Пустой ответ'); return JSON.parse(text); });
        });
    }
    function apiPost(action, formData) {
        formData.append('action', action);
        formData.append('csrf_token', csrf);
        formData.append('root', currentRoot);
        return fetch(adminBase + 'api_filemanager.php', { method: 'POST', body: formData }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text().then(function(text) { if (!text) throw new Error('Пустой ответ'); return JSON.parse(text); });
        });
    }
    // POST with explicit root override (for cross-root bulk operations)
    function apiPostWithRoot(action, formData, rootName) {
        formData.append('action', action);
        formData.append('csrf_token', csrf);
        formData.append('root', rootName);
        return fetch(adminBase + 'api_filemanager.php', { method: 'POST', body: formData }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text().then(function(text) { if (!text) throw new Error('Пустой ответ'); return JSON.parse(text); });
        });
    }

    function escapeHtml(s) { if (!s) return ''; return String(s).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    // --- Bulk Progress Overlay ---
    // Shows a non-blocking overlay with an animated progress bar during long-running bulk operations.
    // Usage:
    //   var prog = showBulkProgress('Удаление файлов', names.length);
    //   prog.update(5, 'Удалено 5 из 12');
    //   prog.done();   // closes with success flash
    //   prog.error();  // closes with error flash
    function showBulkProgress(title, totalCount) {
        // Remove any existing overlay
        var existing = document.getElementById('fmBulkProgressOverlay');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'fmBulkProgressOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:100000;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;';
        overlay.innerHTML = `
            <div style="background:#fff;border-radius:12px;padding:24px;width:420px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                    <i class="fas fa-cog fa-spin" style="color:#2563eb;font-size:18px;"></i>
                    <h4 style="margin:0;font-size:16px;color:#1e293b;font-weight:600;">${escapeHtml(title)}</h4>
                </div>
                <div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;margin-bottom:8px;">
                    <div id="fmBulkProgressBar" style="background:linear-gradient(90deg,#3b82f6,#2563eb);height:100%;width:5%;transition:width 0.3s ease;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b;">
                    <span id="fmBulkProgressLabel">Подготовка...</span>
                    <span id="fmBulkProgressCount">0 / ${totalCount}</span>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        var bar = overlay.querySelector('#fmBulkProgressBar');
        var label = overlay.querySelector('#fmBulkProgressLabel');
        var countEl = overlay.querySelector('#fmBulkProgressCount');
        var startTime = Date.now();
        var _done = false;

        // Animate the bar while waiting (indeterminate bump if no progress updates)
        var animInterval = setInterval(function() {
            if (_done) return;
            var w = parseFloat(bar.style.width) || 0;
            if (w < 90 && totalCount === 0) {
                bar.style.width = (w + 5 > 90 ? 90 : w + 5) + '%';
            }
        }, 300);

        return {
            update: function(done, msg) {
                if (_done) return;
                if (totalCount > 0) {
                    var pct = Math.min(100, Math.round((done / totalCount) * 100));
                    bar.style.width = pct + '%';
                    countEl.textContent = done + ' / ' + totalCount;
                } else {
                    // Single-shot op — just bump to 50%
                    if (parseFloat(bar.style.width) < 50) bar.style.width = '50%';
                }
                if (msg) label.textContent = msg;
            },
            done: function(msg) {
                if (_done) return;
                _done = true;
                clearInterval(animInterval);
                bar.style.width = '100%';
                bar.style.background = 'linear-gradient(90deg,#10b981,#059669)';
                label.textContent = msg || 'Готово';
                setTimeout(function() { overlay.remove(); }, 600);
            },
            error: function(msg) {
                if (_done) return;
                _done = true;
                clearInterval(animInterval);
                bar.style.background = '#dc2626';
                label.textContent = msg || 'Ошибка';
                setTimeout(function() { overlay.remove(); }, 1500);
            }
        };
    }

    function toast(msg, type) {
        type = type || 'info';
        var t = document.createElement('div');
        t.className = 'fm-toast fm-toast-' + type;
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('visible'); });
        setTimeout(function() { t.classList.remove('visible'); setTimeout(function() { t.remove(); }, 300); }, 3000);
    }

    function loadFolder(path) {
        currentPath = path || '';
        selectedNames.clear();
        lastSelectedName = null;
        selectedCard = null;
        updateBulkBar();
        container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#64748b;"><div style="width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:fm-spin .8s linear infinite;margin:0 auto 10px;"></div></div>';
        apiGet('list', { path: currentPath }).then(function(data) {
            if (data.status !== 'success') throw new Error(data.message);
            allFolders = data.folders;
            allFiles = data.files;
            renderBreadcrumbs(data.breadcrumbs);
            renderGrid(data);
        }).catch(function(err) {
            container.innerHTML = '<div class="fm-empty">' + escapeHtml(tr('fm_error', 'Error') + ': ' + err.message) + '</div>';
        });
    }

    function renderBreadcrumbs(crumbs) {
        if (!crumbsEl) return;
        crumbsEl.innerHTML = crumbs.map(function(c, i) {
            var isLast = i === crumbs.length - 1;
            var sep = i > 0 ? '<span style="color:#64748b;">/</span>' : '';
            if (isLast) return sep + '<span class="fm-crumb-current">' + escapeHtml(c.name) + '</span>';
            return sep + '<a class="fm-crumb" data-path="' + escapeHtml(c.path) + '">' + escapeHtml(c.name) + '</a>';
        }).join('');

        // Update the Back button state: disabled when at root (only 1 crumb),
        // enabled when inside a subfolder (2+ crumbs).
        if (backBtnEl) {
            var atRoot = crumbs.length <= 1;
            backBtnEl.disabled = atRoot;
        }
    }

    function getIconForFile(f) {
        if (f.is_image) return '<i class="fas fa-image fm-icon fm-icon-img"></i>';
        if (f.ext === 'zip' || f.ext === 'rar' || f.ext === '7z' || f.ext === 'tar' || f.ext === 'gz') return '<i class="fas fa-file-archive fm-icon fm-icon-zip"></i>';
        if (f.is_video) return '<i class="fas fa-file-video fm-icon" style="color:#7c3aed;"></i>';
        if (f.is_text) return '<i class="fas fa-file-code fm-icon fm-icon-code"></i>';
        if (f.ext === 'pdf') return '<i class="fas fa-file-pdf fm-icon" style="color:#dc2626;"></i>';
        return '<i class="fas fa-file fm-icon" style="color:#94a3b8;"></i>';
    }

    function renderGrid(data) {
        var foldersHtml = data.folders.length ? data.folders.map(function(f) {
            var isSel = selectedNames.has(f.name);
            return '<div class="fm-card' + (isSel ? ' selected' : '') + '" draggable="true" data-type="folder" data-name="' + escapeHtml(f.name) + '" data-path="' + escapeHtml(f.path) + '">' +
                '<div class="fm-card-checkbox" data-name="' + escapeHtml(f.name) + '"><i class="fas ' + (isSel ? 'fa-check-square' : 'fa-square') + '"></i></div>' +
                '<div class="fm-thumb"><i class="fas fa-folder fm-icon fm-icon-folder"></i></div>' +
                '<div class="fm-name" title="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</div>' +
                '<div class="fm-meta">' + tr('fm_folder', 'Folder') + '</div></div>';
        }).join('') : '';

        var filesHtml = data.files.length ? data.files.map(function(f) {
            var thumb = f.is_image ? '<img src="..' + f.url + '" alt="' + escapeHtml(f.name) + '" loading="lazy">' : getIconForFile(f);
            var isSel = selectedNames.has(f.name);
            return '<div class="fm-card' + (isSel ? ' selected' : '') + '" draggable="true" data-type="file" data-name="' + escapeHtml(f.name) + '" data-url="' + escapeHtml(f.url) + '" data-ext="' + escapeHtml(f.ext) + '" data-is-text="' + (f.is_text ? '1' : '0') + '" data-is-zip="' + (f.ext === 'zip' ? '1' : '0') + '">' +
                '<div class="fm-card-checkbox" data-name="' + escapeHtml(f.name) + '"><i class="fas ' + (isSel ? 'fa-check-square' : 'fa-square') + '"></i></div>' +
                '<div class="fm-thumb" ' + (f.is_image ? 'data-img="' + escapeHtml(f.url) + '"' : '') + '>' + thumb + '</div>' +
                '<div class="fm-name" title="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</div>' +
                '<div class="fm-meta">' + escapeHtml(f.size_h) + ' · ' + escapeHtml(f.ext.toUpperCase()) + '</div></div>';
        }).join('') : '';

        var empty = (!data.folders.length && !data.files.length) ?
            '<div class="fm-empty"><i class="fas fa-folder-open text-4xl text-slate-300 mb-2 block"></i><p>' + tr('fm_empty', 'Empty') + '</p></div>' : '';

        container.innerHTML = '<div class="fm-grid">' + foldersHtml + filesHtml + empty + '</div>';
        // Re-mark selected card if it still exists
        if (selectedCard) {
            var sel = container.querySelector('.fm-card[data-name="' + CSS.escape(selectedCard.dataset.name) + '"]');
            if (sel) selectedCard = sel; else selectedCard = null;
        }
        updateBulkBar();
    }

    // === Multi-select helpers ===
    function getOrderedNames() {
        // Build ordered list of names as they appear in the DOM (folders first, then files)
        var names = [];
        container.querySelectorAll('.fm-card').forEach(function(card) { names.push(card.dataset.name); });
        return names;
    }

    function selectRange(targetName) {
        if (!lastSelectedName) {
            toggleSelect(targetName, true);
            return;
        }
        var ordered = getOrderedNames();
        var i1 = ordered.indexOf(lastSelectedName);
        var i2 = ordered.indexOf(targetName);
        if (i1 === -1 || i2 === -1) { toggleSelect(targetName, true); return; }
        var from = Math.min(i1, i2), to = Math.max(i1, i2);
        for (var i = from; i <= to; i++) {
            selectedNames.add(ordered[i]);
        }
        refreshCardSelection();
        updateBulkBar();
    }

    function toggleSelect(name, forceState) {
        // forceState: true (select), false (deselect), undefined (toggle)
        if (forceState === true) selectedNames.add(name);
        else if (forceState === false) selectedNames.delete(name);
        else if (selectedNames.has(name)) selectedNames.delete(name);
        else selectedNames.add(name);
        refreshCardSelection();
        updateBulkBar();
    }

    function refreshCardSelection() {
        container.querySelectorAll('.fm-card').forEach(function(card) {
            var name = card.dataset.name;
            var isSel = selectedNames.has(name);
            card.classList.toggle('selected', isSel);
            var cb = card.querySelector('.fm-card-checkbox i');
            if (cb) cb.className = 'fas ' + (isSel ? 'fa-check-square' : 'fa-square');
        });
    }

    function clearSelection() {
        selectedNames.clear();
        lastSelectedName = null;
        refreshCardSelection();
        updateBulkBar();
    }

    function selectAll() {
        var ordered = getOrderedNames();
        if (selectedNames.size === ordered.length) {
            // All selected → deselect all
            selectedNames.clear();
        } else {
            ordered.forEach(function(n) { selectedNames.add(n); });
        }
        refreshCardSelection();
        updateBulkBar();
    }

    function getSelectedNames() {
        return Array.from(selectedNames);
    }

    // === Bulk action bar update ===
    function updateBulkBar() {
        // Find or create bulk bar inside this panel
        var bar = panelEl ? panelEl.querySelector('.fm-bulk-bar') : null;
        if (!bar) return;
        var count = selectedNames.size;
        if (count > 0) {
            bar.classList.add('is-visible');
            var countEl = bar.querySelector('.fm-bulk-count');
            if (countEl) countEl.textContent = count;
        } else {
            bar.classList.remove('is-visible');
        }
    }

    // === Click handler: support single / Ctrl / Shift / checkbox clicks ===
    container.addEventListener('click', function(e) {
        var checkbox = e.target.closest('.fm-card-checkbox');
        var card = e.target.closest('.fm-card');

        // Checkbox click — toggles selection without affecting other selections
        if (checkbox && card) {
            e.stopPropagation();
            e.preventDefault();
            var name = card.dataset.name;
            toggleSelect(name);
            lastSelectedName = name;
            return;
        }

        if (!card) {
            // Click on empty area — clear selection
            if (selectedNames.size > 0) { clearSelection(); }
            if (selectedCard) { selectedCard.classList.remove('selected'); selectedCard = null; }
            return;
        }

        // Ctrl/Cmd+click — toggles this card in multi-select
        if (e.ctrlKey || e.metaKey) {
            var name = card.dataset.name;
            toggleSelect(name);
            lastSelectedName = name;
            // Also set as primary "selectedCard" for single-item ops (rename/etc.)
            if (selectedCard) selectedCard.classList.remove('selected');
            selectedCard = card;
            card.classList.add('selected');
            return;
        }

        // Shift+click — range select
        if (e.shiftKey) {
            selectRange(card.dataset.name);
            return;
        }

        // Plain click — single selection (clear others)
        if (selectedNames.size > 0) clearSelection();
        if (selectedCard) selectedCard.classList.remove('selected');
        selectedCard = card;
        card.classList.add('selected');

        // For image cards — single click shows lightbox
        if (card.querySelector('[data-img]')) {
            showLightbox('..' + card.dataset.url);
        }
    });

    container.addEventListener('dblclick', function(e) {
        var card = e.target.closest('.fm-card');
        if (!card) return;
        if (card.dataset.type === 'folder') {
            loadFolder(card.dataset.path);
        } else if (card.dataset.isText === '1') {
            openEditor(card.dataset.name);
        }
    });

    // === Right-click context menu ===
    container.addEventListener('contextmenu', function(e) {
        var card = e.target.closest('.fm-card');
        if (!card) return;
        e.preventDefault();
        showContextMenu(e, card.dataset.name, card.dataset.type, card);
    });

    // Breadcrumb clicks
    if (crumbsEl) {
        crumbsEl.addEventListener('click', function(e) {
            var crumb = e.target.closest('.fm-crumb');
            if (crumb) { e.preventDefault(); loadFolder(crumb.dataset.path); }
        });
    }

    // Back button — navigate to parent directory
    if (backBtnEl) {
        backBtnEl.addEventListener('click', function(e) {
            e.preventDefault();
            if (backBtnEl.disabled) return;
            // Compute parent path from currentPath
            if (!currentPath) return;
            var parts = currentPath.split('/');
            parts.pop(); // remove last segment
            var parentPath = parts.join('/');
            loadFolder(parentPath);
        });
    }

    // Root selector
    if (rootSelectEl) {
        rootSelectEl.addEventListener('change', function(e) {
            currentRoot = e.target.value;
            currentPath = '';
            loadFolder('');
        });
    }

    // === Get selected item name (or null) ===
    function getSelectedName() {
        // Prefer multi-select if exactly one is selected; else use selectedCard
        if (selectedNames.size === 1) return Array.from(selectedNames)[0];
        if (selectedCard) return selectedCard.dataset.name;
        toast(tr('fm_select_item', 'Select file or folder'), 'info');
        return null;
    }

    // === Public operations (called by function buttons) ===
    function newFolder() {
        var name = prompt(tr('fm_new_folder_ph', 'New folder name') + ':', 'new-folder');
        if (!name) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('name', name);
        apiPost('mkdir', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function newFile() {
        var name = prompt(tr('fm_new_file_ph', 'New file name') + ':', 'new-file.txt');
        if (!name) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('name', name); fd.append('content', '');
        apiPost('save', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function uploadClick() { if (fileInputEl) fileInputEl.click(); }

    function uploadFiles(files) {
        if (!files || !files.length) return;
        var fd = new FormData();
        for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
        fd.append('path', currentPath);
        apiPost('upload', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function renameSelected() {
        var name = getSelectedName(); if (!name) return;
        var newName = prompt(tr('fm_new_name', 'New name') + ':', name);
        if (!newName || newName === name) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('old_name', name); fd.append('new_name', newName);
        apiPost('rename', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function deleteSelected() {
        // If multi-select is active, do bulk delete
        if (selectedNames.size > 1) {
            return bulkDelete();
        }
        // Single delete (legacy)
        var name = getSelectedName(); if (!name) return;
        if (!confirm(trf('fm_delete_confirm', name))) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('name', name);
        apiPost('delete', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function zipSelected() {
        // If multi-select active, use bulk_zip
        if (selectedNames.size > 1) return bulkZip();
        var name = getSelectedName(); if (!name) return;
        var zipName = prompt(tr('fm_archive_name', 'Archive name') + ':', name);
        if (!zipName) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('zip_name', zipName); fd.append('names[]', name);
        apiPost('zip', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function unzipSelected() {
        var name = getSelectedName(); if (!name) return;
        var fd = new FormData(); fd.append('path', currentPath); fd.append('name', name);
        apiPost('unzip', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    }

    function editSelected() {
        var name = getSelectedName(); if (!name) return;
        openEditor(name);
    }

    // === GET URL — show modal with file's public URL + copy + QR ===
    function getUrl() {
        var name = getSelectedName(); if (!name) return;
        apiGet('get_url', { path: currentPath, name: name }).then(function(d) {
            if (d.status !== 'success') { toast(d.message || 'Error', 'error'); return; }
            showUrlModal(d);
        }).catch(function(e) { toast(e.message, 'error'); });
    }

    // === DOWNLOAD single file (not ZIP) ===
    function downloadSingle() {
        var name = getSelectedName(); if (!name) return;
        var url = adminBase + 'api_filemanager.php?action=download_file&csrf_token=' + encodeURIComponent(csrf) + '&root=' + encodeURIComponent(currentRoot) + '&path=' + encodeURIComponent(currentPath) + '&name=' + encodeURIComponent(name);
        window.location.href = url;
    }

    function showUrlModal(data) {
        // Remove any existing
        var existing = document.getElementById('fmUrlModal');
        if (existing) existing.remove();

        var isImg = data.is_image;
        var isVid = data.is_video;
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(data.url);

        var modal = document.createElement('div');
        modal.id = 'fmUrlModal';
        modal.className = 'fm-modal show';
        modal.style.cssText = 'display:flex !important;';
        modal.innerHTML = `
            <div class="fm-modal-box" style="width:540px;max-width:92vw;">
                <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
                    <h5 class="font-semibold text-slate-800 m-0"><i class="fas fa-link text-blue-600 mr-1"></i>${tr('fm_file_url','URL файла')}</h5>
                    <button onclick="document.getElementById('fmUrlModal').remove()" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
                </div>
                <div class="p-5">
                    <!-- Preview (if image) -->
                    ${isImg ? '<div class="mb-4 text-center"><img src="' + data.url + '" style="max-height:160px;max-width:100%;border-radius:8px;border:1px solid #e2e8f0;"></div>' : ''}
                    ${isVid ? '<div class="mb-4 text-center"><video src="' + data.url + '" controls style="max-height:160px;max-width:100%;border-radius:8px;"></video></div>' : ''}

                    <!-- File info -->
                    <div class="bg-slate-50 rounded-lg p-3 mb-3 text-sm">
                        <div class="flex justify-between mb-1"><span class="text-slate-500">${tr('fm_filename','Имя')}:</span><span class="text-slate-800 font-medium" style="word-break:break-all;">${escapeHtml(data.relative_path)}</span></div>
                        <div class="flex justify-between mb-1"><span class="text-slate-500">${tr('fm_size','Размер')}:</span><span class="text-slate-800">${escapeHtml(data.size_formatted)}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">${tr('fm_modified','Изменён')}:</span><span class="text-slate-800">${escapeHtml(data.modified)}</span></div>
                    </div>

                    <!-- URL field -->
                    <label class="block text-sm font-medium text-slate-700 mb-1">${tr('fm_direct_url','Прямая ссылка')}</label>
                    <div class="flex gap-2 mb-3">
                        <input type="text" id="fmUrlField" value="${data.url}" readonly
                            class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-slate-50 outline-none focus:ring-2 focus:ring-blue-500"
                            onclick="this.select()">
                        <button type="button" id="fmUrlCopyBtn" class="aicms-btn aicms-btn--primary"><i class="fas fa-copy"></i> ${tr('copy','Копировать')}</button>
                    </div>

                    <!-- HTML embed code -->
                    ${isImg ? `
                    <label class="block text-sm font-medium text-slate-700 mb-1">${tr('fm_html_code','HTML-код')}</label>
                    <div class="flex gap-2 mb-3">
                        <input type="text" id="fmHtmlField" value='<img src="${data.url}" alt="">' readonly
                            class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-slate-50 outline-none focus:ring-2 focus:ring-blue-500"
                            onclick="this.select()">
                        <button type="button" id="fmHtmlCopyBtn" class="aicms-btn aicms-btn--secondary"><i class="fas fa-code"></i></button>
                    </div>
                    ` : ''}

                    <!-- Markdown -->
                    <label class="block text-sm font-medium text-slate-700 mb-1">Markdown</label>
                    <div class="flex gap-2 mb-3">
                        <input type="text" id="fmMdField" value="${isImg ? '![](' + data.url + ')' : '[' + data.relative_path + '](' + data.url + ')'}" readonly
                            class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm font-mono bg-slate-50 outline-none focus:ring-2 focus:ring-blue-500"
                            onclick="this.select()">
                        <button type="button" id="fmMdCopyBtn" class="aicms-btn aicms-btn--secondary"><i class="fab fa-markdown"></i></button>
                    </div>

                    <!-- QR code + actions -->
                    <div class="flex items-center gap-4 pt-3 border-t border-slate-100">
                        <img src="${qrUrl}" alt="QR" style="width:80px;height:80px;border-radius:6px;border:1px solid #e2e8f0;" title="QR-код для ссылки">
                        <div class="flex-1 flex gap-2 flex-wrap">
                            <a href="${data.url}" target="_blank" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-external-link-alt"></i> ${tr('fm_open','Открыть')}</a>
                            <button type="button" id="fmDownloadBtn" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-download"></i> ${tr('fm_download_file','Скачать')}</button>
                            <button type="button" id="fmEmailBtn" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-envelope"></i> ${tr('fm_email','Отправить')}</a>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });

        // Copy buttons
        function copyField(fieldId, btn) {
            var f = document.getElementById(fieldId);
            f.select();
            try {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(f.value);
                } else {
                    document.execCommand('copy');
                }
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> ✓';
                btn.classList.add('aicms-btn--success');
                setTimeout(function() { btn.innerHTML = orig; btn.classList.remove('aicms-btn--success'); }, 1500);
                toast(tr('fm_url_copied','Ссылка скопирована'), 'success');
            } catch (e) { toast('Copy failed', 'error'); }
        }

        document.getElementById('fmUrlCopyBtn').onclick = function() { copyField('fmUrlField', this); };
        if (isImg) document.getElementById('fmHtmlCopyBtn').onclick = function() { copyField('fmHtmlField', this); };
        document.getElementById('fmMdCopyBtn').onclick = function() { copyField('fmMdField', this); };
        document.getElementById('fmDownloadBtn').onclick = function() { downloadSingle(); };
        document.getElementById('fmEmailBtn').onclick = function() {
            window.location.href = 'mailto:?subject=' + encodeURIComponent('Файл: ' + data.relative_path) + '&body=' + encodeURIComponent(data.url);
        };
    }

    // === CONTEXT MENU (right-click) ===
    function showContextMenu(e, name, type, card) {
        // Remove existing
        var existing = document.getElementById('fmContextMenu');
        if (existing) existing.remove();

        var menu = document.createElement('div');
        menu.id = 'fmContextMenu';
        menu.style.cssText = 'position:fixed;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:100000;padding:4px;min-width:200px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:13px;';

        // Select this card
        if (selectedCard) selectedCard.classList.remove('selected');
        selectedCard = card;
        card.classList.add('selected');
        if (selectedNames.size > 0 && !selectedNames.has(name)) clearSelection();

        var isText = card.dataset.isText === '1';
        var isImg = card.querySelector('[data-img]') !== null;
        var isZip = card.dataset.isZip === '1';

        var items = [];
        if (type === 'folder') {
            items.push({icon:'fa-folder-open', label:tr('fm_open_folder','Открыть'), action:function() { loadFolder(card.dataset.path); }});
            items.push({icon:'fa-link', label:tr('fm_copy_url','Копировать URL'), action:function() { getUrl(); }});
            items.push('sep');
            items.push({icon:'fa-i-cursor', label:tr('rename'), action:function() { renameSelected(); }});
            items.push({icon:'fa-copy', label:tr('copy'), action:function() { copySelected(); }});
            items.push({icon:'fa-arrows-alt', label:tr('move'), action:function() { moveSelected(); }});
            items.push({icon:'fa-file-archive', label:'ZIP', action:function() { zipSelected(); }});
            items.push('sep');
            items.push({icon:'fa-download', label:tr('fm_download_zip','Скачать ZIP'), action:function() { bulkDownload(); }});
            items.push('sep');
            items.push({icon:'fa-trash', label:tr('delete'), danger:true, action:function() { deleteSelected(); }});
        } else {
            if (isImg) {
                items.push({icon:'fa-eye', label:tr('fm_preview','Просмотр'), action:function() { showLightbox('..' + card.dataset.url); }});
            }
            items.push({icon:'fa-link', label:tr('fm_copy_url','Копировать URL'), action:function() { getUrl(); }});
            items.push({icon:'fa-download', label:tr('fm_download_file','Скачать'), action:function() { downloadSingle(); }});
            items.push('sep');
            if (isText) {
                items.push({icon:'fa-code', label:tr('editor'), action:function() { openEditor(name); }});
            }
            if (isZip) {
                items.push({icon:'fa-file-archive', label:tr('extract'), action:function() { unzipSelected(); }});
            }
            items.push({icon:'fa-i-cursor', label:tr('rename'), action:function() { renameSelected(); }});
            items.push({icon:'fa-copy', label:tr('copy'), action:function() { copySelected(); }});
            items.push({icon:'fa-arrows-alt', label:tr('move'), action:function() { moveSelected(); }});
            items.push({icon:'fa-file-archive', label:'ZIP', action:function() { zipSelected(); }});
            items.push('sep');
            items.push({icon:'fa-trash', label:tr('delete'), danger:true, action:function() { deleteSelected(); }});
        }

        items.forEach(function(item) {
            if (item === 'sep') {
                var sep = document.createElement('div');
                sep.style.cssText = 'height:1px;background:#e2e8f0;margin:4px 0;';
                menu.appendChild(sep);
            } else {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.innerHTML = '<i class="fas ' + item.icon + '" style="width:16px;text-align:center;margin-right:8px;' + (item.danger ? 'color:#dc2626;' : 'color:#64748b;') + '"></i>' + escapeHtml(item.label);
                btn.style.cssText = 'display:block;width:100%;text-align:left;padding:7px 10px;background:none;border:none;cursor:pointer;border-radius:4px;color:' + (item.danger ? '#dc2626' : '#1e293b') + ';font-size:13px;';
                btn.onmouseenter = function() { btn.style.background = item.danger ? '#fef2f2' : '#f1f5f9'; };
                btn.onmouseleave = function() { btn.style.background = 'none'; };
                btn.onclick = function() {
                    menu.remove();
                    item.action();
                };
                menu.appendChild(btn);
            }
        });

        document.body.appendChild(menu);
        // Position
        var x = e.clientX, y = e.clientY;
        var rect = menu.getBoundingClientRect();
        if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
        if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';

        // Close on click outside
        setTimeout(function() {
            document.addEventListener('click', function closeMenu() {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            });
        }, 50);
    }

    // === BULK OPERATIONS ===

    function bulkDelete() {
        var names = getSelectedNames();
        if (!names.length) { toast(tr('fm_no_files_selected', 'Select files'), 'info'); return; }
        if (!confirm(trf('fm_delete_bulk_confirm', names.length))) return;
        var prog = showBulkProgress(tr('fm_deleting', 'Удаление файлов'), 0);
        prog.update(0, trf('fm_deleting_n', names.length));
        var fd = new FormData();
        fd.append('path', currentPath);
        names.forEach(function(n) { fd.append('names[]', n); });
        apiPost('bulk_delete', fd).then(function(d) {
            if (d.status === 'success') { prog.done(d.message); toast(d.message, 'success'); loadFolder(currentPath); }
            else { prog.error(d.message); toast(d.message, 'error'); loadFolder(currentPath); }
        }).catch(function(e) { prog.error(e.message); toast(e.message, 'error'); });
    }

    function bulkZip() {
        var names = getSelectedNames();
        if (!names.length) { toast(tr('fm_no_files_selected', 'Select files'), 'info'); return; }
        var zipName = prompt(tr('fm_archive_name', 'Archive name') + ':', 'archive_' + names.length + '_files');
        if (!zipName) return;
        var prog = showBulkProgress(tr('fm_creating_zip', 'Создание архива'), 0);
        prog.update(0, trf('fm_zipping_n', names.length));
        var fd = new FormData();
        fd.append('path', currentPath);
        fd.append('zip_name', zipName);
        names.forEach(function(n) { fd.append('names[]', n); });
        apiPost('bulk_zip', fd).then(function(d) {
            if (d.status === 'success') { prog.done(d.message); toast(d.message, 'success'); loadFolder(currentPath); }
            else { prog.error(d.message); toast(d.message, 'error'); }
        }).catch(function(e) { prog.error(e.message); toast(e.message, 'error'); });
    }

    function bulkDownload() {
        var names = getSelectedNames();
        if (!names.length) { toast(tr('fm_no_files_selected', 'Select files'), 'info'); return; }
        // Trigger download via window.location (GET request)
        var url = adminBase + 'api_filemanager.php?action=bulk_download&csrf_token=' + encodeURIComponent(csrf) + '&root=' + encodeURIComponent(currentRoot) + '&path=' + encodeURIComponent(currentPath);
        names.forEach(function(n) { url += '&names[]=' + encodeURIComponent(n); });
        window.location.href = url;
        // Don't clear selection — user may want to do something else
    }

    function bulkCopy() {
        var names = getSelectedNames();
        if (!names.length) { toast(tr('fm_no_files_selected', 'Select files'), 'info'); return; }
        var otherFM = getOtherFM();
        if (otherFM && !otherFM.getRoot) otherFM = null;
        if (otherFM && currentRoot === otherFM.getRoot()) {
            // Same root — direct bulk copy to other panel's path
            var toPath = otherFM.getCurrentPath();
            if (toPath === currentPath) { toast(tr('fm_target_same', 'Target same as source'), 'error'); return; }
            var fd = new FormData();
            fd.append('from_path', currentPath);
            fd.append('to_path', toPath);
            fd.append('from_root', currentRoot);
            names.forEach(function(n) { fd.append('names[]', n); });
            apiPostWithRoot('bulk_copy', fd, currentRoot).then(function(d) {
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); otherFM.reload(); }
                else { toast(d.message, 'error'); }
            });
        } else {
            // Show modal for path selection
            bulkMoveOp = { fromPath: currentPath, fromRoot: currentRoot, names: names, type: 'copy' };
            showBulkMoveModal();
        }
    }

    function bulkMove() {
        var names = getSelectedNames();
        if (!names.length) { toast(tr('fm_no_files_selected', 'Select files'), 'info'); return; }
        var otherFM = getOtherFM();
        if (otherFM && !otherFM.getRoot) otherFM = null;
        if (otherFM && currentRoot === otherFM.getRoot()) {
            var toPath = otherFM.getCurrentPath();
            if (toPath === currentPath) { toast(tr('fm_target_same', 'Target same as source'), 'error'); return; }
            var fd = new FormData();
            fd.append('from_path', currentPath);
            fd.append('to_path', toPath);
            fd.append('from_root', currentRoot);
            names.forEach(function(n) { fd.append('names[]', n); });
            apiPostWithRoot('bulk_move', fd, currentRoot).then(function(d) {
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); otherFM.reload(); }
                else { toast(d.message, 'error'); }
            });
        } else {
            bulkMoveOp = { fromPath: currentPath, fromRoot: currentRoot, names: names, type: 'move' };
            showBulkMoveModal();
        }
    }

    function showBulkMoveModal() {
        var isMove = bulkMoveOp.type === 'move';
        var modal = document.getElementById('fmBulkMoveModal');
        if (!modal) { toast(tr('fm_modal_unavailable', 'Modal unavailable'), 'error'); return; }
        document.getElementById('fmBulkMoveTitle').textContent = isMove ? tr('fm_move_files', 'Move files') : tr('fm_copy_files', 'Copy files');
        document.getElementById('fmBulkMoveDesc').textContent = trf('fm_select_count', bulkMoveOp.names.length);
        document.getElementById('fmBulkMoveBtn').textContent = isMove ? tr('move', 'Move') : tr('copy', 'Copy');
        // Build folder tree for the same root
        apiGet('list', { path: '' }).then(function(data) {
            if (data.status !== 'success') return;
            var options = '<option value="">' + tr('fm_root', '/ (root)') + '</option>';
            data.folders.forEach(function(f) { options += '<option value="' + f.path + '">/' + f.path + '</option>'; });
            var promises = data.folders.map(function(f) {
                return apiGet('list', { path: f.path }).then(function(d) {
                    if (d.status === 'success' && d.folders.length) {
                        d.folders.forEach(function(sf) { options += '<option value="' + sf.path + '">/' + sf.path + '</option>'; });
                    }
                });
            });
            Promise.all(promises).then(function() {
                var sel = document.getElementById('fmBulkMoveTarget');
                sel.innerHTML = options;
                // Don't preselect current path
                modal.classList.add('show');
            });
        });
    }

    window.fmExecuteBulkMove = function() {
        var targetPath = document.getElementById('fmBulkMoveTarget').value;
        if (targetPath === bulkMoveOp.fromPath) { toast(tr('fm_target_same', 'Target same as source'), 'error'); return; }
        var fd = new FormData();
        fd.append('from_path', bulkMoveOp.fromPath);
        fd.append('to_path', targetPath);
        fd.append('from_root', bulkMoveOp.fromRoot);
        bulkMoveOp.names.forEach(function(n) { fd.append('names[]', n); });
        apiPostWithRoot('bulk_' + bulkMoveOp.type, fd, bulkMoveOp.fromRoot).then(function(d) {
            document.getElementById('fmBulkMoveModal').classList.remove('show');
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    };

    // Cross-panel copy/move: source = this panel, target = other panel
    function copySelected() {
        // Multi-select path
        if (selectedNames.size > 1) return bulkCopy();
        var name = getSelectedName(); if (!name) return;
        var otherFM = getOtherFM();
        if (otherFM) {
            // Cross-panel copy
            crossRootOp('copy', name, currentPath, currentRoot, otherFM.getCurrentPath(), otherFM.getRoot());
        } else {
            showMoveModal('copy', name);
        }
    }

    function moveSelected() {
        if (selectedNames.size > 1) return bulkMove();
        var name = getSelectedName(); if (!name) return;
        var otherFM = getOtherFM();
        if (otherFM) {
            crossRootOp('move', name, currentPath, currentRoot, otherFM.getCurrentPath(), otherFM.getRoot());
        } else {
            showMoveModal('move', name);
        }
    }

    function getOtherFM() {
        var p1 = document.getElementById('fmPanel1');
        var p2 = document.getElementById('fmPanel2');
        if (p2.classList.contains('hidden')) return null; // single panel mode
        if (panelEl === p1) return window._fmInstances && window._fmInstances[1] || null;
        if (panelEl === p2) return window._fmInstances && window._fmInstances[0] || null;
        return null;
    }

    function crossRootOp(op, name, fromPath, fromRoot, toPath, toRoot) {
        // For same root, use normal move/copy API
        if (fromRoot === toRoot) {
            var fd = new FormData();
            fd.append('from_path', fromPath); fd.append('to_path', toPath); fd.append('name', name);
            // Temporarily set root for this request
            var savedRoot = currentRoot;
            currentRoot = fromRoot;
            apiPost(op, fd).then(function(d) {
                currentRoot = savedRoot;
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); var other = getOtherFM(); if (other) other.reload(); }
                else { toast(d.message, 'error'); }
            });
        } else {
            // Cross-root: use bulk API for single file
            var fd2 = new FormData();
            fd2.append('from_path', fromPath); fd2.append('to_path', toPath); fd2.append('from_root', fromRoot);
            fd2.append('names[]', name);
            apiPostWithRoot('bulk_' + op, fd2, fromRoot).then(function(d) {
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); var other = getOtherFM(); if (other) other.reload(); }
                else { toast(d.message, 'error'); }
            });
        }
    }

    function showMoveModal(type, name) {
        moveOp = { type: type, name: name, fromPath: currentPath, fromRoot: currentRoot };
        document.getElementById('fmMoveTitle').textContent = type === 'move' ? tr('move', 'Move') : tr('copy', 'Copy');
        document.getElementById('fmMoveDesc').textContent = (type === 'move' ? tr('move', 'Move') : tr('copy', 'Copy')) + ' «' + name + '» в:';
        document.getElementById('fmMoveBtn').textContent = type === 'move' ? tr('move', 'Move') : tr('copy', 'Copy');
        apiGet('list', { path: '' }).then(function(data) {
            if (data.status !== 'success') return;
            var options = '<option value="">' + tr('fm_root', '/ (root)') + '</option>';
            data.folders.forEach(function(f) { options += '<option value="' + f.path + '">/' + f.path + '</option>'; });
            var promises = data.folders.map(function(f) {
                return apiGet('list', { path: f.path }).then(function(d) {
                    if (d.status === 'success' && d.folders.length) {
                        d.folders.forEach(function(sf) { options += '<option value="' + sf.path + '">/' + sf.path + '</option>'; });
                    }
                });
            });
            Promise.all(promises).then(function() {
                var sel = document.getElementById('fmMoveTarget');
                sel.innerHTML = options;
                sel.value = currentPath;
                document.getElementById('fmMoveModal').classList.add('show');
            });
        });
    }

    window.fmExecuteMove = function() {
        var targetPath = document.getElementById('fmMoveTarget').value;
        var fd = new FormData();
        fd.append('from_path', moveOp.fromPath); fd.append('to_path', targetPath); fd.append('name', moveOp.name);
        apiPost(moveOp.type, fd).then(function(d) {
            document.getElementById('fmMoveModal').classList.remove('show');
            if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
            else { toast(d.message, 'error'); }
        });
    };

    // === Search ===
    function search(q) {
        q = (q || '').toLowerCase().trim();
        if (!q) { loadFolder(currentPath); return; }
        var ff = allFolders.filter(function(f) { return f.name.toLowerCase().indexOf(q) !== -1; });
        var fl = allFiles.filter(function(f) { return f.name.toLowerCase().indexOf(q) !== -1; });
        renderGrid({ folders: ff, files: fl });
    }

    // === Monaco code editor ===
    var monacoEditor = null;
    var monacoLoaded = false;

    function loadMonaco() {
        if (monacoLoaded || window.monaco) { monacoLoaded = true; return Promise.resolve(); }
        return new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs/loader.js';
            s.onload = function() {
                require.config({ paths: { 'vs': 'https://cdn.jsdelivr.net/npm/monaco-editor@0.44.0/min/vs' } });
                require(['vs/editor/editor.main'], function() { monacoLoaded = true; resolve(); });
            };
            s.onerror = function() { reject(new Error('Monaco loader failed')); };
            document.head.appendChild(s);
        });
    }

    var langMap = { 'php':'php','js':'javascript','ts':'typescript','css':'css','html':'html','htm':'html','json':'json','xml':'xml','md':'markdown','sql':'sql','yaml':'yaml','yml':'yaml','ini':'ini','txt':'plaintext','csv':'plaintext','conf':'ini','htaccess':'ini' };

    function openEditor(name) {
        editorFile = { name: name, path: currentPath };
        apiGet('read', { path: currentPath, name: name }).then(function(d) {
            if (d.status !== 'success') { toast(d.message, 'error'); return; }
            document.getElementById('fmEditorTitle').textContent = 'Редактор: ' + name;
            document.getElementById('fmEditorModal').classList.add('show');
            var lang = langMap[(d.ext || 'txt').toLowerCase()] || 'plaintext';
            loadMonaco().then(function() {
                if (monacoEditor) { monacoEditor.dispose(); monacoEditor = null; }
                monacoEditor = monaco.editor.create(document.getElementById('fmMonacoEditor'), {
                    value: d.content, language: lang, theme: 'vs-dark', automaticLayout: true,
                    wordWrap: 'on', fontSize: 14, minimap: { enabled: true },
                    scrollBeyondLastLine: false, tabSize: 4, lineNumbers: 'on', folding: true
                });
                monacoEditor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, function() { fmSaveFile(); });
            }).catch(function(e) { toast('Monaco: ' + e.message, 'error'); });
        });
    }
    window.fmCloseEditor = function() { document.getElementById('fmEditorModal').classList.remove('show'); if (monacoEditor) { monacoEditor.dispose(); monacoEditor = null; } };
    window.fmSaveFile = function() {
        var content = monacoEditor ? monacoEditor.getValue() : '';
        var fd = new FormData(); fd.append('path', editorFile.path); fd.append('name', editorFile.name); fd.append('content', content);
        apiPost('save', fd).then(function(d) {
            if (d.status === 'success') { toast(d.message, 'success'); fmCloseEditor(); }
            else { toast(d.message, 'error'); }
        });
    };

    // === Lightbox ===
    function showLightbox(url) { document.getElementById('fmLightboxImg').src = url; document.getElementById('fmLightbox').classList.add('show'); }

    // === Drag & drop: card → folder (same panel), card → other panel ===
    container.addEventListener('dragstart', function(e) {
        var card = e.target.closest('.fm-card');
        if (!card) return;
        // If multi-select is active, drag all selected
        var dragNames = selectedNames.size > 0 ? Array.from(selectedNames) : [card.dataset.name];
        e.dataTransfer.setData('text/plain', JSON.stringify({ names: dragNames, fromPath: currentPath, fromRoot: currentRoot }));
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('dragging');
    });
    container.addEventListener('dragend', function(e) {
        var card = e.target.closest('.fm-card');
        if (card) card.classList.remove('dragging');
    });
    container.addEventListener('dragover', function(e) {
        var card = e.target.closest('.fm-card[data-type="folder"]');
        if (card) { e.preventDefault(); card.classList.add('drag-over'); }
    });
    container.addEventListener('dragleave', function(e) {
        var card = e.target.closest('.fm-card[data-type="folder"]');
        if (card) card.classList.remove('drag-over');
    });
    container.addEventListener('drop', function(e) {
        var card = e.target.closest('.fm-card[data-type="folder"]');
        var rawData = e.dataTransfer.getData('text/plain');
        if (!rawData) return;
        e.preventDefault(); e.stopPropagation();
        var data = JSON.parse(rawData);
        var names = data.names || [];
        if (!names.length) return;
        if (card) {
            card.classList.remove('drag-over');
            // Use bulk move
            var fd = new FormData();
            fd.append('from_path', data.fromPath);
            fd.append('to_path', card.dataset.path);
            fd.append('from_root', data.fromRoot);
            names.forEach(function(n) { fd.append('names[]', n); });
            var savedRoot = currentRoot; currentRoot = data.fromRoot;
            apiPost('bulk_move', fd).then(function(d) {
                currentRoot = savedRoot;
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
                else { toast(d.message, 'error'); }
            });
        }
    });
    // Panel-level drop (cross-panel)
    if (panelEl) {
        panelEl.addEventListener('dragover', function(e) {
            if (e.dataTransfer.types.includes('text/plain')) { e.preventDefault(); panelEl.classList.add('drag-target'); }
        });
        panelEl.addEventListener('dragleave', function(e) { if (e.target === panelEl || e.target.classList.contains('fm-container')) panelEl.classList.remove('drag-target'); });
        panelEl.addEventListener('drop', function(e) {
            panelEl.classList.remove('drag-target');
            var rawData = e.dataTransfer.getData('text/plain');
            if (!rawData) return;
            e.preventDefault();
            var data = JSON.parse(rawData);
            var names = data.names || [];
            if (!names.length) return;
            // Bulk move from source panel to this panel's current path
            var fd = new FormData();
            fd.append('from_path', data.fromPath);
            fd.append('to_path', currentPath);
            fd.append('from_root', data.fromRoot);
            names.forEach(function(n) { fd.append('names[]', n); });
            var savedRoot = currentRoot; currentRoot = data.fromRoot;
            apiPost('bulk_move', fd).then(function(d) {
                currentRoot = savedRoot;
                if (d.status === 'success') { toast(d.message, 'success'); loadFolder(currentPath); }
                else { toast(d.message, 'error'); }
            });
        });
    }

    // === File upload drag-drop (from desktop) ===
    var dragCounter = 0;
    var dropOverlay = null;
    document.addEventListener('dragenter', function(e) {
        if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.includes('Files')) {
            e.preventDefault(); dragCounter++; showDropOverlay();
        }
    });
    document.addEventListener('dragover', function(e) { if (e.dataTransfer.types.includes('Files')) e.preventDefault(); });
    document.addEventListener('dragleave', function(e) {
        if (e.dataTransfer.types.includes('Files')) { dragCounter--; if (dragCounter <= 0) { dragCounter = 0; hideDropOverlay(); } }
    });
    document.addEventListener('drop', function(e) {
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            e.preventDefault(); dragCounter = 0; hideDropOverlay(); uploadFiles(e.dataTransfer.files);
        }
    });
    function showDropOverlay() {
        if (dropOverlay) return;
        dropOverlay = document.createElement('div');
        dropOverlay.className = 'fm-drop-overlay';
        dropOverlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(37,99,235,.15);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;pointer-events:none;';
        dropOverlay.innerHTML = '<div style="background:#fff;padding:32px 48px;border-radius:16px;border:3px dashed #2563eb;color:#2563eb;text-align:center;font-size:16px;font-weight:500;box-shadow:0 20px 40px rgba(0,0,0,.15);"><i class="fas fa-cloud-upload-alt" style="font-size:48px;margin-bottom:12px;display:block;"></i>' + tr('fm_drop_files', 'Drop files to upload') + '</div>';
        document.body.appendChild(dropOverlay);
    }
    function hideDropOverlay() { if (dropOverlay) { dropOverlay.remove(); dropOverlay = null; } }

    // === Init ===
    loadFolder('');

    // --- Arrow key navigation in grid ---
    // Moves the "selectedCard" cursor up/down/left/right inside the grid.
    // Holding Shift extends selection instead of moving it.
    function arrowNavigate(direction, extendSelection) {
        var cards = Array.prototype.slice.call(container.querySelectorAll('.fm-card'));
        if (!cards.length) return;
        var currentIdx = selectedCard ? cards.indexOf(selectedCard) : -1;
        if (currentIdx === -1) {
            // Nothing selected yet — pick first
            selectSingleCard(cards[0]);
            return;
        }
        // Determine grid layout: cards have equal width via CSS grid.
        // Compute columns by checking how many fit on one row.
        var grid = container.querySelector('.fm-grid');
        var cols = 1;
        if (grid && grid.getBoundingClientRect) {
            var gridWidth = grid.getBoundingClientRect().width;
            var cardWidth = cards[0].getBoundingClientRect().width;
            if (cardWidth > 0) cols = Math.max(1, Math.floor(gridWidth / cardWidth));
        }
        var newIdx = currentIdx;
        if (direction === 'right') newIdx = Math.min(cards.length - 1, currentIdx + 1);
        else if (direction === 'left') newIdx = Math.max(0, currentIdx - 1);
        else if (direction === 'down') newIdx = Math.min(cards.length - 1, currentIdx + cols);
        else if (direction === 'up') newIdx = Math.max(0, currentIdx - cols);
        if (newIdx === currentIdx) return;
        var newCard = cards[newIdx];
        if (extendSelection) {
            // Shift+Arrow = toggle selection of the destination card
            toggleSelect(newCard.dataset.name, !selectedNames.has(newCard.dataset.name));
            selectSingleCard(newCard);
        } else {
            // Plain arrow = move cursor; preserve current selection
            selectSingleCard(newCard);
        }
        // Scroll into view
        if (newCard.scrollIntoView) newCard.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function selectSingleCard(card) {
        if (selectedCard) selectedCard.classList.remove('selected');
        selectedCard = card;
        if (card) {
            card.classList.add('selected');
            // Don't change selectedNames — arrows move the cursor, not the selection
        }
    }

    // Public API
    var api = {
        loadFolder: loadFolder,
        getCurrentPath: function() { return currentPath; },
        getRoot: function() { return currentRoot; },
        reload: function() { loadFolder(currentPath); },
        arrowNavigate: arrowNavigate,
        _getActiveCardName: function() { return selectedCard ? selectedCard.dataset.name : null; },
        newFolder: newFolder,
        newFile: newFile,
        uploadClick: uploadClick,
        uploadFiles: uploadFiles,
        renameSelected: renameSelected,
        copySelected: copySelected,
        moveSelected: moveSelected,
        deleteSelected: deleteSelected,
        zipSelected: zipSelected,
        unzipSelected: unzipSelected,
        editSelected: editSelected,
        search: search,
        // Multi-select / bulk
        selectAll: selectAll,
        clearSelection: clearSelection,
        getSelectedNames: getSelectedNames,
        bulkDelete: bulkDelete,
        bulkZip: bulkZip,
        bulkDownload: bulkDownload,
        bulkCopy: bulkCopy,
        getUrl: getUrl,
        downloadSingle: downloadSingle,
        bulkMove: bulkMove,
        toggleSelectUnderCursor: function() {
            // Insert key behavior — toggles the card under the last cursor position
            if (selectedCard) {
                toggleSelect(selectedCard.dataset.name);
                lastSelectedName = selectedCard.dataset.name;
            }
        },
        _loaded: false
    };

    // Register instance for cross-panel operations
    if (!window._fmInstances) window._fmInstances = [];
    if (panelEl && panelEl.id === 'fmPanel1') window._fmInstances[0] = api;
    if (panelEl && panelEl.id === 'fmPanel2') window._fmInstances[1] = api;

    return api;
}
