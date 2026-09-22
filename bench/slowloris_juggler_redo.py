#!/usr/bin/env python3
"""Redo the two juggler slowloris legs (the first run leaked the limited
bucket into the open leg). Appends juggler_limited/juggler_open into
bench/results_ddos_ph34.json, overwriting those two keys."""
import json

import attack_slowloris as A

res = {}

print("--- redo: juggler slowloris, limiter OFF (restored open first)", flush=True)
res["juggler_open"] = A.slowloris_round("juggler", "juggler", 8080, "http://juggler:8080/bench",
                                        limiter_on=False)
print("   ", res["juggler_open"], flush=True)

print("--- redo: juggler slowloris, limiter ON (200/100)", flush=True)
res["juggler_limited"] = A.slowloris_round("juggler", "juggler", 8080, "http://juggler:8080/bench",
                                           limiter_on=True)
print("   ", res["juggler_limited"], flush=True)

A.admin_settings({"rate_limit_tokens": 1_000_000, "rate_limit_refill": 1_000_000})
print("limiter restored", flush=True)

path = "bench/results_ddos_ph34.json"
data = json.load(open(path, encoding="utf-8"))
data["slowloris"].update(res)
json.dump(data, open(path, "w", encoding="utf-8"), indent=1)
print("updated", path, flush=True)
