<?php
/**
 * AICMS News Import — RSS/CSV
 * ===========================
 * Import news from an RSS feed URL or a CSV file upload.
 * 
 * Usage:
 *   admin/import_news.php — opens the import page
 *   POST with rss_url — imports from RSS
 *   POST with csv_file — imports from CSV
 */

require_once __DIR__ . '/core/functions.php';
require_auth();

$page_title = 'Импорт новостей';
$breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('news'),'url'=>'news.php'],['label'=>'Импорт']];
ob_start();
?>
<a href="news.php" class="aicms-icon-btn aicms-icon-btn--secondary"><i class="fas fa-arrow-left"></i><span class="btn-label">К списку</span></a>
<?php $action_panel = ob_get_clean();

// Process import
$imported = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $lang = $_POST['lang'] ?? ADMIN_LANG;
    $all_news = read_content('news.json', [], $lang);
    $max_id = empty($all_news) ? 0 : max(array_column($all_news, 'id'));
    
    // RSS import
    if (!empty($_POST['rss_url'])) {
        $rss_url = trim($_POST['rss_url']);
        $xml = @simplexml_load_file($rss_url);
        if ($xml === false) {
            $errors[] = 'Не удалось загрузить RSS-фид. Проверьте URL.';
        } else {
            $items = $xml->channel->item ?? $xml->entry ?? [];
            foreach ($items as $item) {
                $title = (string)($item->title);
                $link = (string)($item->link);
                $description = (string)($item->description ?? $item->summary ?? '');
                $pub_date = (string)($item->pubDate ?? date('Y-m-d'));
                $date = date('Y-m-d', strtotime($pub_date));
                
                // Convert description to HTML content
                $content = '<p>' . htmlspecialchars($description) . '</p>';
                if (!empty($link)) {
                    $content .= '<p><a href="' . htmlspecialchars($link) . '" target="_blank">Читать далее →</a></p>';
                }
                
                $max_id++;
                $all_news[] = [
                    'id' => $max_id,
                    'title' => $title,
                    'content' => $content,
                    'date' => $date,
                    'published' => true,
                    'status' => 'published',
                    'category' => '',
                    'tags' => 'импорт, RSS',
                    'created' => date('Y-m-d H:i:s'),
                ];
                $imported++;
            }
            if ($imported > 0) {
                write_content('news.json', $all_news, $lang);
                require_once __DIR__ . '/core/cache.php';
                cache_clear();
            }
        }
    }
    
    // CSV import
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $csv_content = file_get_contents($_FILES['csv_file']['tmp_name']);
        $lines = str_getcsv($csv_content, "\n");
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) < 2) continue;
            // CSV format: title,date,content[,category,tags]
            $title = trim($row[0]);
            $date = trim($row[1] ?? date('Y-m-d'));
            $content = trim($row[2] ?? '');
            $category = trim($row[3] ?? '');
            $tags = trim($row[4] ?? 'импорт, CSV');
            
            if (empty($title)) continue;
            
            $max_id++;
            $all_news[] = [
                'id' => $max_id,
                'title' => $title,
                'content' => '<p>' . nl2br(htmlspecialchars($content)) . '</p>',
                'date' => $date,
                'published' => true,
                'status' => 'published',
                'category' => $category,
                'tags' => $tags,
                'created' => date('Y-m-d H:i:s'),
            ];
            $imported++;
        }
        if ($imported > 0) {
            write_content('news.json', $all_news, $lang);
            require_once __DIR__ . '/core/cache.php';
            cache_clear();
        }
    }
    
    if ($imported > 0) {
        set_flash_message("Импортировано новостей: {$imported}", 'success');
        header('Location: news.php');
        exit;
    }
    if ($errors) {
        set_flash_message('Ошибки: ' . implode('; ', $errors), 'danger');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= h($page_title) ?> — AICMS</title>
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
            
            <div class="max-w-2xl">
                <div class="bg-white rounded-xl shadow-sm p-6 mb-4">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4"><i class="fas fa-rss text-orange-500 mr-2"></i>Импорт из RSS</h3>
                    <form method="POST" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="lang" value="<?= ADMIN_LANG ?>">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">URL RSS-фида</label>
                            <input type="url" name="rss_url" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="https://example.com/rss.xml" required>
                        </div>
                        <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-download"></i><span class="btn-label">Импортировать из RSS</span></button>
                    </form>
                </div>
                
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-slate-800 mb-4"><i class="fas fa-file-csv text-green-500 mr-2"></i>Импорт из CSV</h3>
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="lang" value="<?= ADMIN_LANG ?>">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">CSV файл</label>
                            <input type="file" name="csv_file" accept=".csv" class="w-full px-3 py-2 border border-slate-300 rounded-lg" required>
                            <p class="text-xs text-slate-400 mt-1">Формат: title,date,content,category,tags (заголовок обязателен)</p>
                        </div>
                        <button type="submit" class="aicms-icon-btn aicms-icon-btn--primary"><i class="fas fa-upload"></i><span class="btn-label">Импортировать из CSV</span></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
</body>
</html>
