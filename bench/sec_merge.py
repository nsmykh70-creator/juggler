#!/usr/bin/env python3
"""Merge all security-phase result JSONs (bench/tmp_*, docker-copied) into
one bench/results_security.json keyed by phase."""
import json
import os
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

SRC = "bench"
OUT = "bench/results_security.json"

MAPPING = {
    "phase5_admin_grpc": "tmp_phase5_admin_grpc_results_ph5.json",
    "phase4_ssrf": "tmp_phase4_ssrf_results_ph4.json",
    "phase2_smuggle": "tmp_phase2_smuggle_results_ph12.json",
    "phase1_fuzz": "tmp_phase1_fuzz_results_ph12.json",
    "phase3_exhaust": "tmp_phase3_exhaust_results_ph3.json",
    "phase6_misc": "tmp_phase6_misc_results_ph6.json",
}

out = {"meta": {
    "target": "bench-juggler container: juggler:bench rebuilt 2026-09-22 (v1.0.0 + pipeline fix), prewarm cap=256",
    "exposure_under_test": "admin 0.0.0.0:19090 (Bearer bench), grpc 0.0.0.0:50051 (no auth), data plane 8080",
    "attacker": "bench-attack (python:3.12-alpine) in same docker net",
}, "phases": {}}

for phase, fname in MAPPING.items():
    p = os.path.join(SRC, fname)
    if os.path.exists(p):
        try:
            out["phases"][phase] = json.load(open(p, encoding="utf-8"))
        except json.JSONDecodeError as e:
            out["phases"][phase] = {"error": str(e)}
    else:
        out["phases"][phase] = {"missing": fname}

json.dump(out, open(OUT, "w", encoding="utf-8"), indent=1)

# summary table
rows = []
def emit(phase, section, key, val):
    if not isinstance(val, dict):
        return
    v = val.get("verdict")
    if v is None:
        return
    sev = val.get("severity", "")
    name = val.get("name", key)
    rows.append((phase, section, key, name, v, sev))

for phase, data in out["phases"].items():
    for section, items in data.items():
        if not isinstance(items, dict):
            continue
        for key, val in items.items():
            emit(phase, section, key, val)

fails = [r for r in rows if r[4] == "FAIL"]
warns = [r for r in rows if r[4] == "WARN"]
passes = [r for r in rows if r[4] == "PASS"]
print(f"PASS {len(passes)}  WARN {len(warns)}  FAIL {len(fails)}")
for r in fails + warns:
    print(" ", r[0], r[2], "->", r[4], "|", r[5])
print(f"written {OUT}")
