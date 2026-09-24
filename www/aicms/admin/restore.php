<?php
require_once __DIR__ . '/core/functions.php';
require_auth();

if($_SERVER['REQUEST_METHOD']!=='POST' || !verify_csrf_token($_POST['csrf_token']??'')){ set_flash_message('Ошибка CSRF.','danger');header('Location: backups.php');exit; }
if(!class_exists('ZipArchive')){ set_flash_message('Ошибка: Класс ZipArchive не найден.','danger');header('Location: backups.php');exit; }

$filename = basename($_POST['file'] ?? '');
$backup_file = realpath(__DIR__ . '/../backup/' . $filename);
$root_path = realpath('..');
$folders_to_restore = ['data', 'themes', 'modules', 'uploads'];

if(!$backup_file || !file_exists($backup_file)) { set_flash_message('Файл резервной копии не найден.','danger');header('Location: backups.php');exit; }

// --- Вспомогательные функции ---
function add_folder_to_zip($folder, $zip, $root_path) { $path=$root_path.'/'.$folder; if(!is_dir($path))return; $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY); foreach($files as $file){if(!$file->isDir()){$filePath=$file->getRealPath();$relativePath=$folder.'/'.substr($filePath,strlen($path)+1);$zip->addFile($filePath,$relativePath);}}}
function delete_dir($dirPath){ if(!is_dir($dirPath))return; if(substr($dirPath,strlen($dirPath)-1,1)!='/'){$dirPath.='/';} $files=glob($dirPath.'*',GLOB_MARK); foreach($files as $file){if(is_dir($file)){delete_dir($file);}else{unlink($file);}} if(is_dir($dirPath)) rmdir($dirPath); }

// 1. Создаем автоматический бэкап текущего состояния перед восстановлением
$auto_backup_file = $root_path . '/backup/auto_backup_before_restore_'.date('Y-m-d_H-i-s').'.zip';
$zip_pre = new ZipArchive(); 
$zip_pre->open($auto_backup_file, ZipArchive::CREATE);
foreach($folders_to_restore as $folder) { add_folder_to_zip($folder, $zip_pre, $root_path); } 
$zip_pre->close();

// 2. Удаляем старые папки
foreach($folders_to_restore as $folder) { if(is_dir($root_path.'/'.$folder)) { delete_dir($root_path.'/'.$folder); } }

// 3. Распаковываем архив
$zip_restore = new ZipArchive();
if ($zip_restore->open($backup_file) === TRUE) {
    $zip_restore->extractTo($root_path);
    $zip_restore->close();
    set_flash_message('Сайт успешно восстановлен из резервной копии.', 'success');
} else {
    set_flash_message('Не удалось открыть архив для восстановления.', 'danger');
}

header('Location: backups.php');
exit;