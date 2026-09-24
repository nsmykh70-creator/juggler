import { initVideoToolbar } from './videoToolbar.js?v=20260901';
import { initColumnToolbar } from './columnToolbar.js?v=20260901';
import { luminaStyles } from '../styles.js?v=20260901';
import { initActiveState } from './activeState.js?v=20260901';
import { initContextMenu } from './contextMenu.js?v=20260901';

export function initEditor(editor) {
    editor.element.style.display = 'none';

    editor.container = document.createElement('div');
    editor.container.className = 'lumina-container';
    loadLuminaStyles(editor);

    loadGoogleFonts(editor);
    loadTailwindCSS(editor); 

    editor.editorWrapper = document.createElement('div');
    editor.editorWrapper.className = 'lumina-wrapper';

    editor.toolbarContainer = document.createElement('div');
    editor.toolbarContainer.className = 'lumina-toolbar-container';
    
    editor.renderToolbar();
    editor.editorWrapper.appendChild(editor.toolbarContainer);

    editor.editor = document.createElement('div');
    editor.editor.className = 'lumina-editor prose max-w-none';
    editor.editor.contentEditable = true;
    
    // Ensure cursor text pointer to indicate clickable area
    editor.editor.style.cursor = 'text';
    
    // Load content from textarea, fixing relative image URLs for admin context
    var rawContent = editor.element.value || `<p>${editor.placeholder}</p>`;
    // Convert relative image paths (uploads/...) to absolute (../uploads/...) for admin
    // The admin panel is in /admin/, so relative paths need ../ prefix
    rawContent = rawContent.replace(/(src=["'])(uploads\/)/gi, '$1../$2');
    rawContent = rawContent.replace(/(src=["'])(\/uploads\/)/gi, '$1../$2');
    editor.editor.innerHTML = rawContent;

    editor.sourceWrapper = document.createElement('div');
    editor.sourceWrapper.className = 'lumina-source-wrapper';
    editor.sourceWrapper.style.display = 'none';
    editor.sourceWrapper.innerHTML = `
        <div class="lumina-source-toolbar">
            <span class="lst-label">Theme:</span>
            <select class="lst-theme lumina-select" style="width:100px;">
                <option value="monokai" selected>Monokai</option>
                <option value="dracula">Dracula</option>
                <option value="material">Material</option>
                <option value="eclipse">Eclipse</option>
                <option value="neo">Neo</option>
                <option value="nord">Nord</option>
                <option value="idea">Idea</option>
            </select>
            <div class="lumina-sep"></div>
            <span class="lst-label">Size:</span>
            <select class="lst-size lumina-select" style="width:70px;">
                <option value="12px">12px</option>
                <option value="14px" selected>14px</option>
                <option value="16px">16px</option>
                <option value="18px">18px</option>
                <option value="20px">20px</option>
                <option value="24px">24px</option>
            </select>
        </div>
        <div class="lumina-cm-host"></div>
    `;

    editor.editorWrapper.appendChild(editor.editor);
    editor.editorWrapper.appendChild(editor.sourceWrapper);

    editor.previewArea = document.createElement('div');
    editor.previewArea.className = 'lumina-preview-area';
    editor.previewArea.style.display = 'none';
    editor.editorWrapper.appendChild(editor.previewArea);

    editor.container.appendChild(editor.editorWrapper);

    editor.statusBar = document.createElement('div');
    editor.statusBar.className = 'lumina-statusbar';
    editor.statusBar.innerHTML = `
        <div class="lumina-breadcrumbs"></div>
        <div class="lumina-stats">
            <span class="lumina-stat-item" id="ls-word-count">0 words</span>
        </div>
    `;
    editor.container.appendChild(editor.statusBar);

    editor.element.parentNode.insertBefore(editor.container, editor.element);

    bindEvents(editor);
    document.execCommand('defaultParagraphSeparator', false, 'p');

    // Active button highlighting (bold/italic/align/etc.) — debounced via rAF
    initActiveState(editor);
    initContextMenu(editor);

    editor.initBubbleMenu();
    editor.initImageToolbar();
    editor.initTableToolbar();
    editor.initBlockToolbar();
    editor.initButtonToolbar();
    editor.initMapToolbar();
    editor.initBoxToolbar();
    
    initVideoToolbar(editor);
    initColumnToolbar(editor);
    
    // Initialize Code Block Toolbar
    editor.initCodeBlockToolbar();

    // Initialize Block Drag-and-Drop (reorder blocks)
    editor.initBlockDragDrop();

    editor.toggleStickyState();
    if (editor.settings.darkEditorMode && editor.editor) {
        editor.editor.classList.add('lumina-editor-dark-mode');
    }
}

export function loadGoogleFonts(editor) {
    // Standalone deployments can disable the external Google Fonts request
    // by passing `googleFonts: false` in the constructor options.
    if (editor.options && editor.options.googleFonts === false) return;
    const families = editor.fonts.map(f => `family=${f.replace(/ /g, '+')}:wght@400;500;600;700`).join('&');
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `https://fonts.googleapis.com/css2?${families}&display=swap`;
    document.head.appendChild(link);
}


// Auto-inject Lumina editor CSS once per page (synchronous).
let __luminaStylesInjected = false;
function loadLuminaStyles(editor) {
    if (__luminaStylesInjected) return;
    if (document.getElementById('lumina-editor-styles')) {
        __luminaStylesInjected = true;
        return;
    }
    const tag = document.createElement('style');
    tag.id = 'lumina-editor-styles';
    tag.innerHTML = luminaStyles;
    document.head.appendChild(tag);
    __luminaStylesInjected = true;
}

export function loadTailwindCSS(editor) {
    // Tailwind is now loaded LOCALLY via tailwind.local.css + tailwind-shim.css
    // in the admin <head> (see admin/core/tailwind_head.php).
    // We NO LONGER load the Play CDN because it injects a runtime preflight
    // that overrides Bootstrap CSS and breaks theme navbars.
    // The local files provide all utility classes needed for block templates.
    // This function is kept as a no-op for backward compatibility with
    // any code that calls it.
    return;
}

export function bindEvents(editor) {
    editor.editor.addEventListener('input', () => { 
        editor.sync(); 
        editor.updateStatusBar(); 
    });

    editor.editor.addEventListener('click', (e) => {
        editor.handleEditorClick(e);
        editor.updateStatusBar();
    });

    // Double click handler to position cursor exactly where clicked
    editor.editor.addEventListener('dblclick', (e) => {
         // Only intervene if clicking the container background (empty space)
         if (e.target !== editor.editor) {
             return;
         }

         e.preventDefault();
         const sel = window.getSelection();
         
         // 1. Check if clicking below the last element
         const lastChild = editor.editor.lastElementChild;
         let isBelowContent = false;
         let bottomY = 0;
         
         if (lastChild) {
             const rect = lastChild.getBoundingClientRect();
             bottomY = rect.bottom;
             // If click Y is significantly below the bottom of the last element
             if (e.clientY > bottomY) {
                 isBelowContent = true;
             }
         } else {
             // Editor is empty
             isBelowContent = true;
             bottomY = editor.editor.getBoundingClientRect().top + 20; // Approx padding
         }

         // 2. If clicking below content, append filler paragraphs until we reach the click point
         if (isBelowContent) {
             const lineHeightApprox = 24; // Approximation for standard paragraph height
             const distance = e.clientY - bottomY;
             const paragraphsNeeded = Math.max(1, Math.floor(distance / lineHeightApprox));
             
             const frag = document.createDocumentFragment();
             let lastP = null;
             
             for(let i = 0; i < paragraphsNeeded; i++) {
                 const p = document.createElement('p');
                 p.innerHTML = '<br>'; // BR needed for height
                 frag.appendChild(p);
                 lastP = p;
             }
             
             editor.editor.appendChild(frag);
             
             if (lastP) {
                 const range = document.createRange();
                 range.selectNodeContents(lastP);
                 range.collapse(true);
                 
                 sel.removeAllRanges();
                 sel.addRange(range);
                 
                 // Scroll to view
                 lastP.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
             }
             editor.sync();
             return;
         }

         // 3. If clicking in empty space BETWEEN elements, use Caret API to snap
         if (document.caretRangeFromPoint) {
             const range = document.caretRangeFromPoint(e.clientX, e.clientY);
             if (range && editor.editor.contains(range.startContainer)) {
                 sel.removeAllRanges();
                 sel.addRange(range);
             }
         } else if (document.caretPositionFromPoint) { // Firefox fallback
             const pos = document.caretPositionFromPoint(e.clientX, e.clientY);
             if (pos && editor.editor.contains(pos.offsetNode)) {
                 const range = document.createRange();
                 range.setStart(pos.offsetNode, pos.offset);
                 range.collapse(true);
                 sel.removeAllRanges();
                 sel.addRange(range);
             }
         }
         
         editor.editor.focus();
    });

    editor.editor.addEventListener('change', (e) => {
        const target = e.target;
        if (target.classList.contains('lumina-code-lang-select')) {
            const wrapper = target.closest('.lumina-code-wrapper');
            const pre = wrapper?.querySelector('.lumina-code-content');
            if (pre) {
                const rawCode = pre.innerText; 
                pre.innerHTML = editor.getHighlightedCode(rawCode, target.value);
                editor.sync();
            }
        }
    });

    editor.editor.addEventListener('keydown', (e) => editor.handleKeydown(e));
    editor.editor.addEventListener('keyup', () => { 
        editor.checkSelection(); 
        editor.updateToolbarState(); 
        editor.updateStatusBar(); 
    });
    editor.editor.addEventListener('mouseup', () => { 
        editor.checkSelection(); 
        editor.updateToolbarState(); 
        editor.updateStatusBar(); 
    });
    editor.editor.addEventListener('paste', (e) => editor.handlePaste(e));

    editor.editor.addEventListener('dragstart', (e) => editor.handleDragStart(e));
    editor.editor.addEventListener('dragover', (e) => editor.handleDragOver(e));
    editor.editor.addEventListener('drop', (e) => editor.handleDrop(e));

    editor.editorWrapper.addEventListener('scroll', () => {
        if (editor.updateFloatingControls) {
            // Debounce via requestAnimationFrame — avoids layout thrash
            // when scroll events fire in bursts (60+ per second).
            if (editor._scrollRaf) cancelAnimationFrame(editor._scrollRaf);
            editor._scrollRaf = requestAnimationFrame(() => {
                editor.updateFloatingControls();
                editor._scrollRaf = null;
            });
        }
    }, { passive: true });  // passive: browser doesn't wait for preventDefault
    
    editor.container.addEventListener('scroll', () => {
        if (editor.updateFloatingControls) {
            if (editor._scrollRaf2) cancelAnimationFrame(editor._scrollRaf2);
            editor._scrollRaf2 = requestAnimationFrame(() => {
                editor.updateFloatingControls();
                editor._scrollRaf2 = null;
            });
        }
    }, { passive: true });

    // mousemove for resize — throttled to next animation frame
    let _resizeRafScheduled = false;
    document.addEventListener('mousemove', (e) => {
        if (!editor.resizeData) return;  // Skip when not resizing
        if (_resizeRafScheduled) return;
        _resizeRafScheduled = true;
        editor._lastResizeEvent = e;
        requestAnimationFrame(() => {
            _resizeRafScheduled = false;
            if (editor.resizeData) editor.handleResizeMove(editor._lastResizeEvent);
        });
    }, { passive: true });
    document.addEventListener('mouseup', () => editor.handleResizeEnd());
}
