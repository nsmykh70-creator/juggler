import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';
import { showQuickInsert, hideQuickInsert } from './quickInsert.js?v=20260901';

const rgbToHex = (col) => {
    if (!col || col === 'rgba(0, 0, 0, 0)' || col === 'transparent') return '#ffffff';
    if (col.startsWith('#')) return col;
    const rgb = col.match(/\d+/g);
    if (!rgb) return '#000000';
    return `#${((1 << 24) + (parseInt(rgb[0]) << 16) + (parseInt(rgb[1]) << 8) + parseInt(rgb[2])).toString(16).slice(1)}`;
};

export function initBlockToolbar(editor) {
    editor.blockToolbar = document.createElement('div');
    editor.blockToolbar.className = 'lumina-block-toolbar';
    editor.blockToolbar.style.display = 'none';

    // Width Buttons
    const createBtn = (label, width) => {
        const button = document.createElement('button');
        button.innerText = label;
        button.className = 'lumina-btn';
        button.type = 'button';
        button.onclick = (e) => {
            e.stopPropagation();
            if (editor.selectedBlock) {
                editor.selectedBlock.style.width = width;
                editor.selectedBlock.style.maxWidth = width;
                if (width !== '100%') {
                    if (!editor.selectedBlock.style.marginLeft) editor.selectedBlock.style.marginLeft = 'auto';
                    if (!editor.selectedBlock.style.marginRight) editor.selectedBlock.style.marginRight = 'auto';
                }
                editor.sync();
                editor.repositionBlockToolbar();
                editor.updateToolbarState();
            }
        };
        return button;
    };

    const widthRow = document.createElement('div');
    widthRow.className = 'lbt-row-actions';
    ['100%', '75%', '50%', '25%'].forEach((label) => {
        widthRow.appendChild(createBtn(label, label));
    });
    
    // Align Buttons
    const alignRow = document.createElement('div');
    alignRow.className = 'lbt-row-actions';
    const createAlignBtn = (icon, mode) => {
        const button = document.createElement('button');
        button.innerHTML = editor.getIcon(icon);
        button.title = `Align ${mode}`;
        button.className = 'lumina-btn';
        button.type = 'button';
        button.dataset.mode = mode;
        button.onclick = (e) => {
            e.stopPropagation();
            if (!editor.selectedBlock) return;
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
            editor.sync();
            editor.repositionBlockToolbar();
            editor.updateToolbarState();
        };
        return button;
    };
    alignRow.appendChild(createAlignBtn('align-left', 'left'));
    alignRow.appendChild(createAlignBtn('align-center', 'center'));
    alignRow.appendChild(createAlignBtn('align-right', 'right'));

    // Action Buttons
    const actionRow = document.createElement('div');
    actionRow.className = 'lbt-row-actions';
    
    // Drag button removed as requested

    const upButton = document.createElement('button');
    upButton.innerHTML = editor.getIcon('arrow-up');
    upButton.className = 'lumina-btn';
    upButton.onclick = (e) => { e.stopPropagation(); if (!editor.selectedBlock) return; const p = editor.selectedBlock.previousElementSibling; if(p) p.before(editor.selectedBlock); editor.sync(); editor.repositionBlockToolbar(); };

    const downButton = document.createElement('button');
    downButton.innerHTML = editor.getIcon('arrow-down');
    downButton.className = 'lumina-btn';
    downButton.onclick = (e) => { e.stopPropagation(); if (!editor.selectedBlock) return; const n = editor.selectedBlock.nextElementSibling; if(n) n.after(editor.selectedBlock); editor.sync(); editor.repositionBlockToolbar(); };

    const tooltipBtn = document.createElement('button');
    tooltipBtn.innerHTML = editor.getIcon('message-square-plus');
    tooltipBtn.className = 'lumina-btn';
    tooltipBtn.onclick = (e) => { e.stopPropagation(); if (!editor.selectedBlock) return; editor.openTooltipModal(editor.selectedBlock); };

    const settingsBtn = document.createElement('button');
    settingsBtn.innerHTML = editor.getIcon('settings');
    settingsBtn.className = 'lumina-btn';
    settingsBtn.onclick = (e) => { e.stopPropagation(); editor.toggleBlockSettings(); };

    const deleteBtn = document.createElement('button');
    deleteBtn.innerHTML = editor.getIcon('trash');
    deleteBtn.className = 'lumina-btn';
    deleteBtn.style.color = 'red';
    deleteBtn.onclick = (e) => { e.stopPropagation(); if (!editor.selectedBlock) return; editor.selectedBlock.remove(); editor.selectedBlock = null; editor.blockToolbar.style.display = 'none'; if (editor.blockSettings) editor.blockSettings.style.display = 'none'; hideQuickInsert(editor); editor.sync(); };

    // Removed dragBtn from appendChild list
    actionRow.appendChild(upButton); actionRow.appendChild(downButton); actionRow.appendChild(tooltipBtn); actionRow.appendChild(settingsBtn); actionRow.appendChild(deleteBtn);

    editor.blockToolbar.appendChild(widthRow);
    editor.blockToolbar.appendChild(document.createElement('div')).className = 'lumina-sep-hor';
    editor.blockToolbar.appendChild(alignRow);
    editor.blockToolbar.appendChild(document.createElement('div')).className = 'lumina-sep-hor';
    editor.blockToolbar.appendChild(actionRow);

    document.body.appendChild(editor.blockToolbar);
    editor.initBlockSettings();

    editor.editor.addEventListener('click', (event) => {
        if (event.target.closest('.lumina-action-btn') || event.target.tagName === 'IMG') return;
        
        // Detect Column Logic
        const col = event.target.closest('.grid > div, .flex > div');
        if (col) {
             const parent = col.parentElement;
             if (parent) {
                 const style = window.getComputedStyle(parent);
                 const isGrid = style.display === 'grid';
                 const isFlexRow = style.display === 'flex' && style.flexDirection === 'row';
                 if (isGrid || isFlexRow) {
                     const blockWrapper = event.target.closest('.lumina-layout-block');
                     if (blockWrapper && blockWrapper.contains(col)) {
                         if (editor.selectedBlock) {
                             editor.selectedBlock.classList.remove('lumina-active-block');
                             editor.selectedBlock = null;
                             editor.blockToolbar.style.display = 'none';
                             if(editor.blockSettings) editor.blockSettings.style.display = 'none';
                         }
                         return; // Handled by columnToolbar
                     }
                 }
             }
        }

        const block = event.target.closest('.lumina-layout-block');
        if (block) {
            editor.selectedBlock = block;
            editor.editor.querySelectorAll('.lumina-layout-block').forEach((b) => b.classList.remove('lumina-active-block'));
            block.classList.add('lumina-active-block');
            editor.repositionBlockToolbar();
            editor.checkMapSelection();
            editor.updateToolbarState();
        } else {
            editor.selectedBlock = null;
            editor.editor.querySelectorAll('.lumina-layout-block').forEach((b) => b.classList.remove('lumina-active-block'));
            editor.blockToolbar.style.display = 'none';
            if (editor.blockSettings) editor.blockSettings.style.display = 'none';
            if (editor.mapToolbar) editor.mapToolbar.style.display = 'none';
            editor.updateToolbarState();
        }
    });
}

function updateToolbarButtons(editor) {
    if (!editor.selectedBlock || !editor.blockToolbar) return;
    const s = editor.selectedBlock.style;
    const btns = editor.blockToolbar.querySelectorAll('button[data-mode]');
    btns.forEach(btn => btn.classList.remove('active'));
    if (s.marginLeft === 'auto' && s.marginRight === 'auto') { const btn = editor.blockToolbar.querySelector('button[data-mode="center"]'); if(btn) btn.classList.add('active'); }
    else if (s.marginLeft === 'auto' && (s.marginRight === '0px' || s.marginRight === '0')) { const btn = editor.blockToolbar.querySelector('button[data-mode="right"]'); if(btn) btn.classList.add('active'); }
    else { const btn = editor.blockToolbar.querySelector('button[data-mode="left"]'); if(btn) btn.classList.add('active'); }
}

export function initBlockSettings(editor) {
    editor.blockSettings = document.createElement('div');
    editor.blockSettings.className = 'lumina-bubble-settings lumina-block-settings';
    editor.blockSettings.style.display = 'none';
    editor.blockSettings.style.width = '280px';
    const createRange = (label, cls, min, max, unit) => `<div class="lbs-row"><label>${label}</label><input type="range" class="${cls}" min="${min}" max="${max}" style="flex:1;margin:0 8px;"><span class="${cls}-val" style="font-size:10px;width:25px;text-align:right;"></span></div>`;
    editor.blockSettings.innerHTML = `<div class="lbs-header">Block Style</div><div class="lbs-row"><label>Background</label><div style="display:flex; gap:5px;"><input type="color" class="lbs-bg-color" style="width:24px;height:24px;border:none;cursor:pointer;"><button class="lumina-btn lbs-bg-clear" style="font-size:10px;padding:0 5px;">Clear</button></div></div><div class="lbs-row"><label>Text Color</label><input type="color" class="lbs-text-color" style="width:24px;height:24px;border:none;cursor:pointer;"></div><div class="lbs-row"><label>Font</label><select class="lbs-font lumina-select" style="width:120px;"><option value="">Inherit</option>${editor.fonts.map((f) => `<option value="${f}">${f}</option>`).join('')}</select></div><div class="lbs-grp"><span>Border</span><div class="lbs-row"><input type="number" class="lbs-border-width" value="0" style="width:40px"> px <select class="lbs-border-style lumina-select" style="width:70px"><option value="solid">Solid</option><option value="dashed">Dashed</option><option value="dotted">Dotted</option></select><input type="color" class="lbs-border-color" value="#e2e8f0" style="width:24px;height:24px;border:none;cursor:pointer;"></div></div>${createRange('Padding', 'lbs-padding', 0, 100, 'px')}${createRange('Margin Y', 'lbs-margin', 0, 100, 'px')}${createRange('Radius', 'lbs-radius', 0, 50, 'px')}${createRange('Font Size', 'lbs-fontsize', 10, 96, 'px')}<div class="lbs-row"><label>Shadow</label><select class="lbs-shadow lumina-select" style="width:120px;"><option value="none">None</option><option value="0 1px 2px 0 rgba(0, 0, 0, 0.05)">Small</option><option value="0 4px 6px -1px rgba(0, 0, 0, 0.1)">Medium</option><option value="0 10px 15px -3px rgba(0, 0, 0, 0.1)">Large</option><option value="0 20px 25px -5px rgba(0, 0, 0, 0.1)">X-Large</option></select></div><div class="lbs-grp" style="margin-top:10px; border-top:1px solid #eee; padding-top:10px;"><span>Appearance Animation</span><div class="lbs-row"><label>Anim</label><div style="display:flex; flex:1; gap:4px; align-items:center;"><select class="lbs-anim lumina-select" style="flex:1;"><option value="none">None</option><option value="lumina-anim-fade">Fade In</option><option value="lumina-anim-slide-left">Slide from Left</option><option value="lumina-anim-slide-right">Slide from Right</option><option value="lumina-anim-slide">Slide Up</option><option value="lumina-anim-slide-down">Slide Down</option><option value="lumina-anim-zoom-in">Zoom In</option><option value="lumina-anim-flip">Flip</option><option value="lumina-anim-bounce">Bounce</option><option value="lumina-anim-pulse">Pulse</option><option value="lumina-anim-glow">Glow</option></select><button type="button" class="lumina-btn lbs-anim-play" title="Preview Animation" style="width:30px; color:black;">▶</button></div></div><div class="lbs-row"><label>Speed</label><input type="range" class="lbs-anim-speed" min="0.3" max="5.0" step="0.1" value="0.8" style="flex:1;margin:0 8px;"><span class="lbs-anim-speed-val" style="font-size:10px;width:30px;text-align:right;">0.8s</span></div><div class="lbs-row"><label>Loop</label><div style="display:flex; align-items:center; gap:8px; flex:1;"><input type="checkbox" class="lbs-anim-loop" style="width:16px; height:16px; cursor:pointer; accent-color:#2563eb;"><span style="font-size:11px; color:#666;">Repeat forever</span></div></div></div>`;

    editor.blockToolbar.appendChild(editor.blockSettings);

    // Known animation classes — used to clear previous animation when switching.
    const BLOCK_ANIM_CLASSES = [
        'lumina-anim-fade', 'lumina-anim-slide', 'lumina-anim-pulse', 'lumina-anim-spin',
        'lumina-anim-slide-left', 'lumina-anim-slide-right', 'lumina-anim-slide-down',
        'lumina-anim-zoom-in', 'lumina-anim-zoom-out', 'lumina-anim-flip',
        'lumina-anim-bounce', 'lumina-anim-shake', 'lumina-anim-glow'
    ];

    // Bindings
    const bindRange = (cls, prop, unit = 'px') => {
        const el = editor.blockSettings.querySelector(`.${cls}`);
        const valEl = editor.blockSettings.querySelector(`.${cls}-val`);
        el.oninput = (e) => {
            if (!editor.selectedBlock) return;
            const value = e.target.value;
            valEl.innerText = value + unit;
            if (prop === 'margin') { editor.selectedBlock.style.marginTop = value + unit; editor.selectedBlock.style.marginBottom = value + unit; }
            else if (prop === 'fontSize') { editor.selectedBlock.style[prop] = value + unit; editor.selectedBlock.querySelectorAll('h1,h2,h3,h4,h5,p').forEach((el2) => el2.style.fontSize = ''); }
            else { editor.selectedBlock.style[prop] = value + unit; }
            editor.sync();
        };
    };
    bindRange('lbs-padding', 'padding'); bindRange('lbs-margin', 'margin'); bindRange('lbs-radius', 'borderRadius'); bindRange('lbs-fontsize', 'fontSize');
    const updateBorder = () => { if (!editor.selectedBlock) return; const width = editor.blockSettings.querySelector('.lbs-border-width').value; const style = editor.blockSettings.querySelector('.lbs-border-style').value; const color = editor.blockSettings.querySelector('.lbs-border-color').value; editor.selectedBlock.style.border = `${width}px ${style} ${color}`; editor.sync(); };
    editor.blockSettings.querySelector('.lbs-border-width').oninput = updateBorder; editor.blockSettings.querySelector('.lbs-border-style').onchange = updateBorder; editor.blockSettings.querySelector('.lbs-border-color').oninput = updateBorder;
    editor.blockSettings.querySelector('.lbs-shadow').onchange = (e) => { if (!editor.selectedBlock) return; editor.selectedBlock.style.boxShadow = e.target.value; editor.sync(); };
    editor.blockSettings.querySelector('.lbs-bg-color').oninput = (e) => { if (!editor.selectedBlock) return; editor.selectedBlock.style.backgroundColor = e.target.value; editor.selectedBlock.querySelectorAll('.bg-gray-100, .bg-white, .bg-slate-50').forEach((el) => { el.style.backgroundColor = 'transparent'; }); editor.sync(); };
    editor.blockSettings.querySelector('.lbs-bg-clear').onclick = () => { if (!editor.selectedBlock) return; editor.selectedBlock.style.backgroundColor = 'transparent'; editor.sync(); };
    editor.blockSettings.querySelector('.lbs-text-color').oninput = (e) => { if (!editor.selectedBlock) return; editor.selectedBlock.style.color = e.target.value; editor.selectedBlock.querySelectorAll('*').forEach((el) => el.style.color = 'inherit'); editor.sync(); };
    editor.blockSettings.querySelector('.lbs-font').onchange = (e) => { if (!editor.selectedBlock) return; editor.selectedBlock.style.fontFamily = e.target.value; editor.sync(); };

    // Animation bindings — apply the chosen animation class to the selected
    // block and persist the duration via inline style.animationDuration.
    const applyAnim = () => {
        if (!editor.selectedBlock) return;
        const anim = editor.blockSettings.querySelector('.lbs-anim').value;
        const speed = editor.blockSettings.querySelector('.lbs-anim-speed').value;
        const loop = editor.blockSettings.querySelector('.lbs-anim-loop').checked;
        // Remove any previously-applied animation classes.
        BLOCK_ANIM_CLASSES.forEach((c) => editor.selectedBlock.classList.remove(c));
        editor.selectedBlock.classList.remove('lumina-anim-loop');
        if (anim !== 'none') {
            editor.selectedBlock.classList.add(anim);
            editor.selectedBlock.style.animationDuration = speed + 's';
            if (loop) editor.selectedBlock.classList.add('lumina-anim-loop');
        } else {
            editor.selectedBlock.style.animationDuration = '';
        }
        editor.blockSettings.querySelector('.lbs-anim-speed-val').innerText = speed + 's';
        editor.sync();
    };
    editor.blockSettings.querySelector('.lbs-anim').onchange = applyAnim;
    editor.blockSettings.querySelector('.lbs-anim-speed').oninput = applyAnim;
    editor.blockSettings.querySelector('.lbs-anim-loop').onchange = applyAnim;
    // Preview button — replay the animation so the user can see how it looks.
    editor.blockSettings.querySelector('.lbs-anim-play').onclick = () => {
        if (!editor.selectedBlock) return;
        const anim = editor.blockSettings.querySelector('.lbs-anim').value;
        if (anim === 'none') return;
        const loop = editor.blockSettings.querySelector('.lbs-anim-loop').checked;
        // Force a reflow so the animation restarts.
        editor.selectedBlock.classList.remove(anim, 'lumina-anim-loop');
        void editor.selectedBlock.offsetWidth;
        editor.selectedBlock.classList.add(anim);
        if (loop) editor.selectedBlock.classList.add('lumina-anim-loop');
    };
}

export function toggleBlockSettings(editor) {
    if (!editor.blockSettings || !editor.selectedBlock) return;
    if (editor.blockSettings.style.display === 'none') {
        editor.blockSettings.style.display = 'block';
        const style = editor.selectedBlock.style;
        const computed = window.getComputedStyle(editor.selectedBlock);
        const setVal = (cls, val, suffix = 'px') => { const num = parseInt(val) || 0; editor.blockSettings.querySelector(`.${cls}`).value = num; editor.blockSettings.querySelector(`.${cls}-val`).innerText = num + suffix; };
        setVal('lbs-padding', style.padding || computed.padding); setVal('lbs-margin', style.marginTop || computed.marginTop); setVal('lbs-radius', style.borderRadius || computed.borderRadius); setVal('lbs-fontsize', style.fontSize || computed.fontSize);
        editor.blockSettings.querySelector('.lbs-bg-color').value = rgbToHex(style.backgroundColor || computed.backgroundColor);
        editor.blockSettings.querySelector('.lbs-text-color').value = rgbToHex(style.color || computed.color);
        editor.blockSettings.querySelector('.lbs-font').value = style.fontFamily.replace(/['"]/g, '') || '';
        editor.blockSettings.querySelector('.lbs-border-width').value = parseInt(style.borderWidth) || 0;
        editor.blockSettings.querySelector('.lbs-border-style').value = style.borderStyle || 'solid';
        editor.blockSettings.querySelector('.lbs-border-color').value = rgbToHex(style.borderColor || computed.borderColor);
        editor.blockSettings.querySelector('.lbs-shadow').value = style.boxShadow && style.boxShadow !== 'none' ? style.boxShadow : 'none';

        // Sync the animation dropdown with the block's current animation class.
        const knownAnims = ['lumina-anim-fade','lumina-anim-slide','lumina-anim-pulse','lumina-anim-spin','lumina-anim-slide-left','lumina-anim-slide-right','lumina-anim-slide-down','lumina-anim-zoom-in','lumina-anim-zoom-out','lumina-anim-flip','lumina-anim-bounce','lumina-anim-shake','lumina-anim-glow'];
        let currentAnim = 'none';
        for (const c of knownAnims) { if (editor.selectedBlock.classList.contains(c)) { currentAnim = c; break; } }
        editor.blockSettings.querySelector('.lbs-anim').value = currentAnim;
        const animDur = style.animationDuration ? parseFloat(style.animationDuration) : 0.8;
        editor.blockSettings.querySelector('.lbs-anim-speed').value = animDur;
        editor.blockSettings.querySelector('.lbs-anim-speed-val').innerText = animDur + 's';
        // Sync the Loop checkbox with the block's current loop state.
        editor.blockSettings.querySelector('.lbs-anim-loop').checked = editor.selectedBlock.classList.contains('lumina-anim-loop');

        repositionBlockToolbar(editor);
    } else {
        editor.blockSettings.style.display = 'none';
        repositionBlockToolbar(editor); // Re-calc quick insert position
    }
}

export function repositionBlockToolbar(editor) {
    if (!editor.selectedBlock || !editor.blockToolbar) return;
    const rect = editor.selectedBlock.getBoundingClientRect();
    
    // Check Settings
    if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.block !== false) {
        editor.blockToolbar.style.display = 'flex';
        smartPosition(editor, editor.blockToolbar, rect, 'top', collectVisiblePanels(editor, editor.blockToolbar));
        updateToolbarButtons(editor);
        showQuickInsert(editor, editor.selectedBlock);
    } else {
        editor.blockToolbar.style.display = 'none';
        // Even if block toolbar is hidden, quick insert might be desired? 
        // Logic in quickInsert will handle its own visibility check
        showQuickInsert(editor, editor.selectedBlock);
    }
}
