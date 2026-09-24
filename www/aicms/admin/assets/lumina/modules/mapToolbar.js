import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';

export function initMapToolbar(editor) {
    editor.mapToolbar = document.createElement('div');
    editor.mapToolbar.className = 'lumina-map-toolbar';
    editor.mapToolbar.style.display = 'none';
    editor.mapToolbar.innerHTML = `
        <div class="lbt-row" style="margin-bottom:0; gap:5px;">
            <span style="font-size:16px;">📍</span>
            <input type="text" class="lmt-input" placeholder="Enter location..." style="width:200px;border:1px solid #ccc;border-radius:4px;padding:4px;font-size:12px;">
            <button type="button" class="lumina-btn-primary lmt-update" style="padding:4px 8px;font-size:11px;">Update</button>
        </div>
    `;
    document.body.appendChild(editor.mapToolbar);

    const button = editor.mapToolbar.querySelector('.lmt-update');
    const input = editor.mapToolbar.querySelector('.lmt-input');

    button.onclick = (event) => {
        event.stopPropagation();
        if (!editor.selectedBlock) return;
        const iframe = editor.selectedBlock.querySelector('iframe');
        if (!iframe) return;
        const query = input.value;
        if (!query) return;
        iframe.src = `https://maps.google.com/maps?q=${encodeURIComponent(query)}&t=&z=13&ie=UTF8&iwloc=&output=embed`;
        editor.sync();
    };

    editor.mapToolbar.addEventListener('click', (event) => event.stopPropagation());
}

export function checkMapSelection(editor) {
    if (!editor.mapToolbar) return;
    if (editor.selectedBlock && editor.selectedBlock.querySelector('iframe[src*="maps"]')) {
        const rect = editor.selectedBlock.getBoundingClientRect();
        editor.mapToolbar.style.display = 'flex';
        smartPosition(editor, editor.mapToolbar, rect, 'top', collectVisiblePanels(editor, editor.mapToolbar));
    } else {
        editor.mapToolbar.style.display = 'none';
    }
}
