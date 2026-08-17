import base64
import json
import sys
from pathlib import Path

import librosa
import numpy as np

from audio_analyzer import HOP_LENGTH, estimate_key


def phrase_ranges(duration, bpm, offset, phrase_beats=16):
    phrase_seconds = phrase_beats * 60.0 / bpm
    anchor = offset
    while anchor > 0:
        anchor -= phrase_seconds
    while anchor + phrase_seconds <= 0:
        anchor += phrase_seconds
    ranges = []
    start = anchor
    while start < duration:
        end = start + phrase_seconds
        clipped_start = max(0.0, start)
        clipped_end = min(duration, end)
        if clipped_end - clipped_start >= phrase_seconds * 0.5:
            ranges.append((clipped_start, clipped_end))
        start = end
    if ranges and duration - ranges[-1][1] > 0.05:
        ranges[-1] = (ranges[-1][0], duration)
    return ranges


def main():
    path_argument = sys.argv[1]
    if path_argument.startswith("base64:"):
        path_argument = base64.b64decode(path_argument[7:]).decode("utf-8")
    audio_path = Path(path_argument)
    bpm = float(sys.argv[2])
    offset = float(sys.argv[3])
    phrase_beats = int(sys.argv[4]) if len(sys.argv) > 4 else 16
    if not audio_path.is_file() or bpm <= 0:
        raise RuntimeError("File audio o BPM non disponibile.")
    signal, sample_rate = librosa.load(str(audio_path), sr=22050, mono=True)
    duration = len(signal) / sample_rate
    harmonic = librosa.effects.harmonic(signal, margin=3.0)
    chroma = librosa.feature.chroma_cqt(y=harmonic, sr=sample_rate, hop_length=HOP_LENGTH)
    rms = librosa.feature.rms(y=harmonic, frame_length=2048, hop_length=HOP_LENGTH)[0]
    phrases = []
    for index, (start, end) in enumerate(phrase_ranges(duration, bpm, offset, phrase_beats), 1):
        first = max(0, int(start * sample_rate / HOP_LENGTH))
        last = min(chroma.shape[1], max(first + 1, int(end * sample_rate / HOP_LENGTH)))
        local_chroma = chroma[:, first:last]
        local_energy = float(np.mean(rms[first:min(last, len(rms))])) if first < len(rms) else 0.0
        musical_key, camelot, confidence = estimate_key(local_chroma)
        if local_energy < 0.0005:
            musical_key, camelot, confidence = "", "", 0
        start_beat = round((start - offset) * bpm / 60.0)
        end_beat = round((end - offset) * bpm / 60.0)
        phrases.append({
            "index": index,
            "start": round(start, 3),
            "end": round(end, 3),
            "start_beat": start_beat,
            "end_beat": end_beat,
            "beats": max(1, end_beat - start_beat),
            "musical_key": musical_key,
            "camelot": camelot,
            "confidence": confidence,
        })
    print(json.dumps({"ok": True, "phrase_beats": phrase_beats, "phrases": phrases}, ensure_ascii=True))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(json.dumps({"ok": False, "error": str(error)}, ensure_ascii=True))
        raise SystemExit(1)
