import { showQuickInsert } from './quickInsert.js?v=20260901';

export function initColumnToolbar(editor) {
    // The separate column toolbar DOM is no longer needed as we use the unified Quick Insert menu.
    // Keeping the function stub to maintain compatibility with initialization calls.
}

export function checkColumnSelection(editor, target) {
    // 1. Identify if we are inside a Grid or Flex Row container
    // This allows us to target columns in blocks like "Hero Split", "Features Grid", etc.
    const container = target.closest('.grid, .flex');
    
    // If no container or it's the main editor element itself, ignore
    if (!container || container === editor.editor) {
        if (editor.selectedColumn) {
            editor.selectedColumn.classList.remove('lumina-col-selected');
            editor.selectedColumn = null;
        }
        return false;
    }

    // Check computed style to confirm it's a layout container (horizontal flow)
    const style = window.getComputedStyle(container);
    const isGrid = style.display === 'grid';
    const isFlexRow = style.display === 'flex' && style.flexDirection === 'row';

    // If it's just a vertical stack (flex-col), we usually treat items as blocks, not columns
    if (!isGrid && !isFlexRow) {
        if (editor.selectedColumn) {
            editor.selectedColumn.classList.remove('lumina-col-selected');
            editor.selectedColumn = null;
        }
        return false;
    }

    // 2. Find the direct child (the column itself) of this container
    let col = target;
    // Traverse up until we hit the container to find the direct child
    while (col && col.parentElement !== container) {
        col = col.parentElement;
    }

    // 3. Select the column
    if (col && col.parentElement === container) {
        // Edge case: Avoid selecting the whole layout block wrapper if we just clicked its padding
        // (Block selection is handled by blockToolbar logic)
        if (col.classList.contains('lumina-layout-block')) return false;

        // Visual Feedback
        if (editor.selectedColumn && editor.selectedColumn !== col) {
            editor.selectedColumn.classList.remove('lumina-col-selected');
        }
        editor.selectedColumn = col;
        col.classList.add('lumina-col-selected');

        // Show Unified Quick Insert Menu
        // The quickInsert module will detect that this is a horizontal context
        // and show Left/Right arrows instead of Up/Down.
        showQuickInsert(editor, col);
        
        return true; // Signal that selection was handled
    }
    
    // Deselect if logic fell through
    if (editor.selectedColumn) {
        editor.selectedColumn.classList.remove('lumina-col-selected');
        editor.selectedColumn = null;
    }
    return false;
}
