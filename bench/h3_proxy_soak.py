#!/usr/bin/env python3
"""H3 soak through a proxy route to a real upstream (Apache/PHP container).

Focus: upstream keep-alive pool health under sustained QUIC traffic.
Derived from bench/h3_soak.py (steady + churn waves + resource sampler),
changed: path targets the /aicms proxy route, and the sampler additionally
records upstream_pool_reuses_total / upstream_pool_size / active gauge
values plus client-side p50/p99 latency.
"""
import argparse
import asyncio
import json
import ssl
import subprocess
import time

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import QuicEvent

HOST, PORT = "127.0.0.1", 8443
AUTHORITY = f"{HOST}:{PORT}"
STEADY_CONNS = 8
CHURN_CONNS = 100
CHURN_PERIOD = 300.0
REQ_TIMEOUT = 10.0
SAMPLE_EVERY = 60.0
ADMIN = "http://127.0.0.1:9090"


class H3Client(QuicConnectionProtocol):
    """Per-stream waiters/status: a shared single waiter resolves the NEXT
    request as soon as a LATE body frame of the PREVIOUS one arrives
    (responses with bodies resolve on both headers-end and data-end).
    Verified against server counters: honest counting requires the map."""

    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self._http = H3Connection(self._quic)
        self._waiters = {}   # stream_id -> Future
        self._status = {}    # stream_id -> int

    def quic_event_received(self, event: QuicEvent):
        for ev in self._http.handle_event(event):
            sid = getattr(ev, "stream_id", None)
            if sid not in self._waiters:
                continue
            if isinstance(ev, HeadersReceived):
                for k, v in ev.headers:
                    if k == b":status":
                        self._status[sid] = int(v.decode())
                if ev.stream_ended and not self._waiters[sid].done():
                    self._waiters[sid].set_result(None)
            elif isinstance(ev, DataReceived):
                if ev.stream_ended and not self._waiters[sid].done():
                    self._waiters[sid].set_result(None)

    def connection_lost(self, exc):
        for f in self._waiters.values():
            if not f.done():
                f.set_exception(exc or ConnectionError("closed"))
        super().connection_lost(exc)

    async def get(self, path: str):
        sid = self._quic.get_next_available_stream_id()
        fut = asyncio.get_event_loop().create_future()
        self._waiters[sid] = fut
        self._http.send_headers(
            stream_id=sid,
            headers=[
                (b":method", b"GET"),
                (b":scheme", b"https"),
                (b":authority", AUTHORITY.encode()),
                (b":path", path.encode()),
            ],
            end_stream=True,
        )
        self.transmit()
        try:
            await asyncio.wait_for(fut, timeout=REQ_TIMEOUT)
        finally:
            self._waiters.pop(sid, None)
            return self._status.pop(sid, None)


def make_cfg() -> QuicConfiguration:
    cfg = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    cfg.verify_mode = ssl.CERT_NONE
    return cfg


class Stats:
    ok = 0
    err = 0
    timeouts = 0
    handshakes = 0
    lat_ms = []          # per-request latency, bounded window
    non_200 = 0


async def steady_worker(stop_at, path):
    while time.monotonic() < stop_at:
        Stats.handshakes += 1
        try:
            async with connect(HOST, PORT, configuration=make_cfg(), create_protocol=H3Client) as c:
                while time.monotonic() < stop_at:
                    t0 = time.perf_counter()
                    try:
                        st = await c.get(path)
                        Stats.lat_ms.append((time.perf_counter() - t0) * 1000.0)
                        Stats.ok += 1
                        if len(Stats.lat_ms) > 20000:
                            del Stats.lat_ms[:10000]
                        if st != 200:
                            Stats.non_200 += 1
                    except asyncio.TimeoutError:
                        Stats.timeouts += 1
                        Stats.err += 1
                    except Exception:
                        Stats.err += 1
                        break
        except Exception:
            Stats.err += 1
            await asyncio.sleep(0.2)


async def churn_wave(path):
    Stats.handshakes += CHURN_CONNS

    async def open_conn():
        try:
            cm = connect(HOST, PORT, configuration=make_cfg(), create_protocol=H3Client)
            s = await cm.__aenter__()
            return s, cm
        except Exception:
            return None, None
    pairs = await asyncio.gather(*(open_conn() for _ in range(CHURN_CONNS)))
    good = [(s, cm) for s, cm in pairs if s is not None]
    Stats.err += len(pairs) - len(good)

    async def one(pair):
        s, _cm = pair
        try:
            t0 = time.perf_counter()
            st = await s.get(path)
            Stats.lat_ms.append((time.perf_counter() - t0) * 1000.0)
            Stats.ok += 1
            if st != 200:
                Stats.non_200 += 1
        except Exception:
            Stats.err += 1
    await asyncio.gather(*(one(p) for p in good))
    for _s, cm in good:
        try:
            await cm.__aexit__(None, None, None)
        except Exception:
            pass


async def churn_worker(stop_at, path, t0):
    next_wave = t0 + CHURN_PERIOD
    while time.monotonic() < stop_at:
        if time.monotonic() >= next_wave:
            await churn_wave(path)
            next_wave += CHURN_PERIOD
        else:
            await asyncio.sleep(1.0)


def server_snapshot(pid):
    ps = (
        "$p=Get-Process -Id " + str(pid)
        + '; Write-Output ("" + [int64]$p.WorkingSet64 + " " + $p.HandleCount + "")'
    )
    r = subprocess.run(["powershell", "-NoProfile", "-Command", ps],
                       capture_output=True, text=True)
    parts = r.stdout.split()
    if len(parts) < 2:
        return None, None
    return int(parts[0]), int(parts[1])


def metric_value(metric, label_sub=None):
    try:
        import urllib.request
        with urllib.request.urlopen(ADMIN + "/api/metrics", timeout=4) as r:
            lines = r.read().decode().splitlines()
    except Exception:
        return None
    for line in lines:
        if line.startswith(metric + " ") or line.startswith(metric + "{"):
            if label_sub and label_sub not in line:
                continue
            try:
                return int(float(line.split()[-1]))
            except (ValueError, IndexError):
                return None
    return None


def pctl(data, p):
    if not data:
        return None
    s = sorted(data)
    k = min(len(s) - 1, int(len(s) * p / 100.0))
    return round(s[k], 1)


async def sampler(pid, stop_at, t0, samples):
    while time.monotonic() < stop_at:
        await asyncio.sleep(SAMPLE_EVERY)
        rss, handles = server_snapshot(pid)
        lats = list(Stats.lat_ms)
        samples.append({
            "t_min": round((time.monotonic() - t0) / 60.0, 2),
            "ok": Stats.ok, "err": Stats.err, "timeouts": Stats.timeouts,
            "non_200": Stats.non_200,
            "rss_mb": round(rss / 1048576, 1) if rss else None,
            "handles": handles,
            "pool_reuses": metric_value("upstream_pool_reuses_total"),
            "pool_idle": metric_value("upstream_pool_size"),
            "active": metric_value("active_upstream_connections"),
            "h3_conns_total": metric_value("h3_connections_total"),
            "p50_ms": pctl(lats, 50),
            "p99_ms": pctl(lats, 99),
        })
        s = samples[-1]
        print(f"[t={s['t_min']:6.1f}m] ok={s['ok']} err={s['err']} to={s['timeouts']} "
              f"n200={s['non_200']} reuse={s['pool_reuses']} idle={s['pool_idle']} "
              f"rss={s['rss_mb']}MB h={s['handles']} p50={s['p50_ms']} p99={s['p99_ms']}",
              flush=True)


def slope_per_min(samples, key):
    pts = [(s["t_min"], s[key]) for s in samples if s.get(key) is not None]
    if len(pts) < 3:
        return None
    n = len(pts)
    mx = sum(p[0] for p in pts) / n
    my = sum(p[1] for p in pts) / n
    denom = sum((p[0] - mx) ** 2 for p in pts)
    if denom == 0:
        return 0.0
    return sum((p[0] - mx) * (p[1] - my) for p in pts) / denom


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pid", type=int, required=True)
    ap.add_argument("--duration", type=float, default=3600.0)
    ap.add_argument("--path", default="/aicms/")
    args = ap.parse_args()

    stop_at = time.monotonic() + args.duration
    t0 = time.monotonic()
    samples = []
    sampler_task = asyncio.create_task(sampler(args.pid, stop_at, t0, samples))
    workers = [asyncio.create_task(steady_worker(stop_at, args.path))
               for _ in range(STEADY_CONNS)]
    churn = asyncio.create_task(churn_worker(stop_at, args.path, t0))

    await asyncio.gather(sampler_task, churn, *workers)

    rss, handles = server_snapshot(args.pid)
    dur_min = (time.monotonic() - t0) / 60.0
    summary = {
        "path": args.path,
        "duration_min": round(dur_min, 2),
        "ok": Stats.ok, "err": Stats.err, "timeouts": Stats.timeouts,
        "non_200": Stats.non_200,
        "handshakes": Stats.handshakes,
        "waves": int((Stats.handshakes - STEADY_CONNS) / CHURN_CONNS),
        "rss_mb_final": round(rss / 1048576, 1) if rss else None,
        "handles_final": handles,
        "slope_rss_mb_per_min": round(slope_per_min(samples, "rss_mb") or 0.0, 3),
        "slope_handles_per_min": round(slope_per_min(samples, "handles") or 0.0, 1),
        "p50_ms_final": pctl(list(Stats.lat_ms), 50),
        "p99_ms_final": pctl(list(Stats.lat_ms), 99),
        "samples": samples,
    }
    out = "bench/soak_h3_proxy_results.json"
    json.dump(summary, open(out, "w"), indent=1)
    print("SOAK_DONE " + json.dumps({k: v for k, v in summary.items() if k != "samples"}))
    print("saved:", out)


if __name__ == "__main__":
    asyncio.run(main())
