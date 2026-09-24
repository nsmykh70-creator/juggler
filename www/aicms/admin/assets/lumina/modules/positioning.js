/**
 * smartPosition — place a floating toolbar/settings panel near a target
 * rectangle while avoiding (a) the editor's main toolbar at the top,
 * (b) the target element itself, and (c) any other floating panels that
 * are currently visible on screen.
 *
 * OVERLAP AVOIDANCE FOR MULTIPLE BUBBLE MENUS
 * -------------------------------------------
 * A single inserted element (e.g. an image inside a layout block) can
 * legitimately have TWO toolbars visible at the same time:
 *   - the block's layout toolbar (top of the block)
 *   - the image's image toolbar (top of the image)
 * Plus their respective settings panels. Without coordination, all of
 * them would stack on top of the same target rect and overlap.
 *
 * Solution: callers can pass an `avoid` array of extra DOM elements (or
 * rects) whose screen rectangles should be treated as obstacles. The
 * algorithm tries the preferred side first, then falls back to the
 * other vertical side, then to the left/right sides, choosing the
 * candidate that does NOT overlap any avoid-rect.
 *
 * Backward compatibility: the third arg can still be a plain string
 * preference ('top' | 'bottom' | 'top-sides'), as in the original API.
 * The new avoid-list is an optional 4th argument.
 */
export function smartPosition(editor, floatingEl, targetRect, preference = 'top', avoid = []) {
    if (!floatingEl || !targetRect) return;

    const originalDisplay = floatingEl.style.display;
    const originalVisibility = floatingEl.style.visibility;

    if (originalDisplay === 'none') {
        floatingEl.style.visibility = 'hidden';
        floatingEl.style.display = 'flex';
    }

    const viewport = { width: window.innerWidth, height: window.innerHeight };
    const floatRect = floatingEl.getBoundingClientRect();
    const w = floatRect.width;
    const h = floatRect.height;

    if (originalDisplay === 'none') {
        floatingEl.style.display = 'none';
        floatingEl.style.visibility = originalVisibility;
    }

    let toolbarBottom = 0;
    if (editor.toolbarContainer) {
        const tbRect = editor.toolbarContainer.getBoundingClientRect();
        if (tbRect.bottom > 0 && tbRect.top < viewport.height / 2) {
            toolbarBottom = tbRect.bottom;
        }
    }

    const gap = 12;
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    // --- Build the list of rectangles to avoid (other visible panels) ---
    // Each entry is {top, left, right, bottom} in VIEWPORT coordinates.
    const avoidRects = [];
    avoid.forEach((entry) => {
        if (!entry) return;
        // Accept either a DOM element or a plain rect.
        let r;
        if (entry.getBoundingClientRect) {
            const el = entry;
            // Skip elements that are not currently visible — they don't
            // take up space on screen.
            if (el.style.display === 'none' || el.style.visibility === 'hidden') return;
            r = el.getBoundingClientRect();
        } else {
            r = entry;
        }
        if (r.width === 0 || r.height === 0) return;
        avoidRects.push({
            top: r.top,
            left: r.left,
            right: r.right,
            bottom: r.bottom
        });
    });

    const posTop = {
        top: targetRect.top - h - gap,
        left: targetRect.left + (targetRect.width / 2) - (w / 2),
        name: 'top'
    };

    const posBottom = {
        top: targetRect.bottom + gap,
        left: targetRect.left + (targetRect.width / 2) - (w / 2),
        name: 'bottom'
    };

    const posRight = {
        top: targetRect.top,
        left: targetRect.right + gap,
        name: 'side-right'
    };

    const posLeft = {
        top: targetRect.top,
        left: targetRect.left - w - gap,
        name: 'side-left'
    };

    const isValid = (pos, type) => {
        if (pos.top < toolbarBottom) return false;
        if (pos.top + h > viewport.height) return false;
        if (type.startsWith('side')) {
            if (pos.left < 0) return false;
            if (pos.left + w > viewport.width) return false;
        }
        return true;
    };

    // Does this candidate position overlap any of the avoid rectangles?
    const overlapsAnyAvoid = (pos) => {
        const posRight2 = pos.left + w;
        const posBottom2 = pos.top + h;
        for (const ar of avoidRects) {
            const verticalOverlap = (pos.top < ar.bottom && posBottom2 > ar.top);
            const horizontalOverlap = (pos.left < ar.right && posRight2 > ar.left);
            if (verticalOverlap && horizontalOverlap) return true;
        }
        return false;
    };

    // Score: lower is better. 0 = no overlap, 1 = overlaps an avoid-rect.
    const score = (pos) => overlapsAnyAvoid(pos) ? 1 : 0;

    let best = null;
    let bestScore = Infinity;

    const consider = (pos, type) => {
        if (!isValid(pos, type)) return;
        const s = score(pos);
        if (s < bestScore) {
            best = pos;
            bestScore = s;
        }
    };

    if (preference === 'top-sides') {
        // Boxes: prefer top, then sides, never bottom (reserved for + button)
        consider(posTop, 'vertical');
        consider(posRight, 'side-right');
        consider(posLeft, 'side-left');

        // If nothing fits perfectly, force Top (clamping will fix visibility)
        if (!best) best = posTop;
    } else if (preference === 'bottom') {
        consider(posBottom, 'vertical');
        consider(posTop, 'vertical');
    } else {
        // Default 'top' behavior
        consider(posTop, 'vertical');
        consider(posBottom, 'vertical');
    }

    // Side fallback for standard modes (only on wide screens)
    if (bestScore > 0 && preference !== 'top-sides' && viewport.width > 768) {
        consider(posRight, 'side-right');
        consider(posLeft, 'side-left');
    }

    // Ultimate fallback for standard modes — pick whichever side has more space
    if (!best && preference !== 'top-sides') {
        const spaceAbove = targetRect.top - toolbarBottom;
        const spaceBelow = viewport.height - targetRect.bottom;

        if (preference === 'bottom') {
            best = (spaceBelow > h || spaceBelow > spaceAbove) ? posBottom : posTop;
        } else {
            best = (spaceAbove > h || spaceAbove > spaceBelow) ? posTop : posBottom;
        }
    }

    // --- Clamping ---
    if (best.top < toolbarBottom) best.top = toolbarBottom + gap;
    if (best.top + h > viewport.height) best.top = viewport.height - h - gap;

    if (best.left < 10) best.left = 10;
    if (best.left + w > viewport.width - 10) best.left = viewport.width - w - 10;

    // --- Strict overlap avoidance with the TARGET element itself ---
    // (Original logic — kept for backward compatibility.)
    const verticalOverlap = (best.top < targetRect.bottom && best.top + h > targetRect.top);
    const horizontalOverlap = (best.left < targetRect.right && best.left + w > targetRect.left);

    if (verticalOverlap && horizontalOverlap) {
        const tryRight = targetRect.right + gap;
        const tryLeft = targetRect.left - w - gap;

        const fitRight = tryRight + w <= viewport.width;
        const fitLeft = tryLeft >= 0;

        if (fitRight) {
            best.left = tryRight;
            const desiredTop = targetRect.top;
            best.top = Math.max(toolbarBottom + gap, Math.min(desiredTop, viewport.height - h - gap));
        } else if (fitLeft) {
            best.left = tryLeft;
            const desiredTop = targetRect.top;
            best.top = Math.max(toolbarBottom + gap, Math.min(desiredTop, viewport.height - h - gap));
        }
    }

    // --- Final pass: if we still overlap an avoid-rect, try to nudge ---
    if (bestScore > 0 && avoidRects.length > 0) {
        // Try shifting down below all avoid-rects
        let maxBottom = 0;
        for (const ar of avoidRects) {
            if (ar.bottom > maxBottom) maxBottom = ar.bottom;
        }
        if (maxBottom + gap + h <= viewport.height) {
            best.top = maxBottom + gap;
        }
    }

    floatingEl.style.top = `${best.top + scrollY}px`;
    floatingEl.style.left = `${best.left + scrollX}px`;
}

/**
 * Helper: collect all currently-visible Lumina floating panels EXCEPT
 * the one we are about to position. Used as the `avoid` list when
 * calling smartPosition so that, e.g., the image toolbar doesn't
 * stack on top of the block toolbar of the block that contains it.
 */
export function collectVisiblePanels(editor, exceptEl) {
    const panels = [];
    const candidates = [
        editor.bubble, editor.textSettings,
        editor.blockToolbar, editor.blockSettings,
        editor.imgToolbar, editor.imgSettings,
        editor.boxToolbar, editor.boxSettings,
        editor.btnToolbar,
        editor.codeBlockToolbar,
        editor.tblToolbar,
        editor.mapToolbar,
        editor.quickInsert
    ];
    candidates.forEach((el) => {
        if (!el) return;
        if (el === exceptEl) return;
        if (el.style.display === 'none') return;
        if (el.style.visibility === 'hidden') return;
        panels.push(el);
    });
    return panels;
}
