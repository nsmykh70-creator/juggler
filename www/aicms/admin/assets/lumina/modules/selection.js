import { smartPosition } from './positioning.js?v=20260901';

export function saveSelection(editor) {
    const sel = window.getSelection();
    if (sel.rangeCount > 0) {
        editor.savedRange = sel.getRangeAt(0);
    }
}

export function restoreSelection(editor) {
    if (editor.savedRange) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(editor.savedRange);
    }
}

export function applyCustomStyle(editor, styles) {
    let range = editor.savedRange;
    const sel = window.getSelection();
    if (sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) {
        range = sel.getRangeAt(0);
    } else {
        restoreSelection(editor);
        range = editor.savedRange;
    }

    if (!range) return;

    const cleanSpan = (span) => {
        if (!span.getAttribute('style')) {
            span.removeAttribute('style');
        }
        if (span.style.length === 0 && span.getAttribute('data-custom-style')) {
            const parent = span.parentNode;
            while (span.firstChild) parent.insertBefore(span.firstChild, span);
            parent.removeChild(span);
        }
    };

    let targetSpan = null;
    let node = range.commonAncestorContainer;
    if (node.nodeType === 3) node = node.parentElement;

    let current = node;
    while (current && current !== editor.editor) {
        if (current.tagName === 'SPAN' && current.getAttribute('data-custom-style') === 'true') {
            targetSpan = current;
            break;
        }
        current = current.parentElement;
    }

    const spanText = targetSpan ? targetSpan.textContent : '';
    const selText = range.toString();
    const isExactMatch = targetSpan && (spanText === selText);
    const isCollapsedInside = range.collapsed && targetSpan;
    
    if (isExactMatch || isCollapsedInside) {
        Object.entries(styles).forEach(([k, v]) => {
            if (v === '' || v === null) {
                targetSpan.style[k] = '';
            } else {
                targetSpan.style[k] = v;
            }
        });
        cleanSpan(targetSpan);
    } else {
        const span = document.createElement('span');
        span.setAttribute('data-custom-style', 'true');
        Object.assign(span.style, styles);

        try {
            const content = range.extractContents();
            span.appendChild(content);
            range.insertNode(span);
            
            range.selectNodeContents(span);
            sel.removeAllRanges();
            sel.addRange(range);
            editor.savedRange = range;
            editor.selectedSpan = span;
        } catch (e) {
            console.error('Style apply error', e);
        }
    }
    
    editor.sync();
}

export function toggleColorPicker(editor, type, btn) {
    const existing = document.querySelector('.lumina-color-picker');
    const triggerId = btn.title || btn.innerText || btn.innerHTML;

    if (existing) {
        existing.remove();
        if (existing.dataset.trigger === triggerId && existing.dataset.type === type) return;
    }

    const picker = document.createElement('div');
    picker.className = 'lumina-color-picker';
    picker.dataset.trigger = triggerId;
    picker.dataset.type = type;

    const grid = document.createElement('div');
    grid.className = 'lcp-grid';
    editor.palette.forEach(color => {
        const swatch = document.createElement('div');
        swatch.className = 'lcp-swatch';
        swatch.style.backgroundColor = color;
        swatch.title = color;
        swatch.onmousedown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            restoreSelection(editor);
            applyCustomStyle(editor, { [type]: color });
            picker.remove();
            editor.checkSelection(); 
            editor.pushHistory(true);
        };
        grid.appendChild(swatch);
    });
    picker.appendChild(grid);

    const row = document.createElement('div');
    row.className = 'lcp-row';
    
    const resetBtn = document.createElement('button');
    resetBtn.innerText = 'Reset';
    resetBtn.className = 'lcp-reset';
    resetBtn.onmousedown = (e) => {
        e.preventDefault();
        restoreSelection(editor);
        applyCustomStyle(editor, { [type]: '' }); 
        picker.remove();
        editor.checkSelection();
        editor.pushHistory(true);
    };

    const hexInput = document.createElement('input');
    hexInput.type = 'text';
    hexInput.placeholder = '#HEX';
    hexInput.className = 'lcp-hex';
    hexInput.onchange = (e) => {
        restoreSelection(editor);
        applyCustomStyle(editor, { [type]: e.target.value });
        editor.checkSelection();
        editor.pushHistory(true);
    };
    hexInput.onkeydown = (e) => {
        if(e.key === 'Enter') {
            e.preventDefault();
            restoreSelection(editor);
            applyCustomStyle(editor, { [type]: e.target.value });
            picker.remove();
            editor.checkSelection();
            editor.pushHistory(true);
        }
    };

    row.appendChild(hexInput);
    row.appendChild(resetBtn);
    picker.appendChild(row);

    document.body.appendChild(picker);

    const rect = btn.getBoundingClientRect();
    smartPosition(editor, picker, rect);

    const closeHandler = (e) => {
        if (!picker.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
            picker.remove();
            document.removeEventListener('mousedown', closeHandler);
        }
    };
    document.addEventListener('mousedown', closeHandler);
}
