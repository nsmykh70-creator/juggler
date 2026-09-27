#!/usr/bin/env python3
"""Add the handoff-vs-reuseport A/B section to docs/grafana-dashboard.json.

Idempotent: existing panels with the A/B titles are replaced in place on
re-run. The A/B panels rely on the accept_mode metric label that Prometheus
attaches per scrape job (monitoring/prometheus.yml: job handoff / reuseport),
while shard_active_conns itself carries accept_mode="handoff" or "" from the
server env (JUGGLER_ACCEPT_MODE).

Run:  python monitoring/add_ab_panels.py && python monitoring/build_dashboards.py
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(os.path.dirname(HERE), "docs", "grafana-dashboard.json")

DS = {"type": "prometheus", "uid": "juggler-main"}

ROW_TITLE = "A/B: accept mode (handoff vs reuseport)"
AB_TITLES = {
    ROW_TITLE,
    "Per-shard active conns (A/B)",
    "Shard imbalance max/mean (A/B)",
    "Total connections (A/B)",
}

# Label provenance: Prometheus attaches accept_mode per scrape job
# (monitoring/prometheus.yml). The reuseport instance runs without
# JUGGLER_ACCEPT_MODE in its env, matching the reuseport default.
REUSE_SELECTOR = 'shard_active_conns{accept_mode="reuseport"}'
HAND_SELECTOR = 'shard_active_conns{accept_mode="handoff"}'


def panel(pid, title, ptype, x, y, w, h, targets, unit=None, desc=None):
    p = {
        "id": pid,
        "type": ptype,
        "title": title,
        "datasource": DS,
        "gridPos": {"h": h, "w": w, "x": x, "y": y},
        "fieldConfig": {"defaults": {}, "overrides": []},
        "options": {},
        "targets": [
            {
                "refId": chr(ord("A") + i),
                "expr": t[0],
                "legendFormat": t[1],
                "datasource": DS,
            }
            for i, t in enumerate(targets)
        ],
    }
    if unit:
        p["fieldConfig"]["defaults"]["unit"] = unit
    if desc:
        p["description"] = desc
    return p


def main():
    dash = json.load(open(SRC, encoding="utf-8"))
    panels = dash.get("panels", [])
    # Drop previous incarnation of the section (idempotency).
    panels = [p for p in panels if p.get("title") not in AB_TITLES]

    # Anchor Y: after the lowest existing panel.
    bottom = max(
        (p.get("gridPos", {}).get("y", 0) + p.get("gridPos", {}).get("h", 0))
        for p in panels
    )
    next_id = max((p.get("id") or 0 for p in panels), default=0) + 1

    imbal = lambda sel: (
        f"max without (shard) ({sel}) / "
        f"clamp_min(avg without (shard) ({sel}), 1e-9)"
    )
    panels += [
        {"id": next_id, "type": "row", "title": ROW_TITLE,
         "gridPos": {"h": 1, "w": 24, "x": 0, "y": bottom}},
        panel(
            next_id + 1, "Per-shard active conns (A/B)", "timeseries",
            0, bottom + 1, 24, 8,
            [
                (HAND_SELECTOR, "handoff s{{shard}}"),
                (REUSE_SELECTOR, "reuseport s{{shard}}"),
            ],
            unit="short",
            desc="Live per-shard connection load for both accept modes. "
                 "handoff (event-time Pythia steering) vs kernel SO_REUSEPORT.",
        ),
        panel(
            next_id + 2, "Shard imbalance max/mean (A/B)", "timeseries",
            0, bottom + 9, 12, 7,
            [
                (imbal(HAND_SELECTOR), "handoff"),
                (imbal(REUSE_SELECTOR), "reuseport"),
            ],
            unit="percentunit" if False else "short",
            desc="Ratio of the busiest shard to the mean, per mode. "
                 "1.0 = perfectly even; higher = lopsided distribution.",
        ),
        panel(
            next_id + 3, "Total connections (A/B)", "timeseries",
            12, bottom + 9, 12, 7,
            [
                (f"sum({HAND_SELECTOR})", "handoff"),
                (f"sum({REUSE_SELECTOR})", "reuseport"),
            ],
            unit="short",
        ),
    ]
    dash["panels"] = panels

    with open(SRC, "w", encoding="utf-8") as f:
        json.dump(dash, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print(f"docs/grafana-dashboard.json: {len(panels)} panels (A/B section at y={bottom})")


if __name__ == "__main__":
    main()
