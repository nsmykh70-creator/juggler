import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';
import { saveToggleSettingsFromButton } from './toggleButtonCreator.js?v=20260901';

const rgbToHex = (color) => {
    if (!color || color === 'transparent') return '#000000';
    if (color.startsWith('#')) return color;
    const match = color.match(/\d+/g);
    if (!match) return '#000000';
    return `#${((1 << 24) + (parseInt(match[0]) << 16) + (parseInt(match[1]) << 8) + parseInt(match[2])).toString(16).slice(1)}`;
};

export function initButtonToolbar(editor) {
    editor.btnToolbar = document.createElement('div');
    editor.btnToolbar.className = 'lumina-button-toolbar';
    editor.btnToolbar.style.display = 'none';
    
    // No inline color/background — these would override dark-theme CSS rules.
    // The .lumina-button-toolbar input/select rules in styles.js handle both
    // light and dark themes. Only layout-related inline styles here.
    const inputStyle = 'border: 1px solid #cbd5e1; border-radius: 4px; padding: 4px;';
    const numberStyle = inputStyle + ' width: 60px;';

    editor.btnToolbar.innerHTML = `
        <div class="lbt-row"><label>Text</label><input type="text" class="lbt-text lumina-modal-input" style="${inputStyle} width:100%;"></div>
        <div class="lbt-row" id="lbt-url-row"><label>URL</label><input type="text" class="lbt-url lumina-modal-input" style="${inputStyle} width:100%;"></div>
        <div class="lbt-row" id="lbt-custom-row"><label>Custom Code <small class="lumina-settings-hint-inline">(onclick, data-*)</small></label><input type="text" class="lbt-custom lumina-modal-input" style="${inputStyle} width:100%;" placeholder='onclick="..." data-id="5"'></div>
        <div class="lbt-row"><label>Size (px)</label><input type="number" class="lbt-size lumina-modal-input" style="${numberStyle}"></div>
        <div class="lbt-row"><label>Pad (px)</label><input type="number" class="lbt-pad lumina-modal-input" style="${numberStyle}"></div>
        <div class="lbt-row"><label>Margin (px)</label><input type="number" class="lbt-margin lumina-modal-input" style="${numberStyle}"></div>
        <div class="lbt-row"><label>BG Color</label><input type="color" class="lbt-bg"></div>
        <div class="lbt-row"><label>Text Color</label><input type="color" class="lbt-color"></div>
        <div class="lbt-actions">
            <button type="button" class="lbt-update">Update</button>
            <button type="button" class="lbt-delete">Delete</button>
            <button type="button" class="lbt-close">Close</button>
        </div>
    `;
    document.body.appendChild(editor.btnToolbar);

    editor.btnToolbar.querySelector('.lbt-close').onclick = (event) => {
        event.stopPropagation();
        hideButtonToolbar(editor);
    };

    editor.btnToolbar.querySelector('.lbt-delete').onclick = (event) => {
        event.stopPropagation();
        if (editor.selectedButton) {
            // If this is a Lumina toggle button, remove the entire
            // .lumina-toggle-block wrapper (not just the button inside).
            // Otherwise the empty <div data-lumina-toggle=""></div> stays
            // in the content — invisible but still there.
            var toggleWrapper = editor.selectedButton.closest('[data-lumina-toggle]');
            if (toggleWrapper) {
                toggleWrapper.remove();
            } else {
                editor.selectedButton.remove();
            }
            hideButtonToolbar(editor);
            editor.sync();
        }
    };

    editor.btnToolbar.querySelector('.lbt-update').onclick = (event) => {
        event.stopPropagation();
        if (!editor.selectedButton) return;
        const toolbar = editor.btnToolbar;

        // Update Text
        const newText = toolbar.querySelector('.lbt-text').value;
        editor.selectedButton.innerText = newText;

        // [LUMINA TOGGLE] In the editor, the toggle button always shows the
        // "collapsed" label ("Читать далее") because the content below is
        // always visible for editing. So we sync the visible text with
        // data-text-collapsed so the frontend shows the correct label.
        if (editor.selectedButton.classList.contains('lumina-toggle-btn')) {
            editor.selectedButton.setAttribute('data-text-collapsed', newText);
        }

        // Get URL from the field
        const urlValue = toolbar.querySelector('.lbt-url').value.trim();

        // If the selected element is a <button> and a URL was entered,
        // convert it to an <a> tag so the link works on the frontend.
        if (editor.selectedButton.tagName === 'BUTTON' && urlValue && urlValue !== '#') {
            const oldBtn = editor.selectedButton;
            const newLink = document.createElement('a');
            newLink.href = urlValue;
            // Copy all attributes (class, style, data-*, etc.)
            for (const attr of oldBtn.attributes) {
                if (attr.name !== 'type') {  // skip type="button"
                    newLink.setAttribute(attr.name, attr.value);
                }
            }
            newLink.innerText = oldBtn.innerText;
            oldBtn.parentNode.replaceChild(newLink, oldBtn);
            editor.selectedButton = newLink;
        } else if (editor.selectedButton.tagName === 'A') {
            // Update href on existing <a>
            editor.selectedButton.href = urlValue || '#';
        } else if (editor.selectedButton.tagName === 'BUTTON' && !urlValue) {
            // <button> without URL — keep as button, apply custom code
        }

        // Apply custom code (onclick, data-*, etc.) — parse the string as
        // HTML attributes and apply them to the button element.
        const customCode = toolbar.querySelector('.lbt-custom').value.trim();
        if (customCode) {
            // Parse attributes from the custom code string
            const div = document.createElement('div');
            div.innerHTML = '<button ' + customCode + '></button>';
            const parsedBtn = div.querySelector('button');
            if (parsedBtn) {
                // Copy all attributes from the parsed button to the selected button
                for (const attr of parsedBtn.attributes) {
                    // Skip 'style' to avoid overwriting our style fields
                    if (attr.name !== 'style') {
                        editor.selectedButton.setAttribute(attr.name, attr.value);
                    }
                }
            }
        }

        editor.selectedButton.style.backgroundColor = toolbar.querySelector('.lbt-bg').value;
        editor.selectedButton.style.color = toolbar.querySelector('.lbt-color').value;
        const size = toolbar.querySelector('.lbt-size').value;
        const pad = toolbar.querySelector('.lbt-pad').value;
        const margin = toolbar.querySelector('.lbt-margin').value;

        editor.selectedButton.style.fontSize = size + 'px';
        editor.selectedButton.style.padding = `${pad}px ${parseInt(pad || 0, 10) * 2}px`;
        editor.selectedButton.style.margin = margin + 'px';

        // If this is a toggle button, save its settings to localStorage
        // so the next inserted toggle button uses the same styling.
        if (editor.selectedButton.classList.contains('lumina-toggle-btn')) {
            saveToggleSettingsFromButton(editor.selectedButton);
        }

        hideButtonToolbar(editor);
        editor.sync();
    };
}

export function showButtonToolbar(editor, button) {
    if (!editor.btnToolbar) return;
    editor.selectedButton = button;
    const toolbar = editor.btnToolbar;

    toolbar.querySelector('.lbt-text').value = button.innerText;

    // ALWAYS show the URL field — for both <a> and <button> tags.
    // For <a>: the URL is the href.
    // For <button>: if the user enters a URL and clicks Update, the <button>
    //   is automatically converted to an <a> tag (see update handler).
    const urlRow = toolbar.querySelector('#lbt-url-row');
    const customRow = toolbar.querySelector('#lbt-custom-row');
    urlRow.style.display = 'flex';

    // Populate URL field
    if (button.tagName === 'A') {
        toolbar.querySelector('.lbt-url').value = button.getAttribute('href') || '';
    } else {
        // For <button>, check if there's a data-href attribute (from a previous conversion attempt)
        toolbar.querySelector('.lbt-url').value = button.getAttribute('data-href') || '';
    }

    // Show Custom Code field for <button> tags (for onclick, data-*, etc.)
    // For <a> tags, also show it (so users can add data-* attributes to links too)
    customRow.style.display = 'flex';

    // Extract existing custom attributes (onclick, data-*, etc.) —
    // everything except style, class, href, and lumina-specific attributes
    const skipAttrs = ['style', 'class', 'contenteditable', 'type', 'href',
                       'data-text-collapsed', 'data-text-expanded',
                       'data-lumina-toggle', 'data-href'];
    const customParts = [];
    for (const attr of button.attributes) {
        if (skipAttrs.indexOf(attr.name) === -1) {
            customParts.push(attr.name + '="' + attr.value.replace(/"/g, '&quot;') + '"');
        }
    }
    toolbar.querySelector('.lbt-custom').value = customParts.join(' ');

    const styles = window.getComputedStyle(button);
    toolbar.querySelector('.lbt-size').value = parseInt(styles.fontSize) || 14;
    toolbar.querySelector('.lbt-pad').value = parseInt(styles.paddingTop) || 10;
    toolbar.querySelector('.lbt-margin').value = parseInt(styles.marginTop) || 0;
    toolbar.querySelector('.lbt-bg').value = rgbToHex(styles.backgroundColor);
    toolbar.querySelector('.lbt-color').value = rgbToHex(styles.color);

    const rect = button.getBoundingClientRect();
    toolbar.style.display = 'block';

    smartPosition(editor, toolbar, rect, 'top', collectVisiblePanels(editor, toolbar));
}

export function hideButtonToolbar(editor) {
    if (!editor.btnToolbar) return;
    editor.btnToolbar.style.display = 'none';
    editor.selectedButton = null;
}
