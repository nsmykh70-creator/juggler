/**
 * AICMS Admin UI Enhancements
 * =================================
 * 1. Auto-dismiss flash messages after 3 seconds (smooth animation).
 * 2. Floating Action Bar (FAB) — always-accessible Save / Back buttons
 *    with language-neutral icons.
 * 3. Rubber stretch-on-hover for ALL labeled buttons (auto-wrap text
 *    into .btn-label spans, apply .aicms-btn--stretch modifier).
 * 4. Stretch coordinator — staggered hand-off: when the cursor moves
 *    from button A to button B, B starts expanding immediately and
 *    A only starts collapsing AFTER B finishes expanding. The speed
 *    is randomized in the 650–900 ms range on each hover for an
 *    organic feel.
 *
 * This file is auto-loaded on every admin page via header.php.
 */
(function () {
    'use strict';

    /* ----------------------------------------------------------
       1. AUTO-DISMISS FLASH MESSAGES
       ---------------------------------------------------------- */
    function initAutoDismissAlerts() {
        var alerts = document.querySelectorAll('.alert-auto-dismiss');
        Array.prototype.forEach.call(alerts, function (alert) {
            // Skip if already scheduled
            if (alert.getAttribute('data-auto-dismiss-scheduled')) return;
            alert.setAttribute('data-auto-dismiss-scheduled', '1');

            // 3 seconds until hide animation starts
            setTimeout(function () {
                // Bootstrap dismiss may have already removed the element
                if (!alert || !alert.parentNode) return;
                alert.classList.add('is-hiding');
                // Remove from DOM after the CSS transition completes
                setTimeout(function () {
                    if (alert && alert.parentNode) {
                        // Try Bootstrap's dispose first
                        if (window.bootstrap && bootstrap.Alert) {
                            try {
                                var bsAlert = bootstrap.Alert.getInstance(alert);
                                if (bsAlert) bsAlert.dispose();
                            } catch (e) { /* noop */ }
                        }
                        alert.parentNode.removeChild(alert);
                    }
                }, 450);
            }, 3000);

            // Pause auto-dismiss on hover so the user can read long messages
            alert.addEventListener('mouseenter', function () {
                alert.style.animationPlayState = 'paused';
                // Stop the progress bar animation
                var after = alert; // can't directly target ::after via JS
                alert.classList.add('is-paused');
            });
            alert.addEventListener('mouseleave', function () {
                alert.classList.remove('is-paused');
            });
        });
    }

    /* ----------------------------------------------------------
       2. FLOATING ACTION BAR (FAB)
       ----------------------------------------------------------
       The FAB is built from <button data-fab-action="..."> elements
       that already exist on the page (in the legacy action footer).
       We clone them into a floating pill so they're always visible.
       The original footer is hidden via the `has-aicms-fab` body class.
    */
    function buildFab() {
        // Prevent double-init
        if (document.querySelector('.aicms-fab-bar')) return;

        // Only build FAB on pages that have a legacy-action-footer.
        // Do NOT use the fallback search — it grabs buttons from the
        // action panel (like "Страница новостей" on pages.php) and
        // hides the entire action panel body.
        var footer = document.querySelector('.legacy-action-footer');
        if (!footer) { return; }

        // Safety: if the footer is inside the action panel, skip FAB
        if (footer.closest('.aicms-action-panel')) return;

        // Collect all actions from the footer
        var saveBtn = footer.querySelector('button[type="submit"]');
        // Back link — any <a> that's not a .aicms-btn--primary (avoid "Save" links)
        var allLinks = footer.querySelectorAll('a');
        var backLink = null;
        for (var j = 0; j < allLinks.length; j++) {
            var t = (allLinks[j].textContent || '').trim();
            if (/←|back|назад|к списку/i.test(t) || allLinks[j].querySelector('.fa-arrow-left')) {
                backLink = allLinks[j];
                break;
            }
        }
        // Extra action (history, settings, etc.)
        var extraLink = null;
        for (var k = 0; k < allLinks.length; k++) {
            if (allLinks[k] === backLink) continue;
            var tt = (allLinks[k].textContent || '').trim();
            if (/истор|history|настройк|settings|верс/i.test(tt) || allLinks[k].querySelector('.fa-history, .fa-cog')) {
                extraLink = allLinks[k];
                break;
            }
        }

        // Build the FAB container
        var fabBar = document.createElement('div');
        fabBar.className = 'aicms-fab-bar';
        fabBar.setAttribute('role', 'toolbar');
        fabBar.setAttribute('aria-label', 'Быстрые действия');
        // FAB buttons are stacked VERTICALLY — they must stretch as one
        // whole block (all buttons expand together on hover/focus).
        fabBar.setAttribute('data-stretch-block', '1');

        var actionsWrap = document.createElement('div');
        actionsWrap.className = 'aicms-fab-actions';

        // Back button (slate)
        if (backLink) {
            var backFab = document.createElement('button');
            backFab.type = 'button';
            backFab.className = 'aicms-fab-btn aicms-fab-btn--back';
            var backLabel = (backLink.textContent || '').trim() || 'Назад';
            backFab.innerHTML = '<i class="fas fa-arrow-left"></i><span class="btn-label">' + backLabel + '</span>';
            backFab.addEventListener('click', function () {
                window.location.href = backLink.getAttribute('href');
            });
            actionsWrap.appendChild(backFab);
        }

        // Extra button (amber, for history/settings)
        if (extraLink) {
            var extraFab = document.createElement('button');
            extraFab.type = 'button';
            extraFab.className = 'aicms-fab-btn aicms-fab-btn--extra';
            var extraIcon = extraLink.querySelector('i');
            var extraLabel = (extraLink.textContent || '').trim() || 'История';
            extraFab.innerHTML = (extraIcon ? extraIcon.outerHTML : '<i class="fas fa-history"></i>') + '<span class="btn-label">' + extraLabel + '</span>';
            extraFab.addEventListener('click', function () {
                window.location.href = extraLink.getAttribute('href');
            });
            actionsWrap.appendChild(extraFab);
        }

        // Save button (green, primary)
        if (saveBtn) {
            var saveFab = document.createElement('button');
            saveFab.type = 'button';
            saveFab.className = 'aicms-fab-btn aicms-fab-btn--save';
            var saveText = (saveBtn.textContent || '').trim() || 'Сохранить';
            saveFab.innerHTML = '<i class="fas fa-save"></i><span class="btn-label">' + saveText + '</span>';
            saveFab.addEventListener('click', function () {
                if (saveBtn.getAttribute('onclick')) {
                    saveBtn.click();
                    return;
                }
                var form = saveBtn.closest('form');
                if (form) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    if (saveBtn.name) { hidden.name = saveBtn.name; hidden.value = saveBtn.value; }
                    form.appendChild(hidden);
                    form.submit();
                }
            });
            actionsWrap.appendChild(saveFab);
        }

        fabBar.appendChild(actionsWrap);

        // Collapse / expand toggle (blue)
        var toggleFab = document.createElement('button');
        toggleFab.type = 'button';
        toggleFab.className = 'aicms-fab-btn aicms-fab-btn--toggle';
        toggleFab.innerHTML = '<i class="fas fa-chevron-down"></i><span class="btn-label">Свернуть</span>';
        toggleFab.setAttribute('aria-expanded', 'true');
        toggleFab.addEventListener('click', function () {
            var collapsed = fabBar.classList.toggle('is-collapsed');
            toggleFab.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggleFab.innerHTML = collapsed
                ? '<i class="fas fa-chevron-up"></i><span class="btn-label">Развернуть</span>'
                : '<i class="fas fa-chevron-down"></i><span class="btn-label">Свернуть</span>';
        });
        fabBar.appendChild(toggleFab);

        document.body.appendChild(fabBar);
        document.body.classList.add('has-aicms-fab');

        // Keep expanded by default on all screens
    }

    /* ----------------------------------------------------------
       3. NEW COMMENTS NOTIFICATION TOAST
       ----------------------------------------------------------
       Shows a toast notification if there are pending comments.
       Data is injected via <meta name="aicms-pending-comments" content="N">
       added by sidebar.php on every admin page.
    */
    function showPendingCommentsToast() {
        var meta = document.querySelector('meta[name="aicms-pending-comments"]');
        if (!meta) return;
        var count = parseInt(meta.getAttribute('content'), 10);
        if (isNaN(count) || count <= 0) return;

        // Don't show on comments.php itself (user is already there)
        if (window.location.pathname.indexOf('comments.php') !== -1) return;

        // Check if already shown in this session
        try {
            if (sessionStorage.getItem('aicms_comments_toast_' + count) === '1') return;
        } catch(e) {}

        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;background:#fff;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:380px;transform:translateX(420px);transition:transform 0.4s ease;cursor:pointer;';
        toast.innerHTML =
            '<div style="width:40px;height:40px;border-radius:50%;background:#fef3c7;color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">' +
            '<i class="fas fa-comment-dots"></i></div>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:600;color:#1e293b;font-size:14px;">Новые комментарии</div>' +
            '<div style="color:#64748b;font-size:13px;margin-top:2px;">На модерации: <strong style="color:#f59e0b;">' + count + '</strong>. Нажмите, чтобы перейти.</div>' +
            '</div>' +
            '<button type="button" class="toast-close-btn" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;">&times;</button>';
        toast.onclick = function() { window.location.href = 'comments.php'; };
        document.body.appendChild(toast);
        var toast_ref = toast;
        var closeBtn = toast.querySelector('.toast-close-btn');
        if (closeBtn) { closeBtn.addEventListener('click', function(e) { e.stopPropagation(); toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }); }
        setTimeout(function() { toast_ref.style.transform = 'translateX(0)'; }, 100);
        // Auto-hide after 8 seconds
        setTimeout(function() {
            toast_ref.style.transform = 'translateX(420px)';
            setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400);
        }, 8000);
        try { sessionStorage.setItem('aicms_comments_toast_' + count, '1'); } catch(e) {}
    }

    /* ----------------------------------------------------------
       3b. NEW CONTACT SUBMISSIONS NOTIFICATION TOAST
       ---------------------------------------------------------- */
    function showPendingSubmissionsToast() {
        var meta = document.querySelector('meta[name="aicms-pending-submissions"]');
        if (!meta) return;
        var count = parseInt(meta.getAttribute('content'), 10);
        if (isNaN(count) || count <= 0) return;
        if (window.location.pathname.indexOf('contact_submissions.php') !== -1) return;
        try {
            var lastShown = parseInt(sessionStorage.getItem('aicms_submissions_toast_ts') || '0', 10);
            var now = Date.now();
            if (now - lastShown < 5000) return;
            sessionStorage.setItem('aicms_submissions_toast_ts', String(now));
        } catch(e) {}
        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;background:#fff;border:1px solid #2563eb;border-left:4px solid #2563eb;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:380px;transform:translateX(420px);transition:transform 0.4s ease;cursor:pointer;';
        toast.innerHTML =
            '<div style="width:40px;height:40px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">' +
            '<i class="fas fa-envelope"></i></div>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:600;color:#1e293b;font-size:14px;">Новые сообщения с сайта</div>' +
            '<div style="color:#64748b;font-size:13px;margin-top:2px;">Новых заявок: <strong style="color:#2563eb;">' + count + '</strong>. Нажмите, чтобы просмотреть.</div>' +
            '</div>' +
            '<button type="button" class="toast-close-btn" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;">&times;</button>';
        toast.onclick = function() { window.location.href = 'contact_submissions.php'; };
        document.body.appendChild(toast);
        var toast_ref = toast;
        var closeBtn = toast.querySelector('.toast-close-btn');
        if (closeBtn) { closeBtn.addEventListener('click', function(e) { e.stopPropagation(); toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }); }
        setTimeout(function() { toast_ref.style.transform = 'translateX(0)'; }, 100);
        setTimeout(function() {
            toast_ref.style.transform = 'translateX(420px)';
            setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400);
        }, 10000);
    }

    /* ----------------------------------------------------------
       5. GLOBAL REAL-TIME POLLING FOR NEW SUBMISSIONS + COMMENTS
       ----------------------------------------------------------
       Polls both api_contact_submissions.php and api_comments.php
       every 15 seconds on ALL admin pages. Updates sidebar badges
       in real-time and shows toast notifications when new items arrive.

       Key fixes vs previous version:
       - Uses absolute path prefix (derived from <script src> of aicms-ui.js)
         so fetch works regardless of current page URL depth
       - Polls BOTH submissions AND comments (not just submissions)
       - Console debug output to help diagnose issues
    */
    function initSubmissionsPolling() {
        if (!document.getElementById('sidebar')) return;
        // Don't double-poll on contact_submissions.php (it has its own)
        var onSubmissionsPage = window.location.pathname.indexOf('contact_submissions.php') !== -1;
        var onCommentsPage = window.location.pathname.indexOf('comments.php') !== -1;

        // Derive the admin base URL from the current page.
        // Admin pages are at: http://host/admin/pagename.php
        // API files are at:   http://host/admin/api_*.php
        // So the base is the directory of the current page.
        var path = window.location.pathname;  // e.g. /admin/index.php or /aicms/admin/index.php
        var lastSlash = path.lastIndexOf('/');
        var adminBase = path.substring(0, lastSlash + 1);  // e.g. /admin/ or /aicms/admin/

        // Get CSRF token
        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="aicms-csrf-token"]');
        if (csrfMeta) { csrfToken = csrfMeta.getAttribute('content'); }
        if (!csrfToken) {
            var csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) csrfToken = csrfInput.value;
        }
        if (!csrfToken) {
            
            return;
        }

        // State tracking
        var subState = { lastCount: 0, hasBaseline: false };
        var comState = { lastCount: 0, hasBaseline: false };
        var pollingActive = true;

        document.addEventListener('visibilitychange', function() {
            pollingActive = !document.hidden;
            if (pollingActive) {
                if (!onSubmissionsPage) checkSubmissions();
                if (!onCommentsPage) checkComments();
            }
        });

        function updateSidebarBadge(linkSelector, count) {
            var link = document.querySelector(linkSelector);
            if (!link) return;
            var badge = link.querySelector('.animate-pulse');
            if (count > 0) {
                if (badge) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = '';
                } else {
                    badge = document.createElement('span');
                    badge.className = 'ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white animate-pulse';
                    badge.textContent = count > 99 ? '99+' : count;
                    link.appendChild(badge);
                }
            } else {
                if (badge) badge.style.display = 'none';
            }
        }

        // --- Poll contact submissions ---
        function checkSubmissions() {
            if (onSubmissionsPage || !pollingActive) return;
            var url = adminBase + 'api_contact_submissions.php?csrf_token=' + encodeURIComponent(csrfToken) + '&last_seen=0';
            fetch(url)
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(data) {
                    if (data.error) {  return; }
                    updateSidebarBadge('a[href="contact_submissions.php"]', data.new_count);
                    if (subState.hasBaseline && data.new_count > subState.lastCount) {
                        
                        showNewSubmissionToast(data.latest, data.new_count);
                    }
                    subState.lastCount = data.new_count;
                    subState.hasBaseline = true;
                })
                .catch(function(e) {
                    
                });
        }

        // --- Poll comments ---
        function checkComments() {
            if (onCommentsPage || !pollingActive) return;
            var url = adminBase + 'api_comments.php?csrf_token=' + encodeURIComponent(csrfToken);
            fetch(url)
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(data) {
                    if (data.error) {  return; }
                    // Comments badge is on the "Дополнения" link
                    var extLink = document.querySelector('a[href="extensions.php"]');
                    if (extLink) {
                        var badge = extLink.querySelector('.animate-pulse');
                        var totalCount = data.new_count;  // just pending comments
                        if (totalCount > 0) {
                            if (badge) {
                                badge.textContent = totalCount > 99 ? '99+' : totalCount;
                                badge.style.display = '';
                            } else {
                                badge = document.createElement('span');
                                badge.className = 'ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-red-500 text-white animate-pulse';
                                badge.textContent = totalCount > 99 ? '99+' : totalCount;
                                extLink.appendChild(badge);
                            }
                        } else {
                            if (badge) badge.style.display = 'none';
                        }
                    }
                    if (comState.hasBaseline && data.new_count > comState.lastCount) {
                        
                        showNewCommentToast(data.latest, data.new_count);
                    }
                    comState.lastCount = data.new_count;
                    comState.hasBaseline = true;
                })
                .catch(function(e) {
                    
                });
        }

        function showNewSubmissionToast(submission, count) {
            if (!submission) return;
            var existing = document.getElementById('global-submission-toast');
            if (existing) existing.remove();
            function escapeHtml(s) { if (!s) return ''; return String(s).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
            var toast = document.createElement('div');
            toast.id = 'global-submission-toast';
            toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;border:1px solid #2563eb;border-left:4px solid #2563eb;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:420px;transform:translateX(450px);transition:transform 0.4s ease;cursor:pointer;';
            toast.innerHTML =
                '<div style="width:42px;height:42px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-envelope"></i></div>' +
                '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:600;color:#1e293b;font-size:14px;">\uD83D\uDCE8 \u041D\u043E\u0432\u043E\u0435 \u0441\u043E\u043E\u0431\u0449\u0435\u043D\u0438\u0435 \u0441 \u0441\u0430\u0439\u0442\u0430!</div>' +
                '<div style="color:#64748b;font-size:13px;margin-top:2px;">\u041E\u0442: <strong style="color:#1e293b;">' + escapeHtml(submission.name) + '</strong></div>' +
                '<div style="color:#94a3b8;font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml((submission.message || '').substring(0, 80)) + '</div>' +
                '</div>' +
                '<button type="button" onclick="event.stopPropagation();var t=this.closest(\'#global-submission-toast\');t.style.transform=\'translateX(450px)\';setTimeout(function(){t.remove();},400);" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;flex-shrink:0;">&times;</button>';
            toast.onclick = function() { window.location.href = adminBase + 'contact_submissions.php'; };
            document.body.appendChild(toast);
            setTimeout(function() { toast.style.transform = 'translateX(0)'; }, 100);
            setTimeout(function() { if (toast.parentElement) { toast.style.transform = 'translateX(450px)'; setTimeout(function() { if (toast.parentElement) toast.remove(); }, 400); } }, 10000);
        }

        function showNewCommentToast(comment, count) {
            if (!comment) return;
            var existing = document.getElementById('global-comment-toast');
            if (existing) existing.remove();
            function escapeHtml(s) { if (!s) return ''; return String(s).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
            var toast = document.createElement('div');
            toast.id = 'global-comment-toast';
            toast.style.cssText = 'position:fixed;top:70px;right:20px;z-index:99999;background:#fff;border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:420px;transform:translateX(450px);transition:transform 0.4s ease;cursor:pointer;';
            toast.innerHTML =
                '<div style="width:42px;height:42px;border-radius:50%;background:#fef3c7;color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-comment-dots"></i></div>' +
                '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:600;color:#1e293b;font-size:14px;">\uD83D\uDCAC \u041D\u043E\u0432\u044B\u0439 \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439!</div>' +
                '<div style="color:#64748b;font-size:13px;margin-top:2px;">\u041E\u0442: <strong style="color:#1e293b;">' + escapeHtml(comment.author_name) + '</strong></div>' +
                '<div style="color:#94a3b8;font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml((comment.content || '').substring(0, 80)) + '</div>' +
                '</div>' +
                '<button type="button" onclick="event.stopPropagation();var t=this.closest(\'#global-comment-toast\');t.style.transform=\'translateX(450px)\';setTimeout(function(){t.remove();},400);" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;flex-shrink:0;">&times;</button>';
            toast.onclick = function() { window.location.href = adminBase + 'comments.php'; };
            document.body.appendChild(toast);
            setTimeout(function() { toast.style.transform = 'translateX(0)'; }, 100);
            setTimeout(function() { if (toast.parentElement) { toast.style.transform = 'translateX(450px)'; setTimeout(function() { if (toast.parentElement) toast.remove(); }, 400); } }, 10000);
        }

        // Initial check after 3 seconds (to get baseline), then poll every 15 seconds
        setTimeout(function() {
            
            if (!onSubmissionsPage) checkSubmissions();
            if (!onCommentsPage) checkComments();
            setInterval(function() {
                if (!onSubmissionsPage) checkSubmissions();
                if (!onCommentsPage) checkComments();
            }, 15000);
        }, 3000);
    }

    /* ----------------------------------------------------------
       4. DARK THEME + KEYBOARD SHORTCUTS
       ---------------------------------------------------------- */
    function initThemeAndShortcuts() {
        // Restore dark theme
        try {
            if (localStorage.getItem('aicms_dark') === '1') {
                document.body.classList.add('dark-theme');
                var icon = document.getElementById('themeIcon');
                if (icon) icon.className = 'fas fa-sun';
            }
        } catch(e) {}

        // Update icon on toggle
        document.addEventListener('click', function(e) {
            if (e.target.closest('.theme-toggle')) {
                var icon = document.getElementById('themeIcon');
                if (icon) {
                    var isDark = document.body.classList.contains('dark-theme');
                    icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S — submit the main form (save)
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                var form = document.querySelector('form[method="POST"]');
                if (form && !form.querySelector('input[name="batch_action"]')) {
                    var submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) submitBtn.click();
                }
                return false;
            }
            // Ctrl+/ — focus search
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                var search = document.querySelector('.aicms-topbar-actions input[type="text"]');
                if (search) search.focus();
                return false;
            }
            // Esc — close modals/dropdowns
            if (e.key === 'Escape') {
                document.querySelectorAll('.reply-form-container').forEach(function(c) { c.style.display = 'none'; });
            }
        });
    }

    /* ----------------------------------------------------------
       3c. NEW BOOKINGS NOTIFICATION TOAST
       ----------------------------------------------------------
       Shows a toast notification if there are new bookings.
       Data is injected via <meta name="aicms-pending-bookings" content="N">
       added by sidebar.php on every admin page.
    */
    function showPendingBookingsToast() {
        var meta = document.querySelector('meta[name="aicms-pending-bookings"]');
        if (!meta) return;
        var count = parseInt(meta.getAttribute('content'), 10);
        if (isNaN(count) || count <= 0) return;

        // Don't show on bookings.php itself (user is already there)
        if (window.location.pathname.indexOf('bookings.php') !== -1) return;

        // Check if already shown in this session
        try {
            if (sessionStorage.getItem('aicms_bookings_toast_' + count) === '1') return;
        } catch(e) {}

        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:140px;right:20px;z-index:9999;background:#fff;border:1px solid #16a34a;border-left:4px solid #16a34a;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:380px;transform:translateX(420px);transition:transform 0.4s ease;cursor:pointer;';
        toast.innerHTML =
            '<div style="width:40px;height:40px;border-radius:50%;background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">' +
            '<i class="fas fa-calendar-check"></i></div>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:600;color:#1e293b;font-size:14px;">Новые бронирования</div>' +
            '<div style="color:#64748b;font-size:13px;margin-top:2px;">Новых заявок: <strong style="color:#16a34a;">' + count + '</strong>. Нажмите, чтобы просмотреть.</div>' +
            '</div>' +
            '<button type="button" class="toast-close-btn" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;">&times;</button>';
        toast.onclick = function() { window.location.href = 'bookings.php'; };
        document.body.appendChild(toast);
        var toast_ref = toast;
        var closeBtn = toast.querySelector('.toast-close-btn');
        if (closeBtn) { closeBtn.addEventListener('click', function(e) { e.stopPropagation(); toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }); }
        setTimeout(function() { toast_ref.style.transform = 'translateX(0)'; }, 100);
        // Auto-hide after 10 seconds
        setTimeout(function() {
            toast_ref.style.transform = 'translateX(420px)';
            setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400);
        }, 10000);
        try { sessionStorage.setItem('aicms_bookings_toast_' + count, '1'); } catch(e) {}
    }


    /* ----------------------------------------------------------
       3d. NEW CAFE RESERVATIONS NOTIFICATION TOAST
       ---------------------------------------------------------- */
    function showPendingCafeToast() {
        var meta = document.querySelector('meta[name="aicms-pending-cafe"]');
        if (!meta) return;
        var count = parseInt(meta.getAttribute('content'), 10);
        if (isNaN(count) || count <= 0) return;
        if (window.location.pathname.indexOf('cafe_bookings.php') !== -1) return;
        try { if (sessionStorage.getItem('aicms_cafe_toast_' + count) === '1') return; } catch(e) {}
        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:200px;right:20px;z-index:9999;background:#fff;border:1px solid #f97316;border-left:4px solid #f97316;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:380px;transform:translateX(420px);transition:transform 0.4s ease;cursor:pointer;';
        toast.innerHTML = '<div style="width:40px;height:40px;border-radius:50%;background:#fff7ed;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-utensils"></i></div><div style="flex:1;"><div style="font-weight:600;color:#1e293b;font-size:14px;">Новые бронирования столиков</div><div style="color:#64748b;font-size:13px;margin-top:2px;">Новых заявок: <strong style="color:#f97316;">' + count + '</strong>. Нажмите, чтобы просмотреть.</div></div><button type="button" class="toast-close-btn" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;">&times;</button>';
        toast.onclick = function() { window.location.href = 'cafe_bookings.php'; };
        document.body.appendChild(toast);
        var toast_ref = toast;
        var closeBtn = toast.querySelector('.toast-close-btn');
        if (closeBtn) { closeBtn.addEventListener('click', function(e) { e.stopPropagation(); toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }); }
        setTimeout(function() { toast_ref.style.transform = 'translateX(0)'; }, 100);
        setTimeout(function() { toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }, 10000);
        try { sessionStorage.setItem('aicms_cafe_toast_' + count, '1'); } catch(e) {}
    }

    /* ----------------------------------------------------------
       3e. NEW SHOP ORDERS NOTIFICATION TOAST
       ---------------------------------------------------------- */
    function showPendingOrdersToast() {
        var meta = document.querySelector('meta[name="aicms-pending-orders"]');
        if (!meta) return;
        var count = parseInt(meta.getAttribute('content'), 10);
        if (isNaN(count) || count <= 0) return;
        if (window.location.pathname.indexOf('shop_orders.php') !== -1) return;
        try { if (sessionStorage.getItem('aicms_orders_toast_' + count) === '1') return; } catch(e) {}
        var toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;top:260px;right:20px;z-index:9999;background:#fff;border:1px solid #8b5cf6;border-left:4px solid #8b5cf6;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:380px;transform:translateX(420px);transition:transform 0.4s ease;cursor:pointer;';
        toast.innerHTML = '<div style="width:40px;height:40px;border-radius:50%;background:#f5f3ff;color:#8b5cf6;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;"><i class="fas fa-shopping-bag"></i></div><div style="flex:1;"><div style="font-weight:600;color:#1e293b;font-size:14px;">Новые заказы магазина</div><div style="color:#64748b;font-size:13px;margin-top:2px;">Новых заказов: <strong style="color:#8b5cf6;">' + count + '</strong>. Нажмите, чтобы просмотреть.</div></div><button type="button" class="toast-close-btn" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;">&times;</button>';
        toast.onclick = function() { window.location.href = 'shop_orders.php'; };
        document.body.appendChild(toast);
        var toast_ref = toast;
        var closeBtn = toast.querySelector('.toast-close-btn');
        if (closeBtn) { closeBtn.addEventListener('click', function(e) { e.stopPropagation(); toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }); }
        setTimeout(function() { toast_ref.style.transform = 'translateX(0)'; }, 100);
        setTimeout(function() { toast_ref.style.transform = 'translateX(420px)'; setTimeout(function() { if (toast_ref.parentElement) toast_ref.remove(); }, 400); }, 10000);
        try { sessionStorage.setItem('aicms_orders_toast_' + count, '1'); } catch(e) {}
    }

    /* ----------------------------------------------------------
       5. RUBBER STRETCH-ON-HOVER FOR ALL LAABELED BUTTONS
       ----------------------------------------------------------
       Walk every eligible button on the page, wrap its loose text
       node into <span class="btn-label">…</span> (if not already
       wrapped), and add the .aicms-btn--stretch modifier so the CSS
       in tailwind.css takes over (icon-only by default, expands to
       reveal label on hover at the speed of --aicms-stretch-duration).

       Eligibility rules — a button is stretched iff ALL of:
         • It already has class .aicms-btn (the regular labeled button)
           Buttons that already use .aicms-icon-btn / .aicms-act /
           .aicms-act-outline / .aicms-expand-btn / .aicms-fab-btn /
           .theme-toggle already stretch — we leave them alone.
         • It contains an <i> icon (else there's nothing to show
           when collapsed).
         • It has non-empty visible text content (after stripping
           the icon). Empty / icon-only buttons stay full-size.
         • It is NOT marked opt-out via .aicms-stretch-off on itself
           or any ancestor (e.g. form footers with full-width "Save").
         • It does NOT carry full-width utility classes:
           .w-full / .flex-1 / .block / .justify-center / .flex-grow
         • It is not a select-like custom control ([data-no-stretch]).
       ---------------------------------------------------------- */
    var STRETCH_FULLWIDTH_RE = /\b(?:w-full|flex-1|flex-grow|block|justify-center)\b/;
    var STRETCH_BUTTON_SELECTOR = '.aicms-btn';
    var STRETCH_SKIP_SELECTOR = '.aicms-icon-btn, .aicms-act, .aicms-act-outline, .aicms-expand-btn, .aicms-fab-btn, .theme-toggle, .aicms-stretch-off, [data-no-stretch]';

    function buttonHasIcon(btn) {
        return !!btn.querySelector('i.fa, i.fas, i.far, i.fab, i.fa-solid, i.fa-regular, i.fa-brands, i[class*="fa-"]');
    }

    function getDirectTextNodes(btn) {
        // Collect text nodes that are direct children of btn (not nested
        // inside <i>, <span class="btn-label">, etc.).
        var nodes = [];
        for (var n = btn.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) { // Node.TEXT_NODE
                var t = n.nodeValue.replace(/\s+/g, ' ').trim();
                if (t) nodes.push(n);
            }
        }
        return nodes;
    }

    function buttonAlreadyHasLabel(btn) {
        // Only treat as already-labelled if .btn-label has actual text.
        var lbl = btn.querySelector('.btn-label');
        if (!lbl) return false;
        return (lbl.textContent || '').trim().length > 0;
    }

    function shouldStretchButton(btn) {
        if (!btn || !btn.matches) return false;
        if (!btn.matches(STRETCH_BUTTON_SELECTOR)) return false;
        // Skip if button also matches a class that already stretches
        // (e.g. <a class="aicms-btn aicms-expand-btn">…</a>).
        if (btn.matches(STRETCH_SKIP_SELECTOR)) return false;
        // Skip opt-out by ancestor
        if (btn.closest('.aicms-stretch-off, [data-no-stretch]')) return false;
        // Skip full-width buttons
        var cls = btn.getAttribute('class') || '';
        if (STRETCH_FULLWIDTH_RE.test(cls)) return false;
        // Skip inline style width:100%
        if (/width\s*:\s*100%/.test(btn.getAttribute('style') || '')) return false;
        // Skip if no icon
        if (!buttonHasIcon(btn)) return false;
        // Skip if no text content at all
        var rawText = (btn.textContent || '').trim();
        if (!rawText) return false;
        // Skip if already has .aicms-btn--stretch (idempotency)
        if (btn.classList.contains('aicms-btn--stretch')) return false;
        return true;
    }

    function applyStretchToButton(btn) {
        if (btn.getAttribute('data-aicms-stretch-processed')) return;
        btn.setAttribute('data-aicms-stretch-processed', '1');

        if (!buttonAlreadyHasLabel(btn)) {
            // Wrap direct text nodes into <span class="btn-label">.
            var textNodes = getDirectTextNodes(btn);
            if (textNodes.length === 0) {
                // Maybe the text is inside an unknown wrapper — leave alone.
                btn.removeAttribute('data-aicms-stretch-processed');
                return;
            }
            var label = document.createElement('span');
            label.className = 'btn-label';
            // Preserve original order: insert the <span> where the first
            // text node lived, then move all direct text nodes inside it.
            var first = textNodes[0];
            btn.insertBefore(label, first);
            for (var i = 0; i < textNodes.length; i++) {
                label.appendChild(textNodes[i]);
            }
            // Trim leading/trailing whitespace inside the label.
            label.textContent = (label.textContent || '').replace(/\s+/g, ' ').trim();
            if (!label.textContent) {
                // Label is empty after trim — undo and skip.
                while (label.firstChild) btn.insertBefore(label.firstChild, label);
                if (label.parentNode) label.parentNode.removeChild(label);
                btn.removeAttribute('data-aicms-stretch-processed');
                return;
            }
        }
        btn.classList.add('aicms-btn--stretch');
    }

    function initStretchButtons(root) {
        var scope = root || document;
        var candidates = scope.querySelectorAll(STRETCH_BUTTON_SELECTOR);
        for (var i = 0; i < candidates.length; i++) {
            if (shouldStretchButton(candidates[i])) {
                applyStretchToButton(candidates[i]);
            }
        }
    }

    /* ----------------------------------------------------------
       5b. FILEMANAGER PANEL BUTTONS (.fm-fn-btn)
       ----------------------------------------------------------
       The filemanager function bar has ~15 coloured icon-only buttons
       (.fm-fn-btn) that have NO visible text — only a `title` attribute
       shown as a CSS tooltip on hover. We turn them into stretch buttons
       by:

         1. Creating a <span class="btn-label"> from the title attribute
            (since there's no loose text node to wrap).
         2. Adding the .fm-fn-btn--stretch modifier so the CSS in
            filemanager.php collapses them to 38×38 icon-only by default
            and expands to reveal the label on hover.
         3. Registering them with the global stretch coordinator (they
            are already in STRETCH_TRACKED_SELECTOR, so direction-aware
            hand-off + random 650–900ms duration apply automatically).

       Skip rules:
         • No title attribute → can't make a label, skip.
         • No <i> icon → nothing to show when collapsed, skip.
         • .fm-fn-search → it's a search input wrapper, not a button.
         • Already has .fm-fn-btn--stretch → idempotency.
       ---------------------------------------------------------- */
    function applyStretchToFilemanagerButton(btn) {
        if (btn.getAttribute('data-aicms-fm-stretch-processed')) return;
        if (btn.classList.contains('fm-fn-search')) return;
        if (btn.classList.contains('fm-fn-btn--stretch')) return;
        if (!buttonHasIcon(btn)) return;
        var title = (btn.getAttribute('title') || '').trim();
        if (!title) return;

        btn.setAttribute('data-aicms-fm-stretch-processed', '1');

        // Build the label span from the title attribute.
        if (!buttonAlreadyHasLabel(btn)) {
            var label = document.createElement('span');
            label.className = 'btn-label';
            label.textContent = title;
            btn.appendChild(label);
        }
        btn.classList.add('fm-fn-btn--stretch');
        // Remove the title attribute so the native CSS tooltip doesn't
        // duplicate the in-button label when expanded.
        btn.setAttribute('data-original-title', title);
        btn.removeAttribute('title');
    }

    function initFilemanagerStretchButtons(root) {
        var scope = root || document;
        var candidates = scope.querySelectorAll('.fm-fn-btn');
        for (var i = 0; i < candidates.length; i++) {
            applyStretchToFilemanagerButton(candidates[i]);
        }
    }

    /* ----------------------------------------------------------
       6. STRETCH COORDINATOR — 3-button sliding window
       ----------------------------------------------------------
       Behaviour (user spec, fourth iteration):

       Four icon buttons in a row:  [1] [2] [3] [4]
       Cursor on button 1 → buttons 1,2,3 expand (window = 1,2,3).
       Move cursor to button 2 → buttons 1,2,3 STAY expanded
                              (window = 2,1,3 = same set).
       Move cursor to button 3 → window slides to 2,3,4
                                 (button 1 collapses, button 4 expands).
       Move cursor to button 4 → window = 3,4 (no right neighbor,
                                 so 3,4 stay expanded).
       Cursor leaves the panel → ALL buttons collapse.

       Rule: hovering button X expands a 3-button window centered on X
       (X-1, X, X+1). At edges, fill from the available side so the
       window always has 3 buttons (if the group has ≥3 buttons).
       For small groups (≤3 buttons), the whole group expands.

       This eliminates the "cursor jumps over button" problem because
       the window only slides when the cursor crosses to a button
       outside the current window — and when it slides, only ONE
       button collapses while ONE expands, so the cursor stays over
       an expanded button.

       The window is recomputed on every mouseover (delegated on
       document). When the cursor leaves the group container
       entirely (mouseout with relatedTarget outside the group),
       all buttons in the group lose .aicms-stretch-keep.

       Speed: --aicms-stretch-duration is randomized to [650, 900] ms
       on every mouseover.

       Tracked button types:
         .aicms-btn--stretch, .aicms-icon-btn, .aicms-act,
         .aicms-act-outline, .aicms-fab-btn, .theme-toggle,
         .aicms-expand-btn, .fm-fn-btn--stretch
       ---------------------------------------------------------- */
    var STRETCH_TRACKED_SELECTOR =
        '.aicms-btn--stretch, .aicms-icon-btn, .aicms-act, .aicms-act-outline, ' +
        '.aicms-fab-btn, .theme-toggle, .aicms-expand-btn, .fm-fn-btn--stretch';

    // prefers-reduced-motion users get INSTANT stretch: the coordinator never
    // writes an animating CSS var (tailwind.css also zeroes transitions via a
    // media query as a belt-and-braces fallback). This covers every admin page
    // at once — topbar, FAB in editors, bulk bars (media/filemanager),
    // fm-fn-bar and the settings demo button.
    var prefersReducedMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function randomStretchMs() {
        if (prefersReducedMotion) return 0;
        // Prefer the admin-configured speed (data/config.json → ui.stretch_speed,
        // injected as window.AICMS_STRETCH_MS by tailwind_head.php). When set,
        // use it directly (plus a small ±5% organic jitter, rounded to 10ms);
        // otherwise fall back to the historical random 650–900 ms range.
        var configured = parseInt(window.AICMS_STRETCH_MS || '', 10);
        if (!isNaN(configured) && configured >= 200 && configured <= 2000) {
            var jitter = configured * (0.95 + Math.random() * 0.1);
            return Math.round(jitter / 10) * 10;
        }
        // Random value in [650, 900] ms, rounded to nearest 10.
        return Math.round((650 + Math.random() * 250) / 10) * 10;
    }

    function setStretchDuration(ms) {
        document.documentElement.style.setProperty('--aicms-stretch-duration', ms + 'ms');
    }

    function findTrackedButton(el) {
        if (!el || !el.closest) return null;
        return el.closest(STRETCH_TRACKED_SELECTOR);
    }

    // Find the closest ancestor of `btn` that contains 2+ tracked buttons
    // ALL ON THE SAME HORIZONTAL ROW (within a 5px tolerance). This is the
    // "group container" — the panel/toolbar where the sliding window operates.
    //
    // The same-row check is critical: without it, a container like
    // .aicms-action-panel-body (which uses flex-wrap: wrap) would group
    // buttons from MULTIPLE visual rows, causing buttons "above and below"
    // to react when hovering on one row. With the check, only buttons in
    // the same visual row as `btn` are considered part of the group.
    //
    // Hidden buttons (display:none, width=0, height=0) are filtered out
    // so that hidden modal buttons don't interfere with visible groups.
    function findGroupContainer(btn) {
        var btnTop = btn.getBoundingClientRect().top;
        var parent = btn.parentElement;
        while (parent && parent !== document.body) {
            // If this ancestor is a "vertical block" (data-stretch-block,
            // e.g. the FAB in document editors), the WHOLE block is the
            // group: all visible tracked buttons inside it stretch together,
            // regardless of their vertical offsets.
            if (parent.hasAttribute && parent.hasAttribute('data-stretch-block')) {
                var blockAll = parent.querySelectorAll(STRETCH_TRACKED_SELECTOR);
                var blockVisible = 0;
                for (var bi = 0; bi < blockAll.length; bi++) {
                    var bRect = blockAll[bi].getBoundingClientRect();
                    if (bRect.width === 0 || bRect.height === 0) continue;
                    blockVisible++;
                }
                if (blockVisible >= 2) return parent;
            }
            var allButtons = parent.querySelectorAll(STRETCH_TRACKED_SELECTOR);
            // Filter: visible + same row as btn
            var sameRowButtons = [];
            for (var i = 0; i < allButtons.length; i++) {
                var b = allButtons[i];
                var rect = b.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0) continue; // hidden
                if (Math.abs(rect.top - btnTop) <= 5) {
                    sameRowButtons.push(b);
                }
            }
            if (sameRowButtons.length >= 2) return parent;
            parent = parent.parentElement;
        }
        return null;
    }

    // Get all tracked buttons within a group container that are on the
    // SAME ROW as `btn`, in DOM order. This ensures the sliding window
    // only operates on buttons in the same visual row.
    // Excludes buttons marked with [data-stretch-independent] — those
    // operate as their own group of 1.
    function getGroupButtons(container, btn) {
        // Vertical blocks (data-stretch-block): every visible tracked button
        // inside the container is part of the group — the whole block
        // stretches together as one unit.
        if (container.hasAttribute && container.hasAttribute('data-stretch-block')) {
            var blockAll = container.querySelectorAll(STRETCH_TRACKED_SELECTOR);
            var blockResult = [];
            for (var bi = 0; bi < blockAll.length; bi++) {
                var bb = blockAll[bi];
                var bRect = bb.getBoundingClientRect();
                if (bRect.width === 0 || bRect.height === 0) continue;
                if (bb.hasAttribute && bb.hasAttribute('data-stretch-independent')) continue;
                blockResult.push(bb);
            }
            return blockResult;
        }
        var btnTop = btn.getBoundingClientRect().top;
        var allButtons = container.querySelectorAll(STRETCH_TRACKED_SELECTOR);
        var result = [];
        for (var i = 0; i < allButtons.length; i++) {
            var b = allButtons[i];
            var rect = b.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) continue; // hidden
            // Skip independent buttons — they are not part of any window group.
            if (b.hasAttribute && b.hasAttribute('data-stretch-independent')) continue;
            if (Math.abs(rect.top - btnTop) <= 5) {
                result.push(b);
            }
        }
        return result;
    }

    // Check whether `btn` is marked as an independent stretch button.
    // Independent buttons operate as their own group of 1: they expand on
    // hover, and when hovered, they do NOT cause other buttons in the same
    // row to expand (and other buttons do NOT cause this one to expand).
    function isIndependentButton(btn) {
        return !!(btn && btn.hasAttribute && btn.hasAttribute('data-stretch-independent'));
    }

    // Compute the N-button window indices [start, end] for a hovered
    // button at index `idx` in a group of `len` buttons. `size` is the
    // window size (default 3, can be overridden via data-stretch-window
    // on the group container).
    function computeWindow(idx, len, size) {
        if (len <= size) return { start: 0, end: len - 1 };
        var half = Math.floor((size - 1) / 2);
        var start = idx - half;
        var end = idx + half;
        // If size is even, add one extra to the right
        if (size % 2 === 0) end = end + 1;
        // Clamp to bounds, shifting the window
        if (start < 0) { start = 0; end = size - 1; }
        if (end > len - 1) { end = len - 1; start = len - size; }
        return { start: start, end: end };
    }

    function applyWindow(groupButtons, win) {
        for (var i = 0; i < groupButtons.length; i++) {
            if (i >= win.start && i <= win.end) {
                groupButtons[i].classList.add('aicms-stretch-keep');
            } else {
                groupButtons[i].classList.remove('aicms-stretch-keep');
            }
        }
    }

    function collapseGroup(container, btn) {
        var buttons = getGroupButtons(container, btn);
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].classList.remove('aicms-stretch-keep');
        }
    }

    // Collapse ALL tracked buttons in the document that are NOT on the same
    // row as `btn`. This is used when the cursor moves to a different row
    // (e.g. a different theme card on themes.php) — buttons in the previous
    // row's group must collapse even though they are inside the same DOM
    // container (the themes grid).
    //
    // Buttons inside the SAME vertical block (data-stretch-block, e.g. the
    // FAB in document editors) are ALWAYS excluded: the whole block expands
    // and collapses as one unit, so moving between its buttons must not
    // collapse its other members.
    function collapseOtherRows(btn) {
        if (!btn) return;
        var btnTop = btn.getBoundingClientRect().top;
        // Find the vertical block ancestor of btn, if any.
        var blockContainer = null;
        var bp = btn.parentElement;
        while (bp && bp !== document.body) {
            if (bp.hasAttribute && bp.hasAttribute('data-stretch-block')) {
                blockContainer = bp;
                break;
            }
            bp = bp.parentElement;
        }
        var all = document.querySelectorAll(STRETCH_TRACKED_SELECTOR + '.aicms-stretch-keep');
        for (var i = 0; i < all.length; i++) {
            var b = all[i];
            if (b === btn) continue;
            // Same vertical block — keep it expanded (the block is one unit).
            if (blockContainer && blockContainer.contains(b)) continue;
            var rect = b.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) continue; // hidden
            // If this kept-expanded button is on a DIFFERENT row than btn,
            // collapse it.
            if (Math.abs(rect.top - btnTop) > 5) {
                b.classList.remove('aicms-stretch-keep');
            }
        }
    }

    // Read the window size from the group container's data-stretch-window
    // attribute. Default is 3. The file manager function bar uses 5.
    function getWindowSize(container) {
        var size = parseInt(container.getAttribute('data-stretch-window') || '3', 10);
        if (isNaN(size) || size < 1) size = 3;
        if (size > 9) size = 9; // sanity limit
        return size;
    }

    function initStretchCoordinator() {
        // Initialize the CSS variable with a random value in range.
        setStretchDuration(randomStretchMs());

        // Remember which group container the cursor is currently working in.
        // The stretch speed is re-randomized ONLY when entering a NEW group
        // (or a lone button), so sliding between buttons of one group never
        // changes the animation speed mid-flight — this keeps the expand /
        // collapse motion smooth and organic.
        var lastStretchGroup = null;
        // The most recently focused tracked stretch button. Used by the
        // focusout handler so the group collapses even when focus leaves
        // via a non-tracked element (e.g. the topbar search field) and
        // then blurs from there.
        var lastFocusedBtn = null;

        // mouseover (delegated on document): cursor entered a tracked button.
        // Compute the N-button window for its group and apply .aicms-stretch-keep.
        document.addEventListener('mouseover', function (e) {
            var btn = findTrackedButton(e.target);
            if (!btn) return;
            // If cursor was already inside this button (moved onto a child),
            // do nothing — relatedTarget would be a descendant of btn.
            var related = e.relatedTarget;
            if (related && btn.contains(related)) return;

            // Re-randomize the duration only when entering a different group
            // (or a lone button). Sliding within the same group keeps the
            // current speed — no abrupt animation-speed jumps.
            var groupNow = findGroupContainer(btn);
            if (groupNow !== lastStretchGroup) {
                setStretchDuration(randomStretchMs());
                lastStretchGroup = groupNow;
            }

            // First: collapse any kept-expanded buttons that are NOT on the
            // same row as `btn`. This handles the case where the cursor
            // jumped from one row to another (e.g. a different theme card)
            // — the previous row's buttons must collapse. Without this,
            // buttons in the old row stay expanded because they are inside
            // the same DOM container as the new button.
            collapseOtherRows(btn);

            // Independent buttons: expand ONLY this button, do not affect
            // any other buttons in the row. The button's own :hover handles
            // the visual expansion; we just don't add .aicms-stretch-keep
            // to any other buttons.
            if (isIndependentButton(btn)) {
                // Remove .aicms-stretch-keep from all OTHER buttons on the
                // same row (so the independent button is the only one
                // expanded).
                var container = findGroupContainer(btn);
                if (container) {
                    var sameRowOthers = getGroupButtons(container, btn);
                    for (var i = 0; i < sameRowOthers.length; i++) {
                        sameRowOthers[i].classList.remove('aicms-stretch-keep');
                    }
                }
                return;
            }

            // Find the group container.
            var container = groupNow;
            if (!container) {
                // Single button with no group — let :hover handle it alone.
                return;
            }

            // Get all tracked buttons in the group ON THE SAME ROW, in DOM order.
            var groupButtons = getGroupButtons(container, btn);
            var idx = groupButtons.indexOf(btn);
            if (idx === -1) return;

            // Compute and apply the N-button window. Vertical blocks
            // (data-stretch-block) expand as a WHOLE block — every button
            // in the block, not a 3-button sliding window.
            var size = getWindowSize(container);
            if (container.hasAttribute && container.hasAttribute('data-stretch-block')) {
                size = groupButtons.length;
            }
            var win = computeWindow(idx, groupButtons.length, size);
            applyWindow(groupButtons, win);

            // If there is an independent button on the same row, collapse it
            // (e.g. "Активировать" should collapse when the 3-button window
            // of the right-side group is expanded).
            var allSameRow = container.querySelectorAll(STRETCH_TRACKED_SELECTOR);
            var btnTop = btn.getBoundingClientRect().top;
            for (var j = 0; j < allSameRow.length; j++) {
                var ib = allSameRow[j];
                if (ib === btn) continue;
                if (!isIndependentButton(ib)) continue;
                var r = ib.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) continue;
                if (Math.abs(r.top - btnTop) <= 5) {
                    ib.classList.remove('aicms-stretch-keep');
                }
            }
        });

        // mouseout (delegated on document): cursor left a tracked button.
        // If the new target is OUTSIDE the group container, collapse all
        // buttons in the group (on the same row).
        document.addEventListener('mouseout', function (e) {
            var btn = findTrackedButton(e.target);
            if (!btn) return;
            // If cursor is still inside btn (e.g. moved onto a child),
            // this is a false alarm.
            var related = e.relatedTarget;
            if (related && btn.contains(related)) return;

            var container = findGroupContainer(btn);
            if (!container) return;
            // If the new target is still inside the group container, the
            // window will be recomputed by the mouseover handler — do nothing.
            if (related && container.contains(related)) return;

            // Cursor left the group — collapse all buttons on the same row.
            collapseGroup(container, btn);
        });

        // Focus handling: a tracked button that receives keyboard focus
        // expands its group window (so keyboard users can read the label),
        // and the group collapses as soon as focus is lost — the requested
        // "collapse on focus loss" behaviour for stretch buttons.
        document.addEventListener('focusin', function (e) {
            var btn = findTrackedButton(e.target);
            if (!btn) return;
            lastFocusedBtn = btn;
            // Same stable-speed rule as mouseover: re-randomize only when
            // focus lands on a button in a different group.
            var focusGroup = findGroupContainer(btn);
            if (focusGroup !== lastStretchGroup) {
                setStretchDuration(randomStretchMs());
                lastStretchGroup = focusGroup;
            }
            collapseOtherRows(btn);

            // Independent buttons: expand ONLY this button (its own :focus
            // CSS handles the visuals); do not affect other buttons.
            if (isIndependentButton(btn)) return;

            var container = findGroupContainer(btn);
            if (!container) return;
            var groupButtons = getGroupButtons(container, btn);
            var idx = groupButtons.indexOf(btn);
            if (idx === -1) return;
            var size = getWindowSize(container);
            if (container.hasAttribute && container.hasAttribute('data-stretch-block')) {
                size = groupButtons.length;
            }
            var win = computeWindow(idx, groupButtons.length, size);
            applyWindow(groupButtons, win);
        });

        // When focus leaves a tracked button and the new target is outside
        // its group container, collapse the group (all buttons on the same
        // row / the whole vertical block).
        document.addEventListener('focusout', function (e) {
            var related = e.relatedTarget;
            // Focus moved to another tracked button — the focusin handler
            // recomputes the window; nothing to collapse here.
            if (related && findTrackedButton(related)) return;

            // The tracked button whose group may need collapsing: the element
            // losing focus if it is itself tracked, otherwise the last focused
            // tracked button (focus moved to a non-tracked element like the
            // search field and is now leaving from there).
            var btn = findTrackedButton(e.target) || lastFocusedBtn;
            if (!btn) return;
            if (related && btn.contains(related)) return;
            var container = findGroupContainer(btn);
            if (!container) return;
            // Close the group whenever focus leaves the tracked button — even
            // when the new focus target sits inside the same container (e.g.
            // the topbar search input): the requested "collapse on focus loss".
            collapseGroup(container, btn);
            lastFocusedBtn = null;
        });
    }

    /* ----------------------------------------------------------
       INIT
       ---------------------------------------------------------- */
    function init() {
        // Start the stretch coordinator early — it sets the initial
        // random stretch duration and binds the mouseover/mouseout
        // delegation listeners on document.
        initStretchCoordinator();
        initAutoDismissAlerts();
        buildFab();
        // Apply stretch modifier to all eligible labeled buttons.
        initStretchButtons(document);
        // Apply stretch to filemanager panel buttons (title-as-label).
        initFilemanagerStretchButtons(document);
        showPendingCommentsToast();
        showPendingBookingsToast();
        showPendingSubmissionsToast();
        showPendingCafeToast();
        showPendingOrdersToast();
        initThemeAndShortcuts();
        initSubmissionsPolling();

        // Re-scan for new buttons added dynamically (modals, AJAX content).
        if (window.MutationObserver) {
            var mo = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var m = mutations[i];
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var node = m.addedNodes[j];
                        if (node.nodeType !== 1) continue;
                        if (node.matches && node.matches(STRETCH_BUTTON_SELECTOR)) {
                            if (shouldStretchButton(node)) applyStretchToButton(node);
                        }
                        if (node.matches && node.matches('.fm-fn-btn')) {
                            applyStretchToFilemanagerButton(node);
                        }
                        if (node.querySelectorAll) {
                            var inner = node.querySelectorAll(STRETCH_BUTTON_SELECTOR);
                            for (var k = 0; k < inner.length; k++) {
                                if (shouldStretchButton(inner[k])) applyStretchToButton(inner[k]);
                            }
                            var fmInner = node.querySelectorAll('.fm-fn-btn');
                            for (var f = 0; f < fmInner.length; f++) {
                                applyStretchToFilemanagerButton(fmInner[f]);
                            }
                        }
                    }
                }
            });
            mo.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
