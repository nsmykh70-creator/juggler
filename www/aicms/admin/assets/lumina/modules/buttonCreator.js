export function openButtonCreatorModal(editor) {
    editor.saveSelection(); // Save current position (e.g. inside box)

    // Use CSS classes for color/background so dark-theme rules apply.
    // Inline color:#000 / background:#fff would override the dark-theme CSS
    // and cause invisible text on the white-on-white items.
    const labelStyle = 'display:block; font-size:11px; font-weight:700; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;';
    const inputStyle = 'width:100%; border:1px solid #e2e8f0; border-radius:6px; padding:8px 10px; font-size:13px; box-sizing:border-box; outline:none; transition: border-color 0.2s;';
    const colorInputStyle = 'width:60px; height:34px; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; padding:2px; box-sizing:border-box;';

    const content = `
        <div class="lumina-btn-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 4px;">
            <div style="grid-column: span 2;">
                <label class="lumina-settings-label" style="${labelStyle}">Тип кнопки / Button Type</label>
                <select id="bc-type" class="lumina-modal-input" style="${inputStyle} height:34px; cursor:pointer;">
                    <option value="link">Ссылка (Link) — переход на URL</option>
                    <option value="button">Кнопка (Button) — без перехода, для кастомного кода</option>
                    <option value="custom">Произвольный код (Custom Code) — полный HTML/JS</option>
                </select>
            </div>

            <div style="grid-column: span 2;">
                <label class="lumina-settings-label" style="${labelStyle}">Текст кнопки / Button Text</label>
                <input type="text" id="bc-text" value="Click Here" class="lumina-modal-input" style="${inputStyle}" placeholder="e.g. Learn More">
            </div>

            <div style="grid-column: span 2;" id="bc-url-row">
                <label class="lumina-settings-label" style="${labelStyle}">Link URL <span class="lumina-settings-hint-inline">(только для типа «Ссылка»)</span></label>
                <input type="text" id="bc-url" value="#" class="lumina-modal-input" style="${inputStyle}" placeholder="https://... или ?page=shop">
            </div>

            <div style="grid-column: span 2; display:none;" id="bc-custom-row">
                <label class="lumina-settings-label" style="${labelStyle}">Произвольный код / Custom Code <span class="lumina-settings-hint-inline">(onclick, data-атрибуты, inline JS)</span></label>
                <textarea id="bc-custom" rows="4" class="lumina-modal-input" style="${inputStyle} font-family:'Fira Code',monospace; font-size:12px; resize:vertical;" placeholder="Например: onclick=&quot;alert('Hello!')&quot; data-product-id=&quot;5&quot;"></textarea>
                <p class="lumina-settings-hint" style="margin-top:4px;">Этот код будет добавлен внутрь тега &lt;button&gt; как HTML-атрибуты. Для полного HTML выберите тип «Произвольный код».</p>
            </div>

            <div style="grid-column: span 2; display:none;" id="bc-fullcode-row">
                <label class="lumina-settings-label" style="${labelStyle}">Полный HTML код / Full HTML Code</label>
                <textarea id="bc-fullcode" rows="6" class="lumina-modal-input" style="${inputStyle} font-family:'Fira Code',monospace; font-size:12px; resize:vertical;" placeholder="<button onclick=&quot;myFunction()&quot; style=&quot;...&quot;>Текст</button>&#10;или: <form action=&quot;...&quot; method=&quot;POST&quot;><button>Купить</button></form>"></textarea>
                <p class="lumina-settings-hint" style="margin-top:4px;">Этот HTML будет вставлен как есть. Стили из полей ниже не применяются.</p>
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Background</label>
                <input type="color" id="bc-bg" value="#2563eb" style="${colorInputStyle}">
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Text Color</label>
                <input type="color" id="bc-color" value="#ffffff" style="${colorInputStyle}">
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Font Size</label>
                <input type="number" id="bc-size" value="14" class="lumina-modal-input" style="${inputStyle}">
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Border Radius</label>
                <input type="number" id="bc-radius" value="6" class="lumina-modal-input" style="${inputStyle}">
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Padding (Vertical)</label>
                <input type="number" id="bc-pad" value="10" class="lumina-modal-input" style="${inputStyle}">
            </div>

            <div>
                <label class="lumina-settings-label" style="${labelStyle}">Margin</label>
                <input type="number" id="bc-margin" value="5" class="lumina-modal-input" style="${inputStyle}">
            </div>

            <div style="grid-column: span 2;">
                <label class="lumina-settings-label" style="${labelStyle}">Shadow</label>
                <select id="bc-shadow" class="lumina-modal-input" style="${inputStyle} height:34px; cursor:pointer;">
                    <option value="none">Нет</option>
                    <option value="0 1px 2px rgba(0,0,0,0.1)">Маленькая</option>
                    <option value="0 4px 6px rgba(0,0,0,0.1)">Средняя</option>
                    <option value="0 10px 15px rgba(0,0,0,0.1)">Большая</option>
                </select>
            </div>

            <div style="grid-column:span 2; margin-top:8px; display:flex; align-items:center;" id="bc-target-row">
                <input type="checkbox" id="bc-target" style="width:16px; height:16px; margin-right:8px; cursor:pointer; accent-color:#2563eb;">
                <label for="bc-target" class="lumina-settings-label" style="font-size:13px; cursor:pointer; user-select:none;">Открыть в новой вкладке</label>
            </div>
        </div>
    `;

    const body = editor.createModalOverlay('Create Button', content, [{
        text: 'Insert Button',
        onClick: (event, modal) => {
            const type = modal.querySelector('#bc-type').value;
            const txt = modal.querySelector('#bc-text').value;

            // Type: Custom Code — insert raw HTML as-is
            if (type === 'custom') {
                const fullCode = modal.querySelector('#bc-fullcode').value;
                editor.restoreSelection();
                document.execCommand('insertHTML', false, fullCode);
                modal.closest('.lumina-modal-overlay').remove();
                editor.sync();
                return;
            }

            const bg = modal.querySelector('#bc-bg').value;
            const col = modal.querySelector('#bc-color').value;
            const rad = modal.querySelector('#bc-radius').value;
            const size = modal.querySelector('#bc-size').value;
            const pad = modal.querySelector('#bc-pad').value;
            const margin = modal.querySelector('#bc-margin').value;
            const shadow = modal.querySelector('#bc-shadow').value;

            // Calculate horizontal padding relative to vertical for balance
            const padV = parseInt(pad, 10) || 10;
            const padH = Math.round(padV * 2.2);
            const marg = parseInt(margin, 10) || 0;

            const style = `background-color:${bg}; color:${col}; border-radius:${rad}px; padding:${padV}px ${padH}px; margin:${marg}px; font-size:${size}px; box-shadow:${shadow}; display:inline-block; text-decoration:none; text-align:center; transition: all 0.2s; border:none; line-height: 1.2; font-weight: 500; cursor: pointer;`;

            if (type === 'link') {
                const url = modal.querySelector('#bc-url').value;
                const target = modal.querySelector('#bc-target').checked ? '_blank' : '_self';
                const html = `<a href="${url}" target="${target}" class="lumina-action-btn lumina-custom-btn" style="${style}">${txt}</a>`;
                editor.restoreSelection();
                document.execCommand('insertHTML', false, html);
            } else if (type === 'button') {
                // Button with custom attributes (onclick, data-*, etc.)
                const customCode = modal.querySelector('#bc-custom').value.trim();
                const html = `<button type="button" class="lumina-action-btn lumina-custom-btn" style="${style}"${customCode ? ' ' + customCode : ''}>${txt}</button>`;
                editor.restoreSelection();
                document.execCommand('insertHTML', false, html);
            }

            modal.closest('.lumina-modal-overlay').remove();
            editor.sync();
        }
    }]);

    // Show/hide fields based on button type
    const typeSelect = body.querySelector('#bc-type');
    const urlRow = body.querySelector('#bc-url-row');
    const customRow = body.querySelector('#bc-custom-row');
    const fullCodeRow = body.querySelector('#bc-fullcode-row');
    const targetRow = body.querySelector('#bc-target-row');
    // Style fields container (all the color/size/padding fields)
    const styleFields = body.querySelectorAll('.lumina-btn-grid > div:not(#bc-type):not(#bc-text):not(#bc-url-row):not(#bc-custom-row):not(#bc-fullcode-row):not(#bc-target-row)');

    function updateFields() {
        const t = typeSelect.value;
        urlRow.style.display = (t === 'link') ? 'block' : 'none';
        customRow.style.display = (t === 'button') ? 'block' : 'none';
        fullCodeRow.style.display = (t === 'custom') ? 'block' : 'none';
        targetRow.style.display = (t === 'link') ? 'flex' : 'none';
        // Hide style fields for "custom" type (full code mode)
        styleFields.forEach(f => { f.style.display = (t === 'custom') ? 'none' : 'block'; });
    }
    typeSelect.onchange = updateFields;
    updateFields();
}
