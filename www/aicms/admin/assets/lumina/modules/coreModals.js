import { getIcon } from './icons.js?v=20260901';
import { DEFAULT_BUTTON_ORDER, SELECT_BUTTONS } from './constants.js?v=20260901';
import { insertVideo, uploadFile } from './contentOps.js?v=20260901';
import { saveSelection, restoreSelection } from './selection.js?v=20260901';
import { smartPosition } from './positioning.js?v=20260901';

// AI: using CMS built-in AI (api_generate_content.php)


/**
 * Create a unified modal overlay.
 *
 * Signature createModalOverlay(editor, title, content, btns, opts) is backward
 * compatible: opts is optional. opts.icon (HTML string) renders an icon next
 * to the title; opts.ariaLabel overrides the aria-label for the dialog.
 *
 * Enhancements:
 *  - Overlay fades in, modal slides up (CSS animations in styles.js).
 *  - ESC closes the modal.
 *  - Tab focus trap cycles within the modal.
 *  - aria-modal="true" and role="dialog" set on the overlay.
 *  - Consistent header: icon + title on left, × button on right.
 *  - Footer buttons: primary (blue) and secondary (gray) variants via b.variant.
 */
export function createModalOverlay(editor, title, content, btns = [], opts = {}) {
    const ov = document.createElement('div');
    ov.className = 'lumina-modal-overlay';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', opts.ariaLabel || (typeof title === 'string' ? title : 'Dialog'));

    // Click outside to close
    ov.addEventListener('click', (e) => { if (e.target === ov) closeFn(); });

    const m = document.createElement('div');
    m.className = 'lumina-modal';

    const iconHtml = opts.icon ? `<span class="lumina-modal-header-icon">${opts.icon}</span>` : '';
    m.innerHTML =
        '<div class="lumina-modal-header">' +
            '<div class="lumina-modal-header-left">' +
                iconHtml +
                `<h3 class="lumina-modal-title">${title}</h3>` +
            '</div>' +
            '<button type="button" class="lumina-modal-close x" aria-label="Close">&times;</button>' +
        '</div>' +
        `<div class="lumina-modal-body">${content}</div>` +
        '<div class="lumina-modal-footer"></div>';

    const f = m.querySelector('.lumina-modal-footer');
    btns.forEach(b => {
        const btn = document.createElement('button');
        btn.type = 'button';
        const variant = (b.variant === 'secondary') ? 'lumina-btn-secondary' : 'lumina-btn-primary';
        btn.className = variant;
        btn.innerText = b.text;
        btn.onclick = e => b.onClick(e, m);
        f.appendChild(btn);
    });

    const closeFn = () => {
        if (ov.parentNode) ov.parentNode.removeChild(ov);
        document.removeEventListener('keydown', keyHandler);
    };

    m.querySelector('.lumina-modal-close').addEventListener('click', closeFn);

    // ESC + focus trap (single listener; auto-cleans when overlay leaves the DOM)
    const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const keyHandler = (e) => {
        if (!document.body.contains(ov)) {
            document.removeEventListener('keydown', keyHandler);
            return;
        }
        // ESC closes
        if (e.key === 'Escape' || e.key === 'Esc') {
            e.preventDefault();
            closeFn();
            return;
        }
        // Tab focus trap
        if (e.key === 'Tab') {
            const focusables = m.querySelectorAll(focusableSelector);
            if (focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey) {
                if (document.activeElement === first || !m.contains(document.activeElement)) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last || !m.contains(document.activeElement)) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
    };
    document.addEventListener('keydown', keyHandler);

    ov.appendChild(m);
    document.body.appendChild(ov);

    // Auto-focus first interactive element (skip the × close button when possible)
    setTimeout(() => {
        if (!document.body.contains(ov)) return;
        const focusables = m.querySelectorAll(focusableSelector);
        if (focusables.length === 0) return;
        const firstInteractive = Array.prototype.find.call(focusables, el => !el.classList.contains('lumina-modal-close'));
        (firstInteractive || focusables[0]).focus();
    }, 100);

    return m.querySelector('.lumina-modal-body');
}

export function createGridModal(editor, title, items, callback) {
    let h = '<div class="lumina-grid-selector">';
    items.forEach(it => h += `<button type="button">${it}</button>`);
    h += '</div>';
    const body = createModalOverlay(editor, title, h);
    body.querySelectorAll('button').forEach(bt => bt.onclick = () => {
        callback(bt.innerText);
        body.closest('.lumina-modal-overlay').remove();
    });
}

export function openSettingsModal(editor) {
    // API key removed
    const buttons = editor.settings.buttons || {};
    const modules = editor.settings.modules || {};
    const bubbleMenus = editor.settings.bubbleMenus || { text: true, image: true, block: true, box: true, quickInsert: true, codeBlock: true };

    const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);

    let h = `
        <div class="lumina-tabs" style="margin-bottom:15px;">
            <button type="button" class="lumina-tab active" data-tab="set-general">${t('tab_general')}</button>
            <button type="button" class="lumina-tab" data-tab="set-buttons">${t('tab_toolbarButtons')}</button>
            <button type="button" class="lumina-tab" data-tab="set-bubbles">${t('tab_bubbleMenus')}</button>
            <button type="button" class="lumina-tab" data-tab="set-language">${t('tab_language')}</button>
        </div>
    `;
    
    // --- General Tab ---
    h += `<div id="set-general" class="lumina-tab-content active">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:15px;">
    `;
    
    if (!editor.disableSticky) {
        const stick = editor.settings.stickyToolbar ? 'checked' : '';
        h += `<div style="display:flex;justify-content:space-between;padding:5px;border:1px solid #f1f5f9;border-radius:6px;">
                <span class="lumina-settings-label">${t('settings_stickyToolbar')}</span>
                <label class="lumina-toggle">
                    <input type="checkbox" id="ls-sticky" ${stick}>
                    <span class="lumina-slider"></span>
                </label>
              </div>`;
    }

    // Editor dark mode toggle (only meaningful when admin dark theme is on)
    const darkEditor = editor.settings.darkEditorMode ? 'checked' : '';
    h += `<div style="display:flex;justify-content:space-between;padding:5px;border:1px solid #f1f5f9;border-radius:6px;">
            <span class="lumina-settings-label">${t('settings_darkEditor') || 'Тёмный фон редактора'}</span>
            <label class="lumina-toggle">
                <input type="checkbox" id="ls-dark-editor" ${darkEditor}>
                <span class="lumina-slider"></span>
            </label>
          </div>`;
    
    Object.keys(modules).forEach(m => {
        const c = modules[m] ? 'checked' : '';
        h += `<div style="display:flex;justify-content:space-between;padding:5px;border:1px solid #f1f5f9;border-radius:6px;">
                <span class="lumina-settings-label" style="text-transform:capitalize;">${m} ${t('settings_moduleSuffix')}</span>
                <label class="lumina-toggle">
                    <input type="checkbox" class="ls-mod" data-mod="${m}" ${c}>
                    <span class="lumina-slider"></span>
                </label>
              </div>`;
    });
    h += `</div></div>`;

    // --- Buttons Tab ---
    const btnOrder = editor.settings.buttonOrder || [...DEFAULT_BUTTON_ORDER];
    h += `<div id="set-buttons" class="lumina-tab-content">
            <div style="font-size:12px;color:#666;margin-bottom:10px;">${t('settings_buttonsHint')}</div>
            <div id="lumina-btn-sort-list" class="lumina-btn-sort-list">
    `;
    btnOrder.forEach(k => {
        if (SELECT_BUTTONS.includes(k)) return;
        if (!(k in buttons)) return;
        const c = buttons[k] ? 'checked' : '';
        const label = k.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
        h += `<div class="lumina-btn-sort-item" draggable="true" data-btn-id="${k}">
                <span class="lumina-btn-sort-handle" title="Drag to reorder">⣿</span>
                <span class="lumina-btn-sort-label">${label}</span>
                <label class="lumina-toggle" style="margin-left:auto;">
                    <input type="checkbox" class="ls-btn-toggle" data-btn="${k}" ${c}>
                    <span class="lumina-slider"></span>
                </label>
              </div>`;
    });
    h += `</div>`;
    // Select visibility toggles — using toggle switches (same style as other buttons)
    const selectToggles = SELECT_BUTTONS.filter(k => k in buttons);
    if (selectToggles.length > 0) {
        h += `<div style="margin-top:12px;font-size:11px;color:#999;margin-bottom:6px;">${t('settings_selectsHint')}</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">`;
        selectToggles.forEach(k => {
            const c = buttons[k] ? 'checked' : '';
            const label = k.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
            h += `<div style="display:flex;justify-content:space-between;align-items:center;padding:5px;border:1px solid #f1f5f9;border-radius:6px;">
                    <span class="lumina-settings-label">${label}</span>
                    <label class="lumina-toggle">
                        <input type="checkbox" class="ls-btn-toggle" data-btn="${k}" ${c}>
                        <span class="lumina-slider"></span>
                    </label>
                  </div>`;
        });
        h += `</div>`;
    }
    h += `</div>`;

    // --- Bubble Menus Tab ---
    h += `<div id="set-bubbles" class="lumina-tab-content">
            <div style="font-size:12px;color:#666;margin-bottom:10px;">${t('settings_bubblesHint')}</div>
            <div style="display:flex; flex-direction:column; gap:8px;">
    `;
    const bubbleNames = {
        text: t('settings_bubbleText'),
        image: t('settings_bubbleImage'),
        block: t('settings_bubbleBlock'),
        box: t('settings_bubbleBox'),
        quickInsert: t('settings_bubbleQuickInsert'),
        codeBlock: t('settings_bubbleCodeBlock')
    };
    Object.keys(bubbleNames).forEach(k => {
        const c = bubbleMenus[k] ? 'checked' : '';
        h += `<div style="display:flex;justify-content:space-between;padding:8px;border:1px solid #f1f5f9;border-radius:6px;align-items:center;">
                <span class="lumina-settings-label" style="font-size:13px;">${bubbleNames[k]}</span>
                <label class="lumina-toggle">
                    <input type="checkbox" class="ls-bubble-toggle" data-bubble="${k}" ${c}>
                    <span class="lumina-slider"></span>
                </label>
              </div>`;
    });
    h += `</div></div>`;

    // --- Language Tab ---
    const currentLang = (typeof editor.getLang === 'function') ? editor.getLang() : 'ru';
    const availableLangs = (typeof editor.getAvailableLangs === 'function')
        ? editor.getAvailableLangs()
        : [{ code: 'ru', name: 'Русский' }, { code: 'en', name: 'English' }];
    const langOptions = availableLangs.map(l =>
        `<option value="${l.code}" ${l.code === currentLang ? 'selected' : ''}>${l.name}</option>`
    ).join('');
    h += `<div id="set-language" class="lumina-tab-content">
            <div style="margin-top:15px;display:flex;flex-direction:column;gap:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border:1px solid #f1f5f9;border-radius:6px;">
                    <span class="lumina-settings-label">${t('settings_languageLabel')}:</span>
                    <select id="ls-lang" class="lumina-select" style="width:160px;padding:6px 10px;">
                        ${langOptions}
                    </select>
                </div>
                <div class="lumina-modal-hint-box">
                    ℹ️ ${t('settings_languageNote')}
                </div>
            </div>
          </div>`;

    const modalBody = createModalOverlay(editor, t('modal_settings'), h, [{
        text: t('btn_save'),
        onClick: (e, b) => {
            if (!editor.disableSticky) {
                const stickyEl = b.querySelector('#ls-sticky');
                if (stickyEl) editor.settings.stickyToolbar = stickyEl.checked;
            }
            // apiKey removed
            
            b.querySelectorAll('.ls-mod').forEach(k => {
                editor.settings.modules[k.dataset.mod] = k.checked;
            });
            b.querySelectorAll('.ls-btn-toggle').forEach(k => {
                editor.settings.buttons[k.dataset.btn] = k.checked;
            });
            // Collect button order from the drag list
            const sortOrder = [];
            b.querySelectorAll('#lumina-btn-sort-list .lumina-btn-sort-item').forEach(item => {
                sortOrder.push(item.dataset.btnId);
            });
            editor.settings.buttonOrder = sortOrder;
            
            // Save Bubble Settings
            b.querySelectorAll('.ls-bubble-toggle').forEach(k => {
                editor.settings.bubbleMenus[k.dataset.bubble] = k.checked;
            });

            // Save Dark Editor Mode
            const darkEditorCheckbox = b.querySelector('#ls-dark-editor');
            if (darkEditorCheckbox) {
                editor.settings.darkEditorMode = darkEditorCheckbox.checked;
                if (editor.editor) {
                    if (darkEditorCheckbox.checked) {
                        editor.editor.classList.add('lumina-editor-dark-mode');
                    } else {
                        editor.editor.classList.remove('lumina-editor-dark-mode');
                    }
                }
            }

            // Save Language (and reload to apply)
            const langSelect = b.querySelector('#ls-lang');
            const newLang = langSelect ? langSelect.value : currentLang;
            const langChanged = newLang !== currentLang;
            if (typeof editor.setLang === 'function') editor.setLang(newLang);

            editor.saveSettings();
            b.closest('.lumina-modal-overlay').remove();
            editor.reRenderToolbar();
            editor.updateFloatingControls(); // Refresh visibility immediately

            // Reload the page so the new language is applied across the editor
            if (langChanged) {
                setTimeout(() => window.location.reload(), 50);
            }
        }
    }, {
        text: t('btn_cancel'),
        variant: 'secondary',
        onClick: (e, b) => {
            b.closest('.lumina-modal-overlay').remove();
        }
    }], { icon: getIcon('settings') });

    modalBody.querySelectorAll('.lumina-tab').forEach(t2 => {
        t2.onclick = () => {
            modalBody.querySelectorAll('.lumina-tab').forEach(x => x.classList.remove('active'));
            modalBody.querySelectorAll('.lumina-tab-content').forEach(x => x.classList.remove('active'));
            t2.classList.add('active');
            modalBody.querySelector(`#${t2.dataset.tab}`).classList.add('active');
        };
    });

    // Drag-and-drop for button reordering
    const sortList = modalBody.querySelector('#lumina-btn-sort-list');
    if (sortList) {
        let dragItem = null;

        sortList.addEventListener('dragstart', (e) => {
            const item = e.target.closest('.lumina-btn-sort-item');
            if (!item) return;
            dragItem = item;
            setTimeout(() => item.classList.add('lumina-btn-sort-dragging'), 0);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        sortList.addEventListener('dragend', (e) => {
            const item = e.target.closest('.lumina-btn-sort-item');
            if (item) item.classList.remove('lumina-btn-sort-dragging');
            sortList.querySelectorAll('.lumina-btn-sort-item').forEach(el => {
                el.classList.remove('lumina-btn-sort-over');
            });
            dragItem = null;
        });

        sortList.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!dragItem) return;
            const afterElement = getDragAfterElement(sortList, e.clientY);
            sortList.querySelectorAll('.lumina-btn-sort-item').forEach(el => {
                el.classList.remove('lumina-btn-sort-over');
            });
            if (afterElement) {
                afterElement.classList.add('lumina-btn-sort-over');
                sortList.insertBefore(dragItem, afterElement);
            } else {
                sortList.appendChild(dragItem);
            }
        });

        function getDragAfterElement(container, y) {
            const elements = [...container.querySelectorAll('.lumina-btn-sort-item:not(.lumina-btn-sort-dragging)')];
            return elements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    }

}

export function openTooltipModal(editor, targetElement = null) {
    editor.saveSelection();
    let currentTitle = '';
    if (targetElement) { 
        currentTitle = targetElement.getAttribute('title') || ''; 
    } else {
        editor.restoreSelection(); 
        const sel = window.getSelection(); 
        if (sel.rangeCount > 0) { 
            let node = sel.anchorNode; 
            if (node.nodeType === 3) node = node.parentElement; 
            const existingSpan = node.closest('.lumina-tooltip, [title]'); 
            if (existingSpan && editor.editor.contains(existingSpan)) { 
                currentTitle = existingSpan.getAttribute('title') || ''; 
                targetElement = existingSpan; 
            } 
        } 
    } 
    const h = `<div class="lbs-row"><label style="width:auto;margin-right:10px;">Tooltip Text:</label><input type="text" id="tooltip-text" value="${currentTitle.replace(/"/g, '&quot;')}" class="lumina-select" style="flex:1" placeholder="Enter hover text..."></div><div style="font-size:11px;color:#666;margin-top:5px;">Leave empty to remove tooltip.</div>`; 
    
    createModalOverlay(editor, 'Set Tooltip', h, [{ 
        text: 'Apply', 
        onClick: (e, modal) => { 
            const text = modal.querySelector('#tooltip-text').value; 
            editor.restoreSelection(); 
            if (targetElement) { 
                if (text) { 
                    targetElement.setAttribute('title', text); 
                    if (targetElement.tagName === 'SPAN') targetElement.classList.add('lumina-tooltip'); 
                } else { 
                    targetElement.removeAttribute('title'); 
                    targetElement.classList.remove('lumina-tooltip'); 
                } 
            } else { 
                if (text) { 
                    const span = document.createElement('span'); 
                    span.className = 'lumina-tooltip'; 
                    span.title = text; 
                    const sel = window.getSelection(); 
                    if (sel.rangeCount > 0 && !sel.isCollapsed) { 
                        const range = sel.getRangeAt(0); 
                        try { range.surroundContents(span); } 
                        catch (e) { 
                            const content = range.extractContents(); 
                            span.appendChild(content); 
                            range.insertNode(span); 
                        } 
                    } 
                } 
            } 
            modal.closest('.lumina-modal-overlay').remove(); 
            editor.sync(); 
            editor.pushHistory(true); 
        } 
    }]); 
    setTimeout(() => { 
        const input = document.getElementById('tooltip-text'); 
        if(input) input.focus(); 
    }, 50); 
}

export function openLinkModal(editor) {
    editor.saveSelection();
    const content = `<div class="lbs-row"><label style="width: auto; margin-right: 10px;">URL</label><input type="text" id="lumina-link-url" value="https://" class="lumina-modal-input"></div><div class="lbs-row" style="margin-top:10px;"><label style="width: auto; margin-right: 10px;"></label><div style="display:flex; align-items:center;"><input type="checkbox" id="lumina-link-target" style="margin-right:8px; width:16px; height:16px;"><span class="lumina-settings-label" style="font-size:12px;">Open in new tab</span></div></div>`;
    
    const modalBody = createModalOverlay(editor, 'Insert Link', content, [{
        text: 'Insert Link',
        onClick: (e, modal) => {
            const url = modal.querySelector('#lumina-link-url').value;
            const targetBlank = modal.querySelector('#lumina-link-target').checked;
            
            if (url) {
                editor.editor.focus();
                editor.restoreSelection();
                document.execCommand('createLink', false, url);
                
                if (targetBlank) {
                    const sel = window.getSelection();
                    if (sel.rangeCount > 0) {
                        let node = sel.anchorNode;
                        if (node.nodeType === 3) node = node.parentElement;
                        const anchor = node.closest('a');
                        if (anchor) {
                            anchor.target = '_blank';
                        }
                    }
                }
            }
            modal.closest('.lumina-modal-overlay').remove();
            editor.sync();
            editor.pushHistory(true);
        }
    }]);
    
    setTimeout(() => {
        const input = modalBody.querySelector('#lumina-link-url');
        if(input) {
            input.focus();
            const val = input.value;
            input.value = '';
            input.value = val;
        }
    }, 50);
}

export function openAnchorModal(editor) {
    editor.saveSelection(); 
    const h = `<div class="lbs-row"><label style="width: auto; margin-right: 10px;">Anchor ID:</label><input type="text" id="anchor-id" class="lumina-modal-input" placeholder="e.g. section-1"></div><div class="lumina-settings-hint" style="margin-top:8px;">Use this ID to link here (e.g. #section-1)</div>`; 
    createModalOverlay(editor, 'Set Anchor', h, [{ 
        text: 'Set ID', 
        onClick: (e, modal) => { 
            const id = modal.querySelector('#anchor-id').value.replace(/[^a-zA-Z0-9-_]/g, ''); 
            if (id) { 
                editor.restoreSelection(); 
                const sel = window.getSelection(); 
                let node = sel.anchorNode; 
                if(node.nodeType === 3) node = node.parentElement; 
                
                const block = node.closest('h1, h2, h3, h4, p, div, section'); 
                if(block && editor.editor.contains(block)) { 
                    block.id = id; 
                } else { 
                    const span = document.createElement('span');
                    span.id = id;
                    span.innerHTML = '&nbsp;';
                    const range = sel.getRangeAt(0);
                    range.insertNode(span);
                } 
                editor.sync(); 
                editor.pushHistory(true); 
            } 
            modal.closest('.lumina-modal-overlay').remove(); 
        } 
    }]); 
    
    setTimeout(() => {
         const input = document.getElementById('anchor-id');
         if(input) input.focus();
    }, 50);
}

export function openAiModal(editor) {
    editor.saveSelection();
    const cmsAiUrl = editor.cmsAiUrl || 'api_generate_content.php';
    const h = `
        <div class="lumina-ai-header-bar"><div class="lah-title">\u2728 AI \u0410\u0441\u0441\u0438\u0441\u0442\u0435\u043D\u0442</div><button type="button" class="lah-close" title="Close">&times;</button></div>
        <div class="lumina-ai-content">
            <div class="lumina-tabs" style="margin-bottom:12px;">
                <button type="button" class="lumina-tab active" data-ai-tab="ai-text"><i class="fas fa-font"></i> Текст</button>
                <button type="button" class="lumina-tab" data-ai-tab="ai-image"><i class="fas fa-image"></i> Изображение</button>
                <button type="button" class="lumina-tab" data-ai-tab="ai-video"><i class="fas fa-video"></i> Видео</button>
                <button type="button" class="lumina-tab" data-ai-tab="ai-settings"><i class="fas fa-cog"></i> Настройки AI</button>
            </div>

            <!-- TEXT TAB -->
            <div id="ai-text" class="lumina-ai-pane active">
                <div class="lumina-ai-input-group" style="flex-direction:column; align-items:stretch;">
                    <div style="margin-bottom:8px;">
                        <select id="ai-prompt-preset" class="lumina-select" style="width:100%; padding:6px 10px; font-size:13px;">
                            <option value="">— Шаблон промпта —</option>
                            <option value="article">📝 Статья на тему...</option>
                            <option value="product">🛒 Описание товара...</option>
                            <option value="blog">✍️ Запись в блог...</option>
                            <option value="about">ℹ️ О компании...</option>
                            <option value="faq">❓ FAQ (вопрос-ответ)...</option>
                            <option value="news">📰 Новость...</option>
                            <option value="review">⭐ Обзор...</option>
                            <option value="tutorial">📚 Инструкция/гайд...</option>
                        </select>
                    </div>
                    <textarea id="ai-prompt-text" class="lumina-modal-textarea" placeholder="Опишите, что нужно написать..." style="height:120px;"></textarea>
                    <button type="button" class="lumina-btn-primary" id="btn-gen-text" style="align-self:flex-end; margin-top:8px;">✨ Сгенерировать текст</button>
                </div>
            </div>

            <!-- IMAGE TAB -->
            <div id="ai-image" class="lumina-ai-pane" style="display:none;">
                <div class="lumina-ai-input-group" style="flex-direction:column; align-items:stretch;">
                    <textarea id="ai-prompt-image" class="lumina-modal-textarea" placeholder="Опишите изображение на английском (лучший результат)..." style="height:100px;"></textarea>
                    <button type="button" class="lumina-btn-primary" id="btn-gen-image" style="align-self:flex-end; margin-top:8px;">✨ Сгенерировать изображение</button>
                </div>
            </div>

            <!-- VIDEO TAB -->
            <div id="ai-video" class="lumina-ai-pane" style="display:none;">
                <div class="lumina-ai-input-group" style="flex-direction:column; align-items:stretch;">
                    <textarea id="ai-prompt-video" class="lumina-modal-textarea" placeholder="Опишите видео на английском..." style="height:100px;"></textarea>
                    <div class="lumina-modal-warn-box" style="margin-top:8px;">⚠️ Генерация видео может занимать до 5 минут.</div>
                    <button type="button" class="lumina-btn-primary" id="btn-gen-video" style="align-self:flex-end; margin-top:8px;">✨ Сгенерировать видео</button>
                </div>
            </div>

            <!-- AI SETTINGS TAB -->
            <div id="ai-settings" class="lumina-ai-pane" style="display:none;">
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;font-size:12px;color:#1e40af;">
                        Настройте провайдеров AI. Ключи хранятся в config.json.
                    </div>

                    <div style="font-weight:600;font-size:13px;color:#1e293b;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">📝 Текст</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                        <select id="ai-text-provider" class="lumina-select" style="width:100%;">
                            <option value="openrouter">OpenRouter (100+ моделей)</option>
                            <option value="deepseek">DeepSeek</option>
                            <option value="groq">Groq (Llama, Mixtral)</option>
                            <option value="zai">Z.ai (GLM-4)</option>
                            <option value="google">Google AI (Gemini)</option>
                            <option value="openai">OpenAI (GPT-4)</option>
                            <option value="anthropic">Anthropic (Claude)</option>
                            <option value="mistral">Mistral AI</option>
                            <option value="together">Together AI</option>
                        </select>
                        <input type="text" id="ai-text-model" class="lumina-select" style="width:100%;" placeholder="gemini-1.5-flash">
                    </div>
                    <input type="password" id="ai-text-key" class="lumina-select" style="width:100%;" placeholder="API Key">

                    <div style="font-weight:600;font-size:13px;color:#1e293b;border-bottom:1px solid #e2e8f0;padding-bottom:4px;margin-top:6px;">🖼️ Изображения</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                        <select id="ai-image-provider" class="lumina-select" style="width:100%;">
                            <option value="pollinations">Pollinations (Free, без ключа)</option>
                            <option value="google_img">Google (Imagen 3)</option>
                            <option value="zai">Z.ai (CogView)</option>
                            <option value="stability">Stability AI (SDXL)</option>
                            <option value="openai_img">OpenAI (DALL-E 3)</option>
                            <option value="together_img">Together AI (SDXL)</option>
                        </select>
                        <input type="text" id="ai-image-model" class="lumina-select" style="width:100%;" placeholder="flux / imagen-3.0-generate-002">
                    </div>
                    <input type="password" id="ai-image-key" class="lumina-select" style="width:100%;" placeholder="API Key (Pollinations — не нужен)">

                    <div style="font-weight:600;font-size:13px;color:#1e293b;border-bottom:1px solid #e2e8f0;padding-bottom:4px;margin-top:6px;">🎬 Видео</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                        <select id="ai-video-provider" class="lumina-select" style="width:100%;">
                            <option value="disabled">Отключено</option>
                            <option value="google_video">Google (Veo)</option>
                            <option value="zai">Z.ai (CogVideoX)</option>
                            <option value="stable">Stability Video</option>
                        </select>
                        <input type="text" id="ai-video-model" class="lumina-select" style="width:100%;" placeholder="veo-2.0-generate-001">
                    </div>
                    <input type="password" id="ai-video-key" class="lumina-select" style="width:100%;" placeholder="API Key">

                    <div class="lumina-modal-hint-box" style="font-size:11px;padding:6px 8px;">
                        💡 <b>Google AI</b> — бесплатный ключ: aistudio.google.com/app/apikey<br>
                        💡 <b>Groq</b> — быстро и бесплатно: console.groq.com/keys<br>
                        💡 <b>OpenRouter</b> — 100+ моделей: openrouter.ai/keys
                    </div>

                    <button type="button" id="ai-settings-save-btn" style="padding:8px 20px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Сохранить настройки</button>
                    <div id="ai-settings-msg" style="font-size:12px;display:none;padding:6px;border-radius:4px;"></div>
                </div>
            </div>

            <div class="lumina-ai-loader" style="display:none;">Генерация...</div>
        </div>`;

    const modalBody = createModalOverlay(editor, 'AI', h, []);
    modalBody.closest('.lumina-modal').querySelector('.lumina-modal-header').style.display = 'none';
    modalBody.querySelector('.lah-close').onclick = () => { modalBody.closest('.lumina-modal-overlay').remove(); };

    // Tab switching
    modalBody.querySelectorAll('[data-ai-tab]').forEach(t => {
        t.onclick = () => {
            modalBody.querySelectorAll('[data-ai-tab]').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            const target = t.dataset.aiTab;
            modalBody.querySelectorAll('.lumina-ai-pane').forEach(p => p.style.display = 'none');
            const pane = modalBody.querySelector('#' + target);
            if (pane) pane.style.display = 'block';
            // When settings tab opens, load current AI config
            if (target === 'ai-settings') loadAiSettings(modalBody);
        };
    });

    // AI Settings: load and save
    function loadAiSettings(mb) {
        fetch('api_ai_settings.php').then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) return;
            var setVal = function(id, val) { var el = mb.querySelector('#' + id); if (el && val) el.value = val; };
            setVal('ai-text-provider', data.text && data.text.provider);
            setVal('ai-text-model', data.text && data.text.model);
            setVal('ai-text-key', data.text && data.text.api_key);
            setVal('ai-image-provider', data.image && data.image.provider);
            setVal('ai-image-model', data.image && data.image.model);
            setVal('ai-image-key', data.image && data.image.api_key);
            setVal('ai-video-provider', data.video && data.video.provider);
            setVal('ai-video-model', data.video && data.video.model);
            setVal('ai-video-key', data.video && data.video.api_key);
        }).catch(function() {});
    }

    var aiSettingsSaveBtn = modalBody.querySelector('#ai-settings-save-btn');
    if (aiSettingsSaveBtn) {
        aiSettingsSaveBtn.onclick = function() {
            var getVal = function(id) { var el = modalBody.querySelector('#' + id); return el ? el.value : ''; };
            var msg = modalBody.querySelector('#ai-settings-msg');
            aiSettingsSaveBtn.innerHTML = 'Сохранение...';
            aiSettingsSaveBtn.disabled = true;
            var fd = new FormData();
            fd.append('text_provider', getVal('ai-text-provider'));
            fd.append('text_model', getVal('ai-text-model'));
            fd.append('text_api_key', getVal('ai-text-key'));
            fd.append('image_provider', getVal('ai-image-provider'));
            fd.append('image_model', getVal('ai-image-model'));
            fd.append('image_api_key', getVal('ai-image-key'));
            fd.append('video_provider', getVal('ai-video-provider'));
            fd.append('video_model', getVal('ai-video-model'));
            fd.append('video_api_key', getVal('ai-video-key'));
            fd.append('csrf_token', window._luminaCsrfToken || '');
            var aiSettingsUrl = (editor.options && editor.options.aiSettingsUrl)
                || (window._luminaEditorConfig && window._luminaEditorConfig.ai_settings_url)
                || 'api_ai_settings.php';
            fetch(aiSettingsUrl, { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(data) {
                aiSettingsSaveBtn.innerHTML = 'Сохранить настройки';
                aiSettingsSaveBtn.disabled = false;
                if (msg) {
                    msg.style.display = 'block';
                    msg.style.background = data.status === 'success' ? '#d1e7dd' : '#f8d7da';
                    msg.style.color = data.status === 'success' ? '#0f5132' : '#842029';
                    msg.textContent = data.status === 'success' ? '✓ Настройки сохранены!' : '✗ ' + (data.message || 'Ошибка');
                }
            }).catch(function(e) {
                aiSettingsSaveBtn.innerHTML = 'Сохранить настройки';
                aiSettingsSaveBtn.disabled = false;
            });
        };
    }

    // Prompt presets — when a preset is selected, fill the textarea with a template
    const promptPresets = {
        'article': 'Напиши подробную статью на тему: [ТЕМА]. Структура: введение, 3-4 основных раздела с подзаголовками, заключение. Объём: 800-1200 слов.',
        'product': 'Напиши продающее описание товара: [НАЗВАНИЕ]. Включи: основные характеристики, преимущества для покупателя, сценарии использования, призыв к действию. Объём: 300-500 слов.',
        'blog': 'Напиши неформальную запись в блог на тему: [ТЕМА]. Личный тон, примеры из жизни, вовлечение читателя вопросами. Объём: 500-800 слов.',
        'about': 'Напиши страницу "О компании" для: [НАЗВАНИЕ КОМПАНИИ]. Включи: миссию, историю, ценности, команду, достижения. Профессиональный но дружелюбный тон.',
        'faq': 'Создай блок FAQ (часто задаваемые вопросы) по теме: [ТЕМА]. 6-8 вопросов и ответов. Вопросы — реальные, которые задают клиенты.',
        'news': 'Напиши новость: [ТЕМА]. Структура: заголовок, лид (краткое содержание в 1-2 предложениях), основной текст с деталями, цитата (придумай от лица эксперта). Объём: 300-400 слов.',
        'review': 'Напиши обзор: [ПРОДУКТ/УСЛУГА]. Плюсы, минусы, вердикт, оценка по 10-балльной шкале. Объективный тон.',
        'tutorial': 'Напиши пошаговую инструкцию: [ТЕМА]. Структура: что понадобится, шаги 1-N с подзаголовками, советы, возможные проблемы. Объём: 600-1000 слов.',
    };
    const presetSelect = modalBody.querySelector('#ai-prompt-preset');
    if (presetSelect) {
        presetSelect.onchange = () => {
            const preset = presetSelect.value;
            if (preset && promptPresets[preset]) {
                modalBody.querySelector('#ai-prompt-text').value = promptPresets[preset];
                modalBody.querySelector('#ai-prompt-text').focus();
            }
        };
    }

    const showLoader = (text) => {
        const loader = modalBody.querySelector('.lumina-ai-loader');
        loader.style.display = 'flex';
        loader.innerText = text || '\u0413\u0435\u043D\u0435\u0440\u0430\u0446\u0438\u044F...';
        loader.style.color = '#333';
    };
    const showLoaderError = (msg) => {
        const loader = modalBody.querySelector('.lumina-ai-loader');
        loader.style.display = 'flex';
        loader.innerText = '\u274C ' + msg;
        loader.style.color = 'red';
    };
    const hideLoader = () => {
        modalBody.querySelector('.lumina-ai-loader').style.display = 'none';
    };

    // ===== TEXT generation =====
    modalBody.querySelector('#btn-gen-text').onclick = async () => {
        const prompt = modalBody.querySelector('#ai-prompt-text').value;
        if (!prompt) return;
        showLoader('\u0413\u0435\u043D\u0435\u0440\u0430\u0446\u0438\u044F \u0442\u0435\u043A\u0441\u0442\u0430...');
        try {
            const formData = new FormData();
            formData.append('action', 'text');
            formData.append('prompt', prompt + '\n\nReturn HTML content suitable for a rich text editor. Use h2, p, ul, li, strong, a tags. Do NOT use markdown.');
            formData.append('csrf_token', window._luminaCsrfToken || '');
            const response = await fetch(cmsAiUrl, { method: 'POST', body: formData });
            if (!response.ok) throw new Error('Server error: ' + response.status);
            const data = await response.json();
            if (data.status === 'success' && data.content) {
                editor.restoreSelection();
                editor.editor.focus();
                document.execCommand('insertHTML', false, data.content);
                editor.sync();
                editor.pushHistory(true);
                modalBody.closest('.lumina-modal-overlay').remove();
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        } catch (e) {
            console.error('AI text generation failed:', e);
            showLoaderError(e.message);
        }
    };

    // ===== IMAGE generation =====
    modalBody.querySelector('#btn-gen-image').onclick = async () => {
        const prompt = modalBody.querySelector('#ai-prompt-image').value;
        if (!prompt) return;
        showLoader('\u0413\u0435\u043D\u0435\u0440\u0430\u0446\u0438\u044F \u0438\u0437\u043E\u0431\u0440\u0430\u0436\u0435\u043D\u0438\u044F (\u043C\u043E\u0436\u0435\u0442 \u0437\u0430\u043D\u044F\u0442\u044C \u0434\u043E 30 \u0441\u0435\u043A)...');
        try {
            const formData = new FormData();
            formData.append('action', 'image');
            formData.append('prompt', prompt);
            formData.append('csrf_token', window._luminaCsrfToken || '');
            const response = await fetch(cmsAiUrl, { method: 'POST', body: formData });
            if (!response.ok) throw new Error('Server error: ' + response.status);
            const data = await response.json();
            if (data.status === 'success' && data.url) {
                editor.restoreSelection();
                editor.editor.focus();
                const imgHtml = '<img src="' + data.url + '" style="max-width:100%; border-radius:4px;" alt="' + prompt.replace(/"/g, '&quot;').substring(0, 80) + '" />';
                document.execCommand('insertHTML', false, imgHtml);
                editor.sync();
                editor.pushHistory(true);
                modalBody.closest('.lumina-modal-overlay').remove();
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        } catch (e) {
            console.error('AI image generation failed:', e);
            showLoaderError(e.message);
        }
    };

    // ===== VIDEO generation =====
    modalBody.querySelector('#btn-gen-video').onclick = async () => {
        const prompt = modalBody.querySelector('#ai-prompt-video').value;
        if (!prompt) return;
        showLoader('\u0413\u0435\u043D\u0435\u0440\u0430\u0446\u0438\u044F \u0432\u0438\u0434\u0435\u043E (\u043C\u043E\u0436\u0435\u0442 \u0437\u0430\u043D\u044F\u0442\u044C \u0434\u043E 5 \u043C\u0438\u043D\u0443\u0442)...');
        try {
            const formData = new FormData();
            formData.append('action', 'video');
            formData.append('prompt', prompt);
            formData.append('csrf_token', window._luminaCsrfToken || '');
            const response = await fetch(cmsAiUrl, { method: 'POST', body: formData });
            if (!response.ok) throw new Error('Server error: ' + response.status);
            const data = await response.json();
            if (data.status === 'success' && data.url) {
                editor.restoreSelection();
                editor.editor.focus();
                // Insert video as a resizable media wrapper
                const videoHtml = '<div class="lumina-resizable-media" style="position:relative; display:inline-block; width:560px; aspect-ratio:16/9; max-width:100%;">' +
                    '<video src="' + data.url + '" controls style="width:100%;height:100%;pointer-events:none;"></video>' +
                    '</div><p><br></p>';
                document.execCommand('insertHTML', false, videoHtml);
                editor.sync();
                editor.pushHistory(true);
                modalBody.closest('.lumina-modal-overlay').remove();
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        } catch (e) {
            console.error('AI video generation failed:', e);
            showLoaderError(e.message);
        }
    };
}

export function openImageUpload(editor) {
    // Save box context BEFORE opening modal — clicking on file input resets selectedBox
    const savedBox = editor.selectedBox || null;
    editor.saveSelection();
    const h = `
        <div class="lumina-tabs">
            <button type="button" class="lumina-tab active" data-tab="upload-tab">Upload File</button>
            <button type="button" class="lumina-tab" data-tab="url-tab">By URL</button>
        </div>
        <div id="upload-tab" class="lumina-tab-content active">
            <div class="lumina-dropzone" id="img-modal-dropzone">
                <div class="lumina-dropzone-icon">☁️</div>
                <div class="lumina-dropzone-text">Drag & Drop image here<br>or click to select</div>
                <input type="file" class="lumina-dropzone-input" accept="image/*" id="img-modal-input">
            </div>
            <div id="upload-status" style="font-size:12px;color:#666;text-align:center;margin-top:5px;min-height:20px;"></div>
        </div>
        <div id="url-tab" class="lumina-tab-content">
            <div class="lbs-row"><label style="width:auto;margin-right:10px;">Image URL:</label><input type="text" id="img-modal-url" class="lumina-select" style="flex:1" placeholder="https://..."></div>
        </div>`;

    const modalBody = createModalOverlay(editor, 'Insert Image', h, [{
        text: 'Insert Image',
        onClick: (e, modal) => {
            const activeTab = modal.querySelector('.lumina-tab.active');
            if (activeTab.dataset.tab === 'url-tab') {
                const url = modal.querySelector('#img-modal-url').value;
                if (url) {
                    // Restore box context before inserting
                    if (savedBox && !editor.selectedBox) editor.selectedBox = savedBox;
                    insertImage(editor, url);
                }
                modal.closest('.lumina-modal-overlay').remove();
            } else { modal.closest('.lumina-modal-overlay').remove(); }
        }
    }]);

    modalBody.querySelectorAll('.lumina-tab').forEach(t => {
        t.onclick = () => {
            modalBody.querySelectorAll('.lumina-tab').forEach(x => x.classList.remove('active'));
            modalBody.querySelectorAll('.lumina-tab-content').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            modalBody.querySelector(`#${t.dataset.tab}`).classList.add('active');
        };
    });

    const zone = modalBody.querySelector('#img-modal-dropzone');
    const inp = modalBody.querySelector('#img-modal-input');
    zone.onclick = () => inp.click();

    inp.onchange = (e) => {
        const f = e.target.files[0];
        if(f) {
            modalBody.querySelector('#upload-status').innerText = 'Uploading...';
            // Use editor.uploadFile which wraps contentOps.uploadFile
            editor.uploadFile(f).then(url => {
                // Restore box context before inserting
                if (savedBox && !editor.selectedBox) editor.selectedBox = savedBox;
                insertImage(editor, url);
                modalBody.closest('.lumina-modal-overlay').remove();
            });
        }
    };
    
    zone.ondragover = e => { e.preventDefault(); zone.classList.add('dragover'); };
    zone.ondragleave = () => zone.classList.remove('dragover');
    zone.ondrop = e => {
        e.preventDefault(); zone.classList.remove('dragover');
        const f = e.dataTransfer.files[0];
        if(f && f.type.startsWith('image/')) {
            modalBody.querySelector('#upload-status').innerText = 'Uploading...';
            editor.uploadFile(f).then(url => { 
                insertImage(editor, url); 
                modalBody.closest('.lumina-modal-overlay').remove(); 
            });
        }
    };
}

export function openVideoModal(editor) {
    // Save box context BEFORE opening modal
    const savedBox = editor.selectedBox || null;
    editor.saveSelection();
    const h = `<div class="lbs-row"><label style="width:auto;margin-right:10px;">Video/Embed URL:</label><input type="text" id="vid-url" class="lumina-select" style="flex:1" placeholder="YouTube, Vimeo, etc."></div><div style="font-size:11px; color:#666; margin-top:8px;">Supports YouTube, Vimeo, Dailymotion, or direct .mp4 links.</div>`;
    createModalOverlay(editor, 'Insert Video', h, [{
        text: 'Insert Video',
        onClick: (e, modal) => {
            const url = modal.querySelector('#vid-url').value;
            if(url) {
                // Restore box context before inserting
                if (savedBox && !editor.selectedBox) editor.selectedBox = savedBox;
                insertVideo(editor, url);
            }
            modal.closest('.lumina-modal-overlay').remove();
        }
    }]);
}

/**
 * AI Image Generation Modal
 * Opens a modal with a prompt textarea + size selector.
 * Calls api_ai_image.php, then inserts the generated image into the editor.
 */
export function openAiImageModal(editor) {
    // Save box context BEFORE opening modal
    const savedBox = editor.selectedBox || null;
    editor.saveSelection();
    const csrf = (window._luminaEditorConfig && window._luminaEditorConfig.csrfToken) || '';
    const uploadUrl = editor.uploadUrl || 'lumina_upload_image.php';

    const h = `
        <div style="padding: 4px 0;">
            <label style="display:block; font-weight:600; margin-bottom:6px; font-size:13px;">Prompt — describe the image:</label>
            <textarea id="ai-img-prompt" rows="4" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; resize:vertical; outline:none; font-family:inherit;"
                placeholder="A serene mountain landscape at sunset, photorealistic, 4K, dramatic lighting"></textarea>
            <div style="display:flex; gap:8px; margin-top:10px; align-items:center;">
                <label style="font-size:13px; font-weight:600; min-width:60px;">Size:</label>
                <select id="ai-img-size" class="lumina-select" style="flex:1;">
                    <option value="1024x1024" selected>Square 1024×1024</option>
                    <option value="768x768">Square 768×768</option>
                    <option value="512x512">Square 512×512</option>
                    <option value="1024x576">Landscape 1024×576</option>
                    <option value="576x1024">Portrait 576×1024</option>
                </select>
            </div>
            <div style="margin-top:10px; padding:8px 10px; background:#eff6ff; border-radius:6px; font-size:12px; color:#1e40af;">
                <i class="fas fa-info-circle"></i> Generation takes ~10-30 seconds. The image will be saved to uploads/ai_generated/ and inserted into the editor.
            </div>
            <div id="ai-img-status" style="margin-top:8px; font-size:13px; color:#64748b; min-height:20px;"></div>
            <div id="ai-img-preview" style="margin-top:8px; text-align:center; display:none;">
                <img id="ai-img-preview-img" style="max-width:100%; max-height:300px; border-radius:8px; border:1px solid #e2e8f0;">
            </div>
        </div>
    `;

    const modal = createModalOverlay(editor, '✨ AI Image Generator', h, [
        {
            text: 'Generate',
            id: 'ai-img-generate-btn',
            onClick: (e, modal) => {
                const prompt = modal.querySelector('#ai-img-prompt').value.trim();
                const size = modal.querySelector('#ai-img-size').value;
                if (prompt.length < 5) {
                    modal.querySelector('#ai-img-status').innerHTML = '<span style="color:#dc2626;">Prompt too short (min 5 chars).</span>';
                    return;
                }
                const status = modal.querySelector('#ai-img-status');
                const btn = modal.querySelector('#ai-img-generate-btn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
                status.innerHTML = '<i class="fas fa-cog fa-spin"></i> Sending prompt to AI...';

                const fd = new FormData();
                fd.append('prompt', prompt);
                fd.append('size', size);
                fd.append('csrf_token', csrf);

                var aiImageUrl = (editor.options && editor.options.aiImageUrl)
                    || (window._luminaEditorConfig && window._luminaEditorConfig.ai_image_url)
                    || 'api_ai_image.php';
                fetch(aiImageUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        btn.disabled = false;
                        btn.innerHTML = 'Generate';
                        if (d.status === 'success') {
                            status.innerHTML = '<span style="color:#16a34a;"><i class="fas fa-check-circle"></i> Image generated! Click "Insert" to add it.</span>';
                            const preview = modal.querySelector('#ai-img-preview');
                            const previewImg = modal.querySelector('#ai-img-preview-img');
                            previewImg.src = d.url + '?t=' + Date.now();
                            preview.style.display = 'block';
                            // Replace button text with Insert
                            btn.innerHTML = '✓ Insert into editor';
                            btn.onclick = () => {
                                // Restore box context before inserting
                                if (savedBox && !editor.selectedBox) editor.selectedBox = savedBox;
                                insertImage(editor, d.url, prompt);
                                modal.closest('.lumina-modal-overlay').remove();
                            };
                        } else {
                            status.innerHTML = '<span style="color:#dc2626;"><i class="fas fa-exclamation-circle"></i> ' + (d.message || 'Generation failed') + '</span>';
                        }
                    })
                    .catch(err => {
                        btn.disabled = false;
                        btn.innerHTML = 'Generate';
                        status.innerHTML = '<span style="color:#dc2626;">Network error: ' + err.message + '</span>';
                    });
            }
        }
    ]);
}

// Helper: insert an image with alt text (supports box context)
function insertImage(editor, url, alt = '') {
    // If a box is selected, insert into the box content
    if (editor.selectedBox) {
        const content = editor.selectedBox.querySelector('.lumina-box-content');
        if (content) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = alt || '';
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.borderRadius = '4px';
            content.appendChild(img);
            editor.sync();
            editor.pushHistory(true);
            setTimeout(() => editor.selectImage(img), 50);
            return;
        }
    }
    editor.restoreSelection();
    editor.editor.focus();
    const html = `<img src="${url}" alt="${alt.replace(/"/g, '&quot;')}" style="max-width:100%; height:auto; border-radius:8px;">`;
    document.execCommand('insertHTML', false, html);
    editor.sync();
    editor.pushHistory(true);
}
