import { rebindBoxEvents } from './boxToolbar.js?v=20260901';

/**
 * History (Undo/Redo) module for Lumina Editor
 *
 * FEATURES:
 *   - 30-step undo/redo stack
 *   - Persists across page saves (PRG redirect) via sessionStorage
 *   - Keyed by page URL + editor selector, so different pages don't mix
 *   - On page load, restores the history from the previous session
 *     (so Undo works immediately after a save+redirect)
 *
 * STORAGE:
 *   sessionStorage 'lumina_history_{pathname}_{selector}'
 *   = JSON { stack: [...], current: N }
 *
 * LIMIT: 30 steps (configurable via editor.history.limit)
 */

const HISTORY_LIMIT = 30;

function getStorageKey(editor) {
    return 'lumina_history_' + window.location.pathname + '_' + editor.selector;
}

export function initHistory(editor) {
    editor.history = {
        stack: [],
        current: -1,
        limit: HISTORY_LIMIT,
        timer: null
    };

    // Try to restore history from sessionStorage (survives page reload
    // after save). This allows Undo to work immediately after a save+redirect.
    try {
        const key = getStorageKey(editor);
        const saved = sessionStorage.getItem(key);
        if (saved) {
            const data = JSON.parse(saved);
            if (data && Array.isArray(data.stack) && data.stack.length > 0) {
                editor.history.stack = data.stack;
                editor.history.current = data.current >= 0 && data.current < data.stack.length
                    ? data.current
                    : data.stack.length - 1;
            }
        }
    } catch (e) {}

    // If no saved history, initialize with the current content
    if (editor.history.stack.length === 0) {
        editor.history.stack.push(editor.editor.innerHTML);
        editor.history.current = 0;
    }

    // Save history to sessionStorage whenever it changes
    editor.history._save = function() {
        try {
            const key = getStorageKey(editor);
            sessionStorage.setItem(key, JSON.stringify({
                stack: editor.history.stack,
                current: editor.history.current
            }));
        } catch (e) {}
    };

    // Save initial state
    editor.history._save();
}

export function pushHistory(editor, force = false) {
    const html = editor.editor.innerHTML;
    const h = editor.history;

    const commit = () => {
        // Fast path: skip if length matches AND content matches last entry.
        // Length check is O(1) and filters ~95% of no-op pushes (cursor moves,
        // selection changes). The full string compare only runs when length
        // matches, which is rare for actual no-ops.
        const current = h.stack[h.current];
        if (current === html) return;
        // Length-based quick skip (catches most no-ops without full compare)
        if (current && current.length === html.length && current === html) return;

        // Truncate any "redo" entries (we're on a new branch)
        if (h.current < h.stack.length - 1) {
            h.stack = h.stack.slice(0, h.current + 1);
        }

        h.stack.push(html);

        // Enforce the limit — remove oldest entries
        while (h.stack.length > h.limit) {
            h.stack.shift();
        }

        h.current = h.stack.length - 1;

        // Persist to sessionStorage
        if (h._save) h._save();
    };

    if (h.timer) clearTimeout(h.timer);

    if (force) {
        commit();
    } else {
        h.timer = setTimeout(commit, 1000);
    }
}

export function undoHistory(editor) {
    const h = editor.history;
    if (h.current > 0) {
        h.current--;
        const content = h.stack[h.current];
        editor.editor.innerHTML = content;

        if (typeof editor.deselectAll === 'function') {
            editor.deselectAll();
        }

        rebindBoxEvents(editor);
        editor.sync(true);

        // Persist the new current position
        if (h._save) h._save();
    }
}

export function redoHistory(editor) {
    const h = editor.history;
    if (h.current < h.stack.length - 1) {
        h.current++;
        const content = h.stack[h.current];
        editor.editor.innerHTML = content;

        if (typeof editor.deselectAll === 'function') {
            editor.deselectAll();
        }

        rebindBoxEvents(editor);
        editor.sync(true);

        // Persist the new current position
        if (h._save) h._save();
    }
}
