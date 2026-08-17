import base64
import json
import math
import re
import subprocess
import sys
from pathlib import Path

import librosa
import numpy as np
from scipy.ndimage import uniform_filter1d
from scipy.signal import find_peaks


ANALYZER_VERSION = "audio-local-0.7-vdj-bpm"
HOP_LENGTH = 512
STEM_ROOTS = [
    Path(r"E:\LIBRERIA_DEFINITIVA\02_DJ_TOOLS\Stems"),
    Path(r"E:\LIBRERIA_TECNICA\02_DJ_TOOLS\Stems"),
    Path(r"E:\VirtualDJ\Stems"),
]
STEM_COLORS = {
    "vocal": "#ff5f87",
    "hihat": "#f8d34f",
    "bass": "#9b6cff",
    "instruments": "#41c7f2",
    "kick": "#62df70",
}


def decode_path(value):
    if value.startswith("base64:"):
        return base64.b64decode(value[7:]).decode("utf-8")
    return value


def finite(value, fallback=0.0):
    number = float(np.asarray(value).reshape(-1)[0])
    return number if math.isfinite(number) else fallback


def normalize(values):
    values = np.asarray(values, dtype=float)
    if values.size == 0:
        return values
    low, high = np.percentile(values, [10, 90])
    if high <= low:
        return np.zeros_like(values)
    return np.clip((values - low) / (high - low), 0, 1)


def waveform_segment(y, sample_rate, start, end, bins=96):
    first = max(0, int(float(start) * sample_rate))
    last = min(len(y), int(float(end) * sample_rate))
    segment = np.abs(y[first:last])
    if segment.size == 0:
        return []
    chunks = np.array_split(segment, bins)
    peaks = np.array([np.percentile(chunk, 95) if chunk.size else 0 for chunk in chunks])
    maximum = float(np.max(peaks))
    if maximum <= 0:
        return [0] * bins
    return [round(float(value / maximum) * 100) for value in peaks]


def find_vdj_stem(audio_path):
    expected_names = [audio_path.name + ".vdjstems", audio_path.stem + ".vdjstems"]
    candidates = [Path(str(audio_path) + ".vdjstems"), audio_path.with_suffix(".vdjstems")]
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    for root in STEM_ROOTS:
        if not root.is_dir():
            continue
        for name in expected_names:
            try:
                candidate = next(root.rglob(name), None)
            except OSError:
                candidate = None
            if candidate and candidate.is_file():
                return candidate
    return None


def probe_stem_streams(stem_path):
    raw = subprocess.check_output(
        ["ffprobe", "-v", "error", "-show_entries", "stream=index,codec_type:stream_tags=title", "-of", "json", str(stem_path)],
        stderr=subprocess.STDOUT,
        timeout=30,
    )
    info = json.loads(raw.decode("utf-8", errors="replace"))
    streams = {}
    for stream in info.get("streams", []):
        title = str(stream.get("tags", {}).get("title", "")).strip().lower()
        if stream.get("codec_type") == "audio" and title in STEM_COLORS:
            streams[title] = int(stream["index"])
    return streams


def stem_waveform(stem_path, stream_index, bins=480):
    raw = subprocess.check_output(
        ["ffmpeg", "-v", "error", "-i", str(stem_path), "-map", f"0:{stream_index}", "-ac", "1", "-ar", "1000", "-f", "f32le", "pipe:1"],
        stderr=subprocess.STDOUT,
        timeout=180,
    )
    samples = np.frombuffer(raw, dtype="<f4")
    return waveform_segment(samples, 1000, 0, len(samples) / 1000, bins=bins)


def analyze_stem_waveforms(audio_path):
    stem_path = find_vdj_stem(audio_path)
    if not stem_path:
        return None, []
    try:
        streams = probe_stem_streams(stem_path)
        layers = []
        labels = {"vocal": "Vocal", "hihat": "Hi-hat", "bass": "Bass", "instruments": "Instruments", "kick": "Kick"}
        for key in ("vocal", "hihat", "bass", "instruments", "kick"):
            if key not in streams:
                continue
            layers.append({"key": key, "label": labels[key], "color": STEM_COLORS[key], "samples": stem_waveform(stem_path, streams[key])})
        return str(stem_path), layers
    except Exception:
        return str(stem_path), []


def ffmpeg_loudness(path):
    process = subprocess.run(
        [
            "ffmpeg",
            "-hide_banner",
            "-nostats",
            "-i",
            str(path),
            "-filter_complex",
            "ebur128=peak=true",
            "-f",
            "null",
            "-",
        ],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    text = process.stderr
    summary = text.rsplit("Summary:", 1)[-1]
    integrated = re.search(r"I:\s*(-?[\d.]+)\s*LUFS", summary)
    range_match = re.search(r"LRA:\s*(-?[\d.]+)\s*LU", summary)
    peak = re.search(r"Peak:\s*(-?[\d.]+)\s*dBFS", summary)
    return {
        "integrated_lufs": float(integrated.group(1)) if integrated else None,
        "loudness_range_lu": float(range_match.group(1)) if range_match else None,
        "true_peak_dbfs": float(peak.group(1)) if peak else None,
    }


def estimate_key(chroma):
    major = np.array([6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88])
    minor = np.array([6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17])
    profile = np.mean(chroma, axis=1)
    profile = (profile - profile.mean()) / (profile.std() or 1)
    names = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"]
    camelot_major = ["8B", "3B", "10B", "5B", "12B", "7B", "2B", "9B", "4B", "11B", "6B", "1B"]
    camelot_minor = ["5A", "12A", "7A", "2A", "9A", "4A", "11A", "6A", "1A", "8A", "3A", "10A"]
    candidates = []
    for root in range(12):
        candidates.append((np.corrcoef(profile, np.roll(major, root))[0, 1], names[root], camelot_major[root]))
        candidates.append((np.corrcoef(profile, np.roll(minor, root))[0, 1], names[root] + "m", camelot_minor[root]))
    score, key, camelot = max(candidates, key=lambda item: finite(item[0], -1))
    return key, camelot, round(max(0.0, min(1.0, finite(score))) * 100)


def detect_structure(y, sample_rate, onset, rms, chroma, beat_frames):
    frame_rate = sample_rate / HOP_LENGTH
    smooth_frames = max(3, int(frame_rate * 2.0))
    smooth_energy = uniform_filter1d(rms, size=smooth_frames)
    energy_norm = normalize(smooth_energy)
    onset_norm = normalize(uniform_filter1d(onset, size=max(3, int(frame_rate * 0.7))))
    lookback = max(1, int(frame_rate * 4))
    rise = np.zeros_like(energy_norm)
    rise[lookback:] = np.maximum(0, energy_norm[lookback:] - energy_norm[:-lookback])
    drop_score = 0.55 * onset_norm + 0.45 * rise
    duration = librosa.get_duration(y=y, sr=sample_rate)
    beat_times = librosa.frames_to_time(beat_frames, sr=sample_rate, hop_length=HOP_LENGTH)
    beat_duration = float(np.median(np.diff(beat_times))) if len(beat_times) > 1 else 0.5
    peaks, _ = find_peaks(
        drop_score,
        distance=max(1, int(frame_rate * 12)),
        prominence=0.12,
    )
    structure_candidates = []
    for frame in peaks:
        time_value = librosa.frames_to_time(frame, sr=sample_rate, hop_length=HOP_LENGTH)
        if 8 <= time_value <= max(8, duration - 8):
            drop_index = int(np.argmin(np.abs(beat_times - time_value))) if len(beat_times) else 0
            build_index = max(0, drop_index - 16)
            drop_end_index = min(len(beat_times) - 1, drop_index + 16) if len(beat_times) else 0
            build_start = float(beat_times[build_index]) if len(beat_times) else max(0, float(time_value) - beat_duration * 16)
            drop_start = float(beat_times[drop_index]) if len(beat_times) else float(time_value)
            drop_end = float(beat_times[drop_end_index]) if len(beat_times) else min(duration, drop_start + beat_duration * 16)
            if drop_end <= drop_start:
                continue
            pre_start_frame = max(0, int((drop_start - beat_duration * 8) * frame_rate))
            drop_frame = min(len(energy_norm) - 1, max(1, int(drop_start * frame_rate)))
            post_end_frame = min(len(energy_norm), max(drop_frame + 1, int((drop_start + beat_duration * 8) * frame_rate)))
            pre_energy = float(np.mean(energy_norm[pre_start_frame:drop_frame])) if drop_frame > pre_start_frame else 0.0
            post_energy = float(np.mean(energy_norm[drop_frame:post_end_frame])) if post_end_frame > drop_frame else 0.0
            pre_onset = float(np.mean(onset_norm[pre_start_frame:drop_frame])) if drop_frame > pre_start_frame else 0.0
            post_onset = float(np.mean(onset_norm[drop_frame:post_end_frame])) if post_end_frame > drop_frame else 0.0
            restart = max(0.0, post_energy - pre_energy) * 0.6 + max(0.0, post_onset - pre_onset) * 0.4
            confidence = min(1.0, float(drop_score[frame]) * 0.72 + restart * 0.55)
            waveform_start = max(0, build_start - 5)
            waveform_end = min(duration, drop_end + 5)
            waveform = waveform_segment(y, sample_rate, waveform_start, waveform_end)
            structure_candidates.append({
                "score": round(confidence * 100),
                "build": {
                    "start": round(build_start, 2), "end": round(drop_start, 2), "time": round(build_start, 2),
                    "waveform_start": round(waveform_start, 2), "waveform_end": round(waveform_end, 2),
                    "beats": max(1, drop_index - build_index), "score": round(confidence * 100), "waveform": waveform,
                },
                "drop": {
                    "start": round(drop_start, 2), "end": round(drop_end, 2), "time": round(drop_start, 2),
                    "waveform_start": round(waveform_start, 2), "waveform_end": round(waveform_end, 2),
                    "beats": max(1, drop_end_index - drop_index), "score": round(confidence * 100),
                    "energy_rise": round(post_energy - pre_energy, 3), "rhythm_rise": round(post_onset - pre_onset, 3),
                    "waveform": waveform,
                },
            })
    selected_structures = sorted(sorted(structure_candidates, key=lambda item: item["score"], reverse=True)[:5], key=lambda item: item["drop"]["start"])
    build_ups, drops = [], []
    for index, candidate in enumerate(selected_structures, start=1):
        candidate["build"]["name"] = f"BUILD UP {index}"
        candidate["drop"]["name"] = f"DROP {index}"
        build_ups.append(candidate["build"])
        drops.append(candidate["drop"])

    active_threshold = max(np.percentile(smooth_energy, 35), np.max(smooth_energy) * 0.12)
    active = np.flatnonzero(smooth_energy >= active_threshold)
    intro_end = librosa.frames_to_time(active[0], sr=sample_rate, hop_length=HOP_LENGTH) if active.size else 0
    outro_start = librosa.frames_to_time(active[-1], sr=sample_rate, hop_length=HOP_LENGTH) if active.size else duration

    loops = []
    loop_beats = 16
    for index in range(0, max(0, len(beat_frames) - loop_beats), 4):
        frames = beat_frames[index : index + loop_beats + 1]
        times = beat_times[index : index + loop_beats + 1]
        intervals = np.diff(times)
        if intervals.size < loop_beats or np.mean(intervals) <= 0:
            continue
        rhythm = max(0.0, 1.0 - np.std(intervals) / np.mean(intervals) * 8)
        start_frame, end_frame = int(frames[0]), int(frames[-1])
        edge = max(1, min(int(frame_rate), (end_frame - start_frame) // 4))
        start_chroma = np.mean(chroma[:, start_frame : start_frame + edge], axis=1)
        end_chroma = np.mean(chroma[:, max(start_frame, end_frame - edge) : end_frame], axis=1)
        denominator = np.linalg.norm(start_chroma) * np.linalg.norm(end_chroma)
        similarity = float(np.dot(start_chroma, end_chroma) / denominator) if denominator else 0
        segment_energy = float(np.mean(energy_norm[start_frame:end_frame])) if end_frame > start_frame else 0
        score = 0.5 * rhythm + 0.35 * max(0, similarity) + 0.15 * segment_energy
        loops.append(
            {
                "start": round(float(times[0]), 2),
                "end": round(float(times[-1]), 2),
                "beats": loop_beats,
                "score": round(score * 100),
            }
        )
    selected_loops = []
    for candidate in sorted(loops, key=lambda item: item["score"], reverse=True):
        if all(abs(candidate["start"] - existing["start"]) >= 8 for existing in selected_loops):
            selected_loops.append(candidate)
        if len(selected_loops) == 5:
            break
    selected_loops.sort(key=lambda item: item["start"])
    overview_waveform = waveform_segment(y, sample_rate, 0, duration, bins=480)
    section_seconds = max(4.0, beat_duration * 16)
    raw_sections = []
    cursor = 0.0
    while cursor < duration:
        section_end = min(duration, cursor + section_seconds)
        first_frame = max(0, int(cursor * frame_rate))
        last_frame = min(len(energy_norm), max(first_frame + 1, int(section_end * frame_rate)))
        section_energy = float(np.mean(energy_norm[first_frame:last_frame])) if last_frame > first_frame else 0
        overlaps_drop = any(cursor < float(drop["end"]) and section_end > float(drop["start"]) for drop in drops)
        overlaps_build = any(cursor < float(build["end"]) and section_end > float(build["start"]) for build in build_ups)
        if cursor < section_seconds:
            label = "Intro"
        elif section_end >= duration - section_seconds:
            label = "Outro"
        elif overlaps_drop:
            label = "Drop"
        elif overlaps_build:
            label = "Build-up"
        elif section_energy <= 0.34:
            label = "Break"
        elif section_energy >= 0.78:
            label = "Picco"
        else:
            label = "Groove"
        raw_sections.append(
            {
                "start": round(cursor, 2),
                "end": round(section_end, 2),
                "label": label,
                "energy": round(section_energy * 100),
            }
        )
        cursor = section_end
    sections = []
    for section in raw_sections:
        if sections and sections[-1]["label"] == section["label"]:
            sections[-1]["end"] = section["end"]
            sections[-1]["energy"] = round((sections[-1]["energy"] + section["energy"]) / 2)
        else:
            sections.append(section)
    return round(float(intro_end), 2), round(float(outro_start), 2), build_ups, drops, selected_loops, overview_waveform, sections


def analyze(path, bpm_override=None):
    audio_path = Path(path)
    if not audio_path.is_file():
        raise FileNotFoundError(f"File audio non trovato: {audio_path}")
    y, sample_rate = librosa.load(audio_path, sr=22050, mono=True)
    if y.size == 0:
        raise RuntimeError("File audio vuoto")
    onset = librosa.onset.onset_strength(y=y, sr=sample_rate, hop_length=HOP_LENGTH)
    tempo, beat_frames = librosa.beat.beat_track(
        onset_envelope=onset,
        sr=sample_rate,
        hop_length=HOP_LENGTH,
    )
    rms = librosa.feature.rms(y=y, hop_length=HOP_LENGTH)[0]
    chroma = librosa.feature.chroma_cqt(y=y, sr=sample_rate, hop_length=HOP_LENGTH)
    key, camelot, key_confidence = estimate_key(chroma)
    intro_end, outro_start, build_ups, drops, loops, overview_waveform, sections = detect_structure(y, sample_rate, onset, rms, chroma, beat_frames)
    stem_source, stem_waveforms = analyze_stem_waveforms(audio_path)
    rms_db = finite(librosa.amplitude_to_db(np.array([np.percentile(rms, 75)]), ref=1.0)[0], -60)
    onset_density = len(librosa.onset.onset_detect(onset_envelope=onset, sr=sample_rate)) / max(1, librosa.get_duration(y=y, sr=sample_rate))
    energy_score = round(np.clip((rms_db + 32) / 24 * 75 + min(25, onset_density * 12), 0, 100))
    beat_intervals = np.diff(librosa.frames_to_time(beat_frames, sr=sample_rate, hop_length=HOP_LENGTH))
    bpm_confidence = round(max(0, 100 - finite(np.std(beat_intervals) / (np.mean(beat_intervals) or 1)) * 300)) if beat_intervals.size else 0
    detected_bpm = round(finite(tempo), 2)
    result = {
        "analyzer_version": ANALYZER_VERSION,
        "duration_seconds": round(float(librosa.get_duration(y=y, sr=sample_rate)), 2),
        "bpm": round(float(bpm_override), 3) if bpm_override and float(bpm_override) > 0 else detected_bpm,
        "detected_bpm": detected_bpm,
        "bpm_confidence": bpm_confidence,
        "musical_key": key,
        "camelot": camelot,
        "key_confidence": key_confidence,
        "energy_score": energy_score,
        "intro_end": intro_end,
        "outro_start": outro_start,
        "build_ups": build_ups,
        "drops": drops,
        "loops": loops,
        "overview_waveform": overview_waveform,
        "stem_source": stem_source,
        "stem_waveforms": stem_waveforms,
        "sections": sections,
    }
    result.update(ffmpeg_loudness(audio_path))
    return result


if __name__ == "__main__":
    try:
        if len(sys.argv) >= 5 and sys.argv[1] == "--waveform":
            waveform_path = Path(decode_path(sys.argv[2]))
            waveform_start = float(sys.argv[3])
            waveform_end = float(sys.argv[4])
            waveform_audio, waveform_rate = librosa.load(
                waveform_path,
                sr=22050,
                mono=True,
                offset=max(0, waveform_start),
                duration=max(0.1, waveform_end - waveform_start),
            )
            print(json.dumps({"ok": True, "waveform": waveform_segment(waveform_audio, waveform_rate, 0, waveform_end - waveform_start)}, ensure_ascii=True))
        else:
            bpm_override = float(sys.argv[2]) if len(sys.argv) > 2 else None
            print(json.dumps({"ok": True, **analyze(decode_path(sys.argv[1]), bpm_override)}, ensure_ascii=True))
    except Exception as exception:
        print(json.dumps({"ok": False, "error": str(exception)}, ensure_ascii=True))
        sys.exit(1)
