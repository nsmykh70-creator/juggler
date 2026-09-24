import {
    FONT_FAMILY_OPTIONS,
    EMOJI_LIST,
    SPECIAL_CHARACTERS,
    COLOR_PALETTE,
    DEFAULT_MODULE_SETTINGS
} from './modules/constants.js?v=20260901';
import { BLOCK_TEMPLATES } from './modules/blockTemplates.js?v=20260901';
import { t as i18nT, getLang as i18nGetLang, setLang as i18nSetLang, getAvailableLangs as i18nGetAvailableLangs } from './i18n.js?v=20260901';

import { 
    loadSettings as loadSettingsModule, 
    saveSettings as saveSettingsModule, 
    toggleStickyState as toggleStickyStateModule, 
    resetToolbarToNormal as resetToolbarToNormalModule, 
    handleWindowResize as handleWindowResizeModule, 
    handleScroll as handleScrollModule 
} from './modules/settings.js?v=20260901';

import { 
    initHistory as initHistoryModule, 
    pushHistory as pushHistoryModule, 
    undoHistory as undoHistoryModule, 
    redoHistory as redoHistoryModule 
} from './modules/history.js?v=20260901';

import { 
    saveSelection as saveSelectionModule, 
    restoreSelection as restoreSelectionModule, 
    applyCustomStyle as applyCustomStyleModule, 
    toggleColorPicker as toggleColorPickerModule 
} from './modules/selection.js?v=20260901';

import { 
    initEditor as initEditorModule, 
    loadGoogleFonts as loadGoogleFontsModule, 
    bindEvents as bindEventsModule 
} from './modules/init.js?v=20260901';

import { 
    toggleSource as toggleSourceModule, 
    toggleFullscreen as toggleFullscreenModule, 
    togglePreview as togglePreviewModule, 
    loadCodeMirrorResources, 
    decompressHtmlImages 
} from './modules/sourceMode.js?v=20260901';

import { 
    initBlockToolbar as initBlockToolbarModule, 
    initBlockSettings as initBlockSettingsModule, 
    toggleBlockSettings as toggleBlockSettingsModule, 
    repositionBlockToolbar as repositionBlockToolbarModule 
} from './modules/blockToolbar.js?v=20260901';

import { 
    initBoxToolbar as initBoxToolbarModule, 
    insertBox as insertBoxModule, 
    deselectBox as deselectBoxModule, 
    repositionBoxToolbar as repositionBoxToolbarModule 
} from './modules/boxToolbar.js?v=20260901';

import { 
    initImageToolbar as initImageToolbarModule, 
    selectImage as selectImageModule, 
    deselectImage as deselectImageModule, 
    createResizers as createResizersModule, 
    removeResizers as removeResizersModule, 
    updateResizerPos as updateResizerPosModule, 
    initResize as initResizeModule, 
    handleResizeMove as handleResizeMoveModule, 
    handleResizeEnd as handleResizeEndModule, 
    repositionImageToolbar as repositionImageToolbarModule 
} from './modules/imageToolbar.js?v=20260901';

import { 
    initTableToolbar as initTableToolbarModule, 
    insertRow as insertRowModule, 
    insertCol as insertColModule 
} from './modules/tableToolbar.js?v=20260901';

import { 
    initButtonToolbar as initButtonToolbarModule, 
    showButtonToolbar as showButtonToolbarModule, 
    hideButtonToolbar as hideButtonToolbarModule 
} from './modules/buttonToolbar.js?v=20260901';

import { 
    initMapToolbar as initMapToolbarModule, 
    checkMapSelection as checkMapSelectionModule 
} from './modules/mapToolbar.js?v=20260901';

import { 
    initCodeBlockToolbar as initCodeBlockToolbarModule,
    hideCodeBlockToolbar as hideCodeBlockToolbarModule
} from './modules/codeBlockToolbar.js?v=20260901';

import { openButtonCreatorModal as openButtonCreatorModalModule } from './modules/buttonCreator.js?v=20260901';
import { insertToggleButton as insertToggleButtonModule } from './modules/toggleButtonCreator.js?v=20260901';
import { openModulePicker as openModulePickerModule } from './modules/modulePicker.js?v=20260901';

import { 
    initBubbleMenu as initBubbleMenuModule, 
    toggleTextSettings as toggleTextSettingsModule, 
    checkSelection as checkSelectionModule 
} from './modules/textBubble.js?v=20260901';

import { openBlockLibrary as openBlockLibraryModule } from './modules/blockLibrary.js?v=20260901';
import { checkColumnSelection as checkColumnSelectionModule } from './modules/columnToolbar.js?v=20260901';
import { initQuickInsert as initQuickInsertModule, hideQuickInsert as hideQuickInsertModule } from './modules/quickInsert.js?v=20260901';
import { initVideoToolbar as initVideoToolbarModule } from './modules/videoToolbar.js?v=20260901';
import { getIcon as getIconModule } from './modules/icons.js?v=20260901';
import { openEmojiPicker as openEmojiPickerModule } from './modules/emojiPicker.js?v=20260901';
import { initBlockDragDrop as initBlockDragDropModule } from './modules/blockDragDrop.js?v=20260901';

import { 
    insertHTML as insertHTMLModule, 
    insertSection as insertSectionModule, 
    insertChecklist as insertChecklistModule, 
    duplicateBlock as duplicateBlockModule, 
    uploadFile as uploadFileModule, 
    insertImage as insertImageModule, 
    insertVideo as insertVideoModule, 
    insertCodeBlock as insertCodeBlockModule, 
    openCodeEditorModal as openCodeEditorModalModule, 
    getHighlightedCode as getHighlightedCodeModule 
} from './modules/contentOps.js?v=20260901';

import { 
    handleEditorClick as handleEditorClickModule, 
    handleKeydown as handleKeydownModule, 
    handlePaste as handlePasteModule, 
    handleDragStart as handleDragStartModule, 
    handleDragOver as handleDragOverModule, 
    handleDrop as handleDropModule 
} from './modules/coreEvents.js?v=20260901';

import { 
    createModalOverlay as createModalOverlayModule, 
    createGridModal as createGridModalModule, 
    openSettingsModal as openSettingsModalModule, 
    openTooltipModal as openTooltipModalModule, 
    openLinkModal as openLinkModalModule, 
    openAiModal as openAiModalModule,
    openAnchorModal as openAnchorModalModule,
    openImageUpload as openImageUploadModule,
    openVideoModal as openVideoModalModule,
    openAiImageModal as openAiImageModalModule
} from './modules/coreModals.js?v=20260901';

import { 
    renderToolbar as renderToolbarModule, 
    updateToolbarState as updateToolbarStateModule, 
    handleAlign as handleAlignModule 
} from './modules/mainToolbar.js?v=20260901';

export default class LuminaEditor {
    constructor(options) {
        this.selector = options.selector;
        this.height = options.height || '500px';
        this.placeholder = options.placeholder || 'Type something amazing...';
        // API key removed - using CMS built-in AI
        this.defaultApiKey = '';
        this.uploadUrl = options.uploadUrl || 'lumina_upload_image.php';
        this.cmsAiUrl = options.cmsAiUrl || 'api_generate_content.php';
        this.onChange = options.onChange || function() {};
        // Store all options so loadSettings() can read server-side config
        // (modules / buttons / bubbleMenus / stickyToolbar) coming from
        // data/lumina_settings.json via lumina_integration.php.
        this.options = options;

        // Expose i18n API on the editor instance
        this.t = i18nT;
        this.getLang = i18nGetLang;
        this.setLang = i18nSetLang;
        this.getAvailableLangs = i18nGetAvailableLangs;
        
        this.element = document.querySelector(this.selector);
        if (!this.element) {
            console.error('LuminaEditor: Target element not found:', this.selector);
            return;
        }

        this.element.luminaEditor = this;
        this.disableSticky = false; 

        this.fonts = FONT_FAMILY_OPTIONS;
        this.emojis = EMOJI_LIST;
        this.chars = SPECIAL_CHARACTERS;
        this.palette = COLOR_PALETTE;
        this.blockTemplates = BLOCK_TEMPLATES;
        
            // apiKey removed - using CMS AI
        
        this.handleScroll = this.handleScroll.bind(this);
        this.handleWindowResize = this.handleWindowResize.bind(this);
        
        this.loadSettings();

        this.isSourceMode = false;
        this.isPreviewMode = false;
        this.isFullscreen = false;
        this.selectedImage = null;
        this.selectedCell = null;
        this.selectedBox = null;
        this.selectedBlock = null;
        this.selectedButton = null;
        this.selectedColumn = null;
        this.selectedCodeBlock = null; // New state
        this.resizeData = null;
        this.history = null;
        this.savedRange = null; 
        this.dragSourceRange = null; 
        
        this.imageState = { rotate: 0, flipX: 1, flipY: 1 };
        this.textState = { rotate: 0, flipX: 1, flipY: 1, shadow: false, spacing: 0, kerning: 'auto' };

        this.init();
        this.initAutoSave();
    }
    
    // Core Methods routed to modules
    loadSettings() { loadSettingsModule(this); }
    saveSettings() { saveSettingsModule(this); }
    init() {
        initEditorModule(this);
        initHistoryModule(this);
        loadCodeMirrorResources();
        // Apply sticky state after container is created
        this.toggleStickyState();
        if(this.statusBar) this.updateStatusBar();
        this.initQuickInsert();
        if(this.editor) this.editor.spellcheck = false;
    }
    initAutoSave() {
        // Use unique key per page URL (not per selector) to avoid cross-contamination
        var saveKey = 'lumina_autosave_' + window.location.pathname + '_' + this.selector;
        var saved = localStorage.getItem(saveKey);
        // Only restore autosave if textarea is empty AND we're editing (not creating new)
        if (saved && !this.element.value && this.element.dataset.hasContent !== '0') {
            this.editor.innerHTML = saved;
            this.pushHistory(true);
            this.sync(true);
        }
        // Clear stale autosaves for this key if we have real content
        if (this.element.value) {
            localStorage.removeItem(saveKey);
        }
        var self = this;
        var lastSavedHtml = '';
        // Use a debounced save that only writes to localStorage when the
        // content actually changed since the last save. setInterval fires
        // every 5s regardless, but we skip the localStorage.setItem call
        // (which is expensive on large documents — ~50ms for 100KB).
        setInterval(() => {
            if (!self.editor) return;
            var html = self.editor.innerHTML;
            // Skip placeholder-only content
            if (!html || html === '<p>' + self.placeholder + '</p>') return;
            // Skip if unchanged since last save
            if (html === lastSavedHtml) return;
            try {
                localStorage.setItem(saveKey, html);
                lastSavedHtml = html;
            } catch (e) {
                // localStorage quota exceeded — clear old autosaves and retry once
                if (e.name === 'QuotaExceededError' || e.name === 'NS_ERROR_DOM_QUOTA_REACHED') {
                    for (var i = localStorage.length - 1; i >= 0; i--) {
                        var k = localStorage.key(i);
                        if (k && k.indexOf('lumina_autosave_') === 0 && k !== saveKey) {
                            localStorage.removeItem(k);
                        }
                    }
                    try { localStorage.setItem(saveKey, html); lastSavedHtml = html; } catch (_) {}
                }
            }
        }, 5000);
    }

    // Helpers
    getIcon(name) { return getIconModule(name); }
    createModalOverlay(title, content, btns) { return createModalOverlayModule(this, title, content, btns); }
    
    // Events
    bindEvents() { bindEventsModule(this); }
    handleEditorClick(e) { handleEditorClickModule(this, e); }
    handleKeydown(e) { handleKeydownModule(this, e); }
    handlePaste(e) { handlePasteModule(this, e); }
    handleDragStart(e) { handleDragStartModule(this, e); }
    handleDragOver(e) { handleDragOverModule(this, e); }
    handleDrop(e) { handleDropModule(this, e); }
    handleWindowResize() { handleWindowResizeModule(this); }
    handleScroll() { handleScrollModule(this); }
    handleResizeMove(e) { handleResizeMoveModule(this, e); }
    handleResizeEnd() { handleResizeEndModule(this); this.pushHistory(true); }

    // Toolbar & UI
    renderToolbar() { renderToolbarModule(this); }
    reRenderToolbar() { this.toolbarContainer.innerHTML = ''; renderToolbarModule(this); this.toggleStickyState(); }
    updateToolbarState() { updateToolbarStateModule(this); }
    toggleStickyState() { toggleStickyStateModule(this); }
    resetToolbarToNormal() { resetToolbarToNormalModule(this); }
    updateFloatingControls() {
        this.checkSelection();
        if (this.selectedBox) this.repositionBoxToolbar();
        if (this.selectedImage) this.repositionImageToolbar();
        if (this.selectedBlock) this.repositionBlockToolbar();
        if (this.selectedButton && this.btnToolbar.style.display !== 'none') {
            const rect = this.selectedButton.getBoundingClientRect();
            this.btnToolbar.style.top = `${rect.bottom + window.scrollY + 5}px`;
            this.btnToolbar.style.left = `${rect.left}px`;
        }
    }
    updateStatusBar() {
        if (!this.statusBar) return;
        const crumbsContainer = this.statusBar.querySelector('.lumina-breadcrumbs');
        crumbsContainer.innerHTML = '';
        const sel = window.getSelection();
        if (sel.rangeCount > 0 && this.editor.contains(sel.anchorNode)) {
            let node = sel.anchorNode;
            if (node.nodeType === 3) node = node.parentElement;
            const path = [];
            while (node && node !== this.editor) { if (node.nodeType === 1) path.unshift(node); node = node.parentElement; }
            path.forEach((el, index) => {
                const item = document.createElement('span'); item.className = 'lumina-breadcrumb-item'; item.innerText = el.tagName.toLowerCase();
                item.onclick = (e) => { e.preventDefault(); e.stopPropagation(); const range = document.createRange(); range.selectNodeContents(el); sel.removeAllRanges(); sel.addRange(range); this.updateStatusBar(); };
                crumbsContainer.appendChild(item);
                if (index < path.length - 1) { const sep = document.createElement('span'); sep.className = 'lumina-breadcrumb-sep'; sep.innerText = '›'; crumbsContainer.appendChild(sep); }
            });
        }
        const wcContainer = this.statusBar.querySelector('#ls-word-count');
        const text = this.editor.innerText || '';
        const words = text.trim().split(/\s+/).filter(w => w.length > 0).length;
        wcContainer.innerText = `${words} words`;
    }

    // Content Operations
    insertHTML(html) { insertHTMLModule(this, html); }
    insertSection() { insertSectionModule(this); }
    insertChecklist() { insertChecklistModule(this); }
    duplicateBlock() { duplicateBlockModule(this); }
    insertTable() { 
        const h = `<div class="lbs-row"><label>Rows</label><input type="number" id="tbl-rows" value="3" min="1" max="20"></div><div class="lbs-row"><label>Cols</label><input type="number" id="tbl-cols" value="3" min="1" max="10"></div>`; 
        this.createModalOverlay('Insert Table', h, [{ text: 'Insert', onClick: (e, m) => { const r = m.querySelector('#tbl-rows').value; const c = m.querySelector('#tbl-cols').value; let t = '<table style="width:100%;border-collapse:collapse;"><tbody>'; for(let i=0;i<r;i++){ t+='<tr>'; for(let j=0;j<c;j++) t+='<td style="border:1px solid #cbd5e1;padding:8px;"><br></td>'; t+='</tr>'; } t+='</tbody></table><p><br/></p>'; this.editor.focus(); this.restoreSelection(); document.execCommand('insertHTML', false, t); m.closest('.lumina-modal-overlay').remove(); this.sync(); this.pushHistory(true); } }]); 
    }
    
    insertBox() { insertBoxModule(this); this.pushHistory(true); }
    insertCodeBlock() { insertCodeBlockModule(this); }
    openCodeEditorModal(wrapper) { openCodeEditorModalModule(this, wrapper); }
    getHighlightedCode(text, lang) { return getHighlightedCodeModule(text, lang); }
    uploadFile(file) { return uploadFileModule(this, file); }
    insertImage(url) { insertImageModule(this, url); }
    insertVideo(url) { insertVideoModule(this, url); }

    // Modals
    openSettingsModal() { openSettingsModalModule(this); }
    openLinkModal() { openLinkModalModule(this); }
    openAiModal() { openAiModalModule(this); }
    openAnchorModal() { openAnchorModalModule(this); }
    openButtonCreatorModal() { openButtonCreatorModalModule(this); }
    insertToggleButton() { insertToggleButtonModule(this); }
    openModulePicker() { openModulePickerModule(this); }
    openBlockLibrary() { openBlockLibraryModule(this); }
    openSpecialCharModal() { createGridModalModule(this, 'Chars', this.chars, c => { this.editor.focus(); this.restoreSelection(); document.execCommand('insertText', false, c); this.pushHistory(true); }); }
    openEmojiModal() { openEmojiPickerModule(this); }
    openTooltipModal(target) { openTooltipModalModule(this, target); }
    openImageUpload() { openImageUploadModule(this); }
    openVideoModal() { openVideoModalModule(this); }
    openAiImageModal() { openAiImageModalModule(this); }

    // Sub-modules Init
    initBlockSettings() { initBlockSettingsModule(this); }
    initBlockToolbar() { initBlockToolbarModule(this); }
    toggleBlockSettings() { toggleBlockSettingsModule(this); }
    repositionBlockToolbar() { repositionBlockToolbarModule(this); }
    initBoxToolbar() { initBoxToolbarModule(this); }
    repositionBoxToolbar() { repositionBoxToolbarModule(this); }
    initImageToolbar() { initImageToolbarModule(this); }
    repositionImageToolbar() { repositionImageToolbarModule(this); }
    selectImage(el) { selectImageModule(this, el); }
    initTableToolbar() { initTableToolbarModule(this); }
    initButtonToolbar() { initButtonToolbarModule(this); }
    initMapToolbar() { initMapToolbarModule(this); }
    checkMapSelection() { checkMapSelectionModule(this); }
    initQuickInsert() { initQuickInsertModule(this); }
    initBubbleMenu() { initBubbleMenuModule(this); }
    toggleTextSettings() { toggleTextSettingsModule(this); }
    initCodeBlockToolbar() { initCodeBlockToolbarModule(this); }
    initBlockDragDrop() { initBlockDragDropModule(this); }
    
    // Selection & State
    saveSelection() { saveSelectionModule(this); }
    restoreSelection() { restoreSelectionModule(this); }
    checkSelection() { checkSelectionModule(this); }
    deselectAll() { 
        deselectImageModule(this); 
        deselectBoxModule(this); 
        hideButtonToolbarModule(this); 
        hideQuickInsertModule(this); 
        hideCodeBlockToolbarModule(this); // Hide code block toolbar
        if (this.selectedBlock) { 
            this.selectedBlock.classList.remove('lumina-active-block'); 
            this.selectedBlock = null; 
            if (this.blockToolbar) this.blockToolbar.style.display = 'none'; 
            if (this.blockSettings) this.blockSettings.style.display = 'none'; 
        } 
    }
    
    // Modes
    toggleSource() { toggleSourceModule(this); }
    toggleFullscreen() { toggleFullscreenModule(this); }
    togglePreview() { togglePreviewModule(this); }
    toggleThemeView() {
        // Режим шаблона требует CMS-адаптер (endpoint темы). В standalone-деплое
        // конфига нет — выходим тихо, чтобы не слать 404-запросы.
        if (!window._luminaEditorConfig) {
            console.info('Lumina: Theme view requires a CMS adapter (window._luminaEditorConfig).');
            return;
        }
        this._themeViewMode = !this._themeViewMode;
        var btn = this.toolbarContainer.querySelector('[data-btn-id="themeView"]');
        var self = this;
        
        if (this._themeViewMode) {
            this.editor.classList.add('lumina-theme-view');
            if (btn) btn.classList.add('active');
            
            // Remove old theme styles
            var old = document.getElementById('lumina-theme-styles');
            if (old) old.remove();
            
            // Determine which theme to load
            var themeName = 'helios';
            var config = window._luminaEditorConfig || {};
            if (config.theme) themeName = config.theme;
            var templateSelect = document.querySelector('select[name="template"]');
            if (templateSelect && templateSelect.value) themeName = templateSelect.value;
            
            // Load scoped theme CSS via PHP endpoint
            fetch('api_theme_css.php?theme=' + encodeURIComponent(themeName) + '&t=' + Date.now())
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(function(css) {
                    var styleEl = document.createElement('style');
                    styleEl.id = 'lumina-theme-styles';
                    styleEl.textContent = css;
                    document.head.appendChild(styleEl);
                    
                    // Also fetch theme index.html to extract inline <style> tags
                    return fetch('../themes/' + themeName + '/index.html?t=' + Date.now());
                })
                .then(function(r) {
                    if (!r.ok) return '';
                    return r.text();
                })
                .then(function(html) {
                    if (!html) return;
                    // Parse HTML and extract <style> tags
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var inlineStyles = doc.querySelectorAll('style');
                    if (inlineStyles.length === 0) return;
                    
                    var existing = document.getElementById('lumina-theme-styles');
                    var css = existing ? existing.textContent : '';
                    
                    // Process each <style> tag — scope under .lumina-editor.lumina-theme-view
                    inlineStyles.forEach(function(s) {
                        var text = s.textContent;
                        // Remove <style> tags if any
                        text = text.replace(/<\/?style[^>]*>/gi, '');
                        // Scope body → .lumina-editor.lumina-theme-view
                        text = text.replace(/(^|[^a-zA-Z0-9_-])body(?![a-zA-Z0-9_-])/g, '$1.lumina-editor.lumina-theme-view');
                        // Scope :root → .lumina-editor.lumina-theme-view
                        text = text.replace(/(^|[^a-zA-Z0-9_-]):root(?![a-zA-Z0-9_-])/g, '$1.lumina-editor.lumina-theme-view');
                        // Remove * { } universal rules
                        text = text.replace(/(^|\n)\s*\*\s*\{[^}]*\}/g, '$1');
                        // Remove overflow/display/position/margin from .lumina-editor.lumina-theme-view rules
                        text = text.replace(/overflow-x\s*:\s*[^;]+;?/gi, '');
                        text = text.replace(/overflow\s*:\s*[^;]+;?/gi, '');
                        // Strip opacity:0 (not 0.3 etc)
                        text = text.replace(/opacity\s*:\s*0(?!\.\d)\s*;?/gi, '');
                        text = text.replace(/visibility\s*:\s*hidden\s*;?/gi, '');
                        
                        css += '\n' + text;
                    });
                    
                    if (existing) {
                        existing.textContent = css;
                    } else {
                        var styleEl2 = document.createElement('style');
                        styleEl2.id = 'lumina-theme-styles';
                        styleEl2.textContent = css;
                        document.head.appendChild(styleEl2);
                    }
                })
                .catch(function(e) {
                    console.error('Theme view error:', e);
                });
        } else {
            this.editor.classList.remove('lumina-theme-view');
            if (btn) btn.classList.remove('active');
            var old = document.getElementById('lumina-theme-styles');
            if (old) old.remove();
        }
    }
    
    // Misc
    sync(skip) { 
        let content = this.editor.innerHTML;
        if (this.isSourceMode && this.cm) { const raw = this.cm.getValue(); content = decompressHtmlImages(this, raw); }
        if (this.element && (this.element.tagName === 'TEXTAREA' || this.element.tagName === 'INPUT')) { this.element.value = content; }
        if (this.onChange) this.onChange(content); 
        if (!skip) this.pushHistory(false); 
    }
    pushHistory(force) { pushHistoryModule(this, force); }
    undo() { undoHistoryModule(this); }
    redo() { redoHistoryModule(this); }
    
    fixPunctuationSpacing() { this.restoreSelection(); const sel = window.getSelection(); if (sel.rangeCount > 0 && !sel.isCollapsed) { const range = sel.getRangeAt(0); let text = range.toString(); const fixed = text.replace(/([.,;?!])(?=[^\s])/g, '$1 '); if (fixed !== text) { document.execCommand('insertText', false, fixed); this.sync(); this.pushHistory(true); } } else { alert('Please select text to fix spacing.'); } }
    toggleSpellCheck() { const current = this.editor.spellcheck; this.editor.spellcheck = !current; this.editor.setAttribute('spellcheck', (!current).toString()); this.updateToolbarState(); } // Removed alert, updated state
    showWordCount() { const textDoc = this.editor.innerText || ''; const wordsDoc = textDoc.trim().split(/\s+/).filter(w => w.length > 0).length; const charsDoc = textDoc.length; const sel = window.getSelection(); const textSel = sel.toString(); const wordsSel = textSel.trim().split(/\s+/).filter(w => w.length > 0).length; const charsSel = textSel.length; const h = `<table class="lumina-wordcount-table"><thead><tr><th>Count</th><th>Document</th><th>Selection</th></tr></thead><tbody><tr><td>Words</td><td>${wordsDoc}</td><td>${wordsSel}</td></tr><tr><td>Characters</td><td>${charsDoc}</td><td>${charsSel}</td></tr><tr><td>Paragraphs</td><td>${this.editor.querySelectorAll('p').length}</td><td>-</td></tr></tbody></table>`; this.createModalOverlay('Word Count', h); }
    
    handleAlign(mode) { handleAlignModule(this, mode); }
    loadGoogleFonts() { loadGoogleFontsModule(this); }
}
