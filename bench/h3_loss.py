#!/usr/bin/env python3
"""HTTP/3 resilience lab: UDP proxy injecting loss / network flaps / NAT rebind
between an aioquic H3 client and Juggler's QUIC plane (:8443/udp).

Topology:
    client(QUIC) --UDP--> lossy proxy (:18443) --> Juggler (:8443/udp)

The proxy is transparent (never parses QUIC); damage is injected per scenario:
  lossN   uniform random datagram loss on both legs
  flapT   full blackout (both legs) for T seconds starting at t=15s
  rebind  at t=15s the proxy switches its server-side source port, so Juggler
          sees a new 4-tuple (NAT rebinding) and must run path validation

Client workers each hold one QUIC connection and issue sequential GETs; the
per-request timeout (15s) is longer than any blackout so in-flight requests
survive the outage if the server keeps the stream alive (the QUIC selling
point). Reconnects are counted, never silent.

Usage: python bench/h3_loss.py <scenario> [--conns N] [--dur S]
Scenarios: loss0 loss1 loss5 loss10 loss20 flap2 flap5 flap10 rebind
"""
import argparse
import asyncio
import json
import random
import socket
import ssl
import statistics
import sys
import threading
import time

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import QuicEvent

SERVER = ("127.0.0.1", 8443)
PROXY_BIND = ("127.0.0.1", 18443)
REQ_TIMEOUT = 15.0  # > longest blackout: lets streams survive the outage

PRESETS = {
    "loss0": dict(loss=0.0, dur=30),
    "loss1": dict(loss=0.01, dur=30),
    "loss5": dict(loss=0.05, dur=30),
    "loss10": dict(loss=0.10, dur=30),
    "loss20": dict(loss=0.20, dur=30),
    "flap2": dict(loss=0.0, flap_at=15.0, flap_dur=2.0, dur=40),
    "flap5": dict(loss=0.0, flap_at=15.0, flap_dur=5.0, dur=45),
    "flap10": dict(loss=0.0, flap_at=15.0, flap_dur=10.0, dur=50),
    "rebind": dict(loss=0.0, rebind_at=15.0, dur=35),
}


class Damage:
    """Scenario state shared with the proxy threads (GIL-safe field reads)."""

    def __init__(self, loss, flap_at=None, flap_dur=0.0):
        self.loss = loss
        self.flap_at = flap_at
        self.flap_dur = flap_dur
        self.both_legs = True
        self.t0: float | None = None  # test start, set before proxy spawns
        self.dropped = 0
        self.forwarded = 0
        self._lock = threading.Lock()
        self._rng = random.Random(20260926)

    def _blackout(self, now):
        if self.flap_at is None or self.t0 is None:
            return False
        elapsed = now - self.t0
        return self.flap_at <= elapsed < self.flap_at + self.flap_dur

    def drop_forward(self, now):  # client -> server
        if self._blackout(now) or self._rng.random() < self.loss:
            with self._lock:
                self.dropped += 1
            return True
        with self._lock:
            self.forwarded += 1
        return False

    def drop_reverse(self, now):  # server -> client
        if (self.both_legs and self._blackout(now)) or self._rng.random() < self.loss:
            with self._lock:
                self.dropped += 1
            return True
        return False


class UdpProxy:
    """NAT-style datagram forwarder.

    Each client (source addr) gets its own pair of server-side sockets: "a"
    (initial path) and "b" (post-rebind path). Replies are sent back from the
    same socket the request went out on, so the 4-tuple stays symmetric per
    leg. `rebind` switches forwarding from "a" to "b", which changes the
    server-visible source port = NAT rebinding (server must path-validate)."""

    def __init__(self, damage: Damage):
        self.damage = damage
        self.running = True
        self.active = "a"
        self.mappings: dict = {}
        self._lock = threading.Lock()

        self.sock_cli = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock_cli.bind(PROXY_BIND)
        self.sock_cli.settimeout(0.5)
        self.threads = [threading.Thread(target=self._fwd_loop, daemon=True)]
        self.threads[0].start()

    def _mapping_for(self, client_addr):
        with self._lock:
            m = self.mappings.get(client_addr)
            if m is None:
                m = {}
                for name in ("a", "b"):
                    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                    s.bind(("127.0.0.1", 0))
                    s.settimeout(0.5)
                    t = threading.Thread(
                        target=self._rev_loop, args=(s, client_addr), daemon=True
                    )
                    t.start()
                    self.threads.append(t)
                    m[name] = s
                self.mappings[client_addr] = m
            return m

    def _fwd_loop(self):
        while self.running:
            try:
                data, addr = self.sock_cli.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                break
            m = self._mapping_for(addr)
            if self.damage.drop_forward(time.monotonic()):
                continue
            try:
                m[self.active].sendto(data, SERVER)
            except OSError:
                pass

    def _rev_loop(self, sock, client_addr):
        while self.running:
            try:
                data, _ = sock.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                break
            if self.damage.drop_reverse(time.monotonic()):
                continue
            try:
                self.sock_cli.sendto(data, client_addr)
            except OSError:
                pass

    def stop(self):
        self.running = False
        try:
            self.sock_cli.close()
        except OSError:
            pass
        for m in self.mappings.values():
            for s in m.values():
                try:
                    s.close()
                except OSError:
                    pass


class H3Client(QuicConnectionProtocol):
    """Same proven pattern as bench/h3_fetch.py, reused per request."""

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

    async def get(self, authority: str, path: str):
        sid = self._quic.get_next_available_stream_id()
        self._http.send_headers(
            stream_id=sid,
            headers=[
                (b":method", b"GET"),
                (b":scheme", b"https"),
                (b":authority", authority.encode()),
                (b":path", path.encode()),
                (b"user-agent", b"juggler-h3-losslab/1.0"),
            ],
            end_stream=True,
        )
        self._waiter = asyncio.get_event_loop().create_future()
        self.status = None
        self.body = b""
        self.transmit()
        await asyncio.wait_for(self._waiter, timeout=REQ_TIMEOUT)


class Stats:
    def __init__(self):
        self.ok = []
        self.status_err = []
        self.timeouts = 0
        self.conn_lost = 0
        self.reconnects = 0
        self.handshakes = 0
        self.handshake_fail = 0

    def merge(self, other):
        self.ok.extend(other.ok)
        self.status_err.extend(other.status_err)
        self.timeouts += other.timeouts
        self.conn_lost += other.conn_lost
        self.reconnects += other.reconnects
        self.handshakes += other.handshakes
        self.handshake_fail += other.handshake_fail


async def worker(host, path, authority, stop_at, stats: Stats):
    """One QUIC connection at a time, sequential requests, honest reconnects."""
    cfg = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN, idle_timeout=30.0)
    cfg.verify_mode = ssl.CERT_NONE
    while time.monotonic() < stop_at:
        try:
            async with connect(host, PROXY_BIND[1],
                               configuration=cfg, create_protocol=H3Client) as cli:
                stats.handshakes += 1
                while time.monotonic() < stop_at:
                    t0 = time.perf_counter()
                    try:
                        await cli.get(authority, path)
                        dt = time.perf_counter() - t0
                        if cli.status == 200:
                            stats.ok.append(dt)
                        else:
                            stats.status_err.append(cli.status)
                    except asyncio.TimeoutError:
                        stats.timeouts += 1
                        break  # stream wedged -> new connection
                    except Exception:
                        stats.conn_lost += 1
                        break  # QUIC connection died -> new connection
        except Exception:
            stats.handshake_fail += 1
            await asyncio.sleep(0.2)
        if time.monotonic() < stop_at:
            stats.reconnects += 1


def pct(sorted_vals, p):
    if not sorted_vals:
        return None
    idx = min(len(sorted_vals) - 1, int(round(p / 100.0 * (len(sorted_vals) - 1))))
    return sorted_vals[idx]


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("scenario", choices=sorted(PRESETS))
    ap.add_argument("--conns", type=int, default=8)
    ap.add_argument("--path", default="/healthz")
    args = ap.parse_args()

    preset = PRESETS[args.scenario]
    dur = preset["dur"]
    damage = Damage(preset["loss"], preset.get("flap_at"), preset.get("flap_dur", 0.0))
    proxy = UdpProxy(damage)
    time.sleep(0.3)
    damage.t0 = time.monotonic()

    authority = f"127.0.0.1:{PROXY_BIND[1]}"
    t_start = time.monotonic()
    stop_at = t_start + dur
    stats = Stats()

    tasks = [
        asyncio.create_task(
            worker("127.0.0.1", args.path, authority, stop_at, stats)
        )
        for i in range(args.conns)
    ]

    async def progress():
        while True:
            await asyncio.sleep(5)
            el = time.monotonic() - t_start
            print(
                f"[t+{el:5.1f}s] ok={len(stats.ok)} timeouts={stats.timeouts} "
                f"lost={stats.conn_lost} hs_fail={stats.handshake_fail} "
                f"recon={stats.reconnects}",
                file=sys.stderr,
                flush=True,
            )

    prog = asyncio.create_task(progress())

    # Scenario trigger threads are just time checks from the main task.
    if preset.get("rebind_at") is not None:
        async def rebind_later():
            await asyncio.sleep(preset["rebind_at"])
            proxy.active = "b"
        asyncio.create_task(rebind_later())

    await asyncio.gather(*tasks)
    wall = time.monotonic() - t_start
    prog.cancel()

    proxy.stop()
    s = sorted(stats.ok)
    out = {
        "scenario": args.scenario,
        "conns": args.conns,
        "duration_s": round(wall, 1),
        "loss": preset["loss"],
        "flap_at_s": preset.get("flap_at"),
        "flap_dur_s": preset.get("flap_dur"),
        "rebind_at_s": preset.get("rebind_at"),
        "requests_ok": len(s),
        "rps": round(len(s) / wall, 1),
        "status_err": len(stats.status_err),
        "timeouts": stats.timeouts,
        "conn_lost": stats.conn_lost,
        "reconnects": stats.reconnects,
        "handshakes": stats.handshakes,
        "handshake_fail": stats.handshake_fail,
        "proxy_forwarded": damage.forwarded,
        "proxy_dropped": damage.dropped,
        "lat_ms": {
            "p50": round(pct(s, 50) * 1000, 1) if s else None,
            "p95": round(pct(s, 95) * 1000, 1) if s else None,
            "p99": round(pct(s, 99) * 1000, 1) if s else None,
            "max": round(s[-1] * 1000, 1) if s else None,
            "mean": round(statistics.fmean(s) * 1000, 1) if s else None,
        },
    }
    if preset.get("flap_dur"):
        survived = sum(1 for v in s if v > preset["flap_dur"])
        out["survived_flap"] = survived
    print(json.dumps(out, ensure_ascii=False))


if __name__ == "__main__":
    asyncio.run(main())
