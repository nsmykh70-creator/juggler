<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

$submissions_file = __DIR__ . '/../data/contact_submissions.json';
$submissions = [];
if (file_exists($submissions_file)) {
    $submissions = json_decode(file_get_contents($submissions_file), true) ?: [];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        foreach ($submissions as &$s) {
            if ((int)$s['id'] === $id) {
                $s['status'] = 'read';
                break;
            }
        }
        unset($s);
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message(t('msg_message_read'), 'success');
        header('Location: contact_submissions.php');
        exit;
    }

    if ($action === 'mark_new' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        foreach ($submissions as &$s) {
            if ((int)$s['id'] === $id) {
                $s['status'] = 'new';
                break;
            }
        }
        unset($s);
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header('Location: contact_submissions.php');
        exit;
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];
        $submissions = array_values(array_filter($submissions, fn($s) => (int)$s['id'] !== $id));
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message(t('msg_message_deleted'), 'success');
        header('Location: contact_submissions.php');
        exit;
    }

    if ($action === 'bulk_delete' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        $submissions = array_values(array_filter($submissions, fn($s) => !in_array((int)$s['id'], $ids)));
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message(str_replace('{$count}', count($ids), t('msg_messages_deleted')), 'success');
        header('Location: contact_submissions.php');
        exit;
    }

    if ($action === 'mark_all_read') {
        foreach ($submissions as &$s) {
            $s['status'] = 'read';
        }
        unset($s);
        file_put_contents($submissions_file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        set_flash_message(t('msg_messages_all_read'), 'success');
        header('Location: contact_submissions.php');
        exit;
    }
}

// Sort: new first, then by date desc
usort($submissions, function($a, $b) {
    if (($a['status'] ?? '') !== ($b['status'] ?? '')) {
        return ($a['status'] ?? '') === 'new' ? -1 : 1;
    }
    return strtotime($b['created'] ?? 'now') <=> strtotime($a['created'] ?? 'now');
});

$new_count = count(array_filter($submissions, fn($s) => ($s['status'] ?? '') === 'new'));

$page_title = t('h_contact');
$breadcrumb = [
    ['label' => t('dashboard'), 'url' => 'index.php'],
    ['label' => t('h_contact')],
];

ob_start();
?>
<span class="text-sm text-slate-500 self-center">
    <?= t('messages_total') ?> <strong class="text-slate-700"><?= count($submissions) ?></strong>
    <?php if ($new_count > 0): ?>
    · <span class="text-blue-600 font-semibold"><?= t('messages_new') ?> <?= $new_count ?></span>
    <?php endif; ?>
</span>
<?php if ($new_count > 0): ?>
<form method="POST" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_all_read">
    <button type="submit" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-check-double"></i><span class="btn-label"><?= t('mark_all_read') ?></span></button>
</form>
<?php endif; ?>
<?php $action_panel = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_contact') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <?php display_flash_message(); ?>

            <?php if (empty($submissions)): ?>
            <div class="bg-white rounded-xl shadow-sm p-12 text-center text-slate-400">
                <i class="fas fa-envelope-open text-5xl text-slate-300 mb-4 block"></i>
                <p class="text-lg font-medium text-slate-500"><?= t('no_messages') ?></p>
                <p class="text-sm mt-2"><?= t('no_messages_hint') ?></p>
            </div>
            <?php else: ?>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-4 py-2 border-b border-slate-100 flex items-center gap-2 flex-wrap bg-slate-50">
                    <button type="button" onclick="document.querySelectorAll('.sub-cb').forEach(c=>c.checked=!c.checked)" class="text-xs px-2 py-1 bg-slate-200 rounded hover:bg-slate-300"><?= t('invert') ?></button>
                    <button type="button" onclick="bulkDelete()" class="text-xs px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700">
                        <i class="fas fa-trash"></i> <?= t('delete_selected_btn') ?>
                    </button>
                    <span class="text-xs text-slate-400 ml-auto"><i class="fas fa-info-circle"></i> <?= t('new_messages_hint') ?></span>
                </div>

                <form method="POST" id="bulkActionForm" style="display:none;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_delete" id="bulkActionInput">
                    <div id="bulkIdsContainer"></div>
                </form>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="px-4 py-3 w-8"><input type="checkbox" onclick="document.querySelectorAll('.sub-cb').forEach(c=>c.checked=this.checked)" class="w-4 h-4 rounded"></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('status') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('name') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('email') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('phone') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('message') ?></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('date') ?></th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider"><?= t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <?php foreach ($submissions as $s): ?>
                            <tr class="hover:bg-slate-50 transition-colors <?= ($s['status'] ?? '') === 'new' ? 'bg-blue-50' : '' ?>">
                                <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" class="sub-cb w-4 h-4 rounded"></td>
                                <td class="px-4 py-3">
                                    <?php if (($s['status'] ?? '') === 'new'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <i class="fas fa-circle text-[6px]"></i> <?= t('new_status') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-slate-200 text-slate-600">
                                            <i class="fas fa-check text-[8px]"></i> <?= t('read_status') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <strong class="text-sm text-slate-800"><?= htmlspecialchars($s['name'] ?? '') ?></strong>
                                    <?php if (!empty($s['ip'])): ?>
                                    <br><small class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($s['ip']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="mailto:<?= htmlspecialchars($s['email'] ?? '') ?>" class="text-sm text-blue-600 hover:underline">
                                        <?= htmlspecialchars($s['email'] ?? '') ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-600">
                                    <?= !empty($s['phone']) ? htmlspecialchars($s['phone']) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <div class="text-sm text-slate-600 truncate" title="<?= htmlspecialchars($s['message'] ?? '') ?>" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?= htmlspecialchars($s['message'] ?? '') ?>
                                    </div>
                                    <?php if (!empty($s['page'])): ?>
                                    <small class="text-xs text-slate-400"><?= t('page_label') ?>: <?= htmlspecialchars($s['page']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap">
                                    <?= isset($s['created']) ? date('d.m.Y H:i', strtotime($s['created'])) : '-' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-1 justify-end">
                                        <button type="button" class="aicms-act-outline aicms-act-outline--primary"
                                            onclick='viewSubmission(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-eye"></i><span class="btn-label"><?= t('view') ?></span>
                                        </button>
                                        <?php if (($s['status'] ?? '') === 'new'): ?>
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button type="submit" class="aicms-act-outline aicms-act-outline--success">
                                                <i class="fas fa-check"></i><span class="btn-label"><?= t('mark_read') ?></span>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_new">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button type="submit" class="aicms-act-outline aicms-act-outline--info">
                                                <i class="fas fa-envelope"></i><span class="btn-label"><?= t('mark_new') ?></span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <a href="mailto:<?= htmlspecialchars($s['email'] ?? '') ?>?subject=<?= rawurlencode(t('reply_email_subject')) ?>" class="aicms-act-outline aicms-act-outline--info" >
                                            <i class="fas fa-reply"></i><span class="btn-label"><?= t('reply_by_email') ?></span>
                                        </a>
                                        <form method="POST" class="inline" onsubmit="return confirm(<?= json_encode(str_replace('{$name}', $s['name'] ?? '', t('delete_message_confirm')), JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS) ?>)">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button type="submit" class="aicms-act-outline aicms-act-outline--danger">
                                                <i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="viewModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" onclick="if(event.target===this)closeModal()">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                    <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center sticky top-0 bg-white">
                        <h5 class="font-semibold text-slate-800 m-0"><?= t('view_message') ?></h5>
                        <button onclick="closeModal()" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
                    </div>
                    <div class="p-5" id="modalBody"></div>
                </div>
            </div>

            <script>
            function viewSubmission(data) {
                var body = document.getElementById('modalBody');
                var html = '<div class="grid grid-cols-2 gap-4 mb-4">';
                html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('name') ?></strong><p class="text-sm text-slate-800 mt-1">' + escapeHtml(data.name) + '</p></div>';
                html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('email') ?></strong><p class="text-sm mt-1"><a href="mailto:' + escapeHtml(data.email) + '" class="text-blue-600">' + escapeHtml(data.email) + '</a></p></div>';
                if (data.phone) {
                    html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('phone') ?></strong><p class="text-sm text-slate-800 mt-1">' + escapeHtml(data.phone) + '</p></div>';
                }
                html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('date') ?></strong><p class="text-sm text-slate-800 mt-1">' + escapeHtml(data.created) + '</p></div>';
                if (data.ip) {
                    html += '<div><strong class="text-xs text-slate-400 uppercase">IP</strong><p class="text-sm text-slate-500 font-mono mt-1">' + escapeHtml(data.ip) + '</p></div>';
                }
                if (data.page) {
                    html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('page_label') ?></strong><p class="text-sm text-slate-500 font-mono mt-1">?' + escapeHtml(data.page) + '</p></div>';
                }
                html += '</div>';
                html += '<div><strong class="text-xs text-slate-400 uppercase"><?= t('message') ?></strong><div class="mt-2 p-4 bg-slate-50 rounded-lg text-sm text-slate-700 whitespace-pre-wrap">' + escapeHtml(data.message) + '</div></div>';
                html += '<div class="mt-5 flex gap-2 justify-end">';
                html += '<a href="mailto:' + escapeHtml(data.email) + '?subject=' + encodeURIComponent('<?= t('reply_email_subject') ?>') + '" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-reply"></i><span class="btn-label"><?= t('reply_by_email') ?></span></a>';
                html += '</div>';
                body.innerHTML = html;
                var modal = document.getElementById('viewModal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
            function closeModal() {
                var modal = document.getElementById('viewModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            function escapeHtml(s) {
                if (!s) return '';
                return String(s).replace(/[&<>"']/g, function(c) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                });
            }
            function bulkDelete() {
                var checked = document.querySelectorAll('.sub-cb:checked');
                if (checked.length === 0) {
                    alert('<?= t('select_one_delete') ?>');
                    return;
                }
                if (!confirm('<?= t('delete_messages_confirm') ?>'.replace('{$count}', checked.length))) return;
                var container = document.getElementById('bulkIdsContainer');
                container.innerHTML = '';
                checked.forEach(function(cb) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = cb.value;
                    container.appendChild(input);
                });
                document.getElementById('bulkActionForm').submit();
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeModal();
            });

            // Real-time polling
            var csrfToken = '<?= csrf_field_value() ?>';
            var lastSeenId = <?php
                $max_id = 0;
                foreach ($submissions as $s) {
                    if ((int)($s['id'] ?? 0) > $max_id) $max_id = (int)$s['id'];
                }
                echo $max_id;
            ?>;
            var pollInterval = null;
            var pageVisible = true;
            document.addEventListener('visibilitychange', function() {
                pageVisible = !document.hidden;
                if (pageVisible && !pollInterval) {
                    checkForNewSubmissions();
                    pollInterval = setInterval(checkForNewSubmissions, 10000);
                } else if (!pageVisible && pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            });
            function checkForNewSubmissions() {
                if (!pageVisible) return;
                fetch('api_contact_submissions.php?csrf_token=' + csrfToken + '&last_seen=' + lastSeenId)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.error) return;
                        var badgeEl = document.querySelector('a[href="contact_submissions.php"] .animate-pulse');
                        if (badgeEl) {
                            if (data.new_count > 0) {
                                badgeEl.textContent = data.new_count > 99 ? '99+' : data.new_count;
                                badgeEl.style.display = '';
                            } else {
                                badgeEl.style.display = 'none';
                            }
                        }
                        if (data.has_new && data.latest) {
                            var latestId = parseInt(data.latest.id, 10);
                            if (latestId > lastSeenId) {
                                lastSeenId = latestId;
                                showNewSubmissionToast(data.latest, data.new_count);
                            }
                        }
                    })
                    .catch(function(e) {});
            }
            function showNewSubmissionToast(submission, newCount) {
                var existing = document.getElementById('new-submission-toast');
                if (existing) existing.remove();
                var toast = document.createElement('div');
                toast.id = 'new-submission-toast';
                toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;border:1px solid #2563eb;border-left:4px solid #2563eb;border-radius:10px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;max-width:420px;transform:translateX(450px);transition:transform 0.4s ease;cursor:pointer;';
                toast.innerHTML =
                    '<div style="width:42px;height:42px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">' +
                    '<i class="fas fa-envelope"></i></div>' +
                    '<div style="flex:1;min-width:0;">' +
                    '<div style="font-weight:600;color:#1e293b;font-size:14px;">\u041D\u043E\u0432\u043E\u0435 \u0441\u043E\u043E\u0431\u0449\u0435\u043D\u0438\u0435!</div>' +
                    '<div style="color:#64748b;font-size:13px;margin-top:2px;">\u041E\u0442: <strong style="color:#1e293b;">' + escapeHtml(submission.name) + '</strong></div>' +
                    '<div style="color:#94a3b8;font-size:12px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml((submission.message || '').substring(0, 80)) + '</div>' +
                    '</div>' +
                    '<button type="button" onclick="event.stopPropagation();var t=this.closest(\'#new-submission-toast\');t.style.transform=\'translateX(450px)\';setTimeout(function(){t.remove();},400);" style="background:none;border:0;color:#94a3b8;font-size:18px;cursor:pointer;padding:0 4px;flex-shrink:0;">&times;</button>';
                toast.onclick = function() { window.location.reload(); };
                document.body.appendChild(toast);
                setTimeout(function() { toast.style.transform = 'translateX(0)'; }, 100);
                setTimeout(function() {
                    if (toast.parentElement) {
                        toast.style.transform = 'translateX(450px)';
                        setTimeout(function() { if (toast.parentElement) toast.remove(); }, 400);
                    }
                }, 12000);
            }
            pollInterval = setInterval(checkForNewSubmissions, 10000);
            setTimeout(checkForNewSubmissions, 2000);
            </script>

            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
