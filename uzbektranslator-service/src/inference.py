import argparse
import json

from config import DEFAULT_PROFILE, DEFAULT_SOURCE_LANGUAGE
from service import TranslationService


def parse_args():
    parser = argparse.ArgumentParser(description="Run UzbekDublyaj inference.")
    parser.add_argument(
        "--profile",
        default=DEFAULT_PROFILE,
        help="Model profile to use: qwen3b, qwen7b, or nllb600m.",
    )
    parser.add_argument(
        "--text",
        default="Can you open the door?",
        help="Input text to translate.",
    )
    parser.add_argument(
        "--context",
        default="polite",
        help="Tone context for chat-style profiles.",
    )
    parser.add_argument(
        "--source-language",
        default=DEFAULT_SOURCE_LANGUAGE,
        help="Source language code, for example 'en' or 'ru'.",
    )
    return parser.parse_args()


def main():
    args = parse_args()
    service = TranslationService(args.profile)
    print(f"Using profile: {args.profile} -> {service.profile['model_name']}")
    print(f"Loading service on device: {service.device}")
    service.load()
    result = service.translate(args.text, args.context, args.source_language)
    print("\nRESULT:\n")
    print(json.dumps({"final_answer": result}, ensure_ascii=False))


if __name__ == "__main__":
    main()
