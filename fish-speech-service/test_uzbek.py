#!/usr/bin/env python3
"""
Test Fish Speech with Uzbek text samples.

Tests pronunciation of tricky Uzbek sounds:
- oʻ (open O, like Turkish ö)
- gʻ (uvular fricative, like Turkish ğ)
- sh, ch digraphs
- Regular Latin Uzbek text

Usage:
    python test_uzbek.py [--server http://localhost:8080] [--reference speaker.wav --ref-text "transcript"]

Without --reference: tests basic Uzbek pronunciation (no voice cloning)
With --reference: tests voice cloning + Uzbek pronunciation
"""

import argparse
import base64
import json
import os
import sys
import time
from pathlib import Path

# Try msgpack first (faster, handles binary audio), fall back to JSON+base64
try:
    import ormsgpack
    USE_MSGPACK = True
except ImportError:
    USE_MSGPACK = False
    print("NOTE: ormsgpack not installed, using JSON+base64 (slower)")

import requests

# Uzbek test sentences — chosen to exercise the hardest pronunciation challenges
TEST_SENTENCES = [
    {
        "id": "01_greeting",
        "text": "Assalomu alaykum! Mening ismim Sardor.",
        "description": "Simple greeting — baseline test",
        "challenge": "Basic Latin Uzbek",
    },
    {
        "id": "02_open_o",
        "text": "Oʻzbekiston — bu goʻzal mamlakat.",
        "description": "Contains oʻ (open O) — the hardest Uzbek sound for TTS",
        "challenge": "oʻ pronunciation (should sound like ö, not regular o)",
    },
    {
        "id": "03_uvular_g",
        "text": "Gʻijduvon shahrida qadimiy masjidlar bor.",
        "description": "Contains gʻ (uvular fricative) + sh digraph",
        "challenge": "gʻ pronunciation (should be soft/uvular, not hard g)",
    },
    {
        "id": "04_mixed_digraphs",
        "text": "Shunday qilib, choyxonada oʻtirib, gʻamgin qoʻshiq eshitdik.",
        "description": "Mixed: sh, ch, oʻ, gʻ in one sentence",
        "challenge": "All difficult sounds together in natural context",
    },
    {
        "id": "05_dialogue_natural",
        "text": "Men senga aytmoqchi edim, lekin vaqt yoʻq edi.",
        "description": "Natural conversational Uzbek with oʻ",
        "challenge": "Conversational prosody + oʻ in common word yoʻq",
    },
    {
        "id": "06_emotional",
        "text": "Nega bunday qildingiz? Bu juda notoʻgʻri!",
        "description": "Emotional sentence with oʻ and gʻ in the same word",
        "challenge": "notoʻgʻri has both oʻ and gʻ — hardest test",
    },
    {
        "id": "07_long_sentence",
        "text": "Bugun biz oʻzimizning mustaqillik kunimizni nishonlaymiz, chunki bu biz uchun eng muhim bayram hisoblanadi.",
        "description": "Long sentence — tests prosody over longer text",
        "challenge": "Natural rhythm and pacing in longer Uzbek",
    },
    {
        "id": "08_apostrophe_variants",
        "text": "O'zbekiston Respublikasining poytaxti Toshkent shahridir.",
        "description": "Uses ASCII apostrophe o' instead of Unicode oʻ",
        "challenge": "ASCII apostrophe handling (common in user input)",
    },
]


def synthesize(server_url: str, text: str, reference_audio: bytes = None,
               reference_text: str = None, reference_id: str = None) -> bytes:
    """Call Fish Speech API and return audio bytes."""

    payload = {
        "text": text,
        "references": [],
        "format": "wav",
        "streaming": False,
        "temperature": 0.7,
        "top_p": 0.7,
        "repetition_penalty": 1.2,
        "chunk_length": 200,
        "max_new_tokens": 1024,
        "normalize": True,
    }

    if reference_id:
        payload["reference_id"] = reference_id
    elif reference_audio and reference_text:
        if USE_MSGPACK:
            payload["references"] = [{"audio": reference_audio, "text": reference_text}]
        else:
            payload["references"] = [{
                "audio": base64.b64encode(reference_audio).decode("utf-8"),
                "text": reference_text,
            }]

    if USE_MSGPACK:
        data = ormsgpack.packb(payload)
        headers = {"content-type": "application/msgpack"}
        resp = requests.post(f"{server_url}/v1/tts", data=data, headers=headers, timeout=120)
    else:
        headers = {"content-type": "application/json"}
        resp = requests.post(f"{server_url}/v1/tts", json=payload, headers=headers, timeout=120)

    resp.raise_for_status()
    return resp.content


def upload_reference(server_url: str, ref_id: str, audio_path: str, text: str):
    """Upload a reference voice for reuse."""
    with open(audio_path, "rb") as f:
        resp = requests.post(
            f"{server_url}/v1/references/add",
            files={"audio": ("sample.wav", f, "audio/wav")},
            data={"id": ref_id, "text": text},
            timeout=60,
        )
    resp.raise_for_status()
    print(f"  Reference voice '{ref_id}' uploaded")


def main():
    parser = argparse.ArgumentParser(description="Test Fish Speech with Uzbek text")
    parser.add_argument("--server", default="http://localhost:8080", help="Fish Speech API URL")
    parser.add_argument("--reference", help="Path to speaker reference WAV (10-30s)")
    parser.add_argument("--ref-text", help="Transcript of the reference audio (required with --reference)")
    parser.add_argument("--output-dir", default="./test_outputs", help="Directory for output WAVs")
    parser.add_argument("--only", help="Run only a specific test by ID (e.g. 02_open_o)")
    args = parser.parse_args()

    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    # Health check
    print(f"Checking Fish Speech server at {args.server}...")
    try:
        health = requests.get(f"{args.server}/v1/health", timeout=5)
        health.raise_for_status()
        print(f"  Server healthy: {health.json()}")
    except Exception as e:
        print(f"  ERROR: Server not reachable: {e}")
        sys.exit(1)

    # Load reference audio if provided
    ref_audio = None
    ref_text = None
    ref_id = None
    mode = "no_clone"

    if args.reference:
        if not args.ref_text:
            print("ERROR: --ref-text is required when using --reference")
            sys.exit(1)
        if not os.path.exists(args.reference):
            print(f"ERROR: Reference file not found: {args.reference}")
            sys.exit(1)

        print(f"\nVoice cloning mode: using {args.reference}")
        mode = "cloned"

        # Upload as reference for caching
        ref_id = "uzbek_test_speaker"
        with open(args.reference, "rb") as f:
            ref_audio = f.read()
        ref_text = args.ref_text

        try:
            upload_reference(args.server, ref_id, args.reference, ref_text)
        except Exception as e:
            print(f"  Warning: Could not upload reference (will use inline): {e}")
            ref_id = None
    else:
        print("\nBasic mode (no voice cloning) — testing pronunciation only")

    # Run tests
    tests = TEST_SENTENCES
    if args.only:
        tests = [t for t in tests if t["id"] == args.only]
        if not tests:
            print(f"ERROR: Test '{args.only}' not found")
            sys.exit(1)

    print(f"\nRunning {len(tests)} Uzbek pronunciation tests...\n")
    print("=" * 70)

    results = []
    for test in tests:
        test_id = test["id"]
        text = test["text"]

        print(f"\nTest: {test_id}")
        print(f"  Text:      {text}")
        print(f"  Challenge: {test['challenge']}")

        output_path = output_dir / f"{mode}_{test_id}.wav"

        try:
            start = time.time()

            audio_bytes = synthesize(
                args.server, text,
                reference_audio=ref_audio if not ref_id else None,
                reference_text=ref_text if not ref_id else None,
                reference_id=ref_id,
            )

            elapsed = time.time() - start
            size_kb = len(audio_bytes) / 1024

            with open(output_path, "wb") as f:
                f.write(audio_bytes)

            print(f"  Result:    OK ({elapsed:.1f}s, {size_kb:.0f} KB)")
            print(f"  Output:    {output_path}")
            results.append({"id": test_id, "status": "ok", "time": elapsed, "size_kb": size_kb})

        except Exception as e:
            print(f"  Result:    FAILED — {e}")
            results.append({"id": test_id, "status": "failed", "error": str(e)})

    # Summary
    print("\n" + "=" * 70)
    ok = sum(1 for r in results if r["status"] == "ok")
    fail = sum(1 for r in results if r["status"] == "failed")
    print(f"\nResults: {ok}/{len(results)} passed, {fail} failed")

    if ok > 0:
        avg_time = sum(r["time"] for r in results if r["status"] == "ok") / ok
        print(f"Average synthesis time: {avg_time:.1f}s")

    print(f"\nOutput files in: {output_dir}/")
    print("\nListen to the outputs and check:")
    print("  1. Is oʻ pronounced as 'ö' (not regular 'o')?")
    print("  2. Is gʻ pronounced as soft uvular (not hard 'g')?")
    print("  3. Are sh/ch pronounced correctly?")
    print("  4. Does the overall prosody sound natural (not robotic)?")
    if mode == "cloned":
        print("  5. Does the voice match the reference speaker?")


if __name__ == "__main__":
    main()
