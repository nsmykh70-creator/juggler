<?php
/**
 * AICMS Database Abstraction Layer
 * =================================
 * Provides a simple PDO-based DB interface supporting MySQL and SQLite.
 * Used by interactive modules: comments, shop, forum.
 *
 * The DB config is stored in data/config.json under the "storage" key.
 * If no DB is configured (JSON-only mode), db() returns null and modules
 * gracefully degrade (show "DB not configured" message).
 *
 * Usage:
 *   $db = db();                     // Get PDO instance or null
 *   if ($db) { ... }                // Check if DB is available
 *   db_table('comments');           // Get prefixed table name
 *   db_query('SELECT * FROM ...');  // Run a query
 *   db_insert('comments', $data);   // Insert a row
 *   db_update('comments', $data, ['id'=>1]); // Update
 *   db_delete('comments', 1);       // Delete by ID
 *   db_select('comments', '*', 'WHERE news_id=?', [5]); // Select
 *   db_row('comments', '*', 'WHERE id=?', [1]); // Single row
 *   db_count('comments', 'WHERE status=?', ['approved']); // Count
 */

// Last connection error (for user-facing diagnostics)
$GLOBALS['_aicms_db_error'] = null;

// Overlay environment secrets (data/.env.php) on top of config.json
require_once __DIR__ . '/env.php';

/**
 * Initialize and return the PDO connection.
 * Returns null if no DB is configured or connection fails.
 */
function db() {
    global $_aicms_db;
    // If we already have a successful connection, reuse it
    if ($_aicms_db instanceof PDO) return $_aicms_db;
    // If a previous attempt failed AND config hasn't changed, don't retry every call
    if ($_aicms_db === false) return null;

    $config_file = __DIR__ . '/../../data/config.json';
    if (!file_exists($config_file)) return null;
    $config = json_decode(file_get_contents($config_file), true);
    if (!is_array($config)) return null;
    $config = aicms_apply_env($config); // env secrets override storage.*
    $storage = $config['storage'] ?? null;
    if (!$storage || ($storage['driver'] ?? 'json') === 'json') return null;

    global $_aicms_db_prefix, $_aicms_db_driver;
    $_aicms_db_prefix = $storage['prefix'] ?? 'aicms_';
    $_aicms_db_driver = $storage['driver'];

    try {
        if ($storage['driver'] === 'mysql') {
            if (!extension_loaded('pdo_mysql')) {
                throw new PDOException('Расширение PHP pdo_mysql не установлено. Обратитесь к хостинг-провайдеру или установите его: apt install php-mysql / yum install php-mysqlnd');
            }
            $host = $storage['host'] ?? 'localhost';
            $port = $storage['port'] ?? 3306;
            $dbname = $storage['database'] ?? '';
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $_aicms_db = new PDO($dsn, $storage['username'] ?? '', $storage['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } elseif ($storage['driver'] === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new PDOException('Расширение PHP pdo_sqlite не установлено.');
            }
            $path = $storage['path'] ?? 'data/aicms.db';
            if (!str_starts_with($path, '/')) $path = __DIR__ . '/../../' . $path;
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
            $_aicms_db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $_aicms_db->exec('PRAGMA journal_mode=WAL');
            $_aicms_db->exec('PRAGMA foreign_keys=ON');
        } else {
            $_aicms_db = false;
            return null;
        }
        // Auto-create module tables if they don't exist
        db_auto_create_tables();
    } catch (PDOException $e) {
        error_log('AICMS DB Error: ' . $e->getMessage());
        $GLOBALS['_aicms_db_error'] = $e->getMessage();
        $_aicms_db = false;
        return null;
    }
    return $_aicms_db;
}

/**
 * Get the last DB connection error message (for user-facing diagnostics).
 */
function db_last_error() {
    return $GLOBALS['_aicms_db_error'] ?? null;
}

/**
 * Force a fresh connection attempt (used by settings.php after config change).
 */
function db_reset() {
    global $_aicms_db, $_aicms_db_prefix, $_aicms_db_driver;
    $_aicms_db = null;
    $_aicms_db_prefix = '';
    $_aicms_db_driver = '';
    $GLOBALS['_aicms_db_error'] = null;
}

/**
 * Get the prefixed table name.
 */
function db_table($name) {
    global $_aicms_db_prefix;
    return $_aicms_db_prefix . $name;
}

/**
 * Check if DB is available.
 */
function db_available() {
    return db() !== null;
}

/**
 * Get the DB driver type ('mysql', 'sqlite', or 'json').
 */
function db_driver() {
    global $_aicms_db_driver;
    return $_aicms_db_driver;
}

/**
 * Run a raw SQL query with optional params.
 */
function db_query($sql, $params = []) {
    $pdo = db();
    if (!$pdo) return [];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Run a query and return the first row.
 */
function db_row($sql, $params = []) {
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * Run a query and return a single value.
 */
function db_one($sql, $params = []) {
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Insert a row into a table.
 * Returns the new row ID or false on failure.
 */
function db_insert($table, $data) {
    $pdo = db();
    if (!$pdo) return false;
    $cols = array_keys($data);
    $placeholders = array_map(fn($c) => ":$c", $cols);
    $sql = 'INSERT INTO ' . db_table($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_combine(array_map(fn($c) => ":$c", $cols), array_values($data)));
    return $pdo->lastInsertId();
}

/**
 * Update rows in a table.
 * $where is an associative array of column => value.
 */
function db_update($table, $data, $where) {
    $pdo = db();
    if (!$pdo) return false;
    $set = array_map(fn($c) => "$c = :set_$c", array_keys($data));
    $wclause = array_map(fn($c) => "$c = :w_$c", array_keys($where));
    $sql = 'UPDATE ' . db_table($table) . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $wclause);
    $params = [];
    foreach ($data as $k => $v) $params[":set_$k"] = $v;
    foreach ($where as $k => $v) $params[":w_$k"] = $v;
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Delete rows from a table by ID.
 */
function db_delete($table, $id) {
    $pdo = db();
    if (!$pdo) return false;
    $stmt = $pdo->prepare('DELETE FROM ' . db_table($table) . ' WHERE id = ?');
    return $stmt->execute([$id]);
}

/**
 * Select rows from a table.
 * $columns: '*' or 'col1, col2'
 * $where: SQL fragment after WHERE (without the keyword), e.g. 'status = ? AND news_id = ?'
 * $params: array of bound values
 * $order: ORDER BY clause, e.g. 'created_at DESC'
 * $limit: max rows
 * $offset: skip rows
 */
function db_select($table, $columns = '*', $where = '', $params = [], $order = '', $limit = 0, $offset = 0) {
    $pdo = db();
    if (!$pdo) return [];
    $sql = 'SELECT ' . $columns . ' FROM ' . db_table($table);
    if ($where) $sql .= ' WHERE ' . $where;
    if ($order) $sql .= ' ORDER BY ' . $order;
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    if ($offset > 0) $sql .= ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Count rows in a table.
 */
function db_count($table, $where = '', $params = []) {
    $pdo = db();
    if (!$pdo) return 0;
    $sql = 'SELECT COUNT(*) FROM ' . db_table($table);
    if ($where) $sql .= ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Get a single row by ID.
 */
function db_find($table, $id) {
    $pdo = db();
    if (!$pdo) return null;
    $stmt = $pdo->prepare('SELECT * FROM ' . db_table($table) . ' WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Auto-create tables for interactive modules if they don't exist.
 * Called once on first db() connection.
 */
function db_auto_create_tables() {
    $pdo = db();
    if (!$pdo) return;
    $driver = db_driver();
    $is_mysql = $driver === 'mysql';

    // Helper to handle MySQL vs SQLite type differences
    $ai = $is_mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text_type = $is_mysql ? 'LONGTEXT' : 'TEXT';
    $enum = function($values) use ($is_mysql) {
        return $is_mysql ? "ENUM('" . implode("','", $values) . "')" : 'TEXT';
    };
    $datetime_default = $is_mysql ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "TEXT DEFAULT (datetime('now','localtime'))";
    $date_type = $is_mysql ? 'DATE' : 'TEXT';
    $decimal = $is_mysql ? 'DECIMAL(10,2)' : 'REAL';
    $varchar = function($n) { return "VARCHAR($n)"; };

    $tables = [
        // ===== COMMENTS + RATINGS =====
        'comments' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            target_type {$varchar(20)} DEFAULT 'news',
            target_id INT NOT NULL,
            lang {$varchar(5)} DEFAULT 'ru',
            parent_id INT DEFAULT 0,
            author_name {$varchar(100)},
            author_email {$varchar(200)},
            user_id INT DEFAULT 0,
            content {$text_type},
            rating INT DEFAULT 0,
            ip {$varchar(45)},
            status {$enum(['pending','approved','spam','deleted'])} DEFAULT 'pending',
            created_at {$datetime_default}
        )",

        // ===== SHOP: PRODUCTS =====
        'shop_products' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(300)} NOT NULL,
            slug {$varchar(300)},
            description {$text_type},
            short_desc {$varchar(500)},
            price {$decimal} DEFAULT 0,
            old_price {$decimal} DEFAULT 0,
            sku {$varchar(100)},
            image {$varchar(500)},
            gallery {$text_type},
            category_id INT DEFAULT 0,
            stock INT DEFAULT 0,
            status {$enum(['active','hidden'])} DEFAULT 'active',
            position INT DEFAULT 0,
            created_at {$datetime_default}
        )",

        // ===== SHOP: CATEGORIES =====
        'shop_categories' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(200)} NOT NULL,
            slug {$varchar(200)},
            parent_id INT DEFAULT 0,
            position INT DEFAULT 0
        )",

        // ===== SHOP: ORDERS =====
        'shop_orders' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            order_number {$varchar(50)},
            customer_name {$varchar(200)},
            customer_email {$varchar(200)},
            customer_phone {$varchar(50)},
            customer_address {$text_type},
            items {$text_type},
            total {$decimal} DEFAULT 0,
            status {$enum(['new','paid','shipped','done','cancelled'])} DEFAULT 'new',
            payment_method {$varchar(50)},
            notes {$text_type},
            created_at {$datetime_default}
        )",

        // ===== FORUM: CATEGORIES =====
        'forum_categories' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(200)} NOT NULL,
            slug {$varchar(200)},
            description {$text_type},
            position INT DEFAULT 0
        )",

        // ===== FORUM: TOPICS =====
        'forum_topics' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(300)} NOT NULL,
            slug {$varchar(300)},
            category_id INT DEFAULT 0,
            author_name {$varchar(100)},
            author_email {$varchar(200)},
            content {$text_type},
            views INT DEFAULT 0,
            replies_count INT DEFAULT 0,
            is_pinned INT DEFAULT 0,
            is_closed INT DEFAULT 0,
            created_at {$datetime_default},
            last_reply_at {$datetime_default}
        )",

        // ===== FORUM: POSTS =====
        'forum_posts' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            topic_id INT NOT NULL,
            author_name {$varchar(100)},
            author_email {$varchar(200)},
            content {$text_type},
            ip {$varchar(45)},
            status {$enum(['visible','hidden'])} DEFAULT 'visible',
            created_at {$datetime_default}
        )",

        // ===== FORUM USERS (for registration) =====
        'forum_users' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            username {$varchar(100)} UNIQUE NOT NULL,
            email {$varchar(200)} UNIQUE NOT NULL,
            password {$varchar(255)} NOT NULL,
            avatar {$varchar(500)},
            posts_count INT DEFAULT 0,
            role {$enum(['user','moderator','seller','admin'])} DEFAULT 'user',
            created_at {$datetime_default}
        )",

        // ===== SITE USERS (for comments + forum registration) =====
        'users' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            username {$varchar(100)} UNIQUE NOT NULL,
            email {$varchar(200)} UNIQUE NOT NULL,
            password {$varchar(255)} NOT NULL,
            display_name {$varchar(150)},
            avatar {$varchar(500)},
            bio {$text_type},
            role {$enum(['user','moderator','seller','admin'])} DEFAULT 'user',
            is_banned INT DEFAULT 0,
            posts_count INT DEFAULT 0,
            comments_count INT DEFAULT 0,
            last_login_at {$datetime_default},
            created_at {$datetime_default}
        )",

        // ===== USER SESSIONS (for "remember me" tokens) =====
        'user_sessions' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            user_id INT NOT NULL,
            token {$varchar(255)} UNIQUE NOT NULL,
            ip {$varchar(45)},
            user_agent {$varchar(500)},
            created_at {$datetime_default},
            expires_at {$datetime_default}
        )",

        // ===== ROOM CATEGORIES (for booking) =====
        'room_categories' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(200)} NOT NULL,
            slug {$varchar(200)},
            description {$text_type},
            position INT DEFAULT 0,
            created_at {$datetime_default}
        )",

        // ===== ROOMS (for hotel booking) =====
        'rooms' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            category_id INT DEFAULT 0,
            title {$varchar(200)} NOT NULL,
            description {$text_type},
            price DECIMAL(10,2) DEFAULT 0,
            capacity INT DEFAULT 2,
            photo {$varchar(500)},
            amenities {$text_type},
            status {$enum(['active','inactive'])} DEFAULT 'active',
            position INT DEFAULT 0,
            created_at {$datetime_default}
        )",

        // ===== CAFE TABLES (for cafe reservation) =====
        'cafe_tables' => "CREATE TABLE IF NOT EXISTS {T} (
            id {$ai},
            title {$varchar(200)} NOT NULL,
            description {$text_type},
            capacity INT DEFAULT 4,
            location {$varchar(100)},
            photo {$varchar(500)},
            status {$enum(['active','inactive'])} DEFAULT 'active',
            position INT DEFAULT 0,
            created_at {$datetime_default}
        )",
    ];

    foreach ($tables as $name => $ddl) {
        $full_name = db_table($name);
        $sql = str_replace('{T}', $full_name, $ddl);
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("AICMS DB: Failed to create table $full_name: " . $e->getMessage());
        }
    }

    // === Migrations: add missing columns to existing tables ===
    $migrations = [
        "comments" => ["user_id" => "INT DEFAULT 0"],
        "shop_products" => ["gallery" => $text_type, "cost_price" => $decimal, "sales_count" => "INT DEFAULT 0", "weight" => "REAL DEFAULT 0", "brand" => $varchar(150)],
        "shop_orders" => ["items_count" => "INT DEFAULT 0"],
    ];

    // === Create news_categories table ===
    try {
        $nc_table = db_table('news_categories');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$nc_table` (
            id {$ai},
            title {$varchar(200)} NOT NULL,
            slug {$varchar(200)},
            lang {$varchar(5)} DEFAULT 'ru',
            position INT DEFAULT 0
        )");
    } catch (PDOException $e) {}

    // === Create indexes for performance ===
    $indexes = [
        'comments' => ['target_id', 'status', 'parent_id', 'lang', 'created_at'],
        'shop_products' => ['status', 'category_id', 'slug', 'lang'],
        'shop_orders' => ['status', 'created_at', 'email'],
        'forum_topics' => ['category_id', 'is_pinned', 'created_at'],
        'forum_posts' => ['topic_id', 'status', 'created_at'],
        'users' => ['username', 'email', 'role', 'status'],
        'user_sessions' => ['session_id', 'user_id', 'expires'],
        'news_categories' => ['slug', 'lang', 'position'],
    ];
    foreach ($indexes as $table => $cols) {
        $full_name = db_table($table);
        foreach ($cols as $col) {
            $idx_name = $full_name . '_idx_' . $col;
            try {
                if ($is_mysql) {
                    $pdo->exec("CREATE INDEX `$idx_name` ON `$full_name` (`$col`)");
                } else {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS `$idx_name` ON `$full_name` (`$col`)");
                }
            } catch (PDOException $e) { /* index might already exist */ }
        }
    }

    // === Create shop_coupons table ===
    try {
        $coupons_table = db_table('shop_coupons');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$coupons_table` (
            id {$ai},
            code {$varchar(50)} UNIQUE NOT NULL,
            type {$enum(['percent','fixed'])} DEFAULT 'percent',
            value {$decimal} DEFAULT 0,
            min_total {$decimal} DEFAULT 0,
            max_uses INT DEFAULT 0,
            uses_count INT DEFAULT 0,
            expires_at {$datetime_default},
            status {$enum(['active','disabled'])} DEFAULT 'active',
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create shop_reviews table ===
    try {
        $reviews_table = db_table('shop_reviews');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$reviews_table` (
            id {$ai},
            product_id INT NOT NULL,
            author_name {$varchar(100)},
            author_email {$varchar(200)},
            user_id INT DEFAULT 0,
            rating INT DEFAULT 5,
            content {$text_type},
            status {$enum(['approved','pending'])} DEFAULT 'pending',
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}
    foreach ($migrations as $table => $cols) {
        $full_name = db_table($table);
        try {
            // Check existing columns
            if ($is_mysql) {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$full_name`");
                $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            } else {
                $stmt = $pdo->query("PRAGMA table_info($full_name)");
                $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 1) : [];
            }
            foreach ($cols as $col => $type) {
                if (!in_array($col, $existing)) {
                    $pdo->exec("ALTER TABLE `$full_name` ADD COLUMN `$col` $type");
                }
            }
        } catch (PDOException $e) {
            // Column might already exist, or migration not needed
        }
    }

    // === Create shop_stock_movements table (stock audit log) ===
    try {
        $sm_table = db_table('shop_stock_movements');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$sm_table` (
            id {$ai},
            product_id INT NOT NULL,
            change_qty INT NOT NULL,
            reason {$varchar(50)},
            order_id INT DEFAULT 0,
            note {$text_type},
            user {$varchar(100)},
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create shop_order_status_history (order status changes) ===
    try {
        $osh_table = db_table('shop_order_status_history');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$osh_table` (
            id {$ai},
            order_id INT NOT NULL,
            old_status {$varchar(30)},
            new_status {$varchar(30)} NOT NULL,
            comment {$text_type},
            user {$varchar(100)},
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create forum_subscriptions table (notify on new posts) ===
    try {
        $fs_table = db_table('forum_subscriptions');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$fs_table` (
            id {$ai},
            topic_id INT NOT NULL,
            user_id INT DEFAULT 0,
            email {$varchar(200)},
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create comment_votes table (likes/dislikes on comments) ===
    try {
        $cv_table = db_table('comment_votes');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$cv_table` (
            id {$ai},
            comment_id INT NOT NULL,
            user_id INT DEFAULT 0,
            voter_ip {$varchar(45)},
            vote {$varchar(10)} NOT NULL,
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create room_reservations table (for date-overlap detection) ===
    try {
        $rr_table = db_table('room_reservations');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$rr_table` (
            id {$ai},
            room_id INT NOT NULL,
            booking_id INT DEFAULT 0,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            status {$enum(['reserved','confirmed','cancelled'])} DEFAULT 'reserved',
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Create cafe_reservations table (for table-conflict detection) ===
    try {
        $cr_table = db_table('cafe_reservations');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$cr_table` (
            id {$ai},
            table_id INT NOT NULL,
            booking_id INT DEFAULT 0,
            reserve_date DATE NOT NULL,
            reserve_time {$varchar(5)} NOT NULL,
            duration_min INT DEFAULT 90,
            status {$enum(['reserved','confirmed','cancelled'])} DEFAULT 'reserved',
            created_at {$datetime_default}
        )");
    } catch (PDOException $e) {}

    // === Add new columns to existing tables (migrations) ===
    $extra_migrations = [
        'forum_topics' => ['is_solved' => 'INT DEFAULT 0', 'solved_post_id' => 'INT DEFAULT 0'],
        'comments'     => ['edited_at' => $datetime_default, 'likes' => 'INT DEFAULT 0', 'dislikes' => 'INT DEFAULT 0'],
    ];
    foreach ($extra_migrations as $table => $cols) {
        $full_name = db_table($table);
        try {
            if ($is_mysql) {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$full_name`");
                $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            } else {
                $stmt = $pdo->query("PRAGMA table_info($full_name)");
                $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 1) : [];
            }
            foreach ($cols as $col => $type) {
                if (!in_array($col, $existing)) {
                    $pdo->exec("ALTER TABLE `$full_name` ADD COLUMN `$col` $type");
                }
            }
        } catch (PDOException $e) {}
    }

    // === Add low-stock threshold column to shop_products ===
    try {
        $sp_table = db_table('shop_products');
        if ($is_mysql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$sp_table`");
            $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } else {
            $stmt = $pdo->query("PRAGMA table_info($sp_table)");
            $existing = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 1) : [];
        }
        if (!in_array('low_stock_threshold', $existing)) {
            $pdo->exec("ALTER TABLE `$sp_table` ADD COLUMN `low_stock_threshold` INT DEFAULT 5");
        }
    } catch (PDOException $e) {}
}
