#!/usr/bin/env python3
"""Real-traffic loader for the CMS routes (wordpress/aicms/joomla).

Mixed GET traffic over the URL list discovered from the live CMS pages
(bench/cms_urls.txt): pages, static assets, query-string variants.
Weighted: index pages are hit more often than deep links.

Threads do sequential keep-alive requests with an optional pacing delay;
per-request status and latency histogram land in bench/cms_load_stats.json
every REPORT_EVERY s so the Grafana panels see live upstream traffic.

Usage: python bench/cms_load.py [threads] [duration_s] [delay_s]
       duration 0 = run until killed.
"""
import http.client
import json
import random
import statistics
import sys
import threading
import time
from collections import Counter
from urllib.parse import urlsplit

URL_FILE = "bench/cms_urls.txt"
STATS = "bench/cms_load_stats.json"
REPORT_EVERY = 5.0

_lock = threading.Lock()
_stats = {"ok": 0, "err": 0, "codes": Counter(), "lat": []}
_stop = threading.Event()


def record(code: int, dt: float) -> None:
    with _lock:
        if 200 <= code < 400:
            _stats["ok"] += 1
        else:
            _stats["err"] += 1
        _stats["codes"][str(code)] += 1
        if len(_stats["lat"]) < 100000:
            _stats["lat"].append(dt * 1000)


def pct(values, p):
    if not values:
        return None
    s = sorted(values)
    return round(s[min(len(s) - 1, int(len(s) * p / 100))], 2)


def snapshot() -> dict:
    with _lock:
        lat = list(_stats["lat"])
        snap = {
            "ok": _stats["ok"], "err": _stats["err"],
            "codes": dict(_stats["codes"]),
            "p50_ms": pct(lat, 50), "p99_ms": pct(lat, 99),
            "ts": time.strftime("%H:%M:%S"),
        }
    return snap


def worker(urls, weights, delay_s: float) -> None:
    rnd = random.Random(threading.get_ident())
    conn = None
    while not _stop.is_set():
        url = rnd.choices(urls, weights=weights)[0]
        sp = urlsplit(url)
        path = sp.path or "/"
        if sp.query:
            path += "?" + sp.query
        for attempt in (1, 2):  # one silent stale-connection retry
            try:
                if conn is None:
                    conn = http.client.HTTPConnection("127.0.0.1", 9085, timeout=15)
                t0 = time.time()
                conn.request("GET", path, headers={"Accept": "*/*"})
                r = conn.getresponse()
                r.read()
                dt = time.time() - t0
                if r.will_close:
                    conn.close()
                    conn = None
                record(r.status, dt)
                break
            except Exception:
                try:
                    if conn:
                        conn.close()
                except Exception:
                    pass
                conn = None
                if attempt == 2:
                    record(0, 0.0)
        if delay_s:
            time.sleep(delay_s)


def main() -> None:
    threads_n = int(sys.argv[1]) if len(sys.argv) > 1 else 16
    duration = int(sys.argv[2]) if len(sys.argv) > 2 else 0
    delay_s = float(sys.argv[3]) if len(sys.argv) > 3 else 0.0

    urls = [u.strip() for u in open(URL_FILE, encoding="utf-8") if u.strip()]
    # index pages weigh 5x, everything else 1x
    weights = [5 if u.rstrip("/").endswith(("wordpress", "aicms", "joomla")) or
               u.count("/") <= 4 else 1 for u in urls]
    print(f"cms_load: {threads_n} threads, {len(urls)} urls, "
          f"duration={duration or 'inf'}s, delay={delay_s}")

    threads = [threading.Thread(target=worker, args=(urls, weights, delay_s),
                                daemon=True) for _ in range(threads_n)]
    for t in threads:
        t.start()

    t0 = time.time()
    try:
        while not _stop.is_set():
            time.sleep(REPORT_EVERY)
            s = snapshot()
            with open(STATS, "w", encoding="utf-8") as f:
                json.dump(s, f, indent=1)
            rps = (s["ok"] + s["err"]) / (time.time() - t0)
            print(f"  [{s['ts']}] ok={s['ok']} err={s['err']} "
                  f"p50={s['p50_ms']}ms p99={s['p99_ms']}ms rps~{rps:.0f}",
                  flush=True)
            if duration and time.time() - t0 >= duration:
                break
    except KeyboardInterrupt:
        pass
    finally:
        _stop.set()
        for t in threads:
            t.join(timeout=3)
        print("cms_load stopped")


if __name__ == "__main__":
    main()
