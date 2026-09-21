#!/usr/bin/env python3
"""Summarize bench/results_ab.json into bench/ab_summary.md.

Produces per-cell medians (rps / p99 / errors / memory), cap64-vs-cap256
deltas, run-to-run spread, the nginx drift control of the same session, and
a drift-calibrated comparison against the historical v0.8.0 matrix session.
"""
import json
import statistics
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

ARMS = ["cap64", "cap256"]

# Historical v0.8.0 session (bench/results.v0.8.0.json, cold matrix medians):
HIST_NGINX = {100: 6219, 1000: 12015, 10000: 10530}
HIST_JUGGLER_V08 = {100: 6129, 1000: 12499, 10000: 10666}
HIST_JUGGLER_V09_CAP64 = {100: 5646, 1000: 8097, 10000: 8416}


def p99_of(r):
    if "p99_ms" in r:
        return r["p99_ms"]
    return r.get("percentiles_ms", {}).get("99")


def rows_for(runs, arm, phase, c):
    return [r for r in runs
            if r["arm"] == arm and r["phase"] == phase and r["concurrency"] == c]


def med(vals):
    vals = [v for v in vals if v is not None]
    return statistics.median(vals) if vals else None


def fmt(v, nd=0):
    if v is None:
        return "—"
    s = f"{v:,.{nd}f}".replace(",", " ")
    return s


def main():
    with open("bench/results_ab.json", encoding="utf-8") as f:
        data = json.load(f)
    runs, controls = data["juggler"], data["nginx_control"]
    conc = data["meta"]["concurrency"]
    rounds = data["meta"]["rounds_per_arm"]

    out = []
    out.append("# Juggler v0.9.0 A/B: upstream pool cap 64 vs 256 (single variable)")
    out.append("")
    out.append(f"**Design:** {rounds} rounds/arm × cold/warm × c={conc}, wrk "
               f"{data['meta']['duration']} per run, 4 threads. One v0.9 binary "
               f"(juggler:bench); between arms ONLY `pool_cap_per_upstream` flips "
               f"(64 vs 256, admin API). Startup prewarm = 256 sockets in BOTH arms, "
               f"floor 32, balancer leastconn — the clamp is the only variable. "
               f"Fresh container per round. nginx interleaved as the drift control.")
    out.append("")

    out.append("## Medians per cell (over all rounds)")
    out.append("")
    out.append("| phase | c | cap64 rps | cap256 rps | Δ(cap256 vs 64) | cap64 p99 | cap256 p99 | cap64 errs | cap256 errs | mem64 MiB | mem256 MiB |")
    out.append("|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|")
    for phase in ("cold", "warm"):
        for c in conc:
            m = {}
            for arm in ARMS:
                rs = rows_for(runs, arm, phase, c)
                m[arm] = {
                    "rps": med([r["rps"] for r in rs]),
                    "p99": med([p99_of(r) for r in rs]),
                    "errs": med([r["errors"] for r in rs]),
                    "mem": med([r.get("mem_median_mib") for r in rs]),
                }
            r64, r256 = m["cap64"]["rps"], m["cap256"]["rps"]
            delta = f"{(r256 / r64 - 1) * 100:+.0f}%" if r64 and r256 else "—"
            out.append(
                f"| {phase} | {c} | {fmt(r64)} | {fmt(r256)} | {delta} | "
                f"{fmt(m['cap64']['p99'])} | {fmt(m['cap256']['p99'])} | "
                f"{fmt(m['cap64']['errs'])} | {fmt(m['cap256']['errs'])} | "
                f"{fmt(m['cap64']['mem'], 1)} | {fmt(m['cap256']['mem'], 1)} |")
    out.append("")

    out.append("## Run-to-run spread (max/min rps per cell)")
    out.append("")
    out.append("| phase | c | cap64 | cap256 |")
    out.append("|---|---:|---:|---:|")
    for phase in ("cold", "warm"):
        for c in conc:
            s = {}
            for arm in ARMS:
                vals = [r["rps"] for r in rows_for(runs, arm, phase, c)]
                s[arm] = f"{max(vals) / min(vals):.2f}×" if vals and min(vals) > 0 else "—"
            out.append(f"| {phase} | {c} | {s['cap64']} | {s['cap256']} |")
    out.append("")

    out.append("## nginx drift control (same session, interleaved)")
    out.append("")
    for phase in ("cold", "warm"):
        for c in conc:
            vals = [r["rps"] for r in controls
                    if r["phase"] == phase and r["concurrency"] == c]
            if vals:
                out.append(f"- {phase} c={c}: median **{fmt(med(vals))}** rps "
                           f"({len(vals)} runs, {min(vals):.0f}–{max(vals):.0f})")
    out.append("")

    out.append("## Drift-calibrated Juggler (cold medians)")
    out.append("")
    out.append("Juggler's cold median rescaled by the same-session nginx control to the "
               "historical session's nginx level (`juggler × hist_nginx / nginx_session`). "
               "This removes the between-session drift before comparing with v0.8.0.")
    out.append("")
    out.append("| c | v0.8.0 (hist) | v0.9 cap64 (hist matrix) | v0.9 cap64 (this run) | v0.9 cap256 (this run) | v0.9 cap256 restored |")
    out.append("|---:|---:|---:|---:|---:|---:|")
    for c in conc:
        j64 = med([r["rps"] for r in rows_for(runs, "cap64", "cold", c)])
        j256 = med([r["rps"] for r in rows_for(runs, "cap256", "cold", c)])
        n_ctl = med([r["rps"] for r in controls if r["phase"] == "cold" and r["concurrency"] == c])
        hist_n = HIST_NGINX.get(c)
        j64_r = j64 * hist_n / n_ctl if (j64 and n_ctl and hist_n) else None
        j256_r = j256 * hist_n / n_ctl if (j256 and n_ctl and hist_n) else None
        out.append(
            f"| {c} | {fmt(HIST_JUGGLER_V08.get(c))} | {fmt(HIST_JUGGLER_V09_CAP64.get(c))} | "
            f"{fmt(j64)} | {fmt(j256)} | **{fmt(j256_r)}** |")
    out.append("")

    text = "\n".join(out)
    with open("bench/ab_summary.md", "w", encoding="utf-8") as f:
        f.write(text)
    print(text)


if __name__ == "__main__":
    main()
