import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';
import { showQuickInsert, hideQuickInsert } from './quickInsert.js?v=20260901';

export function initBoxToolbar(editor) {
    editor.boxToolbar = document.createElement('div');
    editor.boxToolbar.className = 'lumina-box-toolbar lumina-image-toolbar'; 
    editor.boxToolbar.style.display = 'none';

    const buttons = [
        { id: 'settings', icon: 'settings', title: 'Box Settings' },
        { id: 'trash', icon: 'trash', title: 'Delete Box' }
    ];

    buttons.forEach(btn => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumina-btn';
        button.innerHTML = editor.getIcon(btn.icon);
        button.title = btn.title;
        
        button.onmousedown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            if(btn.id === 'trash') {
                if (editor.selectedBox) {
                    editor.selectedBox.remove();
                    deselectBox(editor);
                    hideQuickInsert(editor);
                    editor.sync();
                }
            } else if (btn.id === 'settings') {
                toggleBoxSettings(editor);
            }
        };
        editor.boxToolbar.appendChild(button);
    });

    document.body.appendChild(editor.boxToolbar);
    initBoxSettings(editor);

    document.addEventListener('mousedown', (e) => {
        if (!editor.selectedBox) return;
        const target = e.target;
        const isBox = target.closest('.lumina-free-box') === editor.selectedBox;
        const isToolbar = target.closest('.lumina-box-toolbar');
        const isSettings = target.closest('.lumina-bubble-settings');
        const isResizer = target.closest('.lumina-resizer');
        const isQuickInsert = target.closest('.lumina-quick-insert');

        if (!isBox && !isToolbar && !isSettings && !isResizer && !isQuickInsert) {
            deselectBox(editor);
        }
    });
}

// Function to re-attach events after Undo/Redo operations
export function rebindBoxEvents(editor) {
    const boxes = editor.editor.querySelectorAll('.lumina-free-box');
    boxes.forEach(box => {
        initBoxDrag(editor, box);
    });
}

export function insertBox(editor) {
    const boxId = 'box-' + Date.now();
    
    // Default position (fallback)
    let top = 30;
    let left = 30;
    
    // Calculate position based on cursor
    const sel = window.getSelection();
    // Check if selection exists and is effectively inside the editor (or nested elements)
    if (sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) {
        const range = sel.getRangeAt(0);
        const rect = range.getBoundingClientRect();
        const editorRect = editor.editor.getBoundingClientRect();
        
        // Ensure we have valid coordinates (sometimes hidden cursors return 0)
        if (rect.top !== 0 || rect.left !== 0) {
            // Calculate coordinates relative to the editor container
            // (Viewport Item Pos - Viewport Container Pos) + Scroll Offset
            top = (rect.top - editorRect.top) + editor.editor.scrollTop;
            left = (rect.left - editorRect.left) + editor.editor.scrollLeft;
        }
    }
    
    // Boundary checks to ensure it doesn't spawn off-canvas
    if (top < 0) top = 30;
    if (left < 0) left = 30;
    
    // Prevent spawning too far to the right
    const maxLeft = editor.editor.clientWidth - 220;
    if (left > maxLeft) left = Math.max(30, maxLeft);
    
    const html = `
        <div class="lumina-free-box" id="${boxId}" contenteditable="false" style="position: absolute; top: ${top}px; left: ${left}px; width: 200px; height: 150px; background-color: #ffffff; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 100; border-radius: 8px; max-width: 90%;">
            <div class="lumina-box-drag-handle" title="Drag to move">
                ${editor.getIcon('move')}
            </div>
            <div class="lumina-box-mask" style="position:absolute; top:0; left:0; width:100%; height:100%; z-index:0; pointer-events: none;"></div>
            <div class="lumina-box-content" contenteditable="true" style="width:100%; height:100%; padding:10px; position:relative; z-index:1; outline:none; background:transparent;">
                <p>New Box</p>
            </div>
        </div>
    `;
    
    const temp = document.createElement('div');
    temp.innerHTML = html;
    const box = temp.firstElementChild;
    editor.editor.appendChild(box);
    reanchorBox(editor, box);
    editor.sync();
    initBoxDrag(editor, box);
    setTimeout(() => { selectBox(editor, box); }, 50);
}

export function selectBox(editor, box) {
    if (editor.selectedBox && editor.selectedBox !== box) {
        deselectBox(editor);
    }
    editor.selectedBox = box;
    box.classList.add('lumina-box-selected'); 
    
    const handle = box.querySelector('.lumina-box-drag-handle');
    if(handle) handle.style.display = 'flex';

    repositionBoxToolbar(editor);
    
    showQuickInsert(editor, box); 
    
    createBoxResizers(editor, box);
    initBoxDrag(editor, box);

    if (editor.boxSettings && editor.boxSettings.style.display !== 'none') {
        updateBoxSettingsUI(editor);
        repositionSettings(editor);
    }
}

export function deselectBox(editor) {
    if (editor.selectedBox) {
        editor.selectedBox.classList.remove('lumina-box-selected');
        const handle = editor.selectedBox.querySelector('.lumina-box-drag-handle');
        if(handle) handle.style.display = 'none';
    }
    editor.selectedBox = null;
    if (editor.boxToolbar) editor.boxToolbar.style.display = 'none';
    if (editor.boxSettings) editor.boxSettings.style.display = 'none';
    removeBoxResizers(editor);
    
    hideQuickInsert(editor);
}

function reanchorBox(editor, box) {
    const boxRect = box.getBoundingClientRect();
    const boxMidY = boxRect.top + (boxRect.height / 2);
    const children = Array.from(editor.editor.children).filter(el => 
        el !== box && !el.classList.contains('lumina-free-box') && !el.classList.contains('lumina-resizer') &&
        el.style.display !== 'none' && el.tagName !== 'STYLE' && el.tagName !== 'SCRIPT'
    );
    if (children.length === 0) return;
    let anchor = null;
    for (const child of children) {
        const childRect = child.getBoundingClientRect();
        const childMidY = childRect.top + (childRect.height / 2);
        if (childMidY < boxMidY) {
            anchor = child;
        } else {
            break; 
        }
    }
    if (anchor) {
        if (anchor.nextElementSibling !== box) { anchor.after(box); }
    } else {
        if (editor.editor.firstElementChild !== box) { editor.editor.prepend(box); }
    }
}

function initBoxDrag(editor, box) {
    // Clear existing handler to prevent duplication if re-binding
    box.onmousedown = null; 
    
    box.onmousedown = (e) => {
        if (e.target.closest('.lumina-resizer')) return;
        const isHandle = e.target.closest('.lumina-box-drag-handle');
        if (!isHandle && e.target.isContentEditable) {
            if(editor.selectedBox !== box) { selectBox(editor, box); }
            return;
        }
        e.preventDefault(); e.stopPropagation();
        if(editor.selectedBox !== box) selectBox(editor, box);
        editor.dragData = { startX: e.clientX, startY: e.clientY, startLeft: box.offsetLeft, startTop: box.offsetTop, containerW: editor.editor.clientWidth, containerH: editor.editor.scrollHeight };
        const onMove = (ev) => {
            if (!editor.dragData) return;
            const dx = ev.clientX - editor.dragData.startX;
            const dy = ev.clientY - editor.dragData.startY;
            let newLeft = editor.dragData.startLeft + dx;
            let newTop = editor.dragData.startTop + dy;
            const maxLeft = editor.editor.clientWidth - box.offsetWidth;
            if (newLeft < 0) newLeft = 0; if (newLeft > maxLeft) newLeft = maxLeft; if (newTop < 0) newTop = 0;
            box.style.left = newLeft + 'px'; box.style.top = newTop + 'px';
            repositionBoxToolbar(editor);
            const rect = box.getBoundingClientRect();
            if(editor.boxResizers) { editor.boxResizers.forEach(r => { const h = r.className.split(' ')[1]; updateBoxResizerPos(editor, r, h, rect); }); }
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp);
            editor.dragData = null;
            reanchorBox(editor, box);
            editor.sync();
        };
        document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
    };
}

export function repositionBoxToolbar(editor) {
    if(!editor.selectedBox || !editor.boxToolbar) return;
    const rect = editor.selectedBox.getBoundingClientRect();
    
    // Check Settings
    if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.box !== false) {
        editor.boxToolbar.style.display = 'flex';
        // Use new preference 'top-sides' to prefer Top/Right/Left, never Bottom
        smartPosition(editor, editor.boxToolbar, rect, 'top-sides', collectVisiblePanels(editor, editor.boxToolbar));
    } else {
        editor.boxToolbar.style.display = 'none';
    }

    if(editor.boxResizers) { editor.boxResizers.forEach(r => { const h = r.className.split(' ')[1]; updateBoxResizerPos(editor, r, h, rect); }); }
    
    // Quick Insert Logic checks its own settings
    if(editor.quickInsert && editor.quickInsert.style.display !== 'none') { smartPosition(editor, editor.quickInsert, rect, 'bottom'); }
    
    if(editor.boxSettings && editor.boxSettings.style.display === 'block') { repositionSettings(editor); }
}

function repositionSettings(editor) {
    if (!editor.boxSettings || !editor.selectedBox) return;
    if(editor.boxSettings.style.display === 'none') return;
    if (document.activeElement && editor.boxSettings.contains(document.activeElement) && document.activeElement.type === 'range') return;
    const boxRect = editor.selectedBox.getBoundingClientRect();
    smartPosition(editor, editor.boxSettings, boxRect, 'top', collectVisiblePanels(editor, editor.boxSettings));
}

function createBoxResizers(editor, box) {
    removeBoxResizers(editor);
    const rect = box.getBoundingClientRect();
    const handles = ['nw', 'ne', 'sw', 'se'];
    editor.boxResizers = handles.map((handle) => {
        const el = document.createElement('div');
        el.className = `lumina-resizer ${handle}`;
        el.onmousedown = (event) => initBoxResize(editor, event, handle);
        document.body.appendChild(el);
        updateBoxResizerPos(editor, el, handle, rect);
        return el;
    });
}

function removeBoxResizers(editor) {
    if (editor.boxResizers) { editor.boxResizers.forEach((el) => el.remove()); editor.boxResizers = []; }
}

function updateBoxResizerPos(editor, el, handle, rect) {
    const offset = 6; const sy = window.scrollY; const sx = window.scrollX; let top, left;
    if (handle.includes('n')) top = rect.top + sy - offset; else if (handle.includes('s')) top = rect.bottom + sy - offset;
    if (handle.includes('w')) left = rect.left + sx - offset; else if (handle.includes('e')) left = rect.right + sx - offset;
    el.style.top = `${top}px`; el.style.left = `${left}px`;
}

function initBoxResize(editor, event, handle) {
    event.preventDefault(); event.stopPropagation();
    if (!editor.selectedBox) return;
    editor.resizeData = { startX: event.clientX, startY: event.clientY, startW: editor.selectedBox.offsetWidth, startH: editor.selectedBox.offsetHeight, startLeft: editor.selectedBox.offsetLeft, startTop: editor.selectedBox.offsetTop, handle, isBox: true };
    const onMove = (e) => {
        if (!editor.resizeData) return;
        const { startX, startY, startW, startH, startLeft, startTop, handle } = editor.resizeData;
        const dx = e.clientX - startX; const dy = e.clientY - startY;
        let newW = startW; let newH = startH; let newLeft = startLeft; let newTop = startTop;
        if (handle.includes('e')) newW = startW + dx; if (handle.includes('s')) newH = startH + dy;
        if (handle.includes('w')) { newW = startW - dx; newLeft = startLeft + dx; }
        if (handle.includes('n')) { newH = startH - dy; newTop = startTop + dy; }
        const maxW = editor.editor.clientWidth;
        if (newLeft < 0) { newW += newLeft; newLeft = 0; }
        if (newLeft + newW > maxW) { newW = maxW - newLeft; }
        if (newTop < 0) { newH += newTop; newTop = 0; }
        if (newW > 50) { editor.selectedBox.style.width = `${newW}px`; if(handle.includes('w')) editor.selectedBox.style.left = `${newLeft}px`; }
        if (newH > 50) { editor.selectedBox.style.height = `${newH}px`; if(handle.includes('n')) editor.selectedBox.style.top = `${newTop}px`; }
        repositionBoxToolbar(editor);
        const rect = editor.selectedBox.getBoundingClientRect();
        if (editor.boxResizers) { editor.boxResizers.forEach(r => { const h = r.className.split(' ')[1]; updateBoxResizerPos(editor, r, h, rect); }); }
        if (editor.boxSettings && editor.boxSettings.style.display !== 'none') { editor.boxSettings.querySelector('.lbs-box-w').value = parseInt(newW); editor.boxSettings.querySelector('.lbs-box-h').value = parseInt(newH); }
    };
    const onUp = () => {
        document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp);
        editor.resizeData = null;
        if (editor.selectedBox) {
            reanchorBox(editor, editor.selectedBox);
        }
        editor.sync();
    };
    document.addEventListener('mousemove', onMove); document.addEventListener('mouseup', onUp);
}

function initBoxSettings(editor) {
    editor.boxSettings = document.createElement('div');
    editor.boxSettings.className = 'lumina-bubble-settings';
    editor.boxSettings.style.display = 'none';
    editor.boxSettings.style.width = '300px';
    editor.boxSettings.style.zIndex = 600; 
    
    editor.boxSettings.innerHTML = `<div class="lbs-header">Box Settings</div><div class="lbs-row"><label>Color</label><input type="color" class="lbs-box-bg" style="width:24px;height:24px;border:none;cursor:pointer;"><label style="margin-left:10px;">Radius</label><input type="range" class="lbs-box-radius" min="0" max="50" value="0" style="flex:1"></div><div class="lbs-row"><label>Margin</label><input type="range" class="lbs-box-margin" min="-50" max="100" value="0"><span class="lbs-box-margin-val" style="font-size:10px;width:30px;text-align:right;">0px</span></div><div class="lbs-row"><label>Padding</label><input type="range" class="lbs-box-padding" min="0" max="100" value="10"><span class="lbs-box-padding-val" style="font-size:10px;width:30px;text-align:right;">10px</span></div><div class="lbs-row"><label>Rotate</label><input type="range" class="lbs-box-rotate" min="0" max="360" value="0"><span class="lbs-box-rotate-val" style="font-size:10px;width:30px;text-align:right;">0°</span></div><div class="lbs-row"><label>Opacity</label><input type="range" class="lbs-box-opacity" min="0" max="100" value="100"><span class="lbs-box-opacity-val" style="font-size:10px;width:30px;text-align:right;">100%</span></div><div class="lbs-row"><label>Shadow</label><select class="lbs-box-shadow lumina-select" style="flex:1"><option value="none">None</option><option value="0 1px 2px rgba(0,0,0,0.1)">Small</option><option value="0 4px 6px rgba(0,0,0,0.1)">Medium</option><option value="0 10px 15px rgba(0,0,0,0.2)">Large</option></select></div><div class="lbs-row"><label>Anim</label><div style="display:flex; flex:1; gap:4px; align-items:center;"><select class="lbs-box-anim lumina-select" style="flex:1"><option value="none">None</option><option value="lumina-anim-fade">Fade In</option><option value="lumina-anim-slide">Slide Up</option><option value="lumina-anim-pulse">Pulse</option><option value="lumina-anim-spin">Spin Slow</option></select><button type="button" class="lumina-btn lbs-anim-play" title="Preview Animation" style="width:30px; color:black;">▶</button></div></div><div class="lbs-row"><label>Speed</label><input type="range" class="lbs-box-anim-speed" min="0.5" max="5.0" step="0.1" value="1.0"><span class="lbs-box-anim-speed-val" style="font-size:10px;width:30px;text-align:right;">1.0s</span></div><div class="lbs-row"><label>Loop</label><div style="display:flex; align-items:center; gap:8px; flex:1;"><input type="checkbox" class="lbs-box-anim-loop" style="width:16px; height:16px; cursor:pointer; accent-color:#2563eb;"><span style="font-size:11px; color:#666;">Repeat forever</span></div></div><div class="lbs-grp"><span>Dimensions</span><div class="lbs-row"><input type="number" class="lbs-box-w" style="width:60px" placeholder="W"> px <span style="margin:0 5px">x</span> <input type="number" class="lbs-box-h" style="width:60px" placeholder="H"> px</div></div><div class="lbs-grp" style="margin-top:10px; border-top:1px solid #eee; padding-top:10px;"><span>Masking & Darken</span><div class="lbs-row"><label>Clip</label><input type="checkbox" class="lbs-box-clip" style="width:16px; height:16px; cursor:pointer;"><span style="flex:1; margin-left:8px; font-size:11px;">Crop Content</span></div><div class="lbs-row"><label>Shape</label><select class="lbs-box-mask-shape lumina-select" style="flex:1"><option value="none">None</option><option value="circle">Circle</option><option value="ellipse">Ellipse</option><option value="diamond">Diamond</option><option value="inset">Inset</option></select></div><div class="lbs-row"><label>Darken</label><input type="range" class="lbs-box-darken" min="0" max="90" value="0"><span class="lbs-box-darken-val" style="font-size:10px;width:30px;text-align:right;">0%</span></div></div>`;
    
    document.body.appendChild(editor.boxSettings);
    editor.boxSettings.onmousedown = (e) => e.stopPropagation();

    const updateBox = () => {
        if (!editor.selectedBox) return;
        const bg = editor.boxSettings.querySelector('.lbs-box-bg').value; const radius = editor.boxSettings.querySelector('.lbs-box-radius').value; const margin = editor.boxSettings.querySelector('.lbs-box-margin').value; const padding = editor.boxSettings.querySelector('.lbs-box-padding').value; const rotate = editor.boxSettings.querySelector('.lbs-box-rotate').value; const opacity = editor.boxSettings.querySelector('.lbs-box-opacity').value; const shadow = editor.boxSettings.querySelector('.lbs-box-shadow').value; const anim = editor.boxSettings.querySelector('.lbs-box-anim').value; const animSpeed = editor.boxSettings.querySelector('.lbs-box-anim-speed').value; const animLoop = editor.boxSettings.querySelector('.lbs-box-anim-loop').checked; const w = editor.boxSettings.querySelector('.lbs-box-w').value; const h = editor.boxSettings.querySelector('.lbs-box-h').value; const clip = editor.boxSettings.querySelector('.lbs-box-clip').checked; const maskShape = editor.boxSettings.querySelector('.lbs-box-mask-shape').value; const darken = editor.boxSettings.querySelector('.lbs-box-darken').value;
        // Use rgba for background so opacity affects ONLY the background, not content
        const r = parseInt(bg.slice(1,3), 16); const g = parseInt(bg.slice(3,5), 16); const b = parseInt(bg.slice(5,7), 16); const alpha = opacity / 100;
        editor.selectedBox.style.backgroundColor = `rgba(${r},${g},${b},${alpha})`;
        editor.selectedBox.style.borderRadius = `${radius}px`; editor.selectedBox.style.margin = `${margin}px`; editor.selectedBox.style.transform = `rotate(${rotate}deg)`; editor.selectedBox.style.boxShadow = shadow !== 'none' ? shadow : 'none'; editor.selectedBox.style.overflow = clip ? 'hidden' : 'visible'; editor.selectedBox.style.animationDuration = `${animSpeed}s`;
        const contentDiv = editor.selectedBox.querySelector('.lumina-box-content'); if (contentDiv) { contentDiv.style.padding = `${padding}px`; }
        if(w) editor.selectedBox.style.width = w + 'px'; if(h) editor.selectedBox.style.height = h + 'px';
        editor.selectedBox.classList.remove('lumina-anim-fade', 'lumina-anim-slide', 'lumina-anim-pulse', 'lumina-anim-spin', 'lumina-anim-loop'); if (anim !== 'none') { editor.selectedBox.classList.add(anim); if (animLoop) editor.selectedBox.classList.add('lumina-anim-loop'); }
        if (maskShape === 'circle') editor.selectedBox.style.clipPath = 'circle(50%)'; else if (maskShape === 'ellipse') editor.selectedBox.style.clipPath = 'ellipse(50% 50%)'; else if (maskShape === 'diamond') editor.selectedBox.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)'; else if (maskShape === 'inset') editor.selectedBox.style.clipPath = 'inset(10% 10% 10% 10%)'; else editor.selectedBox.style.clipPath = 'none';
        const overlay = editor.selectedBox.querySelector('.lumina-box-mask'); if(overlay) { overlay.style.backgroundColor = `rgba(0,0,0,${darken / 100})`; }
        editor.boxSettings.querySelector('.lbs-box-margin-val').innerText = margin + 'px'; editor.boxSettings.querySelector('.lbs-box-padding-val').innerText = padding + 'px'; editor.boxSettings.querySelector('.lbs-box-rotate-val').innerText = rotate + '°'; editor.boxSettings.querySelector('.lbs-box-opacity-val').innerText = opacity + '%'; editor.boxSettings.querySelector('.lbs-box-anim-speed-val').innerText = animSpeed + 's'; editor.boxSettings.querySelector('.lbs-box-darken-val').innerText = darken + '%';
        editor.sync();
        const rect = editor.selectedBox.getBoundingClientRect(); if(editor.boxResizers) { editor.boxResizers.forEach(r => { const h = r.className.split(' ')[1]; updateBoxResizerPos(editor, r, h, rect); }); }
        repositionBoxToolbar(editor);
    };
    editor.boxSettings.querySelectorAll('input, select').forEach(el => { el.oninput = updateBox; el.onchange = updateBox; });
    editor.boxSettings.querySelector('.lbs-anim-play').onclick = () => {
        if (!editor.selectedBox) return;
        const anim = editor.boxSettings.querySelector('.lbs-box-anim').value; if(anim === 'none') return;
        editor.selectedBox.classList.remove(anim); void editor.selectedBox.offsetWidth; editor.selectedBox.classList.add(anim);
    };
}

function updateBoxSettingsUI(editor) {
    if (!editor.boxSettings || !editor.selectedBox) return;
    const s = editor.selectedBox.style; const contentDiv = editor.selectedBox.querySelector('.lumina-box-content');
    // Extract bg color and alpha from rgba() or rgb() or hex
    let bg = '#ffffff'; let op = 100;
    const bgVal = s.backgroundColor || '#ffffff';
    const rgbaMatch = bgVal.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
    if (rgbaMatch) {
        const r = parseInt(rgbaMatch[1]); const g = parseInt(rgbaMatch[2]); const b = parseInt(rgbaMatch[3]);
        bg = `#${((1<<24)+(r<<16)+(g<<8)+b).toString(16).slice(1)}`;
        op = rgbaMatch[4] ? Math.round(parseFloat(rgbaMatch[4]) * 100) : 100;
    } else if (bgVal.startsWith('#')) {
        bg = bgVal; op = 100;
    }
    editor.boxSettings.querySelector('.lbs-box-bg').value = bg; editor.boxSettings.querySelector('.lbs-box-radius').value = parseInt(s.borderRadius) || 0; editor.boxSettings.querySelector('.lbs-box-margin').value = parseInt(s.margin) || 0;
    const pad = contentDiv ? (parseInt(contentDiv.style.padding) || 10) : 10; editor.boxSettings.querySelector('.lbs-box-padding').value = pad;
    const rotMatch = s.transform.match(/rotate\((-?\d+)deg\)/); const rot = rotMatch ? rotMatch[1] : 0;
    const animDur = s.animationDuration ? parseFloat(s.animationDuration) : 1.0;
    const overlay = editor.selectedBox.querySelector('.lumina-box-mask'); let dark = 0; if(overlay && overlay.style.backgroundColor) { const rgba = overlay.style.backgroundColor.match(/rgba?\(0, 0, 0, ([\d.]+)\)/); if(rgba) dark = Math.round(parseFloat(rgba[1]) * 100); }
    let clip = 'none'; if(s.clipPath) { if(s.clipPath.includes('circle')) clip = 'circle'; else if(s.clipPath.includes('ellipse')) clip = 'ellipse'; else if(s.clipPath.includes('polygon')) clip = 'diamond'; else if(s.clipPath.includes('inset')) clip = 'inset'; }
    const cl = editor.selectedBox.classList; let anim = 'none'; if(cl.contains('lumina-anim-fade')) anim = 'lumina-anim-fade'; else if(cl.contains('lumina-anim-slide')) anim = 'lumina-anim-slide'; else if(cl.contains('lumina-anim-pulse')) anim = 'lumina-anim-pulse'; else if(cl.contains('lumina-anim-spin')) anim = 'lumina-anim-spin';
    const setVal = (cls, val, txtCls, suffix) => { editor.boxSettings.querySelector(cls).value = val; if(txtCls) editor.boxSettings.querySelector(txtCls).innerText = val + suffix; };
    setVal('.lbs-box-rotate', rot, '.lbs-box-rotate-val', '°'); setVal('.lbs-box-opacity', op, '.lbs-box-opacity-val', '%'); setVal('.lbs-box-margin', parseInt(s.margin) || 0, '.lbs-box-margin-val', 'px'); setVal('.lbs-box-padding', pad, '.lbs-box-padding-val', 'px'); setVal('.lbs-box-anim-speed', animDur, '.lbs-box-anim-speed-val', 's'); setVal('.lbs-box-darken', dark, '.lbs-box-darken-val', '%');
    editor.boxSettings.querySelector('.lbs-box-w').value = parseInt(editor.selectedBox.offsetWidth); editor.boxSettings.querySelector('.lbs-box-h').value = parseInt(editor.selectedBox.offsetHeight); editor.boxSettings.querySelector('.lbs-box-mask-shape').value = clip; editor.boxSettings.querySelector('.lbs-box-shadow').value = s.boxShadow && s.boxShadow !== 'none' ? s.boxShadow : 'none'; editor.boxSettings.querySelector('.lbs-box-anim').value = anim; editor.boxSettings.querySelector('.lbs-box-clip').checked = s.overflow === 'hidden';
    // Sync the loop checkbox with the box's current loop state.
    const loopCheckbox = editor.boxSettings.querySelector('.lbs-box-anim-loop');
    if (loopCheckbox) loopCheckbox.checked = editor.selectedBox.classList.contains('lumina-anim-loop');
}

function toggleBoxSettings(editor) {
    if (!editor.boxSettings || !editor.selectedBox) return;
    if (editor.boxSettings.style.display === 'none') {
        editor.boxSettings.style.display = 'block'; updateBoxSettingsUI(editor); repositionSettings(editor);
    } else { editor.boxSettings.style.display = 'none'; }
}
