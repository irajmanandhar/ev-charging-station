"""Fire 24 simultaneous bookings for the SAME slot on one charger; exactly one may win."""
import json, sys, threading, urllib.request, urllib.error, datetime as dt

base, setup = sys.argv[1], json.load(open(sys.argv[2]))
start = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=1)).replace(minute=0, second=0, microsecond=0)
body = json.dumps({"charger_id": setup["charger"], "starts_at": start.strftime("%Y-%m-%dT%H:%M:%SZ"), "duration_minutes": 60}).encode()
results, gate = [], threading.Barrier(len(setup["tokens"]))


def go(token):
    req = urllib.request.Request(f"{base}/api/bookings", data=body, method="POST", headers={
        "Authorization": f"Bearer {token}", "Content-Type": "application/json", "Accept": "application/json"})
    gate.wait()
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            results.append(r.status)
    except urllib.error.HTTPError as e:
        results.append(e.code)


threads = [threading.Thread(target=go, args=(t,)) for t in setup["tokens"]]
[t.start() for t in threads]
[t.join() for t in threads]
from collections import Counter
print(dict(Counter(results)))
sys.exit(0 if Counter(results).get(201) == 1 else 1)

