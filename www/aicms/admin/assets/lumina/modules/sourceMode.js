function formatHTML(html) {
    let formatted = '';
    let pad = 0;
    html = html.replace(/(>)(<)(\/*)/g, '$1\r\n$2$3');
    html.split('\r\n').forEach(node => {
        let indent = 0;
        if (node.match(/.+<\/\w[^>]*>$/)) indent = 0;
        else if (node.match(/^<\/\w/)) { if (pad !== 0) pad -= 1; }
        else if (node.match(/^<\w[^>]*[^\/]>.*$/)) indent = 1;
        else indent = 0;
        const padding = new Array(pad + 1).join('  ');
        formatted += padding + node + '\r\n';
        pad += indent;
    });
    return formatted.trim();
}

function loadThemeCss(theme) {
    if (theme === 'default') return;
    const id = `cm-theme-${theme}`;
    if (!document.getElementById(id)) {
        const link = document.createElement('link');
        link.id = id;
        link.rel = 'stylesheet';
        link.href = `https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/${theme}.min.css`;
        document.head.appendChild(link);
    }
}

function compressImagesForSource(editor, html) {
    editor.imageMap = editor.imageMap || {};
    return html.replace(/src="(data:image\/[^;]+;base64,[^"]+)"/g, (match, data) => {
        let hash = 0;
        for (let i = 0; i < data.length; i++) {
            hash = ((hash << 5) - hash) + data.charCodeAt(i);
            hash |= 0;
        }
        const key = `LUMINA_IMG_${Math.abs(hash)}`;
        editor.imageMap[key] = data;
        return `src="${key}" data-lumina-compressed="true"`;
    });
}

function decompressImagesForVisual(editor, html) {
    if (!editor.imageMap) return html;
    return html.replace(/src="(LUMINA_IMG_\d+)" data-lumina-compressed="true"|src="(LUMINA_IMG_\d+)"/g, (match, key1, key2) => {
        return `src="${editor.imageMap[key1 || key2] || ''}"`;
    });
}

export function loadCodeMirrorResources() {
    if (window.CodeMirror) return;

    const loadCSS = (href) => {
        if (document.querySelector(`link[href="${href}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    };

    const loadJS = (src) => {
        return new Promise((resolve) => {
            if (document.querySelector(`script[src="${src}"]`)) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            document.head.appendChild(script);
        });
    };

    loadCSS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css');
    loadCSS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/monokai.min.css');

    loadJS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js')
        .then(() => loadJS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/xml/xml.min.js'))
        .then(() => loadJS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js'))
        .then(() => loadJS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/css/css.min.js'))
        .then(() => loadJS('https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/htmlmixed/htmlmixed.min.js'))
        .then(() => console.log('LuminaEditor: CodeMirror loaded'));
}

export function toggleSource(editor) {
    editor.isSourceMode = !editor.isSourceMode;
    if (editor.isSourceMode) {
        let html = editor.editor.innerHTML;
        html = compressImagesForSource(editor, html);
        html = formatHTML(html);

        editor.sourceWrapper.style.display = 'flex';
        editor.editor.style.display = 'none';

        const initCM = () => {
             if (window.CodeMirror) {
                const host = editor.sourceWrapper.querySelector('.lumina-cm-host');
                host.innerHTML = '';
                
                const themeSel = editor.sourceWrapper.querySelector('.lst-theme');
                const sizeSel = editor.sourceWrapper.querySelector('.lst-size');

                if(themeSel) themeSel.onchange = (e) => {
                    loadThemeCss(e.target.value);
                    if(editor.cm) editor.cm.setOption('theme', e.target.value);
                };
                
                if(sizeSel) sizeSel.onchange = (e) => {
                   host.querySelector('.CodeMirror').style.fontSize = e.target.value;
                   if(editor.cm) editor.cm.refresh();
                };

                editor.cm = window.CodeMirror(host, {
                    mode: 'htmlmixed',
                    theme: 'monokai',
                    lineNumbers: true,
                    lineWrapping: true,
                    value: html,
                    viewportMargin: Infinity
                });
                editor.cm.on('change', () => editor.sync());
                setTimeout(() => { editor.cm.refresh(); initMinimap(editor); }, 50);
            }
        };

        if (!window.CodeMirror) {
             const host = editor.sourceWrapper.querySelector('.lumina-cm-host');
             host.innerHTML = '<div style="color:#fff;padding:20px;">Loading Source Editor...</div>';
             loadCodeMirrorResources();
             const check = setInterval(() => {
                 if(window.CodeMirror) {
                     clearInterval(check);
                     initCM();
                 }
             }, 100);
        } else if (!editor.cm) {
            initCM();
        } else {
            editor.cm.setValue(html);
            setTimeout(() => editor.cm.refresh(), 50);
        }
    } else {
        let codeVal = '';
        if (editor.cm) {
            codeVal = editor.cm.getValue();
        } else if (editor.sourceTextarea) {
            codeVal = editor.sourceTextarea.value;
        } else {
            codeVal = editor.editor.innerHTML; 
        }

        editor.editor.innerHTML = decompressImagesForVisual(editor, codeVal);
        editor.editor.style.display = 'block';
        editor.sourceWrapper.style.display = 'none';
        editor.pushHistory();
    }
}

// === Minimap for source code mode ===
function initMinimap(editor) {
    if (!editor.cm) return;
    var host = editor.sourceWrapper.querySelector('.lumina-cm-host');
    if (!host) return;

    // Remove existing minimap
    var existing = host.querySelector('.lumina-minimap');
    if (existing) existing.remove();

    // Create minimap container
    var minimap = document.createElement('div');
    minimap.className = 'lumina-minimap';
    minimap.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:60px;background:#1a1a1a;border-left:1px solid #333;overflow:hidden;pointer-events:auto;z-index:5;';

    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'width:60px;height:100%;cursor:pointer;';
    minimap.appendChild(canvas);

    var viewport = document.createElement('div');
    viewport.className = 'lumina-minimap-viewport';
    viewport.style.cssText = 'position:absolute;left:0;right:0;background:rgba(37,99,235,0.2);border:1px solid rgba(37,99,235,0.5);pointer-events:none;';
    minimap.appendChild(viewport);

    host.appendChild(minimap);

    var cmEl = host.querySelector('.CodeMirror');
    if (cmEl) cmEl.style.paddingRight = '60px';

    function drawMinimap() {
        var ctx = canvas.getContext('2d');
        var lines = editor.cm.lineCount();
        var cmHeight = cmEl ? cmEl.scrollHeight : 0;
        var mmWidth = 60;
        var mmHeight = host.offsetHeight;

        canvas.width = mmWidth;
        canvas.height = mmHeight;

        ctx.fillStyle = '#1a1a1a';
        ctx.fillRect(0, 0, mmWidth, mmHeight);

        // Scale: how many pixels per line in minimap
        var scale = Math.min(mmHeight / cmHeight, 1);
        if (scale < 0.01) scale = 0.01; // prevent too tiny

        // Draw lines as thin rectangles
        for (var i = 0; i < lines; i++) {
            var line = editor.cm.getLine(i);
            var y = i * 20 * scale; // ~20px per line in CM
            if (y > mmHeight) break;

            // Color based on content
            var trimmed = line.trim();
            if (trimmed === '') continue;
            if (trimmed.startsWith('<')) ctx.fillStyle = '#e6db74'; // tags - yellow
            else if (trimmed.startsWith('//') || trimmed.startsWith('/*')) ctx.fillStyle = '#75715e'; // comments - gray
            else if (trimmed.startsWith('.')) ctx.fillStyle = '#66d9ef'; // CSS class - cyan
            else if (trimmed.startsWith('#')) ctx.fillStyle = '#a6e22e'; // CSS id - green
            else ctx.fillStyle = '#f8f8f2'; // text - white

            var lineWidth = Math.min(trimmed.length * 2, mmWidth - 4);
            ctx.fillRect(2, y, lineWidth, Math.max(1, 2 * scale));
        }

        // Update viewport indicator
        var scrollInfo = editor.cm.getScrollInfo();
        var vpTop = scrollInfo.top * scale;
        var vpHeight = scrollInfo.clientHeight * scale;
        viewport.style.top = vpTop + 'px';
        viewport.style.height = vpHeight + 'px';
    }

    // Draw on scroll and change
    editor.cm.on('scroll', drawMinimap);
    editor.cm.on('change', function() { setTimeout(drawMinimap, 50); });
    setTimeout(drawMinimap, 100);

    // Click to scroll
    canvas.addEventListener('click', function(e) {
        var rect = canvas.getBoundingClientRect();
        var y = e.clientY - rect.top;
        var scale = host.offsetHeight / (cmEl ? cmEl.scrollHeight : 1);
        var targetScroll = y / scale;
        editor.cm.scrollTo(null, targetScroll);
    });

    // Redraw on resize
    if (!editor._minimapResize) {
        editor._minimapResize = function() { drawMinimap(); };
        window.addEventListener('resize', editor._minimapResize);
    }
}

export function toggleFullscreen(editor) {
    editor.isFullscreen = !editor.isFullscreen;
    if (editor.isFullscreen) {
        editor.container.classList.add('lumina-fullscreen');
        editor.editor.style.height = '100%';
        editor.sourceWrapper.style.height = '100%';
        // In fullscreen: sticky at top: 0 (no admin topbar)
        editor.container.style.setProperty('--lumina-sticky-top', '0px');
    } else {
        editor.container.classList.remove('lumina-fullscreen');
        editor.editor.style.height = editor.height;
        editor.sourceWrapper.style.height = editor.height;
        // Recalculate sticky top for normal mode (account for admin topbar)
        if (editor.toggleStickyState) editor.toggleStickyState();
    }
}

export function togglePreview(editor) {
    editor.isPreviewMode = !editor.isPreviewMode;
    if (editor.isPreviewMode) {
        editor.editor.style.display = 'none';
        editor.sourceWrapper.style.display = 'none';
        editor.previewArea.style.display = 'flex';
        // Mark wrapper as preview-active so CSS gives it a proper min-height
        if (editor.editorWrapper) editor.editorWrapper.classList.add('lumina-preview-active');

        let device = 'desktop';
        let orientation = 'portrait';
        let currentIframe = null;
        let renderedHtml = null; // cached rendered HTML (with modules expanded)
        let previewToken = null; // CSRF token for preview API

        // Get CSRF token from the page meta
        function getCsrfToken() {
            var meta = document.querySelector('meta[name="aicms-csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        // Get page ID from URL (for edit_page.php?id=N) — used for context
        function getPageId() {
            var match = window.location.search.match(/[?&]id=(\d+)/);
            return match ? match[1] : '0';
        }

        // Render content via API (expands shortcodes/modules server-side)
        function renderPreviewContent(callback) {
            if (renderedHtml !== null) {
                callback(renderedHtml);
                return;
            }
            // Show loading indicator
            if (currentIframe && currentIframe.parentNode) {
                currentIframe.parentNode.innerHTML = '<div class="lp-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview…</div>';
            }
            // Get raw HTML from editor
            var rawHtml = editor.editor.innerHTML;
            // Convert relative image paths back to site-relative for the API
            rawHtml = rawHtml.replace(/\.\.\/uploads\//g, 'uploads/');

            var fd = new FormData();
            fd.append('content', rawHtml);
            fd.append('page_id', getPageId());
            fd.append('lang', (window.ADMIN_LANG || 'ru'));
            fd.append('csrf_token', getCsrfToken());

            // Preview API path is configurable for non-AICMS deployments;
            // defaults to the AICMS /admin/ endpoint.
            var previewUrl = (editor.options && editor.options.previewUrl)
                || (window._luminaEditorConfig && window._luminaEditorConfig.preview_url)
                || 'api_preview.php';

            // Standalone / static deployments have no server preview endpoint.
            // Render the content CLIENT-SIDE in the iframe (with Tailwind CDN so
            // block-template utilities are styled) instead of firing a 404 at
            // api_preview.php. Pass previewUrl: '' (or false) from the adapter
            // to opt in; when unset, the AICMS default (api_preview.php) is used
            // exactly as before.
            if (!previewUrl) {
                renderedHtml = '<!DOCTYPE html>\n<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">' +
                    '<script src="https://cdn.tailwindcss.com"></' + 'script>' +
                    '<style>*{box-sizing:border-box;max-width:100%;}' +
                    'body{font-family:Inter,system-ui,sans-serif;color:#1e293b;margin:0;padding:16px;overflow-x:hidden;}' +
                    'img{max-width:100%;height:auto;}' +
                    'table{display:block;overflow-x:auto;}' +
                    '[style*="grid-template-columns"]{grid-template-columns:1fr !important;}' +
                    'form input,form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;}' +
                    '</style></head><body>' +
                    rawHtml +
                    '</body></html>';
                callback(renderedHtml);
                return;
            }

            fetch(previewUrl, { method: 'POST', body: fd })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(function(text) {
                    var data;
                    try { data = JSON.parse(text); }
                    catch(e) { throw new Error('Invalid JSON: ' + text.substring(0, 200)); }
                    if (data.status === 'success' && data.html) {
                        renderedHtml = data.html;
                        callback(renderedHtml);
                    } else {
                        throw new Error(data.message || 'Preview API returned no HTML');
                    }
                })
                .catch(function(err) {
                    // Fallback: render content directly with basic styling (no modules expanded)
                    // This at least shows the content instead of raw shortcode text
                    renderedHtml = '<!DOCTYPE html>\n<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><style>' +
                        '*{box-sizing:border-box;max-width:100%;}' +
                        'body{font-family:Inter,system-ui,sans-serif;color:#1e293b;margin:0;padding:16px;overflow-x:hidden;}' +
                        'img{max-width:100%;height:auto;}' +
                        'table{display:block;overflow-x:auto;}' +
                        '[style*="grid-template-columns"]{grid-template-columns:1fr !important;}' +
                        'form input,form textarea{width:100%;box-sizing:border-box;}' +
                        '.lp-error{background:#fee2e2;border:1px solid #dc2626;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;}' +
                        '</style></head><body>' +
                        '<div class="lp-error"><b>Preview API error:</b> ' + (err.message || 'unknown') +
                        '. Modules (shortcodes) are not expanded in this fallback view.</div>' +
                        rawHtml +
                        '</body></html>';
                    callback(renderedHtml);
                });
        }

        // Apply device size to the iframe container
        function applyDeviceSize() {
            var frame = editor.previewArea.querySelector('.lumina-preview-frame');
            if (!frame) return;
            frame.className = 'lumina-preview-frame';
            frame.classList.add(device);
            frame.classList.add(orientation);
            editor.previewArea.querySelectorAll('button[data-d]').forEach(b => {
                if (b.dataset.d === device) b.classList.add('active');
                else b.classList.remove('active');
            });
            const rotateBtn = editor.previewArea.querySelector('.lp-rotate');
            if (device === 'desktop') {
                rotateBtn.style.display = 'none';
            } else {
                rotateBtn.style.display = 'flex';
                rotateBtn.classList.toggle('active', orientation === 'landscape');
            }
            // Resize iframe to match device width
            if (currentIframe && currentIframe.parentNode) {
                var lpContent = currentIframe.parentNode;
                // The lp-content gets its width from CSS class; iframe fills it
                currentIframe.style.width = '100%';
                currentIframe.style.height = '100%';
            }
        }

        // Set iframe content via srcdoc
        function setIframeContent(html) {
            var frame = editor.previewArea.querySelector('.lumina-preview-frame');
            if (!frame) return;
            var lpContent = frame.querySelector('.lp-content');
            if (!lpContent) return;
            // Create or update iframe
            if (!currentIframe) {
                lpContent.innerHTML = '';
                currentIframe = document.createElement('iframe');
                currentIframe.setAttribute('sandbox', 'allow-same-origin allow-popups allow-forms allow-scripts');
                currentIframe.style.width = '100%';
                currentIframe.style.height = '100%';
                currentIframe.style.border = 'none';
                currentIframe.style.minHeight = '800px';
                currentIframe.style.display = 'block';
                currentIframe.style.background = '#fff';
                lpContent.appendChild(currentIframe);
            }
            currentIframe.srcdoc = html;
            // Auto-resize iframe height based on content
            currentIframe.onload = function() {
                try {
                    var doc = currentIframe.contentDocument || currentIframe.contentWindow.document;
                    var body = doc.body;
                    var html = doc.documentElement;
                    var height = Math.max(
                        body ? body.scrollHeight : 0,
                        body ? body.offsetHeight : 0,
                        html ? html.clientHeight : 0,
                        html ? html.scrollHeight : 0,
                        html ? html.offsetHeight : 0
                    );
                    // Set min-height based on device
                    var minH = 800;
                    if (device === 'tablet' && orientation === 'portrait') minH = 1024;
                    else if (device === 'tablet' && orientation === 'landscape') minH = 768;
                    else if (device === 'mobile' && orientation === 'portrait') minH = 812;
                    else if (device === 'mobile' && orientation === 'landscape') minH = 375;
                    currentIframe.style.height = Math.max(height, minH) + 'px';
                } catch (e) {
                    // Cross-origin — leave default height
                }
            };
        }

        const updateView = () => {
            applyDeviceSize();
            // Re-render iframe content if needed (device change doesn't require re-render,
            // but we need to adjust iframe height)
            if (currentIframe && currentIframe.contentDocument) {
                try {
                    var doc = currentIframe.contentDocument;
                    var body = doc.body;
                    var height = Math.max(body.scrollHeight, doc.documentElement.scrollHeight);
                    var minH = 800;
                    if (device === 'tablet' && orientation === 'portrait') minH = 1024;
                    else if (device === 'tablet' && orientation === 'landscape') minH = 768;
                    else if (device === 'mobile' && orientation === 'portrait') minH = 812;
                    else if (device === 'mobile' && orientation === 'landscape') minH = 375;
                    currentIframe.style.height = Math.max(height, minH) + 'px';
                } catch (e) {}
            }
        };

        editor.previewArea.innerHTML = `
            <div class="lumina-preview-toolbar">
                <button type="button" data-d="desktop" title="Desktop" class="active">${editor.getIcon('monitor')}</button>
                <button type="button" data-d="tablet" title="Tablet">${editor.getIcon('tablet')}</button>
                <button type="button" data-d="mobile" title="Mobile">${editor.getIcon('smartphone')}</button>
                <div class="lumina-sep" style="margin: 0 10px;"></div>
                <button type="button" class="lp-rotate" title="Rotate Screen" style="display:none;">${editor.getIcon('rotate-screen')}</button>
                <div style="flex:1"></div>
                <button type="button" class="lp-refresh" title="Refresh Preview" style="width:auto;padding:0 12px;font-size:12px;gap:6px;"><i class="fas fa-sync-alt" style="font-size:11px;"></i> Refresh</button>
                <button type="button" class="lp-close lumina-btn-primary" style="width: auto; padding: 0 16px;">Exit Preview</button>
            </div>
            <div class="lumina-preview-frame desktop portrait">
                <div class="lp-content"><div class="lp-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview…</div></div>
            </div>`;

        editor.previewArea.querySelectorAll('button[data-d]').forEach(button => {
            button.onclick = () => {
                device = button.dataset.d;
                if (device === 'desktop') orientation = 'portrait';
                updateView();
            };
        });
        const rotateBtn = editor.previewArea.querySelector('.lp-rotate');
        rotateBtn.onclick = () => {
            orientation = orientation === 'portrait' ? 'landscape' : 'portrait';
            updateView();
        };

        // Refresh button — clears cache and re-renders
        const refreshBtn = editor.previewArea.querySelector('.lp-refresh');
        if (refreshBtn) {
            refreshBtn.onclick = () => {
                renderedHtml = null;
                currentIframe = null;
                var lpContent = editor.previewArea.querySelector('.lp-content');
                if (lpContent) lpContent.innerHTML = '<div class="lp-loading"><i class="fas fa-spinner fa-spin"></i> Loading preview…</div>';
                renderPreviewContent(setIframeContent);
            };
        }

        editor.previewArea.querySelector('.lp-close').onclick = () => togglePreview(editor);

        // Initial render
        renderPreviewContent(setIframeContent);

    } else {
        editor.previewArea.style.display = 'none';
        editor.editor.style.display = 'block';
        if (editor.editorWrapper) editor.editorWrapper.classList.remove('lumina-preview-active');
    }
}

export function formatHtmlValue(html) {
    return formatHTML(html);
}

export function loadCodeTheme(theme) {
    loadThemeCss(theme);
}

export function compressHtmlImages(editor, html) {
    return compressImagesForSource(editor, html);
}

export function decompressHtmlImages(editor, html) {
    return decompressImagesForVisual(editor, html);
}
