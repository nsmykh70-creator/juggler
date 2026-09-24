/**
 * Lumina Editor — Context Menu (Right-Click)
 * ===========================================
 * Replaces the default browser context menu with a custom one that provides
 * Cut/Copy/Paste + formatting + insert link/image/video/table/module actions.
 *
 * Usage: initContextMenu(editor)
 */

export function initContextMenu(editor) {
    if (!editor || !editor.editor) {
        console.warn('Lumina ContextMenu: editor not ready');
        return;
    }

    let contextMenu = null;

    // Create the context menu element
    function createMenu(x, y) {
        // Remove any existing menu
        removeMenu();

        const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);
        const sel = window.getSelection();
        const hasSelection = sel && sel.toString().length > 0;
        const isImage = editor.selectedImage !== null && editor.selectedImage !== undefined;

        const items = [
            { label: t('cut') || 'Cut', icon: 'fa-cut', action: 'cut', disabled: !hasSelection },
            { label: t('copy') || 'Copy', icon: 'fa-copy', action: 'copy', disabled: !hasSelection },
            { label: t('paste') || 'Paste', icon: 'fa-paste', action: 'paste', disabled: false },
            { type: 'separator' },
            { label: t('bold') || 'Bold', icon: 'fa-bold', action: 'bold', disabled: !hasSelection },
            { label: t('italic') || 'Italic', icon: 'fa-italic', action: 'italic', disabled: !hasSelection },
            { label: t('underline') || 'Underline', icon: 'fa-underline', action: 'underline', disabled: !hasSelection },
            { label: t('strikethrough') || 'Strikethrough', icon: 'fa-strikethrough', action: 'strikeThrough', disabled: !hasSelection },
            { type: 'separator' },
            { label: t('link') || 'Insert Link', icon: 'fa-link', action: 'link', disabled: !hasSelection },
            { label: t('image') || 'Insert Image', icon: 'fa-image', action: 'image' },
            { label: t('video') || 'Insert Video', icon: 'fa-video', action: 'video' },
            { label: t('table') || 'Insert Table', icon: 'fa-table', action: 'table' },
            { label: t('insertModule') || 'Insert Module', icon: 'fa-cube', action: 'module' },
            { label: t('insert_file_link') || 'Insert File Link', icon: 'fa-paperclip', action: 'file_link' },
            { type: 'separator' },
            { label: t('sourceCode') || 'Source Code', icon: 'fa-code', action: 'source' },
            { label: t('preview') || 'Preview', icon: 'fa-eye', action: 'preview' },
        ];

        contextMenu = document.createElement('div');
        contextMenu.className = 'lumina-context-menu';
        contextMenu.style.cssText = 'position:fixed;z-index:100010;min-width:220px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.15);padding:4px;font-family:Inter,system-ui,sans-serif;font-size:13px;';

        items.forEach(item => {
            if (item.type === 'separator') {
                const sep = document.createElement('div');
                sep.style.cssText = 'height:1px;background:#e2e8f0;margin:4px 0;';
                contextMenu.appendChild(sep);
            } else {
                const btn = document.createElement('div');
                btn.className = 'lumina-context-menu-item';
                btn.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:6px;cursor:' + (item.disabled ? 'not-allowed' : 'pointer') + ';transition:background 0.1s;color:' + (item.disabled ? '#cbd5e1' : '#1e293b');
                btn.innerHTML = '<i class="fas ' + item.icon + '" style="width:16px;text-align:center;font-size:12px;"></i><span>' + item.label + '</span>';

                if (!item.disabled) {
                    btn.addEventListener('mouseenter', function() {
                        this.style.background = '#f1f5f9';
                    });
                    btn.addEventListener('mouseleave', function() {
                        this.style.background = 'transparent';
                    });
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        removeMenu();
                        // Use setTimeout to ensure menu is removed before modal opens
                        setTimeout(function() {
                            executeAction(item.action);
                        }, 10);
                    });
                }

                contextMenu.appendChild(btn);
            }
        });

        document.body.appendChild(contextMenu);

        // Position menu — make sure it doesn't go off-screen
        const rect = contextMenu.getBoundingClientRect();
        let menuX = x;
        let menuY = y;
        if (menuX + rect.width > window.innerWidth) menuX = window.innerWidth - rect.width - 10;
        if (menuY + rect.height > window.innerHeight) menuY = window.innerHeight - rect.height - 10;
        contextMenu.style.left = menuX + 'px';
        contextMenu.style.top = menuY + 'px';
    }

    function removeMenu() {
        if (contextMenu && contextMenu.parentNode) {
            contextMenu.parentNode.removeChild(contextMenu);
        }
        contextMenu = null;
    }

    function executeAction(action) {
        try {
            editor.editor.focus();
            // Restore selection before opening modals (saved on contextmenu event)
            if (editor.restoreSelection) editor.restoreSelection();
        } catch (e) {}

        switch (action) {
            case 'cut':
                document.execCommand('cut');
                break;
            case 'copy':
                document.execCommand('copy');
                break;
            case 'paste':
                if (!document.execCommand('paste')) {
                    if (window.AicmsToast) AicmsToast.show((editor.t ? editor.t('paste_ctrl_v') : 'Press Ctrl+V to paste'), 'info');
                }
                break;
            case 'bold':
                document.execCommand('bold');
                editor.sync();
                editor.pushHistory(true);
                break;
            case 'italic':
                document.execCommand('italic');
                editor.sync();
                editor.pushHistory(true);
                break;
            case 'underline':
                document.execCommand('underline');
                editor.sync();
                editor.pushHistory(true);
                break;
            case 'strikeThrough':
                document.execCommand('strikeThrough');
                editor.sync();
                editor.pushHistory(true);
                break;
            case 'link':
                if (typeof editor.openLinkModal === 'function') editor.openLinkModal();
                break;
            case 'image':
                if (typeof editor.openImageUpload === 'function') editor.openImageUpload();
                break;
            case 'video':
                if (typeof editor.openVideoModal === 'function') editor.openVideoModal();
                break;
            case 'table':
                if (typeof editor.insertTable === 'function') editor.insertTable();
                break;
            case 'module':
                if (typeof editor.openModulePicker === 'function') editor.openModulePicker();
                break;
            case 'file_link':
                openFilePicker(editor);
                break;
            case 'source':
                if (typeof editor.toggleSource === 'function') editor.toggleSource();
                break;
            case 'preview':
                if (typeof editor.togglePreview === 'function') editor.togglePreview();
                break;
        }
    }

    // Right-click handler on editor
    editor.editor.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        e.stopPropagation();
        // Save selection before opening menu so modals can restore it
        if (editor.saveSelection) editor.saveSelection();
        createMenu(e.clientX, e.clientY);
        return false;
    });

    // Close menu on any click outside
    document.addEventListener('click', function(e) {
        if (contextMenu && !contextMenu.contains(e.target)) {
            removeMenu();
        }
    });

    // Close menu on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && contextMenu) {
            removeMenu();
        }
    });

    // Close menu on scroll
    editor.editor.addEventListener('scroll', removeMenu);
    window.addEventListener('scroll', removeMenu, true);
}

/**
 * Open a file picker modal that lets the user browse /uploads/ and insert
 * a link to the selected file at the current cursor position.
 * Uses api_media.php?action=list (no CSRF for GET, but we pass it anyway).
 */
function openFilePicker(editor) {
    // Remove any existing picker
    var existing = document.getElementById('luminaFilePicker');
    if (existing) existing.remove();

    // Get CSRF token — try multiple sources for compatibility
    var csrf = '';
    if (window._luminaEditorConfig && window._luminaEditorConfig.csrfToken) {
        csrf = window._luminaEditorConfig.csrfToken;
    } else if (window._luminaCsrfToken) {
        csrf = window._luminaCsrfToken;
    } else if (window._luminaEditorConfig && window._luminaEditorConfig.csrf_token) {
        csrf = window._luminaEditorConfig.csrf_token;
    }
    // Fallback: read from meta tag or hidden input if present
    if (!csrf) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) csrf = meta.getAttribute('content');
    }
    if (!csrf) {
        var hidden = document.querySelector('input[name="csrf_token"]');
        if (hidden) csrf = hidden.value;
    }
    var currentFolder = '';
    var currentBreadcrumbs = [];

    var modal = document.createElement('div');
    modal.id = 'luminaFilePicker';
    modal.className = 'lumina-modal-overlay';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:100020;display:flex;align-items:center;justify-content:center;padding:20px;font-family:Inter,system-ui,sans-serif;';
    modal.innerHTML = `
        <div style="background:#fff;border-radius:12px;width:680px;max-width:96vw;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                <h4 style="margin:0;font-size:16px;font-weight:600;color:#1e293b;"><i class="fas fa-paperclip" style="color:#2563eb;margin-right:6px;"></i> Вставить ссылку на файл</h4>
                <button type="button" id="luminaFilePickerClose" style="background:none;border:none;font-size:22px;color:#64748b;cursor:pointer;line-height:1;">&times;</button>
            </div>
            <div id="luminaFilePickerCrumbs" style="padding:10px 18px;border-bottom:1px solid #f1f5f9;font-size:13px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;background:#f8fafc;"></div>
            <div id="luminaFilePickerBody" style="flex:1;overflow-y:auto;padding:12px;">
                <div style="text-align:center;color:#94a3b8;padding:40px 0;"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>
            </div>
            <div style="padding:10px 18px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
                <div style="font-size:12px;color:#64748b;">
                    <i class="fas fa-info-circle"></i> Клик по файлу — вставить ссылку. Двойной клик по папке — войти.
                </div>
                <button type="button" id="luminaFilePickerCancel" style="padding:7px 14px;background:#e2e8f0;color:#475569;border:none;border-radius:6px;cursor:pointer;font-size:13px;">Закрыть</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    function close() { modal.remove(); }
    modal.querySelector('#luminaFilePickerClose').onclick = close;
    modal.querySelector('#luminaFilePickerCancel').onclick = close;
    modal.addEventListener('click', function(e) { if (e.target === modal) close(); });

    function loadFolder(folder) {
        currentFolder = folder;
        var body = modal.querySelector('#luminaFilePickerBody');
        body.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px 0;"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';
        var mediaListUrl = (editor.options && editor.options.mediaListUrl)
            || (window._luminaEditorConfig && window._luminaEditorConfig.media_list_url)
            || 'api_media.php';
        var sep = mediaListUrl.indexOf('?') !== -1 ? '&' : '?';
        var url = mediaListUrl + sep + 'action=list&csrf_token=' + encodeURIComponent(csrf) + '&folder=' + encodeURIComponent(folder);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status + ' — ' + r.statusText);
                }
                return r.text();
            })
            .then(function(text) {
                if (!text || text.trim() === '') {
                    throw new Error('Пустой ответ сервера. Возможно сессия истекла — обновите страницу.');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    // Server returned HTML (probably a login redirect or error page)
                    if (text.indexOf('<html') !== -1 || text.indexOf('<!DOCTYPE') !== -1) {
                        throw new Error('Сессия истекла. Обновите страницу и войдите заново.');
                    }
                    throw new Error('Некорректный JSON: ' + text.substring(0, 100));
                }
            })
            .then(function(d) {
                if (d.status === 'success') {
                    if (d.new_csrf_token) { csrf = d.new_csrf_token; }
                    currentBreadcrumbs = d.breadcrumbs || [];
                    renderCrumbs();
                    renderList(d.folders || [], d.files || []);
                } else {
                    body.innerHTML = '<div style="text-align:center;color:#dc2626;padding:40px 0;"><i class="fas fa-exclamation-triangle"></i> ' + (d.message || 'Ошибка') + '</div>';
                }
            })
            .catch(function(e) {
                body.innerHTML = '<div style="text-align:center;color:#dc2626;padding:40px 0;"><i class="fas fa-exclamation-triangle"></i> ' + e.message + '</div>';
            });
    }

    function renderCrumbs() {
        var el = modal.querySelector('#luminaFilePickerCrumbs');
        var html = '<i class="fas fa-folder-open" style="color:#94a3b8;"></i>';
        currentBreadcrumbs.forEach(function(c, i) {
            var isLast = i === currentBreadcrumbs.length - 1;
            html += '<button type="button" data-path="' + c.path + '" style="background:none;border:none;cursor:pointer;padding:2px 6px;border-radius:4px;font-size:13px;color:' + (isLast ? '#2563eb;font-weight:600;' : '#475569;') + '">' + c.name + '</button>';
            if (!isLast) html += '<span style="color:#cbd5e1;">/</span>';
        });
        el.innerHTML = html;
        el.querySelectorAll('button').forEach(function(btn) {
            btn.onmouseenter = function() { this.style.background = '#e2e8f0'; };
            btn.onmouseleave = function() { this.style.background = 'transparent'; };
            btn.onclick = function() { loadFolder(this.dataset.path); };
        });
    }

    function renderList(folders, files) {
        var body = modal.querySelector('#luminaFilePickerBody');
        if (!folders.length && !files.length) {
            body.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:60px 0;"><i class="fas fa-folder-open" style="font-size:48px;display:block;margin-bottom:12px;"></i>Папка пуста</div>';
            return;
        }
        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;">';

        // Folders first
        folders.forEach(function(f) {
            html += '<div class="lumina-fp-folder" data-path="' + f.path + '" style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;cursor:pointer;text-align:center;transition:all 0.15s;background:#fffbeb;">' +
                '<i class="fas fa-folder" style="font-size:32px;color:#d97706;display:block;margin-bottom:6px;"></i>' +
                '<div style="font-size:12px;color:#475569;word-break:break-all;line-height:1.3;">' + f.name + '</div>' +
                '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + f.file_count + ' файлов</div>' +
                '</div>';
        });

        // Files
        files.forEach(function(f) {
            var icon = 'fa-file';
            var color = '#64748b';
            if (f.type === 'image') { icon = 'fa-image'; color = '#7c3aed'; }
            else if (f.type === 'video') { icon = 'fa-file-video'; color = '#dc2626'; }
            else if (f.type === 'audio') { icon = 'fa-file-audio'; color = '#0891b2'; }
            else if (f.type === 'archive') { icon = 'fa-file-archive'; color = '#d97706'; }
            else if (f.type === 'document') { icon = 'fa-file-pdf'; color = '#dc2626'; }

            var thumb = f.type === 'image' ? '<img src="' + f.url + '" style="width:100%;height:60px;object-fit:cover;border-radius:4px;">' : '<i class="fas ' + icon + '" style="font-size:32px;color:' + color + ';display:block;margin-bottom:6px;"></i>';

            html += '<div class="lumina-fp-file" data-url="' + f.url + '" data-name="' + f.name + '" data-type="' + f.type + '" style="border:1px solid #e2e8f0;border-radius:8px;padding:8px;cursor:pointer;text-align:center;transition:all 0.15s;background:#fff;">' +
                thumb +
                '<div style="font-size:11px;color:#475569;word-break:break-all;line-height:1.3;margin-top:4px;max-height:32px;overflow:hidden;">' + f.name + '</div>' +
                '<div style="font-size:10px;color:#94a3b8;">' + f.size_formatted + '</div>' +
                '</div>';
        });

        html += '</div>';
        body.innerHTML = html;

        // Wire folder clicks (double-click to enter)
        body.querySelectorAll('.lumina-fp-folder').forEach(function(el) {
            el.onmouseenter = function() { this.style.borderColor = '#2563eb'; this.style.background = '#eff6ff'; };
            el.onmouseleave = function() { this.style.borderColor = '#e2e8f0'; this.style.background = '#fffbeb'; };
            el.ondblclick = function() { loadFolder(this.dataset.path); };
        });

        // Wire file clicks (single click to insert)
        body.querySelectorAll('.lumina-fp-file').forEach(function(el) {
            el.onmouseenter = function() { this.style.borderColor = '#16a34a'; this.style.background = '#f0fdf4'; };
            el.onmouseleave = function() { this.style.borderColor = '#e2e8f0'; this.style.background = '#fff'; };
            el.onclick = function() {
                var url = this.dataset.url;
                var name = this.dataset.name;
                var type = this.dataset.type;
                insertFileLink(editor, url, name, type);
                close();
            };
        });
    }

    // Build absolute URL
    function buildAbsoluteUrl(relativeUrl) {
        return window.location.origin + (relativeUrl.startsWith('/') ? relativeUrl : '/' + relativeUrl);
    }

    function insertFileLink(editor, url, name, type) {
        var fullUrl = buildAbsoluteUrl(url);
        try {
            editor.editor.focus();
            if (editor.restoreSelection) editor.restoreSelection();
            var html;
            if (type === 'image') {
                // Insert as <img>
                html = '<img src="' + fullUrl + '" alt="' + name.replace(/"/g, '&quot;') + '" style="max-width:100%;height:auto;border-radius:8px;">';
            } else if (type === 'video') {
                // Insert as <video>
                html = '<video controls style="max-width:100%;border-radius:8px;"><source src="' + fullUrl + '"></video>';
            } else {
                // Insert as <a> link
                html = '<a href="' + fullUrl + '" target="_blank" rel="noopener">📎 ' + name + '</a>';
            }
            document.execCommand('insertHTML', false, html);
            editor.sync();
            editor.pushHistory(true);
        } catch (e) {
            console.error('Insert file link error:', e);
        }
    }

    // Initial load — start at root
    loadFolder('');
}
