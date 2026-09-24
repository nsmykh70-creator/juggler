<?php
/**
 * AICMS Admin — Test DB Connection (AJAX endpoint)
 * =================================================
 * Called from settings.php?tab=database to test MySQL/SQLite credentials
 * without saving them. Returns JSON.
 *
 * Request: POST with form fields db_driver, db_host, db_port, db_name,
 *          db_user, db_pass, db_path, db_prefix, csrf_token
 * Response: { connected: bool, result: string, received: {...}, extension_check: {...} }
 */
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/env.php';

// Auth — must be logged in as admin
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// CSRF — same session token as the rest of admin
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'CSRF error']);
    exit;
}

// Always JSON — never HTML (so the AJAX caller can parse it even on error)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Read submitted fields. If a field is empty (e.g. password was not re-entered),
// fall back to the value stored in data/.env.php — this matches the behaviour
// of the settings form where saved creds are not re-displayed.
$env = aicms_env();
$driver   = $_POST['db_driver'] ?? 'json';
$host     = trim($_POST['db_host'] ?? '') ?: ($env['DB_HOST'] ?? 'localhost');
$port     = trim($_POST['db_port'] ?? '') ?: ($env['DB_PORT'] ?? '3306');
$database = trim($_POST['db_name'] ?? '') ?: ($env['DB_NAME'] ?? '');
$user     = trim($_POST['db_user'] ?? '') ?: ($env['DB_USER'] ?? '');
$pass     = $_POST['db_pass'] ?? '';  // do NOT trim password
if ($pass === '') $pass = $env['DB_PASS'] ?? '';  // fall back to saved password
$path     = trim($_POST['db_path'] ?? '') ?: ($env['DB_PATH'] ?? 'data/aicms.db');
$prefix   = trim($_POST['db_prefix'] ?? '') ?: ($env['DB_PREFIX'] ?? 'aicms_');

// Sanitize: strip CR/LF/NUL from string fields to avoid log injection
$host     = str_replace(["\r", "\n", "\0"], '', $host);
$database = str_replace(["\r", "\n", "\0"], '', $database);
$user     = str_replace(["\r", "\n", "\0"], '', $user);

$result = [
    'connected' => false,
    'result' => '',
    'received' => [
        'driver'   => $driver,
        'host'     => $host,
        'port'     => $port,
        'database' => $database,
        'username' => $user,
        'password_length' => strlen($pass),
        'path'     => $path,
        'prefix'   => $prefix,
        'all_post_keys' => array_keys($_POST),
    ],
    'extension_check' => [
        'pdo_mysql'  => extension_loaded('pdo_mysql')  ? 'yes' : 'NO',
        'pdo_sqlite' => extension_loaded('pdo_sqlite') ? 'yes' : 'NO',
    ],
];

// JSON mode — no DB test needed
if ($driver === 'json') {
    $result['connected'] = true;
    $result['result'] = 'JSON mode — no DB connection required. Content is stored in files.';
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// === Pre-flight validation: tell the user EXACTLY which field is missing
// instead of failing with a cryptic SQLSTATE error.
if ($driver === 'mysql') {
    $missing = [];
    if ($host === '')     $missing[] = 'Хост (обычно "localhost")';
    if ($database === '') $missing[] = 'Имя базы данных (например, "aicms2")';
    if ($user === '')     $missing[] = 'Пользователь MySQL (это НЕ логин админки!)';
    if ($pass === '')     $missing[] = 'Пароль MySQL-пользователя';
    if (!empty($missing)) {
        $result['result'] = "Не заполнены обязательные поля:\n\n  • " . implode("\n  • ", $missing)
            . "\n\nЗаполните ВСЕ поля формы и повторите тест. Подсказки полей указывают правильные значения.";
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Try the actual connection
try {
    if ($driver === 'mysql') {
        if (!extension_loaded('pdo_mysql')) {
            throw new PDOException('PHP extension pdo_mysql is not installed. Contact your hosting provider or install it: apt install php-mysql');
        }
        // Validate required fields
        if ($database === '') {
            throw new PDOException('Database name is required.');
        }
        if ($host === '') {
            $host = 'localhost';
        }
        if ($port === '') {
            $port = '3306';
        }
        // Build DSN — charset=utf8mb4 is critical for proper Unicode support
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // 5-second timeout — default is 60s and that's way too long for a test
            PDO::ATTR_TIMEOUT => 5,
        ]);
        // Verify the connection by running a trivial query
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        // Check if user has CREATE permission (we'll need it for tables)
        try {
            $perm = $pdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchColumn();
        } catch (Exception $e) {
            $perm = '(could not check grants)';
        }
        $result['connected'] = true;
        $result['result'] = "✓ Подключение к MySQL {$version} установлено.\n"
            . "Сервер: {$host}:{$port}\n"
            . "База: {$database}\n"
            . "Пользователь: {$user}\n"
            . "Права: " . substr($perm, 0, 200);
    } elseif ($driver === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) {
            throw new PDOException('PHP extension pdo_sqlite is not installed.');
        }
        // Resolve path relative to CMS root
        if (strpos($path, '/') !== 0) {
            $path = __DIR__ . '/../' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Check write permission on directory
        if (!is_writable($dir)) {
            throw new PDOException("Directory not writable: {$dir}. Check permissions (chmod 775).");
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
        $size = file_exists($path) ? filesize($path) : 0;
        $result['connected'] = true;
        $result['result'] = "Connected to SQLite {$version}.\nFile: {$path}\nSize: " . number_format($size) . " bytes";
    } else {
        throw new PDOException("Unknown driver: {$driver}");
    }
} catch (PDOException $e) {
    $result['connected'] = false;
    $msg = $e->getMessage();
    // Provide actionable hints based on common errors
    $hint = '';
    if (strpos($msg, 'Access denied') !== false) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "1. Проверьте, что пользователь «{$user}» существует в MySQL и имеет пароль.\n";
        $hint .= "2. Создайте пользователя и дайте права на БД «{$database}» через phpMyAdmin → SQL:\n\n";
        $hint .= "   CREATE USER '{$user}'@'{$host}' IDENTIFIED WITH mysql_native_password BY 'ВАШ_ПАРОЛЬ';\n";
        $hint .= "   GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'{$host}';\n";
        $hint .= "   FLUSH PRIVILEGES;\n\n";
        $hint .= "3. На MySQL 8+ важно: IDENTIFIED WITH mysql_native_password\n";
        $hint .= "   (иначе будет ошибка caching_sha2_password).\n";
        $hint .= "4. Проверьте, что пользователь может подключаться с хоста «{$host}»\n";
        $hint .= "   (вместо «localhost» попробуйте «%» или «127.0.0.1»)";
    } elseif (strpos($msg, 'Unknown database') !== false || (stripos($msg, 'database') !== false && stripos($msg, 'not exist') !== false)) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "База «{$database}» не существует. Создайте её:\n\n";
        $hint .= "   CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n\n";
        $hint .= "Затем повторите тест подключения.";
    } elseif (strpos($msg, 'Connection refused') !== false || strpos($msg, 'No connection could be made') !== false) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "Connection refused. Проверьте:\n";
        $hint .= "• MySQL запущен на {$host}:{$port}\n";
        $hint .= "• Брандмауэр не блокирует порт {$port}\n";
        $hint .= "• Для удалённого MySQL — bind-address в my.cnf корректный\n";
        $hint .= "• На shared-хостинге — используйте хост из письма с доступами";
    } elseif (strpos($msg, 'php_network_getaddresses') !== false || strpos($msg, 'getaddrinfo') !== false) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "Не удалось разрешить имя хоста «{$host}».\n";
        $hint .= "Используйте IP-адрес (например, 127.0.0.1) или «localhost».";
    } elseif (stripos($msg, 'pdo_mysql') !== false || stripos($msg, 'pdo_sqlite') !== false) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "PHP-расширение не загружено. Установите:\n";
        $hint .= "  apt install php-mysql   (или php-sqlite3)\n";
        $hint .= "Или попросите хостинг-провайдера включить расширение.";
    } elseif (strpos($msg, 'writable') !== false) {
        $hint = "\n\n=== ЧТО ДЕЛАТЬ ===\n";
        $hint .= "Директория SQLite не доступна для записи:\n";
        $hint .= "  chmod -R 775 " . dirname($path);
    }
    $result['result'] = $msg . $hint;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
