import json
from pathlib import Path

from datasets import Dataset

from config import (
    DEFAULT_SOURCE_LANGUAGE,
    DUB_SYSTEM_PROMPT,
    LEGACY_CONTEXT_MAP,
    SUPPORTED_CONTEXTS,
    SUPPORTED_SOURCE_LANGUAGES,
    SYSTEM_PROMPT,
)


def normalize_context(raw_context: str) -> str:
    context = LEGACY_CONTEXT_MAP.get(raw_context, raw_context)
    if context not in SUPPORTED_CONTEXTS:
        supported = ", ".join(SUPPORTED_CONTEXTS)
        raise ValueError(f"Unsupported context '{raw_context}'. Expected one of: {supported}")
    return context


def normalize_record(record: dict) -> dict:
    if "messages" not in record or len(record["messages"]) != 3:
        raise ValueError("Each record must contain exactly 3 chat messages.")

    user_payload = json.loads(record["messages"][1]["content"])
    assistant_payload = json.loads(record["messages"][2]["content"])

    if "user_message" not in user_payload:
        raise ValueError("Missing 'user_message' in user payload.")
    if "final_answer" not in assistant_payload:
        raise ValueError("Missing 'final_answer' in assistant payload.")

    normalized_context = normalize_context(user_payload["optional_context"])
    source_language = user_payload.get("source_language", DEFAULT_SOURCE_LANGUAGE)
    if source_language not in SUPPORTED_SOURCE_LANGUAGES:
        supported = ", ".join(SUPPORTED_SOURCE_LANGUAGES)
        raise ValueError(
            f"Unsupported source_language '{source_language}'. Expected one of: {supported}"
        )

    return {
        "messages": [
            {"role": "system", "content": SYSTEM_PROMPT},
            {
                "role": "user",
                "content": json.dumps(
                    {
                        "user_message": user_payload["user_message"],
                        "source_language": source_language,
                        "optional_context": normalized_context,
                    },
                    ensure_ascii=False,
                ),
            },
            {
                "role": "assistant",
                "content": json.dumps(
                    {"final_answer": assistant_payload["final_answer"]},
                    ensure_ascii=False,
                ),
            },
        ]
    }


def load_chat_records(path: str) -> list[dict]:
    records = []
    with Path(path).open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            line = line.strip()
            if not line:
                continue
            try:
                raw_record = json.loads(line)
                records.append(normalize_record(raw_record))
            except Exception as exc:
                raise ValueError(f"Failed to parse {path}:{line_number}: {exc}") from exc
    return records


def build_text_dataset(paths: list[str] | tuple[str, ...], tokenizer) -> Dataset:
    rows = []
    for path in paths:
        for record in load_chat_records(path):
            text = tokenizer.apply_chat_template(
                record["messages"],
                tokenize=False,
                add_generation_prompt=False,
            )
            rows.append({"text": text})
    return Dataset.from_list(rows)


def normalize_dub_record(record: dict) -> dict:
    """Validate and re-stamp system prompt for a dubbing training record."""
    if "messages" not in record or len(record["messages"]) != 3:
        raise ValueError("Dub record must have exactly 3 messages.")

    user_payload   = json.loads(record["messages"][1]["content"])
    assist_payload = json.loads(record["messages"][2]["content"])

    if "segments" not in user_payload:
        raise ValueError("Missing 'segments' in dub user payload.")
    if "translations" not in assist_payload:
        raise ValueError("Missing 'translations' in dub assistant payload.")
    if len(user_payload["segments"]) != len(assist_payload["translations"]):
        raise ValueError(
            f"Segment/translation count mismatch: "
            f"{len(user_payload['segments'])} segments vs {len(assist_payload['translations'])} translations."
        )

    source_language = user_payload.get("source_language", DEFAULT_SOURCE_LANGUAGE)
    if source_language not in SUPPORTED_SOURCE_LANGUAGES:
        raise ValueError(f"Unsupported source_language '{source_language}'.")

    return {
        "messages": [
            {"role": "system", "content": DUB_SYSTEM_PROMPT},
            {"role": "user", "content": json.dumps({
                "source_language": source_language,
                "scene_context":   user_payload.get("scene_context", ""),
                "segments":        user_payload["segments"],
            }, ensure_ascii=False)},
            {"role": "assistant", "content": json.dumps(
                {"translations": assist_payload["translations"]},
                ensure_ascii=False,
            )},
        ]
    }


def load_dub_records(path: str) -> list[dict]:
    records = []
    with Path(path).open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            line = line.strip()
            if not line:
                continue
            try:
                raw_record = json.loads(line)
                records.append(normalize_dub_record(raw_record))
            except Exception as exc:
                raise ValueError(f"Failed to parse {path}:{line_number}: {exc}") from exc
    return records


def build_dub_text_dataset(paths: list[str] | tuple[str, ...], tokenizer) -> Dataset:
    rows = []
    for path in paths:
        for record in load_dub_records(path):
            text = tokenizer.apply_chat_template(
                record["messages"],
                tokenize=False,
                add_generation_prompt=False,
            )
            rows.append({"text": text})
    return Dataset.from_list(rows)


def build_seq2seq_dataset(paths: list[str] | tuple[str, ...]) -> Dataset:
    rows = []
    for path in paths:
        for record in load_chat_records(path):
            user_payload = json.loads(record["messages"][1]["content"])
            assistant_payload = json.loads(record["messages"][2]["content"])
            rows.append(
                {
                    "source_text": user_payload["user_message"],
                    "source_language": user_payload["source_language"],
                    "context": user_payload["optional_context"],
                    "target_text": assistant_payload["final_answer"],
                }
            )
    return Dataset.from_list(rows)
