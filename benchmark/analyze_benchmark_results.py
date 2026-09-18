#!/usr/bin/env python3
import json
import os
import statistics
from glob import glob


def load_json(path):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def summarize_latency(tasks_dir):
    durations = []
    for path in glob(os.path.join(tasks_dir, "*.json")):
        try:
            data = load_json(path)
        except Exception:
            continue
        ms = data.get("duration_ms") or data.get("latency_ms")
        if isinstance(ms, (int, float)) and ms >= 0:
            durations.append(float(ms))
    if not durations:
        return None
    durations.sort()
    n = len(durations)

    def pct(p):
        idx = min(n - 1, max(0, int(round((p / 100.0) * (n - 1)))))
        return durations[idx]

    return {
        "count": n,
        "avg_ms": round(statistics.mean(durations), 2),
        "median_ms": round(statistics.median(durations), 2),
        "p95_ms": round(pct(95), 2),
        "p99_ms": round(pct(99), 2),
        "fastest_ms": round(durations[0], 2),
        "slowest_ms": round(durations[-1], 2),
    }


def main():
    base = "/var/www/vhosts/lyralinkai.com/benchmark_private"
    summary_path = os.path.join(base, "scoring", "BENCHMARK_SUMMARY_V2.json")
    if not os.path.exists(summary_path):
        print("SUMMARY_NOT_FOUND")
        return

    summary = load_json(summary_path)
    print("OVERALL")
    for key in ["lyralink_weighted_average", "critical_failures", "evidence_paralysis_count", "unnecessary_refusal_count"]:
        if key in summary:
            print(f"{key}={summary.get(key)}")

    cats = summary.get("category_breakdown", {})
    print("CATEGORIES")
    for name, row in cats.items():
        print(f"{name}\tavg={row.get('lyralink_avg')}\tcount={row.get('lyralink_count')}")

    lat = summarize_latency(os.path.join(base, "tasks"))
    if lat:
        print("LATENCY")
        for k, v in lat.items():
            print(f"{k}={v}")


if __name__ == "__main__":
    main()
