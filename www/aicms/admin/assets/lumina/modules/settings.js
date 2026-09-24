import { DEFAULT_MODULE_SETTINGS, DEFAULT_BUTTON_ORDER, SELECT_BUTTONS } from './constants.js?v=20260901';

/**
 * Insert a button id into `order` at its "natural" position based on the
 * DEFAULT_BUTTON_ORDER. We look for the last button in the saved order that
 * comes BEFORE the new button in DEFAULT_BUTTON_ORDER, and insert the new
 * button right after it. If no such anchor is found, the new button is
 * prepended to the start of the order.
 *
 * This prevents new buttons (added in updates) from being appended at the very
 * end of the toolbar where they could be cut off or placed after the AI button.
 */
function insertAtNaturalPosition(order, btnId, defaultOrder) {
    const defaultIdx = defaultOrder.indexOf(btnId);
    if (defaultIdx === -1) {
        // Not in default order — append at end as fallback
        order.push(btnId);
        return;
    }

    // Find the last button in `order` that comes BEFORE btnId in defaultOrder
    let insertAfterIdx = -1;
    for (let i = 0; i < order.length; i++) {
        const btnDefaultIdx = defaultOrder.indexOf(order[i]);
        if (btnDefaultIdx !== -1 && btnDefaultIdx < defaultIdx) {
            insertAfterIdx = i;
        }
    }

    if (insertAfterIdx === -1) {
        // No anchor found — insert at the beginning
        order.unshift(btnId);
    } else {
        order.splice(insertAfterIdx + 1, 0, btnId);
    }
}

export function loadSettings(editor) {
    // Server-side config passed via the LuminaEditor constructor options
    // (these come from data/lumina_settings.json through lumina_integration.php).
    // Server config takes precedence over localStorage for module/button flags
    // so the admin can centrally enable/disable features.
    const serverModules = (editor.options && editor.options.modules) || {};
    const serverButtons = (editor.options && editor.options.buttons) || {};
    const serverBubble  = (editor.options && editor.options.bubbleMenus) || {};

    // Sticky toolbar: the default is TRUE (user wants sticky on by default).
    // Resolution order: server config (if explicitly set) > localStorage (if set) > DEFAULT (true).
    // We use a strict check for `true`/`false` because `||` would clobber a legit `false`.
    const serverStickyRaw = (editor.options && editor.options.stickyToolbar);
    const serverSticky = (serverStickyRaw === true || serverStickyRaw === false) ? serverStickyRaw : undefined;

    try {
        const saved = localStorage.getItem('lumina_settings');
        if (saved) {
            const parsed = JSON.parse(saved);
            // Sticky resolution: server (if defined) > saved localStorage > default
            const savedSticky = (parsed.stickyToolbar === true || parsed.stickyToolbar === false) ? parsed.stickyToolbar : undefined;
            const resolvedSticky = serverSticky !== undefined ? serverSticky
                                  : (savedSticky !== undefined ? savedSticky
                                  : DEFAULT_MODULE_SETTINGS.stickyToolbar);

            editor.settings = {
                modules: { ...DEFAULT_MODULE_SETTINGS.modules, ...parsed.modules, ...serverModules },
                buttons: { ...DEFAULT_MODULE_SETTINGS.buttons, ...(parsed.buttons || {}), ...serverButtons },
                bubbleMenus: { ...DEFAULT_MODULE_SETTINGS.bubbleMenus, ...(parsed.bubbleMenus || {}), ...serverBubble },
                stickyToolbar: resolvedSticky,
                buttonOrder: parsed.buttonOrder ? [...parsed.buttonOrder] : [...DEFAULT_BUTTON_ORDER]
            };
            // Insert any new buttons (added in updates) at their natural position
            // based on DEFAULT_BUTTON_ORDER, NOT at the end of the saved order.
            // This ensures newly added buttons like `toggleButton` appear in the
            // correct position on the toolbar even for users with saved settings.
            const order = editor.settings.buttonOrder;
            Object.keys(DEFAULT_MODULE_SETTINGS.buttons).forEach(k => {
                if (!SELECT_BUTTONS.includes(k) && !order.includes(k)) {
                    insertAtNaturalPosition(order, k, DEFAULT_BUTTON_ORDER);
                }
            });
            if (editor.settings.modules.diagrams !== undefined) {
                delete editor.settings.modules.diagrams;
            }
        } else {
            // No localStorage — use defaults + server overrides
            editor.settings = {
                modules: { ...DEFAULT_MODULE_SETTINGS.modules, ...serverModules },
                buttons: { ...DEFAULT_MODULE_SETTINGS.buttons, ...serverButtons },
                bubbleMenus: { ...DEFAULT_MODULE_SETTINGS.bubbleMenus, ...serverBubble },
                stickyToolbar: serverSticky !== undefined ? serverSticky : DEFAULT_MODULE_SETTINGS.stickyToolbar,
                buttonOrder: [...DEFAULT_BUTTON_ORDER]
            };
        }

        // HARD GUARD: make sure the toggle button is enabled and in the order
        // even if the user has an old saved settings blob that explicitly
        // disabled it. The toggle button is a core feature of this build.
        if (editor.settings.buttons.toggleButton === undefined) {
            editor.settings.buttons.toggleButton = true;
        }
        if (!editor.settings.buttonOrder) {
            editor.settings.buttonOrder = [...DEFAULT_BUTTON_ORDER];
        }
        if (!editor.settings.buttonOrder.includes('toggleButton')) {
            insertAtNaturalPosition(editor.settings.buttonOrder, 'toggleButton', DEFAULT_BUTTON_ORDER);
        }
        // HARD GUARD: same for the "Вставить модуль" button (newly added).
        if (editor.settings.buttons.insertModule === undefined) {
            editor.settings.buttons.insertModule = true;
        }
        if (!editor.settings.buttonOrder.includes('insertModule')) {
            insertAtNaturalPosition(editor.settings.buttonOrder, 'insertModule', DEFAULT_BUTTON_ORDER);
        }
        // HARD GUARD: same for "Режим шаблона" (themeView) button.
        if (editor.settings.buttons.themeView === undefined) {
            editor.settings.buttons.themeView = true;
        }
        if (!editor.settings.buttonOrder.includes('themeView')) {
            insertAtNaturalPosition(editor.settings.buttonOrder, 'themeView', DEFAULT_BUTTON_ORDER);
        }
        // Also ensure formatHTML is present
        if (editor.settings.buttons.formatHTML === undefined) {
            editor.settings.buttons.formatHTML = true;
        }
        if (!editor.settings.buttonOrder.includes('formatHTML')) {
            insertAtNaturalPosition(editor.settings.buttonOrder, 'formatHTML', DEFAULT_BUTTON_ORDER);
        }
        // Remove aiImage button — replaced by AI content generator
        if (editor.settings.buttons.aiImage !== undefined) {
            delete editor.settings.buttons.aiImage;
        }
        var aiImgIdx = editor.settings.buttonOrder.indexOf('aiImage');
        if (aiImgIdx !== -1) {
            editor.settings.buttonOrder.splice(aiImgIdx, 1);
        }

        if (editor.disableSticky) {
            editor.settings.stickyToolbar = false;
        }
    } catch (e) {
        console.error("Error loading settings:", e);
        // Fallback to defaults on error
        editor.settings = JSON.parse(JSON.stringify(DEFAULT_MODULE_SETTINGS));
        editor.settings.buttonOrder = [...DEFAULT_BUTTON_ORDER];
    }
}

export function saveSettings(editor) {
    localStorage.setItem('lumina_settings', JSON.stringify(editor.settings));
    toggleStickyState(editor);
    applyDarkEditorMode(editor);
}

export function applyDarkEditorMode(editor) {
    if (!editor.editor) return;
    if (editor.settings.darkEditorMode) {
        editor.editor.classList.add('lumina-editor-dark-mode');
    } else {
        editor.editor.classList.remove('lumina-editor-dark-mode');
    }
}

export function toggleStickyState(editor) {
    if (!editor.container) return;

    if (editor.disableSticky) {
        editor.container.classList.remove('lumina-sticky-mode');
        return;
    }

    if (editor.settings.stickyToolbar) {
        editor.container.classList.add('lumina-sticky-mode');
        // Calculate sticky top offset based on admin topbar height
        updateStickyTop(editor);
    } else {
        editor.container.classList.remove('lumina-sticky-mode');
    }
}

function updateStickyTop(editor) {
    if (editor.container.classList.contains('lumina-fullscreen')) {
        editor.container.style.setProperty('--lumina-sticky-top', '0px');
        return;
    }
    // The admin topbar is position:sticky top:0 — it stays at the very top.
    // Our toolbar should stick right below it.
    var topbar = document.querySelector('.aicms-topbar');
    var top = 0;
    if (topbar) {
        top = topbar.offsetHeight;
    }
    editor.container.style.setProperty('--lumina-sticky-top', top + 'px');
    // Also listen to window resize to recalculate
    if (!editor._stickyResizeListener) {
        editor._stickyResizeListener = function() { updateStickyTop(editor); };
        window.addEventListener('resize', editor._stickyResizeListener);
    }
}

export function resetToolbarToNormal(editor) {
    // No-op in new CSS sticky architecture
}

export function handleWindowResize(editor) {
   // No-op
}

export function handleScroll(editor) {
    // No-op
}
