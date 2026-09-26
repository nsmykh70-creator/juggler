# Juggler — Production Deployment Guide (Linux VPS)

Полное руководство по выкатке Juggler на Linux-сервер (Ubuntu 22.04/24.04,
Debian 12): systemd-юнит, настоящие сертификаты Let's Encrypt с авто-обновлением,
firewall и реальный WordPress за прокси.

Формат маршрутов и security-политики — структуры `RouteConfig` / `SecurityConfig`
из `src/main.rs`; все переменные окружения — в README, § «Переменные окружения».

---

## 0. Целевая схема

```
                        ┌────────────────────────────────────────────┐
 Internet ──:80───────▶ │ JUGGLER_REDIRECT_HTTP  → 301 на https      │
 Internet ──:443──────▶ │ Data Plane (rustls, ALPN h2 + http/1.1)    │
                        │   routes: /  → upstream 127.0.0.1:9000     │
                        │   security: deny .php-bak/.git/.env,       │
                        │             headers, cms_fallback /index.php│
                        └───────────────┬────────────────────────────┘
                                        │ keep-alive pool, TCP :9000
                                        ▼
                        ┌────────────────────────────────────────────┐
                        │ PHP-FPM + nginx/apache НАДСТРОЙКА не нужна:│
                        │ любой локальный origin на :9000, напр.     │
                        │ php-fpm за встроенным воротами или gRPC-   │
                        │ управляемый контейнер WordPress            │
                        └────────────────────────────────────────────┘
```

Juggler терминирует TLS и раздаёт статику/проксирует на origin по HTTP.
PHP исполняется на origin (php-fpm + mini_httpd / nginx :9000 / docker-контейнер)
— Juggler не претендует на роль PHP-рантайма, он заменяет собой фронтовой
nginx/Apache.

---

## 1. Сборка и установка

```bash
# Сборка (Rust 1.79+, только stable toolchain)
sudo apt-get update && sudo apt-get install -y build-essential pkg-config
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
source "$HOME/.cargo/env"

git clone <repo-url> /opt/juggler
cd /opt/juggler
cargo build --release --locked
sudo install -m 0755 target/release/juggler /usr/local/bin/juggler
```

Бинарник статически линкует rustls/mimalloc/tokio — никаких системных
зависимостей, кроме glibc. Каталог для данных:

```bash
sudo useradd --system --home /var/lib/juggler --shell /usr/sbin/nologin juggler
sudo mkdir -p /var/lib/juggler /etc/juggler
sudo chown -R juggler:juggler /var/lib/juggler
```

---

## 2. Конфигурация маршрутов

`/etc/juggler/routes.json` — WordPress-пример:deny для чувствительных файлов,
security-заголовки, front-controller fallback (аналог `RewriteRule ^(.*)$ index.php [L]`):

```json
{
  "routes": {
    "/": {
      "upstream_addrs": ["127.0.0.1:9000"],
      "security": {
        "deny_substrings": [".git", ".env", "wp-config.php", ".htaccess", ".user.ini", ".bak"],
        "response_headers": [
          ["X-Frame-Options", "SAMEORIGIN"],
          ["X-Content-Type-Options", "nosniff"],
          ["Referrer-Policy", "strict-origin-when-cross-origin"],
          ["Permissions-Policy", "camera=(), microphone=(), geolocation=()"]
        ],
        "cms_fallback_path": "/index.php"
      }
    }
  }
}
```

Проверка конвертацией существующего `.htaccess` (в корне репозитория):

```bash
python3 htaccess_import.py /var/www/site/.htaccess / | python3 -m json.tool
```

Владелец — только сервисный пользователь (секретов внутри нет, но единообразно):

```bash
sudo chown juggler:juggler /etc/juggler/routes.json
sudo chmod 644 /etc/juggler/routes.json
```

---

## 3. Сертификаты: Let's Encrypt + renew hook

Juggler читает `JUGGLER_TLS_DIR/cert.pem` (полная цепочка) + `key.pem` при
старте. certbot обновляет сертификаты независимо; hook перезапускает сервис.

```bash
sudo apt-get install -y certbot

# Сначала остановите любой сервис на :80 (Juggler-redirect пока не запущен)
sudo certbot certonly --standalone -d example.com -d www.example.com \
     --agree-tos -m admin@example.com

# Дать сервисному пользователю доступ на чтение ключа
sudo chmod 750 /etc/letsencrypt/live /etc/letsencrypt/archive
sudo chgrp juggler /etc/letsencrypt/live /etc/letsencrypt/archive
sudo chmod 640 /etc/letsencrypt/archive/example.com/privkey*.pem
```

Renew hook (`/etc/letsencrypt/renewal-hooks/deploy/juggler.sh`):

```bash
#!/bin/sh
set -eu
# certbot передаёт RENEWED_DOMAINS/RENEWED_LINEAGE; жёсткая перезагрузка
# достаточна: горячих соединений прерываем немного (drain ≈ мгновенный).
systemctl restart juggler
```

```bash
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/juggler.sh
sudo certbot renew --dry-run
```

Альтернатива без перезапуска (zero-downtime): объедините `fullchain.pem` и
`privkey.pem` в общий каталог и используйте inotify-хук, обновляющий файлы
in-place, затем `kill -USR1` — поддержка SIGHUP-reload сертификатов в
roadmap (см. README «Roadmap»).

---

## 4. systemd-юнит

`/etc/systemd/system/juggler.service`:

```ini
[Unit]
Description=Juggler Web Server (TLS front proxy)
Documentation=https://github.com/<repo>#readme
After=network-online.target
Wants=network-online.target

[Service]
User=juggler
Group=juggler
Type=simple
WorkingDirectory=/var/lib/juggler

Environment=JUGGLER_HTTP_ADDR=0.0.0.0:443
Environment=JUGGLER_REDIRECT_HTTP=0.0.0.0:80
Environment=JUGGLER_GRPC_ADDR=127.0.0.1:50051
Environment=JUGGLER_ROUTES_FILE=/etc/juggler/routes.json
Environment=JUGGLER_TLS_DIR=/etc/letsencrypt/live/example.com
# Admin UI: bind loopback, reach via SSH tunnel (see §7)
Environment=JUGGLER_ADMIN_ADDR=127.0.0.1:9090
Environment=JUGGLER_ADMIN_TOKEN=CHANGE_ME_TO_A_LONG_RANDOM_VALUE
# Optional: forecast-driven upstream balancing
# Environment=JUGGLER_BALANCER=predictive

# Приватный порт 443 без root
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=true

# Hardening
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/juggler
PrivateTmp=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictNamespaces=true
LockPersonality=true
MemoryDenyWriteExecute=true
SystemCallArchitectures=native
LimitNOFILE=262144

Restart=on-failure
RestartSec=2
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
```

> `ReadWritePaths=/var/lib/juggler` нужен для персистентного
> самоподписанного фолбэка (`cert.pem`/`key.pem`) — при рабочем
> `JUGGLER_TLS_DIR` сервер туда не пишет, но фолбэк не должен падать.

Запуск:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now juggler
sudo systemctl status juggler
journalctl -u juggler -f     # логи
```

Smoke-проверка:

```bash
curl -sI  http://example.com/          | grep -E "HTTP|Location"   # 301 → https
curl -sIk https://example.com/healthz                              # 200 ok
curl -sIk https://example.com/wp-config.php                        # 403
```

---

## 5. Firewall

Минимум наружу: 80 (redirect) + 443 (TLS). Control Plane 50051 — только loopback
(он уже слушает `127.0.0.1`, это вторая линия обороны).

```bash
sudo ufw default deny incoming
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
# ВАЖНО: 9090 (Admin UI) НЕ открываем наружу — только SSH-туннель (§7)
sudo ufw enable
sudo ufw status verbose
```

Если сервер за облаком с security group — продублируйте те же правила там.

---

## 6. WordPress за Juggler

Вариант A — docker-контейнер с nginx+php-fpm как origin:

```bash
docker run -d --name wp --restart unless-stopped \
  -p 127.0.0.1:9000:80 \
  -v /var/www/wordpress:/var/www/html wordpress:php8.3-apache
```

`upstream_addrs` остаётся `["127.0.0.1:9000"]`. В `wp-config.php` добавьте
распознавание схемы за прокси (иначе WordPress сгенерирует http-URL):

```php
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
define('WP_HOME',    'https://example.com');
define('WP_SITEURL', 'https://example.com');
```

Juggler уже шлёт `X-Forwarded-For` и `X-Request-Id` на апстрим; `X-Forwarded-Proto`
добавляйте в origin-контейнер или заведите WASM-плагин (см. `plugins/`).

Вариант B — нативный php-fpm + легковесный origin:

```bash
sudo apt-get install -y php8.3-fpm php8.3-mysql nginx-light
# nginx слушает только 127.0.0.1:9000, раздаёт статику и fastcgi на php-fpm
```

---

## 7. Наблюдаемость и обслуживание

### Веб-интерфейс управления (админка)

Юнит выше включает `JUGGLER_ADMIN_ADDR=127.0.0.1:9090` с bearer-токеном.
Снаружи порт не открывается; доступ — через SSH-туннель:

```bash
ssh -L 9090:127.0.0.1:9090 admin@example.com
# затем открыть http://127.0.0.1:9090/ и авторизоваться:
#   curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:9090/api/stats
```

Дашборд показывает per-shard телеметрию (активные соединения, EMA-уровень,
тренд и Holt-прогноз) и таблицу маршрутов с глубиной пулов. Hot-reload
маршрутов — POST `/api/routes` с JSON в формате `routes.json`.

Если в юните задан `JUGGLER_BALANCER=predictive`, апстримы выбираются по
прогнозу загрузки (полезно для разнородных бэкендов: медленный бэкенд
перестаёт «затягивать» трафик, как только его тренд растёт).

| Что | Как |
|---|---|
| Метрики | `curl -sk https://127.0.0.1:443/metrics` — Prometheus-формат (`http_requests_total{path,status,proto}`, гистограммы латентности, `shard_active_conns`, `shard_forecast_conns`, `upstream_forecast_load`) |
| Логи | `journalctl -u juggler` (stdout); настроить persistent journal: `Storage=persistent` в `/etc/systemd/journald.conf` |
| Hot-reload маршрутов | `python3 grpc_client.py new_routes.json` — без разрыва соединений; файл `/etc/juggler/routes.json` обновляется автоматически |
| Health-check для мониторинга | `GET /healthz` → `200 ok` |
| Обновление Juggler | `cargo build --release && sudo install ... && sudo systemctl restart juggler` — даунтайм < 2 с |

Prometheus scrape-config (фрагмент):

```yaml
scrape_configs:
  - job_name: juggler
    scheme: https
    tls_config: { insecure_skip_verify: true }   # или добавить CA в trust store
    static_configs:
      - targets: ['example.com:443']
    metrics_path: /metrics
```

### Grafana-дашборд

Готовый дашборд: `docs/grafana-dashboard.json` (16 панелей в 4 рядах:
Overview → Traffic → Upstreams → Internal). Покрытие: RPS по протоколам
h1/h2/h3, латентности p50/p99, микс статусов с подсветкой 429/5xx,
rate-limiter, пул upstream'ов (размер/reuse), failover/stale-retries/
health-state, шарды active vs Pythia-forecast, keep-alive acceptance,
кумулятивный счётчик QUIC-соединений (`h3_connections_total`).

Импорт: Grafana → Dashboards → New → Import → загрузить файл → выбрать
Prometheus-datasource (переменная `DS_JUGGLER` задаётся через `__inputs`
при импорте) → Load. Дашборд ссылается только на реально экспортируемые
серии (см. `# TYPE` в `/metrics`); PromQL-выражение error-rate zero-safe
(`or vector(0)`) — на здоровом сервере панель показывает 0%, а не N/A.

Скрейп-конфиг Prometheus — фрагмент выше (job `juggler`, `metrics_path:
/metrics`). Проверка связки за 30 секунд:

```bash
# target должен стать up через ~10 с после старта Prometheus
curl -s http://127.0.0.1:9090/api/v1/targets | \
  python3 -c "import json,sys; t=json.load(sys.stdin)['data']['activeTargets'][0]; \
  print(t['health'], t.get('lastError',''))"
```

Панели используют только реально экспортируемые серии (см. `# TYPE` в
`/metrics`): `http_requests_total{path,status,proto}`,
`http_response_seconds` (histogram), `shard_active_conns` /
`shard_forecast_conns{shard}`, `upstream_requests_total{upstream,outcome}`,
`upstream_pool_size`, `upstream_failover_total`, `upstream_health_state`,
`rate_limit_rejected_total`, `h3_connections_total`,
`keepalive_connections_total`, `active_upstream_connections`.

---

## 8. Чеклист перед запуском в прод

- [ ] `ufw`: наружу только 22/80/443; 50051 — loopback
- [ ] `certbot renew --dry-run` проходит; deploy-hook перезапускает сервис
- [ ] `deny_substrings` покрывает `.git`, `.env`, `wp-config.php`, `.htaccess`
- [ ] `cms_fallback_path` настроен (WordPress: `/index.php`)
- [ ] Security-заголовки (`X-Frame-Options`, `X-Content-Type-Options`, …) в `response_headers`
- [ ] `WP_HOME`/`WP_SITEURL` https + обработка `X-Forwarded-Proto`
- [ ] `systemd`-hardening юнита активен (`systemd-analyze security juggler` → не хуже 1.5)
- [ ] Метрики видны в Prometheus, алерты на `up==0` и рост 5xx
- [ ] Резервное копирование: `/etc/juggler/routes.json`, `/etc/letsencrypt`, БД

---

## 9. Известные ограничения

- Juggler — HTTP/1.1 + HTTP/2 (h2c к апстримам пока нет; апстримы по HTTP/1.1).
- TLS-сертификаты подхватываются при старте; SIGHUP-reload без рестарта — в roadmap.
- Rate limiting (Token Bucket) глобальный на IP; per-route лимиты — в roadmap.
