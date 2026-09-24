<?php
/**
 * Onboarding Wizard
 * ==================
 * Shown to admins on first login (or when ?force=1 is passed).
 * Guides them through:
 *   1. Site name & description
 *   2. Default language
 *   3. Theme selection
 *   4. First page creation (quick template)
 *   5. 2FA setup recommendation
 *   6. Quick links to next steps
 *
 * Skipped by setting config.onboarding_complete = true.
 */
require_once __DIR__ . '/core/functions.php';
require_auth();
require_once __DIR__ . '/core/lang.php';

$config_file = __DIR__ . '/../data/config.json';
$config = read_json($config_file, []);

// Mark onboarding complete on user request
if (isset($_GET['complete'])) {
    $config['onboarding_complete'] = true;
    write_json($config_file, $config);
    header('Location: index.php');
    exit;
}

// Handle step submission (POST from final step)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Apply settings: site_title, description, default_lang
    if (!empty($_POST['site_title'])) {
        $config['site_title'] = trim($_POST['site_title']);
    }
    if (!empty($_POST['site_description'])) {
        $config['site_description'] = trim($_POST['site_description']);
    }
    if (!empty($_POST['default_lang'])) {
        $config['default_lang'] = $_POST['default_lang'];
    }
    if (!empty($_POST['theme'])) {
        $config['theme'] = $_POST['theme'];
    }
    $config['onboarding_complete'] = true;
    write_json($config_file, $config);
    cache_clear();

    // Create first page if requested
    if (!empty($_POST['create_homepage']) && !empty($_POST['page_title'])) {
        $lang_code = $config['default_lang'] ?? 'ru';
        $pages_file = __DIR__ . '/../data/content/' . $lang_code . '/pages.json';
        $pages = file_exists($pages_file) ? (json_decode(file_get_contents($pages_file), true) ?: []) : [];
        $new_id = count($pages) > 0 ? max(array_column($pages, 'id')) + 1 : 1;
        $pages[] = [
            'id' => $new_id,
            'slug' => 'home',
            'title' => trim($_POST['page_title']),
            'content' => '<h1>' . htmlspecialchars(trim($_POST['page_title'])) . '</h1><p>Добро пожаловать на ваш новый сайт! Это главная страница. Отредактируйте её содержимое в админ-панели.</p>',
            'template' => 'default',
            'published' => true,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($pages_file, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    header('Location: index.php?onboarding_done=1');
    exit;
}

// Available themes
$themes = [];
foreach (glob(__DIR__ . '/../themes/*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if (strpos($name, '_') === 0) continue;  // skip _template_*
    $themes[] = $name;
}

// Available languages
$languages = $config['languages'] ?? [['code' => 'ru', 'name' => 'Русский', 'flag' => '🇷🇺']];

$page_title = t('onboarding_title') ?? 'Добро пожаловать в AICMS';
$breadcrumb = [['label' => t('dashboard'), 'url' => 'index.php'], ['label' => $page_title]];
$action_panel = '<a href="?complete=1" class="aicms-icon-btn aicms-icon-btn--ghost"><i class="fas fa-forward"></i><span class="btn-label">' . (t('skip_onboarding') ?? 'Пропустить') . '</span></a>';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8">
    <title><?= h($page_title) ?> — AICMS</title>
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>
        .onb-step { display: none; }
        .onb-step.active { display: block; }
        .onb-progress-bar { display: flex; gap: 4px; }
        .onb-progress-bar > div { flex: 1; height: 4px; background: #e2e8f0; border-radius: 2px; transition: background 0.2s; }
        .onb-progress-bar > div.active { background: #2563eb; }
        .onb-progress-bar > div.done { background: #10b981; }
        .theme-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.15s; }
        .theme-card:hover { border-color: #93c5fd; transform: translateY(-2px); }
        .theme-card.selected { border-color: #2563eb; background: #eff6ff; }
    </style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <div class="max-w-2xl mx-auto bg-white rounded-xl shadow-sm overflow-hidden">
                <!-- Progress bar -->
                <div class="px-6 pt-5">
                    <div class="onb-progress-bar">
                        <div data-step="1" class="active"></div>
                        <div data-step="2"></div>
                        <div data-step="3"></div>
                        <div data-step="4"></div>
                        <div data-step="5"></div>
                    </div>
                </div>

                <form method="POST" id="onbForm">
                    <?= csrf_field() ?>

                    <!-- Step 1: Welcome -->
                    <div class="onb-step active p-6 text-center" data-step="1">
                        <div class="w-20 h-20 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-4xl">
                            <i class="fas fa-rocket"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-slate-800 mb-2"><?= t('onboarding_welcome') ?? 'Добро пожаловать!' ?></h2>
                        <p class="text-slate-600 mb-6"><?= t('onboarding_intro') ?? 'Этот мастер поможет вам настроить сайт за 5 минут: название, язык, тема, первая страница.' ?></p>
                        <div class="bg-blue-50 rounded-lg p-4 text-left text-sm text-slate-700 mb-4">
                            <div class="font-semibold mb-2"><i class="fas fa-list-check text-blue-600"></i> Что мы настроим:</div>
                            <ul class="space-y-1 ml-4 text-xs text-slate-600">
                                <li>✓ Название и описание сайта</li>
                                <li>✓ Язык по умолчанию</li>
                                <li>✓ Тему оформления</li>
                                <li>✓ Первую страницу</li>
                                <li>✓ Двухфакторную аутентификацию (рекомендуется)</li>
                            </ul>
                        </div>
                        <button type="button" onclick="onbNext()" class="aicms-btn aicms-btn--primary w-full justify-center">
                            <?= t('onboarding_start') ?? 'Начать' ?> <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                    </div>

                    <!-- Step 2: Site info -->
                    <div class="onb-step p-6" data-step="2">
                        <h2 class="text-xl font-bold text-slate-800 mb-4"><i class="fas fa-globe text-indigo-600 mr-2"></i><?= t('onboarding_site_info') ?? 'Информация о сайте' ?></h2>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('site_title_label') ?? 'Название сайта' ?></label>
                            <input type="text" name="site_title" value="<?= h($config['site_title'] ?? 'Мой сайт') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('site_desc_label') ?? 'Описание сайта' ?></label>
                            <textarea name="site_description" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"><?= h($config['site_description'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('default_lang_label') ?? 'Язык по умолчанию' ?></label>
                            <select name="default_lang" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                                <?php foreach ($languages as $li): ?>
                                <option value="<?= h($li['code']) ?>" <?= ($config['default_lang'] ?? 'ru') === $li['code'] ? 'selected' : '' ?>><?= h($li['flag'] ?? '') ?> <?= h($li['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="onbPrev()" class="aicms-btn aicms-btn--secondary"><i class="fas fa-arrow-left mr-1"></i> <?= t('back') ?? 'Назад' ?></button>
                            <button type="button" onclick="onbNext()" class="aicms-btn aicms-btn--primary flex-1 justify-center"><?= t('next') ?? 'Далее' ?> <i class="fas fa-arrow-right ml-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 3: Theme -->
                    <div class="onb-step p-6" data-step="3">
                        <h2 class="text-xl font-bold text-slate-800 mb-4"><i class="fas fa-palette text-indigo-600 mr-2"></i><?= t('onboarding_theme') ?? 'Выберите тему' ?></h2>
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <?php foreach ($themes as $t_name): ?>
                            <div class="theme-card <?= ($config['theme'] ?? 'helios') === $t_name ? 'selected' : '' ?>" onclick="selectTheme(this, '<?= h($t_name) ?>')">
                                <div class="text-2xl mb-2"><i class="fas fa-paint-brush text-slate-400"></i></div>
                                <div class="font-semibold text-slate-800 capitalize"><?= h($t_name) ?></div>
                                <div class="text-xs text-slate-500 mt-1"><?= h($t_name) ?>.html</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="theme" id="themeInput" value="<?= h($config['theme'] ?? 'helios') ?>">
                        <div class="flex gap-2">
                            <button type="button" onclick="onbPrev()" class="aicms-btn aicms-btn--secondary"><i class="fas fa-arrow-left mr-1"></i> <?= t('back') ?? 'Назад' ?></button>
                            <button type="button" onclick="onbNext()" class="aicms-btn aicms-btn--primary flex-1 justify-center"><?= t('next') ?? 'Далее' ?> <i class="fas fa-arrow-right ml-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 4: First page -->
                    <div class="onb-step p-6" data-step="4">
                        <h2 class="text-xl font-bold text-slate-800 mb-4"><i class="fas fa-file-alt text-indigo-600 mr-2"></i><?= t('onboarding_first_page') ?? 'Первая страница' ?></h2>
                        <div class="bg-slate-50 rounded-lg p-3 mb-3 flex items-start gap-3">
                            <input type="checkbox" name="create_homepage" id="create_homepage" value="1" checked class="mt-1">
                            <label for="create_homepage" class="text-sm text-slate-700">
                                <span class="font-medium"><?= t('onboarding_create_homepage') ?? 'Создать главную страницу' ?></span><br>
                                <span class="text-xs text-slate-500"><?= t('onboarding_homepage_hint') ?? 'Создаст страницу с slug "home" — она будет открываться по умолчанию.' ?></span>
                            </label>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('page_title_label') ?? 'Заголовок страницы' ?></label>
                            <input type="text" name="page_title" value="<?= h($config['site_title'] ?? 'Главная') ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="onbPrev()" class="aicms-btn aicms-btn--secondary"><i class="fas fa-arrow-left mr-1"></i> <?= t('back') ?? 'Назад' ?></button>
                            <button type="button" onclick="onbNext()" class="aicms-btn aicms-btn--primary flex-1 justify-center"><?= t('next') ?? 'Далее' ?> <i class="fas fa-arrow-right ml-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 5: 2FA + Done -->
                    <div class="onb-step p-6" data-step="5">
                        <h2 class="text-xl font-bold text-slate-800 mb-4"><i class="fas fa-shield-alt text-indigo-600 mr-2"></i><?= t('onboarding_security') ?? 'Безопасность' ?></h2>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-exclamation-triangle text-amber-600 text-xl mt-0.5"></i>
                                <div class="text-sm text-amber-900">
                                    <div class="font-semibold mb-1"><?= t('onboarding_2fa_recommend') ?? 'Рекомендуем включить 2FA' ?></div>
                                    <p class="text-xs mb-2"><?= t('onboarding_2fa_text') ?? 'Двухфакторная аутентификация значительно повышает безопасность. Её можно включить в любое время в Настройках → Аккаунт.' ?></p>
                                    <a href="settings.php?tab=account" class="text-xs text-amber-700 underline"><?= t('onboarding_setup_2fa_now') ?? 'Настроить сейчас →' ?></a>
                                </div>
                            </div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-4 mb-4 text-sm text-green-800">
                            <div class="font-semibold mb-1"><i class="fas fa-check-circle"></i> <?= t('onboarding_ready') ?? 'Всё готово!' ?></div>
                            <p class="text-xs"><?= t('onboarding_final_text') ?? 'Нажмите «Завершить», чтобы применить настройки и перейти в админ-панель.' ?></p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="onbPrev()" class="aicms-btn aicms-btn--secondary"><i class="fas fa-arrow-left mr-1"></i> <?= t('back') ?? 'Назад' ?></button>
                            <button type="submit" class="aicms-btn aicms-btn--success flex-1 justify-center"><i class="fas fa-check mr-1"></i> <?= t('onboarding_finish') ?? 'Завершить настройку' ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
var currentStep = 1;
var totalSteps = 5;

function showStep(n) {
    document.querySelectorAll('.onb-step').forEach(function(s) { s.classList.remove('active'); });
    var step = document.querySelector('.onb-step[data-step="' + n + '"]');
    if (step) step.classList.add('active');
    // Update progress bar
    document.querySelectorAll('.onb-progress-bar > div').forEach(function(b) {
        var s = parseInt(b.dataset.step);
        b.classList.remove('active', 'done');
        if (s < n) b.classList.add('done');
        else if (s === n) b.classList.add('active');
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function onbNext() {
    if (currentStep < totalSteps) {
        currentStep++;
        showStep(currentStep);
    }
}

function onbPrev() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
    }
}

function selectTheme(card, name) {
    document.querySelectorAll('.theme-card').forEach(function(c) { c.classList.remove('selected'); });
    card.classList.add('selected');
    document.getElementById('themeInput').value = name;
}
</script>
</body>
</html>
