#!/usr/bin/env python3
"""Assemble bench/results_ddos_ph4.json from the probe run output."""
import json

ph34 = json.load(open("bench/results_ddos_ph34.json", encoding="utf-8"))
# Re-run results (attack container, has python3):
ph4 = {
    "header_64k": "HTTP/1.1 431 Request Header Fields Too Large (MAX_REQUEST_HEAD = 32 KiB; limit enforced, connection closed cleanly)",
    "body_8mb": "ConnectionReset by the server mid-body (no 8 MB buffering; request never reaches routing). Server answered 200 OK on the next connection.",
    "garbage_10k": "no response, connection held until client timeout; server stayed alive and served normal traffic afterwards",
    "pipeline_proper_2": "1 response before fix; 10/10 responses after the pipelining fix (verified on the rebuilt live server)",
    "slow_read_20s": "bytes:191 — server streamed the response once headers completed (no unbounded buffering)",
}
ph34["protocol_abuse_rerun"] = ph4
json.dump(ph34, open("bench/results_ddos_ph34.json", "w", encoding="utf-8"), indent=1)
print("updated results_ddos_ph34.json with rerun probes")
