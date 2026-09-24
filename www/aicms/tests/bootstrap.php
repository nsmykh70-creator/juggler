<?php
/**
 * PHPUnit test bootstrap.
 * =======================
 * Sets up a clean test environment for AICMS:
 *   - Defines test data directory (sys_get_temp_dir based)
 *   - Stubs session_start() if not available (CLI)
 *   - Loads composer autoloader if present, else falls back to manual require_once
 */

// Detect if running in CLI mode
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Tests must be run from CLI: vendor/bin/phpunit\n");
    exit(1);
}

// Suppress session warnings in CLI
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Set up test constants
define('AICMS_TESTING', true);
define('AICMS_TEST_DATA_DIR', sys_get_temp_dir() . '/aicms_test_' . getmypid());

// Create clean test data directory
if (is_dir(AICMS_TEST_DATA_DIR)) {
    // Recursively delete
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AICMS_TEST_DATA_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rii as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir(AICMS_TEST_DATA_DIR);
}
mkdir(AICMS_TEST_DATA_DIR, 0755, true);
mkdir(AICMS_TEST_DATA_DIR . '/content', 0755, true);
mkdir(AICMS_TEST_DATA_DIR . '/content/ru', 0755, true);
mkdir(AICMS_TEST_DATA_DIR . '/content/en', 0755, true);

// Default config.json for tests
$test_config = [
    'site_title' => 'Test Site',
    'default_lang' => 'ru',
    'languages' => [
        ['code' => 'ru', 'name' => 'Русский', 'flag' => '🇷🇺'],
        ['code' => 'en', 'name' => 'English', 'flag' => '🇬🇧'],
    ],
    'theme' => 'aurora',
    'features' => [
        '2fa' => false,
        'csp_headers' => true,
    ],
];
file_put_contents(AICMS_TEST_DATA_DIR . '/config.json', json_encode($test_config));

// Default empty users.json
file_put_contents(AICMS_TEST_DATA_DIR . '/users.json', json_encode([
    'username' => 'admin',
    'password' => password_hash('test123', PASSWORD_DEFAULT),
    'role' => 'admin',
    'totp_secret' => '',
]));

// Load composer autoloader if available, else manual require
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // Manual fallback: load core files in dependency order
    require_once __DIR__ . '/../admin/core/functions.php';
    require_once __DIR__ . '/../admin/core/totp.php';
    require_once __DIR__ . '/../admin/core/multisite.php';
    require_once __DIR__ . '/../admin/core/form_protection.php';
    require_once __DIR__ . '/../admin/core/rate_limit.php';
}

// Helper: write a test file to test data dir
function test_write_file($relative_path, $content) {
    $full = AICMS_TEST_DATA_DIR . '/' . $relative_path;
    if (!is_dir(dirname($full))) mkdir(dirname($full), 0755, true);
    file_put_contents($full, $content);
}

// Helper: read a test file
function test_read_file($relative_path) {
    return file_get_contents(AICMS_TEST_DATA_DIR . '/' . $relative_path);
}

// Helper: clean up test data (called in tearDown via afterClass)
function test_cleanup() {
    if (!is_dir(AICMS_TEST_DATA_DIR)) return;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(AICMS_TEST_DATA_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rii as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir(AICMS_TEST_DATA_DIR);
}

register_shutdown_function('test_cleanup');
