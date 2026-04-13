import json
from collections import Counter

from config import EVAL_FILES, SUPPORTED_CONTEXTS, SUPPORTED_SOURCE_LANGUAGES, TRAIN_FILES
from data_utils import load_chat_records


def collect_examples(paths):
    rows = []
    for path in paths:
        for record in load_chat_records(path):
            user_payload = json.loads(record["messages"][1]["content"])
            assistant_payload = json.loads(record["messages"][2]["content"])
            rows.append(
                {
                    "path": path,
                    "source": user_payload["user_message"],
                    "source_language": user_payload["source_language"],
                    "context": user_payload["optional_context"],
                    "target": assistant_payload["final_answer"],
                }
            )
    return rows


def print_split_summary(name, rows):
    contexts = Counter(row["context"] for row in rows)
    unique_sources = len({row["source"] for row in rows})
    print(f"{name}: {len(rows)} rows, {unique_sources} unique source phrases")
    print(f"{name} contexts: {dict(contexts)}")


def main():
    train_rows = collect_examples(TRAIN_FILES)
    eval_rows = collect_examples(EVAL_FILES)

    print_split_summary("train", train_rows)
    print_split_summary("eval", eval_rows)

    supported_contexts = set(SUPPORTED_CONTEXTS)
    seen_contexts = {row["context"] for row in train_rows + eval_rows}
    unsupported = sorted(seen_contexts - supported_contexts)
    if unsupported:
        raise ValueError(f"Unsupported contexts found after normalization: {unsupported}")

    supported_source_languages = set(SUPPORTED_SOURCE_LANGUAGES)
    seen_source_languages = {row["source_language"] for row in train_rows + eval_rows}
    unsupported_source_languages = sorted(seen_source_languages - supported_source_languages)
    if unsupported_source_languages:
        raise ValueError(
            f"Unsupported source languages found after normalization: {unsupported_source_languages}"
        )

    train_pairs = {(row["source"], row["source_language"], row["context"]) for row in train_rows}
    eval_pairs = {(row["source"], row["source_language"], row["context"]) for row in eval_rows}
    exact_overlap = sorted(train_pairs & eval_pairs)
    if exact_overlap:
        raise ValueError(f"Train/eval exact overlap detected: {exact_overlap[:10]}")

    train_sources = {(row["source"], row["source_language"]) for row in train_rows}
    eval_sources = {(row["source"], row["source_language"]) for row in eval_rows}
    source_overlap = sorted(train_sources & eval_sources)
    print(f"Train/eval source overlap: {len(source_overlap)}")
    if source_overlap:
        print(f"Overlap sample: {source_overlap[:10]}")

    print("Validation passed.")


if __name__ == "__main__":
    main()
