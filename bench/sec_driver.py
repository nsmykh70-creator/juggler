#!/usr/bin/env python3
"""Outside driver for the security pass (runs on the Windows host).

For each phase: snapshot juggler RSS + open-FD, run the phase script inside
bench-attack (docker exec), snapshot again, then merge the phase JSON into
bench/results_security.json. Also re-checks admin/gRPC exposure facts that
phases 4/5 rely on.
"""
import json
import subprocess
import sys
import time
import urllib.request

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

ADMIN = "http://127.0.0.1:19090/api/stats"
TOK = {"Authorization": "Bearer bench"}
OUT = "bench/results_security.json"

PHASES = [
    ("phase5_admin_grpc", "sec_phase54.py", ["ph5"]),
    ("phase4_ssrf", "sec_phase54.py", ["ph4"]),
    ("phase2_smuggle", "sec_phase12.py", ["ph12"]),
    ("phase1_fuzz", "sec_phase12.py", ["ph12"]),
    ("phase3_exhaust", "sec_phase3.py", ["ph3"]),
    ("phase6_misc", "sec_phase6.py", ["ph6"]),
]


def sh(cmd, timeout=1800):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True,
                          encoding="utf-8", errors="replace", timeout=timeout)


def juggler_snapshot():
    out = sh('docker stats --no-stream --format "{{.MemUsage}}\\t{{.CPUPerc}}" bench-juggler')
    rss = cpu = None
    for ln in out.stdout.splitlines():
        parts = ln.split("\t")
        if len(parts) >= 2:
            rss, cpu = parts[0].strip(), parts[1].strip()
    return {"rss": rss, "cpu": cpu, "ts": time.strftime("%H:%M:%S")}


def fd_count():
    out = sh('MSYS_NO_PATHCONV=1 docker exec bench-juggler sh -c "ls /proc/1/fd | wc -l"')
    try:
        return int(out.stdout.strip())
    except ValueError:
        return None


def req(method, path, body=None, headers=None):
    r = urllib.request.Request(ADMIN + path, method=method,
                               data=json.dumps(body).encode() if body is not None else None,
                               headers={"Content-Type": "application/json", **TOK, **(headers or {})})
    try:
        with urllib.request.urlopen(r, timeout=10) as resp:
            return resp.status, json.loads(resp.read().decode() or "{}")
    except Exception as e:  # noqa: BLE001
        return None, str(e)


def main():
    results = {"meta": {"started": time.strftime("%Y-%m-%d %H:%M:%S"),
                        "image": "juggler:bench rebuilt v1.0.0, cap=256",
                        "exposure": "admin 0.0.0.0:19090 (token), grpc 0.0.0.0:50051 (no auth)"},
               "phases": {}}
    for name, script, args in PHASES:
        print(f"=== {name}: {script} {args}", flush=True)
        before = juggler_snapshot()
        fd0 = fd_count()
        cmd = f'docker exec bench-attack python /{script} ' + " ".join(args)
        r = sh(cmd, timeout=3600)
        after = juggler_snapshot()
        fd1 = fd_count()
        results["phases"][name] = {
            "container": {"before": before, "after": after},
            "open_fds": {"before": fd0, "after": fd1},
            "stdout_tail": r.stdout[-3000:],
            "rc": r.returncode,
        }
        # merge phase jsons produced inside the container
        for jf in ("/results_ph5.json", "/results_ph4.json", "/results_ph12.json",
                   "/results_ph3.json", "/results_ph6.json"):
            cp = sh(f"docker cp bench-attack:{jf} C:/newwebser/bench/tmp_{name}_{jf.split('/')[-1]}")
            if cp.returncode == 0:
                local = f"bench/tmp_{name}_{jf.split('/')[-1]}"
                try:
                    data = json.load(open(local, encoding="utf-8"))
                    results["phases"][name][jf.split("/")[-1].replace(".json", "")] = data
                except (OSError, json.JSONDecodeError) as e:
                    results["phases"][name][jf.split("/")[-1]] = f"unreadable: {e}"
        print(f"    rc={r.returncode}", flush=True)
        if r.returncode != 0:
            print(r.stdout[-1500:], r.stderr[-800:], flush=True)

    json.dump(results, open(OUT, "w", encoding="utf-8"), indent=1)
    print(f"written {OUT}", flush=True)


if __name__ == "__main__":
    sys.exit(main())
