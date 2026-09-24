<?php
/**
 * AICMS Environment Secrets Loader
 * =================================
 * Секреты (API-ключи, креды БД) хранятся в data/.env.php — PHP-файле, который:
 *   - ничего не выводит при прямом обращении (include возвращает массив);
 *   - исключён из git (.gitignore), поэтому не попадает в репозиторий;
 *   - защищён от раздачи через .htaccess (data/ закрыта целиком).
 *
 * Значения из .env.php ПЕРЕКРЫВАЮТ одноимённые настройки data/config.json
 * при каждой загрузке конфига через aicms_apply_env().
 *
 * Использование:
 *   $config = aicms_apply_env(read_json('data/config.json'));
 *   $secret = aicms_env()['OPENROUTER_API_KEY'] ?? '';
 *   aicms_env_set('OPENROUTER_API_KEY', 'новый-ключ'); // при сохранении настроек
 */

if (!function_exists('aicms_env_file')) {
    function aicms_env_file() {
        return __DIR__ . '/../../data/.env.php';
    }
}

if (!function_exists('aicms_env')) {
    /**
     * Вернуть массив секретов из data/.env.php (пустой массив, если файла нет).
     * @param bool $reload принудительно перечитать файл (после aicms_env_set).
     */
    function aicms_env($reload = false) {
        if ($reload || !isset($GLOBALS['_aicms_env'])) {
            $env = [];
            $file = aicms_env_file();
            if (file_exists($file)) {
                $loaded = @include $file;
                if (is_array($loaded)) $env = $loaded;
            }
            $GLOBALS['_aicms_env'] = $env;
        }
        return $GLOBALS['_aicms_env'];
    }
}

if (!function_exists('aicms_provider_env_key')) {
    /**
     * Сопоставить имя AI-провайдера с ключом .env.php, где лежит его API-ключ.
     */
    function aicms_provider_env_key($provider) {
        $map = [
            'openrouter' => 'OPENROUTER_API_KEY',
            'deepseek'   => 'DEEPSEEK_API_KEY',
            'gemini'     => 'GEMINI_API_KEY',
            'google'     => 'GEMINI_API_KEY',
            'groq'       => 'GROQ_API_KEY',
            'openai'     => 'OPENAI_API_KEY',
            'anthropic'  => 'ANTHROPIC_API_KEY',
            'mistral'    => 'MISTRAL_API_KEY',
            'together'   => 'TOGETHER_API_KEY',
            'zai'        => 'ZAI_API_KEY',
        ];
        return $map[$provider] ?? null;
    }
}

if (!function_exists('aicms_apply_env')) {
    /**
     * Наложить секреты из .env.php на конфиг (значения env имеют приоритет).
     * Покрывает:
     *   - config.storage.*  (host/port/database/username/password/prefix)
     *   - config.ai.text/image/video.api_key — по провайдеру
     *   - legacy ключи config.openrouter_api_key / deepseek_api_key / gemini_api_key
     *     (их по-прежнему читают api_generate_content.php и api_ai_image.php)
     *
     * @param array $config массив конфига (data/config.json)
     * @return array конфиг с подставленными секретами
     */
    function aicms_apply_env($config) {
        if (!is_array($config)) $config = [];
        $env = aicms_env();
        if (!$env) return $config;

        // --- DB storage ---
        if (isset($config['storage']) && is_array($config['storage'])) {
            $map = [
                'DB_HOST'   => 'host',
                'DB_PORT'   => 'port',
                'DB_NAME'   => 'database',
                'DB_USER'   => 'username',
                'DB_PASS'   => 'password',
                'DB_PREFIX' => 'prefix',
            ];
            foreach ($map as $ek => $ck) {
                if (isset($env[$ek]) && $env[$ek] !== '') $config['storage'][$ck] = $env[$ek];
            }
        }

        // --- AI keys (text / image / video) по провайдеру ---
        foreach (['text', 'image', 'video'] as $section) {
            $provider = $config['ai'][$section]['provider'] ?? '';
            $ek = aicms_provider_env_key($provider);
            if ($ek && !empty($env[$ek])) {
                $config['ai'][$section]['api_key'] = $env[$ek];
            }
        }

        // --- Legacy top-level keys (backward compat) ---
        if (!empty($env['OPENROUTER_API_KEY'])) $config['openrouter_api_key'] = $env['OPENROUTER_API_KEY'];
        if (!empty($env['DEEPSEEK_API_KEY']))   $config['deepseek_api_key']   = $env['DEEPSEEK_API_KEY'];
        if (!empty($env['GEMINI_API_KEY']))     $config['gemini_api_key']     = $env['GEMINI_API_KEY'];

        return $config;
    }
}

if (!function_exists('aicms_env_strip')) {
    /**
     * Убрать из конфига значения, которыми управляет .env.php, перед записью
     * в data/config.json. Без этого settings.php при любом сохранении записал бы
     * env-секреты обратно в config.json, сведя на нет весь смысл выноса.
     *
     * Поля, которыми управляет env, заменяются на пустые строки; при следующей
     * загрузке aicms_apply_env() подставит их снова.
     *
     * @param array $config
     * @return array
     */
    function aicms_env_strip($config) {
        if (!is_array($config)) return $config;
        $env = aicms_env();
        if (!$env) return $config;

        // --- DB storage ---
        $map = [
            'DB_HOST'   => 'host',
            'DB_PORT'   => 'port',
            'DB_NAME'   => 'database',
            'DB_USER'   => 'username',
            'DB_PASS'   => 'password',
            'DB_PREFIX' => 'prefix',
        ];
        if (isset($config['storage']) && is_array($config['storage'])) {
            foreach ($map as $ek => $ck) {
                if (isset($env[$ek]) && $env[$ek] !== '') $config['storage'][$ck] = '';
            }
        }

        // --- AI keys по провайдеру ---
        foreach (['text', 'image', 'video'] as $section) {
            $provider = $config['ai'][$section]['provider'] ?? '';
            $ek = aicms_provider_env_key($provider);
            if ($ek && !empty($env[$ek])) $config['ai'][$section]['api_key'] = '';
        }

        // --- Legacy top-level keys ---
        foreach (['openrouter_api_key' => 'OPENROUTER_API_KEY', 'deepseek_api_key' => 'DEEPSEEK_API_KEY', 'gemini_api_key' => 'GEMINI_API_KEY'] as $ck => $ek) {
            if (!empty($env[$ek])) $config[$ck] = '';
        }

        return $config;
    }
}

if (!function_exists('aicms_env_set')) {
    /**
     * Записать (или очистить) один секрет в data/.env.php, сохранив остальные ключи.
     * Используется при сохранении настроек, чтобы секреты не попадали в config.json.
     * Если значение не изменилось — файл не перезаписывается (сохраняются комментарии).
     *
     * @param string $key    ключ секрета (например 'OPENROUTER_API_KEY' или 'DB_PASS')
     * @param string $value  значение; пустая строка удаляет ключ
     * @return bool
     */
    function aicms_env_set($key, $value) {
        $file = aicms_env_file();
        $env = aicms_env(true);

        $changed = false;
        if ($value === '' || $value === null) {
            if (array_key_exists($key, $env)) { unset($env[$key]); $changed = true; }
        } else {
            if (($env[$key] ?? null) !== $value) { $env[$key] = $value; $changed = true; }
        }
        if (!$changed) return true;

        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        // Keep the rotation warning alive across rewrites if the compromised key is still in use
        $rotation_note = '';
        if (!empty($env['OPENROUTER_API_KEY']) && $env['OPENROUTER_API_KEY'] === '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') {
            $rotation_note = " * ⚠️ ROTATE ME: этот OpenRouter-ключ был скомпрометирован — замените на новый (openrouter.ai/keys).\n";
        }
        $header = "<?php\n/**\n * AICMS Environment Secrets\n * Секреты приложения. Значения перекрывают data/config.json.\n * НЕ коммитить в git, не отдавать третьим лицам.\n" . $rotation_note . " */\nreturn ";
        $ok = @file_put_contents($file, $header . var_export($env, true) . ";\n", LOCK_EX);
        aicms_env(true); // обновить кэш
        return $ok !== false;
    }
}
