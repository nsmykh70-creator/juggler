export function openBlockLibrary(editor) {
    // CRITICAL: Save the current selection BEFORE opening the modal.
    // The modal will steal focus, so we must remember the cursor position
    // here and restore it right before inserting the HTML.
    editor.saveSelection();

    const categories = ['All', 'Content', 'Marketing', 'Commerce', 'Media', 'Data', 'Structure'];
    let tabHtml = '<div class="lumina-lib-tabs">';
    categories.forEach((cat, index) => {
        tabHtml += `<button class="lumina-lib-tab ${index === 0 ? 'active' : ''}" data-cat="${cat}">${cat}</button>`;
    });
    tabHtml += '</div>';

    // Animation selector — choose how the inserted block appears.
    // Options match the @keyframes defined in styles.js.
    // All visual styling is in CSS (.lumina-lib-anim-bar) — no inline
    // color/background, so dark-theme rules in styles.js apply correctly.
    const animBarHtml = `
        <div class="lumina-lib-anim-bar">
            <label class="lumina-lib-anim-label">Animation:</label>
            <select class="lbi-anim lumina-select">
                <option value="">None (instant)</option>
                <option value="lumina-anim-fade" selected>Fade In</option>
                <option value="lumina-anim-slide-left">Slide from Left</option>
                <option value="lumina-anim-slide-right">Slide from Right</option>
                <option value="lumina-anim-slide">Slide Up</option>
                <option value="lumina-anim-slide-down">Slide Down</option>
                <option value="lumina-anim-zoom-in">Zoom In</option>
                <option value="lumina-anim-flip">Flip</option>
                <option value="lumina-anim-bounce">Bounce</option>
            </select>
            <span class="lumina-lib-anim-hint">Applies on insert &amp; on page load</span>
        </div>
    `;

    const bodyHtml = '<div class="lumina-lib-content"><div class="lumina-block-grid"></div></div>';
    const modalBody = editor.createModalOverlay('Layout Library', animBarHtml + tabHtml + bodyHtml);
    const grid = modalBody.querySelector('.lumina-block-grid');

    const render = (category) => {
        const filtered = category === 'All'
            ? editor.blockTemplates
            : editor.blockTemplates.filter((tpl) => tpl.category === category);
        let html = '';
        filtered.forEach((tpl) => {
            html += `
                <div class="lumina-block-item" data-id="${tpl.name}">
                    <div class="lbi-header">
                        <div class="lbi-icon">${editor.getIcon(tpl.icon || 'layout')}</div>
                        <small class="lbi-category">${tpl.category}</small>
                    </div>
                    <h4>${tpl.name}</h4>
                    <p class="lbi-desc">${tpl.desc || ''}</p>
                </div>
            `;
        });
        grid.innerHTML = html;
        grid.querySelectorAll('.lumina-block-item').forEach((item) => {
            item.onclick = () => {
                const template = editor.blockTemplates.find((t) => t.name === item.dataset.id);
                if (template) {
                    // Restore the selection that was saved BEFORE the modal opened.
                    // This makes the block insert at the cursor's last known position
                    // instead of the beginning of the page.
                    editor.editor.focus();
                    editor.restoreSelection();

                    var animType = modalBody.querySelector('.lbi-anim').value;
                    var htmlToInsert = template.html;

                    if (animType) {
                        // Inject animation class on the first .lumina-layout-block wrapper.
                        // IMPORTANT: do NOT add a closing quote — the existing class
                        // attribute continues with the Tailwind utility classes that
                        // follow "lumina-layout-block". Adding " here would prematurely
                        // close the attribute and break all subsequent classes (bg-*,
                        // grid, md:grid-cols-2, etc.), which is what caused templates
                        // to render unstyled.
                        htmlToInsert = htmlToInsert.replace(
                            /<div class="lumina-layout-block /,
                            '<div class="lumina-layout-block ' + animType + ' '
                        );
                    }

                    document.execCommand('insertHTML', false, htmlToInsert);
                    modalBody.closest('.lumina-modal-overlay').remove();
                    editor.sync();
                    editor.pushHistory(true);
                }
            };
        });
        if (!filtered.length) {
            grid.innerHTML = '<div class="lumina-block-empty">No blocks found.</div>';
        }
    };

    render('All');
    modalBody.querySelectorAll('.lumina-lib-tab').forEach((tab) => {
        tab.onclick = () => {
            modalBody.querySelectorAll('.lumina-lib-tab').forEach((btn) => btn.classList.remove('active'));
            tab.classList.add('active');
            render(tab.dataset.cat);
        };
    });
}
