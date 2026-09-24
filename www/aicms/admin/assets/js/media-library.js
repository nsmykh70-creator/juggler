/**
 * AICMS Media Library — front-end runtime
 * =======================================
 * Features:
 *   - Grid / List view toggle (with localStorage persistence)
 *   - Search by file name
 *   - Filter by file type
 *   - Sort by name / size / modified date
 *   - Multi-select with bulk actions (copy URL / delete)
 *   - File preview modal (image preview + metadata + copy URL)
 *   - Upload by URL (downloads remote image and stores it locally)
 *   - Drag-and-drop upload
 *   - Stat cards (total count, by type, total size)
 */
function initMediaLibrary(options) {
    const container = document.querySelector(options.container);
    if (!container) return;

    let csrfToken = options.csrfToken;
    const I = window.I18N || {};
    function tr(key, fallback) { return I[key] || fallback || key; }
    function trf(key) {
        const s = I[key] || key;
        if (arguments.length < 2) return s;
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return s.replace(/%s/g, function() { return args[i++] !== undefined ? args[i-1] : '%s'; });
    }
    let currentInputTarget = null;
    let allImages = [];
    let allFolders = [];   // NEW: folders in current directory
    let filteredImages = [];
    let selectedFiles = new Set();
    let currentView = localStorage.getItem('aicms_media_view') || 'grid';
    let currentTypeFilter = '';
    let currentSort = 'modified-desc';
    let currentSearch = '';
    let currentFolder = '';  // NEW: current folder path (relative to /uploads/)
    let currentBreadcrumbs = [];  // NEW: breadcrumb segments

    // ============== HELPERS ==============
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str).replace(/[&<>"']/g, function (s) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[s];
        });
    }

    function fileIconFor(type) {
        switch (type) {
            case 'image':    return 'fas fa-image';
            case 'document': return 'fas fa-file-pdf';
            case 'archive':  return 'fas fa-file-archive';
            case 'video':    return 'fas fa-file-video';
            case 'audio':    return 'fas fa-file-audio';
            default:         return 'fas fa-file';
        }
    }

    function fileBadgeClass(type) {
        return 't-' + (type || 'other');
    }

    function typeLabel(type) {
        switch (type) {
            case 'image':    return 'IMG';
            case 'document': return 'DOC';
            case 'archive':  return 'ZIP';
            case 'video':    return 'VID';
            case 'audio':    return 'AUD';
            default:         return 'FILE';
        }
    }

    function toast(message, kind) {
        kind = kind || 'success';
        let el = document.createElement('div');
        el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:8px;font-size:13px;font-family:inherit;color:#fff;background:' +
            (kind === 'error' ? '#dc2626' : kind === 'warning' ? '#f59e0b' : '#16a34a') +
            ';box-shadow:0 4px 12px rgba(0,0,0,0.2);opacity:0;transform:translateY(10px);transition:all 0.2s';
        el.textContent = message;
        document.body.appendChild(el);
        requestAnimationFrame(function () {
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        });
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transform = 'translateY(10px)';
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 250);
        }, 2500);
    }

    // ============== FILTERING / SORTING ==============
    function applyFilters() {
        filteredImages = allImages.filter(function (img) {
            if (currentTypeFilter && img.type !== currentTypeFilter) return false;
            if (currentSearch && img.name.toLowerCase().indexOf(currentSearch) === -1) return false;
            return true;
        });

        filteredImages.sort(function (a, b) {
            switch (currentSort) {
                case 'modified-asc':  return (a.modified || 0) - (b.modified || 0);
                case 'name-asc':      return a.name.localeCompare(b.name);
                case 'name-desc':     return b.name.localeCompare(a.name);
                case 'size-desc':     return (b.size || 0) - (a.size || 0);
                case 'size-asc':      return (a.size || 0) - (b.size || 0);
                case 'modified-desc':
                default:              return (b.modified || 0) - (a.modified || 0);
            }
        });

        renderGrid();
    }

    // ============== RENDERING ==============
    // Progressive rendering: render first BATCH_SIZE items immediately,
    // then append more as user scrolls (IntersectionObserver on sentinel).
    // This keeps the DOM small for large libraries (500+ files).
    var BATCH_SIZE = 60;
    var renderedCount = 0;
    var sentinelEl = null;
    var intersectionObserver = null;

    function renderGrid() {
        // Reset progressive rendering state
        renderedCount = 0;
        if (intersectionObserver) { intersectionObserver.disconnect(); intersectionObserver = null; }
        if (sentinelEl && sentinelEl.parentNode) sentinelEl.parentNode.removeChild(sentinelEl);

        // Render folders first (if any) — works in both grid and list modes
        var foldersHtml = '';
        if (allFolders.length && !currentTypeFilter && !currentSearch) {
            foldersHtml = allFolders.map(function(f) {
                return '<div class="media-item media-folder-card" data-type="folder" data-name="' + escapeHtml(f.name) + '" data-path="' + escapeHtml(f.path) + '">' +
                    '<input type="checkbox" class="select-checkbox" data-name="' + escapeHtml(f.name) + '">' +
                    '<div class="media-folder-thumb">' +
                        '<i class="fas fa-folder"></i>' +
                    '</div>' +
                    '<div class="media-folder-info">' +
                        '<div class="file-name">' + escapeHtml(f.name) + '</div>' +
                        '<div class="file-meta">' + f.file_count + ' ' + (f.file_count === 1 ? 'файл' : 'файлов') + '</div>' +
                    '</div>' +
                    '<div class="media-folder-actions">' +
                        '<button type="button" data-folder-action="open" title="Открыть"><i class="fas fa-folder-open"></i></button>' +
                        '<button type="button" data-folder-action="rename" title="Переименовать"><i class="fas fa-i-cursor"></i></button>' +
                        '<button type="button" data-folder-action="delete" title="Удалить"><i class="fas fa-trash"></i></button>' +
                    '</div>' +
                    '<div class="type-badge" style="background:rgba(217,119,6,0.85);">FOLDER</div>' +
                '</div>';
            }).join('');
        }

        if (!filteredImages.length && !allFolders.length) {
            container.innerHTML = '<div class="col-span-full text-center py-12 text-slate-400">' +
                '<i class="fas fa-photo-film text-4xl text-slate-300 mb-3 block"></i>' +
                '<p class="text-sm">' + (allImages.length ? tr('no_files_filter', 'Nothing found for this filter.') : tr('no_files', 'No files yet. Upload the first one!')) + '</p>' +
                '</div>';
            updateBulkBar();
            return;
        }

        // Render first batch (folders + first files)
        var batch = filteredImages.slice(0, BATCH_SIZE);
        var filesHtml = batch.map(renderItem).join('');
        container.innerHTML = foldersHtml + filesHtml;
        renderedCount = batch.length;

        // Wire folder clicks + checkboxes
        Array.prototype.forEach.call(container.querySelectorAll('.media-folder-card[data-type="folder"]'), function(el) {
            el.addEventListener('click', function(e) {
                var actionBtn = e.target.closest('[data-folder-action]');
                if (actionBtn) {
                    e.stopPropagation();
                    var action = actionBtn.dataset.folderAction;
                    var path = el.dataset.path;
                    if (action === 'open') { currentFolder = path; selectedFiles.clear(); loadImages(); }
                    else if (action === 'rename') { renameFolder(path); }
                    else if (action === 'delete') { deleteFolder(path); }
                    return;
                }
                // Checkbox click — toggle selection
                if (e.target.classList.contains('select-checkbox')) {
                    e.stopPropagation();
                    var name = el.dataset.name;
                    if (e.target.checked) selectedFiles.add(name);
                    else selectedFiles.delete(name);
                    el.classList.toggle('is-selected', e.target.checked);
                    updateBulkBar();
                    return;
                }
                // Click on card body (not buttons/checkbox) = open folder
                currentFolder = el.dataset.path;
                selectedFiles.clear();
                loadImages();
            });
        });

        // Restore selection visual state for rendered items
        Array.prototype.forEach.call(container.querySelectorAll('.media-item:not([data-type="folder"])'), function (el) {
            var name = el.getAttribute('data-name');
            if (selectedFiles.has(name)) el.classList.add('is-selected');
            var cb = el.querySelector('.select-checkbox');
            if (cb) cb.checked = selectedFiles.has(name);
        });

        // If more items remain, set up IntersectionObserver for progressive loading
        if (renderedCount < filteredImages.length) {
            setupProgressiveLoading();
        }
        updateBulkBar();
    }

    function setupProgressiveLoading() {
        // Create a sentinel element at the bottom that triggers loading when visible
        sentinelEl = document.createElement('div');
        sentinelEl.id = 'media-grid-sentinel';
        sentinelEl.style.cssText = 'grid-column:1/-1;height:60px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;';
        sentinelEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><span class="sentinel-text">Loading more…</span>';
        container.appendChild(sentinelEl);

        if (!('IntersectionObserver' in window)) {
            // Fallback: just render everything
            loadNextBatch();
            return;
        }

        intersectionObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadNextBatch();
                }
            });
        }, { rootMargin: '200px', threshold: 0.1 });

        intersectionObserver.observe(sentinelEl);
    }

    function loadNextBatch() {
        if (renderedCount >= filteredImages.length) {
            // All loaded — remove sentinel
            if (sentinelEl && sentinelEl.parentNode) {
                sentinelEl.parentNode.removeChild(sentinelEl);
                sentinelEl = null;
            }
            if (intersectionObserver) { intersectionObserver.disconnect(); intersectionObserver = null; }
            return;
        }
        var nextBatch = filteredImages.slice(renderedCount, renderedCount + BATCH_SIZE);
        var html = nextBatch.map(renderItem).join('');
        // Insert before sentinel
        if (sentinelEl) {
            sentinelEl.insertAdjacentHTML('beforebegin', html);
        } else {
            container.insertAdjacentHTML('beforeend', html);
        }
        renderedCount += nextBatch.length;

        // Restore selection state for newly added items
        Array.prototype.forEach.call(container.querySelectorAll('.media-item'), function (el) {
            var name = el.getAttribute('data-name');
            if (selectedFiles.has(name)) {
                el.classList.add('is-selected');
                var cb = el.querySelector('.select-checkbox');
                if (cb) cb.checked = true;
            }
        });

        // Update sentinel text
        if (sentinelEl && renderedCount < filteredImages.length) {
            var text = sentinelEl.querySelector('.sentinel-text');
            if (text) text.textContent = 'Loaded ' + renderedCount + ' / ' + filteredImages.length;
        } else if (sentinelEl) {
            // All loaded
            sentinelEl.style.display = 'none';
        }
    }

    function renderItem(image) {
        const fullUrl = '..' + image.url;
        const isSelected = selectedFiles.has(image.name);

        const dimStr = (image.width && image.height) ? (image.width + '×' + image.height) : '';
        const metaStr = image.size_formatted + (dimStr ? ' · ' + dimStr : '');

        // Grid view
        if (currentView === 'grid') {
            return '' +
                '<div class="media-item" data-name="' + escapeHtml(image.name) + '" data-url="' + escapeHtml(image.url) + '">' +
                    '<input type="checkbox" class="select-checkbox" data-name="' + escapeHtml(image.name) + '"' + (isSelected ? ' checked' : '') + '>' +
                    '<span class="type-badge ' + fileBadgeClass(image.type) + '">' + typeLabel(image.type) + '</span>' +
                    '<div class="media-thumb">' + (image.type === 'image' ? '<img src="' + fullUrl + '" alt="' + escapeHtml(image.name) + '" loading="lazy">' : '<i class="' + fileIconFor(image.type) + ' file-icon"></i>') + '</div>' +
                    '<div class="media-item-overlay">' +
                        '<p class="file-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</p>' +
                        '<div class="media-item-actions">' +
                            '<button class="act-view" data-action="view" data-name="' + escapeHtml(image.name) + '" title="View"><i class="fas fa-expand"></i></button>' +
                            '<button class="act-copy" data-action="copy" data-url="' + escapeHtml(image.url) + '" title="Copy URL"><i class="fas fa-link"></i></button>' +
                            '<button class="act-del" data-action="delete" data-name="' + escapeHtml(image.name) + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
        }

        // List view
        return '' +
            '<div class="media-item" data-name="' + escapeHtml(image.name) + '" data-url="' + escapeHtml(image.url) + '">' +
                '<input type="checkbox" class="select-checkbox" data-name="' + escapeHtml(image.name) + '"' + (isSelected ? ' checked' : '') + '>' +
                '<div class="media-thumb">' + (image.type === 'image' ? '<img src="' + fullUrl + '" alt="' + escapeHtml(image.name) + '" loading="lazy">' : '<i class="' + fileIconFor(image.type) + ' file-icon"></i>') + '</div>' +
                '<div class="media-item-info">' +
                    '<span class="file-name" title="' + escapeHtml(image.name) + '">' + escapeHtml(image.name) + '</span>' +
                    '<span class="file-meta">' + metaStr + ' · ' + escapeHtml(image.modified_formatted) + '</span>' +
                '</div>' +
                '<div class="media-item-actions">' +
                    '<button class="act-view" data-action="view" data-name="' + escapeHtml(image.name) + '" title="View"><i class="fas fa-expand"></i></button>' +
                    '<button class="act-copy" data-action="copy" data-url="' + escapeHtml(image.url) + '" title="Copy URL"><i class="fas fa-link"></i></button>' +
                    '<button class="act-del" data-action="delete" data-name="' + escapeHtml(image.name) + '" title="Delete"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>';
    }

    function renderStats(stats) {
        if (!stats || !options.statsContainer) return;
        const root = document.querySelector(options.statsContainer);
        if (!root) return;
        const setStat = function (key, value) {
            const el = root.querySelector('[data-stat="' + key + '"]');
            if (el) el.textContent = value;
        };
        setStat('total', stats.total || 0);
        setStat('image', (stats.by_type && stats.by_type.image) || 0);
        const otherCount = (stats.total || 0) - ((stats.by_type && stats.by_type.image) || 0);
        setStat('other', otherCount);
        setStat('total_size', stats.total_size_formatted || '0 B');
    }

    // ============== VIEW TOGGLE ==============
    function setView(view) {
        currentView = view;
        localStorage.setItem('aicms_media_view', view);
        container.classList.toggle('is-list', view === 'list');
        container.classList.toggle('media-grid', true);
        if (options.viewGridBtn) document.querySelector(options.viewGridBtn).classList.toggle('is-active', view === 'grid');
        if (options.viewListBtn) document.querySelector(options.viewListBtn).classList.toggle('is-active', view === 'list');
        renderGrid();
    }

    // ============== BULK SELECTION ==============
    function updateBulkBar() {
        if (!options.bulkBar) return;
        const bar = document.querySelector(options.bulkBar);
        const countEl = document.querySelector(options.bulkCount);
        if (selectedFiles.size > 0) {
            bar.classList.add('is-visible');
            if (countEl) countEl.textContent = selectedFiles.size;
        } else {
            bar.classList.remove('is-visible');
        }
    }

    function toggleSelection(name, checked) {
        if (checked) selectedFiles.add(name);
        else selectedFiles.delete(name);
        // Update all matching items
        Array.prototype.forEach.call(container.querySelectorAll('.media-item[data-name="' + CSS.escape(name) + '"]'), function (el) {
            el.classList.toggle('is-selected', checked);
            const cb = el.querySelector('.select-checkbox');
            if (cb) cb.checked = checked;
        });
        updateBulkBar();
    }

    function clearSelection() {
        selectedFiles.clear();
        Array.prototype.forEach.call(container.querySelectorAll('.media-item'), function (el) {
            el.classList.remove('is-selected');
            const cb = el.querySelector('.select-checkbox');
            if (cb) cb.checked = false;
        });
        updateBulkBar();
    }

    // ============== API CALLS ==============
    async function loadImages() {
        try {
            const url = 'api_media.php?action=list&csrf_token=' + encodeURIComponent(csrfToken) + '&folder=' + encodeURIComponent(currentFolder);
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ' ' + response.statusText);
            }
            const text = await response.text();
            if (!text || text.trim() === '') {
                throw new Error('Пустой ответ сервера. Возможно сессия истекла — обновите страницу.');
            }
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                // Server returned HTML (login redirect or error page)
                if (text.indexOf('<html') !== -1 || text.indexOf('<!DOCTYPE') !== -1 || text.indexOf('<br') !== -1) {
                    throw new Error('Сервер вернул HTML вместо JSON. Возможно сессия истекла или PHP ошибка. Обновите страницу.');
                }
                throw new Error('Некорректный JSON: ' + text.substring(0, 200));
            }
            if (data.status === 'success') {
                if (data.new_csrf_token) csrfToken = data.new_csrf_token;
                allImages = data.files || [];
                allFolders = data.folders || [];
                currentBreadcrumbs = data.breadcrumbs || [];
                renderBreadcrumbs();
                renderStats(data.stats);
                applyFilters();
            } else {
                throw new Error(data.message || tr('ml_upload_error', 'Load error'));
            }
        } catch (error) {
            container.innerHTML = '<div class="col-span-full text-center py-8 text-red-500"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(error.message) + '</div>';
        }
    }

    function renderBreadcrumbs() {
        var el = document.getElementById('mediaBreadcrumbs');
        if (!el) return;
        var html = '<span class="text-slate-400 mr-1"><i class="fas fa-folder-open"></i></span>';
        currentBreadcrumbs.forEach(function(c, i) {
            var isLast = i === currentBreadcrumbs.length - 1;
            html += '<button type="button" class="px-2 py-0.5 rounded ' + (isLast ? 'text-blue-600 font-semibold' : 'text-slate-600 hover:text-blue-600 hover:bg-slate-100') + '" data-path="' + escapeHtml(c.path) + '">' + escapeHtml(c.name) + '</button>';
            if (!isLast) html += '<span class="text-slate-300">/</span>';
        });
        el.innerHTML = html;
        el.querySelectorAll('button').forEach(function(btn) {
            btn.addEventListener('click', function() {
                currentFolder = btn.dataset.path;
                selectedFiles.clear();
                loadImages();
            });
        });
    }

    async function uploadFiles(files) {
        if (!files || !files.length) return;
        const formData = new FormData();
        for (const file of files) formData.append('files[]', file);
        formData.append('action', 'upload');
        formData.append('folder', currentFolder);
        formData.append('csrf_token', csrfToken);
        const progressBar = options.progressBar ? document.querySelector(options.progressBar) : null;
        const progressContainer = options.progressBarContainer ? document.querySelector(options.progressBarContainer) : null;
        if (progressContainer) progressContainer.style.display = 'block';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api_media.php', true);
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable && progressBar) {
                progressBar.style.width = ((e.loaded / e.total) * 100) + '%';
            }
        };
        xhr.onload = function () {
            if (progressContainer) progressContainer.style.display = 'none';
            if (progressBar) progressBar.style.width = '0%';
            if (xhr.status === 200) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.new_csrf_token) csrfToken = data.new_csrf_token;
                    if (data.status === 'success') {
                        toast(tr('ml_uploaded', 'Files uploaded') + ': ' + files.length);
                        loadImages();
                    } else {
                        toast(data.message || tr('ml_upload_error', 'Upload error'), 'error');
                    }
                } catch (e) {
                    toast(tr('ml_uploaded', 'Files uploaded'), 'success');
                    loadImages();
                }
            } else {
                toast(tr('ml_upload_error', 'Upload error') + ' (HTTP ' + xhr.status + ')', 'error');
            }
        };
        xhr.onerror = function () {
            if (progressContainer) progressContainer.style.display = 'none';
            toast(tr('ml_upload_error', 'Network error during upload'), 'error');
        };
        xhr.send(formData);
    }

    async function deleteFile(filename) {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('path', currentFolder ? currentFolder + '/' + filename : filename);
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast(tr('ml_file_deleted', 'File deleted'));
                loadImages();
            } else {
                throw new Error(d.message || 'Delete error');
            }
        } catch (err) {
            toast(tr('ml_upload_error', 'Error') + ': ' + err.message, 'error');
        }
    }

    async function bulkDelete() {
        if (selectedFiles.size === 0) return;
        if (!confirm('Delete ' + selectedFiles.size + ' file(s)?')) return;
        const names = Array.from(selectedFiles);
        let okCount = 0;
        for (const name of names) {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('path', currentFolder ? currentFolder + '/' + name : name);
            fd.append('csrf_token', csrfToken);
            try {
                const r = await fetch('api_media.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.new_csrf_token) csrfToken = d.new_csrf_token;
                if (d.status === 'success') okCount++;
            } catch (e) { /* skip */ }
        }
        toast(tr('ml_deleted_count', 'Deleted') + ': ' + okCount + ' / ' + names.length);
        selectedFiles.clear();
        loadImages();
    }

    // ============== FOLDER OPERATIONS ==============

    async function createFolder(name) {
        if (!name) return;
        const fd = new FormData();
        fd.append('action', 'create_folder');
        fd.append('folder', currentFolder);
        fd.append('name', name);
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast('Папка создана: ' + name);
                loadImages();
            } else {
                throw new Error(d.message || 'Error');
            }
        } catch (err) {
            toast('Ошибка: ' + err.message, 'error');
        }
    }

    async function deleteFolder(path) {
        if (!path) return;
        if (!confirm('Удалить папку "' + path + '" и всё её содержимое?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_folder');
        fd.append('folder', path);
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast('Папка удалена');
                loadImages();
            } else {
                throw new Error(d.message || 'Error');
            }
        } catch (err) {
            toast('Ошибка: ' + err.message, 'error');
        }
    }

    async function renameFolder(path) {
        if (!path) return;
        var newName = prompt('Новое имя папки:', path.split('/').pop());
        if (!newName || newName === path.split('/').pop()) return;
        const fd = new FormData();
        fd.append('action', 'rename_folder');
        fd.append('folder', path);
        fd.append('new_name', newName);
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast('Папка переименована');
                loadImages();
            } else {
                throw new Error(d.message || 'Error');
            }
        } catch (err) {
            toast('Ошибка: ' + err.message, 'error');
        }
    }

    // Folder picker state
    let folderPickerCurrent = '';
    let folderPickerMode = '';  // 'move' or 'copy'

    async function showFolderPicker(mode) {
        folderPickerMode = mode;
        folderPickerCurrent = '';
        document.getElementById('folderPickerTitle').textContent = mode === 'move' ? 'Переместить в папку' : 'Скопировать в папку';
        document.getElementById('folderPickerModal').classList.add('is-open');
        await loadFolderPicker('');
    }

    async function loadFolderPicker(path) {
        folderPickerCurrent = path;
        try {
            const url = 'api_media.php?action=list_folders&csrf_token=' + encodeURIComponent(csrfToken) + '&folder=' + encodeURIComponent(path);
            const r = await fetch(url);
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                renderFolderPickerCrumbs(path);
                renderFolderPickerList(d.folders || []);
            }
        } catch (e) {
            toast('Ошибка: ' + e.message, 'error');
        }
    }

    function renderFolderPickerCrumbs(path) {
        var el = document.getElementById('folderPickerCrumbs');
        var parts = path ? path.split('/') : [];
        var html = '<button type="button" class="px-2 py-0.5 rounded text-blue-600 hover:bg-slate-100" data-path="">uploads</button>';
        var acc = '';
        parts.forEach(function(p, i) {
            acc = acc ? acc + '/' + p : p;
            html += '<span class="text-slate-300">/</span>';
            html += '<button type="button" class="px-2 py-0.5 rounded text-slate-600 hover:bg-slate-100" data-path="' + escapeHtml(acc) + '">' + escapeHtml(p) + '</button>';
        });
        el.innerHTML = html;
        el.querySelectorAll('button').forEach(function(btn) {
            btn.addEventListener('click', function() { loadFolderPicker(btn.dataset.path); });
        });
    }

    function renderFolderPickerList(folders) {
        var el = document.getElementById('folderPickerList');
        if (!folders.length) {
            el.innerHTML = '<div class="text-center text-slate-400 py-4 text-sm">Нет подпапок. Можно создать новую ниже.</div>';
            return;
        }
        el.innerHTML = folders.map(function(f) {
            return '<button type="button" class="w-full flex items-center gap-2 px-3 py-2 rounded hover:bg-slate-100 text-left" data-path="' + escapeHtml(f.path) + '">' +
                '<i class="fas fa-folder text-amber-500"></i><span class="text-sm text-slate-700">' + escapeHtml(f.name) + '</span>' +
                '</button>';
        }).join('');
        el.querySelectorAll('button').forEach(function(btn) {
            btn.addEventListener('click', function() { loadFolderPicker(btn.dataset.path); });
        });
    }

    async function confirmFolderPicker() {
        var names = Array.from(selectedFiles);
        if (!names.length) { toast('Нет выбранных файлов', 'warning'); return; }
        var action = folderPickerMode === 'move' ? 'move_files' : 'copy_files';
        var fd = new FormData();
        fd.append('action', action);
        fd.append('from_folder', currentFolder);
        fd.append('to_folder', folderPickerCurrent);
        names.forEach(function(n) { fd.append('names[]', n); });
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast(d.message);
                document.getElementById('folderPickerModal').classList.remove('is-open');
                selectedFiles.clear();
                loadImages();
            } else {
                throw new Error(d.message || 'Error');
            }
        } catch (err) {
            toast('Ошибка: ' + err.message, 'error');
        }
    }

    async function createFolderInPicker() {
        var input = document.getElementById('folderPickerNewName');
        var name = input.value.trim();
        if (!name) return;
        const fd = new FormData();
        fd.append('action', 'create_folder');
        fd.append('folder', folderPickerCurrent);
        fd.append('name', name);
        fd.append('csrf_token', csrfToken);
        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                input.value = '';
                loadFolderPicker(folderPickerCurrent);
            } else {
                throw new Error(d.message || 'Error');
            }
        } catch (err) {
            toast('Ошибка: ' + err.message, 'error');
        }
    }

    function copyUrl(url, silent) {
        // url is relative to site root like /uploads/path/file.jpg
        const fullUrl = window.location.origin + (url.startsWith('/') ? url : '/' + url);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullUrl).then(function () {
                if (!silent) toast(tr('ml_url_copied', 'URL copied') + ': ' + fullUrl);
            }).catch(function () { fallbackCopy(fullUrl); if (!silent) toast(tr('ml_url_copied', 'URL copied')); });
        } else {
            fallbackCopy(fullUrl);
            if (!silent) toast(tr('ml_url_copied', 'URL copied'));
        }
    }

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    function bulkCopyUrls() {
        if (selectedFiles.size === 0) return;
        const urls = [];
        allImages.forEach(function (img) {
            if (selectedFiles.has(img.name)) {
                urls.push(window.location.origin + '/uploads/images/' + img.name);
            }
        });
        const text = urls.join('\n');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                toast(tr('ml_urls_copied', 'URLs copied') + ': ' + urls.length);
            }).catch(function () { fallbackCopy(text); toast(tr('ml_urls_copied', 'URLs copied') + ': ' + urls.length); });
        } else {
            fallbackCopy(text);
            toast(tr('ml_urls_copied', 'URLs copied') + ': ' + urls.length);
        }
    }

    // ============== PREVIEW MODAL ==============
    function openPreview(name) {
        const image = allImages.find(function (img) { return img.name === name; });
        if (!image) return;
        const modal = document.querySelector(options.previewModal);
        const titleEl = document.querySelector(options.previewTitle);
        const bodyEl = document.querySelector(options.previewBody);
        const footerEl = document.querySelector(options.previewFooter);
        if (!modal || !bodyEl || !footerEl) return;
        if (titleEl) titleEl.textContent = image.name;

        const fullUrl = '..' + image.url;
        if (image.type === 'image') {
            bodyEl.innerHTML = '<img src="' + fullUrl + '" alt="' + escapeHtml(image.name) + '">';
        } else if (image.type === 'video') {
            bodyEl.innerHTML = '<video src="' + fullUrl + '" controls style="max-width:100%;max-height:70vh;"></video>';
        } else if (image.type === 'audio') {
            bodyEl.innerHTML = '<audio src="' + fullUrl + '" controls style="width:100%;"></audio>';
        } else {
            bodyEl.innerHTML = '<div style="text-align:center;padding:60px 20px;"><i class="' + fileIconFor(image.type) + '" style="font-size:80px;color:#94a3b8;"></i><p style="margin-top:16px;color:#64748b;">' + tr('ml_no_preview', 'Preview not available') + '</p></div>';
        }

        const absoluteUrl = window.location.origin + '/uploads/images/' + image.name;
        const dimStr = (image.width && image.height) ? (image.width + ' × ' + image.height + ' px') : '—';
        footerEl.innerHTML =
            '<div class="info-block"><span>Size</span><strong>' + escapeHtml(image.size_formatted) + '</strong></div>' +
            '<div class="info-block"><span>Dimensions</span><strong>' + dimStr + '</strong></div>' +
            '<div class="info-block"><span>Type</span><strong>' + escapeHtml(image.ext || '—') + '</strong></div>' +
            '<div class="info-block"><span>Modified</span><strong>' + escapeHtml(image.modified_formatted) + '</strong></div>' +
            '<input type="text" class="copy-url-input" value="' + escapeHtml(absoluteUrl) + '" readonly id="previewUrlInput">' +
            '<button class="aicms-btn aicms-btn--primary aicms-btn--sm" id="previewCopyBtn"><i class="fas fa-copy"></i> ' + tr('copy_url', 'Copy') + '</button>' +
            '<a class="aicms-btn aicms-btn--ghost aicms-btn--sm" href="' + fullUrl + '" target="_blank"><i class="fas fa-external-link-alt"></i> Open</a>';

        const copyBtn = document.getElementById('previewCopyBtn');
        if (copyBtn) copyBtn.addEventListener('click', function () {
            const input = document.getElementById('previewUrlInput');
            if (input) {
                input.select();
                fallbackCopy(input.value);
                toast(tr('ml_url_copied', 'URL copied'));
            }
        });

        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closePreview() {
        const modal = document.querySelector(options.previewModal);
        if (modal) modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // ============== URL UPLOAD MODAL ==============
    function openUrlModal() {
        const modal = document.querySelector(options.urlModal);
        if (!modal) return;
        modal.classList.add('is-open');
        const input = document.querySelector(options.urlInput);
        if (input) { input.value = ''; setTimeout(function () { input.focus(); }, 50); }
    }
    function closeUrlModal() {
        const modal = document.querySelector(options.urlModal);
        if (modal) modal.classList.remove('is-open');
    }

    async function submitUrlUpload() {
        const input = document.querySelector(options.urlInput);
        if (!input || !input.value.trim()) { toast(tr('ml_enter_url', 'Enter URL'), 'warning'); return; }
        const url = input.value.trim();

        // Fetch the remote file via a server-side proxy endpoint to avoid CORS
        // We use the upload endpoint with a special "from_url" parameter
        const fd = new FormData();
        fd.append('action', 'upload_from_url');
        fd.append('url', url);
        fd.append('folder', currentFolder);
        fd.append('csrf_token', csrfToken);

        const submitBtn = document.querySelector(options.urlSubmit);
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...'; }

        try {
            const r = await fetch('api_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.new_csrf_token) csrfToken = d.new_csrf_token;
            if (d.status === 'success') {
                toast(tr('ml_loaded', 'Loaded') + ': ' + (d.filename || ''));
                closeUrlModal();
                loadImages();
            } else {
                throw new Error(d.message || tr('ml_upload_error', 'Upload error'));
            }
        } catch (err) {
            toast(tr('ml_upload_error', 'Error') + ': ' + err.message, 'error');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-download"></i> ' + tr('upload', 'Upload'); }
        }
    }

    // ============== EVENT WIRING ==============

    // Upload button + file input
    if (!options.isModal) {
        const uploadButton = document.querySelector(options.uploadButton);
        const fileInput = document.querySelector(options.fileInput);
        if (uploadButton) uploadButton.addEventListener('click', function () { if (fileInput) fileInput.click(); });
        if (fileInput) fileInput.addEventListener('change', function (e) { uploadFiles(e.target.files); e.target.value = ''; });

        // Drag-and-drop on the whole grid area
        container.addEventListener('dragover', function (e) {
            e.preventDefault();
            container.style.outline = '2px dashed #2563eb';
            container.style.outlineOffset = '-8px';
            container.style.background = 'rgba(37,99,235,0.04)';
        });
        container.addEventListener('dragleave', function () {
            container.style.outline = '';
            container.style.background = '';
        });
        container.addEventListener('drop', function (e) {
            e.preventDefault();
            container.style.outline = '';
            container.style.background = '';
            if (e.dataTransfer && e.dataTransfer.files.length) {
                uploadFiles(e.dataTransfer.files);
            }
        });

        // Search
        const searchInput = options.searchInput ? document.querySelector(options.searchInput) : null;
        if (searchInput) {
            let searchTimer = null;
            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimer);
                const val = e.target.value.toLowerCase().trim();
                searchTimer = setTimeout(function () {
                    currentSearch = val;
                    applyFilters();
                }, 180);
            });
        }

        // Type filter
        const typeFilter = options.typeFilter ? document.querySelector(options.typeFilter) : null;
        if (typeFilter) {
            typeFilter.addEventListener('change', function (e) {
                currentTypeFilter = e.target.value;
                applyFilters();
            });
        }

        // Sort
        const sortSelect = options.sortSelect ? document.querySelector(options.sortSelect) : null;
        if (sortSelect) {
            sortSelect.addEventListener('change', function (e) {
                currentSort = e.target.value;
                applyFilters();
            });
        }

        // View toggle
        const viewGridBtn = options.viewGridBtn ? document.querySelector(options.viewGridBtn) : null;
        const viewListBtn = options.viewListBtn ? document.querySelector(options.viewListBtn) : null;
        if (viewGridBtn) viewGridBtn.addEventListener('click', function () { setView('grid'); });
        if (viewListBtn) viewListBtn.addEventListener('click', function () { setView('list'); });

        // Bulk actions
        const bulkDeleteBtn = options.bulkDeleteBtn ? document.querySelector(options.bulkDeleteBtn) : null;
        const bulkCopyBtn = options.bulkCopyBtn ? document.querySelector(options.bulkCopyBtn) : null;
        const bulkClearBtn = options.bulkClearBtn ? document.querySelector(options.bulkClearBtn) : null;
        if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', bulkDelete);
        if (bulkCopyBtn) bulkCopyBtn.addEventListener('click', bulkCopyUrls);
        if (bulkClearBtn) bulkClearBtn.addEventListener('click', clearSelection);

        // NEW: Bulk move/copy to folder
        const bulkMoveBtn = options.bulkMoveBtn ? document.querySelector(options.bulkMoveBtn) : null;
        const bulkCopyFilesBtn = options.bulkCopyFilesBtn ? document.querySelector(options.bulkCopyFilesBtn) : null;
        if (bulkMoveBtn) bulkMoveBtn.addEventListener('click', function() { if (selectedFiles.size === 0) { toast('Выберите файлы', 'warning'); return; } showFolderPicker('move'); });
        if (bulkCopyFilesBtn) bulkCopyFilesBtn.addEventListener('click', function() { if (selectedFiles.size === 0) { toast('Выберите файлы', 'warning'); return; } showFolderPicker('copy'); });

        // NEW: Folder operations (create folder button + modal)
        const newFolderBtn = options.newFolderBtn ? document.querySelector(options.newFolderBtn) : null;
        if (newFolderBtn) newFolderBtn.addEventListener('click', function() {
            var modal = document.getElementById('newFolderModal');
            if (modal) { modal.classList.add('is-open'); var inp = document.getElementById('newFolderName'); if (inp) setTimeout(function() { inp.focus(); }, 50); }
        });
        const newFolderCreateBtn = document.getElementById('newFolderCreate');
        if (newFolderCreateBtn) newFolderCreateBtn.addEventListener('click', function() {
            var name = document.getElementById('newFolderName').value.trim();
            if (!name) { toast('Введите имя папки', 'warning'); return; }
            createFolder(name);
            document.getElementById('newFolderModal').classList.remove('is-open');
            document.getElementById('newFolderName').value = '';
        });
        const newFolderCancelBtn = document.getElementById('newFolderCancel');
        if (newFolderCancelBtn) newFolderCancelBtn.addEventListener('click', function() { document.getElementById('newFolderModal').classList.remove('is-open'); });
        const newFolderCloseBtn = document.getElementById('newFolderClose');
        if (newFolderCloseBtn) newFolderCloseBtn.addEventListener('click', function() { document.getElementById('newFolderModal').classList.remove('is-open'); });
        const newFolderNameInput = document.getElementById('newFolderName');
        if (newFolderNameInput) newFolderNameInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); if (newFolderCreateBtn) newFolderCreateBtn.click(); } });

        // NEW: Folder picker modal events
        const folderPickerCloseBtn = document.getElementById('folderPickerClose');
        if (folderPickerCloseBtn) folderPickerCloseBtn.addEventListener('click', function() { document.getElementById('folderPickerModal').classList.remove('is-open'); });
        const folderPickerCancelBtn = document.getElementById('folderPickerCancel');
        if (folderPickerCancelBtn) folderPickerCancelBtn.addEventListener('click', function() { document.getElementById('folderPickerModal').classList.remove('is-open'); });
        const folderPickerConfirmBtn = document.getElementById('folderPickerConfirm');
        if (folderPickerConfirmBtn) folderPickerConfirmBtn.addEventListener('click', confirmFolderPicker);
        const folderPickerNewBtn = document.getElementById('folderPickerNewBtn');
        if (folderPickerNewBtn) folderPickerNewBtn.addEventListener('click', createFolderInPicker);
        const folderPickerNewInput = document.getElementById('folderPickerNewName');
        if (folderPickerNewInput) folderPickerNewInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); createFolderInPicker(); } });

        // URL upload modal
        const createUrlBtn = options.createUrlBtn ? document.querySelector(options.createUrlBtn) : null;
        if (createUrlBtn) createUrlBtn.addEventListener('click', openUrlModal);
        const urlCloseBtn = options.urlClose ? document.querySelector(options.urlClose) : null;
        if (urlCloseBtn) urlCloseBtn.addEventListener('click', closeUrlModal);
        const urlCancelBtn = options.urlCancel ? document.querySelector(options.urlCancel) : null;
        if (urlCancelBtn) urlCancelBtn.addEventListener('click', closeUrlModal);
        const urlSubmitBtn = options.urlSubmit ? document.querySelector(options.urlSubmit) : null;
        if (urlSubmitBtn) urlSubmitBtn.addEventListener('click', submitUrlUpload);
        const urlInputEl = options.urlInput ? document.querySelector(options.urlInput) : null;
        if (urlInputEl) {
            urlInputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); submitUrlUpload(); }
            });
        }

        // Preview modal
        const previewCloseBtn = options.previewClose ? document.querySelector(options.previewClose) : null;
        if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreview);
        const previewModal = options.previewModal ? document.querySelector(options.previewModal) : null;
        if (previewModal) {
            previewModal.addEventListener('click', function (e) {
                if (e.target === previewModal) closePreview();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closePreview();
                closeUrlModal();
            }
            // Arrow keys for grid navigation (only when not typing in input)
            if (options.mode !== 'modal' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
                e.preventDefault();
                var items = Array.prototype.slice.call(container.querySelectorAll('.media-item'));
                if (!items.length) return;
                // Find currently focused item (last clicked)
                var focused = container.querySelector('.media-item.focused');
                var idx = focused ? items.indexOf(focused) : -1;
                if (idx === -1) { items[0].classList.add('focused'); items[0].scrollIntoView({block:'nearest'}); return; }
                // Compute columns
                var grid = container.querySelector('.media-grid') || items[0].parentElement;
                var cols = 1;
                if (grid && items[0].getBoundingClientRect) {
                    var gridWidth = grid.getBoundingClientRect().width;
                    var itemWidth = items[0].getBoundingClientRect().width;
                    if (itemWidth > 0) cols = Math.max(1, Math.floor(gridWidth / itemWidth));
                }
                var newIdx = idx;
                if (e.key === 'ArrowRight') newIdx = Math.min(items.length - 1, idx + 1);
                else if (e.key === 'ArrowLeft') newIdx = Math.max(0, idx - 1);
                else if (e.key === 'ArrowDown') newIdx = Math.min(items.length - 1, idx + cols);
                else if (e.key === 'ArrowUp') newIdx = Math.max(0, idx - cols);
                if (newIdx !== idx) {
                    focused.classList.remove('focused');
                    items[newIdx].classList.add('focused');
                    items[newIdx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    if (e.shiftKey) {
                        // Shift+Arrow = toggle selection
                        var name = items[newIdx].dataset.name;
                        if (name) {
                            if (selectedFiles.has(name)) selectedFiles.delete(name);
                            else selectedFiles.add(name);
                            updateSelectionUI();
                        }
                    }
                }
            }
            // Enter opens preview of focused item
            if (options.mode !== 'modal' && e.key === 'Enter') {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
                var focused = container.querySelector('.media-item.focused');
                if (focused) { e.preventDefault(); focused.click(); }
            }
        });
    } else {
        // Modal mode (used in edit_page.php etc.)
        document.addEventListener('click', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('btn-select-media')) {
                currentInputTarget = e.target.previousElementSibling;
                const modal = new bootstrap.Modal(document.getElementById('mediaLibraryModal'));
                modal.show();
                loadImages();
            }
        });
    }

    // Click handler for grid items (delegated)
    container.addEventListener('click', async function (e) {
        // Click on a button inside the item
        const btn = e.target.closest('[data-action]');
        if (btn) {
            const action = btn.getAttribute('data-action');
            if (action === 'view') {
                openPreview(btn.getAttribute('data-name'));
                e.stopPropagation();
                return;
            }
            if (action === 'copy') {
                copyUrl(btn.getAttribute('data-url'));
                e.stopPropagation();
                return;
            }
            if (action === 'delete') {
                const filename = btn.getAttribute('data-name');
                if (!confirm('Delete file "' + filename + '" permanently?')) return;
                await deleteFile(filename);
                e.stopPropagation();
                return;
            }
        }

        // Click on the select checkbox
        const checkbox = e.target.closest('.select-checkbox');
        if (checkbox) {
            toggleSelection(checkbox.getAttribute('data-name'), checkbox.checked);
            e.stopPropagation();
            return;
        }

        // Click on the media item itself (not on a button) — toggle selection
        const item = e.target.closest('.media-item');
        if (item) {
            const name = item.getAttribute('data-name');
            if (!name) return;
            const cb = item.querySelector('.select-checkbox');
            if (cb) {
                cb.checked = !cb.checked;
                toggleSelection(name, cb.checked);
            }
        }
    });

    // Initial render + load
    setView(currentView);
    if (!options.isModal || (options.isModal && document.getElementById('mediaLibraryModal') && document.getElementById('mediaLibraryModal').classList.contains('show'))) {
        loadImages();
    }
}
