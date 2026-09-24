<?php
/**
 * Module: Comments + Ratings
 * ===========================
 * Displays a comment form and list for news items.
 * Requires MySQL or SQLite (uses admin/core/db.php).
 *
 * Usage: {{module:comments}}
 * Place inside news content or news widget.
 *
 * The module automatically detects the current news ID from the URL
 * (?page=news shows all news; individual news items use #news-ID anchors).
 * For standalone pages, pass the target_id via shortcode: {{module:comments|target_id=5}}
 *
 * Features:
 *   - Comment form (name, email, text)
 *   - 5-star rating system
 *   - Threaded replies (parent_id)
 *   - Moderation (pending → approved)
 *   - IP tracking for spam prevention
 */

require_once __DIR__ . '/../admin/core/db.php';
require_once __DIR__ . '/../admin/core/module_settings.php';
require_once __DIR__ . '/../admin/core/user_auth.php';
require_once __DIR__ . '/../admin/core/extensions.php';
require_once __DIR__ . '/../admin/core/form_protection.php';

// Check if extension is enabled
if (!extension_enabled('comments')) {
    echo '';
    return;
}

// Get settings
$defaults = [
    'title'         => 'Комментарии',
    'show_ratings'  => true,
    'allow_replies' => true,
    'auto_approve'  => false,
    'per_page'      => 20,
];
$s = module_get_settings('comments', $defaults);

// Public-site CSRF token for the logout form (same session key as user.php / auth_block)
if (empty($_SESSION['site_csrf'])) {
    $_SESSION['site_csrf'] = bin2hex(random_bytes(32));
}
$comments_site_csrf = $_SESSION['site_csrf'];

// Determine target_id (news ID)
$target_id = $GLOBALS['module_inline_params']['target_id'] ?? 0;
if (empty($target_id)) {
    // Check global set by latest_news widget
    if (!empty($GLOBALS['current_news_item']['id'])) {
        $target_id = (int)$GLOBALS['current_news_item']['id'];
    } elseif (isset($news_item['id'])) {
        $target_id = $news_item['id'];
    } elseif (isset($currentPage['id'])) {
        $target_id = $currentPage['id'];
    }
}

// Handle form submission — only ONCE per page load (GLOBALS guard, survives across include)
$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_submit']) && $target_id > 0 && empty($GLOBALS['_comment_post_done'])) {
    // Form protection: honeypot + time-trap (silent bot drop)
    if (!form_honeypot_ok() || !form_timetrap_ok()) {
        $GLOBALS['_comment_post_done'] = true;
        $success = true; // pretend success to bots
    } else {
    // Rate limiting: 5 comments per 5 minutes per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_file = __DIR__ . '/../data/rate_limit_' . md5('comment_' . $ip) . '.json';
    $rl_data = file_exists($rl_file) ? json_decode(@file_get_contents($rl_file), true) : ['count' => 0, 'first' => time()];
    if (time() - $rl_data['first'] > 300) { $rl_data = ['count' => 0, 'first' => time()]; }
    if ($rl_data['count'] >= 5) {
        $error = 'Слишком много комментариев. Попробуйте через 5 минут.';
    } else {
    $rl_data['count']++;
    @file_put_contents($rl_file, json_encode($rl_data), LOCK_EX);
    $GLOBALS['_comment_post_done'] = true;
    $cu = current_user();
    if ($cu) {
        $name = $cu['display_name'] ?: $cu['username'];
        $email = $cu['email'];
        $status = ($cu['role'] === 'moderator' || $cu['role'] === 'admin') ? 'approved' : (!empty($s['auto_approve']) ? 'approved' : 'pending');
    } else {
        $name = trim($_POST['comment_name'] ?? '');
        $email = trim($_POST['comment_email'] ?? '');
        $status = !empty($s['auto_approve']) ? 'approved' : 'pending';
    }
    $text = trim($_POST['comment_text'] ?? '');
    $rating = (int)($_POST['comment_rating'] ?? 0);
    $parent_id = (int)($_POST['comment_parent'] ?? 0);

    if (empty($text)) {
        $error = 'Текст комментария обязателен.';
    } elseif (!$cu && empty($name)) {
        $error = 'Имя обязательно. Войдите в аккаунт или укажите имя.';
    } else {
        $db = db();
        if ($db) {
            // Anti-duplicate: check if same comment already exists in last 60 seconds
            // Use driver-compatible time syntax
            $driver = db_driver();
            $time_expr = $driver === 'mysql' ? "DATE_SUB(NOW(), INTERVAL 60 SECOND)" : "datetime('now', '-60 seconds')";
            $existing = null;
            try {
                $existing = db_row(
                    'SELECT id FROM ' . db_table('comments') . ' WHERE target_id = ? AND author_name = ? AND content = ? AND created_at > ' . $time_expr . ' LIMIT 1',
                    [$target_id, $name, $text]
                );
            } catch (Exception $e) {
                // If anti-duplicate check fails, proceed with insert anyway
            }
            if ($existing) {
                // Duplicate detected — silently skip, treat as success
                $success = true;
            } else {
                $lang = $GLOBALS['current_lang'] ?? 'ru';
                try {
                    $new_id = db_insert('comments', [
                        'target_type'  => 'news',
                        'target_id'    => $target_id,
                        'lang'         => $lang,
                        'parent_id'    => $parent_id,
                        'author_name'  => $name,
                        'author_email' => $email,
                        'content'      => $text,
                        'rating'       => max(0, min(5, $rating)),
                        'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
                        'status'       => $status,
                    ]);
                    // Try to link user_id if column exists
                    if ($cu && $new_id) {
                        try {
                            db_query('UPDATE ' . db_table('comments') . ' SET user_id = ? WHERE id = ?', [(int)$cu['id'], (int)$new_id]);
                            db_query('UPDATE ' . db_table('users') . ' SET comments_count = comments_count + 1 WHERE id = ?', [(int)$cu['id']]);
                        } catch (Exception $e) { /* column might not exist yet, ignore */ }
                    }
                    $success = true;
                    // Send notification
                    require_once __DIR__ . '/../admin/core/notifications.php';
                    notify('new_comment', ['author' => $name, 'content' => $text, 'target_id' => $target_id]);
                    // Webhook + Telegram notification
                    require_once __DIR__ . '/../admin/core/webhooks.php';
                    send_webhook('new_comment', ['author' => $name, 'content' => mb_substr($text, 0, 200), 'target_id' => $target_id]);
                    send_telegram("💬 <b>Новый комментарий</b>\nАвтор: " . htmlspecialchars($name) . "\nТекст: " . htmlspecialchars(mb_substr($text, 0, 200)));
                    // PRG: redirect to avoid re-POST on refresh
                    $redirect_url = $_SERVER['REQUEST_URI'] ?? ('?page=' . ($_GET['page'] ?? 'news'));
                    // Strip query params and add success marker
                    $redirect_url = preg_replace('/[?&]comment_success=[01]/', '', $redirect_url);
                    $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'comment_success=1';
                    $redirect_url .= '#news-' . $target_id;

                    // Clean any output buffers
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    if (!headers_sent()) {
                        header('Location: ' . $redirect_url, true, 303);
                        exit;
                    } else {
                        echo '<script>window.location.href = ' . json_encode($redirect_url) . ';</script>';
                        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect_url) . '"></noscript>';
                        die();
                    }
                } catch (Exception $e) {
                    $error = 'Ошибка сохранения: ' . $e->getMessage();
                }
            }
        } else {
            $error = 'База данных недоступна. Комментарии требуют MySQL или SQLite.';
        }
    }
    } // end rate limit else
    } // end honeypot/time-trap else
}

// Check for PRG success flag
if (!empty($_GET['comment_success'])) {
    $success = true;
}

// Load comments — fetch ALL approved comments for this target, then build tree
$comments = [];
$avg_rating = 0;
$ratings_count = 0;
if ($target_id > 0 && db_available()) {
    $all_comments = db_select('comments', '*', 'target_id = ? AND status = ?',
        [$target_id, 'approved'], 'created_at ASC', 200);
    // Build tree: top-level comments have parent_id=0
    $by_parent = [];
    foreach ($all_comments as $c) {
        $pid = (int)($c['parent_id'] ?? 0);
        $by_parent[$pid][] = $c;
    }
    // Sort top-level by created_at DESC
    $comments = $by_parent[0] ?? [];
    usort($comments, function($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });

    // Recursive function to attach replies
    $attach_replies = function($c) use (&$attach_replies, $by_parent) {
        $c['replies'] = $by_parent[(int)$c['id']] ?? [];
        foreach ($c['replies'] as &$r) {
            $r = $attach_replies($r);
        }
        unset($r);
        return $c;
    };
    foreach ($comments as &$c) { $c = $attach_replies($c); }
    unset($c);

    // Calculate average rating
    $ratings = db_select('comments', 'rating', 'target_id = ? AND status = ? AND rating > 0',
        [$target_id, 'approved']);
    if (!empty($ratings)) {
        $sum = array_sum(array_column($ratings, 'rating'));
        $ratings_count = count($ratings);
        $avg_rating = $ratings_count > 0 ? round($sum / $ratings_count, 1) : 0;
    }
}
?>
<div class="comments-module" style="max-width:700px; margin:2rem auto;">
    <h3 style="margin-bottom:1rem;"><?= htmlspecialchars($s['title']) ?>
        <?php if (!empty($s['show_ratings']) && $ratings_count > 0): ?>
        <span style="font-size:1rem; color:#f59e0b; margin-left:10px;">★ <?= $avg_rating ?> (<?= $ratings_count ?> оценок)</span>
        <?php endif; ?>
    </h3>

    <?php if ($success): ?>
    <div style="padding:12px 16px; background:#d1e7dd; color:#0f5132; border-radius:8px; margin-bottom:16px;">
        ✓ Комментарий отправлен! <?= !empty($s['auto_approve']) ? '' : 'Он появится после проверки модератором.' ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="padding:12px 16px; background:#f8d7da; color:#842029; border-radius:8px; margin-bottom:16px;">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (!db_available()): ?>
    <div style="padding:16px; background:#fff3cd; color:#856404; border-radius:8px; text-align:center;">
        ⚠️ Комментарии требуют подключения базы данных (MySQL или SQLite).
        Настройте БД в установщике или через config.json.
    </div>
    <?php elseif ($target_id > 0): ?>

    <!-- Comment Form -->
    <form method="POST" style="margin-bottom:2rem; padding:1.5rem; background:#f8fafc; border-radius:12px; position:relative;">
        <input type="hidden" name="comment_submit" value="1">
        <input type="hidden" name="comment_parent" id="comment_parent" value="0">
        <?= form_honeypot_field() ?>
        <?= form_timetrap_field() ?>
        <?php $cu = current_user(); ?>
        <?php if ($cu): ?>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:1rem; padding:8px 12px; background:#eff6ff; border-radius:8px; border:1px solid #dbeafe;">
            <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700;">
                <?= htmlspecialchars(mb_substr(user_display_name($cu), 0, 1, 'UTF-8')) ?>
            </div>
            <div style="flex:1;">
                <div style="font-weight:600; color:#1e293b; font-size:14px;"><?= htmlspecialchars(user_display_name($cu)) ?> <span style="color:#64748b; font-weight:400; font-size:13px;">@<?= htmlspecialchars($cu['username']) ?></span></div>
                <div style="font-size:12px; color:#64748b;">Вы вошли в аккаунт.
                    <form method="POST" action="user.php?action=logout" style="display:inline; margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($comments_site_csrf) ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;color:#2563eb;font-size:12px;padding:0;text-decoration:underline;">Выйти</button>
                    </form>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div style="margin-bottom:1rem; padding:10px 12px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; font-size:13px; color:#92400e;">
            <i class="fas fa-info-circle"></i> <a href="user.php?action=login&redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" style="color:#92400e; font-weight:600; text-decoration:underline;">Войдите</a> или <a href="user.php?action=register&redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>" style="color:#92400e; font-weight:600; text-decoration:underline;">зарегистрируйтесь</a> — и комментарии будут публиковаться сразу. Гости могут комментировать с указанием имени.
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
            <input type="text" name="comment_name" placeholder="Ваше имя *" required
                style="padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;">
            <input type="email" name="comment_email" placeholder="Email (не публикуется)"
                style="padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;">
        </div>
        <?php endif; ?>
        <?php if (!empty($s['show_ratings'])): ?>
        <div style="margin-bottom:1rem;">
            <label style="font-size:13px; color:#64748b; margin-bottom:4px; display:block;">Оценка:</label>
            <div class="rating-stars" style="display:inline-flex; gap:4px; font-size:1.6rem; cursor:pointer;">
                <span data-val="1" style="color:#cbd5e1;">★</span>
                <span data-val="2" style="color:#cbd5e1;">★</span>
                <span data-val="3" style="color:#cbd5e1;">★</span>
                <span data-val="4" style="color:#cbd5e1;">★</span>
                <span data-val="5" style="color:#cbd5e1;">★</span>
            </div>
            <input type="hidden" name="comment_rating" id="comment_rating" value="0">
        </div>
        <?php endif; ?>
        <textarea name="comment_text" placeholder="Ваш комментарий *" required rows="4"
            style="width:100%; padding:0.6rem 0.8rem; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; resize:vertical;"></textarea>
        <button type="submit" style="margin-top:0.8rem; padding:0.6rem 1.5rem; background:#2563eb; color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:600;">
            Отправить
        </button>
    </form>

    <!-- Comments List -->
    <?php if (empty($comments)): ?>
    <p style="color:#94a3b8; text-align:center;">Пока нет комментариев. Будьте первым!</p>
    <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:1rem;">
        <?php
        // Recursive render function
        $render_comment = function($c, $depth = 0) use (&$render_comment, $s) {
            $max_depth = 6;
            $avatar_color = 'linear-gradient(135deg,#2563eb,#7c3aed)';
            $bg = $depth === 0 ? '#fff' : '#f8fafc';
            $border = $depth === 0 ? '1px solid #e2e8f0' : '1px solid #e2e8f0';
            $ml = $depth > 0 ? '20px' : '0';
            $reply_enabled = !empty($s['allow_replies']) && $depth < $max_depth;
            ?>
            <div class="comment-item" data-id="<?= (int)$c['id'] ?>" style="padding:1rem 1.2rem; background:<?= $bg ?>; border:<?= $border ?>; border-radius:10px; margin-left:<?= $ml ?>; <?php if($depth>0): ?>border-left:3px solid #2563eb;<?php endif; ?>">
                <div style="display:flex; gap:10px; align-items:flex-start;">
                    <div style="width:32px; height:32px; border-radius:50%; background:<?= $avatar_color ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; flex-shrink:0;">
                        <?= htmlspecialchars(mb_substr($c['author_name'], 0, 1, 'UTF-8')) ?>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px;">
                            <strong style="font-size:14px; color:#1e293b;"><?= htmlspecialchars($c['author_name']) ?></strong>
                            <small style="color:#94a3b8; font-size:12px;"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($c['created_at']))) ?></small>
                        </div>
                        <?php if (!empty($s['show_ratings']) && $c['rating'] > 0): ?>
                        <div style="color:#f59e0b; margin-bottom:6px; font-size:14px; letter-spacing:2px;">
                            <?= str_repeat('★', $c['rating']) . str_repeat('☆', 5 - $c['rating']) ?>
                        </div>
                        <?php endif; ?>
                        <div style="line-height:1.6; color:#334155; font-size:14px; word-wrap:break-word;"><?= nl2br(htmlspecialchars($c['content'])) ?></div>

                        <?php if ($reply_enabled): ?>
                        <button type="button" class="comment-reply-btn" data-parent-id="<?= (int)$c['id'] ?>" style="margin-top:8px; padding:4px 12px; background:transparent; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer; font-size:12px; color:#475569; font-weight:500;">
                            <i class="fas fa-reply" style="margin-right:4px;"></i>Ответить
                        </button>
                        <?php endif; ?>

                        <!-- Reply form placeholder (hidden, shown on click) -->
                        <div class="reply-form-container" data-parent-id="<?= (int)$c['id'] ?>" style="display:none; margin-top:10px;"></div>
                    </div>
                </div>

                <?php if (!empty($c['replies'])): ?>
                <div style="margin-top:10px; display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($c['replies'] as $r): ?>
                        <?php $render_comment($r, $depth + 1); ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
        };
        foreach ($comments as $c) { $render_comment($c, 0); }
        ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
// Reply button — event delegation (works even for dynamically added/hidden elements)
if (!window._commentReplyInitialized) {
    window._commentReplyInitialized = true;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.comment-reply-btn');
        if (!btn) return;
        e.preventDefault();
        var parentId = btn.getAttribute('data-parent-id');
        if (!parentId) return;
        var container = document.querySelector('.reply-form-container[data-parent-id="' + parentId + '"]');
        if (!container) return;

        if (container.style.display === 'block' && container.innerHTML !== '') {
            container.style.display = 'none';
            return;
        }

        var loggedIn = document.querySelector('.auth-block form[action*="action=logout"]') !== null;
        var formHtml = '<form method="POST" style="padding:12px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; position:relative;">' +
            '<input type="hidden" name="comment_submit" value="1">' +
            '<input type="hidden" name="comment_parent" value="' + parentId + '">' +
            '<input type="hidden" name="comment_rating" value="0">' +
            '<div style="position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;" aria-hidden="true"><label>Website (leave empty)<input type="text" name="website_url" value="" tabindex="-1" autocomplete="off"></label></div>' +
            '<input type="hidden" name="form_loaded_at" value="' + Math.floor(Date.now()/1000) + '">';
        if (!loggedIn) {
            formHtml += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">' +
                '<input type="text" name="comment_name" placeholder="Ваше имя *" required style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">' +
                '<input type="email" name="comment_email" placeholder="Email" style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">' +
                '</div>';
        }
        formHtml += '<textarea name="comment_text" placeholder="Ваш ответ..." required rows="2" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; resize:vertical;"></textarea>' +
            '<div style="display:flex; gap:8px; margin-top:6px;">' +
            '<button type="submit" style="padding:5px 14px; background:#2563eb; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600;">Ответить</button>' +
            '<button type="button" class="reply-cancel-btn" style="padding:5px 14px; background:#e2e8f0; color:#475569; border:none; border-radius:6px; cursor:pointer; font-size:13px;">Отмена</button>' +
            '</div></form>';
        container.innerHTML = formHtml;
        container.style.display = 'block';
        container.querySelector('textarea').focus();
    });
    // Cancel button
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('reply-cancel-btn')) {
            var c = e.target.closest('.reply-form-container');
            if (c) c.style.display = 'none';
        }
    });
}
</script>

<?php if (!empty($s['show_ratings'])): ?>
<script>
(function() {
    var stars = document.querySelectorAll('.rating-stars span');
    var input = document.getElementById('comment_rating');
    stars.forEach(function(s) {
        s.addEventListener('click', function() {
            var val = parseInt(this.dataset.val);
            input.value = val;
            stars.forEach(function(st) {
                st.style.color = parseInt(st.dataset.val) <= val ? '#f59e0b' : '#cbd5e1';
            });
        });
    });
})();
</script>
<?php endif; ?>
