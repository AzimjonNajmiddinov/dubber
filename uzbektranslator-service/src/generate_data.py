#!/usr/bin/env python3
"""
Generate dubbing training pairs using the Claude API.

Input JSONL format (one batch per line):
  {"source_language": "en", "segments": [
    {"text": "Don't play games with me.", "speaker": "M1", "duration": 3.2},
    {"text": "I don't know what you mean.", "speaker": "F1", "duration": 2.1}
  ]}

Usage:
  python generate_data.py \\
      --input  data/raw_subtitles.jsonl \\
      --output data/train_dub.jsonl \\
      --eval-output data/eval_dub.jsonl \\
      --api-key sk-ant-... \\
      --batches 500
"""

import argparse
import json
import random
import sys
import time
from pathlib import Path

import anthropic  # pip install anthropic

GENERATION_SYSTEM_PROMPT = """\
You are a professional Uzbek dubbing scriptwriter.

Given a batch of subtitle segments with speaker tags and time slots, you must:
1. Write a brief scene_context (2-3 sentences in English describing the scene/mood).
2. Produce a natural spoken Uzbek translation for each segment.

UZBEK RULES:
- Latin script ONLY. Never use Cyrillic letters.
- Colloquial spoken forms: qilyapman (not qilayotirman), boryapman (not borayotirman).
- Text must fit the time slot: max_chars = round(duration * 12). Count characters carefully.
- Keep character names untranslated. Strip [music][laughing] etc — spoken words only.
- Punctuation for emotion: ! = anger/excitement, ... = hesitation, — = pause, ? = question.

Return ONLY valid JSON with this exact structure (no extra text):
{
  "scene_context": "...",
  "translations": [
    {"speaker": "M1", "text": "..."},
    ...
  ]
}
The translations array must have the same number of items as the input segments array."""


def build_user_message(batch: dict) -> str:
    lines = []
    for i, seg in enumerate(batch["segments"]):
        duration  = seg["duration"]
        max_chars = round(duration * 12)
        lines.append(f"{i + 1}. [{seg['speaker']}] [{duration}s, max {max_chars} chars] {seg['text']}")
    return json.dumps({
        "source_language": batch["source_language"],
        "segments": lines,
    }, ensure_ascii=False)


def call_claude(client: anthropic.Anthropic, model: str, user_msg: str) -> dict | None:
    try:
        response = client.messages.create(
            model=model,
            max_tokens=1024,
            system=GENERATION_SYSTEM_PROMPT,
            messages=[{"role": "user", "content": user_msg}],
        )
        raw = response.content[0].text.strip()
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        print(f"  [WARN] JSON parse failed: {exc}")
        return None
    except Exception as exc:
        print(f"  [WARN] Claude call failed: {exc}")
        return None


def build_training_record(batch: dict, claude_response: dict, dub_system_prompt: str) -> dict | None:
    n_segs       = len(batch["segments"])
    translations = claude_response.get("translations", [])

    if len(translations) != n_segs:
        return None

    lines = []
    for i, seg in enumerate(batch["segments"]):
        duration  = seg["duration"]
        max_chars = round(duration * 12)
        lines.append(f"{i + 1}. [{seg['speaker']}] [{duration}s, max {max_chars} chars] {seg['text']}")

    user_payload   = {
        "source_language": batch["source_language"],
        "scene_context":   claude_response.get("scene_context", ""),
        "segments":        lines,
    }
    assist_payload = {"translations": translations}

    return {
        "messages": [
            {"role": "system",    "content": dub_system_prompt},
            {"role": "user",      "content": json.dumps(user_payload,   ensure_ascii=False)},
            {"role": "assistant", "content": json.dumps(assist_payload, ensure_ascii=False)},
        ]
    }


def main():
    parser = argparse.ArgumentParser(description="Generate dubbing training data via Claude API.")
    parser.add_argument("--input",       required=True,  help="Raw subtitle JSONL input file.")
    parser.add_argument("--output",      required=True,  help="Training JSONL output file.")
    parser.add_argument("--eval-output", default=None,   help="Eval JSONL output file.")
    parser.add_argument("--api-key",     required=True,  help="Anthropic API key.")
    parser.add_argument("--model",       default="claude-sonnet-4-6")
    parser.add_argument("--batches",     type=int, default=None, help="Max batches to process.")
    parser.add_argument("--delay",       type=float, default=0.3, help="Seconds between API calls.")
    parser.add_argument("--split",       type=float, default=0.1, help="Fraction held out for eval.")
    args = parser.parse_args()

    sys.path.insert(0, str(Path(__file__).parent))
    from config import DUB_SYSTEM_PROMPT  # noqa: PLC0415

    client = anthropic.Anthropic(api_key=args.api_key)

    raw_batches = []
    with Path(args.input).open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line:
                raw_batches.append(json.loads(line))

    if args.batches:
        raw_batches = raw_batches[:args.batches]

    random.shuffle(raw_batches)

    records  = []
    skipped  = 0
    for i, batch in enumerate(raw_batches):
        n = len(batch["segments"])
        print(f"[{i + 1}/{len(raw_batches)}] {n} segs ({batch['source_language']})...", end=" ", flush=True)
        user_msg = build_user_message(batch)
        response = call_claude(client, args.model, user_msg)
        if response is None:
            print("SKIP (API error)")
            skipped += 1
            continue
        record = build_training_record(batch, response, DUB_SYSTEM_PROMPT)
        if record is None:
            print(f"SKIP (count mismatch: got {len(response.get('translations', []))} translations)")
            skipped += 1
            continue
        records.append(record)
        print("OK")
        time.sleep(args.delay)

    print(f"\nGenerated {len(records)} records, skipped {skipped}.")

    split_idx     = max(1, int(len(records) * (1 - args.split)))
    train_records = records[:split_idx]
    eval_records  = records[split_idx:]

    with Path(args.output).open("w", encoding="utf-8") as f:
        for rec in train_records:
            f.write(json.dumps(rec, ensure_ascii=False) + "\n")
    print(f"Wrote {len(train_records)} train records → {args.output}")

    if args.eval_output and eval_records:
        with Path(args.eval_output).open("w", encoding="utf-8") as f:
            for rec in eval_records:
                f.write(json.dumps(rec, ensure_ascii=False) + "\n")
        print(f"Wrote {len(eval_records)} eval records  → {args.eval_output}")


if __name__ == "__main__":
    main()
