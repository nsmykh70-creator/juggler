#!/usr/bin/env python3
"""Derive provision-ready dashboard JSON from docs/grafana-dashboard.json.

Grafana file provisioning cannot resolve the __inputs datasource placeholder
that the interactive import flow uses. This script rewrites the dashboard to
bind every panel to the provisioned datasource by UID (juggler-main) and
writes both the dashboard and the provisioning tree.

Run once on the repo (or on container start via command override):
    python3 monitoring/build_dashboards.py
"""
import json
import os
import shutil

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
SRC = os.path.join(REPO, "docs", "grafana-dashboard.json")
OUT_DIR = os.path.join(HERE, "dashboards")
PROV_DIR = os.path.join(HERE, "provisioning", "dashboards")

DS = {"type": "prometheus", "uid": "juggler-main"}


def bind(obj):
    if isinstance(obj, dict):
        for k, v in list(obj.items()):
            if k == "datasource" and (
                v == "${DS_JUGGLER}"
                or v is None
                or (isinstance(v, dict) and v.get("uid") == "${DS_JUGGLER}")
            ):
                obj[k] = DS
            else:
                bind(v)
    elif isinstance(obj, list):
        for x in obj:
            bind(x)


def main():
    dash = json.load(open(SRC, encoding="utf-8"))
    dash.pop("__inputs", None)
    bind(dash)

    os.makedirs(OUT_DIR, exist_ok=True)
    with open(os.path.join(OUT_DIR, "juggler.json"), "w", encoding="utf-8") as f:
        json.dump(dash, f, ensure_ascii=False, indent=2)

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
    print("wrote monitoring/dashboards/juggler.json + provisioning/dashboards/dashboards.yml")


if __name__ == "__main__":
    main()
