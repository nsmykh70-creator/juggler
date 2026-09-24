<?php
require_once __DIR__ . '/core/functions.php';
require_auth();
$modules_dir = __DIR__ . '/../modules/';
$all_modules = glob($modules_dir . '*.php');

$modules_with_settings = ['contact_form', 'contact_info', 'features_grid', 'cta_banner', 'social_links', 'countdown_timer', 'example_module', 'latest_news', 'news_list', 'booking', 'comments', 'shop', 'forum', 'newsletter', 'faq', 'gallery', 'pricing', 'testimonials', 'stats', 'progress', 'accordion', 'tabs', 'hero', 'team'];
$module_descriptions = [
    'contact_form'     => t('mod_desc_contact_form'),
    'contact_info'     => t('mod_desc_contact_info'),
    'features_grid'    => t('mod_desc_features_grid'),
    'cta_banner'       => t('mod_desc_cta_banner'),
    'social_links'     => t('mod_desc_social_links'),
    'countdown_timer'  => t('mod_desc_countdown_timer'),
    'example_module'   => t('mod_desc_example_module'),
    'latest_news'      => t('mod_desc_latest_news'),
    'news_list'        => t('mod_desc_latest_news'),
    'booking'          => t('mod_desc_booking'),
    'comments'         => t('mod_desc_comments'),
    'shop'             => t('mod_desc_shop'),
    'forum'            => t('mod_desc_forum'),
    'newsletter'       => t('mod_desc_newsletter'),
    'faq'              => 'FAQ с раскрывающимися вопросами',
    'gallery'          => 'Галерея изображений с лайтбоксом',
    'pricing'          => 'Тарифные планы (3 колонки)',
    'testimonials'     => 'Отзывы клиентов (карусель)',
    'stats'            => 'Счётчики статистики с анимацией',
    'progress'         => 'Прогресс-бары / шкалы навыков',
    'accordion'        => 'Аккордеон с раскрывающимся контентом',
    'tabs'             => 'Вкладки с переключением контента',
    'hero'             => 'Hero-секция с фоном и кнопкой',
    'team'             => 'Карточки команды с соцсетями',
    'map'              => 'Карта (Google Maps / Яндекс.Карты)',
    'video_gallery'    => 'Видео-галерея с модальным плеером',
    'parallax'         => 'Параллакс-секция с фоновым изображением',
];
$page_title = t('modules_management');
$breadcrumb = [
    ['label' => t('dashboard'), 'url' => 'index.php'],
    ['label' => t('modules')],
];
ob_start();
?>
<button class="aicms-icon-btn aicms-icon-btn--primary" onclick="window.dispatchEvent(new CustomEvent('open-create-module'))"><i class="fas fa-plus"></i><span class="btn-label"><?= t('create_module') ?></span></button>
<span class="ml-auto text-sm text-slate-500 self-center">
    <?= t('total_modules') ?> <strong class="text-slate-700"><?= count($all_modules) ?></strong>
</span>
<?php $action_panel = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= t('title_modules') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100" x-data="{createModal:false}" @open-create-module.window="createModal=true">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php include __DIR__ . '/core/topbar.php'; ?>
        <div class="p-6 flex-1">
            <?php display_flash_message(); ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($all_modules as $path): $name = basename($path, '.php'); ?>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden flex flex-col h-full hover:shadow-md transition-shadow">
                    <div class="p-4 flex-1">
                        <h5 class="text-base font-semibold text-slate-800 flex items-center gap-2 m-0">
                            <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm"><i class="fa fa-puzzle-piece"></i></span>
                            <?= h($name) ?>
                        </h5>
                        <p class="text-sm text-slate-500 mt-2 mb-2"><?= h($module_descriptions[$name] ?? t('custom_module')) ?></p>
                        <p class="text-xs text-slate-400 m-0"><?= t('shortcode') ?>: <code class="px-1.5 py-0.5 bg-slate-100 rounded text-blue-600">{{module:<?= h($name) ?>}}</code></p>
                    </div>
                    <div class="px-4 py-3 border-t border-slate-100 flex justify-end gap-1.5">
                        <?php if (in_array($name, $modules_with_settings)): ?>
                        <a href="module_settings_admin.php?module=<?= h($name) ?>" class="aicms-act-outline aicms-act-outline--warning" ><i class="fas fa-cog"></i><span class="btn-label"><?= t('module_settings') ?></span></a>
                        <?php endif; ?>
                        <a href="edit_module.php?module=<?= h($name) ?>" class="aicms-act-outline aicms-act-outline--primary" ><i class="fa fa-code"></i><span class="btn-label"><?= t('code_editor') ?></span></a>
                        <form action="delete_module.php" method="post" class="inline" onsubmit="return confirm('<?= t('confirm_delete') ?>');"><?= csrf_field() ?><input type="hidden" name="module_name" value="<?= h($name) ?>"><button type="submit" class="aicms-act-outline aicms-act-outline--danger"><i class="fas fa-trash"></i><span class="btn-label"><?= t('delete') ?></span></button></form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Create Module Modal -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" x-show="createModal" x-cloak @keydown.escape.window="createModal=false">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" @click.outside="createModal=false">
        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center">
            <h5 class="text-base font-semibold text-slate-800 m-0"><?= t('create_module') ?></h5>
            <button type="button" class="text-slate-400 hover:text-slate-600 text-xl" @click="createModal=false">&times;</button>
        </div>
        <form action="edit_module.php" method="get" class="p-5">
            <label class="block text-sm font-medium text-slate-700 mb-1"><?= t('module_name_hint') ?></label>
            <input type="text" name="module" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" required pattern="[a-z0-9_]+">
            <div class="mt-4 text-right">
                <button type="submit" class="aicms-act aicms-act--primary aicms-act-lg" title="<?= t('create') ?>"><i class="fas fa-check"></i></button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
