/**
 * Lumina Editor — Active Button Highlighting
 *
 * initActiveState(editor) sets up listeners on the editor content that, on
 * keyup / mouseup / selectionchange, query the current command state via
 * document.queryCommandState() and the current block format by inspecting
 * the selection's parent elements.
 *
 * When a command is active, the corresponding toolbar button receives the
 * `lumina-btn-active` class. Block format detection also keeps the format
 * <select> in sync.
 *
 * Checks are debounced with requestAnimationFrame to avoid hammering the DOM
 * during rapid typing.
 */

const ACTIVE_CLASS = 'lumina-btn-active';

// Map queryCommandState command → CSS selector used to find the toolbar button
const COMMAND_MAP = [
    { cmd: 'bold',                  selector: 'button[data-cmd="bold"]' },
    { cmd: 'italic',                selector: 'button[data-cmd="italic"]' },
    { cmd: 'underline',             selector: 'button[data-cmd="underline"]' },
    { cmd: 'strikeThrough',         selector: 'button[data-cmd="strikeThrough"]' },
    { cmd: 'justifyLeft',           selector: 'button[data-cmd="justifyLeft"]' },
    { cmd: 'justifyCenter',         selector: 'button[data-cmd="justifyCenter"]' },
    { cmd: 'justifyRight',          selector: 'button[data-cmd="justifyRight"]' },
    { cmd: 'justifyFull',           selector: 'button[data-cmd="justifyFull"]' },
    { cmd: 'insertOrderedList',     selector: 'button[data-cmd="insertOrderedList"]' },
    { cmd: 'insertUnorderedList',   selector: 'button[data-cmd="insertUnorderedList"]' }
];

// Block-format tag → [formatSelect option value, optional button selector]
const BLOCK_FORMAT_MAP = [
    { tag: 'H1',         selectVal: 'h1',         btnSelector: 'button[data-btn-id="h1"]' },
    { tag: 'H2',         selectVal: 'h2',         btnSelector: 'button[data-btn-id="h2"]' },
    { tag: 'H3',         selectVal: 'h3',         btnSelector: 'button[data-btn-id="h3"]' },
    { tag: 'H4',         selectVal: 'h4',         btnSelector: 'button[data-btn-id="h4"]' },
    { tag: 'BLOCKQUOTE', selectVal: 'blockquote', btnSelector: 'button[data-btn-id="quote"]' },
    { tag: 'PRE',        selectVal: 'pre',        btnSelector: 'button[data-btn-id="codeBlock"]' },
    { tag: 'P',          selectVal: 'p',          btnSelector: null }
];

function setBtnActive(toolbar, selector, active) {
    if (!selector) return;
    const btns = toolbar.querySelectorAll(selector);
    btns.forEach(btn => {
        if (active) btn.classList.add(ACTIVE_CLASS);
        else btn.classList.remove(ACTIVE_CLASS);
    });
}

function getSelectionBlock(editor) {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    let node = sel.anchorNode;
    if (!node) return null;
    if (node.nodeType === 3) node = node.parentElement;
    if (!node || !editor.editor.contains(node)) return null;
    // Walk up looking for a known block tag.
    // IMPORTANT: a <p> is the innermost block, so if we hit P first we must
    // keep walking up — a more specific ancestor like <blockquote>/<pre>
    // (e.g. <blockquote><p>…</p></blockquote>) should win over the inner P,
    // otherwise the format select would show "Normal" inside a quote.
    let cur = node;
    let pFallback = null;
    while (cur && cur !== editor.editor) {
        if (cur.nodeType === 1) {
            const tag = cur.tagName;
            const hit = BLOCK_FORMAT_MAP.find(b => b.tag === tag);
            if (hit) {
                if (hit.tag === 'P') {
                    if (!pFallback) pFallback = hit;
                } else {
                    return hit;
                }
            }
        }
        cur = cur.parentElement;
    }
    return pFallback;
}

export function initActiveState(editor) {
    if (!editor || !editor.editor || !editor.toolbarContainer) return;

    let rafId = null;

    const check = () => {
        if (rafId !== null) cancelAnimationFrame(rafId);
        rafId = requestAnimationFrame(() => {
            rafId = null;
            runCheck();
        });
    };

    const runCheck = () => {
        const toolbar = editor.toolbarContainer;
        if (!toolbar) return;

        // Only run checks when the selection is inside our editor
        const sel = window.getSelection();
        let insideEditor = false;
        if (sel && sel.rangeCount > 0) {
            let n = sel.anchorNode;
            if (n && editor.editor.contains(n)) insideEditor = true;
        }

        // --- 1. Command states (queryCommandState) ---
        COMMAND_MAP.forEach(entry => {
            let active = false;
            if (insideEditor) {
                try { active = !!document.queryCommandState(entry.cmd); }
                catch (e) { active = false; }
            }
            setBtnActive(toolbar, entry.selector, active);
        });

        // --- 2. Block format detection ---
        const block = insideEditor ? getSelectionBlock(editor) : null;

        // Clear all block-format button highlights first
        BLOCK_FORMAT_MAP.forEach(b => {
            if (b.btnSelector) setBtnActive(toolbar, b.btnSelector, false);
        });

        // Update format <select>
        const formatSelect = editor.formatSelect || toolbar.querySelector('select.lumina-select');
        if (formatSelect && formatSelect.tagName === 'SELECT') {
            if (block && block.selectVal) {
                // Only update if changed to avoid clobbering user interaction
                if (formatSelect.value !== block.selectVal) {
                    formatSelect.value = block.selectVal;
                }
            } else if (formatSelect.value !== 'p') {
                formatSelect.value = 'p';
            }
        }

        // Highlight block-format button if any
        if (block && block.btnSelector) {
            setBtnActive(toolbar, block.btnSelector, true);
        }
    };

    // Attach listeners to the editor element
    editor.editor.addEventListener('keyup', check);
    editor.editor.addEventListener('mouseup', check);
    // selectionchange on document — only fire check when selection is in editor
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) {
            check();
        }
    });

    // Also refresh when the editor regains focus or on input
    editor.editor.addEventListener('focus', check);
    editor.editor.addEventListener('input', check);

    // Run an initial check shortly after init (once toolbar is built)
    setTimeout(check, 0);
}

export default { initActiveState };
