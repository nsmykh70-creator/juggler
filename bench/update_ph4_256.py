#!/usr/bin/env python3
"""Assemble bench/results_ddos_ph34_256.json: slowloris redo + probes on the
rebuilt cap=256 v1.0.0 image."""
import json

ph = json.load(open("bench/results_ddos_ph34.json", encoding="utf-8"))
ph["protocol_abuse_256"] = {
    "header_64k": "HTTP/1.1 431 Request Header Fields Too Large (32 KiB cap enforced)",
    "body_8mb": "connection reset mid-body; next request 200 OK",
    "garbage_10k": "no response, connection held; server alive afterwards",
    "pipeline_proper_x10": "10/10 responses (parse-before-read fix confirmed in rebuilt image; stale image gave 1)",
    "slow_read_20s": "bytes:191 — streams once the head completed",
}
out = {
    "meta": {"image": "juggler:bench rebuilt from main @ 965a375 (v1.0.0 + pipelining fix)",
             "prewarm_target_cap": 256, "pool_cap_per_upstream": 4096, "prewarm_floor": 32},
    "slowloris": ph["slowloris"],
    "protocol_abuse": ph["protocol_abuse_256"],
}
json.dump(out, open("bench/results_ddos_ph34_256.json", "w", encoding="utf-8"), indent=1)
print("written bench/results_ddos_ph34_256.json")
