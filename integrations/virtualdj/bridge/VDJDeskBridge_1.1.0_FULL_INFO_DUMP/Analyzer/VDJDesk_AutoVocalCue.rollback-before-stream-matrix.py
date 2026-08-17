#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VDJDesk FULL INFO DUMP 1.1.0

Flusso:
  VDJDesk Bridge 0.2.8 ON
  -> cambia/carica traccia
  -> VDJDeskBridgeState.json cambia
  -> trova .vdjstems
  -> estrae stream "vocal"
  -> analizza inizio/fine parlato
  -> le pause < 8 beat vengono unite
  -> cancella cue 1-8 del deck interessato
  -> scrive fino a 8 cue cronologici

Requisiti:
  - Python 3
  - numpy
  - ffmpeg + ffprobe nel PATH
  - VirtualDJ Network Control su 127.0.0.1:9665
"""

from __future__ import annotations

import argparse
import ctypes
import json
import os
from pathlib import Path
import re
import subprocess
import shutil
import sys
import threading
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from typing import Dict, List, Optional, Tuple

try:
    import numpy as np
    import librosa
    import torch
    from scipy.ndimage import median_filter
except ImportError as exc:
    print(f"ERRORE: dipendenza Python mancante: {exc}")
    print("Esegui INSTALLA_DIPENDENZE_ANALISI.bat")
    sys.exit(1)

# ----------------------------------------------------------------------
# CONFIG
# ----------------------------------------------------------------------

HOST = "127.0.0.1"
PORT = 9665
BASE = f"http://{HOST}:{PORT}"

SR = 22050

SMOOTH_SECONDS = 0.50
MIN_VOCAL_SECTION_SECONDS = 1.50
THRESHOLD_OFFSET_DB = 0.0

# Detector 0.2.20: classificazione a 3 livelli sullo stem VOCAL.
# Il massimo RMS smussato dello stem vale 100%.
#
# 0-25%     = SILENZIO
# >25-50%   = EFFETTI/CORI
# >50%      = CANTATO
VOCAL_SILENCE_MAX = 0.25
VOCAL_EFFECTS_MAX = 0.50
VOICE_SCORE_ENERGY_WEIGHT = 0.34
VOICE_SCORE_DOMINANCE_WEIGHT = 0.26
VOICE_SCORE_MODULATION_WEIGHT = 0.20
VOICE_SCORE_FLUX_WEIGHT = 0.20
VOCAL_NOISE_PERCENTILE = 20.0
VOCAL_SIGNAL_PERCENTILE = 92.0
VOCAL_ON_RATIO = 0.58
VOCAL_OFF_RATIO = 0.40
NOVOX_MAX_VOICE_SCORE = 0.46
NOVOX_MAX_VOICE_P75 = 0.52
NOVOX_HARD_DB_OFFSET = -8.0
NOVOX_MAX_PEAK = 0.55


# ----------------------------------------------------------------------
# DROP DETECTOR 0.2.23
# Separato dalla parte vocal. Non scrive cue.
# ----------------------------------------------------------------------

DROP_INTERVAL_LEVELS = (1.0, 0.5, 0.25, 0.125)
DROP_INTERVAL_TOLERANCE = 0.38
DROP_MIN_ROLL_STAGES = 2
DROP_POST_SEARCH_BEATS = 2.0
DROP_MIN_DISTANCE_BEATS = 8.0
DROP_ONSET_MIN_SECONDS = 0.07
DROP_KICK_PEAK_PERCENTILE = 72.0
DROP_MIN_SCORE = 4.0

# Attivita' minima continua prima di considerare un tratto voce.
MIN_ON_SECONDS = 0.18

# Piccoli buchi interni dovuti al detector/stem vengono assorbiti.
# La regola degli 8 beat NON modifica la maschera reale.
MICRO_GAP_SECONDS = 0.35

MAX_CUES = 16
SPEECH_SR = 16000
SPEECH_CHUNK_SAMPLES = 512
SPEECH_THRESHOLD = 0.10
SPEECH_ENERGY_MARGIN_DB = 18.0
SPEECH_MIN_SECONDS = 0.25
TIMBRE_WINDOW_SECONDS = 2.0
TIMBRE_STEP_SECONDS = 1.0
TIMBRE_ANCHOR_THRESHOLD = 0.30
TIMBRE_SIMILARITY_THRESHOLD = 0.72
KICK_STRONG_MARGIN_DB = 12.0
NOKICK_MIN_BEATS = 8.0
BUILD_WINDOW_BEATS = 4.0
BUILD_MIN_WINDOWS = 2
BUILD_MIN_GAIN_RATIO = 2.5
BUILD_MIN_GAIN_ABSOLUTE = 0.20
SPEECH_PAD_SECONDS = 0.12
SPEECH_JOIN_GAP_SECONDS = 0.30
_SILERO_VAD_MODEL = None


# Drop detector
DROP_LIBROSA_HOP = 256
DROP_ROLL_TOLERANCE = 0.30
DROP_ROLL_MIN_LEVELS = 2
DROP_SEARCH_BEATS_AFTER_ROLL = 2.0
DROP_MIN_SCORE = 4.0
DROP_MIN_SEPARATION_BEATS = 8.0
DROP_BREAK_LOOKBACK_BEATS = 4.0
DROP_CONFIRM_BEATS = 2.0

# Break detector
BREAK_MIN_BEATS = 4.0
BREAK_LOOKBACK_BEATS = 4.0
BREAK_CONFIRM_BEATS = 2.0

# Un break deve essere un calo strutturale, non un singolo buco.
BREAK_MAX_MIX_LEVEL = 0.38
BREAK_MAX_KICK_LEVEL = 0.30
BREAK_MIN_MIX_DROP = 0.20
BREAK_MIN_KICK_DROP = 0.18

# Evita cue BREAK troppo vicini fra loro.
BREAK_MIN_SEPARATION_BEATS = 8.0
POLL_SECONDS = 0.50

URBAN_VOCAL_MAX_SCORE = 0.44
URBAN_EFFECTS_MAX_SCORE = 0.58
URBAN_GROOVE_MIN_SCORE = 0.28
URBAN_MIN_SECTION_BEATS = 8.0
URBAN_JOIN_GAP_BEATS = 0.75
URBAN_SECTION_MAX_MEAN = 0.42
URBAN_SECTION_MAX_P75 = 0.50
URBAN_SECTION_MAX_VOICED_FRACTION = 0.28
LATIN_URBAN_NO_KICK_MAX = 0.22
LATIN_URBAN_GROOVE_MIN_KICK = 0.42
# Attesa massima per uno stem che VirtualDJ potrebbe stare ancora creando.
STEM_WAIT_SECONDS = 20
STEM_RETRY_SECONDS = 1.0

LOCALAPPDATA = Path(os.environ.get("LOCALAPPDATA", str(Path.home())))
VDJ_DIR = LOCALAPPDATA / "VirtualDJ"

STATE_PATH = VDJ_DIR / "VDJDeskBridgeState.json"
LOG_PATH = VDJ_DIR / "VDJDesk_AutoVocalCue.log"
RESULT_PATH = VDJ_DIR / "VDJDesk_AutoVocalCueState.json"
CACHE_PATH = VDJ_DIR / "VDJDesk_AutoVocalCueCache.json"

# ----------------------------------------------------------------------
# LOG
# ----------------------------------------------------------------------



def log(message: str = "") -> None:
    stamp = time.strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{stamp}] {message}" if message else ""
    try:
        print(line, flush=True)
    except OSError:
        pass

    try:
        VDJ_DIR.mkdir(parents=True, exist_ok=True)
        with LOG_PATH.open("a", encoding="utf-8") as f:
            f.write(line + "\n")
    except Exception:
        pass


# ----------------------------------------------------------------------
# NETWORK CONTROL
# ----------------------------------------------------------------------

def vdj_call(endpoint: str, script: str) -> str:
    url = f"{BASE}/{endpoint}?{urllib.parse.urlencode({'script': script})}"
    req = urllib.request.Request(url, method="GET")

    with urllib.request.urlopen(req, timeout=4) as r:
        return r.read().decode("utf-8", errors="replace").strip()


def vdj_query(script: str) -> str:
    return vdj_call("query", script)


def vdj_execute(script: str) -> bool:
    result = vdj_call("execute", script)
    return result.strip().lower() == "true"


def network_control_ok() -> bool:
    try:
        vdj_query("get_clock")
        return True
    except Exception as exc:
        log(f"Network Control non raggiungibile: {exc}")
        return False


# ----------------------------------------------------------------------
# STATE BRIDGE
# ----------------------------------------------------------------------

def load_bridge_state() -> Optional[dict]:
    try:
        with STATE_PATH.open("r", encoding="utf-8-sig") as f:
            data = json.load(f)

        if not isinstance(data, dict):
            return None

        return data
    except FileNotFoundError:
        return None
    except Exception as exc:
        log(f"Errore lettura state bridge: {exc}")
        return None


def normalize_decks(state: dict) -> List[dict]:
    decks = state.get("decks")

    if isinstance(decks, list):
        out = []
        for item in decks:
            if isinstance(item, dict):
                out.append(item)
        return out

    # Compatibilita con vecchi state a singolo deck.
    if "deck" in state:
        return [state]

    return []


# ----------------------------------------------------------------------
# STEM SEARCH
# ----------------------------------------------------------------------

def _clean_path_text(value: str) -> str:
    value = (value or "").strip().strip('"').strip("'")
    if not value:
        return ""
    return os.path.expandvars(value)


def query_vdj_stems_saved_folder() -> str:
    """
    Prova a leggere stemsSavedFolder tramite Network Control.
    Alcune build/script espongono le option con sintassi diverse,
    quindi proviamo piu forme senza considerare un fallimento fatale.
    """
    commands = [
        "get_setting 'stemsSavedFolder'",
        'get_setting "stemsSavedFolder"',
        "setting 'stemsSavedFolder'",
        'setting "stemsSavedFolder"',
    ]

    for cmd in commands:
        try:
            raw = vdj_query(cmd)
        except Exception:
            continue

        raw = _clean_path_text(raw)

        if (
            raw
            and not raw.lower().startswith("error")
            and raw.lower() not in {"false", "true", "0", "1"}
        ):
            p = Path(raw)
            if p.exists():
                log(f"stemsSavedFolder da VirtualDJ: {p}")
                return str(p)

    return ""


def find_stems_saved_folder_in_settings() -> str:
    """
    Cerca stemsSavedFolder nei file di configurazione VirtualDJ.
    Non presume una sola posizione del profilo.
    """
    possible_roots = [
        VDJ_DIR,
        Path.home() / "Documents" / "VirtualDJ",
    ]

    names = [
        "settings.xml",
        "Settings.xml",
        "settings.json",
        "Settings.json",
    ]

    candidates = []
    for base in possible_roots:
        for name in names:
            candidates.append(base / name)

    # Aggiunge eventuali settings trovati un livello sotto.
    for base in possible_roots:
        if not base.exists():
            continue
        try:
            for p in base.glob("*.xml"):
                if "setting" in p.name.lower():
                    candidates.append(p)
        except Exception:
            pass

    seen = set()

    for path in candidates:
        key = str(path).lower()
        if key in seen or not path.is_file():
            continue
        seen.add(key)

        try:
            text = path.read_text(encoding="utf-8-sig", errors="replace")
        except Exception:
            continue

        # XML/JSON/plain fallback: cerca letteralmente stemsSavedFolder.
        patterns = [
            r'stemsSavedFolder\s*=\s*"([^"]+)"',
            r'stemsSavedFolder\s*=\s*\'([^\']+)\'',
            r'"stemsSavedFolder"\s*:\s*"([^"]+)"',
            r'<stemsSavedFolder>\s*([^<]+)\s*</stemsSavedFolder>',
            r'name\s*=\s*"stemsSavedFolder"[^>]*value\s*=\s*"([^"]+)"',
            r'name\s*=\s*"stemsSavedFolder"[^>]*>([^<]+)<',
        ]

        for pattern in patterns:
            m = re.search(pattern, text, re.IGNORECASE)
            if not m:
                continue

            value = _clean_path_text(m.group(1))
            if value and Path(value).exists():
                log(f"stemsSavedFolder da {path}: {value}")
                return value

    return ""


_STEMS_SAVED_FOLDER_CACHE: Optional[str] = None


def get_stems_saved_folder() -> Optional[Path]:
    global _STEMS_SAVED_FOLDER_CACHE

    if _STEMS_SAVED_FOLDER_CACHE is not None:
        return Path(_STEMS_SAVED_FOLDER_CACHE) if _STEMS_SAVED_FOLDER_CACHE else None

    value = query_vdj_stems_saved_folder()

    if not value:
        value = find_stems_saved_folder_in_settings()

    _STEMS_SAVED_FOLDER_CACHE = value or ""

    if value:
        return Path(value)

    log("stemsSavedFolder non configurato/rilevato: uso cartella sorgente + fallback.")
    return None


def stem_name_matches(audio_path: Path, candidate: Path) -> bool:
    """
    Match permissivo sui nomi usati nelle varie modalita di salvataggio.
    """
    c = candidate.name.lower()
    filename = audio_path.name.lower()
    stem = audio_path.stem.lower()

    expected = {
        filename + ".vdjstems",
        stem + ".vdjstems",
    }

    if c in expected:
        return True

    # Alcune cartelle centralizzate possono alterare leggermente il nome:
    # consideriamo valido un .vdjstems che contenga il nome base completo.
    return c.endswith(".vdjstems") and stem in c


def stem_candidates(audio_path: Path) -> List[Path]:
    """
    Cerca:
      1) accanto al brano;
      2) nella stemsSavedFolder configurata in VirtualDJ;
      3) in comuni cartelle VirtualDJ di fallback.
    """
    candidates: List[Path] = []

    # Default VirtualDJ: prepared stems accanto al file sorgente.
    candidates.append(Path(str(audio_path) + ".vdjstems"))
    candidates.append(audio_path.with_suffix(".vdjstems"))

    try:
        for p in audio_path.parent.glob("*.vdjstems"):
            if stem_name_matches(audio_path, p):
                candidates.append(p)
    except Exception:
        pass

    # Cartella personalizzata ufficiale VirtualDJ.
    saved_folder = get_stems_saved_folder()
    if saved_folder and saved_folder.exists():
        expected_names = [
            audio_path.name + ".vdjstems",
            audio_path.stem + ".vdjstems",
        ]

        for name in expected_names:
            candidates.append(saved_folder / name)

        # La cartella puo contenere sottocartelle: ricerca per nomi esatti,
        # poi match permissivo sul basename.
        try:
            for name in expected_names:
                candidates.extend(saved_folder.rglob(name))
        except Exception:
            pass

        try:
            for p in saved_folder.rglob("*.vdjstems"):
                if stem_name_matches(audio_path, p):
                    candidates.append(p)
        except Exception:
            pass

    common_roots = [
        VDJ_DIR / "Cache",
        VDJ_DIR / "Stems",
        VDJ_DIR / "Cache" / "Stems",
        Path.home() / "Documents" / "VirtualDJ" / "Cache",
        Path.home() / "Documents" / "VirtualDJ" / "Stems",
    ]

    for base in common_roots:
        if not base.exists():
            continue

        try:
            for p in base.rglob("*.vdjstems"):
                if stem_name_matches(audio_path, p):
                    candidates.append(p)
        except Exception:
            pass

    out: List[Path] = []
    seen = set()

    for p in candidates:
        try:
            key = str(p.resolve()).lower()
        except Exception:
            key = str(p).lower()

        if key in seen:
            continue

        seen.add(key)
        out.append(p)

    return out


def is_valid_vdjstems(path: Path) -> bool:
    if not path.is_file():
        return False

    try:
        raw = subprocess.check_output(
            [
                "ffprobe",
                "-v", "error",
                "-show_entries", "stream=index,codec_type:stream_tags=title",
                "-of", "json",
                str(path),
            ],
            stderr=subprocess.STDOUT,
            timeout=15,
        )
        info = json.loads(raw.decode("utf-8", errors="replace"))

        for stream in info.get("streams", []):
            if stream.get("codec_type") != "audio":
                continue

            title = str(stream.get("tags", {}).get("title", "")).strip().lower()
            if title == "vocal":
                return True

    except Exception:
        pass

    return False


def find_stem(audio_path: Path, wait: bool = True) -> Optional[Path]:
    deadline = time.time() + (STEM_WAIT_SECONDS if wait else 0)
    attempt = 0

    while True:
        attempt += 1
        candidates = stem_candidates(audio_path)

        if attempt == 1:
            log(f"Candidati stem trovati/calcolati: {len(candidates)}")
            for p in candidates[:30]:
                log(f"  STEM? {p}")

        for candidate in candidates:
            if not candidate.is_file():
                continue

            log(f"Verifico stem: {candidate}")

            if is_valid_vdjstems(candidate):
                log(f"STEM VALIDO: {candidate}")
                return candidate
            else:
                log(f"File .vdjstems presente ma stream vocal non rilevato: {candidate}")

        if time.time() >= deadline:
            return None

        time.sleep(STEM_RETRY_SECONDS)


# ----------------------------------------------------------------------
# FFMPEG / VOCAL STREAM

# ----------------------------------------------------------------------

def probe_titled_stream(path: Path, wanted_title: str) -> int:
    raw = subprocess.check_output(
        [
            "ffprobe",
            "-v", "error",
            "-show_streams",
            "-show_format",
            "-of", "json",
            str(path),
        ],
        stderr=subprocess.STDOUT,
        timeout=30,
    )

    info = json.loads(raw.decode("utf-8", errors="replace"))
    wanted = wanted_title.strip().lower()

    for stream in info.get("streams", []):
        if stream.get("codec_type") != "audio":
            continue

        title = str(stream.get("tags", {}).get("title", "")).strip().lower()

        if title == wanted:
            return int(stream["index"])

    raise RuntimeError(f'Stream audio title="{wanted_title}" non trovato.')


def probe_vocal_stream(path: Path) -> int:
    return probe_titled_stream(path, "vocal")


def probe_instruments_stream(path: Path) -> Optional[int]:
    try:
        return probe_titled_stream(path, "instruments")
    except Exception:
        return None


def probe_optional_stream(path: Path, title: str) -> Optional[int]:
    try:
        return probe_titled_stream(path, title)
    except Exception:
        return None


def probe_kick_stream(path: Path) -> Optional[int]:
    return probe_optional_stream(path, "kick")


def probe_bass_stream(path: Path) -> Optional[int]:
    return probe_optional_stream(path, "bass")


def probe_hihat_stream(path: Path) -> Optional[int]:
    return probe_optional_stream(path, "hihat")


def decode_stream(path: Path, stream_index: int) -> np.ndarray:
    raw = subprocess.check_output(
        [
            "ffmpeg",
            "-v", "error",
            "-i", str(path),
            "-map", f"0:{stream_index}",
            "-ac", "1",
            "-ar", str(SR),
            "-f", "f32le",
            "pipe:1",
        ],
        stderr=subprocess.STDOUT,
        timeout=180,
    )

    return np.frombuffer(raw, dtype="<f4").copy()


def decode_vocal(path: Path, stream_index: int) -> np.ndarray:
    return decode_stream(path, stream_index)


# ----------------------------------------------------------------------
# VOCAL ANALYSIS - stessa logica costruita prima
# ----------------------------------------------------------------------

def runs(mask: np.ndarray) -> List[Tuple[bool, int, int]]:
    if len(mask) == 0:
        return []

    out: List[Tuple[bool, int, int]] = []
    start = 0
    value = bool(mask[0])

    for i in range(1, len(mask)):
        current = bool(mask[i])
        if current != value:
            out.append((value, start, i))
            start = i
            value = current

    out.append((value, start, len(mask)))
    return out


def fill_short_silences(
    mask: np.ndarray,
    seconds_per_frame: float,
    min_silence_seconds: float,
) -> np.ndarray:
    out = mask.copy()

    for value, start, end in runs(out):
        duration = (end - start) * seconds_per_frame

        if (
            not value
            and duration < min_silence_seconds
            and start > 0
            and end < len(out)
        ):
            out[start:end] = True

    return out


def remove_short_vocals(
    mask: np.ndarray,
    seconds_per_frame: float,
) -> np.ndarray:
    out = mask.copy()

    for value, start, end in runs(out):
        duration = (end - start) * seconds_per_frame

        if value and duration < MIN_VOCAL_SECTION_SECONDS:
            out[start:end] = False

    return out


def _moving_average(x: np.ndarray, frames: int) -> np.ndarray:
    frames = max(1, int(frames))
    if frames <= 1:
        return x.copy()
    kernel = np.ones(frames, dtype=np.float64) / frames
    return np.convolve(x, kernel, mode="same")


def _logistic(x: np.ndarray) -> np.ndarray:
    x = np.clip(x, -30.0, 30.0)
    return 1.0 / (1.0 + np.exp(-x))


def compute_voice_confidence(
    vocal: np.ndarray,
    instruments: Optional[np.ndarray],
    frame: int,
    hop: int,
    rms: np.ndarray,
    db: np.ndarray,
) -> Tuple[np.ndarray, dict]:
    """
    Confidence 0..1 per frame.

    Non tenta di riconoscere parole: misura caratteristiche che rendono
    piu probabile una sorgente vocale rispetto a synth/bleed.

    Componenti:
      - energy: presenza reale nello stem vocal;
      - dominance: vocal piu forte dello stesso istante in instruments;
      - modulation: variazione dell'inviluppo compatibile con sillabe/frasi;
      - spectral flux: cambi spettrali nel tempo (formanti/pronuncia).
    """
    n_frames = len(rms)
    seconds_per_frame = hop / SR

    # 1) ENERGY
    # Mappa la distanza dalla soglia energetica in un punteggio morbido.
    energy_floor = float(np.median(db))
    energy_score = _logistic((db - energy_floor) / 4.0)

    # 2) DOMINANCE vocal vs instruments
    if instruments is not None and len(instruments) >= frame:
        inst_power = instruments.astype(np.float64) ** 2
        kernel = np.ones(frame, dtype=np.float64) / frame
        inst_mean_power = np.convolve(inst_power, kernel, mode="valid")[::hop]
        inst_rms = np.sqrt(np.maximum(inst_mean_power, 1e-12))

        m = min(len(inst_rms), n_frames)
        dominance_db = np.zeros(n_frames, dtype=np.float64)
        dominance_db[:m] = 20.0 * np.log10(
            np.maximum(rms[:m], 1e-9) / np.maximum(inst_rms[:m], 1e-9)
        )
        if m < n_frames:
            dominance_db[m:] = dominance_db[m - 1] if m else 0.0

        # 0 dB circa neutro; +6 dB fortemente vocal-dominante.
        dominance_score = _logistic((dominance_db - 0.5) / 3.0)
    else:
        dominance_db = np.zeros(n_frames, dtype=np.float64)
        # Se instruments non c'e', non penalizziamo troppo.
        dominance_score = np.full(n_frames, 0.55, dtype=np.float64)

    # 3) MODULATION dell'inviluppo
    # Voce/rap/canto: l'energia cambia continuamente a scala sillabica.
    env_db = 20.0 * np.log10(np.maximum(rms, 1e-9))
    local_window = max(3, int(round(1.50 / seconds_per_frame)))
    mean_env = _moving_average(env_db, local_window)
    mean_sq = _moving_average(env_db ** 2, local_window)
    local_std = np.sqrt(np.maximum(mean_sq - mean_env ** 2, 0.0))

    # ~1 dB = molto statico, 3-6 dB = tipicamente dinamico.
    modulation_score = _logistic((local_std - 1.6) / 1.2)

    # 4) SPECTRAL FLUX
    # Calcolo STFT solo sul vocal, normalizzato per energia.
    flux = np.zeros(n_frames, dtype=np.float64)

    if len(vocal) >= frame:
        window = np.hanning(frame).astype(np.float64)
        prev = None
        fi = 0

        for pos in range(0, len(vocal) - frame + 1, hop):
            if fi >= n_frames:
                break

            segment = vocal[pos:pos + frame].astype(np.float64) * window
            mag = np.abs(np.fft.rfft(segment))
            norm = np.linalg.norm(mag) + 1e-12
            mag = mag / norm

            if prev is not None:
                delta = mag - prev
                flux[fi] = np.sqrt(np.mean(np.maximum(delta, 0.0) ** 2))

            prev = mag
            fi += 1

    flux_smooth_frames = max(1, int(round(0.40 / seconds_per_frame)))
    flux_sm = _moving_average(flux, flux_smooth_frames)

    # Adattivo al brano: usa percentili del vocal stesso.
    positive_flux = flux_sm[flux_sm > 0]
    if len(positive_flux):
        p35 = float(np.percentile(positive_flux, 35))
        p75 = float(np.percentile(positive_flux, 75))
        span = max(p75 - p35, 1e-8)
        flux_score = np.clip((flux_sm - p35) / span, 0.0, 1.0)
    else:
        flux_score = np.full(n_frames, 0.5, dtype=np.float64)

    total = (
        VOICE_SCORE_ENERGY_WEIGHT * energy_score
        + VOICE_SCORE_DOMINANCE_WEIGHT * dominance_score
        + VOICE_SCORE_MODULATION_WEIGHT * modulation_score
        + VOICE_SCORE_FLUX_WEIGHT * flux_score
    )

    total = np.clip(total, 0.0, 1.0)

    stats = {
        "voiceScoreMean": float(np.mean(total)) if len(total) else 0.0,
        "voiceScoreP50": float(np.percentile(total, 50)) if len(total) else 0.0,
        "voiceScoreP75": float(np.percentile(total, 75)) if len(total) else 0.0,
        "dominanceDbMedian": float(np.median(dominance_db)) if len(dominance_db) else 0.0,
        "modulationStdMedian": float(np.median(local_std)) if len(local_std) else 0.0,
        "fluxMedian": float(np.median(flux_sm)) if len(flux_sm) else 0.0,
    }

    return total, stats


def _threshold_from_percentiles(db: np.ndarray) -> Tuple[float, float, dict]:
    """
    Soglia adattiva ricavata SOLO dallo stem vocal.

    noise = percentile basso dello stem
    signal = percentile alto dello stem

    Le soglie ON/OFF sono interpolazioni fra noise e signal.
    """
    finite = db[np.isfinite(db)]

    if len(finite) == 0:
        raise RuntimeError("Nessun dato energetico valido nello stem vocal.")

    noise_db = float(np.percentile(finite, VOCAL_NOISE_PERCENTILE))
    signal_db = float(np.percentile(finite, VOCAL_SIGNAL_PERCENTILE))

    # Evita una dinamica nulla su stem strani/quasi silenziosi.
    span = max(signal_db - noise_db, 6.0)

    on_db = noise_db + VOCAL_ON_RATIO * span
    off_db = noise_db + VOCAL_OFF_RATIO * span

    return on_db, off_db, {
        "noiseDb": noise_db,
        "signalDb": signal_db,
        "spanDb": span,
        "onDb": on_db,
        "offDb": off_db,
    }


def _hysteresis_mask(
    db: np.ndarray,
    on_db: float,
    off_db: float,
) -> np.ndarray:
    """
    Entra in VOCAL quando supera on_db.
    Resta VOCAL finchÃ© non scende sotto off_db.
    """
    out = np.zeros(len(db), dtype=bool)
    state = False

    for i, value in enumerate(db):
        if not state:
            if value >= on_db:
                state = True
        else:
            if value < off_db:
                state = False

        out[i] = state

    return out


def _remove_short_on_runs(
    mask: np.ndarray,
    seconds_per_frame: float,
    minimum_seconds: float,
) -> np.ndarray:
    out = mask.copy()

    for value, start, end in runs(out):
        if not value:
            continue

        duration = (end - start) * seconds_per_frame
        if duration < minimum_seconds:
            out[start:end] = False

    return out


def _frame_rms_db(
    audio: np.ndarray,
    frame: int,
    hop: int,
) -> Tuple[np.ndarray, np.ndarray]:
    if len(audio) < frame:
        return np.zeros(0, dtype=np.float64), np.zeros(0, dtype=np.float64)

    rms = librosa.feature.rms(
        y=audio,
        frame_length=frame,
        hop_length=hop,
    )[0].astype(np.float64)
    db = 20.0 * np.log10(np.maximum(rms, 1e-9))
    return rms, db


def _align_frames(values: np.ndarray, n_frames: int) -> np.ndarray:
    if n_frames <= 0:
        return np.zeros(0, dtype=np.float64)
    if len(values) == n_frames:
        return values.astype(np.float64, copy=False)
    if len(values) == 0:
        return np.zeros(n_frames, dtype=np.float64)
    if len(values) > n_frames:
        return values[:n_frames].astype(np.float64, copy=False)

    out = np.empty(n_frames, dtype=np.float64)
    out[:len(values)] = values
    out[len(values):] = values[-1]
    return out


def _silero_speech_probabilities(
    vocal: np.ndarray,
) -> Tuple[np.ndarray, np.ndarray, np.ndarray, float]:
    global _SILERO_VAD_MODEL

    model_path = Path(__file__).with_name("silero_vad.jit")
    if not model_path.is_file():
        raise RuntimeError(f"Modello speech mancante: {model_path}")

    if _SILERO_VAD_MODEL is None:
        torch.set_num_threads(1)
        _SILERO_VAD_MODEL = torch.jit.load(str(model_path), map_location="cpu")

    speech_audio = librosa.resample(
        vocal.astype(np.float32, copy=False),
        orig_sr=SR,
        target_sr=SPEECH_SR,
    ).astype(np.float32, copy=False)
    if len(speech_audio) == 0:
        return (
            np.zeros(0, dtype=np.float64),
            np.zeros(0, dtype=np.float64),
            np.zeros(0, dtype=np.float32),
            SPEECH_CHUNK_SAMPLES / SPEECH_SR,
        )

    remainder = len(speech_audio) % SPEECH_CHUNK_SAMPLES
    if remainder:
        speech_audio = np.pad(
            speech_audio,
            (0, SPEECH_CHUNK_SAMPLES - remainder),
        )

    _SILERO_VAD_MODEL.reset_states()
    probabilities = []
    levels_db = []
    with torch.inference_mode():
        for start in range(0, len(speech_audio), SPEECH_CHUNK_SAMPLES):
            chunk_audio = speech_audio[start:start + SPEECH_CHUNK_SAMPLES]
            chunk = torch.from_numpy(chunk_audio)
            probabilities.append(float(_SILERO_VAD_MODEL(chunk, SPEECH_SR).item()))
            chunk_rms = float(np.sqrt(np.mean(np.square(chunk_audio))))
            levels_db.append(20.0 * np.log10(max(chunk_rms, 1e-9)))

    return (
        np.asarray(probabilities, dtype=np.float64),
        np.asarray(levels_db, dtype=np.float64),
        speech_audio,
        SPEECH_CHUNK_SAMPLES / SPEECH_SR,
    )


def _timbral_speech_mask(
    speech_audio: np.ndarray,
    speech_probability: np.ndarray,
    vocal_level_db: np.ndarray,
    seconds_per_frame: float,
    speech_energy_db: float,
) -> np.ndarray:
    output = np.zeros(len(speech_probability), dtype=bool)
    window_samples = int(round(TIMBRE_WINDOW_SECONDS * SPEECH_SR))
    step_samples = int(round(TIMBRE_STEP_SECONDS * SPEECH_SR))
    if len(speech_audio) < window_samples:
        return output

    features = []
    windows = []
    anchor_flags = []
    for start_sample in range(
        0,
        len(speech_audio) - window_samples + 1,
        step_samples,
    ):
        end_sample = start_sample + window_samples
        chunk = speech_audio[start_sample:end_sample]
        mfcc = librosa.feature.mfcc(
            y=chunk,
            sr=SPEECH_SR,
            n_mfcc=20,
            n_fft=512,
            hop_length=160,
        )
        chroma = librosa.feature.chroma_stft(
            y=chunk,
            sr=SPEECH_SR,
            n_fft=512,
            hop_length=160,
        )
        features.append(
            np.concatenate(
                [
                    np.mean(mfcc, axis=1),
                    np.std(mfcc, axis=1),
                    np.mean(chroma, axis=1),
                    np.std(chroma, axis=1),
                ]
            )
        )

        start_time = start_sample / SPEECH_SR
        end_time = end_sample / SPEECH_SR
        start_frame = max(0, int(np.floor(start_time / seconds_per_frame)))
        end_frame = min(
            len(speech_probability),
            int(np.ceil(end_time / seconds_per_frame)),
        )
        windows.append((start_frame, end_frame))
        anchor_flags.append(
            end_frame > start_frame
            and float(np.mean(speech_probability[start_frame:end_frame]))
            >= TIMBRE_ANCHOR_THRESHOLD
            and float(np.mean(vocal_level_db[start_frame:end_frame]))
            >= speech_energy_db
        )

    feature_matrix = np.asarray(features, dtype=np.float64)
    anchor_mask = np.asarray(anchor_flags, dtype=bool)
    if not np.any(anchor_mask):
        return output

    feature_mean = np.mean(feature_matrix, axis=0)
    feature_std = np.std(feature_matrix, axis=0)
    feature_std[feature_std < 1e-9] = 1.0
    feature_matrix = (feature_matrix - feature_mean) / feature_std
    norms = np.linalg.norm(feature_matrix, axis=1, keepdims=True)
    feature_matrix = feature_matrix / np.maximum(norms, 1e-9)
    similarity = np.max(
        feature_matrix @ feature_matrix[anchor_mask].T,
        axis=1,
    )

    for is_speech, (start_frame, end_frame) in zip(
        similarity >= TIMBRE_SIMILARITY_THRESHOLD,
        windows,
    ):
        if is_speech:
            output[start_frame:end_frame] = True

    return output


def analyze_urban_non_vocal_sections(
    stem_path: Path,
    bpm: float,
    genre: str = "",
) -> dict:
    vocal_stream = probe_vocal_stream(stem_path)
    vocal = decode_vocal(stem_path, vocal_stream)

    speech_probability, vocal_level_db, speech_audio, seconds_per_frame = (
        _silero_speech_probabilities(vocal)
    )
    if len(speech_probability) == 0:
        raise RuntimeError("Stem vocal troppo corto o non analizzabile.")

    vocal_signal_db = float(np.percentile(vocal_level_db, 95.0))
    speech_energy_db = vocal_signal_db - SPEECH_ENERGY_MARGIN_DB
    speech_mask = (
        (speech_probability >= SPEECH_THRESHOLD)
        & (vocal_level_db >= speech_energy_db)
    )
    speech_mask |= _timbral_speech_mask(
        speech_audio,
        speech_probability,
        vocal_level_db,
        seconds_per_frame,
        speech_energy_db,
    )
    speech_mask = _remove_short_on_runs(
        speech_mask,
        seconds_per_frame,
        SPEECH_MIN_SECONDS,
    )
    pad_frames = max(1, int(np.ceil(SPEECH_PAD_SECONDS / seconds_per_frame)))
    speech_mask = np.convolve(
        speech_mask.astype(np.int8),
        np.ones(2 * pad_frames + 1, dtype=np.int8),
        mode="same",
    ) > 0
    speech_mask = fill_short_silences(
        speech_mask,
        seconds_per_frame,
        SPEECH_JOIN_GAP_SECONDS,
    )

    blank_mask = ~speech_mask
    minimum_novox_seconds = 8.0 * 60.0 / max(bpm, 1.0)
    blank_mask = _remove_short_on_runs(
        blank_mask,
        seconds_per_frame,
        minimum_novox_seconds,
    )

    sections = []
    for value, start, end in runs(blank_mask):
        if not value:
            continue

        start_time = start * seconds_per_frame
        end_time = end * seconds_per_frame
        duration = end_time - start_time
        beats = duration / (60.0 / max(bpm, 1.0))
        if duration <= 0.0 or beats < 8.0:
            continue

        section_probability = speech_probability[start:end]
        section_mean_probability = (
            float(np.mean(section_probability)) if len(section_probability) else 0.0
        )
        section_max_probability = (
            float(np.max(section_probability)) if len(section_probability) else 0.0
        )

        sections.append(
            {
                "time": float(start_time),
                "end": float(end_time),
                "duration": float(duration),
                "beats": float(beats),
                "name": "NOVOX",
                "type": "novox",
                "color": "green",
                "speechMean": section_mean_probability,
                "speechMax": section_max_probability,
            }
        )

    log(
        "NOVOX STEM 0: "
        f"speechThreshold={SPEECH_THRESHOLD:.2f} | "
        f"speechEnergyDb={speech_energy_db:.2f} | "
        f"timbreSimilarity={TIMBRE_SIMILARITY_THRESHOLD:.2f} | "
        f"minBeat=8 | "
        f"sections={len(sections)}"
    )

    for idx, section in enumerate(sections, start=1):
        log(
            f"NOVOX {idx}: "
            f"{section['time']:.3f}s -> "
            f"{section['end']:.3f}s | "
            f"dur={section['duration']:.2f}s | "
            f"beat={section['beats']:.1f} | "
            f"speechMean={section['speechMean']:.3f} | "
            f"speechMax={section['speechMax']:.3f}"
        )

    return {
        "engine": "vocal blank detector",
        "sectionLabel": "NOVOX",
        "sectionType": "novox",
        "voiceStats": {
            "speechThreshold": SPEECH_THRESHOLD,
            "speechEnergyDb": speech_energy_db,
            "timbreSimilarity": TIMBRE_SIMILARITY_THRESHOLD,
        },
        "sections": sections,
        "noKickSections": [],
        "grooveStartSections": [],
    }


def analyze_no_kick_sections(stem_path: Path, bpm: float) -> dict:
    kick_stream = probe_kick_stream(stem_path)
    if kick_stream is None:
        raise RuntimeError("Stream kick non trovato nello stem VirtualDJ.")

    kick = decode_stream(stem_path, kick_stream)
    frame = 2048
    hop = 512
    seconds_per_frame = hop / SR
    _, kick_db = _frame_rms_db(kick, frame, hop)
    if len(kick_db) == 0:
        raise RuntimeError("Stem kick troppo corto o non analizzabile.")

    finite = kick_db[np.isfinite(kick_db)]
    noise_db = float(np.percentile(finite, 10.0))
    signal_db = float(np.percentile(finite, 95.0))
    beat_seconds = 60.0 / max(bpm, 1.0)
    strong_kick_db = signal_db - KICK_STRONG_MARGIN_DB
    kick_mask = kick_db >= strong_kick_db
    no_kick_mask = ~kick_mask
    minimum_seconds = NOKICK_MIN_BEATS * beat_seconds
    no_kick_mask = _remove_short_on_runs(
        no_kick_mask,
        seconds_per_frame,
        minimum_seconds,
    )

    sections = []
    for value, start, end in runs(no_kick_mask):
        if not value:
            continue
        start_time = start * seconds_per_frame
        end_time = end * seconds_per_frame
        duration = end_time - start_time
        beats = duration / beat_seconds
        if beats < NOKICK_MIN_BEATS:
            continue
        sections.append(
            {
                "time": float(start_time),
                "end": float(end_time),
                "duration": float(duration),
                "beats": float(beats),
                "name": "NOKICK",
                "type": "nokick",
                "color": "cyan",
            }
        )

    log(
        "NOKICK STEM 4: "
        f"noiseDb={noise_db:.2f} | "
        f"signalDb={signal_db:.2f} | "
        f"strongKickDb={strong_kick_db:.2f} | "
        f"minBeat={NOKICK_MIN_BEATS:.0f} | "
        f"sections={len(sections)}"
    )
    for idx, section in enumerate(sections, start=1):
        log(
            f"NOKICK {idx}: "
            f"{section['time']:.3f}s -> {section['end']:.3f}s | "
            f"dur={section['duration']:.2f}s | "
            f"beat={section['beats']:.1f}"
        )

    return {"sections": sections}


def analyze_build_drop_sections(
    stem_path: Path,
    bpm: float,
    no_kick_sections: List[dict],
) -> List[dict]:
    hihat_stream = probe_hihat_stream(stem_path)
    if hihat_stream is None:
        return []

    hihat = decode_stream(stem_path, hihat_stream)
    hop = 512
    onset = librosa.onset.onset_strength(y=hihat, sr=SR, hop_length=hop)
    onset_times = librosa.frames_to_time(
        np.arange(len(onset)),
        sr=SR,
        hop_length=hop,
    )
    beat_seconds = 60.0 / max(bpm, 1.0)
    window_seconds = BUILD_WINDOW_BEATS * beat_seconds
    track_end = len(hihat) / SR
    pairs = []

    for section in no_kick_sections:
        section_start = float(section["time"])
        section_end = float(section["end"])
        if section_end >= track_end - beat_seconds:
            continue

        window_starts = np.arange(section_start, section_end, window_seconds)
        if len(window_starts) < BUILD_MIN_WINDOWS + 1:
            continue

        density = []
        for start in window_starts:
            end = min(section_end, start + window_seconds)
            values = onset[(onset_times >= start) & (onset_times < end)]
            density.append(float(np.mean(values)) if values.size else 0.0)

        best = None
        for start_index in range(len(density) - BUILD_MIN_WINDOWS):
            for end_index in range(
                start_index + BUILD_MIN_WINDOWS,
                min(len(density), start_index + 6),
            ):
                sequence = density[start_index : end_index + 1]
                rises = sum(
                    sequence[index + 1] > sequence[index]
                    for index in range(len(sequence) - 1)
                )
                if rises < BUILD_MIN_WINDOWS:
                    continue
                gain = sequence[-1] - sequence[0]
                ratio = sequence[-1] / max(sequence[0], 0.05)
                if gain < BUILD_MIN_GAIN_ABSOLUTE or ratio < BUILD_MIN_GAIN_RATIO:
                    continue
                score = gain * ratio
                if best is None or score > best[0]:
                    best = (score, start_index + 1)

        if best is None:
            continue

        pairs.append(
            {
                "build": float(window_starts[best[1]]),
                "drop": section_end,
            }
        )

    log(f"BUILD/DROP HIHAT: pairs={len(pairs)}")
    for index, pair in enumerate(pairs, start=1):
        log(
            f"BUILD {index}: {pair['build']:.3f}s | "
            f"DROP {index}: {pair['drop']:.3f}s"
        )
    return pairs


# ----------------------------------------------------------------------
# DROP ANALYSIS - librosa MIR engine
# ----------------------------------------------------------------------

def _norm_feature(x: np.ndarray) -> np.ndarray:
    x = np.asarray(x, dtype=np.float64)

    if len(x) == 0:
        return x

    lo = float(np.percentile(x, 5))
    hi = float(np.percentile(x, 95))

    if hi <= lo + 1e-12:
        return np.zeros_like(x)

    return np.clip(
        (x - lo) / (hi - lo),
        0.0,
        1.0,
    )


def _feature_mean_between(
    values: np.ndarray,
    times: np.ndarray,
    start: float,
    end: float,
) -> float:
    if len(values) == 0 or len(times) == 0:
        return 0.0

    mask = (times >= start) & (times < end)

    if not np.any(mask):
        center = (start + end) / 2.0
        return float(
            np.interp(center, times, values)
        )

    return float(np.mean(values[mask]))


def _quantize_beat_interval_librosa(
    beats: float,
) -> Optional[float]:
    levels = np.asarray(
        [1.0, 0.5, 0.25, 0.125],
        dtype=np.float64,
    )

    idx = int(np.argmin(np.abs(levels - beats)))
    level = float(levels[idx])

    rel_error = abs(beats - level) / level

    if rel_error <= DROP_ROLL_TOLERANCE:
        return level

    return None


def _find_roll_candidates_librosa(
    onset_times: np.ndarray,
    bpm: float,
) -> List[dict]:
    if len(onset_times) < 4:
        return []

    beat_seconds = 60.0 / bpm
    intervals = np.diff(onset_times) / beat_seconds
    q = [
        _quantize_beat_interval_librosa(float(x))
        for x in intervals
    ]

    rolls = []

    # Search windows rather than one greedy chain. This handles EDM rolls
    # with repeated hits at each subdivision before the next acceleration.
    for start in range(len(q)):
        levels = []
        last_level = None
        end = start

        for j in range(start, min(len(q), start + 24)):
            level = q[j]

            if level is None:
                break

            if last_level is None:
                levels.append(level)
                last_level = level
                end = j
                continue

            # Same subdivision is okay; smaller interval means acceleration.
            if level <= last_level + 1e-12:
                if abs(level - last_level) > 1e-9:
                    levels.append(level)

                last_level = level
                end = j
            else:
                break

        unique_levels = []

        for level in levels:
            if not unique_levels or abs(level - unique_levels[-1]) > 1e-9:
                unique_levels.append(level)

        if len(unique_levels) >= DROP_ROLL_MIN_LEVELS:
            rolls.append(
                {
                    "start": float(onset_times[start]),
                    "end": float(onset_times[min(end + 1, len(onset_times) - 1)]),
                    "levels": unique_levels,
                }
            )

    # Deduplicate overlapping rolls: keep the one with more stages,
    # then the later ending one (closest to the actual drop).
    rolls.sort(key=lambda r: (r["start"], r["end"]))
    merged = []

    for roll in rolls:
        if not merged:
            merged.append(roll)
            continue

        prev = merged[-1]

        if roll["start"] <= prev["end"]:
            better = (
                len(roll["levels"]) > len(prev["levels"])
                or (
                    len(roll["levels"]) == len(prev["levels"])
                    and roll["end"] > prev["end"]
                )
            )

            if better:
                merged[-1] = roll
        else:
            merged.append(roll)

    return merged


def analyze_drops_librosa(
    kick: np.ndarray,
    bass: np.ndarray,
    instruments: np.ndarray,
    bpm: float,
) -> dict:
    # 0.12.0 - KICK WAVEFORM ON/OFF
    # Kick stays the primary driver, but bass/instruments can promote
    # a restart to DROP when the global section opens up audibly.
    beat_seconds = 60.0 / max(bpm, 1.0)
    bar_seconds = 4.0 * beat_seconds

    if len(kick) <= 0:
        return {
            "engine": "librosa MIR kick-on-off",
            "kickOnsets": 0,
            "intro": None,
            "outro": None,
            "rhythmicZones": [],
            "breaks": [],
            "buildUps": [],
            "drops": [],
        }

    hop = 512
    n_fft = 2048
    track_end = len(kick) / SR

    # --------------------------------------------------------------
    # KICK waveform / RMS envelope
    # --------------------------------------------------------------
    kick_rms = librosa.feature.rms(
        y=kick,
        frame_length=n_fft,
        hop_length=hop,
    )[0]

    kick_db = librosa.amplitude_to_db(
        np.maximum(kick_rms, 1e-8),
        ref=1.0,
        top_db=None,
    )

    frame_times = librosa.frames_to_time(
        np.arange(len(kick_db)),
        sr=SR,
        hop_length=hop,
    )

    # Smooth envelope so we classify sections, not individual hits.
    smooth_frames = max(
        3,
        int(round(
            (0.50 / (hop / SR))
        ))
    )
    kernel = np.ones(
        smooth_frames,
        dtype=np.float64,
    ) / smooth_frames

    kick_db_smooth = np.convolve(
        kick_db,
        kernel,
        mode="same",
    )

    # Robust adaptive threshold:
    # high percentile represents actual kick sections,
    # low percentile represents absent/bleed zones.
    hi = float(np.percentile(kick_db_smooth, 80))
    lo = float(np.percentile(kick_db_smooth, 20))

    # Put threshold closer to the low side so real kick remains ON
    # but stem bleed stays OFF.
    on_threshold = lo + 0.42 * (hi - lo)

    active_raw = (
        kick_db_smooth >= on_threshold
    ).astype(np.int32)

    # --------------------------------------------------------------
    # Clean ON/OFF mask at waveform level.
    # Fill short OFF holes and remove short ON spikes.
    # --------------------------------------------------------------
    def _runs(mask):
        runs = []
        if len(mask) == 0:
            return runs

        state = int(mask[0])
        start_i = 0

        for i in range(1, len(mask)):
            s = int(mask[i])
            if s != state:
                runs.append(
                    (state, start_i, i - 1)
                )
                state = s
                start_i = i

        runs.append(
            (state, start_i, len(mask) - 1)
        )
        return runs

    frame_seconds = hop / SR

    # 1) Fill OFF gaps shorter than 0.75 beat inside ON regions.
    mask = active_raw.copy()
    for state, a, b in _runs(mask):
        if state != 0:
            continue
        dur = (b - a + 1) * frame_seconds
        if dur <= 0.75 * beat_seconds:
            left_on = a > 0 and mask[a - 1] == 1
            right_on = (
                b + 1 < len(mask)
                and mask[b + 1] == 1
            )
            if left_on and right_on:
                mask[a:b + 1] = 1

    # 2) Remove ON spikes shorter than 0.75 beat inside OFF regions.
    for state, a, b in _runs(mask):
        if state != 1:
            continue
        dur = (b - a + 1) * frame_seconds
        if dur <= 0.75 * beat_seconds:
            left_off = a > 0 and mask[a - 1] == 0
            right_off = (
                b + 1 < len(mask)
                and mask[b + 1] == 0
            )
            if left_off and right_off:
                mask[a:b + 1] = 0

    # --------------------------------------------------------------
    # KICK onset timing, used only inside OFF tail for BUILD UP.
    # --------------------------------------------------------------
    kick_env = librosa.onset.onset_strength(
        y=kick,
        sr=SR,
        hop_length=hop,
        aggregate=np.median,
    )
    kick_onsets = librosa.onset.onset_detect(
        onset_envelope=kick_env,
        sr=SR,
        hop_length=hop,
        units="time",
        backtrack=False,
    )
    kick_onsets = np.asarray(
        kick_onsets,
        dtype=np.float64,
    )

    global_signal = None
    if len(bass) > 0 or len(instruments) > 0:
        max_len = max(len(bass), len(instruments))
        bass_mix = np.zeros(max_len, dtype=np.float32)
        inst_mix = np.zeros(max_len, dtype=np.float32)
        if len(bass) > 0:
            bass_mix[:len(bass)] = bass[:max_len]
        if len(instruments) > 0:
            inst_mix[:len(instruments)] = instruments[:max_len]
        global_signal = bass_mix * 0.65 + inst_mix * 0.35

    def _density(t0, t1):
        if t1 <= t0:
            return 0.0
        return float(
            np.sum(
                (kick_onsets >= t0)
                & (kick_onsets < t1)
            )
            / max(t1 - t0, 1e-6)
        )

    def _median_interval(t0, t1):
        x = kick_onsets[
            (kick_onsets >= t0)
            & (kick_onsets < t1)
        ]
        if len(x) < 2:
            return None
        return float(np.median(np.diff(x)))

    def _segment_rms(signal: np.ndarray, t0: float, t1: float):
        if signal is None or len(signal) == 0 or t1 <= t0:
            return None
        start = max(0, int(round(t0 * SR)))
        end = min(len(signal), int(round(t1 * SR)))
        if end - start < int(0.50 * SR):
            return None
        segment = signal[start:end].astype(np.float64)
        return float(np.sqrt(np.mean(np.square(segment)) + 1e-12))

    # --------------------------------------------------------------
    # Convert cleaned mask into large waveform zones.
    # Minimum meaningful section = 1 beat.
    # --------------------------------------------------------------
    raw_zones = []

    for state, a, b in _runs(mask):
        t0 = float(frame_times[a])
        t1 = float(
            min(
                track_end,
                frame_times[b] + frame_seconds,
            )
        )

        if t1 - t0 < 1.0 * beat_seconds:
            continue

        raw_zones.append(
            {
                "state": "ON" if state == 1 else "OFF",
                "time": t0,
                "end": t1,
                "duration": t1 - t0,
            }
        )

    # Merge same-state neighbours that survived filtering.
    zones = []
    for z in raw_zones:
        if (
            zones
            and zones[-1]["state"] == z["state"]
            and z["time"] - zones[-1]["end"]
            <= 0.50 * beat_seconds
        ):
            zones[-1]["end"] = z["end"]
            zones[-1]["duration"] = (
                zones[-1]["end"]
                - zones[-1]["time"]
            )
        else:
            zones.append(dict(z))

    # EDM/commercial grooves often contain tiny kick holes inside an
    # otherwise continuous dropped section. If two ON zones are split
    # only by a very short OFF pocket, treat them as one structural ON.
    merged_zones = []
    index = 0
    max_internal_off = 2.0 * beat_seconds
    min_on_for_bridge = 4.0 * beat_seconds
    while index < len(zones):
        current = dict(zones[index])
        if (
            current["state"] == "ON"
            and index + 2 < len(zones)
            and zones[index + 1]["state"] == "OFF"
            and zones[index + 1]["duration"] <= max_internal_off
            and zones[index + 2]["state"] == "ON"
            and current["duration"] >= min_on_for_bridge
            and zones[index + 2]["duration"] >= min_on_for_bridge
        ):
            current["end"] = zones[index + 2]["end"]
            current["duration"] = current["end"] - current["time"]
            merged_zones.append(current)
            index += 3
            continue
        merged_zones.append(current)
        index += 1
    zones = merged_zones

    # --------------------------------------------------------------
    # MAIN RHYTHMIC ZONES
    #
    # A short ON burst inside an otherwise OFF region may be a
    # BUILD UP, not a new main rhythmic section.
    #
    # Main ON zone must be sustained for >= 8 beats (2 bars).
    # Shorter ON islands remain available as BUILD UP material.
    # --------------------------------------------------------------
    main_on_min = 8.0 * beat_seconds

    rhythmic_zones = []

    for z in zones:
        if z["state"] != "ON":
            continue

        if z["duration"] < main_on_min:
            continue

        rhythmic_zones.append(
            {
                "time": float(z["time"]),
                "end": float(z["end"]),
                "duration": float(z["duration"]),
                "beats": float(
                    z["duration"] / beat_seconds
                ),
                "bars": float(
                    z["duration"] / bar_seconds
                ),
            }
        )

    # --------------------------------------------------------------
    # INTRO / OUTRO
    # Based only on first/last MAIN rhythmic zone.
    # No cues.
    # --------------------------------------------------------------
    if rhythmic_zones:
        intro_end = rhythmic_zones[0]["time"]
        outro_start = rhythmic_zones[-1]["end"]
    else:
        intro_end = 0.0
        outro_start = track_end

    if intro_end <= 1.0 * bar_seconds:
        intro_end = 0.0

    if (
        track_end - outro_start
        <= 1.0 * bar_seconds
    ):
        outro_start = track_end

    intro = {
        "time": 0.0,
        "end": float(intro_end),
        "barsApprox": float(
            intro_end / bar_seconds
        ),
    }

    outro = {
        "time": float(outro_start),
        "end": float(track_end),
        "barsApprox": float(
            (track_end - outro_start)
            / bar_seconds
        ),
    }

    # --------------------------------------------------------------
    # MACRO BREAKS
    #
    # Defined by gaps between MAIN rhythmic zones.
    # Any short ON island inside the gap does NOT split the BREAK.
    # It is later inspected as possible BUILD UP.
    # --------------------------------------------------------------
    breaks = []
    drops = []
    build_ups = []

    def _build_up_before_drop(window_start, drop_time, allow_short_gap=False):
        best_bu = None
        for bu_beats in (4, 8, 16):
            bu_dur = bu_beats * beat_seconds
            bu_start = max(window_start, drop_time - bu_dur)

            if (drop_time - bu_start) < 3.0 * beat_seconds:
                continue

            mid = (bu_start + drop_time) / 2.0
            den_a = _density(bu_start, mid)
            den_b = _density(mid, drop_time)
            int_a = _median_interval(bu_start, mid)
            int_b = _median_interval(mid, drop_time)

            density_rise = den_b >= max(den_a * 1.25, den_a + 0.30)
            interval_accel = (
                int_a is not None
                and int_b is not None
                and int_b <= int_a * 0.82
            )

            tail_events = int(
                np.sum(
                    (kick_onsets >= bu_start)
                    & (kick_onsets < drop_time)
                )
            )
            if tail_events < 3:
                continue
            if not (density_rise or interval_accel):
                continue

            score = 0.0
            score += max(0.0, den_b - den_a) * 2.0
            if int_a is not None and int_b is not None:
                score += max(
                    0.0,
                    1.0 - int_b / max(int_a, 1e-6),
                ) * 4.0

            last_tail_onset = kick_onsets[
                (kick_onsets >= bu_start)
                & (kick_onsets < drop_time)
            ]
            if len(last_tail_onset):
                end_gap = drop_time - float(last_tail_onset[-1])
                score += max(
                    0.0,
                    2.0 - end_gap / max(beat_seconds, 1e-6),
                )

            if allow_short_gap:
                score += 0.75

            candidate = {
                "time": float(bu_start),
                "end": float(drop_time),
                "duration": float(drop_time - bu_start),
                "beats": float((drop_time - bu_start) / beat_seconds),
                "source": (
                    "kick-pre-drop"
                    if not allow_short_gap
                    else "kick-pre-drop-short-gap"
                ),
                "densityStart": float(den_a),
                "densityEnd": float(den_b),
                "intervalStart": int_a,
                "intervalEnd": int_b,
                "score": float(score),
            }

            if best_bu is None or candidate["score"] > best_bu["score"]:
                best_bu = candidate

        return best_bu

    def _drop_restart_strength(window_start, drop_time, zone_end):
        pre_start = max(window_start, drop_time - 4.0 * beat_seconds)
        post_end = min(zone_end, drop_time + 4.0 * beat_seconds)
        if post_end - drop_time < 1.5 * beat_seconds:
            return None

        pre_density = _density(pre_start, drop_time)
        post_density = _density(drop_time, post_end)
        pre_interval = _median_interval(pre_start, drop_time)
        post_interval = _median_interval(drop_time, post_end)
        post_events = int(
            np.sum(
                (kick_onsets >= drop_time)
                & (kick_onsets < post_end)
            )
        )

        density_gain = post_density - pre_density
        density_ratio = (
            post_density / max(pre_density, 1e-6)
            if pre_density > 0
            else (2.0 if post_density > 0 else 1.0)
        )
        interval_gain = (
            pre_interval is not None
            and post_interval is not None
            and post_interval <= pre_interval * 0.92
        )
        valid = (
            post_events >= 3
            and (
                density_gain >= 0.35
                or density_ratio >= 1.18
                or interval_gain
            )
        )
        drop_like = (
            post_events >= 4
            and (
                density_gain >= 0.60
                or density_ratio >= 1.35
                or interval_gain
            )
        )

        return {
            "valid": bool(valid),
            "dropLike": bool(drop_like),
            "preDensity": float(pre_density),
            "postDensity": float(post_density),
            "densityGain": float(density_gain),
            "densityRatio": float(density_ratio),
            "preInterval": pre_interval,
            "postInterval": post_interval,
            "postEvents": post_events,
        }

    def _groove_jump_strength(prev_zone_start, prev_zone_end, drop_time, zone_end):
        pre_start = max(float(prev_zone_start), float(prev_zone_end) - 4.0 * beat_seconds)
        pre_end = float(prev_zone_end)
        post_start = float(drop_time)
        post_end = min(float(zone_end), post_start + 4.0 * beat_seconds)
        if pre_end - pre_start < 1.5 * beat_seconds or post_end - post_start < 1.5 * beat_seconds:
            return None

        pre_density = _density(pre_start, pre_end)
        post_density = _density(post_start, post_end)
        pre_interval = _median_interval(pre_start, pre_end)
        post_interval = _median_interval(post_start, post_end)

        density_gain = post_density - pre_density
        density_ratio = (
            post_density / max(pre_density, 1e-6)
            if pre_density > 0
            else (2.0 if post_density > 0 else 1.0)
        )
        interval_gain = (
            pre_interval is not None
            and post_interval is not None
            and post_interval <= pre_interval * 0.90
        )

        return {
            "dropLike": bool(
                density_gain >= 0.45
                or density_ratio >= 1.22
                or interval_gain
            ),
            "preDensity": float(pre_density),
            "postDensity": float(post_density),
            "densityGain": float(density_gain),
            "densityRatio": float(density_ratio),
            "preInterval": pre_interval,
            "postInterval": post_interval,
        }

    def _global_restart_energy(window_start, drop_time, zone_end):
        if global_signal is None:
            return None
        pre_start = max(float(window_start), float(drop_time) - 4.0 * beat_seconds)
        pre_end = float(drop_time)
        post_start = float(drop_time)
        post_end = min(float(zone_end), float(drop_time) + 4.0 * beat_seconds)
        pre_rms = _segment_rms(global_signal, pre_start, pre_end)
        post_rms = _segment_rms(global_signal, post_start, post_end)
        if pre_rms is None or post_rms is None:
            return None
        gain = post_rms - pre_rms
        ratio = post_rms / max(pre_rms, 1e-9)
        return {
            "dropLike": bool(gain >= 0.012 or ratio >= 1.10),
            "preRms": float(pre_rms),
            "postRms": float(post_rms),
            "gain": float(gain),
            "ratio": float(ratio),
        }

    def _try_gap_candidate(prev_main, gap_start, gap_end, next_main, allow_short_gap=False):
        gap_dur = gap_end - gap_start
        min_structural_break = 4.0 * bar_seconds
        min_short_gap = 4.0 * beat_seconds

        if allow_short_gap:
            if gap_dur < min_short_gap or gap_dur >= min_structural_break:
                return None
        else:
            if gap_dur < min_structural_break:
                return None

        if gap_start < intro_end:
            return None
        if gap_end > outro_start:
            return None

        internal_on = []
        for z in zones:
            if z["state"] != "ON":
                continue
            overlap_start = max(gap_start, z["time"])
            overlap_end = min(gap_end, z["end"])
            if overlap_end > overlap_start:
                internal_on.append(
                    {
                        "time": float(overlap_start),
                        "end": float(overlap_end),
                        "duration": float(overlap_end - overlap_start),
                    }
                )

        internal_on_time = sum(x["duration"] for x in internal_on)
        on_fraction = (
            internal_on_time / gap_dur
            if gap_dur > 0
            else 0.0
        )

        max_on_fraction = 0.40 if not allow_short_gap else 0.55
        if on_fraction > max_on_fraction:
            return None

        drop_time = gap_end
        drop_strength = _drop_restart_strength(gap_start, drop_time, float(next_main["end"]))
        if drop_strength is None:
            return None
        groove_jump = _groove_jump_strength(
            float(prev_main["time"]),
            float(prev_main["end"]),
            drop_time,
            float(next_main["end"]),
        )
        global_jump = _global_restart_energy(gap_start, drop_time, float(next_main["end"]))
        if groove_jump is not None and not groove_jump["dropLike"]:
            drop_strength["dropLike"] = False
            drop_strength["grooveJump"] = groove_jump
        if global_jump is not None and global_jump["dropLike"]:
            drop_strength["dropLike"] = True
            drop_strength["globalJump"] = global_jump

        drop = {
            "time": float(drop_time),
            "end": float(next_main["end"]),
            "duration": float(next_main["duration"]),
            "source": (
                "main-kick-zone-restart"
                if not allow_short_gap
                else "short-gap-kick-restart"
            ),
            "restart": drop_strength,
        }

        if allow_short_gap and not drop_strength["valid"]:
            return None

        best_bu = _build_up_before_drop(gap_start, drop_time, allow_short_gap)

        if allow_short_gap and best_bu is None:
            return None

        # A real EDM drop can restart at a groove level similar to the
        # previous section, especially on second/third drops. In that case
        # the groove-jump filter alone is too strict: if we have a strong
        # build-up and a meaningful break, promote it back to DROP.
        if (
            best_bu is not None
            and float(best_bu.get("score", 0.0)) >= 3.0
            and float(gap_dur / beat_seconds) >= 12.0
        ):
            drop_strength["dropLike"] = True

        brk = {
            "time": gap_start,
            "end": gap_end,
            "duration": gap_dur,
            "beats": float(gap_dur / beat_seconds),
            "bars": float(gap_dur / bar_seconds),
            "internalKickTime": float(internal_on_time),
            "internalKickFraction": float(on_fraction),
            "source": (
                "macro-gap"
                if not allow_short_gap
                else "short-gap"
            ),
        }

        return {
            "break": brk,
            "drop": drop,
            "build_up": best_bu,
        }

    def _drop_first_candidates():
        results = []
        for zi in range(1, len(rhythmic_zones)):
            prev_main = rhythmic_zones[zi - 1]
            next_main = rhythmic_zones[zi]
            drop_time = float(next_main["time"])

            if drop_time < intro_end or drop_time > outro_start:
                continue

            # Commercial EDM often builds tension INSIDE the previous
            # main rhythmic block, not only in the OFF gap before the restart.
            window_start = max(float(prev_main["time"]), drop_time - 16.0 * beat_seconds)
            best_bu = _build_up_before_drop(window_start, drop_time, allow_short_gap=True)
            if best_bu is None:
                continue

            gap_start = float(prev_main["end"])
            gap_end = drop_time
            gap_dur = max(0.0, gap_end - gap_start)
            restart = _drop_restart_strength(window_start, drop_time, float(next_main["end"]))
            if restart is None:
                continue
            groove_jump = _groove_jump_strength(
                float(prev_main["time"]),
                float(prev_main["end"]),
                drop_time,
                float(next_main["end"]),
            )
            global_jump = _global_restart_energy(window_start, drop_time, float(next_main["end"]))
            if groove_jump is not None and not groove_jump["dropLike"]:
                restart["dropLike"] = False
                restart["grooveJump"] = groove_jump
            if global_jump is not None and global_jump["dropLike"]:
                restart["dropLike"] = True
                restart["globalJump"] = global_jump

            brk = None
            if gap_dur >= 2.0 * beat_seconds:
                brk = {
                    "time": gap_start,
                    "end": gap_end,
                    "duration": gap_dur,
                    "beats": float(gap_dur / beat_seconds),
                    "bars": float(gap_dur / bar_seconds),
                    "internalKickTime": 0.0,
                    "internalKickFraction": 0.0,
                    "source": "drop-first-gap",
                }

            results.append(
                {
                    "break": brk,
                    "drop": {
                        "time": drop_time,
                        "end": float(next_main["end"]),
                        "duration": float(next_main["duration"]),
                        "source": "drop-first-main-restart",
                        "restart": restart,
                    },
                    "build_up": best_bu,
                }
            )
        return results

    def _commercial_restart_candidates():
        results = []
        for zi, zone in enumerate(rhythmic_zones):
            drop_time = float(zone["time"])

            if drop_time < intro_end or drop_time > outro_start:
                continue

            if zi == 0:
                gap_beats = float(intro_end / beat_seconds) if intro_end > 0 else 0.0
                if intro_end < 8.0 * beat_seconds:
                    continue
                window_start = max(0.0, drop_time - 16.0 * beat_seconds)
                is_micro_restart = False
                break_info = {
                    "time": 0.0,
                    "end": float(drop_time),
                    "duration": float(drop_time),
                    "beats": gap_beats,
                    "bars": float(drop_time / bar_seconds),
                    "internalKickTime": 0.0,
                    "internalKickFraction": 0.0,
                    "source": "intro-restart",
                }
            else:
                prev_zone = rhythmic_zones[zi - 1]
                gap_start = float(prev_zone["end"])
                gap_end = drop_time
                gap_dur = max(0.0, gap_end - gap_start)
                gap_beats = gap_dur / beat_seconds
                if gap_beats < 0.75:
                    continue
                window_start = max(float(prev_zone["time"]), drop_time - 16.0 * beat_seconds)
                is_micro_restart = gap_beats <= 3.0
                break_info = {
                    "time": gap_start,
                    "end": gap_end,
                    "duration": gap_dur,
                    "beats": float(gap_beats),
                    "bars": float(gap_dur / bar_seconds),
                    "internalKickTime": 0.0,
                    "internalKickFraction": 0.0,
                    "source": "commercial-gap",
                }

            best_bu = _build_up_before_drop(window_start, drop_time, allow_short_gap=True)

            if zi == 0:
                intro_bars = float(drop_time / bar_seconds) if bar_seconds > 0 else 0.0
                if (
                    intro_bars < 20.0
                    and (
                        best_bu is None
                        or float(best_bu.get("score", 0.0)) < 2.6
                    )
                ):
                    continue

            if best_bu is None and not is_micro_restart and zi != 0:
                continue

            if best_bu is None:
                best_bu = {
                    "time": float(max(window_start, drop_time - 8.0 * beat_seconds)),
                    "end": float(drop_time),
                    "duration": float(min(8.0 * beat_seconds, drop_time - window_start)),
                    "beats": float(min(8.0, max(0.0, (drop_time - window_start) / beat_seconds))),
                    "source": "commercial-pre-drop-window",
                    "densityStart": 0.0,
                    "densityEnd": 0.0,
                    "intervalStart": None,
                    "intervalEnd": None,
                    "score": 0.5,
                }

            drop_strength = _drop_restart_strength(window_start, drop_time, float(zone["end"]))
            if drop_strength is None or not drop_strength["valid"]:
                continue
            if zi != 0:
                groove_jump = _groove_jump_strength(
                    float(prev_zone["time"]),
                    float(prev_zone["end"]),
                    drop_time,
                    float(zone["end"]),
                )
                global_jump = _global_restart_energy(window_start, drop_time, float(zone["end"]))
                if groove_jump is not None and not groove_jump["dropLike"]:
                    drop_strength["dropLike"] = False
                    drop_strength["grooveJump"] = groove_jump
                if global_jump is not None and global_jump["dropLike"]:
                    drop_strength["dropLike"] = True
                    drop_strength["globalJump"] = global_jump

            if (
                best_bu is not None
                and float(best_bu.get("score", 0.0)) >= 3.0
                and float(gap_beats) >= 12.0
            ):
                drop_strength["dropLike"] = True

            results.append(
                {
                    "break": break_info,
                    "drop": {
                        "time": float(drop_time),
                        "end": float(zone["end"]),
                        "duration": float(zone["duration"]),
                        "source": "commercial-main-restart",
                        "restart": drop_strength,
                    },
                    "build_up": best_bu,
                }
            )
        return results

    def _intro_restart_candidate():
        if not rhythmic_zones:
            return None
        first_zone = rhythmic_zones[0]
        drop_time = float(first_zone["time"])
        intro_bars = drop_time / bar_seconds if bar_seconds > 0 else 0.0
        if drop_time < 8.0 * beat_seconds:
            return None
        window_start = max(0.0, drop_time - 16.0 * beat_seconds)
        best_bu = _build_up_before_drop(window_start, drop_time, allow_short_gap=True)
        drop_strength = _drop_restart_strength(window_start, drop_time, float(first_zone["end"]))

        # First groove entry after a short/standard intro is often just
        # the start of the track, not a real DROP. Keep the old behavior
        # unless the pre-drop build-up is clearly strong.
        if (
            intro_bars < 20.0
            and (
                best_bu is None
                or float(best_bu.get("score", 0.0)) < 2.6
            )
        ):
            return None

        if (
            best_bu is None
            or drop_strength is None
            or not drop_strength["valid"]
            or float(best_bu.get("score", 0.0)) < 2.5
        ):
            groove_time = float(drop_time)
            if (
                intro_bars < 12.0
                and float(first_zone["duration"]) >= 12.0 * beat_seconds
            ):
                delayed_time = float(drop_time + 8.0 * bar_seconds)
                if delayed_time < float(first_zone["end"]) - 4.0 * beat_seconds:
                    groove_time = delayed_time
            return {
                "break": None,
                "drop": None,
                "build_up": None,
                "groove": {
                    "time": groove_time,
                    "end": float(first_zone["end"]),
                    "duration": float(first_zone["end"] - groove_time),
                    "source": "intro-main-groove-start",
                },
            }

        if (
            intro_bars >= 8.0
            and best_bu is not None
            and float(best_bu.get("score", 0.0)) >= 2.6
        ):
            drop_strength["dropLike"] = True

        return {
            "break": None,
            "drop": {
                "time": float(drop_time),
                "end": float(first_zone["end"]),
                "duration": float(first_zone["duration"]),
                "source": "intro-main-restart",
                "restart": drop_strength,
            },
            "build_up": best_bu,
            "groove": None,
        }

    for zi in range(len(rhythmic_zones) - 1):
        prev_main = rhythmic_zones[zi]
        next_main = rhythmic_zones[zi + 1]

        gap_start = float(prev_main["end"])
        gap_end = float(next_main["time"])
        result = _try_gap_candidate(prev_main, gap_start, gap_end, next_main, allow_short_gap=False)
        if result is None:
            continue

        breaks.append(result["break"])
        drops.append(result["drop"])
        if result["build_up"] is not None:
            build_ups.append(result["build_up"])

    if not breaks and not drops:
        for zi in range(len(rhythmic_zones) - 1):
            prev_main = rhythmic_zones[zi]
            next_main = rhythmic_zones[zi + 1]
            gap_start = float(prev_main["end"])
            gap_end = float(next_main["time"])
            result = _try_gap_candidate(prev_main, gap_start, gap_end, next_main, allow_short_gap=True)
            if result is None:
                continue
            breaks.append(result["break"])
            drops.append(result["drop"])
            if result["build_up"] is not None:
                build_ups.append(result["build_up"])

    if not drops:
        for result in _drop_first_candidates():
            if not result["drop"].get("restart", {}).get("valid", False):
                continue
            if result["break"] is not None:
                breaks.append(result["break"])
            drops.append(result["drop"])
            if result["build_up"] is not None:
                build_ups.append(result["build_up"])

    intro_candidate = _intro_restart_candidate()
    if intro_candidate is not None:
        if intro_candidate.get("drop") is not None:
            drops.append(intro_candidate["drop"])
        if intro_candidate.get("build_up") is not None:
            build_ups.append(intro_candidate["build_up"])
        if intro_candidate.get("groove") is not None:
            drops.append(
                {
                    "time": float(intro_candidate["groove"]["time"]),
                    "end": float(intro_candidate["groove"]["end"]),
                    "duration": float(intro_candidate["groove"]["duration"]),
                    "source": "groove-start",
                    "type": "groove",
                }
            )

    if not drops:
        for result in _commercial_restart_candidates():
            if result["break"] is not None:
                breaks.append(result["break"])
            drops.append(result["drop"])
            if result["build_up"] is not None:
                build_ups.append(result["build_up"])

    def _dedupe_events(events, key='time', min_distance_beats=4.0):
        if not events:
            return []
        min_distance = min_distance_beats * beat_seconds
        ordered = sorted(events, key=lambda item: float(item.get(key, 0.0)))
        kept = []
        for event in ordered:
            current_time = float(event.get(key, 0.0))
            if not kept:
                kept.append(event)
                continue
            previous_time = float(kept[-1].get(key, 0.0))
            if current_time - previous_time < min_distance:
                prev_score = float(kept[-1].get('score', kept[-1].get('duration', 0.0)) or 0.0)
                curr_score = float(event.get('score', event.get('duration', 0.0)) or 0.0)
                if curr_score > prev_score:
                    kept[-1] = event
                continue
            kept.append(event)
        return kept

    breaks = _dedupe_events(breaks)
    build_ups = _dedupe_events(build_ups)
    drops = _dedupe_events(drops)

    return {
        "engine": "librosa MIR kick-on-off",
        "kickOnsets": int(len(kick_onsets)),
        "thresholdDb": float(on_threshold),
        "intro": intro,
        "outro": outro,
        "zones": zones,
        "rhythmicZones": rhythmic_zones,
        "breaks": breaks,
        "buildUps": build_ups,
        "drops": drops,
    }

def analyze_mir_stems(
    stem_path: Path,
    bpm: float,
) -> dict:
    kick_stream = probe_kick_stream(stem_path)

    log(
        "MIR KICK ON/OFF: "
        f"kick={kick_stream}"
    )

    if kick_stream is None:
        raise RuntimeError(
            "Stream KICK non trovato"
        )

    kick = decode_stream(
        stem_path,
        kick_stream,
    )
    bass_stream = probe_bass_stream(stem_path)
    instruments_stream = probe_instruments_stream(stem_path)
    bass = (
        decode_stream(stem_path, bass_stream)
        if bass_stream is not None
        else np.zeros(0, dtype=np.float32)
    )
    instruments = (
        decode_stream(stem_path, instruments_stream)
        if instruments_stream is not None
        else np.zeros(0, dtype=np.float32)
    )

    result = analyze_drops_librosa(
        kick,
        bass,
        instruments,
        bpm,
    )

    intro = result.get("intro") or {}
    outro = result.get("outro") or {}

    log(
        "KICK THRESHOLD: "
        f"{result.get('thresholdDb', 0.0):.1f} dB"
    )

    log(
        "STRUTTURA KICK: "
        f"INTRO=0.000->{intro.get('end', 0.0):.3f}s | "
        f"OUTRO={outro.get('time', 0.0):.3f}->"
        f"{outro.get('end', 0.0):.3f}s"
    )

    for i, z in enumerate(
        result.get("zones", []),
        start=1,
    ):
        log(
            f"ZONE {i}: "
            f"{z['state']} | "
            f"{z['time']:.3f}s -> "
            f"{z['end']:.3f}s | "
            f"{z['duration']:.2f}s"
        )

    for i, brk in enumerate(
        result.get("breaks", []),
        start=1,
    ):
        log(
            f"BREAK {i}: "
            f"{brk['time']:.3f}s -> "
            f"{brk['end']:.3f}s | "
            f"{brk['beats']:.1f} beat | "
            f"internalKick={brk.get('internalKickFraction', 0.0) * 100:.0f}%"
        )

    for i, bu in enumerate(
        result.get("buildUps", []),
        start=1,
    ):
        ia = bu.get("intervalStart")
        ib = bu.get("intervalEnd")
        ia_txt = "?" if ia is None else f"{ia:.3f}"
        ib_txt = "?" if ib is None else f"{ib:.3f}"

        log(
            f"BUILD UP {i}: "
            f"{bu['time']:.3f}s -> "
            f"{bu['end']:.3f}s | "
            f"kickDen={bu.get('densityStart', 0.0):.2f}->"
            f"{bu.get('densityEnd', 0.0):.2f} | "
            f"kickInt={ia_txt}->{ib_txt}"
        )

    for i, dr in enumerate(
        result.get("drops", []),
        start=1,
    ):
        log(
            f"DROP {i}: "
            f"{dr['time']:.3f}s | "
            f"kick zone restart"
        )

    log(
        "MIR KICK ON/OFF: "
        f"kickOnsets={result.get('kickOnsets', 0)} | "
        f"mainRhythmZones={len(result.get('rhythmicZones', []))} | "
        f"breaks={len(result.get('breaks', []))} | "
        f"buildUps={len(result.get('buildUps', []))} | "
        f"drops={len(result.get('drops', []))}"
    )

    return result


def load_cache() -> dict:
    try:
        with CACHE_PATH.open("r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_cache(cache: dict) -> None:
    try:
        with CACHE_PATH.open("w", encoding="utf-8") as f:
            json.dump(cache, f, ensure_ascii=False, indent=2)
    except Exception as exc:
        log(f"Impossibile salvare cache: {exc}")


CACHE = load_cache()

def analyze_or_cache(stem: Path, bpm: float) -> dict:
    log(f"Analisi MIR stem: {stem}")

    try:
        stat = stem.stat()
        marker = f"{stat.st_mtime_ns}:{stat.st_size}"
    except Exception:
        marker = "0:0"

    key = (
        f"track-info-only-v3.4-force-refresh|{str(stem).lower()}|"
        f"{bpm:.6f}|{marker}"
    )

    if key in CACHE:
        log("Cache MIR: BYPASS")
    else:
        log("Cache MIR: MISS")

    try:
        mir_analysis = analyze_mir_stems(stem, bpm)
    except Exception as exc:
        log(f"ERRORE ANALISI MIR: {exc}")
        raise

    cue_events = []

    outro = mir_analysis.get("outro") or {}
    outro_start = float(outro.get("time", 0.0) or 0.0)
    outro_end = float(outro.get("end", 0.0) or 0.0)
    if outro_start > 0.0 and outro_end > outro_start:
        cue_events.append(
            {
                "time": outro_start,
                "type": "outro",
                "name": "OUTRO",
                "color": "purple",
                "score": float(outro.get("barsApprox", 0.0) or 0.0),
            }
        )

    for break_index, brk in enumerate(
        mir_analysis.get("breaks", []),
        start=1,
    ):
        if str(brk.get("source", "")) == "intro-restart":
            continue
        cue_events.append(
            {
                "time": float(brk["time"]),
                "type": "break",
                "name": f"BREAK {break_index}",
                "color": "blue",
                "score": float(brk.get("duration", 0.0)),
            }
        )

    for build_index, bu in enumerate(
        mir_analysis.get("buildUps", []),
        start=1,
    ):
        cue_events.append(
            {
                "time": float(bu["time"]),
                "type": "build_up",
                "name": f"BUILD UP {build_index}",
                "color": "orange",
                "score": float(bu.get("score", 0.0)),
            }
        )

    drop_index = 0
    groove_index = 0
    intro_end = float((mir_analysis.get("intro") or {}).get("end", 0.0) or 0.0)
    beat_seconds = 60.0 / max(bpm, 1.0)
    for dr in mir_analysis.get("drops", []):
        restart = dr.get("restart", {}) if isinstance(dr, dict) else {}
        is_groove = (
            str(dr.get("type", "")) == "groove"
            or (isinstance(restart, dict) and restart and not restart.get("dropLike", False))
        )
        if is_groove:
            if (
                intro_end > 0.0
                and abs(float(dr["time"]) - intro_end) <= 1.0 * beat_seconds
            ):
                continue
            groove_index += 1
            cue_events.append(
                {
                    "time": float(dr["time"]),
                    "type": "groove",
                    "name": "GROOVE START" if groove_index == 1 else f"GROOVE START {groove_index}",
                    "color": "cyan",
                    "score": float(dr.get("duration", 0.0)),
                }
            )
            continue

        drop_index += 1
        cue_events.append(
            {
                "time": float(dr["time"]),
                "type": "drop",
                "name": f"DROP {drop_index}",
                "color": "yellow",
                "score": float(dr.get("duration", 0.0)),
            }
        )

    first_zone = (mir_analysis.get("rhythmicZones") or [None])[0]
    has_early_cue = any(
        float(event.get("time", 0.0)) <= intro_end + 10.0 * beat_seconds
        for event in cue_events
        if str(event.get("type", "")) in {"groove", "drop", "build_up"}
    )
    if (
        first_zone
        and intro_end > 0.0
        and not has_early_cue
    ):
        first_zone_end = float(first_zone.get("end", 0.0) or 0.0)
        first_zone_duration = float(first_zone.get("duration", 0.0) or 0.0)
        groove_time = intro_end + 8.0 * beat_seconds * 4.0
        if (
            first_zone_duration >= 24.0 * beat_seconds
            and groove_time < first_zone_end - 4.0 * beat_seconds
        ):
            cue_events.append(
                {
                    "time": float(intro_end),
                    "type": "build_up",
                    "name": "BUILD UP 1",
                    "color": "orange",
                    "score": 2.6,
                }
            )
            cue_events.append(
                {
                    "time": float(groove_time),
                    "type": "groove",
                    "name": "GROOVE START",
                    "color": "cyan",
                    "score": float(first_zone_end - groove_time),
                }
            )

    cue_events.sort(key=lambda x: x["time"])

    log(
        f"Cue KICK BREAK+BUILD UP+DROP: {len(cue_events)} | "
        f"max={MAX_CUES}"
    )

    for idx, event in enumerate(cue_events[:MAX_CUES], start=1):
        log(
            f"CUE PLAN {idx}: {event['time']:.3f}s | "
            f"{event['name']} | {event['type']}"
        )

    result = {
        "stem": str(stem),
        "bpm": bpm,
        "mirAnalysis": mir_analysis,
        "boundaries": cue_events,
    }

    CACHE[key] = result
    save_cache(CACHE)

    return result


def analyze_urban_or_cache(stem: Path, bpm: float, genre: str = "") -> dict:
    log(f"Analisi NOVOX stem: {stem}")

    try:
        stat = stem.stat()
        marker = f"{stat.st_mtime_ns}:{stat.st_size}"
    except Exception:
        marker = "0:0"

    key = (
        f"novox-nokick-build-v6.0|{normalize_genre(genre)}|{str(stem).lower()}|"
        f"{bpm:.6f}|{marker}"
    )

    if key in CACHE:
        log("Cache NOVOX: BYPASS")
    else:
        log("Cache NOVOX: MISS")
    analysis = analyze_urban_non_vocal_sections(stem, bpm, genre)
    no_kick_analysis = analyze_no_kick_sections(stem, bpm)
    build_drop_pairs = analyze_build_drop_sections(
        stem,
        bpm,
        no_kick_analysis.get("sections", []),
    )

    cue_events = []
    section_label = str(analysis.get("sectionLabel") or "NOVOX")
    section_type = str(analysis.get("sectionType") or "novox")
    for idx, section in enumerate(analysis.get("sections", []), start=1):
        cue_events.append(
            {
                "time": float(section["time"]),
                "type": section_type,
                "name": f"{section_label} {idx}",
                "color": "green",
                "score": float(section.get("duration", 0.0)),
                "end": float(section.get("end", section["time"])),
            }
        )

    for idx, section in enumerate(no_kick_analysis.get("sections", []), start=1):
        cue_events.append(
            {
                "time": float(section["time"]),
                "type": "nokick",
                "name": f"NOKICK {idx}",
                "color": "cyan",
                "score": float(section.get("duration", 0.0)),
                "end": float(section.get("end", section["time"])),
            }
        )

    for idx, pair in enumerate(build_drop_pairs, start=1):
        cue_events.append(
            {
                "time": float(pair["build"]),
                "type": "build",
                "name": f"BUILD {idx}",
                "color": "yellow",
                "score": 1.0,
            }
        )
        cue_events.append(
            {
                "time": float(pair["drop"]),
                "type": "drop",
                "name": f"DROP {idx}",
                "color": "red",
                "score": 1.0,
            }
        )

    cue_events.sort(key=lambda x: x["time"])
    log(
        f"Cue {section_label}: "
        f"sections={len(analysis.get('sections', []))} | "
        f"tot={len(cue_events)} | max={MAX_CUES}"
    )

    result = {
        "stem": str(stem),
        "bpm": bpm,
        "sections": analysis.get("sections", []),
        "noKickSections": no_kick_analysis.get("sections", []),
        "buildDropPairs": build_drop_pairs,
        "voiceStats": analysis.get("voiceStats", {}),
        "boundaries": cue_events,
    }

    CACHE[key] = result
    save_cache(CACHE)

    return result


def clear_cues(deck: int) -> None:
    for cue_num in range(1, MAX_CUES + 1):
        try:
            vdj_execute(f"deck {deck} delete_cue {cue_num}")
        except Exception as exc:
            log(f"DECK {deck}: errore cancellazione cue {cue_num}: {exc}")

def write_cues(deck: int, boundaries: List[dict]) -> List[dict]:
    selected = boundaries[:MAX_CUES]
    quantize_raw = vdj_query(f"deck {deck} quantize_setcue").strip().lower()
    quantize_enabled = quantize_raw in {"true", "on", "yes", "1"}
    vdj_execute(f"deck {deck} quantize_setcue off")

    try:
        for cue_num, point in enumerate(selected, start=1):
            cue_time = float(point["time"])
            ms = max(1, int(round(cue_time * 1000)))
            name = str(point["name"])
            color = str(point["color"])

            if cue_time <= 0.001:
                vdj_execute(f"deck {deck} goto 0%")
                vdj_execute(f"deck {deck} set_cue {cue_num}")
            else:
                vdj_execute(f"deck {deck} set_cue {cue_num} {ms}ms")
            vdj_execute(f'deck {deck} cue_name {cue_num} "{name}"')
            vdj_execute(f'deck {deck} cue_color {cue_num} "{color}"')
    finally:
        if quantize_enabled:
            vdj_execute(f"deck {deck} quantize_setcue on")

    return selected


# ----------------------------------------------------------------------
# RESULT STATE
# ----------------------------------------------------------------------

def save_result(
    deck: int,
    audio_path: Path,
    stem: Path,
    analysis: dict,
    written: List[dict],
) -> None:
    result = {
        "updatedAt": time.strftime("%Y-%m-%dT%H:%M:%S"),
        "deck": deck,
        "audioPath": str(audio_path),
        "stemPath": str(stem),
        "bpm": analysis.get("bpm"),
        "sectionsFound": len(analysis.get("sections", [])),
        "cuesWritten": written,
    }

    try:
        with RESULT_PATH.open("w", encoding="utf-8") as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
    except Exception as exc:
        log(f"Errore scrittura result state: {exc}")


def _summary_time(value: float) -> str:
    total_seconds = max(0, int(round(float(value))))
    return f"{total_seconds // 60:02d}:{total_seconds % 60:02d}"


def show_analysis_summary(
    track_label: str,
    genre: str,
    analysis: dict,
    written: List[dict],
) -> None:
    lines = [track_label, f"Genere: {genre or '-'}", ""]

    novox = analysis.get("sections", [])
    lines.append(f"NOVOX ({len(novox)})")
    lines.extend(
        f"  {index}. {_summary_time(section['time'])} -> "
        f"{_summary_time(section.get('end', section['time']))}"
        for index, section in enumerate(novox, start=1)
    )
    if not novox:
        lines.append("  Nessuna zona")

    nokick = analysis.get("noKickSections", [])
    lines.extend(["", f"NOKICK ({len(nokick)})"])
    lines.extend(
        f"  {index}. {_summary_time(section['time'])} -> "
        f"{_summary_time(section.get('end', section['time']))}"
        for index, section in enumerate(nokick, start=1)
    )
    if not nokick:
        lines.append("  Nessuna zona")

    pairs = analysis.get("buildDropPairs", [])
    lines.extend(["", f"BUILD / DROP ({len(pairs)})"])
    lines.extend(
        f"  {index}. BUILD {_summary_time(pair['build'])} -> "
        f"DROP {_summary_time(pair['drop'])}"
        for index, pair in enumerate(pairs, start=1)
    )
    if not pairs:
        lines.append("  Nessuna coppia")

    lines.extend(["", f"CUE SCRITTI: {len(written)}"])
    message = "\n".join(lines)

    def _show() -> None:
        ctypes.windll.user32.MessageBoxW(
            None,
            message,
            "VDJDesk - Analisi completata",
            0x00000040 | 0x00040000,
        )

    threading.Thread(target=_show, daemon=True).start()


# ----------------------------------------------------------------------
# TRACK PROCESS
# ----------------------------------------------------------------------

def parse_bpm(deck_state: dict) -> float:
    try:
        bpm = float(deck_state.get("bpm", 0))
    except Exception:
        bpm = 0.0

    if 40.0 <= bpm <= 250.0:
        return bpm

    deck = int(deck_state.get("deck", 0))
    raw = vdj_query(f"deck {deck} get_bpm").replace(",", ".")
    m = re.search(r"[-+]?[0-9]*\.?[0-9]+", raw)

    if not m:
        raise RuntimeError(f"BPM non leggibile: {raw!r}")

    return float(m.group(0))


def normalize_genre(value: str) -> str:
    return re.sub(r"\s+", " ", str(value or "").strip().lower())


# ----------------------------------------------------------------------
# FULL READ-ONLY INFO DUMP
# ----------------------------------------------------------------------

FULL_INFO_FIELDS = [
    # Metadata / tags
    ("title", "get_title"),
    ("artist", "get_artist"),
    ("remix", "get_remix"),
    ("album", "get_album"),
    ("genre", "get_genre"),
    ("year", "get_year"),
    ("comment", "get_comment"),
    ("label", "get_label"),
    ("rating", "get_rating"),
    ("key", "get_key"),
    ("bpm", "get_bpm"),
    ("length", "get_length"),
    ("filepath", "get_filepath"),
    ("filename", "get_filename"),

    # File / database
    ("bitrate", "get_bitrate"),
    ("samplerate", "get_samplerate"),
    ("filesize", "get_filesize"),
    ("filetype", "get_filetype"),
    ("playcount", "get_playcount"),
    ("firstseen", "get_firstseen"),
    ("lastplay", "get_lastplay"),

    # Deck / transport / mixer
    ("position", "get_position"),
    ("time", "get_time"),
    ("time_elapsed", "get_time_elapsed"),
    ("time_remaining", "get_time_remaining"),
    ("pitch", "get_pitch"),
    ("pitch_range", "get_pitch_range"),
    ("gain", "get_gain"),
    ("volume", "get_volume"),
    ("play_state", "play"),
    ("pause_state", "pause"),
    ("sync_state", "sync"),
    ("master_tempo", "master_tempo"),
    ("key_lock", "key_lock"),
    ("is_audible", "is_audible"),
    ("is_playing", "is_playing"),
    ("is_paused", "is_paused"),
    ("is_loaded", "is_loaded"),

    # Beat / phase / grid
    ("beat", "get_beat"),
    ("beat_position", "get_beatpos"),
    ("beat_phase", "get_beatphase"),
    ("bar", "get_bar"),
    ("bar_position", "get_barpos"),
    ("grid", "get_grid"),
    ("first_beat", "get_firstbeat"),

    # Loop
    ("loop", "get_loop"),
    ("loop_size", "get_loop_size"),
    ("loop_position", "get_loop_position"),

    # Lyrics
    ("hasLyrics", "has_lyrics"),
    ("lyricsLanguage", "get_lyrics_language"),

    # Stem deck state only: NO audio/stem analysis
    ("stem_vocal", "stem 'Vocal'"),
    ("stem_instrumental", "stem 'Instru'"),
    ("stem_bass", "stem 'Bass'"),
    ("stem_kick", "stem 'Kick'"),
    ("stem_hihat", "stem 'HiHat'"),
]

FULL_CUE_COUNT = 16


def _safe_vdj_query(script: str):
    try:
        value = vdj_query(script)
    except Exception:
        return None

    if value is None:
        return None

    value = str(value).strip()
    if not value:
        return None

    low = value.lower()

    # Known textual errors from unsupported/invalid verbs are hidden.
    if (
        low.startswith("error")
        or "unknown verb" in low
        or "invalid script" in low
        or "not found" in low
    ):
        return None

    return value


def collect_full_info(deck: int) -> dict:
    info = {}

    for label, verb in FULL_INFO_FIELDS:
        value = _safe_vdj_query(
            f"deck {deck} {verb}"
        )
        if value is not None:
            info[label] = value

    # Hot cue / cue information, read only.
    for cue_num in range(1, FULL_CUE_COUNT + 1):
        cue_info = {}

        cue_queries = [
            ("position", f"get_cue {cue_num}"),
            ("name", f"cue_name {cue_num}"),
            ("color", f"cue_color {cue_num}"),
        ]

        for label, verb in cue_queries:
            value = _safe_vdj_query(
                f"deck {deck} {verb}"
            )
            if value is not None:
                cue_info[label] = value

        if cue_info:
            info[f"cue_{cue_num}"] = cue_info

    return info


def process_track(
    track: dict,
) -> None:
    deck = track.get("deck", 1)

    try:
        deck_num = int(deck)
    except Exception:
        deck_num = 1

    log("")
    log("=" * 64)
    log(f"DECK {deck_num}: nuova traccia")

    # Values already supplied by the native bridge.
    bridge_keys = [
        "title",
        "artist",
        "filepath",
        "bpm",
        "position",
        "hasLyrics",
        "lyricsLanguage",
    ]

    log("--- BRIDGE INFO ---")
    for key in bridge_keys:
        if key in track:
            log(f"{key}={track.get(key, '')}")

    log("--- VIRTUALDJ QUERY INFO ---")

    try:
        info = collect_full_info(deck_num)
    except Exception as exc:
        log(f"INFO DUMP ERROR: {exc}")
        info = {}

    for key, value in info.items():
        if isinstance(value, dict):
            parts = [
                f"{subkey}={subvalue}"
                for subkey, subvalue in value.items()
            ]
            log(f"{key}: " + " | ".join(parts))
        else:
            log(f"{key}={value}")

    track_title = str(info.get("title") or track.get("title") or "").strip()
    track_artist = str(info.get("artist") or track.get("artist") or "").strip()
    track_path = str(info.get("filepath") or track.get("filepath") or "").strip()
    genre = str(info.get("genre") or track.get("genre") or "").strip()
    track_label = " - ".join(
        part for part in [track_artist, track_title] if part
    ).strip() or "(sconosciuto)"

    log(f"TRACK: {track_label}")
    log(f"Genre VDJ: {genre or '(vuoto)'}")
    log(f"FILE: {track_path or '(vuoto)'}")

    log("Modalita': NOVOX 0 + NOKICK 4 + BUILD/DROP HIHAT 1 / WRITE CUES")

    audio_path_value = str(track.get("filepath") or info.get("filepath") or "").strip()
    if not audio_path_value:
        log("--- END INFO ---")
        log("Filepath assente: salto analisi.")
        return

    audio_path = Path(audio_path_value)
    if not audio_path.is_file():
        log("--- END INFO ---")
        log(f"File audio non trovato: {audio_path}")
        return

    try:
        bpm = parse_bpm(track)
    except Exception as exc:
        log("--- END INFO ---")
        log(f"BPM non disponibile: {exc}")
        return

    stem = find_stem(audio_path, wait=True)
    if stem is None:
        log("--- END INFO ---")
        log("Stem VirtualDJ non trovato: nessuna scrittura cue.")
        return

    try:
        analysis = analyze_urban_or_cache(stem, bpm, genre)
    except Exception as exc:
        log("--- END INFO ---")
        log(f"Analisi audio fallita: {exc}")
        return

    boundaries = analysis.get("boundaries", [])
    clear_cues(deck_num)
    if not boundaries:
        save_result(deck_num, audio_path, stem, analysis, [])
        log("--- END INFO ---")
        log("Nessun cue rilevato.")
        show_analysis_summary(track_label, genre, analysis, [])
        return

    try:
        written = write_cues(deck_num, boundaries)
        save_result(deck_num, audio_path, stem, analysis, written)
    except Exception as exc:
        log("--- END INFO ---")
        log(f"Scrittura cue fallita: {exc}")
        return

    log("--- END INFO ---")
    log(f"Cue scritti: {len(written)}")
    for cue in written:
        log(
            f"WRITE {cue.get('name','CUE')} | "
            f"{float(cue.get('time', 0.0)):.3f}s | "
            f"{cue.get('type','generic')}"
        )
    log(f"DECK {deck_num}: completato.")
    show_analysis_summary(track_label, genre, analysis, written)

def deck_signature(d: dict) -> str:
    deck = d.get("deck", "")
    loaded = d.get("loaded", False)
    filepath = str(d.get("filepath", "") or "")
    title = str(d.get("title", "") or "")
    return f"{deck}|{loaded}|{filepath}|{title}"


def run_once() -> int:
    state = load_bridge_state()

    if state is None:
        log(f"State bridge non trovato: {STATE_PATH}")
        return 1

    decks = normalize_decks(state)

    if not decks:
        log("Nessun deck nello state bridge.")
        return 1

    for d in decks:
        process_track(d)

    return 0


def process_exists(pid: int) -> bool:
    if pid <= 0:
        return True
    PROCESS_QUERY_LIMITED_INFORMATION = 0x1000
    handle = ctypes.windll.kernel32.OpenProcess(
        PROCESS_QUERY_LIMITED_INFORMATION, False, pid
    )
    if handle:
        ctypes.windll.kernel32.CloseHandle(handle)
        return True
    return False


def watch(parent_pid: int = 0) -> None:
    log("=" * 64)
    log("VDJDesk FULL INFO DUMP 1.1.0")
    log(f"Bridge state: {STATE_PATH}")
    log("In attesa di tracce...")
    log("=" * 64)

    last: Dict[int, str] = {}
    last_mtime_ns: Optional[int] = None

    while True:
        try:
            if parent_pid and not process_exists(parent_pid):
                log("VirtualDJ terminato. Arresto analizzatore.")
                return
            if not STATE_PATH.exists():
                time.sleep(POLL_SECONDS)
                continue

            try:
                mtime_ns = STATE_PATH.stat().st_mtime_ns
            except OSError:
                time.sleep(POLL_SECONDS)
                continue

            if last_mtime_ns == mtime_ns:
                time.sleep(POLL_SECONDS)
                continue

            last_mtime_ns = mtime_ns
            state = load_bridge_state()

            if state is None:
                time.sleep(POLL_SECONDS)
                continue

            decks = normalize_decks(state)

            for d in decks:
                try:
                    deck = int(d.get("deck", 0))
                except Exception:
                    continue

                if deck not in (1, 2):
                    continue

                sig = deck_signature(d)
                old = last.get(deck)

                # Registra anche deck vuoto: se poi ricarichi lo stesso
                # brano, la transizione vuoto -> brano viene rilevata.
                last[deck] = sig

                if old == sig:
                    continue

                if bool(d.get("loaded", False)) and d.get("filepath"):
                    process_track(d)

            time.sleep(POLL_SECONDS)

        except KeyboardInterrupt:
            log("Arresto richiesto.")
            return
        except Exception as exc:
            log(f"Errore watcher: {exc}")
            time.sleep(1.0)


def check_dependencies() -> bool:
    ok = True

    try:
        import numpy
        log(f"Dependency numpy: {numpy.__version__}")
    except Exception as exc:
        log(f"ERRORE dependency numpy: {exc}")
        ok = False

    try:
        import scipy
        log(f"Dependency scipy: {scipy.__version__}")
    except Exception as exc:
        log(f"ERRORE dependency scipy: {exc}")
        ok = False

    try:
        import librosa as _librosa
        log(f"Dependency librosa: {_librosa.__version__}")
    except Exception as exc:
        log(f"ERRORE dependency librosa: {exc}")
        ok = False

    for exe in ("ffmpeg", "ffprobe"):
        if shutil.which(exe):
            log(f"Dependency {exe}: OK")
        else:
            log(f"ERRORE dependency {exe}: non trovato nel PATH")
            ok = False

    return ok


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--once",
        action="store_true",
        help="Analizza una sola volta lo stato corrente e termina.",
    )
    parser.add_argument(
        "--parent-pid",
        type=int,
        default=0,
        help="PID di VirtualDJ: termina quando il processo padre non esiste piu.",
    )
    args = parser.parse_args()

    VDJ_DIR.mkdir(parents=True, exist_ok=True)

    if not check_dependencies():
        return 2

    if args.once:
        return run_once()

    watch(args.parent_pid)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
