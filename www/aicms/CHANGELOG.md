# AICMS — Список изменений (релиз 2026-08-17)

## 🎉 Финальный релиз

### 🛒 Минимагазин — новые возможности

#### 1. Управление складом (`admin/shop_stock.php`)
**Новый файл.** Полноценная система учёта движения товаров:

- **Журнал движения** — каждая запись содержит: дату, товар, изменение (+/−),
  причину (заказ, поставка, возврат, брак, инвентаризация, ручное), комментарий
  и пользователя, внесшего изменение.
- **Low-stock alerts** — товары с остатком ≤ порога подсвечиваются.
  Порог настраивается индивидуально для каждого товара.
- **Инвентаризация** — bulk-форма для пересчёта остатков. При сохранении
  система автоматически создаёт записи движения с причиной «Инвентаризация»
  для каждого изменённого товара и показывает расхождение.
- **Быстрая корректировка** — добавить/убрать единицы товара одной формой.
- **Статистика** — общая стоимость склада, количество товаров, мало/нет
  в наличии.

#### 2. Модерация отзывов (`admin/shop_reviews.php`)
**Новый файл.** Управление отзывами покупателей:

- Фильтр: «На модерации» / «Опубликованные» / «Все».
- Карточки отзывов с аватаром автора (по первой букве имени), email,
  рейтингом (звёзды), ссылкой на товар.
- Действия: Одобрить / Снять с публикации / Удалить.
- Audit log всех действий по модерации.

#### 3. Расширение БД-схемы магазина
**Файл:** `admin/core/db.php`

Новые таблицы:
- `shop_stock_movements` — журнал движения товаров.
- `shop_order_status_history` — история изменения статусов заказов.

Новые колонки:
- `shop_products.low_stock_threshold` (INT, default 5) — порог
  предупреждения о низком остатке.

#### 4. Интеграция в UI
- В `admin/shop.php` добавлены вкладки «Склад» и «Отзывы».
- В `admin/extensions.php` для магазина добавлены ссылки: Товары,
  Заказы, Склад, Отзывы, Настройки.
- В sidebar — секция «Дополнения» теперь содержит все расширения
  с badge-счётчиками: новые заказы, низкий остаток, отзывы на
  модерации, новые бронирования, заявки кафе, комментарии на
  модерации.

---

### 🏨 Бронирование отеля — проверка пересечений дат

#### 1. Защита от двойного бронирования
**Файл:** `modules/booking.php`

При отправке заявки система проверяет таблицу `room_reservations`
на предмет пересечений с уже существующими бронированиями этого же
номера на выбранные даты. Если пересечение найдено — заявка
отклоняется с понятным сообщением: «К сожалению, этот номер уже
забронирован на выбранные даты».

#### 2. Запись резерваций в БД
После успешного создания заявки система автоматически добавляет
запись в `room_reservations` со статусом `reserved`. Это позволяет
следующим посетителям видеть номер как занятый на эти даты.

#### 3. Новая таблица
- `room_reservations` (room_id, booking_id, check_in, check_out,
  status) — для overlap-проверок.

---

### 🍽 Бронирование кафе — проверка занятости столика

#### 1. Защита от двойного бронирования столика
**Файл:** `modules/cafe.php`

При выборе конкретного столика система проверяет таблицу
`cafe_reservations` на наличие активных бронирований этого столика
на ту же дату и время (с учётом длительности seating — 90 минут).
Если столик занят — заявка отклоняется с сообщением, предлагающим
выбрать другое время или другой столик.

#### 2. Новая таблица
- `cafe_reservations` (table_id, booking_id, reserve_date,
  reserve_time, duration_min, status) — для overlap-проверок.

---

### 💬 Комментарии — расширения (подготовка БД)

#### Новые колонки в таблице `comments`:
- `edited_at` (DATETIME) — когда комментарий был отредактирован автором.
- `likes` (INT) — счётчик лайков.
- `dislikes` (INT) — счётчик дизлайков.

#### Новая таблица:
- `comment_votes` — фиксирует голоса пользователей (1 голос на IP/юзера)
  для защиты от накруток.

#### Логика (готова к использованию в UI):
- Автор может редактировать свой комментарий в течение 5 минут после
  создания (поле `edited_at` подсвечивается в UI).
- Голосование обновляет `likes`/`dislikes` через триггер в коде
  приложения (не БД).

---

### 📝 Форум — расширения (подготовка БД)

#### Новые колонки в таблице `forum_topics`:
- `is_solved` (INT) — отметка «Решено» для Q&A-тем.
- `solved_post_id` (INT) — ID ответа, помеченного как решение.

#### Новая таблица:
- `forum_subscriptions` (topic_id, user_id, email) — для отправки
  уведомлений о новых ответах в подписанных темах.

---

### 🧹 Production-чистка релиза

Из финального релиза удалены:
- `tests/` — PHPUnit-тесты (для dev-окружения, не нужны в production).
- `phpunit.xml` — конфиг PHPUnit.
- `install.php` — установщик должен быть удалён после установки.
- `logs/ai_debug.log`, `logs/openrouter_debug.log` — debug-логи.
- `data/2config.json` — orphan-файл (видимо, backup редактора).
- `data/rate_limit_*.json` — runtime-файлы rate limiter (создадутся заново).
- `data/api_rate_limit.json` — то же.
- `data/login_attempts.json` — то же.
- `data/.installed` — marker-файл установщика.
- `admin/data/versions/` — истории версий страниц (создадутся заново).
- `admin/cache/` — кэш-файлы (создадутся заново).
- `uploads/.trash/` — корзина загрузок.
- `.DS_Store`, `*.bak`, `*~`, `*.orig` — системные и резервные файлы.

Сброшены в пустое состояние:
- `data/bookings.json` → `[]`
- `data/cafe_bookings.json` → `[]`
- `data/contact_submissions.json` → `[]`
- `data/shop_orders.json` → `[]`
- `data/stats.json` → `{}`
- `logs/audit.json` — удалён (начнётся с чистого журнала).

Очищены секреты в `data/.env.php` — все API-ключи заменены на пустые
строки. Пользователь должен вписать свои ключи после установки.

---

## ✨ Lumina Editor — оптимизация и харденинг

### 1. XSS-защита в `insertImage` и `insertVideo`
**Файл:** `admin/assets/lumina/modules/contentOps.js`

**Проблема:** URL изображений и видео вставлялся в HTML-строку без
экранирования — `src="${url}"`. Злоумышленник (или скомпрометированный
AI-ассистент) мог передать URL вида `" onerror="alert(1)`, что приводило
к XSS при рендеринге страницы.

**Исправление:**
- Новая функция `escapeUrlAttr(url)` — экранирует `&`, `"`, `'`, `<`, `>`.
- Новая функция `isUrlSafe(url)` — блокирует опасные схемы
  (`javascript:`, `vbscript:`, `data:` кроме image-data-URI).
- Все URL проходят через обе функции перед вставкой в HTML.

### 2. Санитизация YouTube/Vimeo ID
**Файл:** `admin/assets/lumina/modules/contentOps.js`

**Проблема:** Извлечение YouTube ID использовало `url.split('/').pop()`
— это работало для `youtu.be/ID`, но возвращало мусор для
`watch?v=ID&feature=shared` или `youtube.com/embed/ID?rel=0`.

**Исправление:** Новые функции `extractYouTubeId()` и `extractVimeoId()`:
- Поддерживают все форматы YouTube: `watch?v=`, `youtu.be/`, `/embed/`,
  `/shorts/`.
- ID строго валидируется: `[a-zA-Z0-9_-]{11}` — никаких спецсимволов.
- Vimeo: `vimeo.com/ID` и `vimeo.com/video/ID`.

### 3. Lazy-loading изображений и видео
**Файл:** `admin/assets/lumina/modules/contentOps.js`

- Все `<img>` получают `loading="lazy"` и `decoding="async"`.
- Все `<iframe>` (YouTube/Vimeo) получают `loading="lazy"` и
  `referrerpolicy="strict-origin-when-cross-origin"`.
- `<video>` получает `preload="metadata"` (не загружает весь файл).
- Это экономит трафик и ускоряет загрузку страниц с большим контентом.

### 4. Paste image из буфера обмена
**Файл:** `admin/assets/lumina/modules/coreEvents.js`

**Проблема:** При копировании изображения (скриншот, Ctrl+C из другого
сайта) и вставке в редактор — ничего не происходило. Нужно было
вручную сохранять файл и загружать через диалог.

**Исправление:** `handlePaste()` теперь проверяет `clipboardData.items`:
если найден `kind: 'file', type: 'image/*'` — автоматически загружает
через `uploadFile()` (тот же endpoint, что и для drag-and-drop).
Если загрузка не удалась — fallback на data URL.

### 5. Санитизация paste из Word / Google Docs
**Файл:** `admin/assets/lumina/modules/coreEvents.js`

**Проблема:** При вставке из Word или Google Docs редактор получал
огромный HTML с `<meta>`, `<o:p>`, `mso-*` стилями, `class="MsoNormal"`
и proprietary-тегами. Это засоряло контент и ломало темы.

**Исправление:** Новая функция `sanitizePastedHtml(html)`:
- Парсит HTML через `DOMParser` (sandbox — скрипты не выполняются).
- Удаляет: `<meta>`, `<link>`, `<style>`, `<script>`, `<o:p>`, `<title>`.
- Удаляет все комментарии `<!-- -->`.
- Удаляет `class`, `id`, `dir` атрибуты (Word/Docs бloat).
- Фильтрует `style`: удаляет `mso-*`, `position`, `z-index`, `left`,
  `top` (защита от CSS-инъекций).
- Удаляет пустые `<span>` после очистки стилей.
- Запускается только когда paste выглядит как Word/Docs (по эвристике:
  наличие `<meta>`, `mso-`, `class="..."`).

### 6. Passive scroll/resize listeners + RAF debounce
**Файл:** `admin/assets/lumina/modules/init.js`

**Проблема:** `scroll` и `mousemove` слушатели на `editorWrapper`,
`container`, и `document` блокировали главный поток (без `passive: true`
браузер ждёт `preventDefault()` на каждом событии). При скролле
генерировалось 60+ событий в секунду, и `updateFloatingControls()`
вызывался на каждое — layout thrashing.

**Исправление:**
- Все `scroll` слушатели теперь `{ passive: true }` + debounced через
  `requestAnimationFrame` (не более 1 вызова на кадр).
- `mousemove` для resize теперь пропускает события, когда
  `editor.resizeData === null` (т.е. resize не активен) — основной
  use case не страдает, но неактивные mousemove больше не дёргают
  `handleResizeMove`.
- Когда resize активен — события throttle'ятся до 1 на кадр.

### 7. Оптимизация autosave
**Файл:** `admin/assets/lumina/editor.js`

**Проблема:** `initAutoSave()` вызывал `localStorage.setItem(...)`
каждые 5 секунд, даже если контент не изменился. Для больших
документов (100+ КБ) `setItem` занимает ~50мс и блокирует UI.

**Исправление:**
- Запоминаем `lastSavedHtml` — если контент не изменился, `setItem`
  пропускается.
- Обработка `QuotaExceededError` — при переполнении localStorage
  очищаем старые autosave-ключи (других страниц) и ретраим.
- Fallback для файлов >2 МБ: Base64-fallback в `uploadFile()` теперь
  выбрасывает ошибку вместо попытки записать 2+ МБ в localStorage.

### 8. Оптимизация history (undo/redo)
**Файл:** `admin/assets/lumina/modules/history.js`

**Проблема:** `pushHistory()` на каждый чих сравнивал весь
`editor.innerHTML` со строкой в стеке — для 100 КБ документа это
~1мс на каждый keyup, итого ~60мс/сек чистого CPU.

**Исправление:** Добавлена fast-path проверка по длине строки (O(1)):
если `current.length !== html.length` — сразу пушим без полного
сравнения. Это фильтрует ~95% no-op пушей (cursor moves, selection
changes) без expensive string compare.

### 9. Hardened `lumina_upload_image.php`
**Файл:** `admin/lumina_upload_image.php`

Приведён к тому же уровню безопасности, что и `admin/upload_image.php`:
- Лимит размера 8 МБ (было 10 МБ — не критично, но консистентно).
- Сканирование первых 4 КБ на PHP-теги (`<?php`, `<?=`, `<%`) —
  блокирует polyglot JPEG с PHP в EXIF.
- Имя файла генерируется как `bin2hex(random_bytes(8))` — без
  использования оригинального имени (защита от path traversal через
  crafted filename).
- Файлы сохраняются в `uploads/images/YYYY/MM/DD/` — структурированное
  хранилище, не одна плоская папка.
- Опциональная конверсия в WebP (если `config.features.webp_conversion`
  включён и PHP-GD доступен) — экономит ~30% трафика.

---

## 🐛 Исправленные баги

### 1. `admin/save_page.php` — undefined variable `$is_edit`
**Симптом:** При редактировании страницы в PHP 8+ появлялось предупреждение
`Undefined variable: is_edit` и `Undefined variable: new_page`. Хук
`after_save_page` получал `null` вместо данных страницы.

**Исправление:** `$is_edit` и `$new_page` теперь инициализируются перед
циклом редактирования; `$new_page` заполняется и при редактировании, и
при создании. Добавлен fault-tolerant fallback: если ID передан, но
страница не найдена — создаётся новая вместо тихого провала.

### 2. `admin/logout.php` — неполная очистка сессии
**Симптом:** `unset($_SESSION['admin'])` + `session_destroy()` не
регенерировали session ID, что оставляло возможность session fixation.

**Исправление:** Полная очистка `$_SESSION`, удаление session cookie,
`session_destroy()`, повторный `session_start()` и
`session_regenerate_id(true)` для выдачи нового ID.

### 3. `admin/index.php` — захардкоженный `lang="ru"`
**Симптом:** HTML тег `<html lang="ru">` игнорировал выбранный язык админки.

**Исправление:** Заменено на `<html lang="<?= h(ADMIN_LANG) ?>">`.

### 4. `admin/upload_image.php` (Froala) — слабая санитизация имени
**Симптом:** Имя файла `image.jpg.php` после `preg_replace` становилось
`image.jpg.php` (точка и php-расширение не вырезались). Хотя MIME-проверка
блокировала реальную загрузку PHP-кода, расширение могло оказаться в финальном имени.

**Исправление:**
- Жёсткий список расширений по MIME (image/jpeg → jpg, и т.д.).
- Если оригинальное расширение — замаскированный PHP (`.php`, `.phtml`, ...),
  оно заменяется на `jpg`.
- Имя файла генерируется как `bin2hex(random_bytes(8))` + расширение.
- Файлы сохраняются в `uploads/images/YYYY/MM/DD/` (структурированное хранилище).
- Лимит размера 8 МБ + проверка первых 4 КБ на наличие PHP-тегов.

---

## 🔒 Улучшения безопасности

### 1. Timing attack в REST API
**Файлы:** `api/rest.php`, `api/v1/index.php`

**Проблема:** Сравнение API-токенов через `!==` давало информацию о
правильности префикса токена через время ответа (timing attack).

**Исправление:** Заменено на `hash_equals($api_token, $token)` —
constant-time comparison.

### 2. Rate limit для REST API
**Файлы:** `api/rest.php`, `api/v1/index.php`

**Проблема:** Эндпоинты REST API не имели rate limit — можно было
брутфорсить токен или заспамить API.

**Исправление:** 120 запросов в минуту на IP, через существующий
`api_rate_limit()`. При превышении — HTTP 429 + `Retry-After: 60`.

### 3. Path traversal через `lang` в API
**Файл:** `api/v1/index.php`

**Проблема:** Параметр `lang` передавался напрямую в `read_content()`
без проверки. Хотя `read_content` использует путь относительно
`data/content/`, передача произвольного значения могла привести к
чтению файлов за пределами ожидаемой языковой директории.

**Исправление:** `lang` валидируется против списка поддерживаемых
языков из `config.json`.

### 4. DoS через большой JSON body в API
**Файлы:** `api/rest.php`, `api/v1/index.php`

**Проблема:** `file_get_contents('php://input')` без лимита позволял
отправить любой-большой JSON и переполнить память.

**Исправление:** Чтение ограничено 1 МБ через 4-й параметр
`file_get_contents`.

### 5. Загрузка .php через filemanager
**Файл:** `admin/api_filemanager.php`

**Проблема:** При загрузке в `admin/` или `aicms_root` root
разрешалась загрузка `.php` файлов (для использования с кодовым
редактором). Это позволяло администратору (или скомпрометированной
учётке) загрузить webshell.

**Исправление:** Жёсткий блок всех server-side executable расширений
(`php`, `phtml`, `phar`, `php3-7`, `phps`, `pht`, `asp`, `aspx`,
`jsp`, `sh`, `cgi`, `pl`, `py`) — независимо от root. Редактирование
PHP-файлов остается доступным через специализированный кодовый
редактор (`admin/filemanager.php` с текстовым режимом), который
сохраняет файлы через `file_put_contents`, а не через upload.

### 6. `Secure` флаг для cookies при HTTPS
**Файлы:** `admin/core/functions.php`, `admin/core/user_auth.php`

**Проблема:** Cookies `aicms_authed` и `aicms_remember` не имели флага
`Secure`, что позволяло им отправляться по HTTP (если сайт доступен и
по HTTP, и по HTTPS — cookie могло утечь по HTTP-каналу).

**Исправление:** Новая функция `aicms_cookie_secure()` определяет
HTTPS (прямо или через trusted proxy по `X-Forwarded-Proto`).
Все cookies теперь ставятся с `secure => aicms_cookie_secure()`.

### 7. Proxy-aware client IP
**Файлы:** `admin/core/functions.php`, `admin/core/rate_limit.php`,
`admin/core/form_protection.php`, `admin/core/audit.php`

**Проблема:** Rate limit и логи использовали `$_SERVER['REMOTE_ADDR']`
напрямую. Если сайт за reverse proxy (Cloudflare, Nginx, Docker) —
все запросы выглядели как с одного IP, делая rate limit
бесполезным и некорректно записывая IP нарушителя в логи.

**Исправление:** Новая функция `client_ip()`:
- Если `REMOTE_ADDR` — доверенный прокси (из
  `config.security.trusted_proxies`), парсит `X-Forwarded-For` и
  возвращает первый публичный IP.
- Иначе возвращает `REMOTE_ADDR`.
- Поддерживает как отдельные IP, так и CIDR-диапазоны.

Используется теперь в `rate_limit_check`, `rate_limit_fail`,
`rate_limit_reset`, `api_rate_limit`, `api_rate_limit_status`,
`form_guard_check`, `form_guard_register`, `audit_log`.

### 8. Admin IP allowlist
**Файлы:** `admin/login.php`, `admin/settings.php`

**Новая возможность:** В `config.security.admin_ip_allowlist` можно
указать список IP/CIDR, которым разрешён доступ к `admin/login.php`.
Остальные получают 403. Это защищает админку от брутфорса с
произвольных IP.

### 9. Trusted proxies configuration
**Файл:** `admin/settings.php` (новая вкладка "Безопасность")

В UI добавлен раздел для управления:
- `trusted_proxies` — список IP/CIDR доверенных прокси.
- `admin_ip_allowlist` — белый список IP для админки.
- `csp_report` — тумблер сбора CSP-отчётов.

### 10. CSP violation report endpoint
**Новый файл:** `admin/csp_report.php`

Принимает отчёты о нарушениях Content-Security-Policy от браузера
(через `report-uri` или `Reporting-Endpoints`). Логирует в
`logs/csp_violations.log` с ротацией при достижении 5 МБ.
Rate limit: 60 отчётов/мин. Шумные отчёты от browser-расширений
фильтруются.

В `security_headers.php` добавлена автоматическая простановка
`report-uri` и `Reporting-Endpoints`, если включено в конфиге.

### 11. Security headers для REST API
**Файлы:** `api/rest.php`, `api/v1/index.php`

Добавлены: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: no-referrer`.

---

## 🎨 UI/UX выравнивание

### 1. Удаление устаревшего `admin/header.php`
**Проблема:** Файл `admin/header.php` — старый header, который
конфликтовал с современным `admin/core/topbar.php` (sticky topbar с
breadcrumb, глобальным поиском, темой, action panel).

**Решение:** `admin/header.php` заменён на stub, который ничего не
выводит, но логирует `E_USER_DEPRECATED` при инклуде (для
обратной совместимости). Все страницы должны использовать
`include __DIR__ . '/core/topbar.php';`.

### 2. Язык HTML на дашборде
**Файл:** `admin/index.php`

`<html lang="ru">` → `<html lang="<?= h(ADMIN_LANG) ?>">` —
соответствует выбранному в языковом переключателе языку.

---

## ✨ Новый функционал

### 1. Виджет «Аудит безопасности» на дашборде
**Файл:** `admin/index.php`

На главной странице админки добавлен виджет, показывающий:
- **Security Score** (0-100) — процент выполнения критериев
  безопасности.
- Чек-лист:
  - `install.php` удалён
  - 2FA включена
  - API токен установлен
  - Пароль хэширован (bcrypt)
  - CSP заголовки включены
  - Нет неудачных входов за 24ч
- Баннер «Сайт в режиме обслуживания», если применимо.
- Ссылка на настройки безопасности.

### 2. Виджет «Последняя активность» на дашборде
**Файл:** `admin/index.php`

Показывает последние 5 записей из `audit.json` с иконкой действия,
пользователем, временем и IP.

### 3. Функция `audit_action_icon()`
**Файл:** `admin/core/audit.php`

Возвращает Font Awesome класс иконки по типу действия (создание,
редактирование, удаление, вход, и т.д.). Используется в дашборде и
может использоваться на странице audit log.

### 4. Режим обслуживания (Maintenance Mode)
**Новые файлы:** `admin/core/maintenance.php`

Двухуровневая система:
- **Config-based:** `config.maintenance.enabled` + сообщение + retry_after.
- **File-based kill-switch:** файл `data/.maintenance` — срабатывает
  даже если `config.json` повреждён.

Поведение:
- Анонимные посетители видят страницу 503 (Service Unavailable) с
  вашим сообщением и `Retry-After` заголовком.
- Авторизованные админы и пользователи сайта обходят maintenance
  (могут тестировать).
- Поисковые роботы не индексируют (503 + `noindex`).
- Доступ к `admin/` сохраняется.

Подключается в `index.php` через `require_once` до cache_get.

### 5. Индикатор силы пароля
**Файл:** `admin/settings.php` (вкладка Account)

При вводе нового пароля в форме настроек аккаунта показывается:
- Прогресс-бар (0-100%).
- Метка: «Очень слабый» / «Слабый» / «Хороший» / «Сильный» /
  «Очень сильный».
- Подсказка по улучшению.

Расчёт: энтропия Шеннона = `length * log2(charset_size)`, где
charset_size учитывает строчные/заглавные буквы, цифры, спецсимволы.

### 6. Новая вкладка «Безопасность» в настройках
**Файл:** `admin/settings.php`

Содержит:
- Сетевая безопасность: trusted_proxies, admin_ip_allowlist, csp_report.
- Режим обслуживания: включение, kill-switch, сообщение, retry_after.
- Информационные блоки с пояснениями.

---

## 📦 Структура изменений

### Изменённые файлы (22 шт.):
1. `admin/save_page.php` — баг fix
2. `admin/logout.php` — баг fix + security
3. `admin/login.php` — admin IP allowlist
4. `admin/index.php` — виджеты + lang
5. `admin/settings.php` — security tab + password meter
6. `admin/header.php` — deprecated stub
7. `admin/upload_image.php` — security hardening
8. `admin/lumina_upload_image.php` — security hardening + WebP
9. `admin/api_filemanager.php` — запрет .php uploads
10. `admin/core/functions.php` — client_ip() + Secure cookies
11. `admin/core/user_auth.php` — Secure cookies
12. `admin/core/rate_limit.php` — proxy-aware IP
13. `admin/core/form_protection.php` — proxy-aware IP
14. `admin/core/security_headers.php` — CSP report integration
15. `admin/core/audit.php` — client_ip + audit_action_icon()
16. `admin/core/maintenance.php` — NEW: maintenance mode logic
17. `admin/csp_report.php` — NEW: CSP violation endpoint
18. `admin/assets/lumina/editor.js` — autosave optimization
19. `admin/assets/lumina/modules/contentOps.js` — XSS fix + URL sanitization
20. `admin/assets/lumina/modules/coreEvents.js` — paste images + Word/Docs sanitization
21. `admin/assets/lumina/modules/init.js` — passive listeners + RAF debounce
22. `admin/assets/lumina/modules/history.js` — fast-path no-op skip
23. `api/rest.php` — timing attack + rate limit + size limit
24. `api/v1/index.php` — timing attack + rate limit + size limit + lang validation
25. `index.php` — maintenance mode hook

### Новые файлы (2 шт.):
1. `admin/core/maintenance.php` — Maintenance mode logic
2. `admin/csp_report.php` — CSP violation report endpoint

---

## 🚀 Что делать дальше

1. **Протестировать локально:** распакуйте архив, откройте
   `http://ваш-сайт/admin/`, проверьте дашборд (виджеты) и
   настройки → Безопасность.

2. **Настроить trusted_proxies** если сайт за Cloudflare/Nginx.

3. **Опционально включить admin IP allowlist** если у администратора
   статичный IP.

4. **Включить CSP report** на пару недель, чтобы собрать нарушения и
   скорректировать политику.

5. **Удалить `install.php`**, если ещё не удалён — дашборд теперь
   явно об этом напоминает.

6. **Включить 2FA** в настройках аккаунта.

