<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_all') {
        audit_log_clear();
        set_flash_message(t('audit_cleared') ?? 'Журнал очищен', 'success');
        header('Location: audit.php');
        exit;
    } elseif ($action === 'cleanup') {
        $days = (int)($_POST['days'] ?? 30);
        if ($days < 1) $days = 30;
        $deleted = audit_log_cleanup($days);
        set_flash_message((t('audit_cleaned') ?? 'Удалено записей старше %d дней') . ': ' . $deleted, 'success');
        header('Location: audit.php');
        exit;
    } elseif ($action === 'delete_entry') {
        $index = (int)($_POST['index'] ?? -1);
        if ($index >= 0) {
            $logFile = __DIR__ . '/../logs/audit.json';
            if (file_exists($logFile)) {
                $logs = json_decode(file_get_contents($logFile), true) ?: [];
                // $index is from newest-first view, so actual index is count-1-index
                $actualIndex = count($logs) - 1 - $index;
                if (isset($logs[$actualIndex])) {
                    array_splice($logs, $actualIndex, 1);
                    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Entry not found']);
                }
            }
            exit;
        }
    }
}

// Filters from GET
$filter_user = trim($_GET['user'] ?? '');
$filter_action = trim($_GET['action_filter'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

// Read all logs (we'll filter in PHP for simplicity)
$all_logs = audit_log_read(1000, 0);
$total_all = audit_log_count();

// Apply filters
$filtered_logs = array_filter($all_logs, function($log) use ($filter_user, $filter_action, $filter_date_from, $filter_date_to) {
    if ($filter_user && stripos($log['user'], $filter_user) === false) return false;
    if ($filter_action && stripos($log['action'], $filter_action) === false) return false;
    if ($filter_date_from && $log['ts'] < $filter_date_from . ' 00:00:00') return false;
    if ($filter_date_to && $log['ts'] > $filter_date_to . ' 23:59:59') return false;
    return true;
});

$total_filtered = count($filtered_logs);
$total_pages = max(1, ceil($total_filtered / $per_page));
$offset = ($page - 1) * $per_page;
$audit_logs = array_slice($filtered_logs, $offset, $per_page);

ob_start();
?>
<form method="GET" class="flex gap-2 items-center flex-wrap">
    <input type="text" name="user" value="<?= h($filter_user) ?>" placeholder="<?= t('user') ?>" class="h-8 px-3 text-sm border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
    <input type="text" name="action_filter" value="<?= h($filter_action) ?>" placeholder="<?= t('action') ?>" class="h-8 px-3 text-sm border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
    <input type="date" name="date_from" value="<?= h($filter_date_from) ?>" class="h-8 px-2 text-sm border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
    <input type="date" name="date_to" value="<?= h($filter_date_to) ?>" class="h-8 px-2 text-sm border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500">
    <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-search"></i><span class="btn-label"><?= t('search_btn') ?? 'Поиск' ?></span></button>
    <a href="audit.php" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-times"></i></a>
</form>
<form method="POST" class="inline" onsubmit="return confirm('<?= t('audit_clear_confirm') ?? 'Очистить весь журнал действий? Это действие нельзя отменить.' ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_all">
    <button type="submit" class="aicms-icon-btn aicms-icon-btn--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('audit_clear_all') ?? 'Очистить всё' ?></span></button>
</form>
<form method="POST" class="inline" onsubmit="return confirm('<?= t('audit_cleanup_confirm') ?? 'Удалить записи старше выбранного количества дней?' ?>')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cleanup">
    <select name="days" class="h-8 px-2 text-sm border border-slate-300 rounded-lg outline-none">
        <option value="7">7 дней</option>
        <option value="30" selected>30 дней</option>
        <option value="90">90 дней</option>
        <option value="180">180 дней</option>
        <option value="365">1 год</option>
    </select>
    <button type="submit" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-broom"></i><span class="btn-label"><?= t('audit_cleanup') ?? 'Очистить старые' ?></span></button>
</form>
<?php $action_panel = ob_get_clean();
$page_title = t('audit_log');
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('audit_log')]];
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_audit') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .audit-row { cursor: pointer; }
        .audit-detail-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100001; align-items:center; justify-content:center; padding:20px; }
        .audit-detail-modal.show { display:flex; }
        .audit-detail-box { background:#fff; border-radius:12px; max-width:600px; width:100%; max-height:85vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px rgba(0,0,0,.25); }
        /* Light theme: details blocks */
        .audit-detail-pre, .audit-detail-text {
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:6px;
            padding:12px;
        }
        .audit-detail-pre {
            font-size:12px;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-all;
        }
        .audit-detail-text {
            font-size:13px;
            word-break:break-word;
            white-space:pre-wrap;
        }
        /* Dark theme overrides */
        body.dark-theme .audit-detail-box { background:#1e293b; color:#e2e8f0; }
        body.dark-theme .audit-detail-box h5 { color:#e2e8f0; }
        body.dark-theme .audit-detail-box .detail-row { border-color:#334155 !important; }
        body.dark-theme .audit-detail-box .detail-label { color:#94a3b8 !important; }
        body.dark-theme .audit-detail-box .detail-value { color:#e2e8f0 !important; }
        body.dark-theme .audit-detail-box .detail-value.text-slate-700 { color:#e2e8f0 !important; }
        body.dark-theme .audit-detail-box pre,
        body.dark-theme .audit-detail-box .audit-detail-pre,
        body.dark-theme .audit-detail-box .audit-detail-text { background:#0f172a !important; color:#e2e8f0 !important; border-color:#334155 !important; }
        body.dark-theme .audit-detail-box .detail-header { border-bottom-color:#334155 !important; }
        body.dark-theme .audit-detail-box .detail-footer { border-top-color:#334155 !important; }
        body.dark-theme .audit-detail-box button.text-slate-400:hover { color:#e2e8f0; }
        .truncate-cell { max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .truncate-cell:hover { white-space:normal; }
    </style>
</head>
<body class="bg-slate-100" <?php if (isset($_COOKIE['aicms_dark']) && $_COOKIE['aicms_dark'] === '1') echo 'class="bg-slate-100 dark-theme"'; ?>>
<script>try{if(localStorage.getItem('aicms_dark')==='1')document.body.classList.add('dark-theme');}catch(e){}</script>
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <?php display_flash_message(); ?>

            <!-- Stats bar -->
            <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                <div class="flex gap-4 text-sm">
                    <span class="text-slate-500"><?= t('audit_total') ?? 'Всего записей' ?>: <strong class="text-slate-700"><?= $total_all ?></strong></span>
                    <?php if ($total_filtered != $total_all): ?>
                    <span class="text-blue-600"><?= t('audit_filtered') ?? 'Отфильтровано' ?>: <strong><?= $total_filtered ?></strong></span>
                    <?php endif; ?>
                </div>
                <span class="text-xs text-slate-400"><?= t('audit_showing') ?? 'Показано' ?> <?= count($audit_logs) ?> <?= t('audit_of') ?? 'из' ?> <?= $total_filtered ?></span>
            </div>

            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><i class="fas fa-history mr-1"></i><?= t('h_audit') ?></div>
                <?php if (empty($audit_logs)): ?>
                <p class="px-5 py-8 text-center text-slate-400"><?= t('audit_empty') ?></p>
                <?php else: ?>
                <div class="overflow-x-auto"><table class="w-full">
                    <thead><tr class="border-b border-slate-200 bg-slate-50">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('time') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('user') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">IP</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('action') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('details') ?></th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase">⚙</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-200">
                    <?php foreach ($audit_logs as $i => $log): 
                        $global_index = $offset + $i;
                        $action_colors = ['create'=>'bg-green-100 text-green-800','edit'=>'bg-blue-100 text-blue-800','delete'=>'bg-red-100 text-red-800','login'=>'bg-purple-100 text-purple-800','logout'=>'bg-slate-100 text-slate-600','clear'=>'bg-orange-100 text-orange-800','upload'=>'bg-cyan-100 text-cyan-800','download'=>'bg-indigo-100 text-indigo-800'];
                        $color = 'bg-slate-100 text-slate-600';
                        foreach ($action_colors as $k=>$v) { if (strpos(strtolower($log['action']), $k) !== false) { $color = $v; break; } }
                    ?>
                    <tr class="hover:bg-slate-50 audit-row" onclick="showAuditDetail(<?= $global_index ?>)">
                        <td class="px-4 py-2 text-xs text-slate-500 font-mono whitespace-nowrap"><?= h($log['ts']) ?></td>
                        <td class="px-4 py-2 text-sm font-medium text-slate-700"><?= h($log['user']) ?></td>
                        <td class="px-4 py-2 text-xs text-slate-400 font-mono"><?= h($log['ip']) ?></td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $color ?>"><?= h($log['action']) ?></span></td>
                        <td class="px-4 py-2 text-xs text-slate-500 truncate-cell"><?= h($log['details']) ?></td>
                        <td class="px-4 py-2 text-center"><i class="fas fa-chevron-right text-slate-300"></i></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="px-5 py-3 border-t border-slate-200 flex items-center justify-between flex-wrap gap-2">
                    <div class="text-sm text-slate-500">
                        <?= t('page') ?? 'Страница' ?> <?= $page ?> <?= t('of') ?? 'из' ?> <?= $total_pages ?>
                    </div>
                    <div class="flex gap-1">
                        <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-chevron-left"></i></a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        for ($p = $start; $p <= $end; $p++):
                        ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="aicms-btn aicms-btn--sm <?= $p == $page ? 'aicms-btn--primary' : 'aicms-btn--secondary' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="aicms-btn aicms-btn--secondary aicms-btn--sm"><i class="fas fa-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Help -->
            <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                <i class="fas fa-info-circle"></i>
                <?= t('audit_help') ?? 'Клик по строке — подробная информация. Используйте фильтры для поиска. Журнал хранит последние 1000 записей.' ?>
            </div>
        </div>
    </div>
</div>

<!-- Detail Modal -->
<div class="audit-detail-modal" id="auditDetailModal" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="audit-detail-box">
        <div class="detail-header px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="font-semibold m-0 text-slate-800"><i class="fas fa-info-circle text-blue-600 mr-1"></i> <?= t('audit_detail') ?? 'Детали записи' ?></h5>
            <button onclick="document.getElementById('auditDetailModal').classList.remove('show')" class="text-slate-400 text-xl hover:text-slate-600">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto p-5" id="auditDetailBody">
            <div class="text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i> <?= t('loading') ?? 'Загрузка...' ?></div>
        </div>
        <div class="detail-footer px-5 py-3 border-t border-slate-200 flex justify-between">
            <button onclick="document.getElementById('auditDetailModal').classList.remove('show')" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-times"></i><span class="btn-label"><?= t('close') ?? 'Закрыть' ?></span></button>
            <button id="auditDeleteBtn" class="aicms-icon-btn aicms-icon-btn--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete_entry') ?? 'Удалить запись' ?></span></button>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
// All audit logs data (passed from PHP for detail view)
var allAuditLogs = <?= json_encode(array_values($filtered_logs), JSON_UNESCAPED_UNICODE) ?>;
var csrfToken = <?= json_encode(csrf_field_value()) ?>;

function showAuditDetail(globalIndex) {
    var modal = document.getElementById('auditDetailModal');
    var body = document.getElementById('auditDetailBody');
    var log = allAuditLogs[globalIndex];
    if (!log) return;

    var actionColors = {
        'create': '#16a34a', 'edit': '#2563eb', 'delete': '#dc2626',
        'login': '#7c3aed', 'logout': '#64748b', 'clear': '#ea580c',
        'upload': '#0891b2', 'download': '#4f46e5'
    };
    var color = '#64748b';
    for (var k in actionColors) {
        if (log.action.toLowerCase().indexOf(k) !== -1) { color = actionColors[k]; break; }
    }

    // Try to parse details as JSON (for structured data)
    var detailsHtml = '';
    try {
        var parsed = JSON.parse(log.details);
        detailsHtml = '<pre class="audit-detail-pre">' +
            escapeHtml(JSON.stringify(parsed, null, 2)) + '</pre>';
    } catch (e) {
        // Not JSON — show as text with word wrap
        detailsHtml = '<div class="audit-detail-text">' + escapeHtml(log.details) + '</div>';
    }

    body.innerHTML = 
        '<div class="space-y-3">' +
            '<div class="detail-row flex gap-3 pb-3 border-b border-slate-100">' +
                '<div class="detail-label w-24 text-xs text-slate-400 font-semibold uppercase flex-shrink-0 pt-1">' + 'Время' + '</div>' +
                '<div class="detail-value flex-1 text-sm font-mono text-slate-700">' + escapeHtml(log.ts) + '</div>' +
            '</div>' +
            '<div class="detail-row flex gap-3 pb-3 border-b border-slate-100">' +
                '<div class="detail-label w-24 text-xs text-slate-400 font-semibold uppercase flex-shrink-0 pt-1">' + 'Пользователь' + '</div>' +
                '<div class="detail-value flex-1 text-sm text-slate-700"><i class="fas fa-user mr-1 text-slate-400"></i>' + escapeHtml(log.user) + '</div>' +
            '</div>' +
            '<div class="detail-row flex gap-3 pb-3 border-b border-slate-100">' +
                '<div class="detail-label w-24 text-xs text-slate-400 font-semibold uppercase flex-shrink-0 pt-1">IP</div>' +
                '<div class="detail-value flex-1 text-sm font-mono text-slate-700"><i class="fas fa-network-wired mr-1 text-slate-400"></i>' + escapeHtml(log.ip) + '</div>' +
            '</div>' +
            '<div class="detail-row flex gap-3 pb-3 border-b border-slate-100">' +
                '<div class="detail-label w-24 text-xs text-slate-400 font-semibold uppercase flex-shrink-0 pt-1">' + 'Действие' + '</div>' +
                '<div class="detail-value flex-1"><span class="px-2 py-1 text-xs font-semibold rounded-full" style="background:' + color + '22;color:' + color + ';">' + escapeHtml(log.action) + '</span></div>' +
            '</div>' +
            '<div class="detail-row">' +
                '<div class="detail-label text-xs text-slate-400 font-semibold uppercase mb-2">' + 'Подробности' + '</div>' +
                '<div class="detail-value">' + detailsHtml + '</div>' +
            '</div>' +
        '</div>';

    // Set delete button handler
    var deleteBtn = document.getElementById('auditDeleteBtn');
    deleteBtn.onclick = function() {
        if (!confirm('Удалить эту запись из журнала?')) return;
        fetch('audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_entry&index=' + globalIndex + '&csrf_token=' + encodeURIComponent(csrfToken)
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.status === 'success') {
                document.getElementById('auditDetailModal').classList.remove('show');
                location.reload();
            } else {
                alert(d.message || 'Ошибка');
            }
        }).catch(function(e) { alert('Ошибка: ' + e.message); });
    };

    modal.classList.add('show');
}

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

// Keyboard: Esc closes modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('auditDetailModal').classList.remove('show');
    }
});
</script>
</body>
</html>
