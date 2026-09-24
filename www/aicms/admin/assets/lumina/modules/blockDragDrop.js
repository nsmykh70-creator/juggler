/**
 * Lumina Editor — Block Drag & Drop
 * ==================================
 * Allows users to grab top-level block elements (h1-h6, p, blockquote,
 * pre, ul, ol, table, div.lumina-box, div.lumina-section) and drag them
 * to reorder within the editor.
 *
 * Approach:
 *   - On mouseover a block element, add a "drag handle" affordance on the left margin.
 *   - When the user starts dragging from the handle, set the block as draggedElement
 *     and use HTML5 DnD events to drop it before/after another block.
 *   - Visual cue: a horizontal blue line shows where the block will land.
 *
 * Coexists with existing image drag/drop (coreEvents.js) — only triggers on
 * blocks with the .lumina-block-drag-handle.
 */

const BLOCK_SELECTOR = 'h1, h2, h3, h4, h5, h6, p, blockquote, pre, ul, ol, table, hr, .lumina-box, .lumina-section, .lumina-code-wrapper, figure';

let dragHandle = null;
let draggedBlock = null;
let dropIndicator = null;
let isDraggingBlock = false;

/**
 * Initialize block drag-and-drop on the editor.
 */
export function initBlockDragDrop(editor) {
    const ed = editor.editor;

    // Create the drag handle (a small grip icon shown on hover)
    dragHandle = document.createElement('div');
    dragHandle.className = 'lumina-block-drag-handle';
    dragHandle.innerHTML = '⋮⋮';
    dragHandle.setAttribute('draggable', 'true');
    dragHandle.style.cssText = `
        position: absolute;
        left: -22px;
        top: 2px;
        width: 18px;
        height: 24px;
        display: none;
        align-items: center;
        justify-content: center;
        cursor: grab;
        color: #94a3b8;
        font-size: 16px;
        line-height: 1;
        background: transparent;
        border-radius: 3px;
        user-select: none;
        opacity: 0;
        transition: opacity 0.15s;
        z-index: 1000;
    `;
    dragHandle.title = 'Drag to reorder';
    document.body.appendChild(dragHandle);

    // Create drop indicator (a horizontal blue line)
    dropIndicator = document.createElement('div');
    dropIndicator.className = 'lumina-drop-indicator';
    dropIndicator.style.cssText = `
        position: absolute;
        height: 3px;
        background: #2563eb;
        border-radius: 2px;
        display: none;
        pointer-events: none;
        z-index: 9999;
        box-shadow: 0 0 4px rgba(37,99,235,0.5);
    `;
    document.body.appendChild(dropIndicator);

    // Show handle on block hover
    ed.addEventListener('mouseover', (e) => {
        if (isDraggingBlock) return;
        const block = e.target.closest(BLOCK_SELECTOR);
        if (!block || !ed.contains(block)) {
            dragHandle.style.display = 'none';
            return;
        }
        // Position handle at block's top-left
        const rect = block.getBoundingClientRect();
        dragHandle.style.display = 'flex';
        dragHandle.style.opacity = '0';
        dragHandle.style.left = (rect.left - 22) + 'px';
        dragHandle.style.top = (rect.top + 2) + 'px';
        dragHandle._targetBlock = block;
        // Show on hover of handle
        dragHandle.onmouseenter = () => { dragHandle.style.opacity = '1'; };
        dragHandle.onmouseleave = () => { if (!isDraggingBlock) dragHandle.style.opacity = '0'; };
    });

    ed.addEventListener('mouseleave', () => {
        if (!isDraggingBlock) dragHandle.style.display = 'none';
    });

    // Start drag from handle
    dragHandle.addEventListener('dragstart', (e) => {
        if (!dragHandle._targetBlock) return;
        draggedBlock = dragHandle._targetBlock;
        isDraggingBlock = true;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'lumina-block');
        // Visual feedback
        draggedBlock.style.opacity = '0.4';
        draggedBlock.style.outline = '2px dashed #2563eb';
        dragHandle.style.opacity = '0';
    });

    dragHandle.addEventListener('dragend', (e) => {
        if (draggedBlock) {
            draggedBlock.style.opacity = '';
            draggedBlock.style.outline = '';
        }
        draggedBlock = null;
        isDraggingBlock = false;
        dropIndicator.style.display = 'none';
        dragHandle.style.display = 'none';
    });

    // Allow drop in editor
    ed.addEventListener('dragover', (e) => {
        if (!isDraggingBlock || !draggedBlock) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        // Find closest block under cursor
        const targetBlock = findBlockAtPoint(ed, e.clientX, e.clientY);
        if (!targetBlock) {
            dropIndicator.style.display = 'none';
            return;
        }
        // Determine if cursor is in upper or lower half of the block
        const rect = targetBlock.getBoundingClientRect();
        const isUpperHalf = e.clientY < rect.top + rect.height / 2;
        // Position indicator
        dropIndicator.style.display = 'block';
        dropIndicator.style.left = rect.left + 'px';
        dropIndicator.style.top = (isUpperHalf ? rect.top : rect.bottom) + 'px';
        dropIndicator.style.width = rect.width + 'px';
        // Store target info
        ed._dropTarget = { block: targetBlock, before: isUpperHalf };
    });

    ed.addEventListener('drop', (e) => {
        if (!isDraggingBlock || !draggedBlock) return;
        e.preventDefault();
        e.stopPropagation();
        const t = ed._dropTarget;
        if (!t || !t.block) return;
        // Don't drop on self
        if (t.block === draggedBlock) return;
        // Don't drop inside self (e.g. nested elements)
        if (draggedBlock.contains(t.block)) return;
        // Move the block
        if (t.before) {
            ed.insertBefore(draggedBlock, t.block);
        } else {
            // Insert after t.block
            if (t.block.nextSibling) {
                ed.insertBefore(draggedBlock, t.block.nextSibling);
            } else {
                ed.appendChild(draggedBlock);
            }
        }
        editor.sync();
        editor.pushHistory(true);
        dropIndicator.style.display = 'none';
    });

    // Hide handle on scroll/resize
    window.addEventListener('scroll', () => { if (!isDraggingBlock) dragHandle.style.display = 'none'; }, true);
    window.addEventListener('resize', () => { if (!isDraggingBlock) dragHandle.style.display = 'none'; });
}

function findBlockAtPoint(ed, x, y) {
    // Get all block elements in the editor
    const blocks = Array.prototype.slice.call(ed.querySelectorAll(BLOCK_SELECTOR));
    // Find the block whose bounding rect contains (x, y), or is closest
    for (let i = 0; i < blocks.length; i++) {
        const r = blocks[i].getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
            return blocks[i];
        }
    }
    // If not directly inside, find closest by vertical distance
    let closest = null;
    let closestDist = Infinity;
    for (let i = 0; i < blocks.length; i++) {
        const r = blocks[i].getBoundingClientRect();
        // Skip the dragged block
        if (blocks[i] === draggedBlock) continue;
        // Distance to center
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        const dist = Math.sqrt((cx - x) ** 2 + (cy - y) ** 2);
        if (dist < closestDist) {
            closestDist = dist;
            closest = blocks[i];
        }
    }
    return closest;
}
