import requests
import random
import time
import json
from datetime import datetime, timezone

BASE_URL = 'http://localhost:8000/api'

TOTAL_REQUESTS = 3500
ANOMALY_DURATION_SEC = 120

distribution = {
    'normal': 0.70,
    'slow': 0.15,
    'slow_hard': 0.05,
    'error': 0.05,
    'db': 0.03,
    'validate': 0.02,
}

# choose anomaly type: latency spike in this implementation
anomaly_type = 'latency_spike'

all_requests = []
error_count = 0

start_time = datetime.now(timezone.utc)
anomaly_start = None
anomaly_end = None

for i in range(TOTAL_REQUESTS):
    if i == int(TOTAL_REQUESTS * 0.5):
        anomaly_start = datetime.now(timezone.utc)
    if anomaly_start and anomaly_end is None and (datetime.now(timezone.utc) - anomaly_start).total_seconds() >= ANOMALY_DURATION_SEC:
        anomaly_end = datetime.now(timezone.utc)

    if anomaly_start and anomaly_end is None:
        spike_slow_hard_pct = 0.30
    else:
        spike_slow_hard_pct = distribution['slow_hard']

    choices = [
        ('normal', distribution['normal']),
        ('slow', distribution['slow']),
        ('slow_hard', spike_slow_hard_pct),
        ('error', distribution['error']),
        ('db', distribution['db']),
        ('validate', distribution['validate']),
    ]

    total = sum(p for _, p in choices)
    r = random.random() * total
    cum = 0
    decision = 'normal'
    for key, p in choices:
        cum += p
        if r <= cum:
            decision = key
            break

    url = f"{BASE_URL}/normal"
    method = 'GET'
    payload = None

    if decision == 'normal':
        url = f"{BASE_URL}/normal"
    elif decision == 'slow':
        url = f"{BASE_URL}/slow"
    elif decision == 'slow_hard':
        url = f"{BASE_URL}/slow?hard=1"
    elif decision == 'error':
        url = f"{BASE_URL}/error"
    elif decision == 'db':
        if random.random() < 0.2:
            url = f"{BASE_URL}/db?fail=1"
        else:
            url = f"{BASE_URL}/db"
    elif decision == 'validate':
        url = f"{BASE_URL}/validate"
        method = 'POST'
        if random.random() < 0.5:
            payload = {'email': 'bad', 'age': 17}
        else:
            payload = {'email': 'user@example.com', 'age': 30}

    now_ts = datetime.now(timezone.utc)
    req_entry = {
        'index': i + 1,
        'timestamp': now_ts.isoformat(),
        'url': url,
        'method': method,
        'payload': payload,
    }

    try:
        if method == 'GET':
            r = requests.get(url, timeout=15)
        else:
            r = requests.post(url, json=payload, timeout=15)
        req_entry['status_code'] = r.status_code
        req_entry['response'] = r.text
        if r.status_code >= 400:
            error_count += 1
    except Exception as e:
        req_entry['status_code'] = None
        req_entry['error'] = str(e)
        error_count += 1

    all_requests.append(req_entry)
    time.sleep(0.05)

if anomaly_start and anomaly_end is None:
    anomaly_end = datetime.now(timezone.utc)

with open('traffic_results.json', 'w') as f:
    json.dump({'requests': all_requests, 'error_count': error_count}, f, indent=2)

if anomaly_start is None:
    anomaly_start = start_time
    anomaly_end = start_time + timedelta(seconds=ANOMALY_DURATION_SEC)

ground_truth = {
    'anomaly_start_iso': anomaly_start.isoformat(),
    'anomaly_end_iso': anomaly_end.isoformat(),
    'anomaly_type': anomaly_type,
    'expected_behavior': 'Hard slow latency spike during anomaly window',
}

with open('ground_truth.json', 'w') as f:
    json.dump(ground_truth, f, indent=2)

print(f"Generated {TOTAL_REQUESTS} requests, {error_count} errors.")
print(f"Ground truth window: {ground_truth['anomaly_start_iso']} to {ground_truth['anomaly_end_iso']}")
