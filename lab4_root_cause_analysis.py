#!/usr/bin/env python3
import json
import uuid
from pathlib import Path
from datetime import timedelta

import pandas as pd
import matplotlib.pyplot as plt

ROOT = Path(__file__).resolve().parent
PREDICTIONS_FILE = ROOT / 'anomaly_predictions.csv'
DATASET_FILE = ROOT / 'aiops_dataset.csv'
GROUND_TRUTH_FILE = ROOT / 'ground_truth.json'
RCA_JSON_FILE = ROOT / 'rca_report.json'
TIMELINE_IMAGE_FILE = ROOT / 'lab4_incident_timeline.png'
REPORT_MD_FILE = ROOT / 'lab4_rca_report.md'


def load_datasets():
    predictions = pd.read_csv(PREDICTIONS_FILE, parse_dates=['window_start'])
    dataset = pd.read_csv(DATASET_FILE, parse_dates=['window_start'])
    ground_truth = None
    if GROUND_TRUTH_FILE.exists():
        with GROUND_TRUTH_FILE.open('r', encoding='utf-8') as f:
            ground_truth = json.load(f)
    return predictions, dataset, ground_truth


def select_incident(predictions):
    anomalies = predictions[predictions['is_anomaly'] == 1].copy()
    if anomalies.empty:
        raise ValueError('No anomalies found in anomaly_predictions.csv')

    grouped = (
        anomalies.groupby('window_start')
        .agg(
            anomaly_count=('endpoint', 'size'),
            score_sum=('anomaly_score', 'sum'),
            endpoints=('endpoint', lambda s: sorted(set(s))),
        )
        .sort_values(['anomaly_count', 'score_sum'], ascending=[False, True])
    )

    selected_time = grouped.index[0]
    selected_end = selected_time + pd.Timedelta(seconds=30)
    incident_rows = anomalies[
        (anomalies['window_start'] >= selected_time - pd.Timedelta(minutes=1))
        & (anomalies['window_start'] <= selected_end + pd.Timedelta(minutes=1))
    ]

    return {
        'incident_start': selected_time,
        'incident_end': selected_end,
        'window': incident_rows,
        'summary': grouped.iloc[0].to_dict(),
    }


def compute_baseline(dataset, incident_start, incident_end):
    baseline_window = dataset[
        (dataset['window_start'] < incident_start - pd.Timedelta(minutes=5))
        | (dataset['window_start'] > incident_end + pd.Timedelta(minutes=5))
    ]
    baseline = (
        baseline_window.groupby('endpoint')[['avg_latency', 'request_rate', 'error_rate']]
        .mean()
        .rename(columns={
            'avg_latency': 'baseline_latency',
            'request_rate': 'baseline_request_rate',
            'error_rate': 'baseline_error_rate',
        })
    )
    return baseline


def analyze_incident(dataset, selected):
    incident_start = selected['incident_start']
    incident_end = selected['incident_end']
    analysis_window = dataset[
        (dataset['window_start'] >= incident_start - pd.Timedelta(minutes=10))
        & (dataset['window_start'] <= incident_end + pd.Timedelta(minutes=10))
    ].copy()
    baseline = compute_baseline(dataset, incident_start, incident_end)
    event_rows = dataset[
        (dataset['window_start'] >= incident_start)
        & (dataset['window_start'] <= incident_end)
    ].copy()

    endpoint_summary = (
        event_rows.groupby('endpoint')[['avg_latency', 'request_rate', 'error_rate']]
        .mean()
        .rename(columns={
            'avg_latency': 'incident_avg_latency',
            'request_rate': 'incident_avg_request_rate',
            'error_rate': 'incident_avg_error_rate',
        })
        .reset_index()
    )

    merged = endpoint_summary.merge(
        baseline.reset_index(), on='endpoint', how='left'
    ).fillna(0)
    merged['latency_ratio'] = merged.apply(
        lambda row: row['incident_avg_latency'] / row['baseline_latency']
        if row['baseline_latency'] > 0 else float('inf'), axis=1
    )
    merged['request_rate_ratio'] = merged.apply(
        lambda row: row['incident_avg_request_rate'] / row['baseline_request_rate']
        if row['baseline_request_rate'] > 0 else float('inf'), axis=1
    )
    merged['error_rate_delta'] = merged['incident_avg_error_rate'] - merged['baseline_error_rate']
    merged['impact_score'] = (
        merged['latency_ratio'] * 1.5
        + merged['request_rate_ratio'] * 1.0
        + merged['error_rate_delta'] * 12.0
    )

    root_endpoint_row = merged.sort_values('impact_score', ascending=False).iloc[0]
    root_endpoint = root_endpoint_row['endpoint']

    primary_signal = 'UNKNOWN'
    if root_endpoint_row['error_rate_delta'] > 0.15 or root_endpoint_row['incident_avg_error_rate'] >= 0.5:
        primary_signal = 'ERROR_SURGE'
    elif root_endpoint_row['latency_ratio'] >= 3.0:
        primary_signal = 'LATENCY_SPIKE'
    elif root_endpoint_row['request_rate_ratio'] >= 2.0:
        primary_signal = 'REQUEST_RATE_SURGE'
    else:
        primary_signal = 'ENDPOINT_ACTIVITY'

    category_counts = (
        event_rows['error_category']
        .fillna('NONE')
        .value_counts()
        .to_dict()
    )

    selected_window_limit = dataset[
        (dataset['window_start'] >= incident_start)
        & (dataset['window_start'] <= incident_end)
    ]

    supporting_evidence = [
        f"Selected window {incident_start.isoformat()} to {incident_end.isoformat()} includes {len(selected_window_limit)} metric rows.",
        f"Endpoint {root_endpoint} had incident latency {root_endpoint_row['incident_avg_latency']:.3f}s vs baseline {root_endpoint_row['baseline_latency']:.3f}s.",
        f"Endpoint {root_endpoint} error rate delta is {root_endpoint_row['error_rate_delta']:.3f}.",
        f"Error categories during the incident: {', '.join(f'{k}={v}' for k,v in category_counts.items())}.",
    ]

    timeline = build_timeline(dataset, selected)
    confidence = min(0.99, max(0.65, 0.6 + abs(root_endpoint_row['impact_score']) * 0.04))
    recommended_action = build_recommendation(root_endpoint, primary_signal)

    return {
        'incident_id': str(uuid.uuid4()),
        'incident_start': incident_start.isoformat(),
        'incident_end': incident_end.isoformat(),
        'selected_endpoints': selected['summary']['endpoints'],
        'root_cause_endpoint': root_endpoint,
        'primary_signal': primary_signal,
        'supporting_evidence': supporting_evidence,
        'error_category_distribution': category_counts,
        'confidence_score': round(confidence, 2),
        'recommended_action': recommended_action,
        'timeline': timeline,
    }, analysis_window


def build_recommendation(endpoint, signal):
    if signal == 'LATENCY_SPIKE':
        return f"Investigate slow path on {endpoint}, review service latency, backend execution, and resource limits."
    if signal == 'ERROR_SURGE':
        return f"Inspect failure handling for {endpoint}, including validation logic, database connectivity, and system error propagation."
    if signal == 'REQUEST_RATE_SURGE':
        return f"Check traffic shaping and capacity for {endpoint}, and validate rate limiting or autoscaling behavior."
    return f"Review {endpoint} telemetry and logs for correlated failures and abnormal request patterns."


def build_timeline(dataset, selected):
    incident_start = selected['incident_start']
    incident_end = selected['incident_end']
    before = dataset[
        (dataset['window_start'] >= incident_start - pd.Timedelta(minutes=10))
        & (dataset['window_start'] < incident_start)
    ]
    peak_window = dataset[
        (dataset['window_start'] >= incident_start)
        & (dataset['window_start'] <= incident_end + pd.Timedelta(minutes=15))
    ]
    after = dataset[dataset['window_start'] > incident_end].sort_values('window_start')

    normal_state = None
    if not before.empty:
        normal_state = {
            'timestamp': before['window_start'].max().isoformat(),
            'description': 'Pre-incident state shows baseline latency and error rates.',
        }
    peak_row = None
    if not peak_window.empty:
        peak_row = peak_window.loc[peak_window['avg_latency'].idxmax()]
    recovery_time = None
    for _, row in after.iterrows():
        endpoint = row['endpoint']
        if endpoint in selected['window']['endpoint'].values:
            recovery_time = row['window_start'].isoformat()
            break

    timeline = [
        {'phase': 'normal_state', 'timestamp': normal_state['timestamp'] if normal_state else None,
         'description': normal_state['description'] if normal_state else 'Baseline state prior to incident.'},
        {'phase': 'anomaly_start', 'timestamp': incident_start.isoformat(),
         'description': 'Anomaly window begins with multiple endpoint deviations.'},
    ]
    if peak_row is not None:
        timeline.append({
            'phase': 'peak_incident',
            'timestamp': peak_row['window_start'].isoformat(),
            'description': f"Peak incident when {peak_row['endpoint']} showed maximum latency ({peak_row['avg_latency']:.3f}s).",
        })
    timeline.append({
        'phase': 'recovery',
        'timestamp': recovery_time,
        'description': 'Recovery observed when endpoint activity returns to normal levels.' if recovery_time else 'Recovery not observed in the available dataset window.',
    })
    return timeline


def render_timeline_chart(analysis_window, selected):
    if analysis_window.empty:
        print('No data available for timeline rendering.')
        return

    incident_start = selected['incident_start']
    incident_end = selected['incident_end']
    window = analysis_window.copy()
    window = window.sort_values('window_start')

    endpoints = sorted(window['endpoint'].unique())
    fig, axes = plt.subplots(3, 1, figsize=(14, 11), sharex=True)

    for endpoint in endpoints:
        endpoint_df = window[window['endpoint'] == endpoint]
        axes[0].plot(endpoint_df['window_start'], endpoint_df['avg_latency'], label=endpoint)
        axes[1].plot(endpoint_df['window_start'], endpoint_df['error_rate'], linestyle='--', label=endpoint)
        axes[2].plot(endpoint_df['window_start'], endpoint_df['request_rate'], linestyle='-.', label=endpoint)

    for ax, title in zip(axes, ['Average Latency (s)', 'Error Rate', 'Request Rate']):
        ax.axvspan(incident_start, incident_end, color='red', alpha=0.12)
        ax.set_ylabel(title)
        ax.legend(loc='upper left', fontsize='small')
        ax.grid(True, alpha=0.3)

    axes[-1].set_xlabel('Time')
    fig.suptitle('Lab 4 Incident Timeline: Metrics around selected anomaly window')
    fig.tight_layout(rect=[0, 0, 1, 0.97])
    fig.savefig(TIMELINE_IMAGE_FILE, dpi=150)
    plt.close(fig)
    print(f'Saved timeline visualization to {TIMELINE_IMAGE_FILE.name}')


def save_report(rca_report):
    with RCA_JSON_FILE.open('w', encoding='utf-8') as f:
        json.dump(rca_report, f, indent=2)

    lines = [
        '# Lab 4 Root Cause Analysis Report',
        '',
        f"**Incident ID:** {rca_report['incident_id']}",
        f"**Root Cause Endpoint:** {rca_report['root_cause_endpoint']}",
        f"**Primary Signal:** {rca_report['primary_signal']}",
        f"**Confidence Score:** {rca_report['confidence_score']}",
        '',
        '## Executive Summary',
        '',
        f"An automated root cause analysis was performed on the selected anomaly window starting at {rca_report['incident_start']}.",
        f"The analysis identified `{rca_report['root_cause_endpoint']}` as the most likely source of the incident.",
        '',
        '## Signal Analysis',
        '',
    ]
    for evidence in rca_report['supporting_evidence']:
        lines.append(f'- {evidence}')
    lines.extend(['', '## Error Category Analysis', ''])
    for category, count in rca_report['error_category_distribution'].items():
        lines.append(f'- {category}: {count}')
    lines.extend(['', '## Incident Timeline', ''])
    for event in rca_report['timeline']:
        lines.append(f"- **{event['phase']}** ({event['timestamp']}): {event['description']}")
    lines.extend(['', '## Recommended Action', '', f'- {rca_report['recommended_action']}', ''])

    with REPORT_MD_FILE.open('w', encoding='utf-8') as f:
        f.write('\n'.join(lines))
    print(f'Saved RCA report to {REPORT_MD_FILE.name}')


def main():
    predictions, dataset, ground_truth = load_datasets()
    selected = select_incident(predictions)
    rca_report, analysis_window = analyze_incident(dataset, selected)
    save_report(rca_report)
    render_timeline_chart(analysis_window, selected)
    print(f'Generated RCA output in {RCA_JSON_FILE.name}, {TIMELINE_IMAGE_FILE.name}, {REPORT_MD_FILE.name}')


if __name__ == '__main__':
    main()
