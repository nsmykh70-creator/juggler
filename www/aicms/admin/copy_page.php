<?php
/**
 * Copy a page from one language to another.
 * POST: id, from_lang, to_lang, csrf_token
 */
require_once __DIR__ . '/core/functions.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash_message('CSRF error.', 'danger');
    header('Location: pages.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$from_lang = $_POST['from_lang'] ?? '';
$to_lang = $_POST['to_lang'] ?? '';

if ($id <= 0 || $from_lang === $to_lang) {
    set_flash_message('Invalid parameters.', 'danger');
    header('Location: pages.php');
    exit;
}

// Find source page
$source_pages = read_content('pages.json', [], $from_lang);
$source_page = null;
foreach ($source_pages as $p) {
    if ($p['id'] === $id) { $source_page = $p; break; }
}

if (!$source_page) {
    set_flash_message('Source page not found.', 'danger');
    header('Location: pages.php');
    exit;
}

// Load target pages
$target_pages = read_content('pages.json', [], $to_lang);

// Check if page with same ID already exists in target
foreach ($target_pages as $p) {
    if ($p['id'] === $id) {
        set_flash_message('Page with ID ' . $id . ' already exists in ' . strtoupper($to_lang) . '.', 'warning');
        header('Location: pages.php');
        exit;
    }
}

// Clone the page
$new_page = $source_page;
$new_page['published'] = false; // Unpublish in target language by default
$target_pages[] = $new_page;

write_content('pages.json', $target_pages, $to_lang);

require_once __DIR__ . '/core/cache.php';
cache_clear();

set_flash_message('Page copied to ' . strtoupper($to_lang) . ' (unpublished). Edit and publish it.', 'success');
header('Location: edit_page.php?id=' . $id);
exit;
