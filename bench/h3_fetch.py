#!/usr/bin/env python3
"""Minimal HTTP/3 GET client built on aioquic. Verifies Juggler's h3 plane
end-to-end: QUIC handshake -> headers -> body. Prints status + headers."""
import argparse
import asyncio
import ssl
from typing import Optional

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import QuicEvent


class H3Client(QuicConnectionProtocol):
    def __init__(self, *a, **kw):
        super().__init__(*a, **kw)
        self._http = H3Connection(self._quic)
        self._waiter: Optional[asyncio.Future] = None
        self.status = None
        self.headers = []
        self.body = b""

    def quic_event_received(self, event: QuicEvent):
        for ev in self._http.handle_event(event):
            if isinstance(ev, HeadersReceived):
                self.headers = ev.headers
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
            self._waiter.set_exception(exc or ConnectionError("connection closed"))
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
                (b"user-agent", b"juggler-h3-probe/1.0"),
            ],
            end_stream=True,
        )
        self._waiter = asyncio.get_event_loop().create_future()
        self.transmit()
        # Response events may have already been delivered; don't block forever.
        try:
            await asyncio.wait_for(self._waiter, timeout=10)
        except asyncio.TimeoutError:
            if self.status is None:
                raise


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("host")
    ap.add_argument("port", type=int)
    ap.add_argument("path")
    ap.add_argument("--insecure", action="store_true")
    args = ap.parse_args()

    cfg = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    if args.insecure:
        cfg.verify_mode = ssl.CERT_NONE

    async with connect(
        args.host, args.port, configuration=cfg, create_protocol=H3Client
    ) as client:
        await client.get(f'{args.host}:{args.port}', args.path)
        print(f"HTTP/3 status: {client.status}")
        for k, v in client.headers:
            print(f"{k.decode()}: {v.decode()}")
        print()
        print(client.body.decode(errors="replace")[:400])


if __name__ == "__main__":
    asyncio.run(main())
