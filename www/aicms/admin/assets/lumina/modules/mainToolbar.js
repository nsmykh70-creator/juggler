import { getIcon } from './icons.js?v=20260901';
import { applyCustomStyle, toggleColorPicker } from './selection.js?v=20260901';
import { updateBubbleState } from './textBubble.js?v=20260901';
import { showCodeBlockToolbar } from './codeBlockToolbar.js?v=20260901';

export function createBtn(editor, icon, title, action, id) { 
    const b = document.createElement('button'); 
    b.type = 'button'; 
    b.className = 'lumina-btn'; 
    b.innerHTML = getIcon(icon); 
    b.title = title; 
    
    // Explicit ID for highlighting
    if (id) b.dataset.btnId = id;

    b.onmousedown = (e) => { 
        e.preventDefault(); 
        if (action) action(e); 
    }; 
    return b; 
}

export function createSep() { 
    const s = document.createElement('div'); 
    s.className = 'lumina-sep'; 
    return s; 
}

export function renderGroup(editor, bar, btns, isVisible) {
    if (!btns) return;
    let hasVisible = false;
    btns.forEach(b => {
         if (b.mod && !editor.settings.modules[b.mod]) return;
         if (!isVisible(b.id)) return;
         hasVisible = true;
         const btn = document.createElement('button'); 
         btn.type = 'button'; 
         btn.className = 'lumina-btn'; 
         btn.innerHTML = getIcon(b.icon); 
         btn.title = b.title;
         
         // Assign IDs for state tracking
         if(b.id) btn.dataset.btnId = b.id;
         if(b.cmd) btn.dataset.cmd = b.cmd;

         btn.onmousedown = (e) => { 
             e.preventDefault(); 
             if (b.action) b.action();
             else if (b.cmd) { 
                 editor.editor.focus(); 
                 document.execCommand(b.cmd, false, b.val || null); 
                 editor.sync(); 
                 editor.pushHistory(true); 
                 editor.updateToolbarState(); 
             } 
         };
         bar.appendChild(btn);
    });
    if (hasVisible) bar.appendChild(createSep());
}

function createSelectsFragment(editor, isVisible) {
    const frag = document.createDocumentFragment();
    const formatSelect = document.createElement('select'); formatSelect.className = 'lumina-select'; formatSelect.style.width = '100px';
    formatSelect.innerHTML = `<option value="p">Normal</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option><option value="blockquote">Quote</option><option value="pre">Code</option>`;
    formatSelect.onchange = (e) => { editor.editor.focus(); editor.restoreSelection(); document.execCommand('formatBlock', false, e.target.value); editor.sync(); editor.pushHistory(true); };
    frag.appendChild(formatSelect);
    editor.formatSelect = formatSelect;
    const fontSelect = document.createElement('select'); fontSelect.className = 'lumina-select'; fontSelect.style.width = '100px';
    fontSelect.innerHTML = `<option value="">Font</option>${editor.fonts.map(f => `<option value="${f}">${f}</option>`).join('')}`;
    fontSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontFamily: e.target.value }); editor.pushHistory(true); };
    frag.appendChild(fontSelect);
    editor.fontSelect = fontSelect;
    const sizeSelect = document.createElement('select'); sizeSelect.className = 'lumina-select'; sizeSelect.style.width = '60px';
    sizeSelect.innerHTML = `<option value="">Size</option>${['8','10','12','14','16','18','20','24','30','36','48','60','72','96'].map(s => `<option value="${s}px">${s}</option>`).join('')}`;
    sizeSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontSize: e.target.value }); editor.pushHistory(true); };
    frag.appendChild(sizeSelect);
    editor.sizeSelect = sizeSelect;
    if (isVisible('lineHeight')) { const lhSelect = document.createElement('select'); lhSelect.className = 'lumina-select'; lhSelect.style.width = '50px'; lhSelect.title = "Line Height"; lhSelect.innerHTML = `<option value="">LH</option>${['1.0','1.2','1.5','1.8','2.0','2.5'].map(v => `<option value="${v}">${v}</option>`).join('')}`; lhSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { lineHeight: e.target.value }); editor.pushHistory(true); }; frag.appendChild(lhSelect); }
    if (isVisible('letterSpacing')) { const lsSelect = document.createElement('select'); lsSelect.className = 'lumina-select'; lsSelect.style.width = '60px'; lsSelect.title = "Letter Spacing"; lsSelect.innerHTML = `<option value="">Spc</option>${['-1px','0px','0.5px','1px','2px','3px'].map(v => `<option value="${v}">${v}</option>`).join('')}`; lsSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { letterSpacing: e.target.value }); editor.pushHistory(true); }; editor.lsSelect = lsSelect; frag.appendChild(lsSelect); }
    if (isVisible('kerning')) { const kernSelect = document.createElement('select'); kernSelect.className = 'lumina-select'; kernSelect.style.width = '60px'; kernSelect.title = "Font Kerning"; kernSelect.innerHTML = `<option value="">Kern</option><option value="auto">Auto</option><option value="normal">Normal</option><option value="none">None</option>`; kernSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontKerning: e.target.value }); editor.pushHistory(true); }; editor.kernSelect = kernSelect; frag.appendChild(kernSelect); }
    frag.appendChild(createSep());
    return frag;
}

function buildButtonDefMap(editor) {
    const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);
    const m = {};
    m['undo'] = { id:'undo', icon:'undo', title:t('undo'), action:() => editor.undo() };
    m['redo'] = { id:'redo', icon:'redo', title:t('redo'), action:() => editor.redo() };
    m['bold'] = { id:'bold', icon:'bold', title:t('bold'), cmd:'bold' };
    m['italic'] = { id:'italic', icon:'italic', title:t('italic'), cmd:'italic' };
    m['underline'] = { id:'underline', icon:'underline', title:t('underline'), cmd:'underline' };
    m['strike'] = { id:'strike', icon:'strikethrough', title:t('strikethrough'), cmd:'strikeThrough' };
    m['superscript'] = { id:'superscript', icon:'superscript', title:t('superscript'), cmd:'superscript' };
    m['subscript'] = { id:'subscript', icon:'subscript', title:t('subscript'), cmd:'subscript' };
    m['fixSpacing'] = { id:'fixSpacing', icon:'text-spacing', title:t('fixSpacing'), action:() => editor.fixPunctuationSpacing() };
    m['spellCheck'] = { id:'spellCheck', icon:'spellcheck', title:t('spellCheck'), action:() => editor.toggleSpellCheck() };
    m['clearFormat'] = { id:'clearFormat', icon:'eraser', title:t('clearFormat'), action:() => { editor.editor.focus(); editor.restoreSelection(); document.execCommand('removeFormat', false, null); editor.sync(); editor.pushHistory(true); } };
    m['quote'] = { id:'quote', icon:'quote', title:t('quote'), cmd:'formatBlock', val:'blockquote' };
    m['codeBlock'] = { id:'codeBlock', icon:'code-block', title:t('codeBlock'), action:() => editor.insertCodeBlock() };
    m['checklist'] = { id:'checklist', icon:'checklist', title:t('checklist'), action:() => editor.insertChecklist() };
    m['ul'] = { id:'ul', icon:'list', title:t('ul'), cmd:'insertUnorderedList' };
    m['ol'] = { id:'ol', icon:'list-ol', title:t('ol'), cmd:'insertOrderedList' };
    m['alignLeft'] = { id:'alignLeft', icon:'align-left', title:t('alignLeft'), action:() => handleAlign(editor,'left'), cmd:'justifyLeft' };
    m['alignCenter'] = { id:'alignCenter', icon:'align-center', title:t('alignCenter'), action:() => handleAlign(editor,'center'), cmd:'justifyCenter' };
    m['alignRight'] = { id:'alignRight', icon:'align-right', title:t('alignRight'), action:() => handleAlign(editor,'right'), cmd:'justifyRight' };
    m['separator'] = { id:'separator', icon:'minus', title:t('separator'), cmd:'insertHorizontalRule' };
    m['link'] = { id:'link', icon:'link', title:t('link'), action:() => editor.openLinkModal() };
    m['anchor'] = { id:'anchor', icon:'anchor', title:t('anchor'), action:() => editor.openAnchorModal() };
    m['image'] = { id:'image', icon:'image', title:t('image'), mod:'images', action:() => editor.openImageUpload() };
    // aiImage button removed — AI content generator (✨ AI) already covers this
    m['video'] = { id:'video', icon:'video', title:t('video'), mod:'video', action:() => editor.openVideoModal() };
    m['table'] = { id:'table', icon:'table', title:t('table'), mod:'tables', action:() => editor.insertTable() };
    m['box'] = { id:'box', icon:'box', title:t('box'), action:() => editor.insertBox() };
    m['section'] = { id:'section', icon:'section-plus', title:t('section'), action:() => editor.insertSection() };
    m['emoji'] = { id:'emoji', icon:'smile', title:t('emoji'), mod:'emoji', action:() => editor.openEmojiModal() };
    m['specialChars'] = { id:'specialChars', icon:'omega', title:t('specialChars'), action:() => editor.openSpecialCharModal() };
    m['tooltip'] = { id:'tooltip', icon:'message-square-plus', title:t('tooltip'), action:() => editor.openTooltipModal() };
    m['blocks'] = { id:'blocks', icon:'layout', title:t('blockTemplates'), mod:'blocks', action:() => editor.openBlockLibrary() };
    m['createButton'] = { id:'createButton', icon:'mouse-pointer', title:t('createButton'), action:() => editor.openButtonCreatorModal() };
    m['toggleButton'] = { id:'toggleButton', icon:'toggle-readmore', title:t('toggleButton'), action:() => editor.insertToggleButton() };
    m['insertModule'] = { id:'insertModule', icon:'modules', title:t('insertModule'), action:() => editor.openModulePicker() };
    m['duplicate'] = { id:'duplicate', icon:'copy', title:t('duplicate'), action:() => editor.duplicateBlock() };
    m['wordCount'] = { id:'wordCount', icon:'bar-chart', title:t('wordCount'), action:() => editor.showWordCount() };
    m['formatHTML'] = { id:'formatHTML', icon:'align-left', title:(t('format_html')||'Format HTML'), action:() => { import('./contentOps.js?v=20260901').then(m => m.formatHTML(editor)); } };
    m['history'] = { id:'history', icon:'history', title:(t('history')||'History'), action:() => { var id = new URLSearchParams(window.location.search).get('id'); if (id) window.open('page_history.php?id=' + id, '_blank'); } };
    m['sourceCode'] = { id:'sourceCode', icon:'code', title:t('sourceCode'), mod:'code', action:() => editor.toggleSource() };
    m['preview'] = { id:'preview', icon:'monitor', title:t('preview'), action:() => editor.togglePreview() };
    m['themeView'] = { id:'themeView', icon:'palette', title:(t('theme_view')||'Режим шаблона'), action:() => editor.toggleThemeView() };
    m['fullscreen'] = { id:'fullscreen', icon:'maximize', title:t('fullscreen'), action:() => editor.toggleFullscreen() };
    m['settings'] = { id:'settings', icon:'settings', title:t('settings'), action:() => editor.openSettingsModal() };
    return m;
}

function renderToolbarOrdered(editor, bar, customOrder, isVisible) {
    const btnDefs = buildButtonDefMap(editor);
    const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);
    const skipIds = new Set(['letterSpacing', 'kerning']);
    const rightSideIds = new Set(['preview', 'fullscreen', 'settings']);

    customOrder.forEach(btnId => {
        if (skipIds.has(btnId)) return;
        // Special rendering for color buttons (not in def map)
        if (btnId === 'textColor') {
            if (!isVisible('textColor')) return;
            const btn = document.createElement('button'); btn.className = 'lumina-btn'; btn.dataset.btnId = 'textColor'; btn.type = 'button';
            btn.innerHTML = '<span style="border-bottom:3px solid currentColor; font-weight:bold; font-size:14px;">A</span>'; btn.title = t('textColor');
            btn.onmousedown = (e) => { e.preventDefault(); toggleColorPicker(editor, 'color', btn); };
            bar.appendChild(btn); return;
        }
        if (btnId === 'highlightColor') {
            if (!isVisible('highlightColor')) return;
            const btn = document.createElement('button'); btn.className = 'lumina-btn'; btn.dataset.btnId = 'highlightColor'; btn.type = 'button';
            btn.innerHTML = getIcon('highlighter'); btn.title = t('highlightColor');
            btn.onmousedown = (e) => { e.preventDefault(); toggleColorPicker(editor, 'backgroundColor', btn); };
            bar.appendChild(btn); return;
        }
        const def = btnDefs[btnId];
        if (!def) return;
        if (def.mod && !editor.settings.modules[def.mod]) return;
        if (!isVisible(def.id)) return;
        const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'lumina-btn';
        btn.innerHTML = getIcon(def.icon); btn.title = def.title;
        if (def.id) btn.dataset.btnId = def.id;
        if (def.cmd) btn.dataset.cmd = def.cmd;
        btn.onmousedown = (e) => {
            e.preventDefault();
            if (def.action) def.action();
            else if (def.cmd) { editor.editor.focus(); document.execCommand(def.cmd, false, def.val || null); editor.sync(); editor.pushHistory(true); editor.updateToolbarState(); }
        };
        bar.appendChild(btn);
    });

    // Insert selects after 'redo' button (fixed position)
    const redoBtn = bar.querySelector('[data-btn-id="redo"]');
    if (redoBtn) { redoBtn.after(createSelectsFragment(editor, isVisible)); }
    else { bar.prepend(createSelectsFragment(editor, isVisible)); }

    // Insert flex spacer before first right-side button
    let spacerInserted = false;
    for (const id of rightSideIds) {
        const btn = bar.querySelector(`[data-btn-id="${id}"]`);
        if (btn) { const spacer = document.createElement('div'); spacer.style.flex = '1'; bar.insertBefore(spacer, btn); spacerInserted = true; break; }
    }
    if (!spacerInserted) { const spacer = document.createElement('div'); spacer.style.flex = '1'; bar.appendChild(spacer); }

    // AI button always at the end
    if (editor.settings.modules.ai) {
        const aiBtn = document.createElement('button'); aiBtn.type = 'button'; aiBtn.className = 'lumina-btn lumina-btn-ai';
        aiBtn.title = t('aiGenerator'); aiBtn.innerHTML = '\u2728 AI'; aiBtn.onclick = () => editor.openAiModal(); bar.appendChild(aiBtn);
    }
}

export function handleAlign(editor, mode) {
    if (editor.selectedBlock) {
        const conflictingClasses = ['w-full', 'mx-auto', 'ml-auto', 'mr-auto', 'ml-0', 'mr-0'];
        editor.selectedBlock.classList.remove(...conflictingClasses);
        const currentW = editor.selectedBlock.style.width; 
        const currentMaxW = editor.selectedBlock.style.maxWidth;
        if ((!currentW || currentW === '100%') && (!currentMaxW || currentMaxW === '100%')) { 
            editor.selectedBlock.style.maxWidth = '90%'; 
            editor.selectedBlock.style.width = '90%'; 
        }
        if (mode === 'left') { 
            editor.selectedBlock.style.setProperty('margin-right', 'auto', 'important'); 
            editor.selectedBlock.style.setProperty('margin-left', '0', 'important'); 
        } else if (mode === 'center') { 
            editor.selectedBlock.style.setProperty('margin-right', 'auto', 'important'); 
            editor.selectedBlock.style.setProperty('margin-left', 'auto', 'important'); 
        } else if (mode === 'right') { 
            editor.selectedBlock.style.setProperty('margin-left', 'auto', 'important'); 
            editor.selectedBlock.style.setProperty('margin-right', '0', 'important'); 
        }
        editor.repositionBlockToolbar();
    } else if (editor.selectedImage) {
        editor.selectedImage.style.float = mode === 'center' ? 'none' : mode; 
        editor.selectedImage.style.display = mode === 'center' ? 'block' : 'inline-block'; 
        editor.selectedImage.style.margin = mode === 'center' ? '10px auto' : '10px';
        editor.repositionImageToolbar();
    } else if (editor.selectedCodeBlock) {
        // Code Block alignment logic mirrors block
        const block = editor.selectedCodeBlock;
        if (mode === 'left') { 
            block.style.setProperty('margin-right', 'auto', 'important'); 
            block.style.setProperty('margin-left', '0', 'important'); 
        } else if (mode === 'center') { 
            block.style.setProperty('margin-right', 'auto', 'important'); 
            block.style.setProperty('margin-left', 'auto', 'important'); 
        } else if (mode === 'right') { 
            block.style.setProperty('margin-left', 'auto', 'important'); 
            block.style.setProperty('margin-right', '0', 'important'); 
        }
        showCodeBlockToolbar(editor, block);
    } else {
        const cmd = mode === 'left' ? 'justifyLeft' : mode === 'center' ? 'justifyCenter' : 'justifyRight'; 
        editor.editor.focus(); 
        document.execCommand(cmd, false, null);
    }
    editor.sync(); 
    editor.pushHistory(true); 
    editor.updateToolbarState();
}

export function renderToolbar(editor) {
    editor.toolbarPlaceholder = document.createElement('div');
    editor.toolbarPlaceholder.className = 'lumina-toolbar-placeholder';
    editor.toolbarContainer.appendChild(editor.toolbarPlaceholder);
    const bar = document.createElement('div');
    bar.className = 'lumina-toolbar';
    const isVisible = (id) => editor.settings.buttons[id] !== false;
    
    // Custom button order (from drag-and-drop settings)
    const customOrder = editor.settings.buttonOrder;
    if (customOrder && customOrder.length > 0) {
        renderToolbarOrdered(editor, bar, customOrder, isVisible);
        editor.toolbarContainer.appendChild(bar);
        return;
    }
    
    // Group definitions matching constants.ts IDs
    const t = (typeof editor.t === 'function') ? editor.t.bind(editor) : (k => k);
    const groups = [
        { id: 'history', btns: [{ id: 'undo', icon: 'undo', title: t('undo'), action: () => editor.undo() }, { id: 'redo', icon: 'redo', title: t('redo'), action: () => editor.redo() }] },
        { id: 'text', btns: [ 
            { id: 'bold', icon: 'bold', title: t('bold'), cmd: 'bold' }, 
            { id: 'italic', icon: 'italic', title: t('italic'), cmd: 'italic' }, 
            { id: 'underline', icon: 'underline', title: t('underline'), cmd: 'underline' }, 
            { id: 'strike', icon: 'strikethrough', title: t('strikethrough'), cmd: 'strikeThrough' }, 
            { id: 'superscript', icon: 'superscript', title: t('superscript'), cmd: 'superscript' }, 
            { id: 'subscript', icon: 'subscript', title: t('subscript'), cmd: 'subscript' }, 
            { id: 'fixSpacing', icon: 'text-spacing', title: t('fixSpacing'), action: () => editor.fixPunctuationSpacing() }, 
            { id: 'spellCheck', icon: 'spellcheck', title: t('spellCheck'), action: () => editor.toggleSpellCheck() }, 
            { id: 'clearFormat', icon: 'eraser', title: t('clearFormat'), action: () => { editor.editor.focus(); editor.restoreSelection(); document.execCommand('removeFormat', false, null); editor.sync(); editor.pushHistory(true); } } 
        ] },
        { id: 'blocks', btns: [ 
            { id: 'quote', icon: 'quote', title: t('quote'), cmd: 'formatBlock', val: 'blockquote' }, 
            { id: 'codeBlock', icon: 'code-block', title: t('codeBlock'), action: () => editor.insertCodeBlock() }, 
            { id: 'checklist', icon: 'checklist', title: t('checklist'), action: () => editor.insertChecklist() }, 
            { id: 'ul', icon: 'list', title: t('ul'), cmd: 'insertUnorderedList' }, 
            { id: 'ol', icon: 'list-ol', title: t('ol'), cmd: 'insertOrderedList' } 
        ] },
        { id: 'insert', btns: [ 
            { id: 'separator', icon: 'minus', title: t('separator'), cmd: 'insertHorizontalRule' }, 
            { id: 'link', icon: 'link', title: t('link'), action: () => editor.openLinkModal() }, 
            { id: 'anchor', icon: 'anchor', title: t('anchor'), action: () => editor.openAnchorModal() }, 
            { id: 'image', icon: 'image', title: t('image'), mod: 'images', action: () => editor.openImageUpload() }, 
            { id: 'video', icon: 'video', title: t('video'), mod: 'video', action: () => editor.openVideoModal() }, 
            { id: 'table', icon: 'table', title: t('table'), mod: 'tables', action: () => editor.insertTable() }, 
            { id: 'box', icon: 'box', title: t('box'), action: () => editor.insertBox() }, 
            { id: 'section', icon: 'section-plus', title: t('section'), action: () => editor.insertSection() } 
        ] },
        { id: 'utils', btns: [
            { id: 'duplicate', icon: 'copy', title: t('duplicate'), action: () => editor.duplicateBlock() }, 
            { id: 'wordCount', icon: 'bar-chart', title: t('wordCount'), action: () => editor.showWordCount() }
        ] }
    ];

    if(groups[0]) renderGroup(editor, bar, groups[0].btns, isVisible);

    const formatSelect = document.createElement('select'); formatSelect.className = 'lumina-select'; formatSelect.style.width = '100px';
    formatSelect.innerHTML = `<option value="p">Normal</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option><option value="blockquote">Quote</option><option value="pre">Code</option>`;
    formatSelect.onchange = (e) => { editor.editor.focus(); editor.restoreSelection(); document.execCommand('formatBlock', false, e.target.value); editor.sync(); editor.pushHistory(true); };
    bar.appendChild(formatSelect);
    editor.formatSelect = formatSelect;

    const fontSelect = document.createElement('select'); fontSelect.className = 'lumina-select'; fontSelect.style.width = '100px';
    fontSelect.innerHTML = `<option value="">Font</option>${editor.fonts.map(f => `<option value="${f}">${f}</option>`).join('')}`;
    fontSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontFamily: e.target.value }); editor.pushHistory(true); };
    bar.appendChild(fontSelect);
    editor.fontSelect = fontSelect;

    const sizeSelect = document.createElement('select'); sizeSelect.className = 'lumina-select'; sizeSelect.style.width = '60px';
    sizeSelect.innerHTML = `<option value="">Size</option>${['8','10','12','14','16','18','20','24','30','36','48','60','72','96'].map(s => `<option value="${s}px">${s}</option>`).join('')}`;
    sizeSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontSize: e.target.value }); editor.pushHistory(true); };
    bar.appendChild(sizeSelect);
    editor.sizeSelect = sizeSelect;
    
    if (isVisible('lineHeight')) { const lhSelect = document.createElement('select'); lhSelect.className = 'lumina-select'; lhSelect.style.width = '50px'; lhSelect.title = "Line Height"; lhSelect.innerHTML = `<option value="">LH</option>${['1.0','1.2','1.5','1.8','2.0','2.5'].map(v => `<option value="${v}">${v}</option>`).join('')}`; lhSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { lineHeight: e.target.value }); editor.pushHistory(true); }; bar.appendChild(lhSelect); }
    if (isVisible('letterSpacing')) { const lsSelect = document.createElement('select'); lsSelect.className = 'lumina-select'; lsSelect.style.width = '60px'; lsSelect.title = "Letter Spacing"; lsSelect.innerHTML = `<option value="">Spc</option>${['-1px','0px','0.5px','1px','2px','3px'].map(v => `<option value="${v}">${v}</option>`).join('')}`; lsSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { letterSpacing: e.target.value }); editor.pushHistory(true); }; editor.lsSelect = lsSelect; bar.appendChild(lsSelect); }
    if (isVisible('kerning')) { const kernSelect = document.createElement('select'); kernSelect.className = 'lumina-select'; kernSelect.style.width = '60px'; kernSelect.title = "Font Kerning"; kernSelect.innerHTML = `<option value="">Kern</option><option value="auto">Auto</option><option value="normal">Normal</option><option value="none">None</option>`; kernSelect.onchange = (e) => { editor.restoreSelection(); editor.editor.focus(); applyCustomStyle(editor, { fontKerning: e.target.value }); editor.pushHistory(true); }; editor.kernSelect = kernSelect; bar.appendChild(kernSelect); }
    
    bar.appendChild(createSep());
    if(groups[1]) renderGroup(editor, bar, groups[1].btns, isVisible);
    if(groups[2]) renderGroup(editor, bar, groups[2].btns, isVisible);
    
    let alignGroupVisible = false;
    if (isVisible('alignLeft')) { const alignLeft = createBtn(editor, 'align-left', t('alignLeft'), () => handleAlign(editor, 'left'), 'alignLeft'); alignLeft.dataset.cmd = 'justifyLeft'; bar.appendChild(alignLeft); alignGroupVisible = true; }
    if (isVisible('alignCenter')) { const alignCenter = createBtn(editor, 'align-center', t('alignCenter'), () => handleAlign(editor, 'center'), 'alignCenter'); alignCenter.dataset.cmd = 'justifyCenter'; bar.appendChild(alignCenter); alignGroupVisible = true; }
    if (isVisible('alignRight')) { const alignRight = createBtn(editor, 'align-right', t('alignRight'), () => handleAlign(editor, 'right'), 'alignRight'); alignRight.dataset.cmd = 'justifyRight'; bar.appendChild(alignRight); alignGroupVisible = true; }
    if (alignGroupVisible) { bar.appendChild(createSep()); }
    
    if(groups[3]) renderGroup(editor, bar, groups[3].btns, isVisible); 

    if (isVisible('textColor')) { const textColorBtn = document.createElement('button'); textColorBtn.className = 'lumina-btn'; textColorBtn.dataset.btnId = 'textColor'; textColorBtn.type = 'button'; textColorBtn.innerHTML = '<span style="border-bottom:3px solid currentColor; font-weight:bold; font-size:14px;">A</span>'; textColorBtn.title = t('textColor'); textColorBtn.onmousedown = (e) => { e.preventDefault(); toggleColorPicker(editor, 'color', textColorBtn); }; bar.appendChild(textColorBtn); }
    if (isVisible('highlightColor')) { const bgColorBtn = document.createElement('button'); bgColorBtn.className = 'lumina-btn'; bgColorBtn.dataset.btnId = 'highlightColor'; bgColorBtn.type = 'button'; bgColorBtn.innerHTML = getIcon('highlighter'); bgColorBtn.title = t('highlightColor'); bgColorBtn.onmousedown = (e) => { e.preventDefault(); toggleColorPicker(editor, 'backgroundColor', bgColorBtn); }; bar.appendChild(bgColorBtn); }
    bar.appendChild(createSep());
    
    if (editor.settings.modules.emoji && isVisible('emoji')) { const eb = createBtn(editor, 'smile', t('emoji'), () => editor.openEmojiModal(), 'emoji'); bar.appendChild(eb); }
    if (isVisible('specialChars')) { const charBtn = createBtn(editor, 'omega', t('specialChars'), () => editor.openSpecialCharModal(), 'specialChars'); bar.appendChild(charBtn); }
    if (isVisible('tooltip')) { const tooltipBtn = createBtn(editor, 'message-square-plus', t('tooltip'), () => editor.openTooltipModal(), 'tooltip'); bar.appendChild(tooltipBtn); }
    if (editor.settings.modules.blocks && isVisible('blocks')) { const bb = createBtn(editor, 'layout', t('blockTemplates'), () => editor.openBlockLibrary(), 'blocks'); bar.appendChild(bb); }
    if (isVisible('createButton')) { const btnCreator = createBtn(editor, 'mouse-pointer', t('createButton'), () => editor.openButtonCreatorModal(), 'createButton'); bar.appendChild(btnCreator); }
    if (isVisible('toggleButton')) { const toggleBtn = createBtn(editor, 'toggle-readmore', t('toggleButton'), () => editor.insertToggleButton(), 'toggleButton'); bar.appendChild(toggleBtn); }
    if (isVisible('insertModule')) { const modBtn = createBtn(editor, 'modules', t('insertModule'), () => editor.openModulePicker(), 'insertModule'); bar.appendChild(modBtn); }
    
    if(groups[4]) renderGroup(editor, bar, groups[4].btns, isVisible);

    if (editor.settings.modules.code && isVisible('sourceCode')) { const cb = createBtn(editor, 'code', t('sourceCode'), () => editor.toggleSource(), 'sourceCode'); bar.appendChild(cb); }
    
    const spacer = document.createElement('div'); spacer.style.flex = '1'; bar.appendChild(spacer);
    
    if (editor.settings.modules.ai) { const aiBtn = document.createElement('button'); aiBtn.type = 'button'; aiBtn.className = 'lumina-btn lumina-btn-ai'; aiBtn.title = t('aiGenerator'); aiBtn.innerHTML = `✨ AI`; aiBtn.onclick = () => editor.openAiModal(); bar.appendChild(aiBtn); }
    if (isVisible('preview')) { const prevBtn = createBtn(editor, 'monitor', t('preview'), () => editor.togglePreview(), 'preview'); bar.appendChild(prevBtn); }
    if (isVisible('fullscreen')) { const fsBtn = createBtn(editor, 'maximize', t('fullscreen'), () => editor.toggleFullscreen(), 'fullscreen'); bar.appendChild(fsBtn); }
    if (isVisible('settings')) { const setBtn = createBtn(editor, 'settings', t('settings'), () => editor.openSettingsModal(), 'settings'); bar.appendChild(setBtn); }
    
    editor.toolbarContainer.appendChild(bar);
}

export function updateToolbarState(editor) {
    if (!editor.toolbarContainer) return;
    
    // Helper to toggle active class
    const toggle = (id, condition) => {
        const btn = editor.toolbarContainer.querySelector(`button[data-btn-id="${id}"]`);
        if (btn) {
            if (condition) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    };

    // 1. Browser Command States (Bold, Italic, etc.)
    ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript'].forEach(cmd => {
        const state = document.queryCommandState(cmd);
        // Map 'strike' to 'strikeThrough' command manually if needed, but here IDs mostly match commands or mapped in render
        const btnCmd = cmd === 'strike' ? 'strikeThrough' : cmd;
        const btn = editor.toolbarContainer.querySelector(`button[data-cmd="${btnCmd}"]`);
        if (btn) { 
            if (state) btn.classList.add('active'); 
            else btn.classList.remove('active'); 
        }
    });

    // 2. Editor Modes
    toggle('spellCheck', editor.editor.spellcheck);
    toggle('sourceCode', editor.isSourceMode);
    toggle('preview', editor.isPreviewMode);
    toggle('fullscreen', editor.isFullscreen);

    // 3. Selection Context Highlighting
    const sel = window.getSelection();
    let parent = null;
    let node = null;
    if (sel.rangeCount > 0) {
        node = sel.anchorNode;
        parent = node.nodeType === 3 ? node.parentElement : node;
    }

    if (parent) {
        toggle('quote', !!parent.closest('blockquote'));
        toggle('codeBlock', !!parent.closest('pre') || !!parent.closest('.lumina-code-wrapper') || !!editor.selectedCodeBlock);
        toggle('checklist', !!parent.closest('.lumina-todo-item'));
        toggle('link', !!parent.closest('a'));
        
        // Corrected Anchor Logic: check if ID is present AND it's not the editor root AND contained in editor
        const anchorEl = parent.closest('[id]');
        const validAnchor = anchorEl && anchorEl.id && anchorEl !== editor.editor && editor.editor.contains(anchorEl);
        toggle('anchor', !!validAnchor);
        
        toggle('table', !!parent.closest('table') || !!editor.selectedCell);
        toggle('box', !!parent.closest('.lumina-free-box') || !!editor.selectedBox);
        
        // Sections / Layout Blocks
        toggle('section', !!parent.closest('.lumina-layout-block') || !!editor.selectedBlock);
        
        // Media
        const isImage = node.nodeName === 'IMG' || !!parent.closest('.lumina-img-selected');
        const isVideo = parent.closest('.lumina-resizable-media') || (isImage && editor.selectedImage && editor.selectedImage.classList.contains('lumina-resizable-media'));
        
        toggle('image', isImage && !isVideo);
        toggle('video', !!isVideo);
    } else {
        // Fallback checks if selection is collapsed but editor variables are set
        toggle('image', !!editor.selectedImage);
        toggle('box', !!editor.selectedBox);
        toggle('section', !!editor.selectedBlock);
        toggle('codeBlock', !!editor.selectedCodeBlock);
        toggle('table', !!editor.selectedCell);
    }

    // 4. Alignment Buttons
    const leftBtn = editor.toolbarContainer.querySelector(`button[data-cmd="justifyLeft"]`);
    const centerBtn = editor.toolbarContainer.querySelector(`button[data-cmd="justifyCenter"]`);
    const rightBtn = editor.toolbarContainer.querySelector(`button[data-cmd="justifyRight"]`);
    
    if(leftBtn) leftBtn.classList.remove('active');
    if(centerBtn) centerBtn.classList.remove('active');
    if(rightBtn) rightBtn.classList.remove('active');

    // Check specific selected elements first
    if (editor.selectedBlock) {
         const s = editor.selectedBlock.style;
         if (s.marginLeft === 'auto' && s.marginRight === 'auto') centerBtn?.classList.add('active');
         else if (s.marginLeft === 'auto' && (s.marginRight === '0px' || s.marginRight === '0')) rightBtn?.classList.add('active');
         else leftBtn?.classList.add('active');
    } else if (editor.selectedCodeBlock) {
         const s = editor.selectedCodeBlock.style;
         if (s.marginLeft === 'auto' && s.marginRight === 'auto') centerBtn?.classList.add('active');
         else if (s.marginLeft === 'auto' && (s.marginRight === '0px' || s.marginRight === '0')) rightBtn?.classList.add('active');
         else leftBtn?.classList.add('active');
    } else if (editor.selectedImage) {
         const s = editor.selectedImage.style;
         if (s.display === 'block' && s.marginLeft === 'auto') centerBtn?.classList.add('active');
         else if (s.float === 'right') rightBtn?.classList.add('active');
         else leftBtn?.classList.add('active');
    } else {
        // Fallback to doc state
        if (document.queryCommandState('justifyCenter')) centerBtn?.classList.add('active');
        else if (document.queryCommandState('justifyRight')) rightBtn?.classList.add('active');
        else if (document.queryCommandState('justifyLeft')) leftBtn?.classList.add('active');
    }
    
    updateBubbleState(editor);
}
