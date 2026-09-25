import json, subprocess, sys, time, re, os
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
EXE = "C:/newwebser/target/release/h3load.exe"
PID = subprocess.run(["powershell","-NoProfile","-Command",
  "(Get-NetTCPConnection -LocalPort 9091 -State Listen).OwningProcess"],
  capture_output=True, text=True).stdout.strip()
print("bench pid:", PID, flush=True)

def snap():
    r = subprocess.run(["powershell","-NoProfile","-Command",
      f"(Get-Process -Id {PID}).CPU; (Get-Process -Id {PID}).WorkingSet64"],
      capture_output=True, text=True)
    parts = [p.replace(",", ".") for p in r.stdout.split()]
    return float(parts[0]), int(parts[1])

def run(mode, conns, streams, dur=8):
    c0, r0 = snap()
    t0 = time.time()
    out = subprocess.run([EXE, mode, "127.0.0.1", "9085" if mode=="h1plain" else "9443",
                          "/b/index.html", str(conns), str(dur), str(streams)],
                         capture_output=True, text=True, cwd="C:/newwebser").stdout.strip()
    d = json.loads(out)
    c1, r1 = snap()
    d["cpu_pct"] = round((c1-c0)/(time.time()-t0)/os.cpu_count()*100, 1)
    d["rss_mb"] = round(r1/1048576, 1)
    print(json.dumps(d), flush=True)
    return d

results = []
for conns, streams in [(32,1),(64,1),(128,1),(256,1),(64,4),(128,4),(256,4),(512,4),(256,8),(512,8)]:
    results.append(run("h3", conns, streams))
json.dump(results, open("bench/matrix_h3.json","w"), indent=1)
