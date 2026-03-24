import json
import os
from datetime import datetime, timedelta

import numpy as np
import pandas as pd
from sklearn.ensemble import IsolationForest
import matplotlib.pyplot as plt

LOG_FILE = 'laravel-app/storage/logs/aiops.log'
DATASET_FILE = 'aiops_dataset.csv'
PREDICTIONS_FILE = 'anomaly_predictions.csv'

PROMETHEUS_URL = os.getenv('PROMETHEUS_URL', 'http://localhost:9090')


def load_logs():
    if os.path.exists('logs.json'):
        with open('logs.json', 'r') as f:
            raw = json.load(f)
        if isinstance(raw, list) and len(raw) > 0:
            return pd.DataFrame(raw)

    if os.path.exists(LOG_FILE):
        # each line is JSON
        rows = []
        with open(LOG_FILE, 'r') as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    rows.append(json.loads(line))
                except json.JSONDecodeError:
                    continue
        if rows:
            return pd.DataFrame(rows)

    print('No real logs found; generating synthetic dataset for demonstration.')
    return generate_synthetic_logs()


def generate_synthetic_logs(num_entries=3000):
    endpoints = ['/api/normal', '/api/slow', '/api/db', '/api/error', '/api/validate']
    now = datetime.utcnow()
    records = []

    for i in range(num_entries):
        t = now - timedelta(seconds=(num_entries - i) * 5)
        endpoint = np.random.choice(endpoints, p=[0.7, 0.15, 0.03, 0.05, 0.07])
        base_latency = {'/api/normal': 0.2, '/api/slow': 0.8, '/api/db': 0.4, '/api/error': 0.2, '/api/validate': 0.3}[endpoint]
        latency = abs(np.random.normal(loc=base_latency, scale=0.1))

        status_code = 200
        error_category = 'NONE'
        if endpoint == '/api/error' or (endpoint == '/api/db' and np.random.rand() < 0.2):
            status_code = 500
            error_category = 'SYSTEM_ERROR' if endpoint == '/api/error' else 'DATABASE_ERROR'
        if endpoint == '/api/validate' and np.random.rand() < 0.3:
            status_code = 422
            error_category = 'VALIDATION_ERROR'

        # anomaly window patterns
        if i > num_entries * 0.7:
            if np.random.rand() < 0.3:
                endpoint = '/api/slow'
                latency = abs(np.random.normal(loc=6.0, scale=0.5))
                status_code = 200
                error_category = 'TIMEOUT_ERROR'

        records.append({
            'timestamp': t.isoformat() + 'Z',
            'path': endpoint,
            'latency_ms': latency * 1000,
            'status_code': status_code,
            'error_category': error_category,
            'correlation_id': str(np.random.randint(1, 1000000)),
            'client_ip': '127.0.0.1',
            'user_agent': 'ml-test',
            'query': None,
            'payload_size_bytes': 0,
            'response_size_bytes': 120,
            'route_name': endpoint,
            'build_version': '1.0.0',
            'host': 'localhost',
        })

    return pd.DataFrame(records)


def build_dataset(df):
    df['timestamp'] = pd.to_datetime(df['timestamp'], utc=True, errors='coerce')
    df = df.dropna(subset=['timestamp', 'path', 'latency_ms'])
    df['endpoint'] = df['path'].astype(str)
    df['latency'] = df['latency_ms'] / 1000.0
    df['error_flag'] = df['status_code'].astype(int).ge(400).astype(int)

    bins = pd.date_range(start=df['timestamp'].min().floor('30S'),
                         end=df['timestamp'].max().ceil('30S'),
                         freq='30S')

    records = []

    for endpoint, group in df.groupby('endpoint'):
        g = group.set_index('timestamp').sort_index()
        g = g.resample('30S').agg({
            'latency': ['mean', 'max', 'std', 'count'],
            'error_flag': 'sum',
        })
        g.columns = ['avg_latency', 'max_latency', 'latency_std', 'request_count', 'errors_per_window']
        g['endpoint'] = endpoint
        g['request_rate'] = g['request_count'] / 30.0
        g['error_rate'] = g['errors_per_window'] / (g['request_count'].replace(0, np.nan))
        g['error_rate'] = g['error_rate'].fillna(0)
        g['endpoint_frequency'] = g['request_count']

        # use most common error_category in window
        local = group[['timestamp', 'error_category']].copy()
        local.index = local['timestamp']
        local = local.resample('30S')['error_category'].agg(lambda x: x.mode().iloc[0] if len(x)>0 else 'NONE')
        g['error_category'] = local

        g = g.reset_index().rename(columns={'timestamp': 'window_start'})
        records.append(g)

    if not records:
        raise ValueError('No endpoint groups found')

    dataset = pd.concat(records, ignore_index=True)
    dataset = dataset.dropna(subset=['avg_latency'])

    dataset = dataset[['window_start', 'endpoint', 'avg_latency', 'max_latency', 'request_rate', 'error_rate', 'latency_std', 'errors_per_window', 'endpoint_frequency', 'error_category']]

    return dataset


def train_model(dataset):
    # train on normal period: first 70% of time window rows
    dataset = dataset.sort_values('window_start')
    n = len(dataset)
    normal_end = int(n * 0.7)
    train_df = dataset.iloc[:normal_end]
    predict_df = dataset

    features = ['avg_latency', 'max_latency', 'request_rate', 'error_rate', 'latency_std', 'errors_per_window', 'endpoint_frequency']

    X_train = train_df[features].fillna(0)

    model = IsolationForest(contamination=0.1, random_state=42)
    model.fit(X_train)

    X_all = predict_df[features].fillna(0)
    scores = model.decision_function(X_all)
    anomalies = model.predict(X_all)

    predict_df['anomaly_score'] = scores
    predict_df['is_anomaly'] = (anomalies == -1).astype(int)

    return model, predict_df


def visualize(dataset, predictions):
    fig, ax = plt.subplots(figsize=(12, 6))
    dataset_by_t = dataset.groupby('window_start')['avg_latency'].mean().reset_index()
    ax.plot(dataset_by_t['window_start'], dataset_by_t['avg_latency'], label='avg_latency')

    anomalous = predictions[predictions['is_anomaly'] == 1].groupby('window_start')['avg_latency'].mean().reset_index()
    ax.scatter(anomalous['window_start'], anomalous['avg_latency'], color='red', label='Anomaly', zorder=5)

    ax.set_title('Latency timeline with anomalies')
    ax.set_xlabel('Time')
    ax.set_ylabel('Avg latency (s)')
    ax.legend()
    fig.savefig('latency_timeline.png', dpi=150)
    plt.close(fig)

    fig, ax = plt.subplots(figsize=(12, 6))
    dataset_by_t2 = dataset.groupby('window_start')['error_rate'].mean().reset_index()
    ax.plot(dataset_by_t2['window_start'], dataset_by_t2['error_rate'], label='error_rate')
    ax.scatter(anomalous['window_start'], dataset_by_t2.loc[dataset_by_t2['window_start'].isin(anomalous['window_start']), 'error_rate'], color='red', label='Anomaly', zorder=5)
    ax.set_title('Error rate timeline with anomalies')
    ax.set_xlabel('Time')
    ax.set_ylabel('Error rate')
    ax.legend()
    fig.savefig('error_rate_timeline.png', dpi=150)
    plt.close(fig)


def main():
    print('Building dataset from telemetry logs...')
    logs = load_logs()
    dataset = build_dataset(logs)
    dataset.to_csv(DATASET_FILE, index=False)

    print('Training anomaly detection model...')
    model, predictions = train_model(dataset)
    predictions.to_csv(PREDICTIONS_FILE, index=False)

    print('Generating visualization plots...')
    visualize(dataset, predictions)

    print('Done. Dataset:', DATASET_FILE)
    print('Anomalies:', PREDICTIONS_FILE)
    print('Plots: latency_timeline.png, error_rate_timeline.png')


if __name__ == '__main__':
    main()
