<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

// Проверка на наличие критически важного расширения PHP
if (!class_exists('ZipArchive')) {
    set_flash_message('Критическая ошибка: Расширение ZipArchive не установлено на сервере. Резервное копирование невозможно.', 'danger');
    header('Location: backups.php');
    exit;
}

$backup_dir = realpath(__DIR__ . '/../backup') . '/';
$root_path = realpath(__DIR__ . '/..');

// Folders/files to EXCLUDE from backup
$exclude_paths = ['backup', 'logs', 'cache'];

function add_folder_to_zip($folder_path, $zip, $root_path, $exclude_paths, $prefix = '') {
    if (!is_dir($folder_path)) return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            // Build relative path from CMS root
            $relativePath = substr($filePath, strlen($root_path) + 1);
            // Normalize for cross-platform
            $relativePath = str_replace('\\', '/', $relativePath);

            // Skip excluded folders (backup, logs, cache)
            $skip = false;
            foreach ($exclude_paths as $ex) {
                if (strpos($relativePath, $ex . '/') === 0 || $relativePath === $ex) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Use prefix if provided (for root-level files)
            $zipPath = $prefix ? $prefix . '/' . $relativePath : $relativePath;
            $zip->addFile($filePath, $zipPath);
        }
    }
}

// --- Логика обработки запросов ---

// Запрос на СКАЧИВАНИЕ (метод GET)
if (isset($_GET['download'])) {
    $file_to_download = $backup_dir . basename($_GET['download']);
    if (file_exists($file_to_download)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file_to_download) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_to_download));
        readfile($file_to_download);
        exit;
    } else {
        set_flash_message('Файл для скачивания не найден.', 'danger');
        header('Location: backups.php');
        exit;
    }
}

// Запросы на СОЗДАНИЕ или УДАЛЕНИЕ (метод POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash_message('Ошибка безопасности (CSRF).', 'danger');
        header('Location: backups.php');
        exit;
    }

    // Запрос на УДАЛЕНИЕ
    if (isset($_POST['delete'])) {
        $file_to_delete = $backup_dir . basename($_POST['delete']);
        if (file_exists($file_to_delete)) {
            unlink($file_to_delete);
            set_flash_message('Резервная копия успешно удалена.', 'success');
        } else {
            set_flash_message('Файл для удаления не найден.', 'warning');
        }
    }
    
    // Запрос на СОЗДАНИЕ
    elseif (isset($_POST['create'])) {
        $filename = 'backup-' . date('Y-m-d_H-i-s') . '.zip';
        $filepath = $backup_dir . $filename;
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            set_flash_message('Не удалось создать ZIP-архив.', 'danger');
        } else {
            // Backup ALL folders and files in CMS root, except excluded ones
            $all_entries = scandir($root_path);
            foreach ($all_entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                // Skip excluded folders
                if (in_array($entry, $exclude_paths)) continue;
                // Skip hidden files (.installed, .htaccess, etc. — optional)
                // We include .htaccess since it's important for clean URLs
                $full_path = $root_path . '/' . $entry;
                if (is_dir($full_path)) {
                    add_folder_to_zip($full_path, $zip, $root_path, $exclude_paths);
                } else {
                    // Root-level file (index.php, install.php, .htaccess, etc.)
                    $zip->addFile($full_path, $entry);
                }
            }
            $zip->close();
            set_flash_message('Резервная копия успешно создана (все файлы кроме backup/, logs/, cache/).', 'success');
        }
    }

    header('Location: backups.php');
    exit;
}

// Если зашли просто по GET без параметров, перенаправляем
header('Location: backups.php');
exit;