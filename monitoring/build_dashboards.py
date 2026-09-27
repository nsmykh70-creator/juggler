#!/usr/bin/env python3
"""Generate provision-ready dashboards from docs/grafana-dashboard.json.

Emits two dashboards into monitoring/dashboards/:

- juggler.json           — the main dashboard. Every selector is scoped with
  job!~"juggler_(handoff|reuseport)" so the bench A/B instances never
  pollute production KPI panels.
- juggler-ab-accept.json — the handoff-vs-reuseport A/B dashboard: per-shard
  active connections, max/mean imbalance ratio, and totals, split by the
  accept_mode label Prometheus attaches to the two bench scrape jobs
  (monitoring/prometheus.yml).

Grafana file provisioning cannot resolve the __inputs datasource placeholder
used by the interactive import flow, so every panel is bound to the
provisioned datasource by UID (juggler-main).

Run:  python3 monitoring/build_dashboards.py
"""
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
SRC = os.path.join(REPO, "docs", "grafana-dashboard.json")
OUT_DIR = os.path.join(HERE, "dashboards")
PROV_DIR = os.path.join(HERE, "provisioning", "dashboards")

DS = {"type": "prometheus", "uid": "juggler-main"}

EXCLUDE = 'job!~"juggler_(handoff|reuseport)"'

# Every metric family the dashboard queries; bare (selector-less) uses get
# a `{...}` matcher appended so no series escapes the scope.
DASH_METRICS = [
    "http_requests_total",
    "http_response_seconds_bucket",
    "rate_limit_rejected_total",
    "h3_connections_total",
    "shard_active_conns",
    "shard_forecast_conns",
    "upstream_requests_total",
    "upstream_pool_size",
    "upstream_pool_reuses_total",
    "upstream_stale_retries_total",
    "upstream_failover_total",
    "upstream_health_state",
    "keepalive_connections_total",
    "active_upstream_connections",
]


def scope(expr, matcher):
    """Insert `matcher` into every selector in the expression.

    Handles both existing `{...}` label lists (skipping ones that already
    pin a job) and bare metric names with no selector at all.
    """

    def inject(m):
        labels = m.group(1).strip()
        if "job=" in labels:
            return m.group(0)
        if not labels:
            return "{" + matcher + "}"
        return "{" + labels + ", " + matcher + "}"

    out = re.sub(r"\{([^{}]*)\}", inject, expr)
    for metric in DASH_METRICS:
        out = re.sub(
            r"\b" + metric + r"\b(?!\{)",
            metric + "{" + matcher + "}",
            out,
        )
    return out


def bind(obj):
    if isinstance(obj, dict):
        for k, v in list(obj.items()):
            if k == "datasource" and (
                v is None
                or v == "${DS_JUGGLER}"
                or (isinstance(v, dict) and v.get("uid") == "${DS_JUGGLER}")
            ):
                obj[k] = DS
            else:
                bind(v)
    elif isinstance(obj, list):
        for x in obj:
            bind(x)


def target(i, expr, legend):
    return {
        "refId": chr(ord("A") + i),
        "expr": expr,
        "legendFormat": legend,
        "datasource": DS,
    }


def ab_dashboard():
    """Handoff-vs-reuseport comparison, built explicitly."""
    hand = 'shard_active_conns{job="juggler_handoff"}'
    reuse = 'shard_active_conns{job="juggler_reuseport"}'
    imbal = lambda sel: (
        f"max without (shard) ({sel}) / clamp_min(avg without (shard) ({sel}), 1e-9)"
    )
    panels = [
        {"id": 1, "type": "timeseries", "title": "Per-shard active conns",
         "datasource": DS, "gridPos": {"h": 9, "w": 24, "x": 0, "y": 0},
         "fieldConfig": {"defaults": {"unit": "short"}, "overrides": []},
         "options": {},
         "description": "Live per-shard connection load for both accept "
                        "modes: handoff (event-time Pythia steering) vs "
                        "kernel SO_REUSEPORT.",
         "targets": [target(0, hand, "handoff s{{shard}}"),
                     target(1, reuse, "reuseport s{{shard}}")]},
        {"id": 2, "type": "timeseries", "title": "Shard imbalance max/mean",
         "datasource": DS, "gridPos": {"h": 8, "w": 12, "x": 0, "y": 9},
         "fieldConfig": {"defaults": {"unit": "short"}, "overrides": []},
         "options": {},
         "description": "Ratio of the busiest shard to the mean, per mode. "
                        "1.0 = perfectly even. Snapshot ratio: event-time "
                        "handoff intentionally packs conns into "
                        "soonest-free shards — judge it by request latency, "
                        "not by this gauge alone.",
         "targets": [target(0, imbal(hand), "handoff"),
                     target(1, imbal(reuse), "reuseport")]},
        {"id": 3, "type": "timeseries", "title": "Total connections",
         "datasource": DS, "gridPos": {"h": 8, "w": 12, "x": 12, "y": 9},
         "fieldConfig": {"defaults": {"unit": "short"}, "overrides": []},
         "options": {},
         "targets": [target(0, f"sum({hand})", "handoff"),
                     target(1, f"sum({reuse})", "reuseport")]},
    ]
    return {
        "uid": "juggler-ab-accept",
        "title": "Juggler A/B: accept mode (handoff vs reuseport)",
        "tags": ["juggler", "accept-mode"],
        "timezone": "browser",
        "schemaVersion": 39,
        "version": 1,
        "refresh": "5s",
        "time": {"from": "now-15m", "to": "now"},
        "panels": panels,
    }


def main():
    dash = json.load(open(SRC, encoding="utf-8"))
    dash.pop("__inputs", None)
    bind(dash)

    # Main dashboard: exclude A/B bench jobs from every selector.
    for p in dash.get("panels", []):
        for t in p.get("targets", []):
            if "expr" in t and t["expr"]:
                t["expr"] = scope(t["expr"], EXCLUDE)

    os.makedirs(OUT_DIR, exist_ok=True)
    with open(os.path.join(OUT_DIR, "juggler.json"), "w", encoding="utf-8") as f:
        json.dump(dash, f, ensure_ascii=False, indent=2)
        f.write("\n")
    with open(os.path.join(OUT_DIR, "juggler-ab-accept.json"), "w", encoding="utf-8") as f:
        json.dump(ab_dashboard(), f, ensure_ascii=False, indent=2)
        f.write("\n")

    os.makedirs(PROV_DIR, exist_ok=True)
    with open(os.path.join(PROV_DIR, "dashboards.yml"), "w", encoding="utf-8") as f:
        f.write(
            "apiVersion: 1\n\n"
            "providers:\n"
            "  - name: juggler\n"
            "    orgId: 1\n"
            "    folder: Juggler\n"
            "    type: file\n"
            "    disableDeletion: false\n"
            "    updateIntervalSeconds: 30\n"
            "    options:\n"
            "      path: /var/lib/grafana/dashboards\n"
        )
    n = len(dash.get("panels", []))
    print(f"wrote monitoring/dashboards/juggler.json ({n} panels, bench jobs excluded)")
    print("wrote monitoring/dashboards/juggler-ab-accept.json (3 panels)")
    print("wrote provisioning/dashboards/dashboards.yml")


if __name__ == "__main__":
    main()
