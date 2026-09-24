import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';

const ACTIONS = [
    { 
        icon: 'table-row-plus-before', 
        title: 'Insert Row Above',
        handler: (editor, table, row, cell) => insertRow(editor, table, row.rowIndex) 
    },
    { 
        icon: 'table-row-plus-after', 
        title: 'Insert Row Below',
        handler: (editor, table, row, cell) => insertRow(editor, table, row.rowIndex + 1) 
    },
    { 
        icon: 'table-col-plus-left', 
        title: 'Insert Column Left',
        handler: (editor, table, row, cell) => insertCol(editor, table, cell.cellIndex) 
    },
    { 
        icon: 'table-col-plus-right', 
        title: 'Insert Column Right',
        handler: (editor, table, row, cell) => insertCol(editor, table, cell.cellIndex + 1) 
    },
    { 
        icon: 'table-row-remove', 
        title: 'Delete Row',
        handler: (editor, table, row) => {
             const rowIndex = row.rowIndex;
             const neighbor = table.rows[rowIndex + 1] || table.rows[rowIndex - 1];
             table.deleteRow(rowIndex);
             return neighbor ? neighbor.cells[0] : null;
        } 
    },
    {
        icon: 'table-col-remove',
        title: 'Delete Column',
        handler: (editor, table, row, cell) => {
            const colIndex = cell.cellIndex;
            const neighborCell = row.cells[colIndex + 1] || row.cells[colIndex - 1];
            for (let i = 0; i < table.rows.length; i++) {
                if (table.rows[i].cells[colIndex]) {
                    table.rows[i].deleteCell(colIndex);
                }
            }
            return neighborCell;
        }
    },
    { 
        icon: 'table-remove', 
        title: 'Delete Table',
        handler: (editor, table) => { 
            table.remove(); 
            return null; 
        } 
    },
    // ===== Cell merge / split (colspan/rowspan support) =====
    {
        icon: 'merge-cells',  // fa-merge-cells — uses 'object-ungroup' as fallback
        title: 'Merge Right (→)',
        handler: (editor, table, row, cell) => mergeCell(editor, cell, 'right')
    },
    {
        icon: 'merge-cells-vertical',
        title: 'Merge Down (↓)',
        handler: (editor, table, row, cell) => mergeCell(editor, cell, 'down')
    },
    {
        icon: 'split-cells',
        title: 'Split Cell',
        handler: (editor, table, row, cell) => splitCell(editor, cell)
    },
    {
        icon: 'header',
        title: 'Toggle Header (th/td)',
        handler: (editor, table, row, cell) => toggleHeader(cell)
    }
];

/**
 * Merge the current cell with its right neighbor (horizontal merge / colspan).
 * The right cell's content is appended to the current cell.
 */
function mergeCell(editor, cell, direction) {
    if (!cell) return cell;
    const row = cell.parentElement;
    const table = cell.closest('table');
    if (!row || !table) return cell;

    if (direction === 'right') {
        const nextCell = cell.nextElementSibling;
        if (!nextCell || nextCell.tagName !== 'TD' && nextCell.tagName !== 'TH') return cell;
        // Compute new colspan
        const currentColspan = parseInt(cell.colSpan || '1', 10);
        const nextColspan = parseInt(nextCell.colSpan || '1', 10);
        cell.colSpan = currentColspan + nextColspan;
        // Append content
        if (nextCell.innerHTML.trim() && nextCell.innerHTML.trim() !== '<br>') {
            cell.innerHTML += ' ' + nextCell.innerHTML;
        }
        nextCell.remove();
        return cell;
    }

    if (direction === 'down') {
        // Find the cell directly below by counting colspans
        const rowIndex = row.rowIndex;
        const nextRow = table.rows[rowIndex + 1];
        if (!nextRow) return cell;
        // Determine which cell in nextRow is "below" this one
        const startCol = getCellStartCol(row, cell);
        const belowCell = findCellByStartCol(nextRow, startCol);
        if (!belowCell) return cell;
        const currentRowspan = parseInt(cell.rowSpan || '1', 10);
        const belowRowspan = parseInt(belowCell.rowSpan || '1', 10);
        cell.rowSpan = currentRowspan + belowRowspan;
        if (belowCell.innerHTML.trim() && belowCell.innerHTML.trim() !== '<br>') {
            cell.innerHTML += ' ' + belowCell.innerHTML;
        }
        belowCell.remove();
        return cell;
    }
    return cell;
}

/**
 * Split a cell that has colspan > 1 or rowspan > 1.
 * If both, splits one step in each direction (colspan -1, rowspan -1).
 */
function splitCell(editor, cell) {
    if (!cell) return cell;
    const row = cell.parentElement;
    const table = cell.closest('table');
    if (!row || !table) return cell;

    const colspan = parseInt(cell.colSpan || '1', 10);
    const rowspan = parseInt(cell.rowSpan || '1', 10);
    let newCell = cell;

    if (colspan > 1) {
        // Reduce colspan and insert a new cell to the right
        cell.colSpan = colspan - 1;
        const newTd = document.createElement(cell.tagName);
        newTd.innerHTML = '<br>';
        newTd.style.border = '1px solid #ccc';
        newTd.style.padding = '5px';
        if (rowspan > 1) newTd.rowSpan = rowspan;
        cell.after(newTd);
        newCell = newTd;
    } else if (rowspan > 1) {
        // Reduce rowspan and insert a new cell in the row below
        cell.rowSpan = rowspan - 1;
        const rowIndex = row.rowIndex;
        const nextRow = table.rows[rowIndex + 1];
        if (nextRow) {
            const newTd = document.createElement(cell.tagName);
            newTd.innerHTML = '<br>';
            newTd.style.border = '1px solid #ccc';
            newTd.style.padding = '5px';
            // Insert at correct position based on column index
            const startCol = getCellStartCol(row, cell);
            const insertBefore = findCellByStartCol(nextRow, startCol + 1);
            if (insertBefore) {
                nextRow.insertBefore(newTd, insertBefore);
            } else {
                nextRow.appendChild(newTd);
            }
            newCell = newTd;
        }
    } else {
        // No colspan/rowspan — nothing to split
        return cell;
    }
    return newCell;
}

/**
 * Toggle a cell between <td> and <th> (header cell).
 */
function toggleHeader(cell) {
    if (!cell) return cell;
    const newTag = cell.tagName === 'TH' ? 'TD' : 'TH';
    const newCell = document.createElement(newTag);
    newCell.innerHTML = cell.innerHTML;
    newCell.colSpan = cell.colSpan;
    newCell.rowSpan = cell.rowSpan;
    // Copy style
    if (cell.style.cssText) newCell.style.cssText = cell.style.cssText;
    cell.replaceWith(newCell);
    return newCell;
}

/**
 * Get the starting column index of a cell (accounting for colspans of preceding cells in the same row).
 */
function getCellStartCol(row, cell) {
    let col = 0;
    for (let i = 0; i < row.cells.length; i++) {
        if (row.cells[i] === cell) return col;
        col += parseInt(row.cells[i].colSpan || '1', 10);
    }
    return col;
}

/**
 * Find the cell in a row whose starting column matches the given index.
 */
function findCellByStartCol(row, targetCol) {
    let col = 0;
    for (let i = 0; i < row.cells.length; i++) {
        if (col === targetCol) return row.cells[i];
        col += parseInt(row.cells[i].colSpan || '1', 10);
    }
    return null;
}

export function initTableToolbar(editor) {
    editor.tblToolbar = document.createElement('div');
    editor.tblToolbar.className = 'lumina-table-toolbar';
    editor.tblToolbar.style.display = 'none';
    editor.tblToolbar.style.zIndex = '10000';

    editor.tblToolbar.onmousedown = (e) => {
        e.stopPropagation();
    };

    ACTIONS.forEach((action) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumina-btn';
        button.innerHTML = editor.getIcon(action.icon);
        button.title = action.title;
        
        if (action.icon.includes('remove')) {
            button.style.color = '#ef4444'; 
        }
        
        button.onclick = (event) => {
            event.stopPropagation();
            event.preventDefault(); 
            
            const cell = editor.selectedCell;
            if (!cell) return;
            
            const row = cell.parentElement;
            const table = cell.closest('table');
            if (!table) return;

            const newFocusTarget = action.handler(editor, table, row, cell);
            
            editor.sync();
            
            if (!table.parentElement) {
                editor.tblToolbar.style.display = 'none';
                editor.selectedCell = null;
            } else if (newFocusTarget && document.body.contains(newFocusTarget)) {
                editor.selectedCell = newFocusTarget;
                const range = document.createRange();
                range.selectNodeContents(newFocusTarget);
                range.collapse(true);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);

                setTimeout(() => {
                    const rect = newFocusTarget.getBoundingClientRect();
                    if (rect.width > 0 && rect.height > 0 && (rect.top !== 0 || rect.left !== 0)) {
                        smartPosition(editor, editor.tblToolbar, rect, 'top', collectVisiblePanels(editor, editor.tblToolbar));
                    }
                }, 0);
            } else if (editor.selectedCell && document.body.contains(editor.selectedCell)) {
                setTimeout(() => {
                    const rect = editor.selectedCell.getBoundingClientRect();
                    if (rect.width > 0 && rect.height > 0 && (rect.top !== 0 || rect.left !== 0)) {
                        smartPosition(editor, editor.tblToolbar, rect, 'top', collectVisiblePanels(editor, editor.tblToolbar));
                    }
                }, 0);
            } else {
                editor.tblToolbar.style.display = 'none';
                editor.selectedCell = null;
            }
        };
        editor.tblToolbar.appendChild(button);
    });

    document.body.appendChild(editor.tblToolbar);

    editor.editor.addEventListener('click', (event) => {
        if (event.target.closest('.lumina-table-toolbar')) return;

        const cell = event.target.closest('td, th');
        
        if (cell && editor.editor.contains(cell)) {
            editor.selectedCell = cell;
            const rect = cell.getBoundingClientRect();
            
            editor.tblToolbar.style.display = 'flex';
            smartPosition(editor, editor.tblToolbar, rect, 'top', collectVisiblePanels(editor, editor.tblToolbar));
        } else if (editor.tblToolbar) {
            editor.tblToolbar.style.display = 'none';
            editor.selectedCell = null;
        }
    });

    initTableResizing(editor);
}

function initTableResizing(editor) {
    let resizingState = null; 

    editor.editor.addEventListener('mousemove', (e) => {
        if (resizingState) return; 

        const target = e.target.closest('td, th');
        if (!target || !editor.editor.contains(target)) {
            editor.editor.style.cursor = '';
            return;
        }

        const rect = target.getBoundingClientRect();
        const offsetX = e.clientX - rect.left;
        const offsetY = e.clientY - rect.top;
        const tolerance = 5; 

        if (rect.width - offsetX < tolerance) {
            target.style.cursor = 'col-resize';
        } else if (rect.height - offsetY < tolerance) {
            target.style.cursor = 'row-resize';
        } else {
            target.style.cursor = '';
        }
    });

    editor.editor.addEventListener('mousedown', (e) => {
        const target = e.target.closest('td, th');
        if (!target) return;

        const rect = target.getBoundingClientRect();
        const offsetX = e.clientX - rect.left;
        const offsetY = e.clientY - rect.top;
        const tolerance = 5;

        if (rect.width - offsetX < tolerance) {
            e.preventDefault();
            resizingState = {
                target: target,
                startX: e.clientX,
                startWidth: target.offsetWidth,
                type: 'col'
            };
            document.body.style.cursor = 'col-resize';
        } 
        else if (rect.height - offsetY < tolerance) {
            e.preventDefault();
            resizingState = {
                target: target,
                startY: e.clientY,
                startHeight: target.offsetHeight,
                type: 'row'
            };
            document.body.style.cursor = 'row-resize';
        }
    });

    document.addEventListener('mousemove', (e) => {
        if (!resizingState) return;

        if (resizingState.type === 'col') {
            const dx = e.clientX - resizingState.startX;
            const newWidth = Math.max(30, resizingState.startWidth + dx);
            resizingState.target.style.width = `${newWidth}px`;
            resizingState.target.style.minWidth = `${newWidth}px`;
        } else if (resizingState.type === 'row') {
            const dy = e.clientY - resizingState.startY;
            const newHeight = Math.max(30, resizingState.startHeight + dy);
            resizingState.target.parentElement.style.height = `${newHeight}px`;
        }
    });

    document.addEventListener('mouseup', () => {
        if (resizingState) {
            resizingState = null;
            document.body.style.cursor = '';
            editor.sync();
            
            if (editor.selectedCell && editor.tblToolbar.style.display === 'flex') {
                const rect = editor.selectedCell.getBoundingClientRect();
                if(rect.width > 0 && rect.height > 0) {
                    smartPosition(editor, editor.tblToolbar, rect, 'top', collectVisiblePanels(editor, editor.tblToolbar));
                }
            }
        }
    });
}

export function insertRow(editor, table, index) {
    const referenceRow = table.rows[index === 0 ? 1 : 0];
    const cellCount = referenceRow ? referenceRow.cells.length : (table.rows[0]?.cells.length || 1);
    const row = table.insertRow(index);
    for (let i = 0; i < cellCount; i++) {
        const cell = row.insertCell(i);
        cell.innerHTML = '<br>';
        cell.style.border = '1px solid #ccc';
        cell.style.padding = '5px';
    }
    return null;
}

export function insertCol(editor, table, index) {
    for (let i = 0; i < table.rows.length; i++) {
        const cell = table.rows[i].insertCell(index);
        cell.innerHTML = '<br>';
        cell.style.border = '1px solid #ccc';
        cell.style.padding = '5px';
    }
    return null;
}
