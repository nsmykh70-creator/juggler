<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/module_settings.php';
require_auth();

$module_name = $_GET['module'] ?? '';
if (empty($module_name) || !preg_match('/^[a-z0-9_]+$/', $module_name)) { set_flash_message(t('err_invalid_module_name'), 'danger'); header('Location: modules.php'); exit; }
$module_path = __DIR__ . '/../modules/' . $module_name . '.php';
if (!file_exists($module_path)) { set_flash_message(t('module_not_found'), 'danger'); header('Location: modules.php'); exit; }

$settings_schemas = [
    'contact_form' => ['title'=>t('mod.contact_form.title'),'fields'=>['email'=>['label'=>t('mod.contact_form.email.label'),'type'=>'email','default'=>'admin@example.com'],'title'=>['label'=>t('mod.contact_form.title_field.label'),'type'=>'text','default'=>t('mod.contact_form.title_field.default')],'button_text'=>['label'=>t('mod.contact_form.button_text.label'),'type'=>'text','default'=>t('mod.contact_form.button_text.default')],'show_phone'=>['label'=>t('mod.contact_form.show_phone.label'),'type'=>'checkbox','default'=>true],'success_msg'=>['label'=>t('mod.contact_form.success_msg.label'),'type'=>'text','default'=>t('mod.contact_form.success_msg.default')]]],
    'contact_info' => ['title'=>t('mod.contact_info.title'),'fields'=>['title'=>['label'=>t('mod.contact_info.title_field.label'),'type'=>'text','default'=>t('mod.contact_info.title_field.default')],'address'=>['label'=>t('mod.contact_info.address.label'),'type'=>'textarea','default'=>t('mod.contact_info.address.default')],'phone'=>['label'=>t('mod.contact_info.phone.label'),'type'=>'text','default'=>'+7 (495) 123-45-67'],'email'=>['label'=>t('mod.contact_info.email.label'),'type'=>'email','default'=>'info@example.com'],'hours'=>['label'=>t('mod.contact_info.hours.label'),'type'=>'text','default'=>t('mod.contact_info.hours.default')],'show_icons'=>['label'=>t('mod.contact_info.show_icons.label'),'type'=>'checkbox','default'=>true]]],
    'features_grid' => ['title'=>t('mod.features_grid.title'),'fields'=>['title'=>['label'=>t('mod.features_grid.title_field.label'),'type'=>'text','default'=>t('mod.features_grid.title_field.default')],'subtitle'=>['label'=>t('mod.features_grid.subtitle.label'),'type'=>'text','default'=>t('mod.features_grid.subtitle.default')],'columns'=>['label'=>t('mod.features_grid.columns.label'),'type'=>'number','default'=>3]]],
    'cta_banner' => ['title'=>t('mod.cta_banner.title'),'fields'=>['heading'=>['label'=>t('mod.cta_banner.heading.label'),'type'=>'text','default'=>t('mod.cta_banner.heading.default')],'text'=>['label'=>t('mod.cta_banner.text.label'),'type'=>'textarea','default'=>t('mod.cta_banner.text.default')],'button_text'=>['label'=>t('mod.cta_banner.button_text.label'),'type'=>'text','default'=>t('mod.cta_banner.button_text.default')],'button_url'=>['label'=>t('mod.cta_banner.button_url.label'),'type'=>'text','default'=>'#'],'bg_color'=>['label'=>t('mod.cta_banner.bg_color.label'),'type'=>'color','default'=>'#0d6efd'],'text_color'=>['label'=>t('mod.cta_banner.text_color.label'),'type'=>'color','default'=>'#ffffff'],'style'=>['label'=>t('mod.cta_banner.style.label'),'type'=>'select','default'=>'gradient','options'=>['solid'=>t('mod.cta_banner.style.option.solid'),'gradient'=>t('mod.cta_banner.style.option.gradient'),'outline'=>t('mod.cta_banner.style.option.outline')]]]],
    'social_links' => ['title'=>t('mod.social_links.title'),'fields'=>['style'=>['label'=>t('mod.social_links.style.label'),'type'=>'select','default'=>'circles','options'=>['circles'=>t('mod.social_links.style.option.circles'),'squares'=>t('mod.social_links.style.option.squares')]],'size'=>['label'=>t('mod.social_links.size.label'),'type'=>'number','default'=>40],'align'=>['label'=>t('mod.social_links.align.label'),'type'=>'select','default'=>'center','options'=>['left'=>t('mod.social_links.align.option.left'),'center'=>t('mod.social_links.align.option.center'),'right'=>t('mod.social_links.align.option.right')]],'telegram'=>['label'=>t('mod.social_links.telegram.label'),'type'=>'text','default'=>''],'vk'=>['label'=>t('mod.social_links.vk.label'),'type'=>'text','default'=>''],'youtube'=>['label'=>t('mod.social_links.youtube.label'),'type'=>'text','default'=>''],'instagram'=>['label'=>t('mod.social_links.instagram.label'),'type'=>'text','default'=>''],'facebook'=>['label'=>t('mod.social_links.facebook.label'),'type'=>'text','default'=>''],'twitter'=>['label'=>t('mod.social_links.twitter.label'),'type'=>'text','default'=>''],'github'=>['label'=>t('mod.social_links.github.label'),'type'=>'text','default'=>''],'whatsapp'=>['label'=>t('mod.social_links.whatsapp.label'),'type'=>'text','default'=>'']]],
    'countdown_timer' => ['title'=>t('mod.countdown_timer.title'),'fields'=>['target_date'=>['label'=>t('mod.countdown_timer.target_date.label'),'type'=>'text','default'=>date('Y-m-d',strtotime('+30 days'))],'title'=>['label'=>t('mod.countdown_timer.title_field.label'),'type'=>'text','default'=>t('mod.countdown_timer.title_field.default')],'show_days'=>['label'=>t('mod.countdown_timer.show_days.label'),'type'=>'checkbox','default'=>true],'show_hours'=>['label'=>t('mod.countdown_timer.show_hours.label'),'type'=>'checkbox','default'=>true],'show_minutes'=>['label'=>t('mod.countdown_timer.show_minutes.label'),'type'=>'checkbox','default'=>true],'show_seconds'=>['label'=>t('mod.countdown_timer.show_seconds.label'),'type'=>'checkbox','default'=>true],'bg_color'=>['label'=>t('mod.countdown_timer.bg_color.label'),'type'=>'color','default'=>'#1e293b'],'expired_text'=>['label'=>t('mod.countdown_timer.expired_text.label'),'type'=>'text','default'=>t('mod.countdown_timer.expired_text.default')]]],
    'example_module' => ['title'=>t('mod.example_module.title'),'fields'=>['title'=>['label'=>t('mod.example_module.title_field.label'),'type'=>'text','default'=>t('mod.example_module.title_field.default')],'message'=>['label'=>t('mod.example_module.message.label'),'type'=>'textarea','default'=>t('mod.example_module.message.default')],'border_color'=>['label'=>t('mod.example_module.border_color.label'),'type'=>'color','default'=>'#0d6efd'],'bg_color'=>['label'=>t('mod.example_module.bg_color.label'),'type'=>'color','default'=>'#eef5ff'],'show_time'=>['label'=>t('mod.example_module.show_time.label'),'type'=>'checkbox','default'=>true]]],
    'newsletter' => ['title'=>t('mod.newsletter.title'),'fields'=>['title'=>['label'=>t('mod.newsletter.title_field.label'),'type'=>'text','default'=>t('mod.newsletter.title_field.default')],'desc'=>['label'=>t('mod.newsletter.desc.label'),'type'=>'text','default'=>t('mod.newsletter.desc.default')],'button_text'=>['label'=>t('mod.newsletter.button_text.label'),'type'=>'text','default'=>t('mod.newsletter.button_text.default')],'placeholder'=>['label'=>t('mod.newsletter.placeholder.label'),'type'=>'text','default'=>t('mod.newsletter.placeholder.default')],'success_msg'=>['label'=>t('mod.newsletter.success_msg.label'),'type'=>'text','default'=>t('mod.newsletter.success_msg.default')]]],
    'latest_news' => ['title'=>t('mod.latest_news.title'),'fields'=>['title'=>['label'=>t('mod.latest_news.title_field.label'),'type'=>'text','default'=>t('mod.latest_news.title_field.default')],'count'=>['label'=>t('mod.latest_news.count.label'),'type'=>'number','default'=>5],'show_date'=>['label'=>t('mod.latest_news.show_date.label'),'type'=>'checkbox','default'=>true],'show_toggle'=>['label'=>t('mod.latest_news.show_toggle.label'),'type'=>'checkbox','default'=>true],'preview_chars'=>['label'=>t('mod.latest_news.preview_chars.label'),'type'=>'number','default'=>0]]],
    'news_list' => ['title'=>t('mod.news_list.title'),'fields'=>['first_open'=>['label'=>t('mod.news_list.first_open.label'),'type'=>'checkbox','default'=>true],'date_format'=>['label'=>t('mod.news_list.date_format.label'),'type'=>'select','default'=>'d.m.Y','options'=>['d.m.Y'=>t('mod.news_list.date_format.option.d.m.Y'),'Y-m-d'=>t('mod.news_list.date_format.option.Y-m-d')]]]],
    'booking' => ['title'=>t('mod.booking.title'),'fields'=>['title'=>['label'=>t('mod.booking.title_field.label'),'type'=>'text','default'=>t('mod.booking.title_field.default')],'email'=>['label'=>t('mod.booking.email.label'),'type'=>'email','default'=>'admin@example.com'],'button_text'=>['label'=>t('mod.booking.button_text.label'),'type'=>'text','default'=>t('mod.booking.button_text.default')],'show_phone'=>['label'=>t('mod.booking.show_phone.label'),'type'=>'checkbox','default'=>true],'show_guests'=>['label'=>t('mod.booking.show_guests.label'),'type'=>'checkbox','default'=>true],'min_date'=>['label'=>t('mod.booking.min_date.label'),'type'=>'number','default'=>0],'max_date'=>['label'=>t('mod.booking.max_date.label'),'type'=>'number','default'=>90],'success_msg'=>['label'=>t('mod.booking.success_msg.label'),'type'=>'text','default'=>t('mod.booking.success_msg.default')]]],
    'comments' => ['title'=>t('mod.comments.title'),'fields'=>['title'=>['label'=>t('mod.comments.title_field.label'),'type'=>'text','default'=>t('mod.comments.title_field.default')],'show_ratings'=>['label'=>t('mod.comments.show_ratings.label'),'type'=>'checkbox','default'=>true],'allow_replies'=>['label'=>t('mod.comments.allow_replies.label'),'type'=>'checkbox','default'=>true],'auto_approve'=>['label'=>t('mod.comments.auto_approve.label'),'type'=>'checkbox','default'=>false],'per_page'=>['label'=>t('mod.comments.per_page.label'),'type'=>'number','default'=>20]]],
    'shop' => ['title'=>t('mod.shop.title'),'fields'=>['title'=>['label'=>t('mod.shop.title_field.label'),'type'=>'text','default'=>t('mod.shop.title_field.default')],'currency'=>['label'=>t('mod.shop.currency.label'),'type'=>'text','default'=>t('mod.shop.currency.default')],'per_page'=>['label'=>t('mod.shop.per_page.label'),'type'=>'number','default'=>12],'show_stock'=>['label'=>t('mod.shop.show_stock.label'),'type'=>'checkbox','default'=>true],'checkout_email'=>['label'=>t('mod.shop.checkout_email.label'),'type'=>'email','default'=>'admin@example.com'],'shipping_enabled'=>['label'=>t('mod.shop.shipping_enabled.label'),'type'=>'checkbox','default'=>false],'shipping_free_from'=>['label'=>t('mod.shop.shipping_free_from.label'),'type'=>'number','default'=>5000],'shipping_base_cost'=>['label'=>t('mod.shop.shipping_base_cost.label'),'type'=>'number','default'=>300],'shipping_per_kg'=>['label'=>t('mod.shop.shipping_per_kg.label'),'type'=>'number','default'=>50],'yookassa_shop_id'=>['label'=>t('mod.shop.yookassa_shop_id.label'),'type'=>'text','default'=>''],'yookassa_secret'=>['label'=>t('mod.shop.yookassa_secret.label'),'type'=>'text','default'=>''],'stripe_key'=>['label'=>t('mod.shop.stripe_key.label'),'type'=>'text','default'=>''],'stripe_secret'=>['label'=>t('mod.shop.stripe_secret.label'),'type'=>'text','default'=>'']]],
    'forum' => ['title'=>t('mod.forum.title'),'fields'=>['title'=>['label'=>t('mod.forum.title_field.label'),'type'=>'text','default'=>t('mod.forum.title_field.default')],'posts_per_page'=>['label'=>t('mod.forum.posts_per_page.label'),'type'=>'number','default'=>20],'allow_guest'=>['label'=>t('mod.forum.allow_guest.label'),'type'=>'checkbox','default'=>true]]],
    'cafe' => ['title'=>'Кафе — Настройки','fields'=>['title'=>['label'=>'Заголовок формы','type'=>'text','default'=>'Бронирование столика'],'email'=>['label'=>'Email для уведомлений','type'=>'email','default'=>'admin@example.com'],'button_text'=>['label'=>'Текст кнопки','type'=>'text','default'=>'Забронировать'],'show_phone'=>['label'=>'Показывать поле телефона','type'=>'checkbox','default'=>true],'show_guests'=>['label'=>'Показывать поле количества гостей','type'=>'checkbox','default'=>true],'success_msg'=>['label'=>'Сообщение об успехе','type'=>'text','default'=>'Спасибо! Ваш столик забронирован, мы свяжемся с вами.']]],
    // === New modules v2 ===
    'faq' => ['title'=>'FAQ — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Часто задаваемые вопросы'],'icon_style'=>['label'=>'Стиль иконки','type'=>'select','default'=>'plus','options'=>['plus'=>'Плюс (+)','chevron'=>'Стрелка (▼)','circle'=>'Круг с +']],'bg_color'=>['label'=>'Цвет фона','type'=>'color','default'=>'#ffffff'],'border_color'=>['label'=>'Цвет рамки','type'=>'color','default'=>'#e2e8f0']]],
    'gallery' => ['title'=>'Галерея — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Галерея'],'columns'=>['label'=>'Колонки (1-4)','type'=>'number','default'=>3],'gap'=>['label'=>'Отступ между фото (px)','type'=>'number','default'=>12],'show_lightbox'=>['label'=>'Лайтбокс по клику','type'=>'checkbox','default'=>true],'images'=>['label'=>'Изображения (URL через запятую)','type'=>'textarea','default'=>'']]],
    'pricing' => ['title'=>'Тарифы — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Тарифные планы'],'highlighted_plan'=>['label'=>'Подсвеченный план (1-3)','type'=>'number','default'=>2],'currency'=>['label'=>'Валюта','type'=>'text','default'=>'₽'],'period'=>['label'=>'Период','type'=>'text','default'=>'/мес']]],
    'testimonials' => ['title'=>'Отзывы — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Отзывы клиентов'],'auto_rotate'=>['label'=>'Автопрокрутка','type'=>'checkbox','default'=>true],'rotate_interval'=>['label'=>'Интервал (сек)','type'=>'number','default'=>5],'show_stars'=>['label'=>'Показывать звёзды','type'=>'checkbox','default'=>true]]],
    'stats' => ['title'=>'Статистика — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>''],'bg_color'=>['label'=>'Цвет фона','type'=>'color','default'=>'#f8fafc'],'columns'=>['label'=>'Колонки (2-4)','type'=>'number','default'=>4],'animate'=>['label'=>'Анимация счётчиков','type'=>'checkbox','default'=>true]]],
    'progress' => ['title'=>'Прогресс-бары — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Наши навыки'],'animate'=>['label'=>'Анимация заполнения','type'=>'checkbox','default'=>true],'show_percentage'=>['label'=>'Показывать проценты','type'=>'checkbox','default'=>true],'bar_height'=>['label'=>'Высота полосы (px)','type'=>'number','default'=>24]]],
    'accordion' => ['title'=>'Аккордеон — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>''],'multiple_open'=>['label'=>'Несколько открытых одновременно','type'=>'checkbox','default'=>false],'icon_style'=>['label'=>'Стиль иконки','type'=>'select','default'=>'chevron','options'=>['chevron'=>'Стрелка','plus'=>'Плюс','none'=>'Без иконки']],'bg_color'=>['label'=>'Цвет фона','type'=>'color','default'=>'#ffffff']]],
    'tabs' => ['title'=>'Вкладки — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>''],'layout'=>['label'=>'Расположение','type'=>'select','default'=>'horizontal','options'=>['horizontal'=>'Горизонтально','vertical'=>'Вертикально']],'active_tab'=>['label'=>'Активная вкладка (1-4)','type'=>'number','default'=>1]]],
    'hero' => ['title'=>'Hero-секция — Настройки','fields'=>['title_field'=>['label'=>'Заголовок','type'=>'text','default'=>'Добро пожаловать'],'subtitle'=>['label'=>'Подзаголовок','type'=>'textarea','default'=>'Описание вашего продукта или услуги'],'button_text'=>['label'=>'Текст кнопки','type'=>'text','default'=>'Начать'],'button_url'=>['label'=>'URL кнопки','type'=>'text','default'=>'#'],'bg_color'=>['label'=>'Цвет фона','type'=>'color','default'=>'#1e293b'],'text_color'=>['label'=>'Цвет текста','type'=>'color','default'=>'#ffffff'],'bg_style'=>['label'=>'Стиль фона','type'=>'select','default'=>'solid','options'=>['solid'=>'Сплошной','gradient'=>'Градиент']]]],
    'team' => ['title'=>'Команда — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Наша команда'],'columns'=>['label'=>'Колонки (2-4)','type'=>'number','default'=>4],'show_social'=>['label'=>'Показывать соцсети','type'=>'checkbox','default'=>true],'hover_effect'=>['label'=>'Эффект при наведении','type'=>'select','default'=>'overlay','options'=>['overlay'=>'Затемнение с био','scale'=>'Увеличение фото','none'=>'Без эффекта']]]],
    'map' => ['title'=>'Карта — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Как нас найти'],'address'=>['label'=>'Адрес','type'=>'text','default'=>'Москва, Красная площадь, 1'],'zoom'=>['label'=>'Масштаб (1-20)','type'=>'number','default'=>15],'height'=>['label'=>'Высота (px)','type'=>'number','default'=>400],'map_type'=>['label'=>'Провайдер карты','type'=>'select','default'=>'google','options'=>['google'=>'Google Maps','yandex'=>'Яндекс.Карты']],'show_link'=>['label'=>'Показать ссылку «Открыть в приложении»','type'=>'checkbox','default'=>1]]],
    'video_gallery' => ['title'=>'Видео-галерея — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Видео-галерея'],'columns'=>['label'=>'Колонки (2-4)','type'=>'number','default'=>3],'videos'=>['label'=>'Видео (URL по одному на строку)','type'=>'textarea','default'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]],
    'parallax' => ['title'=>'Параллакс — Настройки','fields'=>['title'=>['label'=>'Заголовок','type'=>'text','default'=>'Ваш заголовок'],'subtitle'=>['label'=>'Подзаголовок','type'=>'textarea','default'=>'Описание поверх параллакс-фона'],'image_url'=>['label'=>'URL фонового изображения','type'=>'text','default'=>'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=1920'],'height'=>['label'=>'Высота (px)','type'=>'number','default'=>500],'overlay_opacity'=>['label'=>'Прозрачность затемнения (0-1)','type'=>'text','default'=>'0.5'],'text_color'=>['label'=>'Цвет текста','type'=>'color','default'=>'#ffffff'],'cta_text'=>['label'=>'Текст кнопки (пусто — нет кнопки)','type'=>'text','default'=>''],'cta_url'=>['label'=>'Ссылка кнопки','type'=>'text','default'=>'']]],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $schema = $settings_schemas[$module_name] ?? null;
    $edit_lang = trim($_POST['edit_lang'] ?? '');
    if ($schema) {
        $new = [];
        foreach ($schema['fields'] as $key => $field) {
            if ($field['type'] === 'checkbox') $new[$key] = isset($_POST[$key]);
            else $new[$key] = trim($_POST[$key] ?? $field['default']);
        }
        if ($module_name === 'social_links') {
            $pk = ['telegram','vk','youtube','instagram','facebook','twitter','github','whatsapp'];
            $platforms = []; foreach ($pk as $p) { $platforms[$p] = $new[$p] ?? ''; unset($new[$p]); }
            $new['platforms'] = $platforms;
        }
        // Save: if a specific language is being edited, save to localized file;
        // otherwise save to base file.
        if (!empty($edit_lang) && $edit_lang !== 'default') {
            module_save_settings_localized($module_name, $edit_lang, $new);
            set_flash_message(t('msg_module_settings_saved') . ' (' . strtoupper($edit_lang) . ')', 'success');
        } else {
            module_save_settings($module_name, $new);
            set_flash_message(t('msg_module_settings_saved'), 'success');
        }
    }
    // Invalidate cache — module settings affect frontend rendering
    require_once __DIR__ . '/core/cache.php'; cache_clear();
    $redirect_extra = !empty($edit_lang) && $edit_lang !== 'default' ? '&lang=' . urlencode($edit_lang) : '';
    header('Location: module_settings_admin.php?module=' . $module_name . $redirect_extra); exit;
}

$schema = $settings_schemas[$module_name] ?? null;
$edit_lang = trim($_GET['lang'] ?? 'default');
if ($edit_lang === '') $edit_lang = 'default';
// Load settings for the currently-edited language
if ($edit_lang === 'default') {
    $current = module_get_settings($module_name, []);
} else {
    // Load default + merge with lang overrides (so fields not overridden show defaults)
    $defaults_from_schema = [];
    foreach (($schema['fields'] ?? []) as $k => $f) $defaults_from_schema[$k] = $f['default'];
    $current = module_get_settings_localized($module_name, $edit_lang, $defaults_from_schema);
}
// List languages that have overrides
$localized_langs = module_get_localized_langs($module_name);
$inp = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none transition-all';
$lbl = 'block text-sm font-medium text-slate-700 mb-1';

// Module-specific field grouping for cleaner layout on complex modules.
// Modules not listed here fall back to the default linear layout.
$field_groups = [
    'shop' => [
        'general'  => ['title', 'currency', 'per_page', 'show_stock', 'checkout_email'],
        'shipping' => ['shipping_enabled', 'shipping_free_from', 'shipping_base_cost', 'shipping_per_kg'],
        'payments' => ['yookassa_shop_id', 'yookassa_secret', 'stripe_key', 'stripe_secret'],
    ],
    'booking' => [
        'general'  => ['title', 'email', 'button_text', 'success_msg'],
        'form'     => ['show_phone', 'show_guests', 'min_date', 'max_date'],
    ],
    'cafe' => [
        'general'  => ['title', 'email', 'button_text', 'success_msg'],
        'form'     => ['show_phone', 'show_guests'],
    ],
    'social_links' => [
        'style'     => ['style', 'size', 'align'],
        'platforms' => ['telegram', 'vk', 'youtube', 'instagram', 'facebook', 'twitter', 'github', 'whatsapp'],
    ],
    'cta_banner' => [
        'content' => ['heading', 'text', 'button_text', 'button_url'],
        'style'   => ['bg_color', 'text_color', 'style'],
    ],
    'hero' => [
        'content' => ['title_field', 'subtitle', 'button_text', 'button_url'],
        'style'   => ['bg_color', 'text_color', 'bg_style'],
    ],
];
$group_icons = [
    'general'   => 'fa-cog',
    'form'      => 'fa-list-alt',
    'shipping'  => 'fa-truck',
    'payments'  => 'fa-credit-card',
    'style'     => 'fa-palette',
    'content'   => 'fa-align-left',
    'platforms' => 'fa-share-alt',
];
$group_titles = [
    'general'   => 'Основные настройки',
    'form'      => 'Поля формы',
    'shipping'  => 'Доставка',
    'payments'  => 'Платёжные системы',
    'style'     => 'Внешний вид',
    'content'   => 'Контент',
    'platforms' => 'Платформы',
];
$groups_for_module = $field_groups[$module_name] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_module_settings') ?>: <?= h($module_name) ?> — AICMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('module_settings_title');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('modules'),'url'=>'modules.php'],['label'=>h($module_name)]];
        $action_panel = '<p class="text-sm text-slate-500 self-center">'.t('module_label').' <code class="px-1.5 py-0.5 bg-slate-100 rounded text-blue-600 font-mono text-xs">'.h($module_name).'</code> · <code class="text-xs font-mono">{{module:'.h($module_name).'}}</code></p><a href="modules.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">'.t('back_to_list').'</span></a>';
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>
        <?php if (!$schema): ?>
        <div class="bg-amber-50 text-amber-700 rounded-lg p-4 flex items-center gap-2"><i class="fas fa-info-circle"></i><?= t('no_module_settings') ?> <a href="edit_module.php?module=<?= h($module_name) ?>" class="text-blue-600 underline ml-1"><?= t('code_editor_link') ?></a></div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow-sm max-w-2xl">
            <div class="px-5 py-3 border-b border-slate-200 font-semibold text-slate-700"><i class="fas fa-sliders-h mr-1"></i><?= h($schema['title']) ?></div>
            <div class="p-5">
                <!-- Language tabs for per-language overrides -->
                <?php if (count(SUPPORTED_LANGS) > 1): ?>
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-slate-100 flex-wrap">
                    <span class="text-xs text-slate-500 mr-2"><i class="fas fa-language"></i> <?= t('language_label') ?? 'Language' ?>:</span>
                    <a href="module_settings_admin.php?module=<?= h($module_name) ?>&lang=default"
                       class="px-3 py-1 text-xs rounded-full font-medium <?= $edit_lang === 'default' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                        <?= t('default_label') ?? 'По умолчанию' ?>
                    </a>
                    <?php foreach (SUPPORTED_LANGS as $li): ?>
                    <a href="module_settings_admin.php?module=<?= h($module_name) ?>&lang=<?= h($li['code']) ?>"
                       class="px-3 py-1 text-xs rounded-full font-medium <?= $edit_lang === $li['code'] ? 'bg-blue-600 text-white' : (in_array($li['code'], $localized_langs) ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-slate-100 text-slate-700 hover:bg-slate-200') ?>">
                        <?= h($li['flag'] ?? '') ?> <?= strtoupper($li['code']) ?>
                        <?php if (in_array($li['code'], $localized_langs)): ?><span title="есть переопределения">•</span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($edit_lang !== 'default'): ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-2 mb-4 text-xs text-blue-700">
                    <i class="fas fa-info-circle"></i>
                    <?= t('module_lang_help') ?? 'Editing language-specific overrides. Empty fields will fall back to default values.' ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <form method="POST"><?= csrf_field() ?>
                    <input type="hidden" name="edit_lang" value="<?= h($edit_lang) ?>">
                    <?php
                    // Helper: render a single field by key
                    // (used both by grouped and linear layouts)
                    function render_module_field($key, $field, $val, $module_name, $inp, $lbl) {
                        $val = ($module_name === 'social_links' && in_array($key, ['telegram','vk','youtube','instagram','facebook','twitter','github','whatsapp'])) ? (($GLOBALS['_social_platforms'][$key] ?? null) ?? $field['default']) : $val;
                        ob_start();
                        if ($field['type'] === 'checkbox'):
                        ?>
                        <div class="flex items-center gap-3 mb-3">
                            <label class="aicms-toggle"><input type="checkbox" name="<?= h($key) ?>" id="f_<?= h($key) ?>" value="1" <?= $val ? 'checked' : '' ?>><span class="aicms-toggle-track"></span></label>
                            <label for="f_<?= h($key) ?>" class="text-sm text-slate-700 cursor-pointer select-none"><?= h($field['label']) ?></label>
                        </div>
                        <?php elseif ($field['type'] === 'color'): ?>
                        <div class="mb-3">
                            <label class="<?= $lbl ?>" for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
                            <div class="flex items-center gap-2">
                                <input type="color" name="<?= h($key) ?>" id="f_<?= h($key) ?>" class="w-14 h-10 border border-slate-300 rounded-lg cursor-pointer p-1" value="<?= h($val) ?>">
                                <input type="text" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm" value="<?= h($val) ?>" oninput="document.getElementById('f_<?= h($key) ?>').value = this.value" placeholder="#hex">
                            </div>
                        </div>
                        <?php elseif ($field['type'] === 'textarea'): ?>
                        <div class="mb-3">
                            <label class="<?= $lbl ?>" for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
                            <textarea name="<?= h($key) ?>" id="f_<?= h($key) ?>" rows="3" class="<?= $inp ?>"><?= h($val) ?></textarea>
                        </div>
                        <?php elseif ($field['type'] === 'select'): ?>
                        <div class="mb-3">
                            <label class="<?= $lbl ?>" for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
                            <select name="<?= h($key) ?>" id="f_<?= h($key) ?>" class="<?= $inp ?>"><?php foreach ($field['options'] as $ov=>$ol): ?><option value="<?= h($ov) ?>" <?= $val==$ov?'selected':'' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
                        </div>
                        <?php elseif ($field['type'] === 'number'): ?>
                        <div class="mb-3">
                            <label class="<?= $lbl ?>" for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
                            <input type="number" name="<?= h($key) ?>" id="f_<?= h($key) ?>" class="<?= $inp ?>" value="<?= h($val) ?>">
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="<?= $lbl ?>" for="f_<?= h($key) ?>"><?= h($field['label']) ?></label>
                            <input type="<?= h($field['type']) ?>" name="<?= h($key) ?>" id="f_<?= h($key) ?>" class="<?= $inp ?>" value="<?= h($val) ?>">
                        </div>
                        <?php endif;
                        return ob_get_clean();
                    }

                    // For social_links, resolve platform values into a global so
                    // render_module_field can find them without changing its signature.
                    if ($module_name === 'social_links') {
                        $GLOBALS['_social_platforms'] = $current['platforms'] ?? [];
                    }

                    if ($groups_for_module):
                        // Grouped layout — for shop, booking, cafe, social_links, cta_banner, hero
                        foreach ($groups_for_module as $group_key => $field_keys):
                            $group_icon = $group_icons[$group_key] ?? 'fa-folder';
                            $group_title = $group_titles[$group_key] ?? ucfirst($group_key);
                        ?>
                        <div class="mb-5 rounded-lg border border-slate-200 overflow-hidden">
                            <div class="px-4 py-2.5 bg-slate-50 border-b border-slate-200 flex items-center gap-2">
                                <i class="fas <?= h($group_icon) ?> text-blue-600 text-sm"></i>
                                <span class="text-sm font-semibold text-slate-700"><?= h($group_title) ?></span>
                            </div>
                            <div class="p-4">
                                <?php
                                // Render fields in this group in a responsive grid
                                // (2 cols for short fields, 1 col for textareas/long)
                                foreach ($field_keys as $key):
                                    $field = $schema['fields'][$key] ?? null;
                                    if (!$field) continue;
                                    $val = ($module_name === 'social_links' && in_array($key, ['telegram','vk','youtube','instagram','facebook','twitter','github','whatsapp']))
                                        ? ($current['platforms'][$key] ?? $field['default'])
                                        : ($current[$key] ?? $field['default']);
                                    $is_wide = in_array($field['type'], ['textarea'], true);
                                    $is_short = in_array($field['type'], ['checkbox', 'color', 'number'], true);
                                ?>
                                <div class="<?= $is_wide ? 'col-span-2' : ($is_short ? '' : '') ?>">
                                    <?= render_module_field($key, $field, $val, $module_name, $inp, $lbl) ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php
                        endforeach;

                        // Render any fields NOT in any group (e.g., future fields)
                        $grouped_keys = [];
                        foreach ($groups_for_module as $gk => $keys) $grouped_keys = array_merge($grouped_keys, $keys);
                        $ungrouped = array_diff(array_keys($schema['fields']), $grouped_keys);
                        if (!empty($ungrouped)):
                        ?>
                        <div class="mb-5 rounded-lg border border-amber-200 overflow-hidden">
                            <div class="px-4 py-2.5 bg-amber-50 border-b border-amber-200 flex items-center gap-2">
                                <i class="fas fa-info-circle text-amber-600 text-sm"></i>
                                <span class="text-sm font-semibold text-amber-800">Дополнительные настройки</span>
                            </div>
                            <div class="p-4">
                                <?php foreach ($ungrouped as $key):
                                    $field = $schema['fields'][$key];
                                    $val = $current[$key] ?? $field['default'];
                                    echo render_module_field($key, $field, $val, $module_name, $inp, $lbl);
                                endforeach; ?>
                            </div>
                        </div>
                        <?php
                        endif;
                    else:
                        // Linear layout (default) — for modules without group definitions
                        foreach ($schema['fields'] as $key => $field):
                            $val = ($module_name === 'social_links' && in_array($key, ['telegram','vk','youtube','instagram','facebook','twitter','github','whatsapp']))
                                ? ($current['platforms'][$key] ?? $field['default'])
                                : ($current[$key] ?? $field['default']);
                            echo render_module_field($key, $field, $val, $module_name, $inp, $lbl);
                        endforeach;
                    endif;
                    ?>
                    <div class="mt-4 pt-4 border-t border-slate-100 text-right">
                        <button type="submit" class="aicms-btn aicms-btn--success">
                            <i class="fas fa-save"></i> <?= t('save_module_settings') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
