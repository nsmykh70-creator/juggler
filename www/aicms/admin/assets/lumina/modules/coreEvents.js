import { selectImage, deselectImage } from './imageToolbar.js?v=20260901';
import { deselectBox } from './boxToolbar.js?v=20260901';
import { hideButtonToolbar, showButtonToolbar } from './buttonToolbar.js?v=20260901';
import { checkColumnSelection } from './columnToolbar.js?v=20260901';
import { hideQuickInsert, showQuickInsert } from './quickInsert.js?v=20260901';
import { openCodeEditorModal } from './contentOps.js?v=20260901';
import { applyCustomStyle } from './selection.js?v=20260901';
import { showCodeBlockToolbar, hideCodeBlockToolbar } from './codeBlockToolbar.js?v=20260901';

export function handleEditorClick(editor, e) {
    // 1. High Priority UI Elements (Code Edit, Buttons, Images)
    
    // Code Copy Button (or its child SVG/label span)
    const copyTarget = e.target.closest('.lumina-code-copy');
    if (copyTarget) {
        const wrapper = copyTarget.closest('.lumina-code-wrapper'); 
        const pre = wrapper.querySelector('.lumina-code-content');
        if (pre) {
            const text = pre.innerText;
            navigator.clipboard.writeText(text).then(() => {
                // Update only the label span, preserve the SVG icon
                const label = copyTarget.querySelector('.lumina-code-copy-label');
                const originalText = label ? label.innerText : copyTarget.innerText;
                if (label) {
                    label.innerText = '✓ Copied!';
                } else {
                    copyTarget.innerText = '✓ Copied!';
                }
                copyTarget.style.background = '#16a34a';
                copyTarget.style.borderColor = '#16a34a';
                setTimeout(() => {
                    if (label) label.innerText = originalText;
                    else copyTarget.innerText = originalText;
                    copyTarget.style.background = '';
                    copyTarget.style.borderColor = '';
                }, 2000);
            });
        }
        e.stopPropagation(); 
        return;
    }

    // Code Edit Button
    if (e.target.classList.contains('lumina-code-edit')) {
        const wrapper = e.target.closest('.lumina-code-wrapper'); 
        if (wrapper) { openCodeEditorModal(editor, wrapper); } 
        e.stopPropagation(); 
        return;
    }
    
    // Detect Click on Code Wrapper (Background/Frame) to show Toolbar
    const codeWrapper = e.target.closest('.lumina-code-wrapper');
    if (codeWrapper && editor.editor.contains(codeWrapper)) {
        showCodeBlockToolbar(editor, codeWrapper);
        // Do not return here, allow bubble menu logic to maybe close others, 
        // but we handle selection manually for wrapper
    } else {
        // Only hide if we aren't clicking inside the toolbar itself
        if (!e.target.closest('.lumina-code-toolbar')) {
            hideCodeBlockToolbar(editor);
        }
    }

    // Buttons and Links
    const btn = e.target.closest('a, button, .lumina-action-btn');
    if (btn && editor.editor.contains(btn)) { 
        e.preventDefault(); 
        e.stopPropagation(); 
        e.stopImmediatePropagation();
        showButtonToolbar(editor, btn); 
        const range = document.createRange(); 
        range.selectNodeContents(btn); 
        const sel = window.getSelection(); 
        sel.removeAllRanges(); 
        sel.addRange(range); 
        return; 
    }

    // Images / Video
    if (e.target.tagName === 'IMG' || e.target.classList.contains('lumina-resizable-media')) {
            if (e.target.classList.contains('lumina-resizable-media')) {
                selectImage(editor, e.target); 
                if(editor.imgToolbar) editor.imgToolbar.style.display = 'none';
                if(editor.videoToolbar) { 
                    const rect = e.target.getBoundingClientRect(); 
                    editor.videoToolbar.style.display = 'flex'; 
                    // Use smart positioning for video toolbar? Already inside
                }
                return;
            }
            selectImage(editor, e.target); 
            return;
    }
    
    // 2. CHECK COLUMN SELECTION 
    const colHandled = checkColumnSelection(editor, e.target);
    if (colHandled) {
        if (editor.selectedBlock) {
            editor.selectedBlock.classList.remove('lumina-active-block');
            editor.selectedBlock = null;
            if(editor.blockToolbar) editor.blockToolbar.style.display = 'none';
            if(editor.blockSettings) editor.blockSettings.style.display = 'none';
        }
        return;
    }

    // 3. HIDE LOGIC
    if (e.target === editor.editor || (!e.target.closest('.lumina-image-toolbar') && !e.target.closest('.lumina-resizer') && !e.target.closest('.lumina-table-toolbar') && !e.target.closest('.lumina-code-toolbar'))) {
            if (editor.selectedImage && e.target !== editor.selectedImage) { deselectImage(editor); }
            if (editor.selectedBox && !e.target.closest('.lumina-free-box')) { deselectBox(editor); }
            if (editor.selectedButton && !e.target.closest('.lumina-button-toolbar') && e.target !== editor.selectedButton) { hideButtonToolbar(editor); }
    }

    // 4. Quick Insert Hiding
    const isQuickMenu = e.target.closest('.lumina-quick-wrapper');
    const isTarget = editor.quickInsertTarget && (editor.quickInsertTarget === e.target || editor.quickInsertTarget.contains(e.target));
    if (!colHandled && !isQuickMenu && !isTarget && !codeWrapper && !e.target.closest('.lumina-code-toolbar')) {
            if (e.target === editor.editor) {
                hideQuickInsert(editor);
            }
    }
}

export function handleKeydown(editor, e) {
    // Tab key — insert spaces
    if (e.key === 'Tab') { 
        e.preventDefault(); 
        document.execCommand('insertText', false, '    '); 
        return;
    }
    
    // === Keyboard shortcuts ===
    const ctrl = e.ctrlKey || e.metaKey;
    if (!ctrl) return;
    
    // Ctrl+K — Insert link
    if (e.key === 'k' || e.key === 'K') {
        if (!e.shiftKey) {
            e.preventDefault();
            if (editor.openTooltipModal) editor.openTooltipModal();
            return;
        }
    }
    // Ctrl+Shift+K — Insert image by URL
    if ((e.key === 'k' || e.key === 'K') && e.shiftKey) {
        e.preventDefault();
        if (editor.openImageModal) editor.openImageModal();
        return;
    }
    // Ctrl+M — Insert module
    if (e.key === 'm' || e.key === 'M') {
        e.preventDefault();
        if (editor.openModulePicker) editor.openModulePicker();
        return;
    }
    // Ctrl+/ — Toggle source code / visual
    if (e.key === '/') {
        e.preventDefault();
        if (editor.toggleSource) editor.toggleSource();
        return;
    }
    // Ctrl+Shift+V — Paste as plain text
    if ((e.key === 'v' || e.key === 'V') && e.shiftKey) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text');
        document.execCommand('insertText', false, text);
        return;
    }
    // Ctrl+Shift+F — Search & Replace
    if ((e.key === 'f' || e.key === 'F') && e.shiftKey) {
        e.preventDefault();
        openSearchReplace(editor);
        return;
    }
    // Ctrl+Shift+P — Preview
    if ((e.key === 'p' || e.key === 'P') && e.shiftKey) {
        e.preventDefault();
        if (editor.togglePreview) editor.togglePreview();
        return;
    }
    // Ctrl+S — Save (prevent browser save dialog, trigger form submit)
    if (e.key === 's' || e.key === 'S') {
        e.preventDefault();
        const form = editor.editor.closest('form');
        if (form) form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        return;
    }
}

// === Search & Replace ===
function openSearchReplace(editor) {
    const t = editor.t || (k => k);
    const content = `
        <div style="display:flex;flex-direction:column;gap:12px;padding:4px;">
            <div>
                <label style="font-size:13px;color:#475569;display:block;margin-bottom:4px;">${t('search_find') || 'Найти'}:</label>
                <input type="text" id="sr-find" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;outline:none;" placeholder="${t('search_placeholder') || 'Текст для поиска...'}">
            </div>
            <div>
                <label style="font-size:13px;color:#475569;display:block;margin-bottom:4px;">${t('search_replace') || 'Заменить на'}:</label>
                <input type="text" id="sr-replace" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;box-sizing:border-box;outline:none;" placeholder="${t('replace_placeholder') || 'Текст для замены...'}">
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" id="sr-find-next" class="aicms-btn aicms-btn--ghost" style="font-size:13px;padding:6px 14px;">${t('find_next') || 'Найти далее'}</button>
                <button type="button" id="sr-replace-btn" class="aicms-btn aicms-btn--ghost" style="font-size:13px;padding:6px 14px;">${t('replace_one') || 'Заменить'}</button>
                <button type="button" id="sr-replace-all" class="aicms-btn aicms-btn--primary" style="font-size:13px;padding:6px 14px;">${t('replace_all') || 'Заменить все'}</button>
            </div>
            <div id="sr-status" style="font-size:12px;color:#64748b;min-height:18px;"></div>
        </div>
    `;
    
    const overlay = document.createElement('div');
    overlay.className = 'lumina-modal-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:100020;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;animation:luminaFadeIn 0.2s ease;';
    
    const modal = document.createElement('div');
    modal.className = 'lumina-modal';
    modal.style.cssText = 'width:480px;max-width:90vw;animation:luminaSlideUp 0.25s cubic-bezier(0.16,1,0.3,1);';
    modal.innerHTML = `
        <div class="lumina-modal-header">
            <div style="display:flex;align-items:center;gap:8px;">
                <i class="fas fa-search" style="color:#2563eb;"></i>
                <h3 style="margin:0;font-size:16px;">${t('search_replace_title') || 'Поиск и замена'}</h3>
            </div>
            <button type="button" class="x" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;padding:4px 8px;">&times;</button>
        </div>
        <div class="lumina-modal-body" style="padding:20px;">${content}</div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const closeBtn = modal.querySelector('.x');
    const findInput = modal.querySelector('#sr-find');
    const replaceInput = modal.querySelector('#sr-replace');
    const status = modal.querySelector('#sr-status');
    
    closeBtn.onclick = () => overlay.remove();
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
    
    // ESC to close
    const escHandler = (e) => { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
    
    setTimeout(() => findInput.focus(), 50);
    
    // Find next
    modal.querySelector('#sr-find-next').onclick = () => {
        const query = findInput.value;
        if (!query) return;
        const found = findInEditor(editor, query);
        status.textContent = found ? (t('found_match') || 'Найдено') : (t('not_found') || 'Не найдено');
    };
    
    // Replace one
    modal.querySelector('#sr-replace-btn').onclick = () => {
        const query = findInput.value;
        const replacement = replaceInput.value;
        if (!query) return;
        const sel = window.getSelection();
        if (sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) {
            const selectedText = sel.toString();
            if (selectedText === query) {
                document.execCommand('insertText', false, replacement);
                status.textContent = (t('replaced') || 'Заменено');
            }
        }
        const found = findInEditor(editor, query);
        status.textContent = found ? (t('replaced_next') || 'Заменено, найдено следующее') : (t('replaced_done') || 'Заменено, больше нет совпадений');
    };
    
    // Replace all
    modal.querySelector('#sr-replace-all').onclick = () => {
        const query = findInput.value;
        const replacement = replaceInput.value;
        if (!query) return;
        let count = 0;
        const html = editor.editor.innerHTML;
        // Simple text replacement (not perfect for HTML, but works for basic cases)
        const newText = html.replace(new RegExp(query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), () => { count++; return replacement; });
        if (count > 0) {
            editor.editor.innerHTML = newText;
            editor.sync();
            status.textContent = ((t('replaced_count') || 'Заменено: ') + count);
        } else {
            status.textContent = (t('not_found') || 'Не найдено');
        }
    };
    
    // Enter to find next
    findInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); modal.querySelector('#sr-find-next').click(); }
    });
}

function findInEditor(editor, query) {
    const sel = window.getSelection();
    const range = sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    
    // If we have a selection, start searching after it
    let startNode = editor.editor;
    let startOffset = 0;
    if (range && editor.editor.contains(range.endContainer)) {
        startNode = range.endContainer;
        startOffset = range.endOffset;
    }
    
    // Use the browser's find API (window.find) if available
    if (window.find) {
        // Deselect first to search from beginning or from current position
        const found = window.find(query, false, false, true, false, true, false);
        if (found && editor.editor.contains(window.getSelection().anchorNode)) {
            return true;
        }
        // Not found after current position — wrap around
        sel.removeAllRanges();
        const allRange = document.createRange();
        allRange.selectNodeContents(editor.editor);
        allRange.collapse(true); // Collapse to start
        sel.removeAllRanges();
        sel.addRange(allRange);
        return window.find(query, false, false, true, false, true, false) && editor.editor.contains(window.getSelection().anchorNode);
    }
    return false;
}

export function handlePaste(editor, e) {
    const wrapper = e.target.closest('.lumina-code-wrapper');
    if (wrapper && e.target.classList.contains('lumina-code-content')) { 
        e.preventDefault(); 
        const text = (e.clipboardData || window.clipboardData).getData('text'); 
        document.execCommand('insertText', false, text); 
        return;
    }

    // ===== Paste images from clipboard (screenshots, copied images) =====
    const cd = e.clipboardData || window.clipboardData;
    if (cd && cd.items) {
        for (let i = 0; i < cd.items.length; i++) {
            const item = cd.items[i];
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (!file) continue;
                editor.uploadFile(file)
                    .then(url => editor.insertImage(url))
                    .catch(err => {
                        console.error('Clipboard image upload failed:', err);
                        // Last-resort: insert as data URL
                        const reader = new FileReader();
                        reader.onload = ev => editor.insertImage(ev.target.result);
                        reader.readAsDataURL(file);
                    });
                return;
            }
        }
    }

    // ===== Paste HTML from Word / Google Docs / external sources =====
    // Word and Google Docs add huge inline styles, proprietary tags
    // (<o:p>, <w:...>), and inline mso- styles that bloat the document.
    // We strip these to keep content clean.
    if (cd && cd.getData('text/html')) {
        const rawHtml = cd.getData('text/html');
        // Only sanitise if it looks like Word / Google Docs paste
        // (Word adds <meta>, mso- styles, <!--...-->; Google Docs adds
        // <col> and dir="ltr" spans with class="*").
        if (/<(meta|o:|w:|v:)|mso-|class="[^"]*"/i.test(rawHtml)) {
            e.preventDefault();
            const cleaned = sanitizePastedHtml(rawHtml);
            editor.restoreSelection();
            if (!window.getSelection().rangeCount) editor.editor.focus();
            document.execCommand('insertHTML', false, cleaned);
            editor.sync();
            editor.pushHistory(true);
            return;
        }
    }
}

/**
 * Sanitise HTML pasted from Word / Google Docs / browsers.
 * Removes:
 *   - <meta>, <link>, <style>, <script>, <o:>, <w:>, <v:> tags
 *   - <!-- comments -->
 *   - class="..." attributes (Word/Docs add meaningless classes)
 *   - style="mso-*" and other proprietary styles
 *   - Inline styles on <span> when the span has no visible style
 *   - Empty <span>s after style removal
 * Keeps: text formatting (b/i/u), headings, lists, tables, links.
 */
function sanitizePastedHtml(html) {
    if (!html) return '';
    // Parse through a detached DOMParser so scripts/styles can't execute
    var doc = new DOMParser().parseFromString(html, 'text/html');
    // Remove unwanted tags entirely
    var unwanted = doc.querySelectorAll('meta, link, style, script, o\\:p, title, head');
    unwanted.forEach(function(el) { el.remove(); });
    // Remove Word/Docs-specific namespaces and proprietary tags
    var body = doc.body || doc.documentElement;
    // Strip comments
    var walker = doc.createTreeWalker(body, NodeFilter.SHOW_COMMENT, null);
    var toRemove = [];
    while (walker.nextNode()) toRemove.push(walker.currentNode);
    toRemove.forEach(function(n) { n.remove(); });
    // Walk all elements and clean attributes
    var all = body.querySelectorAll('*');
    all.forEach(function(el) {
        // Remove class attribute (Word/Docs add bloat like "MsoNormal")
        el.removeAttribute('class');
        // Remove id attribute (Word adds _anchor ids)
        el.removeAttribute('id');
        // Remove dir attribute (Docs adds dir="ltr" everywhere)
        el.removeAttribute('dir');
        // Sanitise style: drop mso-* properties, keep only safe CSS
        var style = el.getAttribute('style');
        if (style) {
            var cleaned = style.split(';').filter(function(decl) {
                var prop = decl.split(':')[0].trim().toLowerCase();
                if (prop.startsWith('mso-')) return false;
                if (prop === 'position' || prop === 'z-index' || prop === 'left' || prop === 'top') return false;
                return true;
            }).join(';').trim();
            if (cleaned) el.setAttribute('style', cleaned);
            else el.removeAttribute('style');
        }
    });
    // Remove empty <span> elements (after style removal many spans are empty)
    body.querySelectorAll('span').forEach(function(s) {
        if (!s.textContent.trim() && !s.querySelector('img,br')) s.remove();
    });
    // Convert <o:p> remnants (already removed above, but in case of namespace issues)
    body.innerHTML = body.innerHTML.replace(/<\/?o:p>/gi, '');
    return body.innerHTML;
}

export function handleDragStart(editor, e) { 
    if (e.target.tagName === 'IMG') { 
        editor.draggedImage = e.target; 
        e.dataTransfer.effectAllowed = 'move'; 
        editor.dragSourceRange = null;
    } else {
        const sel = window.getSelection();
        if (sel.rangeCount > 0 && !sel.isCollapsed) {
            editor.dragSourceRange = sel.getRangeAt(0);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', editor.dragSourceRange.toString());
        } else {
            editor.dragSourceRange = null;
        }
    }
}

export function handleDragOver(editor, e) { 
    e.preventDefault(); 
    e.dataTransfer.dropEffect = 'move'; 
    if (document.caretRangeFromPoint) {
        const range = document.caretRangeFromPoint(e.clientX, e.clientY);
        if (range && editor.editor.contains(range.startContainer)) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    } else if (document.caretPositionFromPoint) {
        const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
        if (pos && editor.editor.contains(pos.offsetNode)) {
            const range = document.createRange();
            range.setStart(pos.offsetNode, pos.offset);
            range.collapse(true);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }
}

export function handleDrop(editor, e) {
    const files = e.dataTransfer.files;
    if (files && files.length > 0) {
        e.preventDefault(); e.stopPropagation();
        if (files[0].type.startsWith('image/')) {
            editor.uploadFile(files[0]).then(url => editor.insertImage(url)).catch(err => console.error("Upload failed:", err));
        }
        return;
    }

    if (editor.draggedImage && editor.editor.contains(editor.draggedImage)) {
        e.preventDefault(); e.stopPropagation();
        let range = null;
        if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(e.clientX, e.clientY);
        else if (document.caretPositionFromPoint) {
            const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
            if (pos) { range = document.createRange(); range.setStart(pos.offsetNode, pos.offset); range.collapse(true); }
        }
        if (range && editor.editor.contains(range.startContainer)) {
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
            range.insertNode(editor.draggedImage);
            selectImage(editor, editor.draggedImage);
            editor.draggedImage = null; editor.sync(); editor.pushHistory(true);
        }
        return;
    }

    if (editor.dragSourceRange) {
            e.preventDefault(); e.stopPropagation();
            let dropRange = null;
            if (document.caretRangeFromPoint) {
                dropRange = document.caretRangeFromPoint(e.clientX, e.clientY);
            } else if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
                if (pos) { dropRange = document.createRange(); dropRange.setStart(pos.offsetNode, pos.offset); dropRange.collapse(true); }
            }
            if (dropRange && editor.editor.contains(dropRange.startContainer)) {
                try {
                    const fragment = editor.dragSourceRange.extractContents();
                    dropRange.insertNode(fragment);
                    dropRange.collapse(false);
                    const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(dropRange);
                    editor.sync(); editor.pushHistory(true);
                } catch (err) { console.warn('Drag drop error:', err); }
            }
            editor.dragSourceRange = null;
    }
}
