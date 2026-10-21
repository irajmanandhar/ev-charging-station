"""One driver fires 8 simultaneous bookings for the same hour on 8 different chargers; only one may succeed."""
import json, sys, threading, urllib.request, urllib.error, datetime as dt
from collections import Counter
base, setup = sys.argv[1], json.load(open(sys.argv[2]))
start = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=2)).replace(minute=0, second=0, microsecond=0).strftime("%Y-%m-%dT%H:%M:%SZ")
res, gate = [], threading.Barrier(len(setup["chargers"]))
def go(cid):
    body = json.dumps({"charger_id": cid, "starts_at": start, "duration_minutes": 60}).encode()
    req = urllib.request.Request(base + "/api/bookings", data=body, method="POST", headers={"Authorization": "Bearer " + setup["token"], "Content-Type": "application/json", "Accept": "application/json"})
    gate.wait()
    try:
        with urllib.request.urlopen(req, timeout=60) as r: res.append(r.status)
    except urllib.error.HTTPError as e: res.append(e.code)
ts = [threading.Thread(target=go, args=(c,)) for c in setup["chargers"]]
[t.start() for t in ts]; [t.join() for t in ts]
print(dict(Counter(res)))
sys.exit(0 if Counter(res).get(201) == 1 else 1)
