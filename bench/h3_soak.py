#!/usr/bin/env python3
"""Hourly H3 soak: steady traffic + periodic churn waves, resource-leak watch.

Two independent workloads share one event loop against the bench instance
(127.0.0.1:9443):
  steady : 8 long-lived QUIC connections, sequential GETs, forever
  churn  : every CHURN_PERIOD a wave of CHURN_CONNS short-lived connections
           does one GET each and exits (new UDP 4-tuples each wave)

Every SAMPLE_EVERY the driver records: client counters, server RSS and
handle count (via PowerShell for the given PID), h3_connections_total from
/metrics. At the end it prints a JSON summary with linear-slope estimates
per resource (units/minute) — nonzero positive slopes with traffic present
indicate leaks.

Usage: python bench/h3_soak.py --pid <server pid> --duration 3600
"""
import argparse
import asyncio
import json
import ssl
import subprocess
import sys
import time

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import QuicEvent

HOST, PORT = "127.0.0.1", 9443
AUTHORITY = f"{HOST}:{PORT}"
STEADY_CONNS = 8
CHURN_CONNS = 200
CHURN_PERIOD = 300.0
REQ_TIMEOUT = 10.0
SAMPLE_EVERY = 60.0


class H3Client(QuicConnectionProtocol):
    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self._http = H3Connection(self._quic)
        self._waiter = None
        self.status = None
        self.body = b""

    def quic_event_received(self, event: QuicEvent):
        for ev in self._http.handle_event(event):
            if isinstance(ev, HeadersReceived):
                for k, v in ev.headers:
                    if k == b":status":
                        self.status = int(v.decode())
                if ev.stream_ended and self._waiter and not self._waiter.done():
                    self._waiter.set_result(None)
            elif isinstance(ev, DataReceived):
                self.body += ev.data
                if ev.stream_ended and self._waiter and not self._waiter.done():
                    self._waiter.set_result(None)

    def connection_lost(self, exc):
        if self._waiter and not self._waiter.done():
            self._waiter.set_exception(exc or ConnectionError("closed"))
        super().connection_lost(exc)

    async def get(self, path: str):
        sid = self._quic.get_next_available_stream_id()
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
        self._waiter = asyncio.get_event_loop().create_future()
        self.transmit()
        await asyncio.wait_for(self._waiter, timeout=REQ_TIMEOUT)


def make_cfg() -> QuicConfiguration:
    cfg = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    cfg.verify_mode = ssl.CERT_NONE
    return cfg


class Stats:
    ok = 0
    err = 0
    timeouts = 0
    handshakes = 0


async def steady_worker(stop_at, path):
    while time.monotonic() < stop_at:
        Stats.handshakes += 1
        try:
            async with connect(HOST, PORT, configuration=make_cfg(), create_protocol=H3Client) as c:
                while time.monotonic() < stop_at:
                    try:
                        await c.get(path)
                        Stats.ok += 1
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
    # connect() returns an async context manager, not a coroutine: enter each
    # one explicitly so gather() gets real awaitables.
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
            await s.get(path)
            Stats.ok += 1
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
    # "<rss_bytes> <handles>" — plain string, no f-string quoting traps.
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


def h3_conn_total():
    # /metrics is served by the data plane (plain port), not the admin UI.
    try:
        import urllib.request
        with urllib.request.urlopen("http://127.0.0.1:9085/metrics", timeout=4) as r:
            for line in r.read().decode().splitlines():
                if line.startswith("h3_connections_total"):
                    return int(float(line.split()[1]))
    except Exception:
        return None
    return None


async def sampler(pid, stop_at, t0, samples):
    while time.monotonic() < stop_at:
        await asyncio.sleep(SAMPLE_EVERY)
        rss, handles = server_snapshot(pid)
        samples.append({
            "t_min": round((time.monotonic() - t0) / 60.0, 2),
            "ok": Stats.ok, "err": Stats.err, "timeouts": Stats.timeouts,
            "handshakes": Stats.handshakes,
            "rss_mb": round(rss / 1048576, 1) if rss else None,
            "handles": handles,
            "h3_conns_total": h3_conn_total(),
        })
        s = samples[-1]
        print(f"[t={s['t_min']:6.1f}m] ok={s['ok']} err={s['err']} hs={s['handshakes']} "
              f"rss={s['rss_mb']}MB handles={s['handles']} h3_total={s['h3_conns_total']}",
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
    return sum((p[0] - mx) * (p[1] - my) for p in pts) / denom  # units per minute


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pid", type=int, required=True)
    ap.add_argument("--duration", type=float, default=3600.0)
    ap.add_argument("--path", default="/healthz")
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
    rss_slope = slope_per_min(samples, "rss_mb")
    handle_slope = slope_per_min(samples, "handles")
    summary = {
        "duration_min": round(dur_min, 2),
        "ok": Stats.ok, "err": Stats.err, "timeouts": Stats.timeouts,
        "handshakes": Stats.handshakes,
        "waves": CHURN_CONNS and int((Stats.handshakes - STEADY_CONNS) / CHURN_CONNS),
        "rss_mb_final": round(rss / 1048576, 1) if rss else None,
        "handles_final": handles,
        "slope_rss_mb_per_min": round(rss_slope, 3) if rss_slope is not None else None,
        "slope_handles_per_min": round(handle_slope, 1) if handle_slope is not None else None,
        "samples": samples,
    }
    out = "bench/soak_h3_results.json"
    json.dump(summary, open(out, "w"), indent=1)
    print("SOAK_DONE " + json.dumps({k: v for k, v in summary.items() if k != "samples"}))
    print("saved:", out)


if __name__ == "__main__":
    asyncio.run(main())
