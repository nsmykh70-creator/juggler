#!/usr/bin/env python3
"""Juggler H2 vs H3 load test.

Both modes drive the same request stream against the same endpoint:
  - N concurrent connections, each running its own sequential request loop
    (connection stays open; one in-flight request per connection).
  - Small GETs; per-request latency recorded.

H3: aioquic with a custom QuicConnectionProtocol routing QUIC events into
    H3Connection (the pattern from aioquic's official http3_client example,
    already proven against Juggler by bench/h3_fetch.py).
H2: httpx AsyncClient with http2=True and max_connections=N.

--churn  reconnects each H3 connection every ~1.5 s (connection churn /
         handshake storm probe) instead of holding one long connection.

Output (single JSON line):
{"mode","conns","seconds","ok","err","rps","p50_ms","p99_ms","status_counts"}
  ok    = completed responses (any status; see status_counts)
  err   = transport failures (timeout, connection lost, handshake error)

Usage:
  python bench/h3_load.py h3 127.0.0.1 8443 /bench 32 10
  python bench/h3_load.py h2 127.0.0.1 8443 /bench 32 10
"""
import argparse
import asyncio
import json
import ssl
import sys
import time
from collections import Counter

PATH = "/bench"


def parse_args():
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["h2", "h3"])
    ap.add_argument("host")
    ap.add_argument("port", type=int)
    ap.add_argument("path")
    ap.add_argument("conns", type=int)
    ap.add_argument("seconds", type=float)
    ap.add_argument("--churn", action="store_true",
                    help="H3: reconnect each connection every ~1.5 s")
    return ap.parse_args()


def percentile(sorted_ms, p):
    if not sorted_ms:
        return None
    idx = min(int(len(sorted_ms) * p / 100), len(sorted_ms) - 1)
    return round(sorted_ms[idx], 2)


# ------------------------------------------------------------------ HTTP/3 --
def run_h3(host, port, conns, seconds, path=PATH, verify=False, churn=False):
    from aioquic.asyncio.client import connect
    from aioquic.asyncio.protocol import QuicConnectionProtocol
    from aioquic.h3.connection import H3_ALPN, H3Connection
    from aioquic.h3.events import DataReceived, HeadersReceived
    from aioquic.quic.configuration import QuicConfiguration
    from aioquic.quic.events import QuicEvent

    async def amain():
        cfg = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
        if not verify:
            cfg.verify_mode = ssl.CERT_NONE

        statuses = Counter()
        lat = []
        err = {"n": 0}
        deadline = time.monotonic() + seconds
        authority = f"{host}:{port}"

        class Client(QuicConnectionProtocol):
            def __init__(self, *a, **kw):
                super().__init__(*a, **kw)
                self._http = H3Connection(self._quic)
                self._waiters = {}  # stream_id -> Future

            def quic_event_received(self, event: QuicEvent):
                try:
                    events = self._http.handle_event(event)
                except Exception:
                    return
                for ev in events:
                    sid = getattr(ev, "stream_id", None)
                    w = self._waiters.get(sid)
                    if w is None or w.done():
                        continue
                    if isinstance(ev, HeadersReceived):
                        for k, v in ev.headers:
                            if k == b":status":
                                w.status = int(v.decode())
                    elif isinstance(ev, DataReceived) and ev.stream_ended:
                        if w.status is not None:
                            lat.append((time.perf_counter() - w.start) * 1000)
                            statuses[w.status] += 1
                        else:
                            err["n"] += 1
                        w.set_result(None)

            def connection_lost(self, exc):
                for w in self._waiters.values():
                    if not w.done():
                        w.set_exception(exc or ConnectionError("h3 conn closed"))
                self._waiters.clear()
                super().connection_lost(exc)

            async def get(self):
                sid = self._quic.get_next_available_stream_id()
                w = asyncio.get_event_loop().create_future()
                w.start = time.perf_counter()
                w.status = None
                self._waiters[sid] = w
                self._http.send_headers(
                    stream_id=sid,
                    headers=[
                        (b":method", b"GET"),
                        (b":scheme", b"https"),
                        (b":authority", authority.encode()),
                        (b":path", path.encode()),
                        (b"user-agent", b"juggler-h3-load/1.0"),
                    ],
                    end_stream=True,
                )
                self.transmit()
                await asyncio.wait_for(w, timeout=15)

        reported = {"n": 0}

        async def one_conn():
            try:
                async with connect(host, port, configuration=cfg,
                                   create_protocol=Client, wait_connected=True) as cl:
                    next_reconnect = time.monotonic() + 1.5 if churn else None
                    while time.monotonic() < deadline:
                        try:
                            await cl.get()
                            if next_reconnect is not None and time.monotonic() >= next_reconnect:
                                break  # exit context manager -> fresh handshake
                        except (asyncio.TimeoutError, Exception):
                            if reported["n"] < 2:
                                reported["n"] += 1
                                import traceback
                                traceback.print_exc()
                            err["n"] += 1
                            await asyncio.sleep(0.01)
            except Exception:
                if reported["n"] < 2:
                    reported["n"] += 1
                    import traceback
                    traceback.print_exc()
                err["n"] += 1

        async def churn_loop():
            # Each worker reconnects every ~1.5 s until the deadline.
            while time.monotonic() < deadline:
                await one_conn()

        workers = churn_loop if churn else one_conn
        await asyncio.gather(*[workers() for _ in range(conns)])

        total = sum(statuses.values())
        lat.sort()
        return {
            "mode": "h3",
            "conns": conns,
            "seconds": seconds,
            "ok": total,
            "err": err["n"],
            "rps": round(total / seconds, 1),
            "p50_ms": percentile(lat, 50),
            "p99_ms": percentile(lat, 99),
            "status_counts": {str(k): v for k, v in sorted(statuses.items())},
        }

    return asyncio.run(amain())


# ------------------------------------------------------------------ HTTP/2 --
def run_h2(host, port, conns, seconds, path=PATH, verify=False):
    import httpx

    async def amain():
        statuses = Counter()
        lat = []
        ok = 0
        err = {"n": 0}
        scheme = "https"
        deadline = time.monotonic() + seconds
        limits = httpx.Limits(max_connections=conns, max_keepalive_connections=conns)
        async with httpx.AsyncClient(http2=True, verify=verify, limits=limits,
                                     timeout=httpx.Timeout(15.0)) as client:

            async def one_conn():
                nonlocal ok
                shown = {"n": 0}
                while time.monotonic() < deadline:
                    t0 = time.perf_counter()
                    try:
                        r = await client.get(f"{scheme}://{host}:{port}{path}")
                        lat.append((time.perf_counter() - t0) * 1000)
                        statuses[r.status_code] += 1
                        if 200 <= r.status_code < 400:
                            ok += 1
                        else:
                            err["n"] += 1
                    except Exception:
                        if shown["n"] < 2:
                            shown["n"] += 1
                            import traceback
                            traceback.print_exc()
                        err["n"] += 1

            await asyncio.gather(*[one_conn() for _ in range(conns)])

        lat.sort()
        return {
            "mode": "h2",
            "conns": conns,
            "seconds": seconds,
            "ok": ok,
            "err": err["n"],
            "rps": round(ok / seconds, 1),
            "p50_ms": percentile(lat, 50),
            "p99_ms": percentile(lat, 99),
            "status_counts": {str(k): v for k, v in sorted(statuses.items())},
        }

    return asyncio.run(amain())


if __name__ == "__main__":
    mode, host, port, path, conns, seconds = (
        sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4],
        int(sys.argv[5]), float(sys.argv[6]),
    )
    verify = False  # self-signed lab certificate
    if mode == "h3":
        res = run_h3(host, port, conns, seconds, path, verify, churn="--churn" in sys.argv)
    elif mode == "h2":
        res = run_h2(host, port, conns, seconds, path, verify)
    else:
        raise SystemExit(f"unknown mode {mode}")
    print(json.dumps(res))
