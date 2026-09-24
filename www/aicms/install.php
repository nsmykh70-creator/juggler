<?php
/**
 * AICMS Installer — Joomla-style multi-step wizard
 * Supports: JSON files, MySQL, SQLite storage backends
 * Includes .htaccess generator/editor
 */
session_start();

// ============== INSTALLER LOCK ==============
$lockFile = __DIR__ . '/data/.installed';
if (file_exists($lockFile) && empty($_GET['force'])) {
    header('Location: index.php');
    exit;
}

// ============== I18N ==============
$lang = $_POST['installer_lang'] ?? ($_SESSION['installer_lang'] ?? 'ru');
$_SESSION['installer_lang'] = $lang;

$i18n = [
    'ru' => [
        'step_welcome' => 'Добро пожаловать', 'step_check' => 'Проверка', 'step_db' => 'База данных',
        'step_htaccess' => '.htaccess', 'step_site' => 'Сайт', 'step_admin' => 'Администратор',
        'step_install' => 'Установка', 'step_done' => 'Готово',
        'welcome_title' => 'Установка AICMS',
        'welcome_desc' => 'Простая и быстрая CMS с ИИ-ассистентом. Этот мастер поможет настроить систему за несколько минут.',
        'next' => 'Далее', 'back' => 'Назад', 'install' => 'Установить',
        'finish' => 'Перейти в панель управления',
        'req_title' => 'Проверка сервера', 'req_php' => 'Версия PHP',
        'req_ext' => 'Расширения PHP', 'req_dir' => 'Права записи',
        'req_ok' => 'OK', 'req_fail' => 'Ошибка', 'req_warn' => 'Внимание',
        'db_title' => 'Настройка хранения данных', 'db_choose' => 'Выберите тип хранилища',
        'db_json_desc' => 'Файлы JSON в папке data/ (по умолчанию, не требует базы данных)',
        'db_mysql_desc' => 'Сервер MySQL / MariaDB (для высоконагруженных проектов)',
        'db_sqlite_desc' => 'Файл SQLite (лёгкая база данных в одном файле)',
        'db_test' => 'Проверить подключение', 'db_test_ok' => 'Подключение успешно!',
        'db_test_fail' => 'Ошибка подключения',
        'db_host' => 'Хост', 'db_port' => 'Порт', 'db_name' => 'База данных',
        'db_user' => 'Пользователь', 'db_pass' => 'Пароль', 'db_prefix' => 'Префикс таблиц',
        'db_path' => 'Путь к файлу БД',
        'htaccess_title' => 'Настройка .htaccess',
        'htaccess_desc' => 'Настройте файл .htaccess для безопасности и оптимизации',
        'htaccess_preview' => 'Предпросмотр .htaccess', 'htaccess_security' => 'Безопасность',
        'htaccess_perf' => 'Производительность', 'htaccess_ssl' => 'SSL / HTTPS',
        'htaccess_custom' => 'Пользовательские правила',
        'htaccess_deny_data' => 'Запретить доступ к data/, backup/, logs/',
        'htaccess_hide_json' => 'Скрыть JSON-файлы от прямого доступа',
        'htaccess_cors' => 'CORS заголовки для ресурсов',
        'htaccess_gzip' => 'Gzip-сжатие', 'htaccess_cache' => 'Кэширование браузера',
        'htaccess_ssl_redirect' => 'Редирект HTTP \u2192 HTTPS',
        'htaccess_www' => 'Редирект www \u2192 без www',
        'site_title' => 'Настройка сайта', 'site_name' => 'Название сайта',
        'site_desc' => 'Описание', 'site_keywords' => 'Ключевые слова',
        'site_theme' => 'Тема оформления', 'site_lang' => 'Язык по умолчанию',
        'admin_title' => 'Создание администратора', 'admin_user' => 'Логин',
        'admin_pass' => 'Пароль', 'admin_pass2' => 'Повторите пароль',
        'admin_pass_short' => 'Пароль слишком короткий (мин. 6 символов)',
        'admin_pass_match' => 'Пароли не совпадают',
        'admin_user_short' => 'Логин слишком короткий (мин. 3 символа)',
        'installing' => 'Установка...', 'install_done' => 'AICMS успешно установлена!',
        'install_remove' => 'Удалить install.php для безопасности',
    ],
    'en' => [
        'step_welcome' => 'Welcome', 'step_check' => 'Check', 'step_db' => 'Database',
        'step_htaccess' => '.htaccess', 'step_site' => 'Site', 'step_admin' => 'Admin',
        'step_install' => 'Install', 'step_done' => 'Done',
        'welcome_title' => 'AICMS Installation',
        'welcome_desc' => 'Simple and fast CMS with AI assistant. This wizard will help you set up the system in a few minutes.',
        'next' => 'Next', 'back' => 'Back', 'install' => 'Install',
        'finish' => 'Go to admin panel',
        'req_title' => 'Server Requirements', 'req_php' => 'PHP Version',
        'req_ext' => 'PHP Extensions', 'req_dir' => 'Write Permissions',
        'req_ok' => 'OK', 'req_fail' => 'Failed', 'req_warn' => 'Warning',
        'db_title' => 'Data Storage Configuration', 'db_choose' => 'Choose storage type',
        'db_json_desc' => 'JSON files in data/ directory (default, no database required)',
        'db_mysql_desc' => 'MySQL / MariaDB server (for high-traffic projects)',
        'db_sqlite_desc' => 'SQLite file (lightweight database in a single file)',
        'db_test' => 'Test Connection', 'db_test_ok' => 'Connection successful!',
        'db_test_fail' => 'Connection failed',
        'db_host' => 'Host', 'db_port' => 'Port', 'db_name' => 'Database',
        'db_user' => 'Username', 'db_pass' => 'Password', 'db_prefix' => 'Table prefix',
        'db_path' => 'Database file path',
        'htaccess_title' => '.htaccess Configuration',
        'htaccess_desc' => 'Configure .htaccess for security and performance',
        'htaccess_preview' => '.htaccess Preview', 'htaccess_security' => 'Security',
        'htaccess_perf' => 'Performance', 'htaccess_ssl' => 'SSL / HTTPS',
        'htaccess_custom' => 'Custom Rules',
        'htaccess_deny_data' => 'Deny access to data/, backup/, logs/',
        'htaccess_hide_json' => 'Hide JSON files from direct access',
        'htaccess_cors' => 'CORS headers for assets',
        'htaccess_gzip' => 'Gzip compression', 'htaccess_cache' => 'Browser caching',
        'htaccess_ssl_redirect' => 'Redirect HTTP \u2192 HTTPS',
        'htaccess_www' => 'Redirect www to non-www',
        'site_title' => 'Site Configuration', 'site_name' => 'Site Name',
        'site_desc' => 'Description', 'site_keywords' => 'Keywords',
        'site_theme' => 'Theme', 'site_lang' => 'Default Language',
        'admin_title' => 'Create Administrator', 'admin_user' => 'Username',
        'admin_pass' => 'Password', 'admin_pass2' => 'Confirm Password',
        'admin_pass_short' => 'Password too short (min 6 chars)',
        'admin_pass_match' => 'Passwords do not match',
        'admin_user_short' => 'Username too short (min 3 chars)',
        'installing' => 'Installing...', 'install_done' => 'AICMS installed successfully!',
        'install_remove' => 'Remove install.php for security',
    ],
];
$t = $i18n[$lang] ?? $i18n['ru'];

// ============== STEP LOGIC ==============
$steps = ['welcome','check','db','site','admin','install','done'];
$currentStep = $_SESSION['install_step'] ?? 'welcome';

// Handle GET-based "back" navigation (outside POST block — GET requests)
if (isset($_GET['action']) && $_GET['action'] === 'go_back') {
    $from = $_GET['from'] ?? '';
    $fi = array_search($from, $steps);
    if ($fi !== false && $fi > 0) {
        $_SESSION['install_step'] = $steps[$fi - 1];
    }
    header('Location: install.php');
    exit;
}

// Handle direct step access via ?step= (e.g. install.php?step=done&force=1)
if (isset($_GET['step']) && in_array($_GET['step'], $steps)) {
    $_SESSION['install_step'] = $_GET['step'];
    $currentStep = $_GET['step'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_lang') {
        $_SESSION['installer_lang'] = $_POST['installer_lang'] ?? 'ru';
        $lang = $_SESSION['installer_lang'];
        $t = $i18n[$lang];
        header('Location: install.php');
        exit;
    }

    if ($action === 'go_step') {
        $target = $_POST['target'] ?? 'welcome';
        $ti = array_search($target, $steps);
        $ci = array_search($currentStep, $steps);
        if ($ti !== false && $ci !== false && $ti <= $ci + 1) {
            $_SESSION['install_step'] = $target;
        }
        header('Location: install.php');
        exit;
    }

    if ($action === 'test_db') {
        header('Content-Type: application/json');
        $type = $_POST['db_type'] ?? 'json';
        if ($type === 'mysql') {
            $host = $_POST['db_host'] ?? 'localhost';
            $port = $_POST['db_port'] ?? '3306';
            $user = $_POST['db_user'] ?? '';
            $pass = $_POST['db_pass'] ?? '';
            // Try localhost first, then 127.0.0.1 (TCP)
            $hosts = ($host === 'localhost') ? ['localhost', '127.0.0.1'] : [$host];
            $connected = false;
            $last_error = '';
            foreach ($hosts as $h) {
                try {
                    $dsn = "mysql:host={$h};port={$port};charset=utf8mb4";
                    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
                    echo json_encode(['ok' => true, 'msg' => $t['db_test_ok'] . " ({$h})"]);
                    $connected = true;
                    break;
                } catch (Exception $e) {
                    $last_error = $e->getMessage();
                }
            }
            if (!$connected) {
                echo json_encode(['ok' => false, 'msg' => $last_error]);
            }
        } elseif ($type === 'sqlite') {
            try {
                $path = $_POST['db_path'] ?? __DIR__ . '/data/aicms.db';
                $dir = dirname($path);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                echo json_encode(['ok' => true, 'msg' => $t['db_test_ok']]);
            } catch (Exception $e) {
                echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => true, 'msg' => 'JSON \u2014 no connection needed']);
        }
        exit;
    }

    if ($action === 'save_step') {
        $step = $_POST['step'] ?? '';
        if ($step === 'db') {
            $_SESSION['install_db'] = [
                'type' => $_POST['db_type'] ?? 'json', 'host' => $_POST['db_host'] ?? 'localhost',
                'port' => $_POST['db_port'] ?? '3306', 'name' => $_POST['db_name'] ?? 'aicms',
                'user' => $_POST['db_user'] ?? '', 'pass' => $_POST['db_pass'] ?? '',
                'prefix' => $_POST['db_prefix'] ?? 'aicms_', 'path' => $_POST['db_path'] ?? 'data/aicms.db',
            ];
        }
        if ($step === 'htaccess') {
            $_SESSION['install_htaccess'] = [
                'deny_data' => isset($_POST['htaccess_deny_data']), 'hide_json' => isset($_POST['htaccess_hide_json']),
                'cors' => isset($_POST['htaccess_cors']), 'gzip' => isset($_POST['htaccess_gzip']),
                'cache' => isset($_POST['htaccess_cache']), 'ssl_redirect' => isset($_POST['htaccess_ssl_redirect']),
                'www_redirect' => isset($_POST['htaccess_www']), 'custom' => $_POST['htaccess_custom'] ?? '',
            ];
        }
        if ($step === 'site') {
            $_SESSION['install_site'] = [
                'title' => $_POST['site_name'] ?? 'AICMS', 'desc' => $_POST['site_desc'] ?? '',
                'keywords' => $_POST['site_keywords'] ?? '', 'theme' => $_POST['site_theme'] ?? 'helios',
                'lang' => $_POST['site_lang'] ?? 'ru',
            ];
        }
        if ($step === 'admin') {
            $user = trim($_POST['admin_user'] ?? '');
            $pass = $_POST['admin_pass'] ?? '';
            $pass2 = $_POST['admin_pass2'] ?? '';
            $errors = [];
            if (strlen($user) < 3) $errors[] = $t['admin_user_short'];
            if (strlen($pass) < 6) $errors[] = $t['admin_pass_short'];
            if ($pass !== $pass2) $errors[] = $t['admin_pass_match'];
            if (empty($errors)) {
                $_SESSION['install_admin'] = ['user' => $user, 'pass' => $pass];
                $_SESSION['install_step'] = 'install';
                header('Location: install.php');
                exit;
            }
            $_SESSION['install_admin_errors'] = $errors;
            header('Location: install.php');
            exit;
        }
        $ni = array_search($step, $steps) + 1;
        if (isset($steps[$ni])) $_SESSION['install_step'] = $steps[$ni];
        header('Location: install.php');
        exit;
    }

    if ($action === 'do_install') {
        header('Content-Type: application/json');
        echo json_encode(doInstall());
        exit;
    }

    if ($action === 'remove_installer') {
        @unlink(__FILE__);
        header('Location: admin/');
        exit;
    }
}

// ============== REQUIREMENTS ==============
function checkRequirements() {
    $results = [];
    $results[] = ['label' => 'PHP >= 7.4', 'value' => PHP_VERSION, 'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'fail'];
    foreach ([['json',true],['session',true],['mbstring',false],['fileinfo',false],['pdo',false],['pdo_mysql',false],['pdo_sqlite',false],['zip',false],['curl',false]] as [$ext,$req]) {
        $loaded = extension_loaded($ext);
        $results[] = ['label' => $ext, 'value' => $loaded ? 'loaded' : 'missing', 'status' => $loaded ? 'ok' : ($req ? 'fail' : 'warn')];
    }
    foreach (['data/','data/content/','data/content/ru/','data/content/en/','uploads/','uploads/images/','backup/','logs/'] as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) @mkdir($path, 0755, true);
        $w = is_writable($path);
        $results[] = ['label' => $dir, 'value' => $w ? 'writable' : 'not writable', 'status' => $w ? 'ok' : 'fail'];
    }
    return $results;
}

// ============== INSTALLATION ==============
function doInstall() {
    $log = []; $ok = true;
    $dirs = ['data','data/content','data/content/ru','data/content/en','uploads','uploads/images','backup','logs','modules','widgets'];
    foreach ($dirs as $d) {
        $path = __DIR__ . '/' . $d;
        if (!is_dir($path)) { if (mkdir($path, 0755, true)) $log[] = ['ok',"Created: {$d}/"]; else { $log[] = ['fail',"Cannot create: {$d}/"]; $ok = false; } }
        else $log[] = ['ok',"Exists: {$d}/"];
    }

    $site = $_SESSION['install_site'] ?? ['title'=>'AICMS','desc'=>'','keywords'=>'','theme'=>'helios','lang'=>'ru'];
    $db = $_SESSION['install_db'] ?? ['type'=>'json'];
    $config = [
        'site_title' => $site['title'], 'site_description' => $site['desc'],
        'site_keywords' => $site['keywords'], 'favicon' => '',
        'theme' => $site['theme'],
        'languages' => [['code' => $site['lang'], 'name' => $site['lang'] === 'ru' ? '\u0420\u0443\u0441\u0441\u043a\u0438\u0439' : 'English']],
        'default_lang' => $site['lang'], 'ai_provider' => 'deepseek', 'show_news' => true,
        'storage' => [
            'driver' => $db['type'], 'host' => $db['host'] ?? 'localhost',
            'port' => $db['port'] ?? '3306', 'database' => $db['name'] ?? 'aicms',
            'username' => '', 'password' => '',
            'prefix' => $db['prefix'] ?? 'aicms_', 'path' => $db['path'] ?? 'data/aicms.db',
        ],
    ];
    // DB credentials are written to data/.env.php, NOT to config.json
    if (($db['type'] ?? 'json') === 'mysql') {
        $env_secrets = [
            'DB_HOST'   => $db['host'] ?? 'localhost',
            'DB_PORT'   => $db['port'] ?? '3306',
            'DB_NAME'   => $db['name'] ?? 'aicms',
            'DB_USER'   => $db['user'] ?? '',
            'DB_PASS'   => $db['pass'] ?? '',
            'DB_PREFIX' => $db['prefix'] ?? 'aicms_',
        ];
        $env_content = "<?php\n/** AICMS Environment Secrets — created by install.php */\nreturn " . var_export($env_secrets, true) . ";\n";
        if (file_put_contents(__DIR__ . '/data/.env.php', $env_content))
            $log[] = ['ok','Written: data/.env.php (DB credentials)'];
        else { $log[] = ['fail','Cannot write: data/.env.php']; $ok = false; }
    }
    if (file_put_contents(__DIR__ . '/data/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)))
        $log[] = ['ok','Written: data/config.json']; else { $log[] = ['fail','Cannot write: data/config.json']; $ok = false; }

    $admin = $_SESSION['install_admin'] ?? ['user'=>'admin','pass'=>'password'];
    $user = ['username' => $admin['user'], 'password' => password_hash($admin['pass'], PASSWORD_DEFAULT)];
    if (file_put_contents(__DIR__ . '/data/users.json', json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)))
        $log[] = ['ok','Created admin: ' . $admin['user']]; else { $log[] = ['fail','Cannot write: data/users.json']; $ok = false; }

    foreach (array_unique([$site['lang'],'ru','en']) as $l) {
        $cd = __DIR__ . "/data/content/{$l}";
        if (!is_dir($cd)) mkdir($cd, 0755, true);
        foreach (['pages.json','news.json'] as $f) {
            $fp = $cd . '/' . $f;
            if (!file_exists($fp)) { file_put_contents($fp, '[]'); $log[] = ['ok',"Created: data/content/{$l}/{$f}"]; }
        }
    }

    $homeTitle = $site['lang'] === 'en' ? 'Home' : '\u0413\u043b\u0430\u0432\u043d\u0430\u044f';
    $homeContent = $site['lang'] === 'en' ? '<p>Welcome to AICMS! Edit this page in the admin panel.</p>' : '<p>\u0414\u043e\u0431\u0440\u043e \u043f\u043e\u0436\u0430\u043b\u043e\u0432\u0430\u0442\u044c \u0432 AICMS! \u041e\u0442\u0440\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u0443\u0439\u0442\u0435 \u044d\u0442\u0443 \u0441\u0442\u0440\u0430\u043d\u0438\u0446\u0443 \u0432 \u043f\u0430\u043d\u0435\u043b\u0438 \u0443\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f.</p>';
    $pages = [['slug'=>'home','title'=>$homeTitle,'content'=>$homeContent,'template'=>'','date'=>date('Y-m-d H:i:s')]];
    file_put_contents(__DIR__ . "/data/content/{$site['lang']}/pages.json", json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $log[] = ['ok','Created default page: home'];

    // MySQL setup
    if ($db['type'] === 'mysql') {
        $mysql_ok = false;
        $mysql_errors = [];

        // Try multiple connection methods: localhost (socket), 127.0.0.1 (TCP)
        $hosts_to_try = [];
        if ($db['host'] === 'localhost') {
            $hosts_to_try = ['localhost', '127.0.0.1'];
        } else {
            $hosts_to_try = [$db['host']];
        }

        foreach ($hosts_to_try as $try_host) {
            try {
                $pdo = new PDO("mysql:host={$try_host};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>10]);
                $log[] = ['ok',"MySQL: Connected to {$try_host}"];
                $mysql_ok = true;
                break;
            } catch (PDOException $e) {
                $mysql_errors[] = "{$try_host}: " . $e->getMessage();
            }
        }

        if ($mysql_ok) {
            try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db['name']}`");
            $p = $db['prefix'];
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}pages`(`id`INT AUTO_INCREMENT PRIMARY KEY,`lang`VARCHAR(5)NOT NULL DEFAULT'ru',`slug`VARCHAR(255)NOT NULL,`title`VARCHAR(500)NOT NULL,`content`LONGTEXT,`template`VARCHAR(255),`custom_fields`LONGTEXT,`date`DATETIME,`sort_order`INT DEFAULT 0,UNIQUE(`lang`,`slug`))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}news`(`id`INT AUTO_INCREMENT PRIMARY KEY,`lang`VARCHAR(5)NOT NULL DEFAULT'ru',`title`VARCHAR(500)NOT NULL,`content`LONGTEXT,`date`DATE,`image`VARCHAR(500),UNIQUE(`lang`,`title`,`date`))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}settings`(`key`VARCHAR(255)PRIMARY KEY,`value`LONGTEXT)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}users`(`id`INT AUTO_INCREMENT PRIMARY KEY,`username`VARCHAR(255)UNIQUE NOT NULL,`password`VARCHAR(255)NOT NULL)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}stats`(`id`INT AUTO_INCREMENT PRIMARY KEY,`date`DATE NOT NULL,`ip`VARCHAR(45),`page`VARCHAR(500))ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $log[] = ['ok','MySQL: Core tables created'];

            // Module tables (comments, shop, forum)
            $module_tables = [
                "CREATE TABLE IF NOT EXISTS `{$p}comments`(`id`INT AUTO_INCREMENT PRIMARY KEY,`target_type`VARCHAR(20)DEFAULT'news',`target_id`INT NOT NULL,`lang`VARCHAR(5)DEFAULT'ru',`parent_id`INT DEFAULT 0,`author_name`VARCHAR(100),`author_email`VARCHAR(200),`content`LONGTEXT,`rating`INT DEFAULT 0,`ip`VARCHAR(45),`status`ENUM('pending','approved','spam','deleted')DEFAULT'pending',`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_products`(`id`INT AUTO_INCREMENT PRIMARY KEY,`title`VARCHAR(300)NOT NULL,`slug`VARCHAR(300),`description`LONGTEXT,`short_desc`VARCHAR(500),`price`DECIMAL(10,2)DEFAULT 0,`old_price`DECIMAL(10,2)DEFAULT 0,`sku`VARCHAR(100),`image`VARCHAR(500),`gallery`LONGTEXT,`category_id`INT DEFAULT 0,`stock`INT DEFAULT 0,`status`ENUM('active','hidden')DEFAULT'active',`position`INT DEFAULT 0,`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_categories`(`id`INT AUTO_INCREMENT PRIMARY KEY,`title`VARCHAR(200)NOT NULL,`slug`VARCHAR(200),`parent_id`INT DEFAULT 0,`position`INT DEFAULT 0)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_orders`(`id`INT AUTO_INCREMENT PRIMARY KEY,`order_number`VARCHAR(50),`customer_name`VARCHAR(200),`customer_email`VARCHAR(200),`customer_phone`VARCHAR(50),`customer_address`LONGTEXT,`items`LONGTEXT,`total`DECIMAL(10,2)DEFAULT 0,`status`ENUM('new','paid','shipped','done','cancelled')DEFAULT'new',`payment_method`VARCHAR(50),`notes`LONGTEXT,`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_categories`(`id`INT AUTO_INCREMENT PRIMARY KEY,`title`VARCHAR(200)NOT NULL,`slug`VARCHAR(200),`description`LONGTEXT,`position`INT DEFAULT 0)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_topics`(`id`INT AUTO_INCREMENT PRIMARY KEY,`title`VARCHAR(300)NOT NULL,`slug`VARCHAR(300),`category_id`INT DEFAULT 0,`author_name`VARCHAR(100),`author_email`VARCHAR(200),`content`LONGTEXT,`views`INT DEFAULT 0,`replies_count`INT DEFAULT 0,`is_pinned`INT DEFAULT 0,`is_closed`INT DEFAULT 0,`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP,`last_reply_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_posts`(`id`INT AUTO_INCREMENT PRIMARY KEY,`topic_id`INT NOT NULL,`author_name`VARCHAR(100),`author_email`VARCHAR(200),`content`LONGTEXT,`ip`VARCHAR(45),`status`ENUM('visible','hidden')DEFAULT'visible',`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_users`(`id`INT AUTO_INCREMENT PRIMARY KEY,`username`VARCHAR(100)UNIQUE NOT NULL,`email`VARCHAR(200)UNIQUE NOT NULL,`password`VARCHAR(255)NOT NULL,`avatar`VARCHAR(500),`posts_count`INT DEFAULT 0,`role`ENUM('user','moderator','admin')DEFAULT'user',`created_at`DATETIME DEFAULT CURRENT_TIMESTAMP)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];
            foreach ($module_tables as $mt) { try { $pdo->exec($mt); } catch (Exception $e) {} }
            $log[] = ['ok','MySQL: Module tables created (comments, shop, forum)'];

            $stmt = $pdo->prepare("INSERT IGNORE INTO `{$p}users`(`username`,`password`)VALUES(?,?)");
            $stmt->execute([$admin['user'], password_hash($admin['pass'], PASSWORD_DEFAULT)]);
            $log[] = ['ok','MySQL: Admin inserted'];
            } catch (PDOException $e) {
                $log[] = ['fail','MySQL: ' . $e->getMessage()];
                // Don't fail the whole install — CMS works without DB (JSON mode)
                $log[] = ['ok','Installation continues without MySQL (JSON-only mode)'];
            }
        } else {
            // MySQL connection failed — log warning, continue installation
            foreach ($mysql_errors as $err) {
                $log[] = ['fail','MySQL: ' . $err];
            }
            $log[] = ['ok','Installation continues without MySQL (JSON-only mode). You can configure DB later in data/config.json'];
            // Switch to JSON mode in config
            $config['storage']['driver'] = 'json';
            file_put_contents(__DIR__ . '/data/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    // SQLite setup
    if ($db['type'] === 'sqlite') {
        try {
            $dbPath = $db['path'];
            if (!str_starts_with($dbPath, '/')) $dbPath = __DIR__ . '/' . $dbPath;
            if (!is_dir(dirname($dbPath))) mkdir(dirname($dbPath), 0755, true);
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $p = $db['prefix'];
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}pages`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`lang`TEXT NOT NULL DEFAULT'ru',`slug`TEXT NOT NULL,`title`TEXT NOT NULL,`content`TEXT,`template`TEXT,`custom_fields`TEXT,`date`TEXT,`sort_order`INTEGER DEFAULT 0,UNIQUE(`lang`,`slug`))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}news`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`lang`TEXT NOT NULL DEFAULT'ru',`title`TEXT NOT NULL,`content`TEXT,`date`TEXT,`image`TEXT,UNIQUE(`lang`,`title`,`date`))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}settings`(`key`TEXT PRIMARY KEY,`value`TEXT)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}users`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`username`TEXT UNIQUE NOT NULL,`password`TEXT NOT NULL)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}stats`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`date`TEXT NOT NULL,`ip`TEXT,`page`TEXT)");
            $log[] = ['ok','SQLite: Core tables created'];

            // Module tables for SQLite
            $sqlite_module_tables = [
                "CREATE TABLE IF NOT EXISTS `{$p}comments`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`target_type`TEXT DEFAULT'news',`target_id`INTEGER NOT NULL,`lang`TEXT DEFAULT'ru',`parent_id`INTEGER DEFAULT 0,`author_name`TEXT,`author_email`TEXT,`content`TEXT,`rating`INTEGER DEFAULT 0,`ip`TEXT,`status`TEXT DEFAULT'pending',`created_at`TEXT DEFAULT(datetime('now','localtime')))",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_products`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`title`TEXT NOT NULL,`slug`TEXT,`description`TEXT,`short_desc`TEXT,`price`REAL DEFAULT 0,`old_price`REAL DEFAULT 0,`sku`TEXT,`image`TEXT,`gallery`TEXT,`category_id`INTEGER DEFAULT 0,`stock`INTEGER DEFAULT 0,`status`TEXT DEFAULT'active',`position`INTEGER DEFAULT 0,`created_at`TEXT DEFAULT(datetime('now','localtime')))",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_categories`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`title`TEXT NOT NULL,`slug`TEXT,`parent_id`INTEGER DEFAULT 0,`position`INTEGER DEFAULT 0)",
                "CREATE TABLE IF NOT EXISTS `{$p}shop_orders`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`order_number`TEXT,`customer_name`TEXT,`customer_email`TEXT,`customer_phone`TEXT,`customer_address`TEXT,`items`TEXT,`total`REAL DEFAULT 0,`status`TEXT DEFAULT'new',`payment_method`TEXT,`notes`TEXT,`created_at`TEXT DEFAULT(datetime('now','localtime')))",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_categories`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`title`TEXT NOT NULL,`slug`TEXT,`description`TEXT,`position`INTEGER DEFAULT 0)",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_topics`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`title`TEXT NOT NULL,`slug`TEXT,`category_id`INTEGER DEFAULT 0,`author_name`TEXT,`author_email`TEXT,`content`TEXT,`views`INTEGER DEFAULT 0,`replies_count`INTEGER DEFAULT 0,`is_pinned`INTEGER DEFAULT 0,`is_closed`INTEGER DEFAULT 0,`created_at`TEXT DEFAULT(datetime('now','localtime')),`last_reply_at`TEXT DEFAULT(datetime('now','localtime')))",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_posts`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`topic_id`INTEGER NOT NULL,`author_name`TEXT,`author_email`TEXT,`content`TEXT,`ip`TEXT,`status`TEXT DEFAULT'visible',`created_at`TEXT DEFAULT(datetime('now','localtime')))",
                "CREATE TABLE IF NOT EXISTS `{$p}forum_users`(`id`INTEGER PRIMARY KEY AUTOINCREMENT,`username`TEXT UNIQUE NOT NULL,`email`TEXT UNIQUE NOT NULL,`password`TEXT NOT NULL,`avatar`TEXT,`posts_count`INTEGER DEFAULT 0,`role`TEXT DEFAULT'user',`created_at`TEXT DEFAULT(datetime('now','localtime')))",
            ];
            foreach ($sqlite_module_tables as $mt) { try { $pdo->exec($mt); } catch (Exception $e) {} }
            $log[] = ['ok','SQLite: Module tables created (comments, shop, forum)'];

            $stmt = $pdo->prepare("INSERT OR IGNORE INTO `{$p}users`(`username`,`password`)VALUES(?,?)");
            $stmt->execute([$admin['user'], password_hash($admin['pass'], PASSWORD_DEFAULT)]);
            $log[] = ['ok','SQLite: Admin inserted'];
        } catch (PDOException $e) { $log[] = ['fail','SQLite: ' . $e->getMessage()]; $ok = false; }
    }

    // DB driver file
    if ($db['type'] !== 'json') {
        $driver = generateDriverCode();
        if (file_put_contents(__DIR__ . '/data/driver.php', $driver)) $log[] = ['ok','Created: data/driver.php'];
        else { $log[] = ['fail','Cannot write: data/driver.php']; $ok = false; }
    }

    // .htaccess — create a safe default (user can edit later in admin Settings)
    $default_htaccess = "# AICMS .htaccess\n# Created: " . date('Y-m-d H:i:s') . "\n# Safe to delete if issues. Edit in Admin → Settings → .htaccess\n\n<IfModule mod_deflate.c>\n    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json\n</IfModule>\n\n<IfModule mod_expires.c>\n    ExpiresActive On\n    ExpiresByType image/png \"access plus 1 year\"\n    ExpiresByType image/jpg \"access plus 1 year\"\n    ExpiresByType image/jpeg \"access plus 1 year\"\n    ExpiresByType image/gif \"access plus 1 year\"\n    ExpiresByType image/svg+xml \"access plus 1 year\"\n    ExpiresByType text/css \"access plus 1 month\"\n    ExpiresByType application/javascript \"access plus 1 month\"\n</IfModule>\n";
    if (!file_exists(__DIR__ . '/.htaccess')) {
        file_put_contents(__DIR__ . '/.htaccess', $default_htaccess);
        $log[] = ['ok','Created: .htaccess (safe default)'];
    }

    file_put_contents(__DIR__ . '/data/.installed', date('Y-m-d H:i:s'));
    $log[] = ['ok','Installation complete'];
    return ['ok' => $ok, 'log' => $log];
}

// ============== .HTACCESS GENERATOR ==============
function generateHtaccess($o = []) {
    $L = ["# AICMS .htaccess\n# " . date('Y-m-d H:i:s') . "\n# This file is safe to delete if it causes issues."];

    // Security: deny access to sensitive directories
    // Use <Directory> match instead of RewriteRule for better compatibility
    if (!empty($o['deny_data'])) {
        $L[] = "\n# Security: deny access to sensitive directories";
        $L[] = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^data/ - [F,L]\n    RewriteRule ^backup/ - [F,L]\n    RewriteRule ^logs/ - [F,L]\n</IfModule>";
    }

    // Hide JSON files — but ONLY in data/ directory, not globally
    if (!empty($o['hide_json'])) {
        $L[] = "\n# Hide JSON files in data/ directory";
        $L[] = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^data/.*\\.json$ - [F,L]\n</IfModule>";
    }

    if (!empty($o['cors'])) $L[] = "\n# CORS\n<IfModule mod_headers.c>\n    <FilesMatch \"\\.(js|css|png|jpg|jpeg|gif|ico|svg|webp|woff|woff2|ttf|eot)$\">\n        Header set Access-Control-Allow-Origin \"*\"\n    </FilesMatch>\n</IfModule>";
    if (!empty($o['gzip'])) $L[] = "\n# Gzip compression\n<IfModule mod_deflate.c>\n    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json application/xml application/xhtml+xml image/svg+xml\n</IfModule>";
    if (!empty($o['cache'])) $L[] = "\n# Browser caching\n<IfModule mod_expires.c>\n    ExpiresActive On\n    ExpiresByType text/css \"access plus 1 month\"\n    ExpiresByType application/javascript \"access plus 1 month\"\n    ExpiresByType image/png \"access plus 1 year\"\n    ExpiresByType image/jpg \"access plus 1 year\"\n    ExpiresByType image/jpeg \"access plus 1 year\"\n    ExpiresByType image/gif \"access plus 1 year\"\n    ExpiresByType image/svg+xml \"access plus 1 year\"\n    ExpiresByType image/webp \"access plus 1 year\"\n    ExpiresByType font/woff2 \"access plus 1 year\"\n</IfModule>";
    if (!empty($o['ssl_redirect'])) $L[] = "\n# SSL redirect\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTPS} off\n    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>";
    if (!empty($o['www_redirect'])) $L[] = "\n# WWW redirect\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\n    RewriteRule ^(.*)$ https://%1/$1 [R=301,L]\n</IfModule>";
    if (!empty($o['custom'])) $L[] = "\n# Custom Rules\n" . $o['custom'];
    return implode("\n", $L) . "\n";
}

// ============== DB DRIVER GENERATOR ==============
function generateDriverCode() {
    return <<<'D'
<?php
if (!class_exists('AicmsDB')) {
class AicmsDB {
    private static $pdo = null;
    private static $prefix = '';
    public static function init($cfg) {
        if (self::$pdo) return;
        self::$prefix = $cfg['prefix'] ?? 'aicms_';
        try {
            if ($cfg['driver'] === 'mysql') {
                self::$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            } elseif ($cfg['driver'] === 'sqlite') {
                $p = $cfg['path']; if (!str_starts_with($p,'/')) $p = dirname(__DIR__).'/'.$p;
                if (!is_dir(dirname($p))) mkdir(dirname($p),0755,true);
                self::$pdo = new PDO('sqlite:'.$p);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (PDOException $e) { error_log('AicmsDB: '.$e->getMessage()); self::$pdo = null; }
    }
    public static function pdo() { return self::$pdo; }
    public static function connected() { return self::$pdo !== null; }
    private static function t($n) { return self::$prefix . $n; }
    public static function all($table) { if(!self::$pdo)return[]; return self::$pdo->query("SELECT * FROM `".self::t($table)."`")->fetchAll(); }
    public static function find($table, $conds, $order=null) {
        if(!self::$pdo)return[]; $w=[]; $p=[];
        foreach($conds as $c=>$v){$w[]="`$c`=?";$p[]=$v;}
        $sql="SELECT * FROM `".self::t($table)."`"; if($w)$sql.=" WHERE ".implode(" AND ",$w); if($order)$sql.=" ORDER BY $order";
        $s=self::$pdo->prepare($sql);$s->execute($p);return $s->fetchAll();
    }
    public static function save($table, $data, $id=null) {
        if(!self::$pdo)return false; unset($data['id']);
        foreach($data as &$v) if(is_array($v))$v=json_encode($v);
        if($id){$sets=[];$params=[];foreach($data as $c=>$v){$sets[]="`$c`=?";$params[]=$v;}$params[]=$id;
            self::$pdo->prepare("UPDATE `".self::t($table)."` SET ".implode(",",$sets)." WHERE id=?")->execute($params);return $id;
        }else{$cols=array_keys($data);$ph=array_fill(0,count($cols),'?');
            self::$pdo->prepare("INSERT INTO `".self::t($table)."` (`".implode("`,`",$cols)."`) VALUES (".implode(",",$ph).")")->execute(array_values($data));
            return self::$pdo->lastInsertId();
        }
    }
    public static function delete($table,$id){if(!self::$pdo)return false;self::$pdo->prepare("DELETE FROM `".self::t($table)."` WHERE id=?")->execute([$id]);return true;}
    public static function count($table,$conds=[]){if(!self::$pdo)return 0;$sql="SELECT COUNT(*) FROM `".self::t($table)."`";$p=[];
        if($conds){$w=[];foreach($conds as $c=>$v){$w[]="`$c`=?";$p[]=$v;}$sql.=" WHERE ".implode(" AND ",$w);}
        return(int)self::$pdo->prepare($sql)->execute($p)->fetchColumn();}
}
}
D;
}

function getThemes() {
    $t = []; $d = __DIR__ . '/themes/';
    if (is_dir($d)) foreach (scandir($d) as $f) { if ($f[0]==='.'||!is_dir($d.$f)) continue; if (file_exists($d.$f.'/index.html')) $t[] = $f; }
    if (empty($t)) $t = ['helios','modern','default'];
    sort($t); return $t;
}

// ============== RENDER ==============
$themes = getThemes();
$reqResults = checkRequirements();
$hasFatal = !empty(array_filter($reqResults, fn($r) => $r['status'] === 'fail'));
$s = $_SESSION['install_site'] ?? ['title'=>'AICMS','desc'=>'','keywords'=>'','theme'=>'helios','lang'=>'ru'];
$db = $_SESSION['install_db'] ?? ['type'=>'json','host'=>'localhost','port'=>'3306','name'=>'aicms','user'=>'','pass'=>'','prefix'=>'aicms_','path'=>'data/aicms.db'];
$ht = $_SESSION['install_htaccess'] ?? ['deny_data'=>true,'hide_json'=>true,'cors'=>false,'gzip'=>true,'cache'=>true,'ssl_redirect'=>false,'www_redirect'=>false,'custom'=>''];
$ad = $_SESSION['install_admin'] ?? ['user'=>'admin','pass'=>''];
$adErr = $_SESSION['install_admin_errors'] ?? []; unset($_SESSION['install_admin_errors']);
function esc($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function ck($v, $k) { return !empty($v[$k]) ? 'checked' : ''; }
function sel($v, $k, $c) { return ($v[$k] ?? '') === $c ? 'selected' : ''; }
function radSel($type, $v) { return $v === $type ? 'selected' : ''; }
?>
<!DOCTYPE html><html lang="<?=esc($lang)?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>AICMS — <?=esc($t['welcome_title'])?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--p:#4f46e5;--pd:#4338ca;--pl:#818cf8;--bg:#f1f5f9;--c:#fff;--tx:#1e293b;--m:#64748b;--bd:#e2e8f0;--ok:#22c55e;--err:#ef4444;--wrn:#f59e0b;--r:12px;--sh:0 1px 3px rgba(0,0,0,.1)}
body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;display:flex;flex-direction:column;align-items:center}
.I{width:100%;max-width:820px;padding:24px 16px}
.H{background:linear-gradient(135deg,var(--p),#7c3aed);color:#fff;border-radius:var(--r);padding:28px 32px;margin-bottom:24px;box-shadow:0 4px 24px rgba(79,70,229,.25)}
.H h1{font-size:24px;font-weight:700;margin-bottom:4px}.H p{font-size:14px;opacity:.85}
.H form{display:inline-flex;gap:6px;margin-top:12px}
.H button{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:4px 14px;border-radius:20px;cursor:pointer;font-size:13px;transition:.15s}
.H button:hover,.H button.on{background:rgba(255,255,255,.3)}
.S{display:flex;gap:4px;margin-bottom:24px;background:var(--c);border-radius:var(--r);padding:6px;box-shadow:var(--sh)}
.Si{flex:1;text-align:center;padding:10px 4px;border-radius:8px;font-size:11px;color:var(--m);transition:.2s;font-weight:500}
.Sn{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--bd);color:var(--m);font-size:12px;font-weight:600;margin-bottom:4px;transition:.2s}
.Si.a{background:var(--p);color:#fff}.Si.a .Sn{background:#fff;color:var(--p)}
.Si.d .Sn{background:var(--ok);color:#fff}.Si.d{color:var(--ok)}
.C{background:var(--c);border-radius:var(--r);padding:28px 32px;box-shadow:var(--sh);margin-bottom:20px;animation:fi .25s ease-out}
@keyframes fi{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.C h2{font-size:20px;font-weight:600;margin-bottom:4px}.C .ds{color:var(--m);font-size:14px;margin-bottom:20px}
.C h3{font-size:15px;font-weight:600;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--bd)}
.F{margin-bottom:16px}.F label{display:block;font-size:13px;font-weight:500;margin-bottom:5px}
.F input,.F select,.F textarea{width:100%;padding:9px 12px;border:1px solid var(--bd);border-radius:8px;font-size:14px;color:var(--tx);background:#fff;transition:border .15s;font-family:inherit}
.F input:focus,.F select:focus,.F textarea:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(79,70,229,.12)}
.F textarea{min-height:140px;font-family:'Courier New',monospace;font-size:13px;line-height:1.5;resize:vertical}
.F .hi{font-size:12px;color:var(--m);margin-top:3px}
.FR{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.RC{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.Rc{border:2px solid var(--bd);border-radius:10px;padding:16px;cursor:pointer;transition:.2s;text-align:center}
.Rc:hover{border-color:var(--pl)}.Rc.s{border-color:var(--p);background:rgba(79,70,229,.04)}
.Rc input{display:none}.Rc .ri{font-size:28px;margin-bottom:8px}.Rc .rt{font-size:14px;font-weight:600;margin-bottom:4px}.Rc .rd{font-size:11px;color:var(--m);line-height:1.4}
.Tg{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.T{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border:1px solid var(--bd);border-radius:8px;font-size:13px}
.T input{display:none}.T .sl{width:38px;height:20px;background:#cbd5e1;border-radius:10px;position:relative;transition:.2s;flex-shrink:0}
.T .sl::after{content:'';width:16px;height:16px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.T input:checked+.sl{background:var(--p)}.T input:checked+.sl::after{left:20px}
.CL{display:flex;flex-direction:column;gap:6px}
.CI{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:8px;font-size:13px}
.CI.ok{background:#f0fdf4;color:#166534}.CI.fail{background:#fef2f2;color:#991b1b}.CI.warn{background:#fffbeb;color:#92400e}
.CI .b{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px}
.CI.ok .b{background:#bbf7d0}.CI.fail .b{background:#fecaca}.CI.warn .b{background:#fef08a}
.N{display:flex;justify-content:space-between;gap:12px;margin-top:20px}
.B{display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:.15s;font-family:inherit}
.Bp{background:var(--p);color:#fff}.Bp:hover{background:var(--pd)}
.Bs{background:var(--bd);color:var(--tx)}.Bs:hover{background:#d1d5db}
.B:disabled{opacity:.5;cursor:not-allowed}.Bsm{padding:6px 14px;font-size:12px}
.IP{margin:20px 0}.PI{display:flex;align-items:flex-start;gap:10px;padding:6px 0;font-size:13px}
.PI .ic{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;margin-top:1px}
.PI.ok .ic{background:#bbf7d0;color:#166534}.PI.fail .ic{background:#fecaca;color:#991b1b}.PI.pend .ic{background:#f1f5f9;color:#94a3b8}
.SC{text-align:center;padding:40px 20px}.SC .si{font-size:56px;margin-bottom:16px}.SC h2{font-size:22px;margin-bottom:8px}
.SC p{color:var(--m);font-size:14px;margin-bottom:24px}
.HP{background:#1e293b;color:#e2e8f0;border-radius:8px;padding:16px;font-family:'Courier New',monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:300px;overflow-y:auto;margin-top:12px}
.ER{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px}
.ER li{font-size:13px;color:#991b1b;margin-bottom:2px}
.DT{margin-top:8px;padding:8px 12px;border-radius:6px;font-size:12px;display:none}
.DT.ok{display:block;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.DT.fail{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.hd{display:none !important}
@media(max-width:640px){.RC{grid-template-columns:1fr}.Tg{grid-template-columns:1fr}.FR{grid-template-columns:1fr}.S{flex-wrap:wrap}.Si{flex:0 0 25%;font-size:10px}.C{padding:20px 16px}.H{padding:20px}}
</style></head><body><div class="I">

<div class="H">
<h1>AICMS</h1>
<p><?=esc($t['welcome_desc'])?></p>
<form method="POST"><input type="hidden" name="action" value="set_lang">
<button type="submit" name="installer_lang" value="ru" class="<?=$lang==='ru'?'on':''?>">Русский</button>
<button type="submit" name="installer_lang" value="en" class="<?=$lang==='en'?'on':''?>">English</button>
</form>
</div>

<div class="S">
<?php foreach($steps as $i=>$si):?>
<div class="Si <?=$si===$currentStep?'a':''?> <?=array_search($si,$steps)<array_search($currentStep,$steps)?'d':''?>">
<div class="Sn"><?=array_search($si,$steps)<array_search($currentStep,$steps)?'✓':($i+1)?></div>
<div><?=esc($t['step_'.$si])?></div>
</div>
<?php endforeach;?>
</div>

<?php if($currentStep==='welcome'):?>
<div class="C">
<h2><?=esc($t['welcome_title'])?></h2><p class="ds"><?=esc($t['welcome_desc'])?></p>
<div style="background:#f8fafc;border-radius:10px;padding:20px;margin-bottom:16px">
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center">
<div><div style="font-size:28px;margin-bottom:6px">📄</div><div style="font-size:13px;font-weight:600">JSON</div><div style="font-size:11px;color:var(--m)">Файловое хранилище</div></div>
<div><div style="font-size:28px;margin-bottom:6px">🗄️</div><div style="font-size:13px;font-weight:600">MySQL</div><div style="font-size:11px;color:var(--m)">Сервер баз данных</div></div>
<div><div style="font-size:28px;margin-bottom:6px">💾</div><div style="font-size:13px;font-weight:600">SQLite</div><div style="font-size:11px;color:var(--m)">Embedded БД</div></div>
</div></div>
</div>
<div class="N"><div></div><form method="POST"><input type="hidden" name="action" value="go_step"><input type="hidden" name="target" value="check"><button type="submit" class="B Bp"><?=esc($t['next'])?> →</button></form></div>
<?php endif;?>

<?php if($currentStep==='check'):?>
<div class="C">
<h2><?=esc($t['req_title'])?></h2><p class="ds"><?=$lang==='ru'?'Проверка сервера':'Checking server requirements'?></p>
<div class="CL"><?php foreach($reqResults as $r):?>
<div class="CI <?=$r['status']?>"><span><?=esc($r['label'])?></span><span class="b"><?=esc($r['value'])?></span></div>
<?php endforeach;?></div>
<?php if($hasFatal):?><p style="color:var(--err);font-size:13px;margin-top:12px"><?=$lang==='ru'?'Критические ошибки. Устраните их.':'Critical errors. Fix them.'?></p><?php endif;?>
</div>
<div class="N">
<form method="POST"><input type="hidden" name="action" value="go_step"><input type="hidden" name="target" value="welcome"><button type="submit" class="B Bs">← <?=esc($t['back'])?></button></form>
<?php if(!$hasFatal):?><form method="POST"><input type="hidden" name="action" value="go_step"><input type="hidden" name="target" value="db"><button type="submit" class="B Bp"><?=esc($t['next'])?> →</button></form><?php else:?><button class="B Bp" disabled><?=esc($t['next'])?> →</button><?php endif;?>
</div>
<?php endif;?>

<?php if($currentStep==='db'):?>
<form method="POST" id="dbF"><input type="hidden" name="action" value="save_step"><input type="hidden" name="step" value="db">
<div class="C">
<h2><?=esc($t['db_title'])?></h2><p class="ds"><?=esc($t['db_choose'])?></p>
<div class="RC" id="dbCards">
<label class="Rc <?=$db['type']==='json'?'s':''?>"><input type="radio" name="db_type" value="json" <?=$db['type']==='json'?'checked':''?>><div class="ri">📄</div><div class="rt">JSON</div><div class="rd"><?=esc($t['db_json_desc'])?></div></label>
<label class="Rc <?=$db['type']==='mysql'?'s':''?>"><input type="radio" name="db_type" value="mysql" <?=$db['type']==='mysql'?'checked':''?>><div class="ri">🗄️</div><div class="rt">MySQL</div><div class="rd"><?=esc($t['db_mysql_desc'])?></div></label>
<label class="Rc <?=$db['type']==='sqlite'?'s':''?>"><input type="radio" name="db_type" value="sqlite" <?=$db['type']==='sqlite'?'checked':''?>><div class="ri">💾</div><div class="rt">SQLite</div><div class="rd"><?=esc($t['db_sqlite_desc'])?></div></label>
</div>
<div id="mF" class="<?=$db['type']!=='mysql'?'hd':''?>"><h3>MySQL / MariaDB</h3>
<div class="FR"><div class="F"><label><?=esc($t['db_host'])?></label><input type="text" name="db_host" value="<?=esc($db['host'])?>"></div><div class="F"><label><?=esc($t['db_port'])?></label><input type="text" name="db_port" value="<?=esc($db['port'])?>"></div></div>
<div class="F"><label><?=esc($t['db_name'])?></label><input type="text" name="db_name" value="<?=esc($db['name'])?>"><div class="hi"><?=$lang==='ru'?'Создаётся автоматически':'Created automatically'?></div></div>
<div class="FR"><div class="F"><label><?=esc($t['db_user'])?></label><input type="text" name="db_user" value="<?=esc($db['user'])?>"></div><div class="F"><label><?=esc($t['db_pass'])?></label><input type="password" name="db_pass" value="<?=esc($db['pass'])?>"></div></div>
<div class="F"><label><?=esc($t['db_prefix'])?></label><input type="text" name="db_prefix" value="<?=esc($db['prefix'])?>"></div>
<button type="button" class="B Bs Bsm" id="btM" style="margin-top:4px">🔍 <?=esc($t['db_test'])?></button><div id="trM" class="DT"></div>
</div>
<div id="sF" class="<?=$db['type']!=='sqlite'?'hd':''?>"><h3>SQLite</h3>
<div class="F"><label><?=esc($t['db_path'])?></label><input type="text" name="db_path" value="<?=esc($db['path'])?>"><div class="hi"><?=$lang==='ru'?'Относительно корня сайта':'Relative to site root'?></div></div>
<div class="F"><label><?=esc($t['db_prefix'])?></label><input type="text" name="db_prefix" value="<?=esc($db['prefix'])?>"></div>
<button type="button" class="B Bs Bsm" id="btS" style="margin-top:4px">🔍 <?=esc($t['db_test'])?></button><div id="trS" class="DT"></div>
</div>
<div id="jI" class="<?=$db['type']!=='json'?'hd':''?>"><h3>JSON</h3><p style="font-size:13px;color:var(--m)"><?=$lang==='ru'?'Данные в файлах JSON. Настройка не требуется.':'Data in JSON files. No configuration needed.'?></p></div>
</div>
<div class="N">
    <a href="install.php?action=go_back&from=db" class="B Bs" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;background:var(--bd);color:var(--tx)">← <?=esc($t['back'])?></a>
    <button type="submit" class="B Bp"><?=esc($t['next'])?> →</button>
</div>
</form>
<?php endif;?>

<?php if($currentStep==='htaccess'):?>
<form method="POST" id="htF"><input type="hidden" name="action" value="save_step"><input type="hidden" name="step" value="htaccess">
<div class="C">
<h2><?=esc($t['htaccess_title'])?></h2><p class="ds"><?=esc($t['htaccess_desc'])?></p>
<h3>🔒 <?=esc($t['htaccess_security'])?></h3>
<div class="Tg">
<label class="T"><span><?=esc($t['htaccess_deny_data'])?></span><input type="checkbox" name="htaccess_deny_data" value="1" <?=ck($ht,'deny_data')?> onchange="uP()"><span class="sl"></span></label>
<label class="T"><span><?=esc($t['htaccess_hide_json'])?></span><input type="checkbox" name="htaccess_hide_json" value="1" <?=ck($ht,'hide_json')?> onchange="uP()"><span class="sl"></span></label>
</div>
<h3>⚡ <?=esc($t['htaccess_perf'])?></h3>
<div class="Tg">
<label class="T"><span><?=esc($t['htaccess_cors'])?></span><input type="checkbox" name="htaccess_cors" value="1" <?=ck($ht,'cors')?> onchange="uP()"><span class="sl"></span></label>
<label class="T"><span><?=esc($t['htaccess_gzip'])?></span><input type="checkbox" name="htaccess_gzip" value="1" <?=ck($ht,'gzip')?> onchange="uP()"><span class="sl"></span></label>
<label class="T"><span><?=esc($t['htaccess_cache'])?></span><input type="checkbox" name="htaccess_cache" value="1" <?=ck($ht,'cache')?> onchange="uP()"><span class="sl"></span></label>
</div>
<h3>🔐 <?=esc($t['htaccess_ssl'])?></h3>
<div class="Tg">
<label class="T"><span><?=esc($t['htaccess_ssl_redirect'])?></span><input type="checkbox" name="htaccess_ssl_redirect" value="1" <?=ck($ht,'ssl_redirect')?> onchange="uP()"><span class="sl"></span></label>
<label class="T"><span><?=esc($t['htaccess_www'])?></span><input type="checkbox" name="htaccess_www" value="1" <?=ck($ht,'www_redirect')?> onchange="uP()"><span class="sl"></span></label>
</div>
<h3>✏️ <?=esc($t['htaccess_custom'])?></h3>
<div class="F"><textarea name="htaccess_custom" id="htC" onchange="uP()" placeholder="<?=esc($lang==='ru'?'Пользовательские правила...':'Custom rules...')?>"><?=esc($ht['custom'])?></textarea></div>
<h3>👁️ <?=esc($t['htaccess_preview'])?></h3><div class="HP" id="htP"></div>
</div>
<div class="N">
    <a href="install.php?action=go_back&from=db" class="B Bs" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;background:var(--bd);color:var(--tx)">← <?=esc($t['back'])?></a>
    <button type="submit" class="B Bp"><?=esc($t['next'])?> →</button>
</div>
</form>
<?php endif;?>

<?php if($currentStep==='site'):?>
<form method="POST"><input type="hidden" name="action" value="save_step"><input type="hidden" name="step" value="site">
<div class="C">
<h2><?=esc($t['site_title'])?></h2><p class="ds"><?=$lang==='ru'?'Основные настройки':'Basic settings'?></p>
<div class="F"><label><?=esc($t['site_name'])?></label><input type="text" name="site_name" value="<?=esc($s['title'])?>" required></div>
<div class="F"><label><?=esc($t['site_desc'])?></label><input type="text" name="site_desc" value="<?=esc($s['desc'])?>"></div>
<div class="F"><label><?=esc($t['site_keywords'])?></label><input type="text" name="site_keywords" value="<?=esc($s['keywords'])?>"></div>
<div class="FR">
<div class="F"><label><?=esc($t['site_theme'])?></label><select name="site_theme"><?php foreach($themes as $th):?><option value="<?=esc($th)?>" <?=sel($s,'theme',$th)?>><?=esc($th)?></option><?php endforeach;?></select></div>
<div class="F"><label><?=esc($t['site_lang'])?></label><select name="site_lang"><option value="ru" <?=sel($s,'lang','ru')?>>Русский</option><option value="en" <?=sel($s,'lang','en')?>>English</option></select></div>
</div></div>
<div class="N">
    <a href="install.php?action=go_back&from=db" class="B Bs" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;background:var(--bd);color:var(--tx)">← <?=esc($t['back'])?></a>
    <button type="submit" class="B Bp"><?=esc($t['next'])?> →</button>
</div>
</form>
<?php endif;?>

<?php if($currentStep==='admin'):?>
<form method="POST" id="aF"><input type="hidden" name="action" value="save_step"><input type="hidden" name="step" value="admin">
<div class="C">
<h2><?=esc($t['admin_title'])?></h2><p class="ds"><?=$lang==='ru'?'Создайте администратора':'Create administrator account'?></p>
<?php if(!empty($adErr)):?><ul class="ER"><?php foreach($adErr as $e):?><li><?=esc($e)?></li><?php endforeach;?></ul><?php endif;?>
<div class="F"><label><?=esc($t['admin_user'])?></label><input type="text" name="admin_user" value="<?=esc($ad['user'])?>" required minlength="3" autocomplete="username"></div>
<div class="FR">
<div class="F"><label><?=esc($t['admin_pass'])?></label><input type="password" name="admin_pass" required minlength="6" autocomplete="new-password"></div>
<div class="F"><label><?=esc($t['admin_pass2'])?></label><input type="password" name="admin_pass2" required minlength="6" autocomplete="new-password"></div>
</div></div>
<div class="N">
    <a href="install.php?action=go_back&from=site" class="B Bs" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;background:var(--bd);color:var(--tx)">← <?=esc($t['back'])?></a>
    <button type="submit" class="B Bp"><?=esc($t['install'])?> 🚀</button>
</div>
</form>
<?php endif;?>

<?php if($currentStep==='install'):?>
<div class="C"><h2><?=esc($t['installing'])?></h2><p class="ds"><?=$lang==='ru'?'Выполняется...':'Installing...'?></p><div class="IP" id="iPr"></div></div>
<?php endif;?>

<?php if($currentStep==='done'):?>
<div class="C"><div class="SC">
<div class="si">🎉</div><h2><?=esc($t['install_done'])?></h2>
<p><?=esc($t['install_remove'])?></p>
<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
<a href="admin/" class="B Bp"><?=esc($t['finish'])?></a>
<a href="index.php" class="B Bs"><?=$lang==='ru'?'Открыть сайт':'Open Website'?></a>
</div>
<p style="margin-top:20px;font-size:12px;color:var(--m)"></p>
<form method="POST" style="display:inline-block"><input type="hidden" name="action" value="remove_installer"><button type="submit" class="B Bsm" style="background:var(--err);color:#fff">🗑️ <?=esc($t['install_remove'])?></button></form>
</div></div>
<?php endif;?>

</div>
<script>
document.querySelectorAll('.Rc').forEach(c=>{c.addEventListener('click',()=>{document.querySelectorAll('.Rc').forEach(x=>x.classList.remove('s'));c.classList.add('s');c.querySelector('input').checked=true;
const v=c.querySelector('input').value;
document.getElementById('mF').classList.toggle('hd',v!=='mysql');document.getElementById('sF').classList.toggle('hd',v!=='sqlite');document.getElementById('jI').classList.toggle('hd',v!=='json');})});

document.getElementById('btM')?.addEventListener('click',async()=>{const r=document.getElementById('trM');r.className='DT';r.style.display='block';r.textContent='Testing...';const f=new FormData();f.append('action','test_db');f.append('db_type','mysql');f.append('db_host',document.querySelector('[name=db_host]').value);f.append('db_port',document.querySelector('[name=db_port]').value);f.append('db_name',document.querySelector('[name=db_name]').value);f.append('db_user',document.querySelector('[name=db_user]').value);f.append('db_pass',document.querySelector('[name=db_pass]').value);
try{const j=await(await fetch('install.php',{method:'POST',body:f})).json();r.className='DT '+(j.ok?'ok':'fail');r.textContent=j.msg;}catch(e){r.className='DT fail';r.textContent=e.message;}});

document.getElementById('btS')?.addEventListener('click',async()=>{const r=document.getElementById('trS');r.className='DT';r.style.display='block';r.textContent='Testing...';const f=new FormData();f.append('action','test_db');f.append('db_type','sqlite');f.append('db_path',document.querySelector('#sF [name=db_path]')?.value||'data/aicms.db');
try{const j=await(await fetch('install.php',{method:'POST',body:f})).json();r.className='DT '+(j.ok?'ok':'fail');r.textContent=j.msg;}catch(e){r.className='DT fail';r.textContent=e.message;}});

function uP(){const f=document.getElementById('htF');if(!f)return;
const o={deny_data:f.htaccess_deny_data?.checked,hide_json:f.htaccess_hide_json?.checked,cors:f.htaccess_cors?.checked,gzip:f.htaccess_gzip?.checked,cache:f.htaccess_cache?.checked,ssl_redirect:f.htaccess_ssl_redirect?.checked,www_redirect:f.htaccess_www?.checked,custom:f.htaccess_custom?.value||''};
const L=['# AICMS .htaccess','# '+new Date().toISOString().slice(0,19),''];
if(o.deny_data)L.push('\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^data/ - [F,L]\n    RewriteRule ^backup/ - [F,L]\n    RewriteRule ^logs/ - [F,L]\n</IfModule>');
if(o.hide_json)L.push('\n<FilesMatch "\\.json$">\n    <IfModule mod_authz_core.c>Require all denied</IfModule>\n    <IfModule !mod_authz_core.c>Order deny,allow\nDeny from all</IfModule>\n</FilesMatch>');
if(o.cors)L.push('\n<IfModule mod_headers.c>\n    <FilesMatch "\\.(js|css|json|png|jpg|jpeg|gif|ico|svg|webp|woff|woff2|ttf|eot)$">\n        Header set Access-Control-Allow-Origin "*"\n    </FilesMatch>\n</IfModule>');
if(o.gzip)L.push('\n<IfModule mod_deflate.c>\n    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json\n</IfModule>');
if(o.cache)L.push('\n<IfModule mod_expires.c>\n    ExpiresActive On\n    ExpiresByType text/css "access plus 1 month"\n    ExpiresByType application/javascript "access plus 1 month"\n    ExpiresByType image/png "access plus 1 year"\n    ExpiresByType image/jpg "access plus 1 year"\n    ExpiresByType image/jpeg "access plus 1 year"\n    ExpiresByType image/gif "access plus 1 year"\n    ExpiresByType image/svg+xml "access plus 1 year"\n    ExpiresByType font/woff2 "access plus 1 year"\n</IfModule>');
if(o.ssl_redirect)L.push('\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTPS} off\n    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>');
if(o.www_redirect)L.push('\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\n    RewriteRule ^(.*)$ https://%1/$1 [R=301,L]\n</IfModule>');
if(o.custom)L.push('\n# Custom\n'+o.custom);
document.getElementById('htP').textContent=L.join('\n');}
if(document.getElementById('htF'))uP();

if(document.getElementById('iPr')){(async()=>{
const pr=document.getElementById('iPr');try{const fd=new FormData();fd.append('action','do_install');const j=await(await fetch('install.php',{method:'POST',body:fd})).json();
j.log.forEach((item,i)=>{setTimeout(()=>{const d=document.createElement('div');d.className='PI '+item[0];d.innerHTML='<span class="ic">'+(item[0]==='ok'?'✓':'✗')+'</span><span>'+item[1]+'</span>';pr.appendChild(d);},i*120);});
setTimeout(()=>{if(j.ok)window.location.href='install.php?step=done&force=1';else pr.innerHTML+='<p style="color:var(--err);margin-top:12px"><?=$lang==='ru'?'Ошибки. Проверьте права.':'Errors. Check permissions.'?></p>';},j.log.length*120+200);
}catch(e){pr.innerHTML='<p style="color:var(--err)">'+e.message+'</p>';}})();}
</script></body></html>