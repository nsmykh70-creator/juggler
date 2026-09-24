import { applyCustomStyle as applyCustomStyleModule, toggleColorPicker } from './selection.js?v=20260901';
import { smartPosition, collectVisiblePanels } from './positioning.js?v=20260901';

export function initBubbleMenu(editor) {
    editor.bubble = document.createElement('div');
    editor.bubble.className = 'lumina-bubble';
    editor.bubble.style.display = 'none';

    const fontSelect = document.createElement('select');
    fontSelect.className = 'lumina-select';
    fontSelect.style.width = '110px';
    editor.fonts.forEach((font) => {
        const option = document.createElement('option');
        option.value = font;
        option.text = font;
        fontSelect.appendChild(option);
    });
    editor.bubbleFontSelect = fontSelect;
    
    fontSelect.onchange = (event) => {
        applyCustomStyleModule(editor, { fontFamily: event.target.value });
        editor.checkSelection(); 
    };
    fontSelect.onmousedown = (e) => e.stopPropagation();
    editor.bubble.appendChild(fontSelect);

    const sizeSelect = document.createElement('select');
    sizeSelect.className = 'lumina-select';
    sizeSelect.style.width = '100px';
    ['8','10','12','14','16','18','20','24','30','36','48','60','72','96'].forEach((size) => {
        const option = document.createElement('option');
        option.value = `${size}px`;
        option.text = size;
        sizeSelect.appendChild(option);
    });
    editor.bubbleSizeSelect = sizeSelect;

    sizeSelect.onchange = (event) => {
        applyCustomStyleModule(editor, { fontSize: event.target.value });
        editor.checkSelection(); 
    };
    sizeSelect.onmousedown = (e) => e.stopPropagation();
    editor.bubble.appendChild(sizeSelect);

    const items = [
        { icon: 'bold', command: 'bold', title: 'Bold' }, 
        { icon: 'italic', command: 'italic', title: 'Italic' }, 
        { icon: 'underline', command: 'underline', title: 'Underline' },
        { icon: 'eraser', command: 'removeFormat', title: 'Clear Formatting' },
        { icon: 'link', action: 'link', title: 'Link' },
        { isTextColor: true, title: 'Text Color' },
        { icon: 'highlighter', action: 'highlight', title: 'Highlight' },
        { icon: 'settings', action: 'settings', title: 'Text Settings' }
    ];

    items.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumina-btn';
        button.title = item.title || '';

        if (item.isTextColor) {
             button.innerHTML = '<span style="border-bottom:3px solid currentColor; font-weight:bold; font-size:14px; line-height:14px; display:inline-block;">A</span>';
             button.onmousedown = (e) => {
                 e.preventDefault();
                 e.stopPropagation();
                 toggleColorPicker(editor, 'color', button);
             };
        } else {
            button.innerHTML = editor.getIcon(item.icon);
            if (item.command) button.dataset.cmd = item.command;
            
            button.onmousedown = (event) => {
                event.preventDefault(); 
                event.stopPropagation();
                if (item.action === 'highlight') {
                    toggleColorPicker(editor, 'backgroundColor', button);
                } else if (item.action === 'settings') {
                    toggleTextSettings(editor);
                } else if (item.action === 'link') {
                    editor.openLinkModal();
                } else if (item.action === 'tooltip') {
                    editor.openTooltipModal();
                } else if (item.command) {
                    if (item.command === 'removeFormat') {
                        document.execCommand('removeFormat', false, null);
                    } else {
                        document.execCommand(item.command);
                    }
                }
                editor.checkSelection();
                editor.updateToolbarState();
                updateBubbleState(editor);
            };
        }
        editor.bubble.appendChild(button);
    });

    const update = () => {
         const { rotate, flipX, flipY, shadow, spacing, kerning } = editor.textState;
         const styles = {};

         if (rotate !== 0 || flipX !== 1 || flipY !== 1) {
             styles.display = 'inline-block';
             styles.transform = `rotate(${rotate}deg) scale(${flipX}, ${flipY})`;
         } else {
             styles.transform = 'none';
             styles.display = '';
         }

         styles.textShadow = shadow ? '2px 2px 4px rgba(0,0,0,0.4)' : 'none';
         styles.letterSpacing = spacing !== 0 ? `${spacing}px` : 'normal';
         styles.fontKerning = (kerning && kerning !== 'auto') ? kerning : 'auto';

         applyCustomStyleModule(editor, styles);
         // NOTE: text animations (including typewriter) are now applied via
         // the dedicated handleAnimChange() handler, not via update(). This
         // keeps transform / spacing concerns separate from animation
         // concerns, which avoids clobbering the animation when the user
         // changes rotation or spacing after picking an animation.
    };
    
    editor.textSettings = document.createElement('div');
    editor.textSettings.className = 'lumina-bubble-settings';
    editor.textSettings.style.display = 'none';
    editor.textSettings.innerHTML = `
        <div class="lbs-header">Text Style</div>
        <div class="lbs-row"><label>Rotate</label><div style="display:flex; align-items:center; gap:5px; flex:1;"><input type="number" class="lts-rotate-num" value="0" placeholder="0" style="width:60px; padding:4px; border:1px solid #ccc; border-radius:4px; background:#ffffff; color:#000000;"><span style="font-size:11px; color:#666;">deg</span><button type="button" class="lumina-btn lts-rotate-reset" title="Reset Effects" style="width:auto; padding:0 8px; font-size:11px; height:26px; margin-left:auto;">Reset</button></div></div>
        <div class="lbs-row"><label style="width:90px;">Letter Spacing</label><input type="range" class="lts-spacing" min="-2" max="20" step="0.5" value="0" style="flex:1;"><span class="lts-spacing-val" style="font-size:10px;width:30px;text-align:right;">0px</span></div>
        <div class="lbs-row"><label style="width:90px;">Kerning</label><select class="lts-kerning lumina-select" style="flex:1;"><option value="auto">Auto</option><option value="normal">Normal</option><option value="none">None</option></select></div>
        <div class="lbs-row"><label>Shadow</label><input type="checkbox" class="lts-shadow" style="width:16px; height:16px; cursor:pointer; background-color: #ffffff;"></div>
        <div class="lbs-grp" style="margin-top:10px; border-top:1px solid #eee; padding-top:10px;"><span>Text Animation</span>
            <div class="lbs-row"><label>Effect</label><div style="display:flex; flex:1; gap:4px; align-items:center;"><select class="lts-text-anim lumina-select" style="flex:1;"><option value="none">None</option><option value="lumina-anim-text-blur-in">Blur In</option><option value="lumina-anim-text-glow">Glow Pulse</option><option value="lumina-anim-text-wave">Wave</option><option value="lumina-anim-text-rise">Rise Up</option><option value="lumina-anim-text-shimmer">Shimmer</option><option value="lumina-anim-text-neon">Neon</option><option value="lumina-anim-text-stretch">Stretch</option><option value="lumina-anim-text-flip-in">Flip In</option><option value="lumina-anim-text-wobble">Wobble</option><option value="lumina-anim-text-drop">Drop In</option><option value="lumina-anim-text-gradient">Gradient Flow</option><option value="lumina-anim-text-aurora">Aurora (Northern Lights)</option><option value="lumina-anim-text-typewriter">Typewriter</option></select><button type="button" class="lumina-btn lts-play-text-anim" title="Preview Animation" style="width:30px; color:black;">▶</button></div></div>
            <div class="lbs-row"><label style="width:90px;">Speed</label><input type="range" class="lts-text-anim-speed" min="0.3" max="6.0" step="0.1" value="1.0" style="flex:1;"><span class="lts-text-anim-speed-val" style="font-size:10px;width:30px;text-align:right;">1.0s</span></div>
            <div class="lbs-row"><label>Loop</label><div style="display:flex; align-items:center; gap:8px; flex:1;"><input type="checkbox" class="lts-text-anim-loop" style="width:16px; height:16px; cursor:pointer; accent-color:#2563eb;"><span style="font-size:11px; color:#666;">Repeat forever</span></div></div>
        </div>
        <div class="lbs-grp"><span>Transform</span><div class="lbs-shapes"><button type="button" class="lts-flipx" title="Flip Horizontal" style="color:#000000;">Flip X</button><button type="button" class="lts-flipy" title="Flip Vertical" style="color:#000000;">Flip Y</button></div></div>
    `;
    editor.textSettings.onmousedown = (e) => e.stopPropagation();
    editor.bubble.appendChild(editor.textSettings);
    document.body.appendChild(editor.bubble);

    // Known text animation classes — used to clear previous animation when switching.
    const TEXT_ANIM_CLASSES = [
        'lumina-anim-text-blur-in', 'lumina-anim-text-glow', 'lumina-anim-text-wave',
        'lumina-anim-text-rise', 'lumina-anim-text-shimmer', 'lumina-anim-text-neon',
        'lumina-anim-text-stretch', 'lumina-anim-text-flip-in', 'lumina-anim-text-wobble',
        'lumina-anim-text-drop', 'lumina-anim-text-gradient', 'lumina-anim-text-aurora',
        'lumina-anim-text-typewriter'
    ];

    // Gradient animations (shimmer, gradient, aurora) set these CSS
    // properties via the class: background, -webkit-background-clip,
    // background-clip, -webkit-text-fill-color. When switching away
    // from a gradient animation, we must explicitly clear these as
    // inline styles too, because some browsers don't properly
    // recalculate the computed style when the class is removed.
    const clearGradientStyles = (el) => {
        el.style.background = '';
        el.style.backgroundImage = '';
        el.style.webkitBackgroundClip = '';
        el.style.backgroundClip = '';
        el.style.webkitTextFillColor = '';
        el.style.filter = '';
    };

    // Apply the chosen text animation to the selected span, including
    // the loop modifier and the speed (in seconds) set by the slider.
    const applyTextAnim = (animClass, speed, loop) => {
        let targetSpan = editor.selectedSpan || findSelectedSpan(editor);
        if (!targetSpan) return;
        TEXT_ANIM_CLASSES.forEach((c) => targetSpan.classList.remove(c));
        targetSpan.classList.remove('lumina-anim-loop');
        clearGradientStyles(targetSpan);
        if (animClass !== 'none') {
            targetSpan.classList.add(animClass);
            // Speed (seconds) — applied via inline style.animationDuration.
            targetSpan.style.animationDuration = speed + 's';
            if (loop) targetSpan.classList.add('lumina-anim-loop');
        } else {
            targetSpan.style.animationDuration = '';
        }
        editor.sync();
    };

    // Typewriter is a special animation: it wraps each character in a
    // .lumina-tw-char span with a staggered animation-delay so the text
    // appears to be typed out. Speed = total time for all characters.
    const applyTypewriter = (targetSpan, speed, loop) => {
        const chars = targetSpan.innerText.split('');
        const delayPerChar = speed / (chars.length || 1);
        const frag = document.createDocumentFragment();
        chars.forEach((ch, i) => {
            const s = document.createElement('span');
            s.textContent = ch;
            s.className = 'lumina-tw-char' + (loop ? ' lumina-tw-char-loop' : '');
            s.style.animationDelay = (i * delayPerChar) + 's';
            // For loop mode, each char animation repeats infinitely.
            if (loop) s.style.animationIterationCount = 'infinite';
            frag.appendChild(s);
        });
        targetSpan.innerHTML = '';
        targetSpan.appendChild(frag);
        targetSpan.classList.add('lumina-anim-text-typewriter');
        targetSpan.dataset.luminaTypewriterActive = 'true';
    };

    // Remove any previously-applied typewriter effect, restoring the
    // original text content from the data-originalHtml attribute.
    const removeTypewriter = (targetSpan) => {
        if (targetSpan.dataset.luminaTypewriterActive) {
            targetSpan.innerHTML = targetSpan.dataset.originalHtml || targetSpan.innerText;
            delete targetSpan.dataset.originalHtml;
            delete targetSpan.dataset.luminaTypewriterActive;
            targetSpan.classList.remove('lumina-anim-text-typewriter');
        }
    };

    const textAnimSelect = editor.textSettings.querySelector('.lts-text-anim');
    const textSpeedInput = editor.textSettings.querySelector('.lts-text-anim-speed');
    const textLoopCheckbox = editor.textSettings.querySelector('.lts-text-anim-loop');

    // Ensure the current selection is wrapped in a <span data-custom-style>
    // so that animation classes can be applied to it. If the user hasn't
    // applied any custom style yet, findSelectedSpan() returns null and
    // animations silently do nothing. This function fixes that by creating
    // the span on demand.
    const ensureTargetSpan = () => {
        let span = editor.selectedSpan || findSelectedSpan(editor);
        if (span) return span;

        // No existing span — create one wrapping the current selection.
        // We use applyCustomStyle with a no-op style (letterSpacing: normal)
        // which forces span creation without changing the appearance.
        // The span survives because it has one style property set.
        editor.restoreSelection();
        applyCustomStyleModule(editor, { letterSpacing: 'normal' });
        span = findSelectedSpan(editor);
        if (span) editor.selectedSpan = span;
        return span;
    };

    const handleAnimChange = () => {
        const anim = textAnimSelect ? textAnimSelect.value : 'none';
        const speed = textSpeedInput ? parseFloat(textSpeedInput.value) : 1.0;
        const loop = textLoopCheckbox ? textLoopCheckbox.checked : false;

        // If user selected "None", try to clear the animation from the
        // existing span (if any) but don't create a new span just to
        // put "no animation" on it.
        if (anim === 'none') {
            let span = editor.selectedSpan || findSelectedSpan(editor);
            if (!span) return;
            removeTypewriter(span);
            TEXT_ANIM_CLASSES.forEach((c) => span.classList.remove(c));
            span.classList.remove('lumina-anim-loop');
            span.style.animationDuration = '';
            clearGradientStyles(span);
            editor.sync();
            return;
        }

        let targetSpan = ensureTargetSpan();
        if (!targetSpan) return;

        // Always clear any prior typewriter effect first.
        removeTypewriter(targetSpan);

        if (anim === 'lumina-anim-text-typewriter') {
            // Save original HTML so we can restore it if user switches away.
            if (!targetSpan.dataset.luminaTypewriterActive) {
                targetSpan.dataset.originalHtml = targetSpan.innerHTML;
            }
            // Restore text first (in case we're re-applying with new speed).
            targetSpan.innerHTML = targetSpan.dataset.originalHtml || targetSpan.innerText;
            applyTypewriter(targetSpan, speed, loop);
        } else {
            applyTextAnim(anim, speed, loop);
        }
        editor.sync();
    };

    if (textAnimSelect)     textAnimSelect.onchange    = handleAnimChange;
    if (textSpeedInput)     textSpeedInput.oninput     = handleAnimChange;
    if (textLoopCheckbox)   textLoopCheckbox.onchange  = handleAnimChange;

    // Preview button — replay the animation so the user can see how it looks.
    const playTextAnimBtn = editor.textSettings.querySelector('.lts-play-text-anim');
    if (playTextAnimBtn) {
        playTextAnimBtn.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            const anim = textAnimSelect ? textAnimSelect.value : 'none';
            if (anim === 'none') return;
            const speed = textSpeedInput ? parseFloat(textSpeedInput.value) : 1.0;
            const loop = textLoopCheckbox ? textLoopCheckbox.checked : false;
            let targetSpan = ensureTargetSpan();
            if (!targetSpan) return;

            if (anim === 'lumina-anim-text-typewriter') {
                // For typewriter, just re-run applyTypewriter.
                if (!targetSpan.dataset.luminaTypewriterActive) {
                    targetSpan.dataset.originalHtml = targetSpan.innerHTML;
                }
                targetSpan.innerHTML = targetSpan.dataset.originalHtml || targetSpan.innerText;
                applyTypewriter(targetSpan, speed, loop);
            } else {
                // For non-typewriter animations: force a reflow to restart.
                targetSpan.classList.remove(anim, 'lumina-anim-loop');
                void targetSpan.offsetWidth;
                targetSpan.classList.add(anim);
                if (loop) targetSpan.classList.add('lumina-anim-loop');
                targetSpan.style.animationDuration = speed + 's';
            }
        };
    }

    const bindInput = (sel, prop, isCheck=false) => { const el = editor.textSettings.querySelector(sel); if(el) { if(isCheck) { el.onchange = (e) => { editor.textState[prop] = e.target.checked; update(); }; } else { el.oninput = (e) => { editor.textState[prop] = e.target.value; update(); }; } } };
    bindInput('.lts-rotate-num', 'rotate'); bindInput('.lts-shadow', 'shadow', true);
    bindInput('.lts-spacing', 'spacing');
    const kernSel = editor.textSettings.querySelector('.lts-kerning'); if(kernSel) kernSel.onchange = (e) => { editor.textState.kerning = e.target.value; update(); };

    const flipXBtn = editor.textSettings.querySelector('.lts-flipx');
    if(flipXBtn) flipXBtn.onclick = () => { editor.textState.flipX = editor.textState.flipX === 1 ? -1 : 1; update(); };
    const flipYBtn = editor.textSettings.querySelector('.lts-flipy');
    if(flipYBtn) flipYBtn.onclick = () => { editor.textState.flipY = editor.textState.flipY === 1 ? -1 : 1; update(); };
    const resetBtn = editor.textSettings.querySelector('.lts-rotate-reset');
    if(resetBtn) resetBtn.onclick = () => {
        editor.textState = { ...editor.textState, rotate: 0, flipX: 1, flipY: 1, shadow: false };
        editor.textSettings.querySelector('.lts-rotate-num').value = 0;
        editor.textSettings.querySelector('.lts-shadow').checked = false;
        // Clear any text animation class on the selected span.
        let targetSpan = editor.selectedSpan || findSelectedSpan(editor);
        if (targetSpan) {
            removeTypewriter(targetSpan);
            TEXT_ANIM_CLASSES.forEach((c) => targetSpan.classList.remove(c));
            targetSpan.classList.remove('lumina-anim-loop');
            targetSpan.style.animationDuration = '';
            clearGradientStyles(targetSpan);
        }
        if (textAnimSelect)   textAnimSelect.value = 'none';
        if (textSpeedInput)   textSpeedInput.value = 1.0;
        if (textLoopCheckbox) textLoopCheckbox.checked = false;
        update();
    };
}

export function updateBubbleState(editor) {
    if (!editor.bubble || editor.bubble.style.display === 'none') return;
    
    editor.bubble.querySelectorAll('button[data-cmd]').forEach(btn => {
        const cmd = btn.dataset.cmd;
        if (document.queryCommandState(cmd)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

export function toggleTextSettings(editor) {
    if (!editor.textSettings) return;
    editor.textSettings.style.display = editor.textSettings.style.display === 'none' ? 'block' : 'none';
    checkSelection(editor);
}

function findSelectedSpan(editor) {
    let range = null;
    const sel = window.getSelection();
    if (sel.rangeCount > 0 && editor.editor.contains(sel.anchorNode)) { range = sel.getRangeAt(0); } else { range = editor.savedRange; }
    if (!range) return null;
    let temp = range.commonAncestorContainer;
    if (temp.nodeType === 3) temp = temp.parentElement;
    while (temp && temp !== editor.editor) {
        if (temp.tagName === 'SPAN' && temp.getAttribute('data-custom-style')) { return temp; }
        temp = temp.parentElement;
    }
    return null;
}

export function checkSelection(editor) {
    if (editor.selectedImage) return;
    if (editor.selectedButton) return; 

    const selection = window.getSelection();
    if (!selection.rangeCount) return;

    let node = selection.anchorNode;
    if (node && node.nodeType === 3) node = node.parentElement;
    
    if (!node || !editor.editor.contains(node)) return;

    if (node.closest('.lumina-action-btn') || node.closest('.lumina-custom-btn') || node.closest('button')) {
        return;
    }

    const range = selection.getRangeAt(0);
    editor.savedRange = range.cloneRange();

    const computed = window.getComputedStyle(node);
    if (computed) {
        let fontFamily = computed.fontFamily.replace(/['"]/g, '').split(',')[0].trim();
        const fontSize = computed.fontSize;
        
        if (editor.fontSelect) editor.fontSelect.value = fontFamily;
        if (editor.sizeSelect) editor.sizeSelect.value = fontSize;
        if (editor.bubbleFontSelect) editor.bubbleFontSelect.value = fontFamily;
        if (editor.bubbleSizeSelect) editor.bubbleSizeSelect.value = fontSize;
    }

    editor.selectedSpan = findSelectedSpan(editor);

    if (!selection.isCollapsed) {
        const rect = range.getBoundingClientRect();
        
        if (editor.bubble) {
            // CHECK SETTINGS
            if (editor.settings.bubbleMenus && editor.settings.bubbleMenus.text !== false) {
                editor.bubble.style.display = 'flex';
                smartPosition(editor, editor.bubble, rect, 'bottom', collectVisiblePanels(editor, editor.bubble));
                updateBubbleState(editor);
            } else {
                editor.bubble.style.display = 'none';
            }
        }
    } else {
        if (editor.bubble) editor.bubble.style.display = 'none';
        if (editor.textSettings) editor.textSettings.style.display = 'none';
    }
    
    if (editor.selectedSpan && editor.textSettings && editor.textSettings.style.display === 'block') {
         const s = editor.selectedSpan.style;
         const rotMatch = s.transform.match(/rotate\((-?\d+)deg\)/);
         const rot = rotMatch ? parseInt(rotMatch[1]) : 0;
         const isShadow = s.textShadow && s.textShadow !== 'none';

         const setVal = (sel, val, isCheck) => {
             const el = editor.textSettings.querySelector(sel);
             if(el) { if(isCheck) el.checked = val; else el.value = val; }
         };

         setVal('.lts-rotate-num', rot);
         setVal('.lts-shadow', isShadow, true);

         // Sync the Text Animation dropdown with the span's current class.
         const knownTextAnims = ['lumina-anim-text-blur-in','lumina-anim-text-glow','lumina-anim-text-wave','lumina-anim-text-rise','lumina-anim-text-shimmer','lumina-anim-text-neon','lumina-anim-text-stretch','lumina-anim-text-flip-in','lumina-anim-text-wobble','lumina-anim-text-drop','lumina-anim-text-gradient','lumina-anim-text-aurora','lumina-anim-text-typewriter'];
         let currentTextAnim = 'none';
         for (const c of knownTextAnims) { if (editor.selectedSpan.classList.contains(c)) { currentTextAnim = c; break; } }
         setVal('.lts-text-anim', currentTextAnim);

         // Sync speed (animation-duration in seconds, parsed from inline style).
         const animDur = s.animationDuration ? parseFloat(s.animationDuration) : 1.0;
         setVal('.lts-text-anim-speed', animDur);
         const speedValEl = editor.textSettings.querySelector('.lts-text-anim-speed-val');
         if (speedValEl) speedValEl.innerText = animDur.toFixed(1) + 's';

         // Sync loop checkbox.
         setVal('.lts-text-anim-loop', editor.selectedSpan.classList.contains('lumina-anim-loop'), true);

         editor.textState.rotate = rot;
         editor.textState.shadow = isShadow;
    }
    
    if(typeof editor.updateToolbarState === 'function') {
        editor.updateToolbarState();
    }
}
