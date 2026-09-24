/**
 * Toggle Button Module for Lumina Editor
 *
 * Inserts a "Читать далее / Свернуть" toggle button at the current cursor
 * position — immediately, without any modal dialog.
 *
 * The button remembers its last-used styling (colors, radius, shadow, etc.)
 * via localStorage. When you insert a new toggle button on another page/news,
 * it will use the same styling as the last one you configured.
 *
 * The button does NOT create a separate content block. Instead, it marks a
 * "toggle point" in the document. On the frontend, the global runtime
 * (assets/js/lumina-toggle.js) automatically collects all content that
 * FOLLOWS the button (until the next toggle button or end of parent) and
 * wraps it in a hidden .lumina-toggle-content div. Clicking the button
 * reveals / hides that content.
 *
 * In the editor, the following content remains fully visible and editable.
 */

const STORAGE_KEY = 'lumina_toggle_button_settings';

/**
 * Load saved toggle button settings from localStorage.
 * Returns an object with style properties, or defaults if nothing saved.
 */
function loadToggleSettings() {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) return JSON.parse(saved);
    } catch (e) {}
    // Defaults
    return {
        bgColor: '#2563eb',
        textColor: '#ffffff',
        fontSize: '14',
        padding: '10',
        margin: '0',
        borderRadius: '8',
        shadowPreset: 'sm',
        shadowColor: '#000000'
    };
}

/**
 * Save toggle button settings to localStorage so the next insertion
 * uses the same styling.
 */
function saveToggleSettings(settings) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch (e) {}
}

/**
 * Build a CSS box-shadow string from a preset + color.
 */
function buildShadow(preset, color) {
    if (!color) color = '#000000';
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    const c = (alpha) => `rgba(${r}, ${g}, ${b}, ${alpha})`;
    const shadows = {
        none: 'none',
        sm:   c('0.15') + ' 0px 2px 4px 0px',
        md:   c('0.2')  + ' 0px 6px 12px -2px, ' + c('0.12') + ' 0px 3px 6px -2px',
        lg:   c('0.25') + ' 0px 14px 24px -4px, ' + c('0.15') + ' 0px 6px 10px -3px'
    };
    return shadows[preset] || shadows.sm;
}

export function insertToggleButton(editor) {
    editor.saveSelection();
    editor.restoreSelection();

    // Load last-used settings
    const s = loadToggleSettings();

    // Build the inline style string from saved settings
    const padV = parseInt(s.padding, 10) || 10;
    const padH = Math.round(padV * 2);
    const style = `display:inline-block; background-color:${s.bgColor}; color:${s.textColor}; border-radius:${s.borderRadius}px; padding:${padV}px ${padH}px; margin:${s.margin}px; font-size:${s.fontSize}px; box-shadow:${buildShadow(s.shadowPreset, s.shadowColor)}; border:none; line-height:1.2; font-weight:500; cursor:pointer; transition: all 0.1s ease-out, box-shadow 0.15s ease-out, filter 0.1s ease-out;`;

    const html =
        '<div class="lumina-toggle-block" data-lumina-toggle>' +
            '<button type="button" ' +
                'class="btn btn-primary btn-sm toggle-news-btn lumina-toggle-btn" ' +
                'data-text-collapsed="Читать далее" ' +
                'data-text-expanded="Свернуть" ' +
                'style="' + style + '" ' +
                'contenteditable="false">Читать далее</button>' +
        '</div>';

    document.execCommand('insertHTML', false, html);
    editor.sync();
    editor.pushHistory(true);
}

/**
 * Extract settings from a toggle button element (after user edited it
 * via the button toolbar) and save them to localStorage.
 * Called from buttonToolbar.js when the user clicks "Update" on a
 * toggle button.
 */
export function saveToggleSettingsFromButton(buttonEl) {
    const styles = window.getComputedStyle(buttonEl);
    const settings = {
        bgColor: rgbToHex(styles.backgroundColor),
        textColor: rgbToHex(styles.color),
        fontSize: String(parseInt(styles.fontSize) || 14),
        padding: String(parseInt(styles.paddingTop) || 10),
        margin: String(parseInt(styles.marginTop) || 0),
        borderRadius: String(parseInt(styles.borderTopLeftRadius) || 0),
        shadowPreset: detectShadowPreset(styles.boxShadow),
        shadowColor: extractShadowColor(styles.boxShadow) || '#000000'
    };
    saveToggleSettings(settings);
}

function rgbToHex(color) {
    if (!color || color === 'transparent') return '#000000';
    if (color.startsWith('#')) return color;
    const match = color.match(/\d+/g);
    if (!match) return '#000000';
    return `#${((1 << 24) + (parseInt(match[0]) << 16) + (parseInt(match[1]) << 8) + parseInt(match[2])).toString(16).slice(1)}`;
}

function detectShadowPreset(shadow) {
    if (!shadow || shadow === 'none') return 'none';
    const sig = shadow.toLowerCase()
        .replace(/rgba\([^)]+\)/g, 'C')
        .replace(/\s+/g, '')
        .replace(/px/g, '');
    const presets = {
        'sm': 'C0240',
        'md': 'C0612-2,C036-2',
        'lg': 'C01424-4,C0610-3'
    };
    for (const [key, val] of Object.entries(presets)) {
        if (sig === val) return key;
    }
    return 'sm';
}

function extractShadowColor(shadow) {
    if (!shadow || shadow === 'none') return null;
    const m = shadow.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (!m) return null;
    const r = parseInt(m[1]), g = parseInt(m[2]), b = parseInt(m[3]);
    return '#' + [r, g, b].map(n => n.toString(16).padStart(2, '0')).join('');
}

export default { insertToggleButton, saveToggleSettingsFromButton };
