import { restoreSelection, saveSelection, applyCustomStyle } from './selection.js?v=20260901';
import { loadCodeMirrorResources, decompressHtmlImages } from './sourceMode.js?v=20260901';
import { selectImage, repositionImageToolbar, deselectImage } from './imageToolbar.js?v=20260901';
import { smartPosition } from './positioning.js?v=20260901';
import { hideQuickInsert } from './quickInsert.js?v=20260901';
import { initBoxToolbar, insertBox, deselectBox } from './boxToolbar.js?v=20260901';
import { initBlockToolbar, repositionBlockToolbar, toggleBlockSettings, initBlockSettings } from './blockToolbar.js?v=20260901';
import { initTableToolbar, insertRow, insertCol } from './tableToolbar.js?v=20260901';

// ---- HTML / Text Insertion ----

export function insertHTML(editor, html) {
    // Only force focus if we don't have a valid selection inside the editor
    const sel = window.getSelection();
    if (!(sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode))) {
        editor.editor.focus();
    }
    
    if (sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) {
        document.execCommand('insertHTML', false, html);
    } else {
        editor.editor.innerHTML += html;
    }
    editor.sync();
    editor.pushHistory(true);
}

export function insertSection(editor) {
    // The toolbar button uses onmousedown + preventDefault(), so the editor's
    // current selection (cursor position) is preserved when this is called.
    // We restore it to make sure the section lands at the cursor — not at the
    // top of the page.
    const sel = window.getSelection();
    if (!(sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode))) {
        editor.restoreSelection();
        if (!window.getSelection().rangeCount) editor.editor.focus();
    }
    // Default fade-in animation; user can change it via Block Settings later.
    const sectionHtml = `<div class="lumina-layout-block lumina-anim-fade w-full max-w-7xl mx-auto my-6 px-6 py-6 border border-dashed border-slate-300 rounded-lg min-h-[120px] relative group hover:border-blue-400 transition-colors"><p class="text-slate-400 text-center italic">New Section - Drag elements here</p></div><p><br/></p>`;
    document.execCommand('insertHTML', false, sectionHtml);
    editor.sync();
    editor.pushHistory(true);
}

export function insertChecklist(editor) {
    editor.restoreSelection();
    const html = `<div class="lumina-todo-item" style="display:flex; align-items:center; margin-bottom:4px;"><input type="checkbox" style="margin-right:8px; cursor:pointer;">&nbsp;</div>`;
    document.execCommand('insertHTML', false, html);
    editor.sync();
    editor.pushHistory(true);
}

export function duplicateBlock(editor) {
    editor.editor.focus();
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    let node = sel.anchorNode;
    if (node.nodeType === 3) node = node.parentElement;
    
    const block = node.closest('.lumina-layout-block, .lumina-code-wrapper, .lumina-todo-item, table, blockquote, pre, p, h1, h2, h3, h4, h5, h6, li');
    if (block && editor.editor.contains(block)) {
        const clone = block.cloneNode(true);
        block.after(clone);
        const range = document.createRange();
        range.selectNodeContents(clone);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);
        editor.sync();
        editor.pushHistory(true);
    }
}

// ---- Media Handling ----

/**
 * Escape a URL string for safe insertion into an HTML attribute
 * (src="..."). Prevents XSS via crafted URLs containing " or other
 * HTML metacharacters.
 *
 * Examples:
 *   escapeUrlAttr('https://example.com/a.jpg')  → 'https://example.com/a.jpg'
 *   escapeUrlAttr('javascript:alert(1)')        → 'javascript:alert(1)' (still dangerous — see isUrlSafe below)
 *   escapeUrlAttr('"onerror="alert(1)')          → '&quot;onerror=&quot;alert(1)'
 */
function escapeUrlAttr(url) {
    return String(url)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Validate that a URL is safe to use as an <img src> or <iframe src>.
 * Blocks javascript:, data: (except image data URIs), vbscript: schemes.
 *
 * @param {string} url
 * @returns {boolean}
 */
function isUrlSafe(url) {
    if (typeof url !== 'string' || url === '') return false;
    // Allow relative URLs (uploads/..., ../uploads/..., /uploads/...)
    if (/^(\.\.?\/|\/|uploads\/)/.test(url)) return true;
    // Allow http(s) URLs
    if (/^https?:\/\//i.test(url)) return true;
    // Allow image data URIs (data:image/png;base64,...)
    if (/^data:image\/(png|jpeg|jpg|gif|webp|bmp|x-icon);base64,/i.test(url)) return true;
    // Block dangerous schemes
    if (/^(javascript|vbscript|data|file):/i.test(url)) return false;
    // Default: allow (protocol-relative, anchor etc.)
    return true;
}

/**
 * Extract YouTube video ID from any YouTube URL format:
 *   - https://www.youtube.com/watch?v=ID
 *   - https://youtu.be/ID
 *   - https://www.youtube.com/embed/ID
 *   - https://www.youtube.com/shorts/ID
 * Returns null if not a YouTube URL or ID could not be extracted.
 */
function extractYouTubeId(url) {
    if (!url || typeof url !== 'string') return null;
    // watch?v=ID
    var m = url.match(/[?&]v=([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];
    // youtu.be/ID
    m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];
    // /embed/ID
    m = url.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];
    // /shorts/ID
    m = url.match(/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/);
    if (m) return m[1];
    return null;
}

function extractVimeoId(url) {
    if (!url || typeof url !== 'string') return null;
    var m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    return m ? m[1] : null;
}

export async function uploadFile(editor, file) {
    // Validate file type before upload (defence in depth — server also checks)
    if (file && file.type && !file.type.startsWith('image/')) {
        throw new Error('Only image files are allowed.');
    }
    // File size cap (8 MB) — matches server-side limit
    if (file && file.size > 8 * 1024 * 1024) {
        throw new Error('File too large (max 8 MB).');
    }
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', window._luminaCsrfToken || '');

    try {
        const response = await fetch(editor.uploadUrl, { method: 'POST', body: formData });
        if (!response.ok) throw new Error('Server returned ' + response.status);
        const data = await response.json();
        if (data.link) return data.link;
        else if (data.url) return data.url;
        else if (data.location) return data.location;
        else if (data.error) throw new Error(data.error);
        else throw new Error('Invalid server response');
    } catch (e) {
        console.warn('Image upload failed, falling back to Base64.', e);
        // Base64 fallback only for files under 2 MB (avoid localStorage overflow)
        if (file && file.size > 2 * 1024 * 1024) {
            throw new Error('Upload failed and file too large for Base64 fallback.');
        }
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (ev) => resolve(ev.target.result);
            reader.onerror = () => reject(new Error('FileReader failed'));
            reader.readAsDataURL(file);
        });
    }
}

export function insertImage(editor, url) {
    if (!isUrlSafe(url)) {
        console.warn('Lumina: blocked unsafe image URL:', url);
        return;
    }
    var safeUrl = escapeUrlAttr(url);
    if (editor.selectedBox) {
        const content = editor.selectedBox.querySelector('.lumina-box-content');
        if (content) {
            const img = document.createElement('img');
            img.src = url;  // .src assignment is safe (browser parses URL)
            img.style.maxWidth = '100%';
            img.style.borderRadius = '4px';
            img.loading = 'lazy';  // perf: lazy-load images
            img.decoding = 'async';
            content.appendChild(img);
            editor.sync(); 
            editor.pushHistory(true);
            setTimeout(() => editor.selectImage(img), 50);
            return;
        }
    }
    editor.restoreSelection(); 
    if (!window.getSelection().rangeCount) editor.editor.focus();

    const imgHtml = `<img src="${safeUrl}" style="max-width:100%; border-radius:4px;" loading="lazy" decoding="async" />`;
    document.execCommand('insertHTML', false, imgHtml);
    editor.sync(); 
    editor.pushHistory(true); 
    setTimeout(() => {
        const imgs = editor.editor.querySelectorAll('img');
        for(let i = imgs.length - 1; i >= 0; i--) {
            if(imgs[i].src === url || (url.startsWith('data:') && imgs[i].src.startsWith('data:'))) {
                editor.selectImage(imgs[i]); break;
            }
        }
    }, 100);
}

export function insertVideo(editor, url) {
    if (!isUrlSafe(url)) {
        console.warn('Lumina: blocked unsafe video URL:', url);
        return;
    }
    var safeUrl = escapeUrlAttr(url);
    var embed = '';
    var ytId = extractYouTubeId(url);
    var vimeoId = extractVimeoId(url);
    if (ytId) {
        // Sanitized YouTube embed — ID is constrained to [a-zA-Z0-9_-]{11}
        embed = `<iframe src="https://www.youtube.com/embed/${ytId}" frameborder="0" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="width:100%;height:100%;pointer-events:none;"></iframe>`;
    } else if (vimeoId) {
        embed = `<iframe src="https://player.vimeo.com/video/${vimeoId}" frameborder="0" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="width:100%;height:100%;pointer-events:none;"></iframe>`;
    } else {
        embed = `<video src="${safeUrl}" controls preload="metadata" style="width:100%;height:100%;pointer-events:none;"></video>`;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'lumina-resizable-media';
    wrapper.style.position = 'relative'; 
    wrapper.style.display = 'inline-block'; 
    wrapper.style.width = '560px'; 
    wrapper.style.aspectRatio = '16/9'; 
    wrapper.style.maxWidth = '100%';
    wrapper.innerHTML = embed;
    
    if (editor.selectedBox) {
        const content = editor.selectedBox.querySelector('.lumina-box-content');
        if(content) { 
            wrapper.style.width = '100%'; 
            content.appendChild(wrapper); 
            editor.sync(); 
            editor.pushHistory(true); 
            setTimeout(() => editor.selectImage(wrapper), 50); 
            return; 
        }
    }
    editor.restoreSelection(); 
    if (!window.getSelection().rangeCount) editor.editor.focus();
    
    document.execCommand('insertHTML', false, wrapper.outerHTML);
    
    editor.sync(); 
    editor.pushHistory(true);
}

// ---- Code Blocks ----

export function insertCodeBlock(editor) {
    editor.restoreSelection();
    const html = `<div class="lumina-code-wrapper" contenteditable="false"><div class="lumina-code-header"><select class="lumina-code-lang-select"><option value="auto">Auto</option><option value="html">HTML</option><option value="css">CSS</option><option value="js">JavaScript</option><option value="ts">TypeScript</option><option value="php">PHP</option><option value="c">C</option><option value="cpp">C++</option><option value="csharp">C#</option><option value="go">Go</option><option value="rust">Rust</option></select><div class="lumina-code-actions"><button type="button" class="lumina-code-edit">Edit</button><button type="button" class="lumina-code-copy"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg><span class="lumina-code-copy-label">Copy</span></button></div></div><pre class="lumina-code-content" contenteditable="false">Click 'Edit' to add code...</pre></div><p><br></p>`;
    document.execCommand('insertHTML', false, html); 
    editor.sync(); 
    editor.pushHistory(true);
    setTimeout(() => { 
        const wrappers = editor.editor.querySelectorAll('.lumina-code-wrapper'); 
        const newWrapper = wrappers[wrappers.length - 1]; 
        if(newWrapper) openCodeEditorModal(editor, newWrapper); 
    }, 100);
}

export function openCodeEditorModal(editor, wrapper) {
    const pre = wrapper.querySelector('.lumina-code-content'); 
    const select = wrapper.querySelector('.lumina-code-lang-select'); 
    const currentCode = pre.innerText === "Click 'Edit' to add code..." ? '' : pre.innerText;
    
    const h = `<div style="display:flex; flex-direction:column; height:400px;"><div class="p-2 text-sm text-slate-500 bg-slate-100 border-b" style="padding: 8px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; font-family: sans-serif;">Language: <strong id="modal-lang-display">${select.options[select.selectedIndex].text}</strong></div><textarea id="lumina-code-textarea" class="lumina-code-textarea" spellcheck="false" placeholder="Paste your code here...">${currentCode}</textarea></div>`;
    
    editor.createModalOverlay('Edit Code', h, [{
        text: 'Save Code',
        onClick: (e, modal) => {
            const textarea = modal.querySelector('#lumina-code-textarea'); 
            const rawCode = textarea.value; 
            const lang = select.value;
            const highlightedHTML = getHighlightedCode(rawCode, lang); 
            pre.innerHTML = highlightedHTML;
            modal.closest('.lumina-modal-overlay').remove(); 
            editor.sync(); 
            editor.pushHistory(true);
        }
    }]);
}

export function getHighlightedCode(text, lang = 'auto') {
    if (!text) return '<br>';
    text = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    const span = (cls, content) => `<span class="${cls}">${content}</span>`;
    const commentRegex = /(\/\/.*$|\/\*[\s\S]*?\*\/)/gm; 
    const stringRegex = /(".*?"|'.*?'|`.*?`)/g;
    
    if (lang === 'auto') { 
        if (text.trim().startsWith('&lt;')) lang = 'html'; 
        else if (text.match(/(\{|\})\s*$/gm)) lang = 'css'; 
        else lang = 'js'; 
    }
    
    if (lang === 'html' || lang === 'xml') {
        text = text.replace(/(&lt;\/?)(\w+)(.*?)(\/?&gt;)/g, (match, p1, p2, p3, p4) => {
            let attrs = p3.replace(/(\s+)([a-zA-Z0-9-]+)(=)/g, '$1' + span('token-attr', '$2') + '$3'); 
            attrs = attrs.replace(/(".*?")/g, span('token-str', '$1'));
            return span('token-op', p1) + span('token-tag', p2) + attrs + span('token-op', p4);
        });
        text = text.replace(/(&lt;!--.*?--&gt;)/g, span('token-com', '$1'));
    } else if (lang === 'css') {
            text = text.replace(commentRegex, span('token-com', '$1')); 
            text = text.replace(/([a-zA-Z-]+)(:)/g, span('token-attr', '$1') + '$2'); 
            text = text.replace(/(:)\s*(.*?)(;)/g, (m, p1, p2, p3) => p1 + span('token-val', p2) + p3);
    } else {
        const tokens = []; 
        const saveToken = (str, type) => { tokens.push({ str, type }); return `___TOKEN${tokens.length - 1}___`; };
        text = text.replace(commentRegex, (m) => saveToken(m, 'token-com')); 
        text = text.replace(stringRegex, (m) => saveToken(m, 'token-str'));
        let kw = ''; const commonKw = 'if|else|while|for|return|break|continue|switch|case|default';
        switch(lang) {
            case 'js': case 'ts': kw = `${commonKw}|const|let|var|function|class|this|new|import|export|from|async|await|try|catch|extends|implements|interface|type|public|private`; break;
            case 'php': kw = `${commonKw}|function|class|public|private|protected|echo|use|namespace|array`; text = text.replace(/(\$[a-zA-Z_]\w*)/g, span('token-var', '$1')); break;
            case 'c': case 'cpp': case 'csharp': kw = `${commonKw}|int|void|char|float|double|bool|struct|class|public|private|protected|static|namespace|using|include|define|template|virtual|override`; break;
            case 'go': kw = `${commonKw}|func|package|import|type|struct|interface|map|chan|go|defer|var|const`; break;
            case 'rust': kw = `${commonKw}|fn|let|mut|struct|enum|impl|trait|mod|use|pub|crate|match|loop`; break;
            default: kw = `${commonKw}|function|class|var|let|const`;
        }
        const kwRegex = new RegExp(`\\b(${kw})\\b`, 'g'); text = text.replace(kwRegex, span('token-kwd', '$1'));
        text = text.replace(/\b(\d+)\b/g, span('token-num', '$1'));
        if (['c', 'cpp', 'csharp', 'go', 'rust', 'ts'].includes(lang)) { text = text.replace(/\b([A-Z][a-zA-Z0-9_]*)\b/g, span('token-type', '$1')); }
        tokens.forEach((token, i) => { const placeholder = `___TOKEN${i}___`; text = text.replace(placeholder, span(token.type, token.str)); });
    }
    return text;
}

// === Format HTML (auto-indent) ===
export function formatHTML(editor) {
    var html = editor.editor.innerHTML;
    var formatted = '';
    var indent = 0;
    var tab = '    ';
    
    // Remove extra whitespace between tags
    html = html.replace(/>\s+</g, '><');
    html = html.replace(/^\s+/, '');
    
    // Split by tags
    var parts = html.split(/(<[^>]+>)/g);
    
    // Void elements (self-closing)
    var voidElements = ['img','br','hr','input','meta','link','area','base','col','embed','source','track','wbr'];
    
    for (var i = 0; i < parts.length; i++) {
        var part = parts[i];
        if (!part) continue;
        
        if (part.startsWith('<')) {
            // It's a tag
            var tagName = part.match(/<\/?(\w+)/);
            tagName = tagName ? tagName[1].toLowerCase() : '';
            var isClosing = part.startsWith('</');
            var isSelfClosing = part.endsWith('/>') || voidElements.indexOf(tagName) !== -1;
            var isDoctype = part.startsWith('<!') || part.startsWith('<?');
            
            if (isClosing) {
                indent = Math.max(0, indent - 1);
                formatted += tab.repeat(indent) + part + '\n';
            } else if (isSelfClosing || isDoctype) {
                formatted += tab.repeat(indent) + part + '\n';
            } else {
                formatted += tab.repeat(indent) + part + '\n';
                indent++;
            }
        } else {
            // It's text content
            var trimmed = part.trim();
            if (trimmed) {
                formatted += tab.repeat(indent) + trimmed + '\n';
            }
        }
    }
    
    // Update editor
    editor.editor.innerHTML = formatted.trim();
    editor.sync();
    editor.pushHistory(true);
    
    if (window.AicmsToast) {
        var t = editor.t || (k => k);
        AicmsToast.show(t('html_formatted') || 'HTML formatted', 'success');
    }
}
