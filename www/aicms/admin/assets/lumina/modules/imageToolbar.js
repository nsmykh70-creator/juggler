import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';

const IMAGE_BUTTONS = [
    { id: 'replace', icon: 'image-plus', title: 'Replace' },
    { id: 'left', icon: 'align-left', title: 'Float Left' },
    { id: 'center', icon: 'align-center', title: 'Center' },
    { id: 'right', icon: 'align-right', title: 'Float Right' },
    { id: 'tooltip', icon: 'message-square-plus', title: 'Tooltip' },
    { id: 'settings', icon: 'settings', title: 'Settings' },
    { id: 'trash', icon: 'trash', title: 'Delete' }
];

export function initImageToolbar(editor) {
    editor.imgToolbar = document.createElement('div');
    editor.imgToolbar.className = 'lumina-image-toolbar';
    editor.imgToolbar.style.display = 'none';

    IMAGE_BUTTONS.forEach((btnConfig) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumina-btn';
        button.innerHTML = editor.getIcon(btnConfig.icon);
        button.title = btnConfig.title;
        button.dataset.id = btnConfig.id; 
        button.onmousedown = (event) => handleToolbarClick(editor, btnConfig.id, event);
        editor.imgToolbar.appendChild(button);
    });

    editor.imgSettings = document.createElement('div');
    editor.imgSettings.className = 'lumina-bubble-settings';
    editor.imgSettings.style.display = 'none';
    editor.imgSettings.style.width = '260px';
    editor.imgSettings.innerHTML = getSettingsTemplate();

    bindImageSettingEvents(editor);

    document.body.appendChild(editor.imgToolbar);
    document.body.appendChild(editor.imgSettings);
}

function updateImageToolbarState(editor) {
    if(!editor.selectedImage || !editor.imgToolbar) return;
    
    const s = editor.selectedImage.style;
    const btns = editor.imgToolbar.querySelectorAll('button[data-id]');
    btns.forEach(b => b.classList.remove('active'));
    
    if (s.display === 'block' && s.marginLeft === 'auto' && s.marginRight === 'auto') {
        const btn = editor.imgToolbar.querySelector('button[data-id="center"]');
        if(btn) btn.classList.add('active');
    } else if (s.float === 'right') {
        const btn = editor.imgToolbar.querySelector('button[data-id="right"]');
        if(btn) btn.classList.add('active');
    } else {
        if (s.display !== 'block' && s.float !== 'right') {
             const btn = editor.imgToolbar.querySelector('button[data-id="left"]');
             if(btn) btn.classList.add('active');
        }
    }
}

export function selectImage(editor, element) {
    editor.selectedImage = element;
    const isVideo = element.classList.contains('lumina-resizable-media');
    
    element.classList.add('lumina-img-selected');
    const rect = element.getBoundingClientRect();
    
    // Check Settings
    if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.image !== false) {
        editor.imgToolbar.style.display = 'flex';
        // Avoid overlapping with the block toolbar / box toolbar of any
        // parent container that is also currently selected.
        smartPosition(editor, editor.imgToolbar, rect, 'top', collectVisiblePanels(editor, editor.imgToolbar));
        updateImageToolbarState(editor);
    } else {
        editor.imgToolbar.style.display = 'none';
    }

    const replaceBtn = editor.imgToolbar.querySelector('button[title="Replace"]');
    if (replaceBtn) replaceBtn.style.display = isVideo ? 'none' : 'flex';

    const style = element.style;
    const setValue = (selector, value) => {
        const input = editor.imgSettings?.querySelector(selector);
        if (input) input.value = value;
    };

    setValue('.lis-radius', parseInt(style.borderRadius) || 0);
    setValue('.lis-opacity', Math.round(parseFloat(style.opacity || '1') * 100));
    setValue('.lis-margin', parseInt(style.marginTop) || parseInt(style.margin) || 0);
    setValue('.lis-padding', parseInt(style.padding) || 0);

    const fitSelect = editor.imgSettings?.querySelector('.lis-fit-mode');
    if(fitSelect) fitSelect.value = style.objectFit || 'cover';

    const transform = style.transform || '';
    const rotateMatch = transform.match(/rotate\((-?\d+)deg\)/);
    const scaleMatch = transform.match(/scale\((-?\d+),\s*(-?\d+)\)/);

    editor.imageState.rotate = rotateMatch ? parseInt(rotateMatch[1]) : 0;
    editor.imageState.flipX = scaleMatch ? parseInt(scaleMatch[1]) : 1;
    editor.imageState.flipY = scaleMatch ? parseInt(scaleMatch[2]) : 1;

    setValue('.lis-rotate', editor.imageState.rotate);

    const shadowCheckbox = editor.imgSettings?.querySelector('.lis-shadow');
    if (shadowCheckbox) {
        shadowCheckbox.checked = style.boxShadow && style.boxShadow !== 'none';
    }

    // Determine current Mask
    const maskSelect = editor.imgSettings?.querySelector('.lis-mask');
    let currentMask = 'none';
    const clip = style.clipPath;
    if (clip) {
        if (clip.includes('circle')) currentMask = 'circle';
        else if (clip.includes('ellipse')) currentMask = 'ellipse'; 
        else if (clip.includes('50% 0%')) currentMask = 'triangle';
        else if (clip.includes('25% 0%')) currentMask = 'hexagon';
        else if (clip.includes('50% 0%, 100% 50%')) currentMask = 'diamond';
        else if (clip.includes('inset')) currentMask = 'square';
    } else if (style.borderRadius === '50%') {
        currentMask = 'circle';
    }
    if (maskSelect) maskSelect.value = currentMask;

    // Hide Shape controls for Video
    const shapeGroup = editor.imgSettings.querySelector('.lbs-grp.shapes-grp');
    if (shapeGroup) shapeGroup.style.display = isVideo ? 'none' : 'block';

    createResizers(editor, rect);
    positionImageSettings(editor);
}

export function repositionImageToolbar(editor) {
    if (!editor.selectedImage) return;
    const rect = editor.selectedImage.getBoundingClientRect();
    
    if (editor.imgToolbar && editor.settings.bubbleMenus && editor.settings.bubbleMenus.image !== false) {
        editor.imgToolbar.style.display = 'flex';
        smartPosition(editor, editor.imgToolbar, rect, 'top', collectVisiblePanels(editor, editor.imgToolbar));
        updateImageToolbarState(editor);
    } else {
        editor.imgToolbar.style.display = 'none';
    }

    if (editor.resizers) {
        editor.resizers.forEach((el) => {
            const handle = el.className.split(' ')[1];
            updateResizerPos(editor, el, handle, rect);
        });
    }

    positionImageSettings(editor);
}

export function deselectImage(editor) {
    if (editor.selectedImage) {
        editor.selectedImage.classList.remove('lumina-img-selected');
    }
    editor.selectedImage = null;
    if (editor.imgToolbar) editor.imgToolbar.style.display = 'none';
    if (editor.imgSettings) editor.imgSettings.style.display = 'none';
    removeResizers(editor);
}

export function createResizers(editor, rect) {
    removeResizers(editor);
    const handles = ['nw', 'ne', 'sw', 'se', 'n', 's', 'e', 'w'];
    editor.resizers = handles.map((handle) => {
        const el = document.createElement('div');
        el.className = `lumina-resizer ${handle}`;
        el.onmousedown = (event) => initResize(editor, event, handle);
        document.body.appendChild(el);
        updateResizerPos(editor, el, handle, rect);
        return el;
    });
}

export function removeResizers(editor) {
    if (editor.resizers) {
        editor.resizers.forEach((el) => el.remove());
        editor.resizers = [];
    }
}

export function updateResizerPos(editor, el, handle, rect) {
    const offset = 5;
    const sy = window.scrollY;
    const sx = window.scrollX;
    let top;
    let left;

    if (handle.includes('n')) top = rect.top + sy - offset;
    else if (handle.includes('s')) top = rect.bottom + sy - offset;
    else top = rect.top + sy + rect.height / 2 - offset;

    if (handle.includes('w')) left = rect.left + sx - offset;
    else if (handle.includes('e')) left = rect.right + sx - offset;
    else left = rect.left + sx + rect.width / 2 - offset;

    el.style.top = `${top}px`;
    el.style.left = `${left}px`;
}

export function initResize(editor, event, handle) {
    event.preventDefault();
    event.stopPropagation();
    if (!editor.selectedImage) return;
    editor.resizeData = {
        startX: event.clientX,
        startY: event.clientY,
        startW: editor.selectedImage.offsetWidth,
        startH: editor.selectedImage.offsetHeight,
        handle,
        isVideo: editor.selectedImage.classList.contains('lumina-resizable-media')
    };
}

export function handleResizeMove(editor, event) {
    if (!editor.resizeData || !editor.selectedImage) return;
    const { startX, startY, startW, startH, handle, isVideo } = editor.resizeData;
    let width = startW;
    let height = startH;
    const dx = event.clientX - startX;
    const dy = event.clientY - startY;

    if (handle.includes('e')) width += dx;
    if (handle.includes('w')) width -= dx;
    
    if (isVideo) {
        // Enforce 16:9 Aspect Ratio for video wrapper
        if (handle.includes('e') || handle.includes('w')) {
            height = width * (9 / 16);
        } else if (handle.includes('s') || handle.includes('n')) {
            if (handle.includes('s')) height += dy;
            if (handle.includes('n')) height -= dy;
            width = height * (16 / 9);
        }
    } else {
        // Free resize for images
        if (handle.includes('s')) height += dy;
        if (handle.includes('n')) height -= dy;
    }

    editor.selectedImage.style.width = `${Math.max(20, width)}px`;
    editor.selectedImage.style.height = `${Math.max(20, height)}px`;

    if (editor.resizers) {
        const rect = editor.selectedImage.getBoundingClientRect();
        editor.resizers.forEach((resizer) => {
            const handleName = resizer.className.split(' ')[1];
            updateResizerPos(editor, resizer, handleName, rect);
        });
    }
}

export function handleResizeEnd(editor) {
    if (editor.resizeData) {
        editor.resizeData = null;
        editor.sync();
    }
}

function handleToolbarClick(editor, action, event) {
    event.stopPropagation();
    if (!editor.selectedImage) return;

    if (action === 'settings') {
        toggleImageSettings(editor);
        return;
    }

    if (action === 'tooltip') {
        editor.openTooltipModal(editor.selectedImage);
        return;
    }

    if (action === 'trash') {
        editor.selectedImage.remove();
        deselectImage(editor);
        editor.sync();
        return;
    }

    if (action === 'replace') {
        openReplaceModal(editor);
        return;
    }

    if (action === 'left') {
        editor.selectedImage.style.float = 'left';
        editor.selectedImage.style.display = 'inline-block';
        editor.selectedImage.style.margin = '10px';
        editor.selectedImage.style.marginLeft = '0';
        editor.selectedImage.style.marginRight = '10px';
    } else if (action === 'center') {
        editor.selectedImage.style.float = 'none';
        editor.selectedImage.style.display = 'block';
        editor.selectedImage.style.marginLeft = 'auto';
        editor.selectedImage.style.marginRight = 'auto';
    } else if (action === 'right') {
        editor.selectedImage.style.float = 'right';
        editor.selectedImage.style.display = 'inline-block';
        editor.selectedImage.style.margin = '10px';
        editor.selectedImage.style.marginLeft = '10px';
        editor.selectedImage.style.marginRight = '0';
    }

    editor.sync();
    repositionImageToolbar(editor); // Update highlight
    editor.updateToolbarState(); // Force main toolbar to sync immediately
}

function openReplaceModal(editor) {
    const content = `
        <div class="lumina-dropzone" id="img-dropzone">
            <div class="lumina-dropzone-icon">☁️</div>
            <div class="lumina-dropzone-text">Drag & Drop image here<br>or click to upload</div>
            <input type="file" class="lumina-dropzone-input" accept="image/*" id="img-drop-input">
        </div>
        <div class="lbs-row">
            <label style="width:auto;margin-right:10px;">Or URL:</label>
            <input type="text" id="img-url-input" class="lumina-select" style="flex:1" placeholder="https://...">
        </div>
    `;

    const modal = editor.createModalOverlay('Replace Image', content, [{
        text: 'Update',
        onClick: (e, modalContent) => {
            const url = modalContent.querySelector('#img-url-input').value;
            if (url) {
                editor.selectedImage.src = url;
                editor.sync();
            }
            modalContent.closest('.lumina-modal-overlay').remove();
        }
    }]);

    setTimeout(() => bindDropzone(editor), 100);
}

function bindDropzone(editor) {
    const zone = document.getElementById('img-dropzone');
    const input = document.getElementById('img-drop-input');
    if (!zone || !input) return;

    zone.onclick = () => input.click();
    input.onchange = (event) => {
        const file = event.target.files[0];
        if (file) uploadAndReplace(editor, file);
    };

    zone.ondragover = (event) => {
        event.preventDefault();
        zone.classList.add('dragover');
    };
    zone.ondragleave = () => zone.classList.remove('dragover');
    zone.ondrop = (event) => {
        event.preventDefault();
        zone.classList.remove('dragover');
        const file = event.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            uploadAndReplace(editor, file);
        }
    };
}

function uploadAndReplace(editor, file) {
    editor.uploadFile(file).then((url) => {
        editor.selectedImage.src = url;
        editor.sync();
        const overlay = document.querySelector('.lumina-modal-overlay');
        if (overlay) overlay.remove();
    });
}

function toggleImageSettings(editor) {
    if (!editor.imgSettings) return;
    if (editor.imgSettings.style.display === 'none') {
        editor.imgSettings.style.display = 'block';
        positionImageSettings(editor);
    } else {
        editor.imgSettings.style.display = 'none';
    }
}

function positionImageSettings(editor) {
    if (!editor.imgSettings || !editor.imgToolbar) return;
    const rect = editor.imgToolbar.getBoundingClientRect();
    smartPosition(editor, editor.imgSettings, rect, 'bottom', collectVisiblePanels(editor, editor.imgSettings));
}

function getSettingsTemplate() {
    return `
        <div class="lbs-header">Image Style</div>
        <div class="lbs-row">
            <label>Shape</label>
            <select class="lis-mask lumina-select" style="flex:1">
                <option value="none">Normal</option>
                <option value="square">Square</option>
                <option value="circle">Circle</option>
                <option value="rounded">Rounded</option>
                <option value="triangle">Triangle</option>
                <option value="hexagon">Hexagon</option>
                <option value="diamond">Diamond</option>
            </select>
        </div>
        <div class="lbs-row">
            <label>Radius</label>
            <input type="range" class="lis-radius" min="0" max="50" value="0">
        </div>
        <div class="lbs-row">
            <label>Opacity</label>
            <input type="range" class="lis-opacity" min="0" max="100" value="100">
        </div>
        <div class="lbs-row">
            <label>Margin</label>
            <input type="range" class="lis-margin" min="0" max="50" value="0">
        </div>
        <div class="lbs-row">
            <label>Padding</label>
            <input type="range" class="lis-padding" min="0" max="50" value="0">
        </div>
        <div class="lbs-row">
            <label>Rotate</label>
            <input type="range" class="lis-rotate" min="0" max="360" value="0">
        </div>
        <div class="lbs-row">
            <label>Shadow</label>
            <input type="checkbox" class="lis-shadow" style="width:16px;height:16px;">
        </div>
        <div class="lbs-row">
            <label>Fit</label>
            <select class="lis-fit-mode lumina-select" style="flex:1">
                <option value="cover">Cover</option>
                <option value="contain">Contain</option>
                <option value="fill">Fill</option>
            </select>
        </div>
    `;
}

function bindImageSettingEvents(editor) {
    if (!editor.imgSettings) return;

    const updateImg = () => {
        if (!editor.selectedImage) return;
        const radius = editor.imgSettings.querySelector('.lis-radius').value;
        const opacity = editor.imgSettings.querySelector('.lis-opacity').value;
        const margin = editor.imgSettings.querySelector('.lis-margin').value;
        const padding = editor.imgSettings.querySelector('.lis-padding').value;
        const rotate = editor.imgSettings.querySelector('.lis-rotate').value;
        const shadow = editor.imgSettings.querySelector('.lis-shadow').checked;
        const fit = editor.imgSettings.querySelector('.lis-fit-mode').value;
        const mask = editor.imgSettings.querySelector('.lis-mask').value;

        // Apply styles
        editor.selectedImage.style.borderRadius = `${radius}px`;
        editor.selectedImage.style.opacity = opacity / 100;
        
        if (editor.selectedImage.style.marginLeft !== 'auto') {
             editor.selectedImage.style.margin = `${margin}px`;
        } else {
             editor.selectedImage.style.marginTop = `${margin}px`;
             editor.selectedImage.style.marginBottom = `${margin}px`;
        }
        
        editor.selectedImage.style.padding = `${padding}px`;
        editor.selectedImage.style.transform = `rotate(${rotate}deg)`;
        editor.selectedImage.style.boxShadow = shadow ? '0 4px 6px rgba(0,0,0,0.3)' : 'none';
        editor.selectedImage.style.objectFit = fit;

        // Apply Masks
        if (mask === 'none') {
            editor.selectedImage.style.clipPath = 'none';
            editor.selectedImage.style.aspectRatio = '';
        } else if (mask === 'circle') {
             editor.selectedImage.style.borderRadius = '50%'; 
             editor.selectedImage.style.clipPath = 'none';
             editor.selectedImage.style.aspectRatio = '1/1';
             editor.selectedImage.style.objectFit = 'cover';
        } else if (mask === 'square') {
             editor.selectedImage.style.clipPath = 'none';
             editor.selectedImage.style.aspectRatio = '1/1';
             editor.selectedImage.style.objectFit = 'cover';
             editor.selectedImage.style.borderRadius = '0';
        } else if (mask === 'rounded') {
             editor.selectedImage.style.borderRadius = '16px';
             editor.selectedImage.style.clipPath = 'none';
        } else if (mask === 'triangle') {
             editor.selectedImage.style.clipPath = 'polygon(50% 0%, 0% 100%, 100% 100%)';
             editor.selectedImage.style.borderRadius = '0';
             editor.selectedImage.style.aspectRatio = '1/1';
             editor.selectedImage.style.objectFit = 'cover';
        } else if (mask === 'hexagon') {
             editor.selectedImage.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)';
             editor.selectedImage.style.borderRadius = '0';
             editor.selectedImage.style.aspectRatio = '1/1';
             editor.selectedImage.style.objectFit = 'cover';
        } else if (mask === 'diamond') {
             editor.selectedImage.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)';
             editor.selectedImage.style.borderRadius = '0';
             editor.selectedImage.style.aspectRatio = '1/1';
             editor.selectedImage.style.objectFit = 'cover';
        }

        editor.imageState.rotate = parseInt(rotate);
        editor.sync();
    };

    editor.imgSettings.querySelectorAll('input, select').forEach(el => {
        el.oninput = updateImg;
        el.onchange = updateImg;
    });
}
