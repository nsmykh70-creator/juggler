<?php
// Файл: /widgets/latest_news.php
//
// ВЫВОД НОВОСТЕЙ
// --------------
// Если в контенте есть Lumina-кнопка [data-lumina-toggle]:
//   - Выводим один .news-content-full с классом has-lumina-toggle
//   - НЕ добавляем legacy-кнопку
//   - Lumina-кнопка видна в редакторе, работает на фронтенде
//
// Если в контенте НЕТ Lumina-кнопки:
//   - Используем оригинальный механизм (short/full split + legacy кнопка)
//   - legacy-кнопка работает на фронтенде, но не видна в редакторе

function get_html_announcement($html, $paragraphs_to_keep = 4) {
    if (empty(trim($html))) {
        return ['short' => '', 'full' => '', 'truncated' => false];
    }
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $all_nodes = $dom->childNodes;
    $total_nodes = $all_nodes->length;
    if ($total_nodes <= $paragraphs_to_keep) {
        return ['short' => $html, 'full' => $html, 'truncated' => false];
    }
    $announcement_dom = new DOMDocument();
    $announcement_dom->loadHTML('<?xml encoding="utf-8" ?>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    for ($i = 0; $i < $paragraphs_to_keep; $i++) {
        if ($all_nodes->item($i) !== null) {
            $node_to_import = $announcement_dom->importNode($all_nodes->item($i), true);
            $announcement_dom->appendChild($node_to_import);
        }
    }
    $short_html = $announcement_dom->saveHTML();
    $short_html = preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $short_html);
    return ['short' => $short_html, 'full' => $html, 'truncated' => true];
}

// Load module settings
$module_file = __DIR__ . '/../admin/core/module_settings.php';
if (file_exists($module_file)) require_once $module_file;
$ln_settings = function_exists('module_get_settings')
    ? module_get_settings('latest_news', [
        'title' => 'Последние новости', 'count' => 5,
        'show_date' => true, 'show_toggle' => true, 'preview_chars' => 0,
    ])
    : ['title' => 'Последние новости', 'count' => 5, 'show_date' => true, 'show_toggle' => true, 'preview_chars' => 0];

if (!empty($ln_settings['count']) && $ln_settings['count'] > 0) {
    $news_to_display = array_slice($news_to_display, 0, (int)$ln_settings['count']);
}
if (!isset($news_to_display) || empty($news_to_display)) {
    echo '<p>Новостей пока нет.</p>';
    return;
}
$news_to_display = array_values(array_filter($news_to_display, function($item) {
    return !isset($item['published']) || $item['published'] === true;
}));
if (empty($news_to_display)) {
    echo '<p>Новостей пока нет.</p>';
    return;
}
?>

<div class="news-blog-container">
    <?php foreach ($news_to_display as $news_item): ?>
        <?php
        $raw_content = $news_item['content'] ?? '';

        // Check if content has a Lumina toggle button
        $has_lumina_toggle = (strpos($raw_content, 'data-lumina-toggle') !== false);

        // Set global context for {{module:comments}}
        $GLOBALS['current_news_item'] = $news_item;

        if ($has_lumina_toggle):
            // ─── Lumina toggle path ───
            // Content has a [data-lumina-toggle] button (inserted via editor
            // OR auto-injected by save_news.php). Output single .news-content-full.
            // The Lumina button handles collapse/expand via lumina-toggle.js.
            // The has-lumina-toggle class on <article> tells CSS to show
            // .news-content-full regardless of is-expanded state.
            $full_html = function_exists('process_shortcodes')
                ? process_shortcodes($raw_content)
                : $raw_content;
            ?>
            <article class="news-item has-lumina-toggle" id="news-<?= h($news_item['id']) ?>">
                <h2 class="news-title"><?= h($news_item['title']) ?></h2>
                <?php if (!empty($ln_settings['show_date'])): ?>
                <p class="news-date text-muted small mb-3">
                    <i class="far fa-calendar"></i> Опубликовано: <?= date('d.m.Y', strtotime($news_item['date'])) ?>
                </p>
                <?php endif; ?>
                <div class="news-content-full fr-view">
                    <?= $full_html ?>
                </div>
            </article>
        <?php else:
            // ─── Legacy path (original widget) ───
            // No Lumina button in content. Use short/full split + legacy button.
            $content_parts = get_html_announcement($raw_content);
            if (!empty($ln_settings['preview_chars']) && $ln_settings['preview_chars'] > 0) {
                $short_text = strip_tags($content_parts['short']);
                if (mb_strlen($short_text) > $ln_settings['preview_chars']) {
                    $content_parts['short'] = '<p>' . mb_substr($short_text, 0, $ln_settings['preview_chars']) . '...</p>';
                }
            }
            $full_html = function_exists('process_shortcodes')
                ? process_shortcodes($content_parts['full'])
                : $content_parts['full'];
            ?>
            <article class="news-item" id="news-<?= h($news_item['id']) ?>">
                <h2 class="news-title"><?= h($news_item['title']) ?></h2>
                <?php if (!empty($ln_settings['show_date'])): ?>
                <p class="news-date text-muted small mb-3">
                    <i class="far fa-calendar"></i> Опубликовано: <?= date('d.m.Y', strtotime($news_item['date'])) ?>
                </p>
                <?php endif; ?>

                <div class="news-content-short fr-view">
                    <?= $content_parts['short'] ?>
                </div>

                <?php if ($content_parts['truncated'] && !empty($ln_settings['show_toggle'])): ?>
                    <div class="news-content-full fr-view">
                        <?= $full_html ?>
                    </div>
                    <button class="toggle-news-btn">Читать далее</button>
                <?php else: ?>
                    <div class="news-content-full fr-view">
                        <?= $full_html ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
