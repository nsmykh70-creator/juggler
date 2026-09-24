import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';

const rgbToHex = (col) => {
    if (!col || col === 'rgba(0, 0, 0, 0)' || col === 'transparent') return '#ffffff';
    if (col.startsWith('#')) return col;
    const rgb = col.match(/\d+/g);
    if (!rgb) return '#000000';
    return `#${((1 << 24) + (parseInt(rgb[0]) << 16) + (parseInt(rgb[1]) << 8) + parseInt(rgb[2])).toString(16).slice(1)}`;
};

export function initCodeBlockToolbar(editor) {
    editor.codeBlockToolbar = document.createElement('div');
    editor.codeBlockToolbar.className = 'lumina-block-toolbar lumina-code-toolbar'; // Added unique class
    editor.codeBlockToolbar.style.display = 'none';
    editor.codeBlockToolbar.style.zIndex = '10000';

    // --- Row 1: Widths ---
    const widthRow = document.createElement('div');
    widthRow.className = 'lbt-row-actions';
    const createWidthBtn = (label, width) => {
        const button = document.createElement('button');
        button.innerText = label;
        button.className = 'lumina-btn';
        button.type = 'button';
        button.onclick = (e) => {
            e.stopPropagation();
            if (editor.selectedCodeBlock) {
                editor.selectedCodeBlock.style.width = width;
                editor.selectedCodeBlock.style.maxWidth = width;
                if (width !== '100%') {
                    if (!editor.selectedCodeBlock.style.marginLeft) editor.selectedCodeBlock.style.marginLeft = 'auto';
                    if (!editor.selectedCodeBlock.style.marginRight) editor.selectedCodeBlock.style.marginRight = 'auto';
                }
                editor.sync();
                showCodeBlockToolbar(editor, editor.selectedCodeBlock); // Reposition
                editor.updateToolbarState();
            }
        };
        return button;
    };
    ['100%', '75%', '50%', '25%'].forEach(w => widthRow.appendChild(createWidthBtn(w, w)));
    editor.codeBlockToolbar.appendChild(widthRow);
    editor.codeBlockToolbar.appendChild(document.createElement('div')).className = 'lumina-sep-hor';

    // --- Row 2: Alignment ---
    const alignRow = document.createElement('div');
    alignRow.className = 'lbt-row-actions';
    const createAlignBtn = (icon, mode) => {
        const button = document.createElement('button');
        button.innerHTML = editor.getIcon(icon);
        button.title = `Align ${mode}`;
        button.className = 'lumina-btn';
        button.type = 'button';
        button.onclick = (e) => {
            e.stopPropagation();
            if (!editor.selectedCodeBlock) return;
            
            // Clean classes
            editor.selectedCodeBlock.classList.remove('mx-auto', 'ml-auto', 'mr-auto', 'ml-0', 'mr-0');
            
            // Ensure width is set so alignment is visible
            const currentW = editor.selectedCodeBlock.style.width;
            if (!currentW || currentW === '100%') {
                editor.selectedCodeBlock.style.width = '90%';
            }

            if (mode === 'left') {
                editor.selectedCodeBlock.style.setProperty('margin-right', 'auto', 'important');
                editor.selectedCodeBlock.style.setProperty('margin-left', '0', 'important');
            } else if (mode === 'center') {
                editor.selectedCodeBlock.style.setProperty('margin-right', 'auto', 'important');
                editor.selectedCodeBlock.style.setProperty('margin-left', 'auto', 'important');
            } else if (mode === 'right') {
                editor.selectedCodeBlock.style.setProperty('margin-left', 'auto', 'important');
                editor.selectedCodeBlock.style.setProperty('margin-right', '0', 'important');
            }
            editor.sync();
            showCodeBlockToolbar(editor, editor.selectedCodeBlock);
            editor.updateToolbarState();
        };
        return button;
    };
    alignRow.appendChild(createAlignBtn('align-left', 'left'));
    alignRow.appendChild(createAlignBtn('align-center', 'center'));
    alignRow.appendChild(createAlignBtn('align-right', 'right'));
    editor.codeBlockToolbar.appendChild(alignRow);
    editor.codeBlockToolbar.appendChild(document.createElement('div')).className = 'lumina-sep-hor';

    // --- Row 3: Actions (Color, Move, Delete) ---
    const actionRow = document.createElement('div');
    actionRow.className = 'lbt-row-actions';

    // Background Color
    const bgWrapper = document.createElement('div');
    bgWrapper.style.display = 'flex';
    bgWrapper.style.alignItems = 'center';
    bgWrapper.style.justifyContent = 'center';
    bgWrapper.style.width = '32px';
    const colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.className = 'lcb-bg-input';
    colorInput.style.width = '24px';
    colorInput.style.height = '24px';
    colorInput.style.border = 'none';
    colorInput.style.cursor = 'pointer';
    colorInput.style.backgroundColor = 'transparent';
    colorInput.title = 'Background Color';
    colorInput.oninput = (e) => {
        if(editor.selectedCodeBlock) {
            editor.selectedCodeBlock.style.backgroundColor = e.target.value;
            // Also color header
            const header = editor.selectedCodeBlock.querySelector('.lumina-code-header');
            if(header) header.style.backgroundColor = e.target.value;
            // Ensure pre inside is transparent to show wrapper color
            const pre = editor.selectedCodeBlock.querySelector('pre');
            if(pre) pre.style.backgroundColor = 'transparent';
            editor.sync();
        }
    };
    bgWrapper.appendChild(colorInput);
    actionRow.appendChild(bgWrapper);

    // Move Up
    const upBtn = document.createElement('button');
    upBtn.className = 'lumina-btn';
    upBtn.innerHTML = editor.getIcon('arrow-up');
    upBtn.title = 'Move Up';
    upBtn.onclick = (e) => {
        e.stopPropagation();
        if(editor.selectedCodeBlock) {
            const prev = editor.selectedCodeBlock.previousElementSibling;
            if(prev) { prev.before(editor.selectedCodeBlock); editor.sync(); showCodeBlockToolbar(editor, editor.selectedCodeBlock); }
        }
    };
    actionRow.appendChild(upBtn);

    // Move Down
    const downBtn = document.createElement('button');
    downBtn.className = 'lumina-btn';
    downBtn.innerHTML = editor.getIcon('arrow-down');
    downBtn.title = 'Move Down';
    downBtn.onclick = (e) => {
        e.stopPropagation();
        if(editor.selectedCodeBlock) {
            const next = editor.selectedCodeBlock.nextElementSibling;
            if(next) { next.after(editor.selectedCodeBlock); editor.sync(); showCodeBlockToolbar(editor, editor.selectedCodeBlock); }
        }
    };
    actionRow.appendChild(downBtn);

    // Delete
    const delBtn = document.createElement('button');
    delBtn.className = 'lumina-btn';
    delBtn.innerHTML = editor.getIcon('trash');
    delBtn.style.color = '#ef4444';
    delBtn.title = 'Delete';
    delBtn.onclick = (e) => {
        e.stopPropagation();
        if(editor.selectedCodeBlock) {
            editor.selectedCodeBlock.remove();
            hideCodeBlockToolbar(editor);
            editor.sync();
        }
    };
    actionRow.appendChild(delBtn);

    editor.codeBlockToolbar.appendChild(actionRow);
    document.body.appendChild(editor.codeBlockToolbar);
}

export function showCodeBlockToolbar(editor, block) {
    if (!editor.codeBlockToolbar) return;
    
    // Check Settings
    if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.codeBlock === false) {
        editor.codeBlockToolbar.style.display = 'none';
        return;
    }

    editor.selectedCodeBlock = block;
    
    // Init Color Input
    const input = editor.codeBlockToolbar.querySelector('.lcb-bg-input');
    const computed = window.getComputedStyle(block);
    // Convert to hex
    const color = computed.backgroundColor;
    if(input) input.value = rgbToHex(color);

    editor.codeBlockToolbar.style.display = 'flex';
    const rect = block.getBoundingClientRect();
    // Prefer top positioning; avoid stacking on top of any parent
    // block toolbar that may also be visible.
    smartPosition(editor, editor.codeBlockToolbar, rect, 'top', collectVisiblePanels(editor, editor.codeBlockToolbar));
}

export function hideCodeBlockToolbar(editor) {
    if (!editor.codeBlockToolbar) return;
    editor.codeBlockToolbar.style.display = 'none';
    editor.selectedCodeBlock = null;
}
