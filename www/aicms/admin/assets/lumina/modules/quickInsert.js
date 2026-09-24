import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';
import { getIcon } from './icons.js?v=20260901';

export function initQuickInsert(editor) {
    editor.quickInsert = document.createElement('div');
    editor.quickInsert.className = 'lumina-quick-wrapper';
    editor.quickInsert.style.display = 'none';

    const triggerBar = document.createElement('div');
    triggerBar.className = 'lumina-quick-trigger';
    
    const createArrow = (icon, title, dir) => {
        const btn = document.createElement('button');
        btn.className = 'lumina-btn lumina-quick-arrow'; // Added class identifier
        btn.innerHTML = editor.getIcon(icon);
        btn.title = title;
        btn.onmousedown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            moveTarget(editor, dir);
        };
        return btn;
    };

    const btnLeft = createArrow('arrow-up', 'Move Left', 'left');
    btnLeft.innerHTML = editor.getIcon('arrow-up').replace('<svg', '<svg style="transform: rotate(-90deg)"');

    const btnRight = createArrow('arrow-down', 'Move Right', 'right');
    btnRight.innerHTML = editor.getIcon('arrow-down').replace('<svg', '<svg style="transform: rotate(-90deg)"');

    const btnAdd = document.createElement('button');
    btnAdd.className = 'lumina-btn active';
    btnAdd.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
    btnAdd.title = 'Insert Content';
    btnAdd.onmousedown = (e) => {
        e.preventDefault();
        e.stopPropagation();
        toggleDropdown(editor);
    };

    const btnUp = createArrow('arrow-up', 'Move Up', 'up');
    const btnDown = createArrow('arrow-down', 'Move Down', 'down');

    triggerBar.appendChild(btnLeft);
    triggerBar.appendChild(btnRight);
    triggerBar.appendChild(btnAdd); 
    triggerBar.appendChild(btnUp);
    triggerBar.appendChild(btnDown);

    const dropdown = document.createElement('div');
    dropdown.className = 'lumina-quick-dropdown';
    dropdown.style.display = 'none';

    const items = [
        { label: 'Image', icon: 'image', action: () => { editor.openImageUpload(); } },
        { label: 'Video', icon: 'video', action: () => { editor.openVideoModal(); } },
        { label: 'Text', icon: 'type', action: () => { insertInside(editor, '<p>Start writing here...</p>'); } },
        { label: 'Map', icon: 'map', action: () => { insertInside(editor, '<iframe width="100%" height="300" frameborder="0" src="https://maps.google.com/maps?q=New%20York&t=&z=13&ie=UTF8&iwloc=&output=embed" style="border-radius:8px;"></iframe>'); } },
        { label: 'Button', icon: 'mouse-pointer', action: () => { editor.openButtonCreatorModal(); } },
        { label: 'Section', icon: 'section-plus', action: () => { editor.insertSection(); } },
        { label: 'Box', icon: 'box', id: 'box-item', action: () => { editor.insertBox(); } } // Added ID
    ];

    items.forEach(item => {
        const row = document.createElement('div');
        row.className = 'lumina-quick-item';
        if (item.id) row.dataset.id = item.id; // Store ID for logic
        row.innerHTML = `${editor.getIcon(item.icon)} <span>${item.label}</span>`;
        row.onmousedown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            prepareInsert(editor); 
            item.action();
            hideDropdown(editor);
        };
        dropdown.appendChild(row);
    });

    editor.quickInsert.appendChild(triggerBar);
    editor.quickInsert.appendChild(dropdown);
    document.body.appendChild(editor.quickInsert);
}

export function showQuickInsert(editor, targetElement) {
    if (!editor.quickInsert) return;
    
    // Check Global Setting
    if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.quickInsert === false) {
        editor.quickInsert.style.display = 'none';
        return;
    }
    
    editor.quickInsertTarget = targetElement;
    editor.quickInsert.style.display = 'flex';
    
    const dropdown = editor.quickInsert.querySelector('.lumina-quick-dropdown');
    if (dropdown) dropdown.style.display = 'none';

    // === Logic for Box Handling ===
    const isBox = targetElement.classList.contains('lumina-free-box');
    
    // 1. Hide Arrows if Box
    const arrowBtns = editor.quickInsert.querySelectorAll('.lumina-quick-arrow');
    arrowBtns.forEach(btn => {
        btn.style.display = isBox ? 'none' : 'flex';
    });

    // 2. Hide "Box" option from dropdown if target is already a Box (prevent nesting/confusion)
    const boxItem = dropdown?.querySelector('[data-id="box-item"]');
    if (boxItem) {
        boxItem.style.display = isBox ? 'none' : 'flex';
    }
    // ==============================

    let rect = targetElement.getBoundingClientRect();

    let referenceRect = rect;
    let preference = 'bottom';

    if (editor.blockSettings && editor.blockSettings.style.display !== 'none') {
        referenceRect = editor.blockSettings.getBoundingClientRect();
        preference = 'bottom';
    } else if (editor.blockToolbar && editor.blockToolbar.style.display !== 'none') {
        referenceRect = editor.blockToolbar.getBoundingClientRect();
        preference = 'bottom';
    }

    // Pass the list of all other visible panels as obstacles so the
    // quick-insert bar never stacks on top of, say, the image toolbar
    // of a selected image inside the block.
    smartPosition(editor, editor.quickInsert, referenceRect, preference, collectVisiblePanels(editor, editor.quickInsert));
}

export function hideQuickInsert(editor) {
    if (editor.quickInsert) {
        editor.quickInsert.style.display = 'none';
        editor.quickInsertTarget = null;
        const dropdown = editor.quickInsert.querySelector('.lumina-quick-dropdown');
        if (dropdown) dropdown.style.display = 'none';
    }
}

function toggleDropdown(editor) {
    const dropdown = editor.quickInsert.querySelector('.lumina-quick-dropdown');
    if (dropdown) {
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    }
}

function hideDropdown(editor) {
    const dropdown = editor.quickInsert.querySelector('.lumina-quick-dropdown');
    if (dropdown) dropdown.style.display = 'none';
}

function moveTarget(editor, direction) {
    const target = editor.quickInsertTarget;
    if (!target || !target.parentElement) return;

    if (direction === 'up' || direction === 'left') {
        const prev = target.previousElementSibling;
        if (prev) {
            target.parentElement.insertBefore(target, prev);
        }
    } else {
        const next = target.nextElementSibling;
        if (next) {
            const nextNext = next.nextElementSibling;
            if (nextNext) {
                target.parentElement.insertBefore(target, nextNext);
            } else {
                target.parentElement.appendChild(target);
            }
        }
    }
    
    editor.sync();
    setTimeout(() => {
        showQuickInsert(editor, target);
    }, 0);
}

export function prepareInsert(editor) {
    let container = editor.quickInsertTarget;
    if (!container) {
        editor.editor.focus();
        return;
    }

    // --- CASE 1: BOX (Insert INSIDE) ---
    // If we are targeting a Box, we want to focus inside its content area
    if (container.classList.contains('lumina-free-box')) {
        let contentArea = container.querySelector('.lumina-box-content');
        if (!contentArea) contentArea = container;
        try {
            contentArea.focus();
            const range = document.createRange();
            range.selectNodeContents(contentArea);
            range.collapse(false); // End of content
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            editor.saveSelection(); // Save this position
        } catch (e) {
            editor.editor.focus();
        }
        return;
    }

    // --- CASE 2: SECTION / LAYOUT BLOCK (Insert BELOW) ---
    // If we are targeting a Section or Block, we want to append AFTER it
    if (container.classList.contains('lumina-layout-block') || container.tagName === 'SECTION' || container.tagName === 'DIV') {
        const range = document.createRange();
        range.setStartAfter(container);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        
        // Ensure Editor is focused so commands work
        editor.editor.focus();
        editor.saveSelection(); // CRITICAL: Save this "after" position for contentOps
        return;
    }

    // Default Fallback
    editor.editor.focus();
}

function insertInside(editor, html) {
    // If targeting box, double check we are inside
    if (editor.selectedBox && editor.quickInsertTarget === editor.selectedBox) {
         const content = editor.selectedBox.querySelector('.lumina-box-content');
         if (content) {
             const sel = window.getSelection();
             // If selection is not inside the box content, fix it
             if (!sel.rangeCount || !content.contains(sel.anchorNode)) {
                 const range = document.createRange();
                 range.selectNodeContents(content);
                 range.collapse(false);
                 sel.removeAllRanges();
                 sel.addRange(range);
                 editor.saveSelection();
             }
         }
    } else {
        // Force sync selection state if we moved it in prepareInsert
        // (prepareInsert calls saveSelection, so restoreSelection in contentOps will pick it up)
    }

    document.execCommand('insertHTML', false, html);
    editor.sync();
    editor.pushHistory(true);
}
