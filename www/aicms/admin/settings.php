<?php
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/cache.php';  // For cache_clear() on content-affecting changes
require_auth();

$config_file = __DIR__ . '/../data/config.json';
$users_file = __DIR__ . '/../data/users.json';
$config = read_json($config_file, ['site_title' => 'AICMS', 'site_description' => '']);
$user = read_json($users_file);

// Handle all POST forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { set_flash_message('CSRF error.', 'danger'); header('Location: settings.php'); exit; }

    if (isset($_POST['form_type']) && $_POST['form_type'] === 'site_settings') {
        $config['site_title'] = $_POST['site_title'] ?? ''; $config['site_description'] = $_POST['site_description'] ?? '';
        $config['site_keywords'] = $_POST['site_keywords'] ?? ''; $config['show_news'] = isset($_POST['show_news']);
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['ico','png','jpg','jpeg','gif','svg'])) {
                // MIME check — block SVG with scripts (XSS), block PHP disguised as image
                $check = verify_upload_safe($_FILES['favicon']['tmp_name'], $_FILES['favicon']['name']);
                if ($check['ok']) {
                    $fav_dir = __DIR__.'/../uploads/'; if(!is_dir($fav_dir))mkdir($fav_dir,0755,true);
                    move_uploaded_file($_FILES['favicon']['tmp_name'], $fav_dir.'favicon.'.$ext);
                    $config['favicon'] = 'uploads/favicon.'.$ext;
                }
            }
        }
        write_json($config_file, aicms_env_strip($config)); cache_clear(); set_flash_message(t('msg_settings_saved'), 'success'); header('Location: settings.php?tab=site'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'account_settings') {
        $new_username = trim($_POST['username'] ?? ''); $current_password = $_POST['current_password'] ?? ''; $new_password = $_POST['new_password'] ?? '';
        if (empty($new_username)) { set_flash_message('Username cannot be empty.', 'danger'); header('Location: settings.php?tab=account'); exit; }
        $current_user = read_json($users_file);
        if (!empty($new_password)) { if (empty($current_password) || !password_verify($current_password, $current_user['password'])) { set_flash_message('Wrong password.', 'danger'); header('Location: settings.php?tab=account'); exit; } $current_user['password'] = password_hash($new_password, PASSWORD_DEFAULT); }
        $current_user['username'] = $new_username; write_json($users_file, $current_user); $_SESSION['admin'] = $new_username;
        set_flash_message(t('msg_settings_saved'), 'success'); header('Location: settings.php?tab=account'); exit;
    }
    // ===== 2FA: Enable =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === '2fa_enable') {
        require_once __DIR__ . '/core/totp.php';
        $current_user = read_json($users_file);
        $secret = $_POST['totp_secret'] ?? '';
        $code = trim($_POST['totp_verify_code'] ?? '');
        if (totp_verify($secret, $code)) {
            $current_user['totp_secret'] = $secret;
            // Generate 10 one-time backup codes (hashed for storage)
            $backup_plain = totp_generate_backup_codes(10);
            $current_user['totp_backup_codes'] = array_map('totp_hash_backup_code', $backup_plain);
            $current_user['totp_enabled_at'] = date('Y-m-d H:i:s');
            write_json($users_file, $current_user);
            // Stash plain backup codes in session for ONE-TIME display after redirect
            $_SESSION['_totp_backup_plain'] = $backup_plain;
            unset($_SESSION['_totp_pending_secret']);
            set_flash_message(t('two_factor_enabled_info'), 'success');
        } else {
            set_flash_message(t('two_factor_code_wrong'), 'danger');
        }
        header('Location: settings.php?tab=account'); exit;
    }
    // ===== 2FA: Disable =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === '2fa_disable') {
        $current_user = read_json($users_file);
        $current_user['totp_secret'] = '';
        $current_user['totp_backup_codes'] = [];
        $current_user['totp_enabled_at'] = null;
        write_json($users_file, $current_user);
        set_flash_message(t('disable_2fa'), 'success');
        header('Location: settings.php?tab=account'); exit;
    }
    // ===== 2FA: Regenerate backup codes =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === '2fa_regenerate_backup') {
        require_once __DIR__ . '/core/totp.php';
        $current_user = read_json($users_file);
        if (!empty($current_user['totp_secret'])) {
            $backup_plain = totp_generate_backup_codes(10);
            $current_user['totp_backup_codes'] = array_map('totp_hash_backup_code', $backup_plain);
            write_json($users_file, $current_user);
            $_SESSION['_totp_backup_plain'] = $backup_plain;
            set_flash_message('Backup codes regenerated. Save the new codes — old ones are invalid.', 'success');
        }
        header('Location: settings.php?tab=account'); exit;
    }
    // ===== 2FA: Generate new secret =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === '2fa_generate') {
        require_once __DIR__ . '/core/totp.php';
        $new_secret = totp_generate_secret();
        $_SESSION['_totp_pending_secret'] = $new_secret;
        header('Location: settings.php?tab=account&setup_2fa=1'); exit;
    }
    // ===== Features toggle =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'features_save') {
        $config['features'] = [
            '2fa' => !empty($_POST['features']['2fa']),
            'csp_headers' => !empty($_POST['features']['csp_headers']),
            'webp_conversion' => !empty($_POST['features']['webp_conversion']),
            'ai_translation' => !empty($_POST['features']['ai_translation']),
            'telegram_bot' => !empty($_POST['features']['telegram_bot']),
            'webhooks' => !empty($_POST['features']['webhooks']),
            'rest_api' => !empty($_POST['features']['rest_api']),
        ];
        write_json($config_file, aicms_env_strip($config));
        cache_clear();
        set_flash_message(t('msg_features_saved'), 'success');
        header('Location: settings.php?tab=features'); exit;
    }
    // ===== Integrations (webhook + Telegram + SMTP) =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'integrations_save') {
        $config['webhook_url'] = trim($_POST['webhook_url'] ?? '');
        $config['telegram_bot_token'] = trim($_POST['telegram_bot_token'] ?? '');
        $config['telegram_chat_id'] = trim($_POST['telegram_chat_id'] ?? '');
        $config['site_email'] = trim($_POST['site_email'] ?? '');
        $config['smtp'] = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'port' => (int)($_POST['smtp_port'] ?? 587),
            'username' => trim($_POST['smtp_username'] ?? ''),
            'password' => trim($_POST['smtp_password'] ?? ''),
            'security' => $_POST['smtp_security'] ?? 'tls',
        ];
        write_json($config_file, aicms_env_strip($config));
        cache_clear();
        set_flash_message(t('msg_integrations_saved'), 'success');
        header('Location: settings.php?tab=features'); exit;
    }
    // ===== API token =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'api_token_save') {
        $config['api_token'] = trim($_POST['api_token'] ?? '');
        write_json($config_file, aicms_env_strip($config));
        set_flash_message(t('msg_api_token_saved'), 'success');
        header('Location: settings.php?tab=features'); exit;
    }
    // ===== Interface (button stretch animation speed) =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'interface_save') {
        $stretch_ms = (int)($_POST['stretch_speed'] ?? 700);
        if ($stretch_ms < 200) $stretch_ms = 200;
        if ($stretch_ms > 2000) $stretch_ms = 2000;
        $config['ui']['stretch_speed'] = $stretch_ms;
        write_json($config_file, aicms_env_strip($config));
        cache_clear();
        set_flash_message(t('msg_settings_saved'), 'success');
        header('Location: settings.php?tab=interface'); exit;
    }
    // ===== AI settings (ключи хранятся в data/.env.php, а не в config.json) =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'ai_settings_save') {
        $config['ai'] = [
            'text' => [
                'provider' => $_POST['text_provider'] ?? 'openrouter',
                'model'    => trim($_POST['text_model'] ?? ''),
                'api_key'  => trim($_POST['text_api_key'] ?? ''),
            ],
            'image' => [
                'provider' => $_POST['image_provider'] ?? 'pollinations',
                'model'    => trim($_POST['image_model'] ?? ''),
                'api_key'  => trim($_POST['image_api_key'] ?? ''),
            ],
            'video' => [
                'provider' => $_POST['video_provider'] ?? 'disabled',
                'model'    => trim($_POST['video_model'] ?? ''),
                'api_key'  => trim($_POST['video_api_key'] ?? ''),
            ],
        ];
        // Route API keys to data/.env.php by provider; keep config.json clean.
        // Only blank config.json if the env write succeeded (avoid silent secret loss).
        foreach (['text', 'image', 'video'] as $_sec) {
            $_provider = $config['ai'][$_sec]['provider'] ?? '';
            $_ek = aicms_provider_env_key($_provider);
            if ($_ek && aicms_env_set($_ek, $config['ai'][$_sec]['api_key'] ?? '')) {
                $config['ai'][$_sec]['api_key'] = '';
            }
        }
        write_json($config_file, aicms_env_strip($config));
        set_flash_message('AI настройки сохранены. Используются для AI SEO, AI перевода и редактора Lumina.', 'success');
        header('Location: settings.php?tab=ai'); exit;
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'add_language') {
        $code = trim($_POST['lang_code'] ?? ''); $name = trim($_POST['lang_name'] ?? ''); $flag = trim($_POST['lang_flag'] ?? '');
        if (!preg_match('/^[a-z]{2,5}$/', $code)) { set_flash_message('Invalid code.', 'danger'); header('Location: settings.php?tab=localization'); exit; }
        $locale_file = __DIR__ . '/locales/' . $code . '.php';
        if (!file_exists($locale_file)) { $template = file_get_contents(__DIR__.'/locales/ru.php'); $template = preg_replace("/'__meta' => \[.*?\]/s", "'__meta' => [\n        'code'  => '$code',\n        'name'  => '".addslashes($name)."',\n        'flag'  => '$flag',\n    ]", $template); file_put_contents($locale_file, $template); }
        if (!isset($config['languages']) || !is_array($config['languages'])) $config['languages'] = [];
        $exists = false; foreach ($config['languages'] as $l) { if (($l['code']??'') === $code) { $exists = true; break; } }
        if (!$exists) { $config['languages'][] = ['code'=>$code, 'name'=>$name?:ucfirst($code)]; write_json($config_file, aicms_env_strip($config)); cache_clear(); }
        set_flash_message(t('msg_lang_added'), 'success'); header('Location: settings.php?tab=localization'); exit;
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_language') {
        $code = trim($_POST['lang_code'] ?? ''); $default_lang = $config['default_lang'] ?? 'ru';
        if ($code === $default_lang) { set_flash_message(t('cannot_delete_default'), 'danger'); header('Location: settings.php?tab=localization'); exit; }
        if (isset($config['languages'])) { $config['languages'] = array_values(array_filter($config['languages'], fn($l) => ($l['code']??'') !== $code)); write_json($config_file, aicms_env_strip($config)); cache_clear(); }
        $lf = __DIR__.'/locales/'.$code.'.php'; if (file_exists($lf) && $code !== $default_lang) unlink($lf);
        if (ADMIN_LANG === $code) $_SESSION['admin_lang'] = $default_lang;
        set_flash_message(t('msg_lang_removed'), 'success'); header('Location: settings.php?tab=localization'); exit;
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'set_default') {
        $config['default_lang'] = trim($_POST['lang_code'] ?? ''); write_json($config_file, aicms_env_strip($config)); cache_clear();
        set_flash_message(t('msg_default_lang_set'), 'success'); header('Location: settings.php?tab=localization'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'htaccess_save') {
        file_put_contents(__DIR__.'/../.htaccess', $_POST['htaccess_content'] ?? ''); set_flash_message(t('msg_htaccess_saved'), 'success'); header('Location: settings.php?tab=htaccess'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'htaccess_generate') {
        $o = ['deny_data'=>isset($_POST['ht_deny_data']),'hide_json'=>isset($_POST['ht_hide_json']),'gzip'=>isset($_POST['ht_gzip']),'cache'=>isset($_POST['ht_cache']),'ssl'=>isset($_POST['ht_ssl']),'www'=>isset($_POST['ht_www'])];
        $ht = "# AICMS .htaccess\n# ".date('Y-m-d H:i:s')."\n";
        if($o['deny_data'])$ht.="\n<FilesMatch \"^(data|backup|logs)/.*\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n";
        if($o['hide_json'])$ht.="\n<FilesMatch \"^data/.*\\.json$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n</FilesMatch>\n";
        if($o['gzip'])$ht.="\n<IfModule mod_deflate.c>\n    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json\n</IfModule>\n";
        if($o['cache'])$ht.="\n<IfModule mod_expires.c>\n    ExpiresActive On\n    ExpiresByType text/css \"access plus 1 month\"\n    ExpiresByType application/javascript \"access plus 1 month\"\n    ExpiresByType image/png \"access plus 1 year\"\n    ExpiresByType image/jpg \"access plus 1 year\"\n    ExpiresByType image/jpeg \"access plus 1 year\"\n    ExpiresByType image/gif \"access plus 1 year\"\n    ExpiresByType image/svg+xml \"access plus 1 year\"\n    ExpiresByType font/woff2 \"access plus 1 year\"\n</IfModule>\n";
        if($o['ssl'])$ht.="\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTPS} off\n    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n</IfModule>\n";
        if($o['www'])$ht.="\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\n    RewriteRule ^(.*)$ https://%1/$1 [R=301,L]\n</IfModule>\n";
        file_put_contents(__DIR__.'/../.htaccess', $ht); set_flash_message(t('msg_htaccess_created'), 'success'); header('Location: settings.php?tab=htaccess'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'htaccess_delete') {
        if (file_exists(__DIR__.'/../.htaccess') && unlink(__DIR__.'/../.htaccess')) set_flash_message(t('msg_htaccess_deleted'), 'success'); else set_flash_message(t('msg_htaccess_not_deleted'), 'danger');
        header('Location: settings.php?tab=htaccess'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'db_settings') {
        // Read all DB fields from POST
        $new_storage = [
            'driver'   => $_POST['db_driver'] ?? 'json',
            'host'     => trim($_POST['db_host'] ?? 'localhost'),
            'port'     => trim($_POST['db_port'] ?? '3306'),
            'database' => trim($_POST['db_name'] ?? 'aicms'),
            'username' => trim($_POST['db_user'] ?? ''),
            'password' => $_POST['db_pass'] ?? '',  // don't trim password!
            'prefix'   => trim($_POST['db_prefix'] ?? 'aicms_'),
            'path'     => trim($_POST['db_path'] ?? 'data/aicms.db'),
        ];
        $db_pass_diag = $new_storage['password']; // для диагностики до очистки
        // Креды БД хранятся в data/.env.php, а не в config.json
        if ($new_storage['driver'] !== 'json') {
            $env_map = [
                'host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_NAME',
                'username' => 'DB_USER', 'password' => 'DB_PASS', 'prefix' => 'DB_PREFIX',
            ];
            foreach ($env_map as $_fld => $_ek) {
                $_val = $new_storage[$_fld] ?? '';
                if ($_val !== '' && aicms_env_set($_ek, $_val)) {
                    $new_storage[$_fld] = '';
                }
            }
        }
        $config['storage'] = $new_storage;
        $save_ok = write_json($config_file, aicms_env_strip($config));
        if (!$save_ok) {
            set_flash_message(t('msg_config_save_error'), 'danger');
            header('Location: settings.php?tab=database'); exit;
        }
        require_once __DIR__.'/core/db.php';
        if ($new_storage['driver'] !== 'json') {
            db_reset();
            if (db()) {
                set_flash_message(t('msg_db_connected'), 'success');
            } else {
                $err = db_last_error() ?: 'Unknown error';
                // Build a diagnostic message
                $diag = 'Could not connect to ' . strtoupper($new_storage['driver']) . ': ' . $err;
                $diag .= ' | Saved data: host=' . $new_storage['host'] . ':' . $new_storage['port'] . ' db=' . $new_storage['database'] . ' user=' . $new_storage['username'] . ' password=' . (strlen($db_pass_diag) > 0 ? 'YES (' . strlen($db_pass_diag) . ' ' . t('chars') . ')' : 'NO (empty!)');
                set_flash_message($diag, 'danger');
            }
        } else {
            set_flash_message(t('msg_db_switched_json'), 'success');
        }
        header('Location: settings.php?tab=database'); exit;
    }
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'db_migrate') {
        require_once __DIR__.'/core/db.php';
        db_reset();
        if (!db()) {
            $err = db_last_error() ?: 'Unknown error';
            set_flash_message(str_replace('{$err}', $err, t('msg_db_unavailable')), 'danger');
        } else {
            set_flash_message(t('msg_tables_created'), 'success');
        }
        header('Location: settings.php?tab=database'); exit;
    }
    // ===== Security & Maintenance =====
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'security_save') {
        $config['maintenance'] = [
            'enabled'     => !empty($_POST['maintenance_enabled']),
            'message'     => mb_substr(trim($_POST['maintenance_message'] ?? ''), 0, 500),
            'retry_after' => max(30, min(86400, (int)($_POST['maintenance_retry_after'] ?? 300))),
        ];
        $config['security'] = [
            'trusted_proxies' => array_values(array_filter(array_map('trim', explode("\n", $_POST['trusted_proxies'] ?? '')), fn($s) => $s !== '')),
            'csp_report'      => !empty($_POST['csp_report']),
            'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode("\n", $_POST['admin_ip_allowlist'] ?? '')), fn($s) => $s !== '')),
        ];
        // Also create/remove the .maintenance kill-switch file
        $kill_file = __DIR__ . '/../data/.maintenance';
        if (!empty($_POST['maintenance_kill_switch']) && !file_exists($kill_file)) {
            @file_put_contents($kill_file, date('c'));
        } elseif (empty($_POST['maintenance_kill_switch']) && file_exists($kill_file)) {
            @unlink($kill_file);
        }
        write_json($config_file, aicms_env_strip($config));
        cache_clear();
        set_flash_message('Настройки безопасности сохранены.', 'success');
        header('Location: settings.php?tab=security'); exit;
    }
}

$active_tab = $_GET['tab'] ?? 'site';
$allowed_tabs = ['site','account','localization','htaccess','database','features','ai','interface','security'];
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'site';
$htaccess_content = ''; $htaccess_path = __DIR__ . '/../.htaccess'; if (file_exists($htaccess_path)) $htaccess_content = file_get_contents($htaccess_path);
require_once __DIR__ . '/core/db.php'; db_reset(); $db_connected = db_available(); $db_driver = db_driver(); $db_error = db_last_error();
$available_locales = get_available_locales(); $default_lang = $config['default_lang'] ?? 'ru';
$inp = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all';
$lbl = 'block text-sm font-medium text-slate-700 mb-1';
?>
<!DOCTYPE html>
<html lang="<?= ADMIN_LANG ?>">
<head>
    <meta charset="UTF-8"><title><?= t('title_settings') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/core/tailwind_head.php'; ?>
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-100">
<div class="flex">
    <?php include __DIR__ . '/core/sidebar.php'; ?>
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        <?php
        $page_title = t('h_settings');
        $breadcrumb = [['label'=>t('dashboard'),'url'=>'index.php'],['label'=>t('settings')]];
        include __DIR__ . '/core/topbar.php';
        ?>
        <div class="p-6 flex-1">
        <?php display_flash_message(); ?>

        <div x-data="{tab:'<?= $active_tab ?>'}">
            <!-- Tab buttons -->
            <div class="flex flex-wrap gap-1 mb-4 border-b border-slate-200">
                <button @click="tab='site'" :class="tab==='site'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-globe mr-1"></i><?= t('tab_site') ?></button>
                <button @click="tab='account'" :class="tab==='account'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-user-shield mr-1"></i><?= t('tab_account') ?></button>
                <button @click="tab='localization'" :class="tab==='localization'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-language mr-1"></i><?= t('tab_localization') ?></button>
                <button @click="tab='database'" :class="tab==='database'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-database mr-1"></i><?= t('tab_db') ?></button>
                <button @click="tab='htaccess'" :class="tab==='htaccess'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-file-code mr-1"></i><?= t('tab_htaccess') ?></button>
                <button @click="tab='features'" :class="tab==='features'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-toggle-on mr-1"></i><?= t('tab_features') ?></button>
                <button @click="tab='ai'" :class="tab==='ai'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-robot mr-1"></i> AI</button>
                <button @click="tab='interface'" :class="tab==='interface'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-sliders-h mr-1"></i> Интерфейс</button>
                <button @click="tab='security'" :class="tab==='security'?'border-blue-600 text-blue-600':'border-transparent text-slate-500 hover:text-slate-700'" class="px-4 py-2 text-sm font-medium border-b-2 transition-all"><i class="fas fa-shield-alt mr-1"></i> Безопасность</button>
            </div>

            <!-- TAB: SITE -->
            <div x-show="tab==='site'" class="bg-white rounded-xl shadow-sm p-5">
                <form action="settings.php" method="POST" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="form_type" value="site_settings">
                    <h5 class="text-base font-semibold text-slate-800 mb-4"><i class="fas fa-globe mr-1"></i><?= t('site_settings') ?></h5>
                    <div class="mb-3"><label class="<?= $lbl ?>"><?= t('site_title') ?></label><input type="text" name="site_title" class="<?= $inp ?>" value="<?= h($config['site_title'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="<?= $lbl ?>"><?= t('description_label') ?></label><textarea name="site_description" rows="2" class="<?= $inp ?>"><?= h($config['site_description'] ?? '') ?></textarea></div>
                    <div class="mb-3"><label class="<?= $lbl ?>"><?= t('site_keywords') ?></label><input type="text" name="site_keywords" class="<?= $inp ?>" value="<?= h($config['site_keywords'] ?? '') ?>"></div>
                    <div class="mb-3 flex items-center gap-3"><label class="aicms-toggle"><input type="checkbox" name="show_news" id="show_news_toggle" <?= ($config['show_news'] ?? true) ? 'checked' : '' ?> aria-label="<?= t('show_news_label') ?>"><span class="aicms-toggle-track"></span></label><label for="show_news_toggle" class="text-sm text-slate-700 cursor-pointer select-none"><?= t('show_news_label') ?></label></div>
                    <div class="mb-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i><?= t('ai_settings_moved') ?></div>
                    <div class="mb-3">
                        <label class="<?= $lbl ?>"><?= t('favicon') ?></label>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($config['favicon']) && file_exists(__DIR__.'/../'.$config['favicon'])): ?>
                            <img src="../<?= h($config['favicon']) ?>" alt="favicon" class="w-10 h-10 object-contain rounded-lg border border-slate-200 bg-white p-1">
                            <?php endif; ?>
                            <label class="aicms-btn aicms-btn--secondary aicms-btn--sm cursor-pointer mb-0">
                                <i class="fas fa-upload"></i><span class="btn-label"><?= t('upload_file') ?></span>
                                <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg,.gif,.svg" class="hidden" data-empty-label="<?= h(t('upload_file')) ?>" onchange="var l=this.closest('label').querySelector('.btn-label');l.textContent=this.files[0]?this.files[0].name:this.getAttribute('data-empty-label');">
                            </label>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">ICO, PNG, JPG, GIF, SVG</p>
                    </div>
                    <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> <?= t('save') ?></button></div>
                </form>
            </div>

            <!-- TAB: ACCOUNT -->
            <div x-show="tab==='account'" x-cloak class="bg-white rounded-xl shadow-sm p-5 max-w-md">
                <form action="settings.php" method="POST"><?= csrf_field() ?><input type="hidden" name="form_type" value="account_settings">
                    <h5 class="font-semibold text-slate-800 mb-4"><i class="fas fa-user-shield mr-1"></i><?= t('admin_account_section') ?></h5>
                    <div class="mb-3"><label class="<?= $lbl ?>"><?= t('username_label') ?></label><input type="text" name="username" class="<?= $inp ?>" value="<?= h($user['username']) ?>" required></div>
                    <hr class="border-slate-200 my-4"><p class="text-sm text-slate-400 mb-3"><?= t('leave_empty_pass') ?></p>
                    <div class="mb-3"><label class="<?= $lbl ?>"><?= t('current_password') ?></label><input type="password" name="current_password" class="<?= $inp ?>" autocomplete="current-password"></div>
                    <div class="mb-3">
                        <label class="<?= $lbl ?>"><?= t('new_password') ?></label>
                        <input type="password" name="new_password" id="new_password_input" class="<?= $inp ?>" autocomplete="new-password" oninput="updatePasswordStrength(this.value)">
                        <div class="mt-2">
                            <div class="w-full bg-slate-200 rounded-full h-1.5 overflow-hidden">
                                <div id="password_strength_bar" class="h-full rounded-full transition-all" style="width:0%;background:#cbd5e1"></div>
                            </div>
                            <div class="flex justify-between text-xs mt-1">
                                <span id="password_strength_label" class="text-slate-400">Введите новый пароль</span>
                                <span id="password_strength_hint" class="text-slate-400"></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-user-cog"></i> <?= t('save') ?></button></div>
                </form>
                <script>
                // Password strength meter — estimates entropy based on character
                // class composition and length. Purely client-side, no PII sent.
                (function() {
                    window.updatePasswordStrength = function(pw) {
                        var bar = document.getElementById('password_strength_bar');
                        var lbl = document.getElementById('password_strength_label');
                        var hint = document.getElementById('password_strength_hint');
                        if (!bar || !lbl) return;
                        if (!pw) {
                            bar.style.width = '0%'; bar.style.background = '#cbd5e1';
                            lbl.textContent = 'Введите новый пароль'; lbl.className = 'text-slate-400';
                            if (hint) hint.textContent = '';
                            return;
                        }
                        var charset = 0;
                        if (/[a-z]/.test(pw)) charset += 26;
                        if (/[A-Z]/.test(pw)) charset += 26;
                        if (/[0-9]/.test(pw)) charset += 10;
                        if (/[^a-zA-Z0-9]/.test(pw)) charset += 32;
                        var entropy = pw.length * Math.log2(charset || 1);
                        var score, label, color, hint_text;
                        if (entropy < 28) {
                            score = 25; label = 'Очень слабый'; color = '#dc2626'; hint_text = 'Используйте ≥8 символов';
                        } else if (entropy < 40) {
                            score = 50; label = 'Слабый'; color = '#f59e0b'; hint_text = 'Добавьте цифры и спецсимволы';
                        } else if (entropy < 60) {
                            score = 75; label = 'Хороший'; color = '#2563eb'; hint_text = 'Хороший пароль';
                        } else if (entropy < 80) {
                            score = 90; label = 'Сильный'; color = '#16a34a'; hint_text = 'Сильный пароль';
                        } else {
                            score = 100; label = 'Очень сильный'; color = '#15803d'; hint_text = 'Отличный пароль';
                        }
                        bar.style.width = score + '%';
                        bar.style.background = color;
                        lbl.textContent = label;
                        lbl.className = 'text-sm font-medium';
                        lbl.style.color = color;
                        if (hint) hint.textContent = hint_text;
                    };
                })();
                </script>

                <hr class="border-slate-200 my-5">
                
                <!-- 2FA Section -->
                <?php require_once __DIR__ . '/core/totp.php'; ?>
                <?php $totp_secret = $user['totp_secret'] ?? ''; ?>
                <?php $setup_2fa = !empty($_GET['setup_2fa']); ?>
                <?php $pending_secret = $_SESSION['_totp_pending_secret'] ?? ''; ?>
                <?php $_2fa_feature_on = !empty($config['features']['2fa']); ?>
                
                <h5 class="font-semibold text-slate-800 mb-3"><i class="fas fa-mobile-alt mr-1 text-indigo-600"></i><?= t('two_factor_auth') ?></h5>
                
                <?php if (!$_2fa_feature_on): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-3 text-sm text-amber-800">
                    <i class="fas fa-exclamation-triangle"></i> <?= t('two_factor_disabled_warn') ?>
                </div>
                <?php elseif (!empty($totp_secret)): ?>
                <!-- 2FA is enabled -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-3 text-sm text-green-700">
                    <i class="fas fa-check-circle"></i> <?= t('two_factor_enabled_info') ?>
                    <?php if (!empty($user['totp_enabled_at'])): ?>
                    <span class="text-xs text-green-600 ml-2">· <?= t('enabled_on') ?? 'enabled on' ?> <?= h($user['totp_enabled_at']) ?></span>
                    <?php endif; ?>
                </div>

                <?php
                // One-time backup codes display (after 2FA just enabled or codes regenerated)
                $backup_plain = $_SESSION['_totp_backup_plain'] ?? null;
                if ($backup_plain) {
                    unset($_SESSION['_totp_backup_plain']);  // show once
                }
                ?>
                <?php if ($backup_plain): ?>
                <div class="bg-amber-50 border border-amber-300 rounded-lg p-4 mb-3">
                    <div class="flex items-start gap-2 mb-2">
                        <i class="fas fa-exclamation-triangle text-amber-600 mt-0.5"></i>
                        <div>
                            <h6 class="font-semibold text-amber-900 mb-1">Backup codes — save them now</h6>
                            <p class="text-xs text-amber-800 mb-3">These 10 one-time codes let you sign in if you lose your phone. Each can be used only once. Store them in a safe place — they will NOT be shown again.</p>
                        </div>
                    </div>
                    <div class="bg-white border border-amber-200 rounded p-3 mb-3">
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            <?php foreach ($backup_plain as $i => $bc): ?>
                            <div class="px-2 py-1 <?= $i % 2 ? 'bg-amber-50' : '' ?> rounded"><?= h($bc) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" onclick="navigator.clipboard.writeText(<?= json_encode(implode('\n', $backup_plain)) ?>);this.textContent='Copied!';" class="aicms-btn aicms-btn--secondary aicms-btn--sm">
                            <i class="fas fa-copy"></i> Copy all
                        </button>
                        <a href="settings.php?tab=account" class="aicms-btn aicms-btn--secondary aicms-btn--sm">I saved them</a>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 mb-3 text-xs text-slate-600">
                    <div class="flex items-center justify-between">
                        <span><i class="fas fa-key text-slate-500"></i> Backup codes: <strong><?= count($user['totp_backup_codes'] ?? []) ?> remaining</strong></span>
                        <form method="POST" onsubmit="return confirm('Generate new backup codes? The old ones will be invalidated.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_type" value="2fa_regenerate_backup">
                            <button type="submit" class="aicms-btn aicms-btn--secondary aicms-btn--sm">
                                <i class="fas fa-redo"></i> Regenerate
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" onsubmit="return confirm('<?= t('disable_2fa_confirm') ?>');" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="2fa_disable">
                    <button type="submit" class="aicms-btn aicms-btn--danger"><i class="fas fa-times"></i> <?= t('disable_2fa') ?></button>
                </form>
                
                <?php elseif ($setup_2fa && !empty($pending_secret)): ?>
                <!-- 2FA setup wizard -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-3">
                    <p class="text-sm text-blue-800 mb-3"><?= t('two_factor_step_1') ?></p>
                    <div class="text-center mb-3">
                        <img src="<?= h(totp_generate_qr_url($pending_secret, $user['username'])) ?>" alt="QR" class="mx-auto rounded-lg border">
                    </div>
                    <p class="text-xs text-slate-500 mb-3"><?= t('or_enter_secret') ?> <code class="px-2 py-1 bg-slate-100 rounded font-mono"><?= h($pending_secret) ?></code></p>
                    <hr class="border-blue-200 my-3">
                    <p class="text-sm text-blue-800 mb-2"><?= t('two_factor_step_2') ?></p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_type" value="2fa_enable">
                        <input type="hidden" name="totp_secret" value="<?= h($pending_secret) ?>">
                        <input type="text" name="totp_verify_code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" inputmode="numeric"
                            class="w-full px-3 py-2 border border-slate-300 rounded-lg text-center text-2xl tracking-widest font-mono mb-3" autocomplete="one-time-code">
                        <div class="flex gap-2">
                            <button type="submit" class="aicms-btn aicms-btn--success flex-1 justify-center"><i class="fas fa-check"></i> <?= t('confirm_enable_2fa') ?></button>
                            <a href="settings.php?tab=account" class="aicms-btn aicms-btn--secondary"><?= t('cancel') ?></a>
                        </div>
                    </form>
                </div>
                
                <?php else: ?>
                <!-- 2FA is disabled — show enable button -->
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-3 text-sm text-amber-800">
                    <i class="fas fa-exclamation-triangle"></i> <?= t('two_factor_not_enabled_warn') ?>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_type" value="2fa_generate">
                    <button type="submit" class="aicms-btn aicms-btn--primary"><i class="fas fa-shield-alt"></i> <?= t('enable_2fa') ?></button>
                </form>
                <?php endif; ?>
            </div>

            <!-- TAB: LOCALIZATION -->
            <div x-show="tab==='localization'" x-cloak x-data="{addLang:false}">
                <div class="flex justify-between items-center mb-3"><p class="text-sm text-slate-500 m-0"><?= t('languages_count') ?> <?= count($available_locales) ?> · <?= t('default_label') ?> <code class="px-1.5 py-0.5 bg-slate-100 rounded"><?= h($default_lang) ?></code></p>
                <button class="aicms-btn aicms-btn--primary" @click="addLang=true"><i class="fas fa-plus"></i> <?= t('add_language_btn') ?></button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full">
                    <thead><tr class="border-b border-slate-200 bg-slate-50"><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('flag') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('code') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('name_label') ?></th><th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase"><?= t('translation_coverage') ?></th><th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase"><?= t('actions') ?></th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
                    <?php foreach ($available_locales as $code => $meta): $cov = get_translation_coverage($code); $is_def = ($code === $default_lang); ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-2xl"><?= h($meta['flag']) ?></td>
                        <td class="px-4 py-3"><code class="text-xs px-1.5 py-0.5 bg-slate-100 rounded"><?= h($code) ?></code><?php if($is_def): ?><span class="ml-1 px-1.5 py-0.5 text-xs bg-green-100 text-green-700 rounded"><?= t('default_badge') ?></span><?php endif; ?></td>
                        <td class="px-4 py-3 text-sm text-slate-700"><?= h($meta['name']) ?></td>
                        <td class="px-4 py-3"><div class="flex items-center gap-2"><div class="w-16 bg-slate-200 rounded-full h-1.5"><div class="h-full rounded-full <?= $cov>=80?'bg-green-500':($cov>=50?'bg-amber-500':'bg-red-500') ?>" style="width:<?= $cov ?>%"></div></div><span class="text-xs text-slate-400"><?= $cov ?>%</span></div></td>
                        <td class="px-4 py-3"><div class="flex gap-1 justify-end"><?php if(!$is_def): ?><form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="lang_code" value="<?= h($code) ?>"><button class="aicms-act-outline aicms-act-outline--success" data-tooltip="<?= t('default_tip') ?>"><i class="fas fa-star"></i></button></form><form method="POST" class="inline" onsubmit="return confirm('<?= t('confirm_lang_delete') ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_language"><input type="hidden" name="lang_code" value="<?= h($code) ?>"><button class="aicms-act-outline aicms-act-outline--danger" data-tooltip="<?= t('delete_tip_lang') ?>"><i class="fas fa-trash"></i></button></form><?php else: ?><span class="text-slate-300 text-sm">—</span><?php endif; ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <!-- Add Language Modal -->
                <div x-show="addLang" x-cloak @keydown.escape.window="addLang=false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4" @click.outside="addLang=false">
                        <div class="px-5 py-3 border-b border-slate-200 flex justify-between items-center"><h5 class="font-semibold m-0"><?= t('add_language_modal') ?></h5><button @click="addLang=false" class="text-slate-400 text-xl">&times;</button></div>
                        <form method="POST" class="p-5"><?= csrf_field() ?><input type="hidden" name="action" value="add_language">
                            <div class="mb-3"><label class="<?= $lbl ?>"><?= t('code_label') ?></label><input type="text" name="lang_code" class="<?= $inp ?>" required pattern="[a-z]{2,5}" placeholder="fr, de, zh"></div>
                            <div class="mb-3"><label class="<?= $lbl ?>"><?= t('name_label') ?></label><input type="text" name="lang_name" class="<?= $inp ?>" required placeholder="Français"></div>
                            <div class="mb-3"><label class="<?= $lbl ?>"><?= t('flag') ?></label><input type="text" name="lang_flag" class="<?= $inp ?>" placeholder="🇫🇷" maxlength="8"></div>
                            <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--primary"><i class="fas fa-check"></i> <?= t('create_btn') ?></button></div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- TAB: DATABASE -->
            <div x-show="tab==='database'" x-cloak x-data="{driver:'<?= h($config['storage']['driver']??'json') ?>'}">
                <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i><strong><?= t('db_mode_label') ?></strong> <?php if($db_connected): ?><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs"><?= strtoupper($db_driver) ?> ✓</span><?php else: ?><span class="px-2 py-0.5 bg-slate-200 text-slate-600 rounded-full text-xs">JSON</span><?php endif; ?> · <?= t('db_mode_hint') ?></div>
                <?php if (!empty($db_error) && !$db_connected): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4 text-sm text-red-700">
                    <div class="flex items-start gap-2">
                        <i class="fas fa-exclamation-triangle mt-0.5"></i>
                        <div class="flex-1">
                            <strong><?= t('db_connection_error') ?></strong>
                            <pre class="mt-2 mb-0 p-2 bg-red-100 rounded text-xs font-mono whitespace-pre-wrap break-all"><?= h($db_error) ?></pre>
                            <div class="mt-3 text-xs text-red-600">
                                <strong><?= t('possible_causes') ?></strong>
                                <ul class="list-disc ml-5 mt-1 space-y-0.5">
                                    <li><?= t('cause_host') ?></li>
                                    <li><?= str_replace('...', h($config['storage']['database'] ?? ''), t('cause_db_missing')) ?></li>
                                    <li><?= str_replace('...', h($config['storage']['username'] ?? ''), t('cause_user_rights')) ?></li>
                                    <li><?= t('cause_pdo') ?></li>
                                    <li><?= t('cause_firewall') ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="bg-white rounded-xl shadow-sm p-5 mb-4">
                    <form action="settings.php" method="POST" id="dbSettingsForm"><?= csrf_field() ?><input type="hidden" name="form_type" value="db_settings">
                        <div class="mb-3"><label class="<?= $lbl ?>"><?= t('storage_type') ?></label>
                            <select name="db_driver" class="<?= $inp ?>" x-model="driver" @change="document.getElementById('dbSettingsForm').dataset.driver = $event.target.value">
                                <option value="json"><?= t('storage_json') ?></option>
                                <option value="mysql">MySQL</option>
                                <option value="sqlite">SQLite</option>
                            </select>
                        </div>
                        <?php
                            $cur_driver = $config['storage']['driver'] ?? 'json';
                            $mysql_style = $cur_driver === 'mysql' ? '' : 'display:none;';
                            $sqlite_style = $cur_driver === 'sqlite' ? '' : 'display:none;';
                            // Detect if creds are stored in .env.php (then config.json has them empty,
                            // but they ARE actually configured). Show "(saved)" indicator so user
                            // doesn't think the form is broken.
                            $env = aicms_env();
                            $env_has_db = !empty($env['DB_USER']) || !empty($env['DB_HOST']) || !empty($env['DB_NAME']);
                            // IMPORTANT: use ?: (Elvis) not ?? (null-coalesce) — empty string ''
                            // from aicms_env_strip() should fall through to defaults, otherwise
                            // the form shows empty Host/Port/Database fields and the user has no
                            // idea what to type. This was the cause of the "Access denied for ''@'localhost'"
                            // error: empty db_host + empty db_user from the form.
                            if ($env_has_db) {
                                $env_user = $env['DB_USER'] ?? '';
                                $env_host = $env['DB_HOST'] ?? 'localhost';
                                $env_port = $env['DB_PORT'] ?? '3306';
                                $env_db   = $env['DB_NAME'] ?? '';
                                $env_pass_set = !empty($env['DB_PASS']);
                                $env_prefix = $env['DB_PREFIX'] ?? 'aicms_';
                            } else {
                                $env_user = $config['storage']['username'] ?? '';
                                // Force non-empty defaults for host/port so the form is usable
                                $env_host = !empty($config['storage']['host']) ? $config['storage']['host'] : 'localhost';
                                $env_port = !empty($config['storage']['port']) ? $config['storage']['port'] : '3306';
                                $env_db   = $config['storage']['database'] ?? '';
                                $env_pass_set = !empty($config['storage']['password']);
                                $env_prefix = !empty($config['storage']['prefix']) ? $config['storage']['prefix'] : 'aicms_';
                            }
                        ?>
                        <div id="mysql_fields" class="space-y-3" style="<?= $mysql_style ?>">
                            <?php if ($env_has_db): ?>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-xs text-green-800 mb-2">
                                <i class="fas fa-check-circle mr-1"></i>
                                <strong>Креды загружены из data/.env.php</strong> (приоритет над config.json).
                                При сохранении формы они будут перезаписаны в .env.php.
                                Текущий: <code class="px-1 py-0.5 bg-white rounded font-mono"><?= h($env_user) ?>@<?= h($env_host) ?>:<?= h($env_port) ?>/<?= h($env_db) ?></code>
                            </div>
                            <?php else: ?>
                            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 mb-2">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <strong>data/.env.php не настроен.</strong>
                                Заполните все поля ниже. Значения будут сохранены в <code>data/.env.php</code>
                                (защищённый PHP-файл, не отдаётся веб-сервером).
                            </div>
                            <?php endif; ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="<?= $lbl ?>"><?= t('host') ?> <span class="text-red-500">*</span></label>
                                    <input type="text" name="db_host" class="<?= $inp ?>" value="<?= h($env_host) ?>" placeholder="localhost" required>
                                    <p class="text-xs text-slate-400 mt-1 m-0">Обычно <code>localhost</code> или <code>127.0.0.1</code></p>
                                </div>
                                <div>
                                    <label class="<?= $lbl ?>"><?= t('port') ?></label>
                                    <input type="text" name="db_port" class="<?= $inp ?>" value="<?= h($env_port) ?>" placeholder="3306">
                                    <p class="text-xs text-slate-400 mt-1 m-0">MySQL по умолчанию: <code>3306</code></p>
                                </div>
                            </div>
                            <div>
                                <label class="<?= $lbl ?>"><?= t('database') ?> <span class="text-red-500">*</span></label>
                                <input type="text" name="db_name" class="<?= $inp ?>" value="<?= h($env_db) ?>" placeholder="aicms2" required>
                                <p class="text-xs text-slate-400 mt-1 m-0">Имя БД. Должна существовать — создайте через phpMyAdmin: <code>CREATE DATABASE aicms2 CHARACTER SET utf8mb4;</code></p>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="<?= $lbl ?>">Пользователь MySQL <span class="text-red-500">*</span></label>
                                    <input type="text" name="db_user" class="<?= $inp ?>" value="<?= h($env_user) ?>" autocomplete="off" placeholder="aicms" required>
                                    <p class="text-xs text-slate-400 mt-1 m-0">Это <strong>не</strong> логин админки. Это MySQL-пользователь.</p>
                                </div>
                                <div>
                                    <label class="<?= $lbl ?>"><?= t('password') ?> <span id="pass_counter" class="ml-2 text-xs font-normal text-slate-400"><?= $env_pass_set ? '(сохранён)' : '' ?></span></label>
                                    <div class="relative">
                                        <input type="password" name="db_pass" id="db_pass_input" class="<?= $inp ?> pr-10" value="" autocomplete="new-password" placeholder="<?= $env_pass_set ? t('pass_saved_ph') : 'пароль MySQL-пользователя' ?>" style="font-family:monospace;" oninput="document.getElementById('pass_counter').textContent = this.value.length > 0 ? '(' + this.value.length + ' <?= t('chars') ?>)' : '<?= $env_pass_set ? '(сохранён)' : '' ?>';">
                                        <button type="button" onclick="var i=document.getElementById('db_pass_input'); i.type = i.type==='password' ? 'text' : 'password'; this.querySelector('i').className = i.type==='password' ? 'fas fa-eye' : 'fas fa-eye-slash';" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" title="<?= t('show_hide') ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div><label class="<?= $lbl ?>"><?= t('table_prefix') ?></label><input type="text" name="db_prefix" class="<?= $inp ?>" value="<?= h($env_prefix) ?>"></div>
                            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong><?= t('mysql_pass_hint') ?></strong>
                                <ul class="list-disc ml-5 mt-1 space-y-0.5">
                                    <li><?= t('xampp_hint') ?></li>
                                    <li><?= t('openserver_hint') ?></li>
                                    <li><?= t('uniserver_hint') ?></li>
                                    <li><?= t('denwer_hint') ?></li>
                                    <li><?= t('vps_hint') ?></li>
                                </ul>
                            </div>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-xs text-red-800">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <strong><?= t('access_denied_hint') ?></strong>
                                <p class="mt-1 mb-2"><?= t('caching_sha2_hint') ?></p>
                                <ol class="list-decimal ml-5 mt-1 space-y-1">
                                    <li><?= t('open_phpmyadmin') ?></li>
                                    <li><?= t('run_sql_hint') ?></li>
                                </ol>
                                <pre class="mt-2 p-2 bg-white rounded text-xs font-mono overflow-x-auto">CREATE USER 'aicms'@'localhost' IDENTIFIED WITH mysql_native_password BY 'aicms';
GRANT ALL PRIVILEGES ON aicms2.* TO 'aicms'@'localhost';
FLUSH PRIVILEGES;</pre>
                                <p class="mt-2"><?= t('fill_form_hint') ?></p>
                                <p class="mt-2 pt-2 border-t border-red-200">
                                    <?= t('alt_host_hint') ?>
                                </p>
                            </div>
                        </div>
                        <div id="sqlite_fields" class="space-y-3" style="<?= $sqlite_style ?>">
                            <div><label class="<?= $lbl ?>"><?= t('db_path_label') ?></label><input type="text" name="db_path" class="<?= $inp ?>" value="<?= h($config['storage']['path']??'data/aicms.db') ?>"></div>
                            <div><label class="<?= $lbl ?>"><?= t('table_prefix') ?></label><input type="text" name="db_prefix" class="<?= $inp ?>" value="<?= h($config['storage']['prefix']??'aicms_') ?>"></div>
                        </div>
                        <div class="mt-4 flex justify-between items-center gap-2">
                            <button type="button" id="testDbBtn" class="aicms-btn aicms-btn--secondary"><i class="fas fa-vial"></i> <?= t('test_connection') ?></button>
                            <button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-plug"></i> <?= t('save_and_connect') ?></button>
                        </div>
                        <div id="test_result" class="mt-3 hidden p-4 rounded-lg text-sm font-mono whitespace-pre-wrap break-words"></div>
                    </form>
                </div>
                <?php if ($db_connected): ?><div class="bg-white rounded-xl shadow-sm p-5"><form method="POST"><?= csrf_field() ?><input type="hidden" name="form_type" value="db_migrate"><button type="submit" class="px-4 py-2 border border-blue-300 text-blue-700 rounded-lg hover:bg-blue-50 text-sm"><i class="fas fa-sync mr-1"></i><?= t('create_tables') ?></button></form></div><?php endif; ?>
            </div>

            <!-- TAB: .HTACCESS -->
            <div x-show="tab==='htaccess'" x-cloak>
                <?php if (empty($htaccess_content)): ?>
                <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i><code>.htaccess</code> <?= t('htaccess_missing') ?></div>
                <div class="bg-white rounded-xl shadow-sm p-5 mb-4">
                    <form action="settings.php" method="POST"><?= csrf_field() ?><input type="hidden" name="form_type" value="htaccess_generate">
                        <h6 class="text-sm font-semibold text-slate-600 mb-3"><?= t('security_section') ?></h6>
                        <div class="grid grid-cols-2 gap-2 mb-4"><label class="flex items-center text-sm"><input type="checkbox" name="ht_deny_data" checked class="mr-2"> <?= t('block_data') ?></label><label class="flex items-center text-sm"><input type="checkbox" name="ht_hide_json" checked class="mr-2"> <?= t('hide_json') ?></label></div>
                        <h6 class="text-sm font-semibold text-slate-600 mb-3"><?= t('performance_section') ?></h6>
                        <div class="grid grid-cols-2 gap-2 mb-4"><label class="flex items-center text-sm"><input type="checkbox" name="ht_gzip" checked class="mr-2"> <?= t('gzip') ?></label><label class="flex items-center text-sm"><input type="checkbox" name="ht_cache" checked class="mr-2"> <?= t('caching') ?></label></div>
                        <h6 class="text-sm font-semibold text-slate-600 mb-3"><?= t('ssl_section') ?></h6>
                        <div class="grid grid-cols-2 gap-2 mb-4"><label class="flex items-center text-sm"><input type="checkbox" name="ht_ssl" class="mr-2"> <?= t('http_to_https') ?></label><label class="flex items-center text-sm"><input type="checkbox" name="ht_www" class="mr-2"> <?= t('www_to_nonwww') ?></label></div>
                        <div class="bg-amber-50 rounded-lg p-3 text-xs text-amber-700 mb-3"><i class="fas fa-exclamation-triangle mr-1"></i><?= t('htaccess_warn') ?></div>
                        <div class="text-right"><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm"><i class="fas fa-file-code mr-1"></i><?= t('create_htaccess') ?></button></div>
                    </form>
                </div>
                <?php else: ?>
                <div class="flex justify-between items-center mb-3"><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold"><i class="fas fa-check-circle"></i> <?= t('htaccess_exists') ?></span>
                <form method="POST" onsubmit="return confirm('<?= t('confirm_delete') ?>')"><?= csrf_field() ?><input type="hidden" name="form_type" value="htaccess_delete"><button class="px-3 py-1.5 border border-red-300 text-red-600 rounded-lg text-sm hover:bg-red-50"><i class="fas fa-trash mr-1"></i><?= t('delete') ?></button></form></div>
                <div class="bg-white rounded-xl shadow-sm p-5">
                    <form action="settings.php" method="POST"><?= csrf_field() ?><input type="hidden" name="form_type" value="htaccess_save">
                        <textarea name="htaccess_content" rows="20" class="w-full px-3 py-2 border border-slate-300 rounded-lg font-mono text-xs leading-relaxed"><?= h($htaccess_content) ?></textarea>
                        <div class="mt-3 text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> <?= t('save') ?></button></div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB: FEATURES -->
            <div x-show="tab==='features'" x-cloak>
                <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i><?= t('features_hint') ?></div>
                <div class="bg-white rounded-xl shadow-sm p-5">
                    <form method="POST" action="settings.php?tab=features"><?= csrf_field() ?><input type="hidden" name="form_type" value="features_save">
                    <?php
                    $feature_defs = [
                        '2fa' => ['label' => t('feature_2fa'), 'desc' => t('feature_2fa_desc'), 'icon' => 'fa-mobile-alt'],
                        'csp_headers' => ['label' => t('feature_csp'), 'desc' => t('feature_csp_desc'), 'icon' => 'fa-shield-alt'],
                        'webp_conversion' => ['label' => t('feature_webp'), 'desc' => t('feature_webp_desc'), 'icon' => 'fa-image'],
                        'ai_translation' => ['label' => t('feature_ai_translate'), 'desc' => t('feature_ai_translate_desc'), 'icon' => 'fa-language'],
                        'telegram_bot' => ['label' => t('feature_telegram'), 'desc' => t('feature_telegram_desc'), 'icon' => 'fa-paper-plane'],
                        'webhooks' => ['label' => t('feature_webhooks'), 'desc' => t('feature_webhooks_desc'), 'icon' => 'fa-webhook'],
                        'rest_api' => ['label' => t('feature_rest_api'), 'desc' => t('feature_rest_api_desc'), 'icon' => 'fa-code'],
                    ];
                    $current_features = $config['features'] ?? [];
                    ?>
                    <?php foreach ($feature_defs as $fkey => $fdef): ?>
                    <div class="flex items-start justify-between py-3 border-b border-slate-100 last:border-0">
                        <div class="flex items-start gap-3">
                            <i class="fas <?= $fdef['icon'] ?> text-slate-400 mt-1"></i>
                            <div>
                                <div class="font-medium text-sm text-slate-800"><?= $fdef['label'] ?></div>
                                <div class="text-xs text-slate-500 mt-0.5"><?= $fdef['desc'] ?></div>
                            </div>
                        </div>
                        <label class="aicms-toggle ml-4">
                            <input type="checkbox" name="features[<?= $fkey ?>]" value="1" <?= !empty($current_features[$fkey]) ? 'checked' : '' ?> aria-label="<?= h($fdef['label']) ?>">
                            <span class="aicms-toggle-track"></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <div class="mt-4 text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> <?= t('save') ?></button></div>
                    </form>

                    <?php if (!empty($current_features['webhooks']) || !empty($current_features['telegram_bot'])): ?>
                    <hr class="border-slate-200 my-4">
                    <h4 class="text-sm font-bold text-slate-700 mb-3"><?= t('integrations_section') ?></h4>
                    <form method="POST" action="settings.php?tab=features"><?= csrf_field() ?><input type="hidden" name="form_type" value="integrations_save">
                    <?php if (!empty($current_features['webhooks'])): ?>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Webhook URL</label><input type="url" name="webhook_url" class="<?= $inp ?>" value="<?= h($config['webhook_url'] ?? '') ?>" placeholder="https://hooks.slack.com/services/..."></div>
                    <?php endif; ?>
                    <?php if (!empty($current_features['telegram_bot'])): ?>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Telegram Bot Token</label><input type="text" name="telegram_bot_token" class="<?= $inp ?>" value="<?= h($config['telegram_bot_token'] ?? '') ?>" placeholder="123456:ABC-DEF..."></div>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Telegram Chat ID</label><input type="text" name="telegram_chat_id" class="<?= $inp ?>" value="<?= h($config['telegram_chat_id'] ?? '') ?>" placeholder="123456789"></div>
                    <?php endif; ?>
                    
                    <!-- SMTP / Email settings -->
                    <hr class="border-slate-200 my-4">
                    <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fas fa-envelope text-blue-500 mr-1"></i> SMTP / Email</h4>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Email отправителя</label><input type="email" name="site_email" class="<?= $inp ?>" value="<?= h($config['site_email'] ?? '') ?>" placeholder="noreply@example.com"></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">SMTP Host</label><input type="text" name="smtp_host" class="<?= $inp ?>" value="<?= h($config['smtp']['host'] ?? '') ?>" placeholder="smtp.gmail.com"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">SMTP Port</label><input type="number" name="smtp_port" class="<?= $inp ?>" value="<?= h($config['smtp']['port'] ?? 587) ?>" placeholder="587"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">SMTP Username</label><input type="text" name="smtp_username" class="<?= $inp ?>" value="<?= h($config['smtp']['username'] ?? '') ?>" placeholder="user@example.com"></div>
                        <div><label class="block text-sm font-medium text-slate-700 mb-1">SMTP Password</label><input type="password" name="smtp_password" class="<?= $inp ?>" value="<?= h($config['smtp']['password'] ?? '') ?>" placeholder="••••••••"></div>
                    </div>
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1">Шифрование</label>
                        <select name="smtp_security" class="<?= $inp ?>">
                            <option value="tls" <?= ($config['smtp']['security'] ?? 'tls')==='tls'?'selected':'' ?>>TLS (рекомендуется)</option>
                            <option value="ssl" <?= ($config['smtp']['security'] ?? '')==='ssl'?'selected':'' ?>>SSL</option>
                            <option value="none" <?= ($config['smtp']['security'] ?? '')==='none'?'selected':'' ?>>Без шифрования</option>
                        </select>
                    </div>
                    
                    <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> <?= t('save_integrations') ?></button></div>
                    </form>
                    <?php endif; ?>

                    <?php if (!empty($current_features['rest_api'])): ?>
                    <hr class="border-slate-200 my-4">
                    <h4 class="text-sm font-bold text-slate-700 mb-3"><?= t('feature_rest_api') ?></h4>
                    <form method="POST" action="settings.php?tab=features"><?= csrf_field() ?><input type="hidden" name="form_type" value="api_token_save">
                    <div class="mb-3"><label class="block text-sm font-medium text-slate-700 mb-1"><?= t('api_token') ?></label><input type="text" name="api_token" class="<?= $inp ?> font-mono" value="<?= h($config['api_token'] ?? '') ?>" placeholder="<?= t('api_token_ph') ?>"></div>
                    <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> <?= t('save_token') ?></button></div>
                    </form>
                    <p class="text-xs text-slate-400 mt-2"><?= t('api_endpoint_help') ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB: AI -->
            <div x-show="tab==='ai'" x-cloak>
                <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i>Настройки AI провайдера используются для <strong>AI SEO</strong>, <strong>AI перевода</strong> и <strong>редактора Lumina</strong>.</div>
                <div class="bg-white rounded-xl shadow-sm p-5">
                    <?php
                    $ai_cfg = $config['ai'] ?? [];
                    $ai_text = $ai_cfg['text'] ?? ['provider'=>'openrouter','model'=>'','api_key'=>''];
                    $ai_image = $ai_cfg['image'] ?? ['provider'=>'pollinations','model'=>'','api_key'=>''];
                    $ai_video = $ai_cfg['video'] ?? ['provider'=>'disabled','model'=>'','api_key'=>''];
                    ?>
                    <form method="POST" action="settings.php?tab=ai"><?= csrf_field() ?><input type="hidden" name="form_type" value="ai_settings_save">

                    <!-- TEXT AI (for SEO, Translation, Lumina) -->
                    <h4 class="text-sm font-bold text-slate-700 mb-3 pb-2 border-b border-slate-100"><i class="fas fa-font mr-1"></i> AI для текста (SEO, перевод, Lumina)</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Провайдер</label>
                            <select name="text_provider" class="<?= $inp ?>">
                                <option value="openrouter" <?= ($ai_text['provider']??'')==='openrouter'?'selected':'' ?>>OpenRouter (рекомендуется)</option>
                                <option value="deepseek" <?= ($ai_text['provider']??'')==='deepseek'?'selected':'' ?>>DeepSeek</option>
                                <option value="groq" <?= ($ai_text['provider']??'')==='groq'?'selected':'' ?>>Groq</option>
                                <option value="zai" <?= ($ai_text['provider']??'')==='zai'?'selected':'' ?>>Z.ai (GLM)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Модель</label>
                            <input type="text" name="text_model" class="<?= $inp ?>" value="<?= h($ai_text['model']??'') ?>" placeholder="mistralai/mistral-7b-instruct:free">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">API ключ</label>
                            <input type="text" name="text_api_key" class="<?= $inp ?> font-mono" value="<?= h($ai_text['api_key']??'') ?>" placeholder="sk-...">
                        </div>
                    </div>
                    <div class="bg-amber-50 rounded-lg p-3 mb-4 text-xs text-amber-800">
                        <strong>Важно:</strong> Для OpenRouter получите <strong>бесплатный API ключ</strong> на <a href="https://openrouter.ai/keys" target="_blank" class="underline font-semibold">openrouter.ai/keys</a>. Без ключа бесплатная модель может возвращать ошибку 403. Рекомендуемые бесплатные модели: <code class="bg-white px-1 rounded">mistralai/mistral-7b-instruct:free</code>, <code class="bg-white px-1 rounded">google/gemma-2-9b-it:free</code>, <code class="bg-white px-1 rounded">meta-llama/llama-3.1-8b-instruct:free</code>.
                    </div>

                    <!-- IMAGE AI -->
                    <h4 class="text-sm font-bold text-slate-700 mb-3 mt-6 pb-2 border-b border-slate-100"><i class="fas fa-image mr-1"></i> AI для изображений</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Провайдер</label>
                            <select name="image_provider" class="<?= $inp ?>">
                                <option value="pollinations" <?= ($ai_image['provider']??'')==='pollinations'?'selected':'' ?>>Pollinations (бесплатно)</option>
                                <option value="openrouter" <?= ($ai_image['provider']??'')==='openrouter'?'selected':'' ?>>OpenRouter</option>
                                <option value="disabled" <?= ($ai_image['provider']??'')==='disabled'?'selected':'' ?>>Отключено</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Модель</label>
                            <input type="text" name="image_model" class="<?= $inp ?>" value="<?= h($ai_image['model']??'') ?>" placeholder="flux">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">API ключ</label>
                            <input type="text" name="image_api_key" class="<?= $inp ?> font-mono" value="<?= h($ai_image['api_key']??'') ?>" placeholder="(не требуется для Pollinations)">
                        </div>
                    </div>

                    <!-- VIDEO AI -->
                    <h4 class="text-sm font-bold text-slate-700 mb-3 mt-6 pb-2 border-b border-slate-100"><i class="fas fa-video mr-1"></i> AI для видео</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Провайдер</label>
                            <select name="video_provider" class="<?= $inp ?>">
                                <option value="disabled" <?= ($ai_video['provider']??'')==='disabled'?'selected':'' ?>>Отключено</option>
                                <option value="openrouter" <?= ($ai_video['provider']??'')==='openrouter'?'selected':'' ?>>OpenRouter</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Модель</label>
                            <input type="text" name="video_model" class="<?= $inp ?>" value="<?= h($ai_video['model']??'') ?>" placeholder="">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">API ключ</label>
                            <input type="text" name="video_api_key" class="<?= $inp ?> font-mono" value="<?= h($ai_video['api_key']??'') ?>" placeholder="">
                        </div>
                    </div>

                    <div class="text-right mt-4">
                        <button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> Сохранить настройки AI</button>
                    </div>
                    </form>
                </div>
            </div>

            <!-- TAB: INTERFACE (stretch button animation speed) -->
            <div x-show="tab==='interface'" x-cloak>
                <div class="bg-blue-50 rounded-lg p-4 mb-4 text-sm text-blue-700"><i class="fas fa-info-circle mr-1"></i>Скорость анимации «резиновых» кнопок админ-панели — раскрытие при наведении/фокусе и сворачивание при потере фокуса.</div>
                <div class="bg-white rounded-xl shadow-sm p-5">
                    <form method="POST" action="settings.php?tab=interface"><?= csrf_field() ?><input type="hidden" name="form_type" value="interface_save">
                        <h5 class="text-base font-semibold text-slate-800 mb-4"><i class="fas fa-sliders-h mr-1 text-blue-600"></i>Интерфейс</h5>
                        <div class="mb-4">
                            <label for="stretch_speed_range" class="block text-sm font-medium text-slate-700 mb-2">Скорость анимации кнопок</label>
                            <input type="range" name="stretch_speed" id="stretch_speed_range" min="200" max="2000" step="50" value="<?= (int)($config['ui']['stretch_speed'] ?? 700) ?>" class="w-full" oninput="document.getElementById('stretch_speed_val').textContent = this.value + ' ms'">
                            <div class="flex justify-between text-xs text-slate-400 mt-1"><span>200 ms · быстро</span><span>700 ms · по умолчанию</span><span>2000 ms · медленно</span></div>
                            <div class="flex items-center gap-3 mt-2 flex-wrap">
                                <p class="text-sm text-slate-600 m-0">Текущая скорость: <strong id="stretch_speed_val" class="text-blue-600"><?= (int)($config['ui']['stretch_speed'] ?? 700) ?> ms</strong></p>
                                <button type="button" id="stretchDemoBtn" class="aicms-icon-btn aicms-icon-btn--primary" data-stretch-independent="1" aria-label="Демо скорости анимации"><i class="fas fa-arrows-alt-h"></i><span class="btn-label">Демо</span></button>
                                <span class="text-xs text-slate-400">наведите на кнопку — эффект с текущей скоростью</span>
                            </div>
                        </div>
                        <div class="text-right"><button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> Сохранить</button></div>
                    </form>
                    <script>
                    (function () {
                        var slider = document.getElementById('stretch_speed_range');
                        var demo = document.getElementById('stretchDemoBtn');
                        if (!slider || !demo) return;
                        // prefers-reduced-motion: демо не анимируется — на кнопке
                        // ставится 0.01s (собственная inline-переменная перекрывает
                        // наследуемое значение :root даже с !important, поэтому её
                        // нужно выставить явно, а не полагаться на каскад).
                        var reducedMotion = window.matchMedia &&
                            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        function applyDemoSpeed() {
                            var ms = parseInt(slider.value, 10) || 700;
                            // Своя inline-переменная на кнопке перекрывает html-уровневую
                            // рандомизацию координатора — демо всегда анимируется ровно
                            // на скорости, выставленной на слайдере (до сохранения).
                            demo.style.setProperty('--aicms-stretch-duration', (reducedMotion ? 0.01 : ms / 1000) + 's');
                        }
                        slider.addEventListener('input', applyDemoSpeed);
                        applyDemoSpeed();
                    })();
                    </script>
                </div>
            </div>

            <!-- TAB: SECURITY & MAINTENANCE -->
            <div x-show="tab==='security'" x-cloak>
                <div class="bg-amber-50 rounded-lg p-4 mb-4 text-sm text-amber-800 border border-amber-200">
                    <i class="fas fa-shield-alt mr-1"></i>
                    <strong>Безопасность и обслуживание.</strong>
                    Настройки защиты сайта: контроль доверенных прокси, ограничение IP для входа в админку, отчёты CSP и режим обслуживания.
                </div>
                <?php
                $sec = $config['security'] ?? [];
                $maint = $config['maintenance'] ?? [];
                $kill_file = __DIR__ . '/../data/.maintenance';
                $kill_switch_on = file_exists($kill_file);
                $trusted_proxies_text = implode("\n", $sec['trusted_proxies'] ?? []);
                $admin_ip_allowlist_text = implode("\n", $sec['admin_ip_allowlist'] ?? []);
                ?>
                <div class="bg-white rounded-xl shadow-sm p-5 mb-4">
                    <form method="POST" action="settings.php?tab=security"><?= csrf_field() ?><input type="hidden" name="form_type" value="security_save">
                        <h5 class="text-base font-semibold text-slate-800 mb-4"><i class="fas fa-network-wired mr-1 text-blue-600"></i>Сетевая безопасность</h5>
                        <div class="mb-4">
                            <label class="<?= $lbl ?>">Доверенные прокси (по одному на строку, IP или CIDR)</label>
                            <textarea name="trusted_proxies" rows="3" class="<?= $inp ?> font-mono text-sm" placeholder="например: 10.0.0.1&#10;192.168.0.0/16"><?= h($trusted_proxies_text) ?></textarea>
                            <p class="text-xs text-slate-500 mt-1">Если сайт за обратным прокси (Cloudflare, Nginx, Docker) — укажите IP прокси, чтобы корректно определять реальный IP посетителя по <code>X-Forwarded-For</code> для rate limit и логов.</p>
                        </div>
                        <div class="mb-4">
                            <label class="<?= $lbl ?>">Белый список IP для входа в админку (по одному на строку)</label>
                            <textarea name="admin_ip_allowlist" rows="3" class="<?= $inp ?> font-mono text-sm" placeholder="оставьте пустым — разрешён любой IP"><?= h($admin_ip_allowlist_text) ?></textarea>
                            <p class="text-xs text-slate-500 mt-1">Ограничивает доступ к <code>/admin/login.php</code> только указанным IP-адресам. Пусто = без ограничений.</p>
                        </div>
                        <div class="mb-4">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="csp_report" value="1" <?= !empty($sec['csp_report']) ? 'checked' : '' ?> class="w-4 h-4 rounded">
                                <span class="text-sm font-medium text-slate-700">Включить сбор отчётов CSP (Content-Security-Policy)</span>
                            </label>
                            <p class="text-xs text-slate-500 mt-1 ml-6">Логировать нарушения CSP в <code>logs/csp_violations.log</code>. Помогает обнаружить XSS-атtempts и настроить корректную CSP для используемых тем.</p>
                        </div>
                        <div class="text-right">
                            <button type="submit" class="aicms-btn aicms-btn--success"><i class="fas fa-save"></i> Сохранить</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-5">
                    <h5 class="text-base font-semibold text-slate-800 mb-4"><i class="fas fa-tools mr-1 text-amber-600"></i>Режим обслуживания</h5>
                    <div class="bg-slate-50 rounded-lg p-4 mb-4 text-sm text-slate-700">
                        <p class="m-0 mb-2"><strong>Как это работает:</strong></p>
                        <ul class="list-disc ml-5 space-y-1 text-xs">
                            <li>Анонимные посетители видят страницу 503 (Service Unavailable) с вашим сообщением.</li>
                            <li>Авторизованные администраторы и пользователи сайта <strong>обходят</strong> режим обслуживания — могут видеть и тестировать сайт.</li>
                            <li>Поисковые роботы получают заголовок <code>Retry-After</code> и HTTP 503 (сайт не индексируется).</li>
                            <li>«Тумблер убил-всё» — отдельная кнопка, создаёт файл <code>data/.maintenance</code> для экстренного включения без БД.</li>
                        </ul>
                    </div>
                    <form method="POST" action="settings.php?tab=security"><?= csrf_field() ?><input type="hidden" name="form_type" value="security_save">
                        <div class="mb-4">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="maintenance_enabled" value="1" <?= !empty($maint['enabled']) ? 'checked' : '' ?> class="w-4 h-4 rounded">
                                <span class="text-sm font-medium text-slate-700">Включить режим обслуживания (показывать 503 анонимам)</span>
                            </label>
                        </div>
                        <div class="mb-4">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="maintenance_kill_switch" value="1" <?= $kill_switch_on ? 'checked' : '' ?> class="w-4 h-4 rounded">
                                <span class="text-sm font-medium text-slate-700">«Тумблер убил-всё» (файл data/.maintenance)</span>
                            </label>
                            <p class="text-xs text-slate-500 mt-1 ml-6">Файловый kill-switch — срабатывает даже если config.json повреждён.</p>
                        </div>
                        <div class="mb-4">
                            <label class="<?= $lbl ?>" for="maintenance_message">Сообщение посетителям</label>
                            <textarea name="maintenance_message" id="maintenance_message" rows="3" class="<?= $inp ?>" placeholder="Сайт временно на техническом обслуживании. Скоро вернёмся!"><?= h($maint['message'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="<?= $lbl ?>" for="maintenance_retry_after">Retry-After (секунды)</label>
                            <input type="number" name="maintenance_retry_after" id="maintenance_retry_after" min="30" max="86400" value="<?= (int)($maint['retry_after'] ?? 300) ?>" class="<?= $inp ?>">
                            <p class="text-xs text-slate-500 mt-1">Через сколько секунд поисковые роботы и браузеры должны повторить запрос (30–86400).</p>
                        </div>
                        <div class="text-right">
                            <button type="submit" class="aicms-btn aicms-btn--warning"><i class="fas fa-tools"></i> Сохранить настройки обслуживания</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>
<script src="assets/js/aicms-ui.js?v=20260812"></script>
<script>
// Database tab — toggle MySQL/SQLite fields visibility without Alpine x-show
(function() {
    var sel = document.querySelector('select[name="db_driver"]');
    if (!sel) return;
    var mysql = document.getElementById('mysql_fields');
    var sqlite = document.getElementById('sqlite_fields');
    function update() {
        if (sel.value === 'mysql') {
            if (mysql) mysql.style.display = '';
            if (sqlite) sqlite.style.display = 'none';
        } else if (sel.value === 'sqlite') {
            if (mysql) mysql.style.display = 'none';
            if (sqlite) sqlite.style.display = '';
        } else {
            if (mysql) mysql.style.display = 'none';
            if (sqlite) sqlite.style.display = 'none';
        }
    }
    sel.addEventListener('change', update);
    update();

    // Update password counter on load
    var passInput = document.getElementById('db_pass_input');
    var counter = document.getElementById('pass_counter');
    if (passInput && counter) {
        counter.textContent = passInput.value.length > 0 ? '(' + passInput.value.length + ' <?= t('chars') ?>)' : '';
    }

    // AJAX test connection
    var testBtn = document.getElementById('testDbBtn');
    var testResult = document.getElementById('test_result');
    if (testBtn) {
        testBtn.addEventListener('click', function() {
            var form = document.getElementById('dbSettingsForm');
            var formData = new FormData(form);
            // Explicitly add password (in case browser strips it from FormData)
            var passInput = document.getElementById('db_pass_input');
            if (passInput) {
                formData.set('db_pass', passInput.value);
            }
            // Also add CSRF token explicitly
            var csrfInput = form.querySelector('input[name="csrf_token"]');
            if (csrfInput) formData.set('csrf_token', csrfInput.value);

            testResult.className = 'mt-3 p-4 rounded-lg text-sm font-mono whitespace-pre-wrap break-words bg-blue-50 text-blue-800';
            testResult.textContent = '<?= t('testing_connection') ?>';
            testBtn.disabled = true;
            testBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= t('test_btn') ?>...';

            fetch('test_db_connection.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) {
                // Defensive: if response is not JSON (e.g. PHP error returned HTML),
                // show the raw text so the user sees the actual error instead of
                // a confusing "Unexpected token '<'" JSON parse failure.
                var ct = r.headers.get('Content-Type') || '';
                if (ct.indexOf('application/json') === -1) {
                    return r.text().then(function(txt) {
                        throw new Error('Server returned non-JSON response (' + ct + '):\n\n' + txt.substring(0, 800));
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (data.error) {
                    throw new Error(data.error);
                }
                var html = '<?= t('what_server_got') ?>\n';
                html += 'Driver: ' + data.received.driver + '\n';
                html += 'Host: ' + data.received.host + '\n';
                html += 'Port: ' + data.received.port + '\n';
                html += 'Database: ' + data.received.database + '\n';
                html += 'Username: ' + data.received.username + '\n';
                html += 'Password length: ' + data.received.password_length + '\n';
                html += 'All POST keys: ' + JSON.stringify(data.received.all_post_keys) + '\n\n';
                html += '<?= t('php_extensions') ?>\n';
                html += 'pdo_mysql: ' + data.extension_check.pdo_mysql + '\n';
                html += 'pdo_sqlite: ' + data.extension_check.pdo_sqlite + '\n\n';
                html += '<?= t('result_label') ?>\n';
                html += data.result;
                testResult.textContent = html;
                if (data.connected) {
                    testResult.className = 'mt-3 p-4 rounded-lg text-sm font-mono whitespace-pre-wrap break-words bg-green-50 text-green-800 border border-green-200';
                } else {
                    testResult.className = 'mt-3 p-4 rounded-lg text-sm font-mono whitespace-pre-wrap break-words bg-red-50 text-red-800 border border-red-200';
                }
            })
            .catch(function(err) {
                testResult.className = 'mt-3 p-4 rounded-lg text-sm font-mono whitespace-pre-wrap break-words bg-red-50 text-red-800';
                testResult.textContent = '<?= str_replace('{$message}', "' + err.message + '", t('ajax_error')) ?>';
            })
            .finally(function() {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="fas fa-vial"></i> <?= t('test_connection') ?>';
            });
        });
    }

    // Form submit — show alert with password length for debugging
    var form = document.getElementById('dbSettingsForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var passInput = document.getElementById('db_pass_input');
            var passLen = passInput ? passInput.value.length : -1;
            // Show what we're about to send
            if (sel.value === 'mysql' && passLen === 0) {
                if (!confirm(<?= json_encode(t('empty_pass_warn')) ?>)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
})();
</script>
</body>
</html>
