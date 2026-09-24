<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/audit.php';
require_auth();

$config = read_json(__DIR__ . '/../data/config.json');

// First-time onboarding redirect
if (empty($config['onboarding_complete']) && empty($_GET['onboarding_done']) && empty($config['site_title'])) {
    header('Location: onboarding.php');
    exit;
}

$page_count = 0;
$news_count = 0;
foreach (SUPPORTED_LANGS as $lang_info) {
    $lang_pages = read_content('pages.json', [], $lang_info['code']);
    $lang_news = read_content('news.json', [], $lang_info['code']);
    $page_count += count($lang_pages);
    $news_count += count($lang_news);
}
$current_theme = $config['theme'] ?? '—';
$php_version = phpversion();

// === Security audit data for the new dashboard widget ===
$users = read_json(__DIR__ . '/../data/users.json', []);
$has_2fa = !empty($users['totp_secret']);
$api_token_set = !empty($config['api_token']);
$install_exists = file_exists(__DIR__ . '/../install.php');
$pass_strength_ok = !empty($users['password']) && (strlen($users['password']) >= 60); // bcrypt hashes are ~60 chars
$csp_enabled = !empty($config['features']['csp_headers']);
$maintenance_on = !empty($config['maintenance']['enabled']) || file_exists(__DIR__ . '/../data/.maintenance');
$last_audit_entries = audit_log_read(5);

// Recent failed login attempts (count from rate limit file)
$login_attempts_file = __DIR__ . '/../data/login_attempts.json';
$failed_logins_24h = 0;
if (file_exists($login_attempts_file)) {
    $attempts = json_decode(@file_get_contents($login_attempts_file), true) ?: [];
    $cutoff = time() - 86400;
    foreach ($attempts as $key => $entry) {
        if (strpos($key, 'admin_login:') === 0 && ($entry['first_attempt'] ?? 0) > $cutoff) {
            $failed_logins_24h += $entry['count'] ?? 0;
        }
    }
}

// Compute security score (0-100)
$security_checks = [
    'install_deleted'   => !$install_exists,
    'has_2fa'           => $has_2fa,
    'api_token_set'     => $api_token_set,
    'pass_strong'       => $pass_strength_ok,
    'csp_enabled'       => $csp_enabled,
    'no_failed_logins'  => $failed_logins_24h === 0,
];
$security_score = (int)round((array_sum($security_checks) / count($security_checks)) * 100);

$visitor_stats_raw = read_json(__DIR__ . '/../data/stats.json');
if (!is_array($visitor_stats_raw)) $visitor_stats_raw = [];
$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d.m', strtotime($date));
    $chart_data[] = isset($visitor_stats_raw[$date]) ? count($visitor_stats_raw[$date]) : 0;
}
$visitor_data_json = json_encode($chart_data);
$visitor_labels_json = json_encode($chart_labels);

// Safe disk space calculation
// On InfinityFree and other hosts, disk_free_space/disk_total_space are DISABLED.
// Calling a disabled function in PHP 8+ throws a FatalError (not catchable by @).
// So we MUST check function_exists() first.
$disk_free = 0;
$disk_total = 0;
$disk_used = 0;
$disk_usage_percent = 0;
if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
    $df = @disk_free_space(__DIR__);
    $dt = @disk_total_space(__DIR__);
    if ($df !== false && $dt !== false && $dt > 0) {
        $disk_free = $df;
        $disk_total = $dt;
        $disk_used = $dt - $df;
        $disk_usage_percent = round(($disk_used / $dt) * 100);
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes($bytes, $precision = 2) {
        if ($bytes === false || $bytes === null || $bytes <= 0) return 'N/A';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

$page_title = t('h_dashboard');
$breadcrumb = [
    ['label' => t('dashboard')],
];
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="<?= h(ADMIN_LANG) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_dashboard') ?></title>
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

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-file-alt"></i></div>
                <div>
                    <h5 class="text-2xl font-bold text-slate-800 m-0"><?= $page_count ?></h5>
                    <p class="text-slate-500 text-sm m-0"><?= t('pages_count') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-newspaper"></i></div>
                <div>
                    <h5 class="text-2xl font-bold text-slate-800 m-0"><?= $news_count ?></h5>
                    <p class="text-slate-500 text-sm m-0"><?= t('news_count') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center mr-4 text-xl"><i class="fab fa-php"></i></div>
                <div>
                    <h5 class="text-2xl font-bold text-slate-800 m-0"><?= $php_version ?></h5>
                    <p class="text-slate-500 text-sm m-0"><?= t('php_version') ?></p>
                </div>
            </div>
            <div class="aicms-stat-card flex items-center">
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center mr-4 text-xl"><i class="fas fa-palette"></i></div>
                <div>
                    <h5 class="text-lg font-bold text-slate-800 m-0"><?= h($current_theme) ?></h5>
                    <p class="text-slate-500 text-sm m-0"><?= t('theme_label') ?></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <a href="pages.php" class="aicms-stat-card flex items-center justify-between text-slate-700 no-underline">
                <div class="flex items-center gap-3"><div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fas fa-file-alt"></i></div><span class="font-medium"><?= t('pages') ?></span></div>
                <i class="fas fa-arrow-right text-blue-600"></i>
            </a>
            <a href="news.php" class="aicms-stat-card flex items-center justify-between text-slate-700 no-underline">
                <div class="flex items-center gap-3"><div class="w-9 h-9 rounded-lg bg-green-50 text-green-600 flex items-center justify-center"><i class="fas fa-newspaper"></i></div><span class="font-medium"><?= t('news') ?></span></div>
                <i class="fas fa-arrow-right text-green-600"></i>
            </a>
            <a href="themes.php" class="aicms-stat-card flex items-center justify-between text-slate-700 no-underline">
                <div class="flex items-center gap-3"><div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fas fa-palette"></i></div><span class="font-medium"><?= t('themes') ?></span></div>
                <i class="fas fa-arrow-right text-amber-600"></i>
            </a>
            <a href="settings.php" class="aicms-stat-card flex items-center justify-between text-slate-700 no-underline">
                <div class="flex items-center gap-3"><div class="w-9 h-9 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center"><i class="fas fa-cogs"></i></div><span class="font-medium"><?= t('settings') ?></span></div>
                <i class="fas fa-arrow-right text-slate-600"></i>
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-7 gap-4">
            <div class="lg:col-span-4 bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><?= t('visitors_7days') ?></div>
                <div class="p-5">
                    <canvas id="visitorsChart" height="120"></canvas>
                </div>
            </div>
            <div class="lg:col-span-3 bg-white rounded-xl shadow-sm">
                <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><?= t('system_info') ?></div>
                <div class="p-5 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500"><?= t('web_server') ?></span>
                        <strong class="text-slate-800"><?= h($server_software) ?></strong>
                    </div>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-500"><?= t('disk_space') ?></span>
                            <span class="text-slate-700"><?= $disk_usage_percent ?>%</span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                            <div class="bg-blue-600 h-full rounded-full transition-all" style="width: <?= $disk_usage_percent ?>%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-slate-400 mt-1">
                            <span><?= t('disk_used') ?> <?= format_bytes($disk_used) ?></span>
                            <span><?= t('disk_total') ?> <?= format_bytes($disk_total) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END grid: chart + system info -->

        <!-- Security Audit Widget (full width, OUTSIDE the 7-col grid) -->
        <div class="bg-white rounded-xl shadow-sm mt-4 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                    <span class="font-semibold text-slate-700"><i class="fas fa-shield-alt text-green-600 mr-1"></i> Аудит безопасности</span>
                    <span class="px-2 py-0.5 text-xs font-bold rounded-full <?= $security_score >= 80 ? 'bg-green-100 text-green-800' : ($security_score >= 50 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') ?>">
                        Оценка: <?= $security_score ?>/100
                    </span>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
                        <?php
                        $check_items = [
                            ['ok' => !$install_exists, 'label' => 'install.php удалён', 'hint' => 'Установщик не должен быть доступен после установки'],
                            ['ok' => $has_2fa, 'label' => '2FA включена', 'hint' => 'Двухфакторная аутентификация для админа'],
                            ['ok' => $api_token_set, 'label' => 'API токен установлен', 'hint' => 'REST API защищён токеном'],
                            ['ok' => $pass_strength_ok, 'label' => 'Пароль хэширован (bcrypt)', 'hint' => 'Современный алгоритм хэширования'],
                            ['ok' => $csp_enabled, 'label' => 'CSP заголовки включены', 'hint' => 'Защита от XSS и инъекций'],
                            ['ok' => $failed_logins_24h === 0, 'label' => 'Нет неудачных входов (24ч)', 'hint' => $failed_logins_24h > 0 ? "За последние 24 часа: $failed_logins_24h попыток" : 'За последние 24 часа'],
                        ];
                        foreach ($check_items as $ci):
                            $color = $ci['ok'] ? 'text-green-600' : 'text-red-500';
                            $icon = $ci['ok'] ? 'fa-check-circle' : 'fa-exclamation-triangle';
                        ?>
                        <div class="flex items-start gap-3">
                            <i class="fas <?= $icon ?> <?= $color ?> mt-0.5 text-base flex-shrink-0" style="width:18px;text-align:center"></i>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-slate-800"><?= h($ci['label']) ?></div>
                                <div class="text-xs text-slate-500"><?= h($ci['hint']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($maintenance_on): ?>
                    <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
                        <i class="fas fa-tools mr-1"></i> Сайт в режиме обслуживания
                    </div>
                    <?php endif; ?>
                    <div class="mt-4 text-right">
                        <a href="settings.php?tab=security" class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 font-medium">Настроить <i class="fas fa-arrow-right text-xs"></i></a>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-xl shadow-sm mt-4 overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                    <span class="font-semibold text-slate-700"><i class="fas fa-history text-blue-600 mr-1"></i> Последняя активность</span>
                    <a href="audit.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Все записи →</a>
                </div>
                <div class="overflow-x-auto">
                    <?php if (empty($last_audit_entries)): ?>
                    <div class="p-8 text-center text-slate-400">
                        <i class="fas fa-inbox text-3xl text-slate-300 mb-2 block"></i>
                        <p class="m-0 text-sm">Записей пока нет</p>
                    </div>
                    <?php else: ?>
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50">
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider" style="width:32px"></th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Действие</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Детали</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Пользователь</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Время</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($last_audit_entries as $entry): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2 text-center">
                                    <div class="w-7 h-7 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-xs mx-auto">
                                        <i class="fas <?= audit_action_icon($entry['action'] ?? '') ?>"></i>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <strong class="text-slate-800"><?= h($entry['action'] ?? '') ?></strong>
                                </td>
                                <td class="px-4 py-2 text-sm text-slate-600">
                                    <?= h(mb_substr($entry['details'] ?? '', 0, 120)) ?>
                                </td>
                                <td class="px-4 py-2 text-sm text-slate-500 hidden md:table-cell"><?= h($entry['user'] ?? '') ?></td>
                                <td class="px-4 py-2 text-sm text-slate-500 hidden md:table-cell whitespace-nowrap"><?= h($entry['ts'] ?? '') ?></td>
                                <td class="px-4 py-2 text-sm text-slate-400 font-mono hidden lg:table-cell whitespace-nowrap"><?= h($entry['ip'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>new Chart(document.getElementById('visitorsChart'),{type:'line',data:{labels:<?= $visitor_labels_json ?>,datasets:[{label:'<?= t('visitors_chart_label') ?>',data:<?= $visitor_data_json ?>,borderColor:'#2563eb',fill:!0,tension:0.3}]},options:{scales:{y:{beginAtZero:!0}},plugins:{legend:{display:false}}}});</script>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
