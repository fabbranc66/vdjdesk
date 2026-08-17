import json
import statistics
import subprocess
import sys
from pathlib import Path

import numpy as np


def average(samples, duration, start, end):
    if not samples or duration <= 0 or end <= start:
        return 0.0
    first = max(0, min(len(samples) - 1, int(start / duration * len(samples))))
    last = max(first + 1, min(len(samples), int(end / duration * len(samples))))
    return float(statistics.mean(samples[first:last]))


def window(samples, duration, time_value, before_beats, after_beats, beat_seconds):
    before = average(samples, duration, max(0.0, time_value - before_beats * beat_seconds), time_value)
    after = average(samples, duration, time_value, min(duration, time_value + after_beats * beat_seconds))
    return before, after


def grid_points(duration, offset, beat_seconds, step_beats, minimum_beat=0):
    points = []
    beat_index = minimum_beat
    while offset + beat_index * beat_seconds <= duration:
        time_value = offset + beat_index * beat_seconds
        if time_value >= 0:
            points.append((beat_index, time_value))
        beat_index += step_beats
    return points


def suppress(events, minimum_beats, beat_seconds):
    selected = []
    for event in sorted(events, key=lambda item: item["score"], reverse=True):
        if any(abs(event["time"] - current["time"]) < minimum_beats * beat_seconds for current in selected):
            continue
        selected.append(event)
    return sorted(selected, key=lambda item: item["time"])


def probe_stem_streams(stem_path):
    raw = subprocess.check_output(
        ["ffprobe", "-v", "error", "-show_streams", "-of", "json", str(stem_path)],
        stderr=subprocess.STDOUT,
        timeout=30,
    )
    payload = json.loads(raw.decode("utf-8", errors="replace"))
    streams = {}
    for stream in payload.get("streams", []):
        title = str(stream.get("tags", {}).get("title", "")).strip().lower()
        if title in {"kick", "hihat"}:
            streams[title] = int(stream["index"])
    return streams


def decode_stem(stem_path, stream_index, sample_rate=1000):
    raw = subprocess.check_output(
        ["ffmpeg", "-v", "error", "-i", str(stem_path), "-map", f"0:{stream_index}", "-ac", "1", "-ar", str(sample_rate), "-f", "f32le", "pipe:1"],
        stderr=subprocess.STDOUT,
        timeout=180,
    )
    return np.frombuffer(raw, dtype="<f4")


def phase_strength(onsets, sample_rate, time_value, radius_seconds=0.075):
    center = int(time_value * sample_rate)
    radius = max(1, int(radius_seconds * sample_rate))
    first = max(0, center - radius)
    last = min(len(onsets), center + radius + 1)
    return float(np.max(onsets[first:last])) if last > first else 0.0


def strong_average(values):
    active = sorted((float(value) for value in values if value > 0), reverse=True)
    if not active:
        return 0.0
    keep = max(1, int(len(active) * 0.6))
    return float(statistics.mean(active[:keep]))


def detect_rhythm_phase(analysis, duration, offset, beat_seconds):
    stem_source = str(analysis.get("stem_source") or "")
    if not stem_source or not Path(stem_source).is_file():
        return {"available": False, "straight_kick": False}
    try:
        sample_rate = 1000
        streams = probe_stem_streams(stem_source)
        if "kick" not in streams or "hihat" not in streams:
            return {"available": False, "straight_kick": False}
        energy_layers = {}
        for layer in ("kick", "hihat"):
            signal = decode_stem(stem_source, streams[layer], sample_rate)
            energy_layers[layer] = np.abs(signal)
        profiles = {"kick": [], "hihat": []}
        for phase_index in range(8):
            phase_offset = phase_index / 8 * beat_seconds
            for layer in profiles:
                values = []
                time_value = offset + phase_offset
                while time_value < duration:
                    center = int(time_value * sample_rate)
                    first = max(0, center - int(0.05 * sample_rate))
                    last = min(len(energy_layers[layer]), center + int(0.10 * sample_rate))
                    if last > first:
                        values.append(float(np.mean(energy_layers[layer][first:last])))
                    time_value += beat_seconds
                profiles[layer].append(strong_average(values))
        kick_phase = int(np.argmax(profiles["kick"]))
        expected_hihat_phase = (kick_phase + 4) % 8
        hihat_phase = int(np.argmax(profiles["hihat"]))
        phase_distance = min((hihat_phase - expected_hihat_phase) % 8, (expected_hihat_phase - hihat_phase) % 8)
        kick_ratio = profiles["kick"][kick_phase] / max(float(statistics.median(profiles["kick"])), 1e-9)
        hihat_ratio = profiles["hihat"][expected_hihat_phase] / max(profiles["hihat"][kick_phase], 1e-9)
        return {
            "available": True,
            "straight_kick": kick_ratio >= 2.0 and hihat_ratio >= 1.5 and phase_distance <= 1,
            "kick_phase": kick_phase,
            "hihat_phase": hihat_phase,
            "expected_hihat_phase": expected_hihat_phase,
            "kick_phase_ratio": round(kick_ratio, 3),
            "hihat_levare_ratio": round(hihat_ratio, 3),
        }
    except Exception as error:
        return {"available": False, "straight_kick": False, "error": str(error)}


def detect_hihat(samples, duration, offset, beat_seconds):
    bars = grid_points(duration, offset, beat_seconds, 16, 16)
    transitions = []
    for beat_index, time_value in bars:
        before4, after4 = window(samples, duration, time_value, 4, 4, beat_seconds)
        before16, after16 = window(samples, duration, time_value, 16, 16, beat_seconds)
        change = abs(after16 - before16)
        if change >= 22 and max(before4, after4) >= 15 and duration - time_value >= 16 * beat_seconds:
            transitions.append({
                "time": time_value,
                "label": "DROP",
                "score": change,
                "beat": beat_index,
                "peak": max(before4, after4),
                "direction": "rise" if after16 > before16 else "fall",
            })
    drops = []
    builds = []
    for transition in suppress(transitions, 16, beat_seconds):
        candidates = []
        for beat_index, time_value in bars:
            distance = transition["beat"] - beat_index
            if distance < 16 or distance > 48:
                continue
            _, after4 = window(samples, duration, time_value, 0, 4, beat_seconds)
            if after4 <= transition["peak"] * 0.55:
                candidates.append((beat_index, time_value, after4))
        if not candidates:
            continue
        if transition["direction"] == "rise":
            late = candidates[-1]
            earlier = next((item for item in candidates if item[0] == transition["beat"] - 32), None)
            if earlier:
                early_level = average(samples, duration, earlier[1], late[1])
                late_level = average(samples, duration, late[1], transition["time"])
                beat_index, time_value, level = earlier if late_level > 0 and early_level <= late_level * 0.4 else late
            else:
                beat_index, time_value, level = late
        else:
            beat_index, time_value, level = candidates[0]
        drops.append({key: value for key, value in transition.items() if key not in {"peak", "direction"}})
        builds.append({"time": time_value, "label": "BUILD-UP", "score": max(1.0, transition["peak"] - level), "beat": beat_index})
    events = sorted(builds + drops, key=lambda item: item["time"])
    standalone = []
    for beat_index, time_value in bars:
        _, after4 = window(samples, duration, time_value, 0, 4, beat_seconds)
        future_levels = [
            average(samples, duration, time_value + shift * beat_seconds, time_value + (shift + 4) * beat_seconds)
            for shift in range(8, 33, 4)
        ]
        peak = max(future_levels, default=0.0)
        if after4 <= 6 and peak >= 30 and not any(abs(time_value - event["time"]) <= 48 * beat_seconds for event in events):
            if not standalone or beat_index - standalone[-1]["beat"] > 48:
                standalone.append({"time": time_value, "label": "BUILD-UP", "score": peak - after4, "beat": beat_index})
    return sorted(events + standalone, key=lambda item: item["time"])


def detect_instruments(samples, duration, offset, beat_seconds):
    candidates = []
    for beat_index, time_value in grid_points(duration, offset, beat_seconds, 1, 4):
        before4, after4 = window(samples, duration, time_value, 4, 4, beat_seconds)
        _, after16 = window(samples, duration, time_value, 0, 16, beat_seconds)
        if before4 >= 25 and after4 <= 3 and after4 <= before4 * 0.15:
            candidates.append({"time": time_value, "label": "NOSOUND", "score": before4 - after4, "beat": beat_index})
        elif before4 <= 5 and after16 >= 17 and after16 - before4 >= 12:
            candidates.append({"time": time_value, "label": "SOUND", "score": after16 - before4, "beat": beat_index})
    selected = suppress(candidates, 8, beat_seconds)
    first_nosound = next((item["time"] for item in selected if item["label"] == "NOSOUND"), duration)
    return [item for item in selected if item["time"] >= first_nosound and duration - item["time"] >= 48 * beat_seconds]


def legacy_groove_sequence(candidates, breaks):
    selected = []
    boundaries = [item["beat"] for item in breaks]
    segments = []
    current = []
    boundary_index = 0
    for candidate in candidates:
        while boundary_index < len(boundaries) and candidate["beat"] > boundaries[boundary_index]:
            if current:
                segments.append(current)
            current = []
            boundary_index += 1
        current.append(candidate)
    if current:
        segments.append(current)
    for segment in segments:
        by_beat = {item["beat"]: item for item in segment}
        best_run = []
        for item in segment:
            run = []
            beat_index = item["beat"]
            while beat_index in by_beat:
                run.append(by_beat[beat_index])
                beat_index += 64
            if len(run) > len(best_run):
                best_run = run
        if len(best_run) >= 3:
            keep = {item["beat"] for item in best_run}
            keep.add(best_run[0]["beat"] - 32)
            keep.add(segment[0]["beat"])
            selected.extend(item for item in segment if item["beat"] in keep)
        else:
            selected.extend(segment[:1])
    return selected


def detect_kick(samples, duration, offset, beat_seconds, straight_kick=False):
    bars = grid_points(duration, offset, beat_seconds, 16, 16)
    clear_grooves = []
    breaks = []
    for beat_index, time_value in bars:
        before4, after4 = window(samples, duration, time_value, 4, 4, beat_seconds)
        before16, after16 = window(samples, duration, time_value, 16, 16, beat_seconds)
        minimum_rise = 20 if straight_kick else 35
        if before4 <= 45 and after4 >= 55 and after4 - before4 >= minimum_rise and after16 >= 70:
            clear_grooves.append({"time": time_value, "label": "GROOVE", "score": after4 - before4, "beat": beat_index})
        minimum_remaining = 48 if straight_kick else 32
        if before16 >= 40 and after16 <= 12 and after16 <= before16 * 0.25 and duration - time_value >= minimum_remaining * beat_seconds:
            breaks.append({"time": time_value, "label": "BREAK", "score": before16 - after16, "beat": beat_index})
    for beat_index, time_value in grid_points(duration, offset, beat_seconds, 8, 8):
        before4, after4 = window(samples, duration, time_value, 4, 4, beat_seconds)
        if before4 >= 50 and after4 <= 5 and after4 <= before4 * 0.1 and duration - time_value >= 32 * beat_seconds:
            breaks.append({"time": time_value, "label": "BREAK", "score": before4 - after4, "beat": beat_index})
    grooves = list(clear_grooves)
    if straight_kick:
        grooves = legacy_groove_sequence(clear_grooves, breaks)
        events = suppress(grooves, 8, beat_seconds) + suppress(breaks, 8, beat_seconds)
        return sorted(events, key=lambda item: item["time"])
    existing = {item["beat"] for item in grooves}
    for current, following in zip(clear_grooves, clear_grooves[1:]):
        if following["beat"] - current["beat"] != 64:
            continue
        beat_index = current["beat"] + 32
        time_value = offset + beat_index * beat_seconds
        _, after16 = window(samples, duration, time_value, 0, 16, beat_seconds)
        grooves.append({"time": time_value, "label": "GROOVE", "score": after16, "beat": beat_index})
        existing.add(beat_index)
    if clear_grooves:
        beat_index = clear_grooves[-1]["beat"] + 32
        if beat_index not in existing:
            time_value = offset + beat_index * beat_seconds
            before4, after4 = window(samples, duration, time_value, 4, 4, beat_seconds)
            if before4 <= 8 and after4 >= 25:
                grooves.append({"time": time_value, "label": "GROOVE", "score": after4 - before4, "beat": beat_index})
    events = suppress(grooves, 16, beat_seconds) + suppress(breaks, 16, beat_seconds)
    return sorted(events, key=lambda item: item["time"])


def sections_from_events(events, duration, initial_label):
    sections = []
    start = 0.0
    label = initial_label
    for event in sorted(events, key=lambda item: item["time"]):
        time_value = max(0.0, min(duration, float(event["time"])))
        if time_value <= start + 0.05:
            continue
        sections.append({"start": round(start, 3), "end": round(time_value, 3), "label": label, "automatic": True})
        start = time_value
        label = event["label"]
    sections.append({"start": round(start, 3), "end": round(duration, 3), "label": label, "automatic": True})
    return sections


def main():
    analysis_path = Path(sys.argv[1])
    rules_path = Path(sys.argv[2])
    macro_genre = sys.argv[3]
    year = int(sys.argv[4]) if len(sys.argv) > 4 and str(sys.argv[4]).isdigit() else 0
    analysis = json.loads(analysis_path.read_text(encoding="utf-8"))
    rules = json.loads(rules_path.read_text(encoding="utf-8"))
    macro_rules = rules.get("macro_genres", {}).get(macro_genre)
    if not macro_rules:
        raise RuntimeError(f"Regole non disponibili per il macrogenere {macro_genre}.")
    duration = float(analysis.get("duration_seconds") or 0)
    bpm = float(analysis.get("bpm") or 0)
    offset = float(analysis.get("grid_offset_seconds") or 0)
    if duration <= 0 or bpm <= 0:
        raise RuntimeError("Durata o BPM non disponibili.")
    beat_seconds = 60.0 / bpm
    rhythm_phase = detect_rhythm_phase(analysis, duration, offset, beat_seconds)
    legacy_cutoff = int(macro_rules.get("profiles", {}).get("straight_kick", {}).get("maximum_year", 2000))
    straight_kick = 0 < year <= legacy_cutoff and bool(rhythm_phase.get("straight_kick"))
    waveforms = {item.get("key"): [float(value) for value in item.get("samples", [])] for item in analysis.get("stem_waveforms", [])}
    events = {
        "hihat": detect_hihat(waveforms.get("hihat", []), duration, offset, beat_seconds),
        "kick": detect_kick(waveforms.get("kick", []), duration, offset, beat_seconds, straight_kick),
        "instruments": [] if straight_kick else detect_instruments(waveforms.get("instruments", []), duration, offset, beat_seconds),
    }
    initial = {"hihat": "HIHAT", "kick": "KICK", "instruments": "INSTRUMENTS"}
    layer_sections = {key: sections_from_events(items, duration, initial[key]) for key, items in events.items()}
    profile = "commerciale_straight_kick" if straight_kick else "commerciale_standard"
    print(json.dumps({"ok": True, "profile": profile, "year": year or None, "rhythm_phase": rhythm_phase, "events": events, "layer_sections": layer_sections}, ensure_ascii=False))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"ok": False, "error": str(error)}, ensure_ascii=False))
        raise SystemExit(1)
