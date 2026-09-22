# 🤹 Juggler Web Server

Высокопроизводительный HTTPS веб-сервер / обратный прокси на Rust, спроектированный
как ответ на архитектурные ограничения Apache и Nginx. Название отражает суть:
сервер держит в воздухе тысячи соединений, горячие маршруты и пул апстримов —
и не роняет ни одного шарика.

**HTTP/2 из коробки**: ALPN-согласование (`h2` + `http/1.1`) на одном порту,
мультиплексирование потоков и перекодирование h2↔h1 в обе стороны.

| Проблема Apache/Nginx | Решение Juggler |
|---|---|
| Apache: процесс/поток на запрос → взрыв памяти под нагрузкой | **Thread-per-Core**: фиксированный пул (1 поток = 1 ядро), все соединения — дешёвые асинхронные задачи Tokio |
| Nginx: любая правка конфига = reload воркеров | **gRPC Control Plane**: конфиг подменяется атомарно в памяти (ArcSwap) без разрыва соединений |
| Nginx: конфиг только в файлах, без API | **API-first**: маршруты задаются gRPC-вызовом; файл `routes.json` — лишь persistence-слой |
| Nginx/Apache: нет встроенного rate limiting | **Token Bucket на IP** внутри ядра, до проксирования |
| Nginx: upstream падает → клиенты получают 504/502 | **Failover + пассивные health checks**: недоступный апстрим пропускается, а после N сбоев исключается из балансировки до конца cooldown — трафик больше не тратит таймауты на мёртвые бэкенды |
| Оба: обрыв соединения после каждого запроса (прототип) | **Keep-alive**: одно TLS-соединение обслуживает множество запросов |
| Оба: HTTP/2 только через отдельный порт или надстройки | **HTTP/2 по ALPN** на том же порту: h2-стримы мультиплексируются и перекодируются в h1 к апстримам |
| Оба: модули на C роняют сервер | **WASM-плагины в песочнице** (wasmtime): fuel-лимит CPU, кап памяти, изоляция — падение фильтра не роняет сервер |

## Архитектура

```
                    ┌──────────────────────────────────────────┐
  Admin / CI ──gRPC──▶ Control Plane :50051 (шард 0, отдельный рантайм)│
                    │   └─ ArcSwap<ServerConfig> ──┐          │
                    │                              │ swap     │
  Клиенты ──TLS────▶ Data Plane :8443               ▼          │
                    │  12 шардов: по потоку на ядро          │
                    │  (current_thread runtime + LocalSet)   │
                    │  accept: SO_REUSEPORT (Linux, ядро     │
                    │  балансирует) или forecast hand-off    │
                    │  (Windows: центральный acceptor +      │
                    │  lock-free очереди, шард по прогнозу)  │
                    │  keep-alive loop per shard             │
                    │   parse → rate limit → security gate   │
                    │   → WASM-фильтры (on_request +        │
                    │     transform_body, fail-closed)      │
                    │   → Least Connections + tie-break     │
                    │   → health filter → failover → proxy ──▶ upstream :7001, :7002 …
                    │   → /metrics, /healthz                │
                    └──────────────────────────────────────────┘
         routes.json ▲ (persistence: автосохранение при reload,
                       автозагрузка при старте + прогрев пула)
```

* **Data Plane** (8443, HTTPS): терминация TLS (rustls, ALPN: `h2` + `http/1.1`
  на одном порту). Сертификат: `JUGGLER_TLS_DIR` с `cert.pem` + `key.pem` →
  иначе самоподписанный, **сгенерированный один раз и сохранённый** в `cert.pem`/
  `key.pem` рядом с бинарником (SAN: `localhost`, `127.0.0.1`) — исключение в
  браузере достаточно принять единожды, оно переживает рестарты.
  - *HTTP/2*: мультиплексирование стримов (каждый стрим — отдельная Tokio-задача),
    чтение тела по фреймам flow-control, перекодирование в HTTP/1.1 к апстримам
    (hop-by-hop заголовки вычищаются, Content-Length выставляется точно).
  - *HTTP/1.1*: keep-alive (переписывание `Connection`, лимит 1000 запросов на
    соединение), чтение тела по Content-Length.
  - *Общее*: Token Bucket rate limiting, **Least Connections со случайным
    tie-break**, **failover** (мёртвый апстрим пропускается, таймаут 3 c), чтение
    ответа апстрима с пониманием Content-Length **и chunked**, инъекция
    `X-Forwarded-For` и `X-Request-Id`, нормализация версии ответа (HTTP/1.0
    апстрима отдаётся клиенту как HTTP/1.1).
* **Control Plane** (50051, gRPC): `UpdateConfig` атомарно подменяет таблицу
  маршрутов и сохраняет её в `routes.json`. Валидация: путь начинается с `/`,
  минимум один апстрим.
* **Persistence**: маршруты автоматически восстанавливаются после рестарта —
  сервер не стартует «пустым» после деплоя.
* **Метрики** (`/metrics`): `http_requests_total{path,status,proto}` (лейбл
  `proto` разделяет `h1` и `h2`), `http_response_seconds`,
  `upstream_requests_total{upstream,outcome}`, `upstream_failover_total`,
  `keepalive_connections_total`, `rate_limit_rejected_total`,
  `active_upstream_connections`.

## Быстрый старт

Требования: Rust 1.75+ (stable). protoc вендорен в `third_party/protoc/` —
системный не нужен (на Linux/macOS также подхватывается системный из PATH).

```bash
cargo run --release
```

```
🤹 Juggler: thread-per-core runtime on N core(s)
⚙️  Control Plane (gRPC) listening on 127.0.0.1:50051
🔒 Data Plane (HTTPS) listening on https://127.0.0.1:8443
```

При старте сервер читает `routes.json` (создайте сами или загрузите через
Control Plane — пример в репозитории).

### HTTPS без предупреждения браузера (mkcert)

```bash
scripts/dev-cert.sh                                # или: scripts/dev-cert.sh mysite.dev
JUGGLER_TLS_DIR=dev-certs cargo run --release
```

Скрипт использует `mkcert` (устанавливает локальный CA в доверенные ОС/браузера)
или, при его отсутствии, `openssl` с локальным CA (`dev-certs/dev-ca.pem` —
импортировать вручную). Больше никакого «Proceed anyway».

### HTTP → HTTPS redirect

```bash
JUGGLER_REDIRECT_HTTP=0.0.0.0:80 cargo run --release
# curl -sI http://example.com/foo → 301 Location: https://example.com/foo
```

### Переменные окружения

| Переменная | По умолчанию | Описание |
|---|---|---|
| `JUGGLER_HTTP_ADDR` | `127.0.0.1:8443` | адрес Data Plane |
| `JUGGLER_GRPC_ADDR` | `127.0.0.1:50051` | адрес Control Plane |
| `JUGGLER_ROUTES_FILE` | `routes.json` | файл persistence маршрутов |
| `JUGGLER_TLS_DIR` | — | каталог с `cert.pem` + `key.pem` (PEM, полный цепочки). Без переменной — самоподписанный серт |
| `JUGGLER_REDIRECT_HTTP` | — | адрес plain-HTTP листенера, отдающего `301` на HTTPS (напр. `0.0.0.0:80`). Host сохраняется, порт берётся из `JUGGLER_HTTP_ADDR` |
| `JUGGLER_ADMIN_ADDR` | — | адрес веб-интерфейса управления (напр. `127.0.0.1:9090`): дашборд, `/api/stats`, `/api/routes` |
| `JUGGLER_ADMIN_TOKEN` | — | bearer-токен для админ-API. Не задан — авторизация отключена (только localhost) |
| `JUGGLER_BALANCER` | `leastconn` | `predictive` — выбор апстрима по Holt-прогнозу загрузки (уровень + тренд), а не только по текущим соединениям |

Для продакшена: `JUGGLER_HTTP_ADDR=0.0.0.0:443` (привилегированный порт —
учтите capabilities/запуск от имени сервисного пользователя с `CAP_NET_BIND_SERVICE`).
Полное руководство по выкатке на Linux VPS — **[docs/DEPLOY.md](docs/DEPLOY.md)**:
systemd, Let's Encrypt с renew-hook, firewall, WordPress.

## Загрузка конфигурации

### Вариант A: демо-клиент

```bash
python grpc_client.py          # /api → 127.0.0.1:7001 + 127.0.0.1:7002
```

### Вариант B: свой JSON

`routes.json`:
```json
{
  "routes": {
    "/api":  { "upstream_addrs": ["10.0.0.1:5000", "10.0.0.2:5000"] },
    "/app":  { "upstream_addrs": ["10.0.0.3:8000"] }
  }
}
```

### Статика: docroot `www/`

Как `htdocs` у Apache: при старте Juggler создаёт `www/` и автоматически
регистрирует каждый подкаталог как маршрут — каталог `www/sample_sites1/`
отвечает на `https://<host>/sample_sites1/` (файл по умолчанию — `index.html`).
Положите свой сайт в `www/<имя>/` — маршрут появится без правки конфигов.

- MIME определяется по расширению (html/css/js/png/svg/json/woff2 и др.),
- каталог-объекты (`DirectoryListing`) выключены, отсутствующий файл → 404,
- traversal-атаки (`..%2f`, `%2e%2e`) блокируются на резолвере,
- маршрутом можно управлять и явно: `"static_dir": "www/mysite"` в `routes.json`
  (перезаписывает автоподстановку и живёт в hot-reload наравне с прокси-маршрутами).

```bash
python grpc_client.py routes.json
```

### Вариант C: из Python-кода

```python
import grpc
import control_plane_pb2 as pb2
import control_plane_pb2_grpc as pb2_grpc

with grpc.insecure_channel("127.0.0.1:50051") as ch:
    stub = pb2_grpc.ControlPlaneStub(ch)
    stub.UpdateConfig(pb2.UpdateConfigRequest(routes={
        "/api": pb2.RouteConfig(upstream_addrs=["127.0.0.1:7001"]),
    }))
```

(файлы `control_plane_pb2*.py` генерируются автоматически при первом запуске
`grpc_client.py` / `integration_tests.py`).

## Тестирование

```bash
# unit-тесты (45 шт.: bucket, балансировщик, парсер, keep-alive, chunked,
# ALPN, h2->h1 кодирование, сквозной h2, WASM-плагины и т.д.)
cargo test --release

# интеграционные (18 сценариев): запустить бэкенды и сервер, затем
pip install requests urllib3 grpcio grpcio-tools "httpx[http2]"
python mock_backends.py &
./target/release/juggler &          # или: cargo run --release
python integration_tests.py
```

Интеграционный набор: TLS handshake, `/healthz` + `/metrics`, hot-reload через
gRPC, проксирование (заголовок `X-Backend`), поведение Least Connections,
rate limiter (429 при всплеске), keep-alive (3 запроса через одно соединение),
failover (мёртвый апстрим → живой), health checks (маркировка после N
сбоев, пропуск мёртвого, авто-восстановление через half-open probe), явный HTTP/1.1, **HTTP/2 по ALPN с
мультиплексированием** (проверка через httpx[http2]), **WASM-плагины**:
инъекция заголовка, переписывание тела, fail-open при отсутствии файла,
метрики `wasm_plugin_calls_total` / `wasm_modules_loaded`.

## Docker

```bash
docker build -t juggler-server .
docker run -d -p 8443:8443 -p 50051:50051 --name juggler juggler-server
```

В контейнере адреса по умолчанию `0.0.0.0` (см. ENV в Dockerfile).

Со стеком мониторинга:

```bash
docker compose up -d
# Grafana: http://localhost:3000 (admin/admin), datasource: http://prometheus:9090
```

Полезные PromQL:
```text
rate(http_requests_total[1m])                                       # RPS по путям
sum by (proto) (rate(http_requests_total[1m]))                      # RPS: h1 vs h2
histogram_quantile(0.95, rate(http_response_seconds_bucket[5m]))    # p95 латентности
rate(rate_limit_rejected_total[1m])                                 # отброшено лимитером
sum by (upstream) (rate(upstream_requests_total{outcome="error"}[5m]))  # ошибки апстримов
upstream_failover_total                                             # срабатывания failover
```

## CI/CD

`.github/workflows/ci.yml` — три джобы на каждый push/PR:
1. **tests** — `cargo fmt --check`, `cargo clippy -D warnings`, unit-тесты
   ×3 прогона (балансировщик использует случайный tie-break).
2. **integration** — сборка, подъём моков и сервера, полный прогон
   `integration_tests.py`, **проверка persistence** (рестарт сервера с
   восстановлением маршрутов), логи падений в артефакты.
3. **docker** — сборка образа (публикация включается добавлением registry login).

## HTTP API (Data Plane)

| Путь | Описание |
|---|---|
| `/healthz` | health-check, всегда `200 ok` |
| `/metrics` | метрики Prometheus |
| остальное | маршруты из конфига, иначе `404 route not found` |

Заголовки, добавляемые к запросам апстримам: `X-Forwarded-For: client`,
`X-Request-Id: req-<64bit hex>`.

## Веб-интерфейс управления и телеметрия (v0.8.0)

Запустите с `JUGGLER_ADMIN_ADDR=127.0.0.1:9090` и откройте
`http://127.0.0.1:9090/` — дашборд (авто-обновление 3 с):

- **KPI**: версия, uptime, число шардов и маршрутов
- **Таблица шардов**: активные соединения, принято всего, EMA-уровень,
  **Holt-тренд** (растёт/падает) и **прогноз** нагрузки на шаг вперёд —
  видно, какой ядру становится тесно, до того как это станет проблемой
- **Живое обновление без перезагрузки**: мониторинг (KPI, шарды,
  стадии, маршруты) опрашивает `/api/stats` раз в 1.5 с и обновляется
  на месте — страница никогда не перезагружается
- **Клиентские спарклайны**: в каждой строке шарда — SVG-график последних
  120 точек (уровень + прогноз, окно 4 мин). Видно, как прогнозный
  балансир уводит нагрузку: пунктирная линия (прогноз) опережает
  сплошную (факт) при росте
- **Стадии конвейера запроса** (`read+parse`, `route`, `upstream`,
  `respond`): средняя/максимальная задержка на стадию с бар-чартом —
  сразу видно, где узкое место, без внешнего профилировщика
- **Настройки — статичные**: карточка Fine Tuning рендерится один раз и
  обновляется только после успешного Apply — значения не сбрасываются,
  пока вы их вводите. Слайдеры синхронизированы с числовыми полями
- **Маршруты и пулы**: апстримы с числом переиспользуемых соединений,
  WASM-плагины, политика безопасности

JSON API (тот же сервер, `Authorization: Bearer $JUGGLER_ADMIN_TOKEN` если
токен задан):

| Метод | Путь | Описание |
|---|---|---|
| GET | `/` | HTML-дашборд |
| GET | `/api/stats` | полный снапшот: шарды + Holt, маршруты, пулы |
| GET | `/api/routes` | текущая таблица маршрутов (формат routes.json) |
| POST | `/api/routes` | hot-reload маршрутов (валидация + persist + прогрев пулов) |

Телеметрия шардов и апстримов также экспортируется в Prometheus:
`shard_active_conns`, `shard_forecast_conns` (прогноз),
`upstream_forecast_load` (прогноз in-flight загрузки апстрима).

Предиктивный балансировщик: `JUGGLER_BALANCER=predictive` — апстрим
выбирается по прогнозу загрузки (уровень + тренд in-flight запросов),
поэтому запросы уходят туда, где очередь **будет** короче, а не где она
короче прямо сейчас. По умолчанию — классический least-connections.

### Ядро прогноза v2 (lock-free, cache-line aligned)

Модуль предсказания (шарды и апстримы) построен как массив ячеек с
выравниванием по 64 байта (`#[repr(align(64))]`): одна ячейка = одна
кэш-линия L1, соседние ядра не спорят за одну линию (false sharing
исключён). Математика — фиксированная точка Q16 на i64/i128 без float:
Holt-рекуррентности сводятся к сложению и умножению, детерминированы и
дешевле на 30–50% против f64-варианта. Сглаживание **адаптивное**:
коэффициенты α/β сами расширяются при всплесках (большая ошибка прогноза)
и сжимаются на стабильной нагрузке — прогноз быстро реагирует на рампу и
не дёргается в спокойном режиме.

Горячий путь вообще не берёт mutex'ов на прогноз: выборка апстрима читает
атомары ячеек (Relaxed), телеметрия стадий накапливается в per-core
аккумуляторах и сворачивается сэмплером раз в 2 с. Результат: снятие
глобального `Mutex<HashMap>` из predictive-выбора и полной копии ответа
(`raw.to_vec()`) из пути ответа — ответ теперь модифицируется in-place.

Память: пул апстримов поддерживается «тёплым» — цель прогрева адаптируется
к половине исторического пика одновременных соединений (high-water mark,
мин. 32), а сэмплер раз в 2 с вызывает `mi_collect()` на Unix, чтобы
mimalloc возвращал освободившиеся страницы ОС после всплесков нагрузки.

### Zero-copy парсинг и пул буферов соединений (v0.9.0)

`RawRequest` больше не хранит `String`'и — все поля (метод, путь, версия,
заголовки) — **диапазоны байтов** в исходный буфер соединения. Внутренне
резолвятся `Content-Length` и keep-alive (HTTP/1.0 vs 1.1): один проход
вместо линейного поиска по заголовкам на каждом месте использования.
**Аллокации на запрос: 12–14 → 1** (единственная — материализация пути для
метрик), замер парсера в release: **909 → 692 нс/запрос (1.31×)**
(микробенчмарк `bench_parse_request_zero_copy_vs_legacy` в тестах).

`build_forwarded_request` пересобран в single-pass: одна аллокация, без
промежуточного буфера заголовков, без `Vec::splice` (сдвиг хвоста) и без
второй копии головы при перезаписи Content-Length. `X-Request-Id`
генерируется прямой записью hex-цифр без `format!`.

Чтение из сокета — из **per-shard пула буферов** (16 KiB, lock-free
`SegQueue`, до 64 штук на шард): keep-alive соединение не делает
malloc/free на каждый запрос, освободившийся буфер остаётся кэш-тёплым на
том же ядре. Постатийная телеметрия (`read+parse → route → upstream →
respond`, per-core аккумуляторы) показывает оставшееся узкое место:
на localhost-демо это connect/read к Python-мок-апстриму, парсинг и
маршрутизация — микросекунды.

### Приём соединений: платформо-зависимый

- **Linux/BSD** — классический nginx-подход: каждый шард биндит свой
  `SO_REUSEPORT`-листенер, ядро хэширует 4-tuple и раскидывает коннекты
  по ядрам. Нулевая координация в userspace (модель, измеренная в bench).
- **Windows** (SO_REUSEPORT нет — без обхода весь трафик доставался
  одному «счастливому» слушателю) — **forecast hand-off**: один
  центральный acceptor-поток принимает соединения и раскладывает их в
  per-shard lock-free очереди (`crossbeam SegQueue`, отдельные каналы для
  TLS / plain / redirect). Шард-получатель выбирается по стоимости
  `2×прогноз + 4×глубина очереди + активные коннекты` — прогноз v2 видит
  рост нагрузки раньше сырых счётчиков, поэтому выборка упреждающая, а не
  round-robin. Peer IP проносится через очередь — rate limiting по IP
  работает без изменений. Проверено: 400 коннектов легли на **12 из 12**
  шардов (раньше — 100% на один), 0 ошибок.

## WASM-плагины (wasmtime)

Каждый маршрут может нести поле `wasm_plugin` — путь к `.wasm`-модулю,
который выполняется на каждом запросе этого маршрута внутри Data Plane.
Плагины компилируются при hot-reload (Cranelift-кэш общий на процесс) и
инстанцируются per-request в изолированном `Store`.

**Жизненный цикл запроса внутри плагина:**

1. Хост публикует в линейную память плагина тело запроса (смещение 0) и путь
   (смещение 1 MiB).
2. Опциональный экспорт `on_request()` — фаза заголовков: плагин вызывает
   хост-функции `juggler.add_header(k,kl,v,vl)` и `juggler.path()`, добавляя
   заголовки к проксируемому запросу.
3. Опциональный экспорт `transform_body(ptr, len) -> ptr` — фаза тела:
   плагин переписывает тело (в т.ч. через `juggler.alloc`) и возвращает
   указатель на `[len: u32 le][bytes]`.

**Изоляция (sandbox):** fuel-лимит 100 000 на вызов (бесконечный цикл =
trap, не hang), кап памяти 2 MiB на инстанс, scratch-выделения ≤ 4 KiB,
тело на переписывание ≤ 1 MiB; никакого файлового/сетевого доступа — только
три хост-функции. Плагин, ссылающийся на несуществующий файл, не ломает
reload и маршрут (fail-open); **trap** внутри работающего плагина даёт
клиенту 502 (fail-closed — лучше чистая ошибка, чем неотфильтрованный запрос).

Примеры — в `plugins/*.wat` (add_header: инъекция заголовка; upper_body:
перевод тела в верхний регистр). Сборка: `python plugins/compile_plugins.py`
(нужен `pip install wasmtime`); `cargo test` дополнительно сам собирает и
прогоняет примеры через dev-dependency `wat`.

## Health checks апстримов (пассивные)

Диспетчер записывает исход каждой попытки в трекер здоровья. После N
подряд идущих сбоев (по умолчанию 3, `JUGGLER_HEALTH_MAX_FAILURES`) апстрим
помечается unhealthy и исключается из балансировки: мёртвый бэкенд больше
не получает трафик и не съедает connect-таймаут на каждом запросе.

Восстановление — автоматическое, по схеме circuit breaker: через cooldown
(по умолчанию 5 c, `JUGGLER_HEALTH_COOLDOWN_SECS`) цепь переводится в
half-open и ровно один пробный запрос получает путь к апстриму. Успех —
цепь замыкается, апстрим снова в ротации; провал — цепь размыкается
немедленно. Если unhealthy отмечены все апстримы маршрута, запрос всё равно
уходит (least-loaded из «подозрительных»), чтобы маршрут не умирал целиком
на время общего сбоя. История апстримов, удалённых при hot-reload,
сбрасывается.

Состояние экспортируется в метрику `upstream_health_state{upstream,state}`
(healthy / unhealthy / half-open).

## Безопасность и .htaccess-совместимость (v0.6.0)

Вместо рассыпанных по каталогам `.htaccess` (как в Apache) Juggler держит
политику безопасности каждого маршрута в конфиге — валидированном,
hot-reload-обновляемом и проверяемом целиком:

```json
"/cms": {
  "upstream_addrs": ["127.0.0.1:9000"],
  "security": {
    "deny_substrings": ["wp-config.php", ".git", ".htaccess"],
    "response_headers": [["X-Frame-Options", "DENY"],
                         ["X-Content-Type-Options", "nosniff"]],
    "cms_fallback_path": "/cms"
  }
}
```

- **deny_substrings** — пути, содержащие эти подстроки (без учёта регистра),
  получают 403 ещё до обращения к апстриму. Аналог
  `<FilesMatch "^\.(htaccess|git)"> Require all denied`.
- **response_headers** — security-заголовки в каждый ответ маршрута
  (`Header always set …`); существующие значения заменяются.
- **cms_fallback_path** — фронт-контроллер WordPress/Laravel/Drupal
  (аналог `RewriteRule ^(.*)$ index.php [L]`): неизвестные пути маршрута
  проксируются на этот же роут, CMS сама разбирает URI. Изящные URL
  популярных CMS работают без mod_rewrite.

Мост из существующих `.htaccess`: `python htaccess_import.py
/path/to/.htaccess /route > patch.json && python grpc_client.py patch.json`.

Сравнение с классикой — `bench/REPORT.md`: Juggler против nginx/Apache/
Caddy/OpenLiteSpeed по RPS, латентности и памяти.

## Security testing (v1.0.0)

Juggler v1.0.0 прошёл структурированный security pass covering HTTP parser
fuzzing (1200 structured mutations), request-smuggling/desync vectors,
resource exhaustion, admin/gRPC authorization, configuration atomicity,
SSRF/egress controls (config-time and resolve-time — the verified
hostname-resolution gap is closed by the DNS-rebinding guard; exotic
resolver/cache semantics are out of scope),
path traversal and protocol-abuse scenarios. Issues found during the audit
(control-plane authorization and several protocol-hardening defects) were
fixed and re-verified live on the rebuilt image. In the tested scenarios no
crash, hang, partial configuration application or request-
smuggling/desynchronization was observed. Distributed multi-source admin
throttling remains a documented defence-in-depth follow-up (per-IP latch is
in place; behind a reverse proxy/WAF the trusted-proxy/XFF question is a
deployment decision). Full reports: `bench/report_security.html`,
`bench/report_ddos.html`.

## Ограничения и roadmap

- HTTP/2: серверные стримы обслуживаются конкурентно; апстримы пока говорят
  только HTTP/1.1 (h2→h1 перекодирование на каждом запросе). HTTP/2 к апстримам
  (h2c-pool) — в roadmap.
- Keep-alive ограничен 1000 запросов на соединение (защита от resource leak).
- Failover срабатывает на уровне соединения (connect/EOF); HTTP-статусы 5xx
  от живого апстрима не ретраются (идемпотентность — на стороне клиента).
- Rate limiting — по IP без обработки `X-Forwarded-For` от доверенных прокси.
- WASM-плагины: фильтры работают только на фазе запроса (заголовки/тело);
  фильтрация ответов апстрима, response-phase хуки и AOT-кэш модулей — в
  roadmap. HTTP/3 (QUIC) и TLS-сертификаты из Control Plane — в roadmap.

## Структура проекта

```
├── Cargo.toml              # juggler v0.6.0 (tokio, h2, tonic, rustls, prometheus, mimalloc…)
├── build.rs                # компиляция .proto через вендоренный protoc
├── proto/control_plane.proto   # package juggler.control (+SecurityConfig)
├── third_party/protoc/     # вендоренный protoc 32.1 (win64; linux-сборки CI
│                             качают protoc из protobuf releases — см. ci.yml)
├── src/main.rs             # весь сервер: шардированный data plane (SO_REUSEPORT
│                             на Linux / forecast hand-off на Windows,
│                             thread-per-core), control plane, security layer,
│                             WASM-пайплайн, keep-alive пул, health checks, 58 unit-тестов
├── routes.json             # persistence маршрутов (прим.: это валидный JSON)
├── routes_wasm.json        # пример конфига с wasm_plugin на маршрутах
├── htaccess_import.py      # мост .htaccess -> Juggler security policy
├── plugins/                # примеры плагинов (.wat + .wasm) и компилятор
├── bench/                  # сравнительный бенчмарк против nginx (+отчёт, HTML)
├── integration_tests.py    # e2e-тесты (18 сценариев: WASM, health, security)
├── grpc_client.py          # gRPC-клиент управления
├── mock_backends.py        # тестовые бэкенды (fast/slow/echo)
├── Dockerfile, docker-compose.yml, prometheus.yml, .gitignore
└── .github/workflows/ci.yml
```
