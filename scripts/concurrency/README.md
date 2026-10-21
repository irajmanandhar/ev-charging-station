# Concurrency checks

The PHPUnit suite runs requests one after another, so it cannot prove the booking row locks work.
These scripts fire truly simultaneous requests at a multi-worker server backed by MySQL
(SQLite serialises writers and would hide a missing lock).

| Script | Fires | Passes when |
|---|---|---|
| `same_slot.py` | 24 drivers book the same charger and hour | exactly one `201`, the rest `409` |
| `same_user.py` | one driver books 8 chargers for the same hour | exactly one `201`, the rest `422` |

Run locally (MySQL reachable, migrations applied):

```bash
cd backend
PHP_CLI_SERVER_WORKERS=12 php -S 127.0.0.1:8000 -t public &
BACKEND_PATH=$PWD php ../scripts/concurrency/setup-same-slot.php > /tmp/same-slot.json
python ../scripts/concurrency/same_slot.py http://127.0.0.1:8000 /tmp/same-slot.json
BACKEND_PATH=$PWD php ../scripts/concurrency/setup-same-user.php > /tmp/same-user.json
python ../scripts/concurrency/same_user.py http://127.0.0.1:8000 /tmp/same-user.json
```

`setup-same-user.php` reuses the operator created by `setup-same-slot.php`, so run them in that order.
CI runs the same steps in the `concurrency` job. Use a throwaway database: the setup scripts create users.
