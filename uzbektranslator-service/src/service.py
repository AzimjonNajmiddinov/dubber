import json
from pathlib import Path

import torch
from peft import PeftModel
from transformers import AutoModelForCausalLM, AutoModelForSeq2SeqLM, AutoTokenizer

try:
    from .config import (
        DEFAULT_SOURCE_LANGUAGE,
        DUB_MAX_NEW_TOKENS,
        DUB_SYSTEM_PROMPT,
        LEGACY_CONTEXT_MAP,
        SOURCE_LANGUAGE_TO_NLLB,
        SUPPORTED_CONTEXTS,
        SUPPORTED_SOURCE_LANGUAGES,
        SYSTEM_PROMPT,
        get_profile,
    )
except ImportError:
    from config import (
        DEFAULT_SOURCE_LANGUAGE,
        DUB_MAX_NEW_TOKENS,
        DUB_SYSTEM_PROMPT,
        LEGACY_CONTEXT_MAP,
        SOURCE_LANGUAGE_TO_NLLB,
        SUPPORTED_CONTEXTS,
        SUPPORTED_SOURCE_LANGUAGES,
        SYSTEM_PROMPT,
        get_profile,
    )


def normalize_context(raw_context: str) -> str:
    context = LEGACY_CONTEXT_MAP.get(raw_context, raw_context)
    if context not in SUPPORTED_CONTEXTS:
        supported = ", ".join(SUPPORTED_CONTEXTS)
        raise ValueError(f"Unsupported context '{raw_context}'. Expected one of: {supported}")
    return context


def normalize_source_language(raw_source_language: str) -> str:
    source_language = raw_source_language or DEFAULT_SOURCE_LANGUAGE
    if source_language not in SUPPORTED_SOURCE_LANGUAGES:
        supported = ", ".join(SUPPORTED_SOURCE_LANGUAGES)
        raise ValueError(
            f"Unsupported source_language '{raw_source_language}'. Expected one of: {supported}"
        )
    return source_language


def detect_device() -> str:
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


def model_dtype(device: str):
    if device == "cuda":
        return torch.float16
    return torch.float32


class TranslationService:
    def __init__(self, profile_name: str, device: str | None = None):
        self.profile_name = profile_name
        self.profile = get_profile(profile_name)
        self.device = device or detect_device()
        self.tokenizer = None
        self.model = None

    def load(self):
        output_dir = Path(self.profile["output_dir"])
        if not output_dir.exists():
            raise FileNotFoundError(
                f"Profile output directory not found: {output_dir}. Train this profile before serving it."
            )

        if self.profile["architecture"] in ("causal_chat", "causal_chat_dub"):
            self._load_causal_chat()
            return

        if self.profile["architecture"] == "seq2seq_translation":
            self._load_seq2seq()
            return

        raise ValueError(f"Unsupported architecture: {self.profile['architecture']}")

    def translate(
        self,
        text: str,
        context: str = "work",
        source_language: str = DEFAULT_SOURCE_LANGUAGE,
    ) -> str:
        if not text.strip():
            raise ValueError("Input text must not be empty.")
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Translation service is not loaded.")

        normalized_context = normalize_context(context)
        normalized_source_language = normalize_source_language(source_language)

        if self.profile["architecture"] == "causal_chat":
            return self._translate_causal_chat(text, normalized_context, normalized_source_language)

        if self.profile["architecture"] == "seq2seq_translation":
            return self._translate_seq2seq(text, normalized_source_language)

        raise ValueError(f"Unsupported architecture: {self.profile['architecture']}")

    def translate_dub(
        self,
        segments: list,
        source_language: str = DEFAULT_SOURCE_LANGUAGE,
        scene_context: str = "",
    ) -> list[dict]:
        """Translate a batch of dubbing segments with timing and speaker awareness."""
        if not segments:
            raise ValueError("segments must not be empty.")
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Translation service is not loaded.")

        normalized_language = normalize_source_language(source_language)

        if self.profile["architecture"] in ("causal_chat", "causal_chat_dub"):
            return self._translate_causal_chat_dub(segments, normalized_language, scene_context)

        raise ValueError(f"Profile '{self.profile_name}' does not support dubbing translation.")

    def _translate_causal_chat_dub(self, segments: list, source_language: str, scene_context: str) -> list[dict]:
        lines = []
        for i, seg in enumerate(segments):
            text     = seg.text if hasattr(seg, "text") else seg["text"]
            speaker  = seg.speaker if hasattr(seg, "speaker") else seg.get("speaker", "M1")
            duration = seg.duration if hasattr(seg, "duration") else seg.get("duration", 3.0)
            max_chars = round(duration * 12)
            lines.append(f"{i + 1}. [{speaker}] [{duration}s, max {max_chars} chars] {text}")

        payload = {
            "source_language": source_language,
            "scene_context": scene_context,
            "segments": lines,
        }
        messages = [
            {"role": "system", "content": DUB_SYSTEM_PROMPT},
            {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
        ]
        prompt = self.tokenizer.apply_chat_template(
            messages, tokenize=False, add_generation_prompt=True
        )
        inputs = self.tokenizer(prompt, return_tensors="pt").to(self.device)
        input_length = inputs["input_ids"].shape[1]

        with torch.no_grad():
            outputs = self.model.generate(
                **inputs,
                max_new_tokens=DUB_MAX_NEW_TOKENS,
                do_sample=False,
                pad_token_id=self.tokenizer.eos_token_id,
            )

        generated_ids = outputs[0][input_length:]
        raw_result = self.tokenizer.decode(generated_ids, skip_special_tokens=True).strip()
        try:
            parsed = json.loads(raw_result)
            return parsed["translations"]
        except Exception:
            # Fallback: return original texts with preserved speaker tags
            return [
                {
                    "speaker": seg.speaker if hasattr(seg, "speaker") else seg.get("speaker", "M1"),
                    "text":    seg.text    if hasattr(seg, "text")    else seg.get("text", ""),
                }
                for seg in segments
            ]

    def _load_causal_chat(self):
        self.tokenizer = AutoTokenizer.from_pretrained(self.profile["output_dir"], trust_remote_code=True)
        base_model = AutoModelForCausalLM.from_pretrained(
            self.profile["model_name"],
            trust_remote_code=True,
            torch_dtype=model_dtype(self.device),
            low_cpu_mem_usage=True,
        ).to(self.device)
        self.model = PeftModel.from_pretrained(base_model, self.profile["output_dir"]).to(self.device)
        self.model.eval()

    def _load_seq2seq(self):
        # src_lang is set dynamically per request in _translate_seq2seq;
        # load with a default so the tokenizer initialises correctly.
        default_src = SOURCE_LANGUAGE_TO_NLLB[DEFAULT_SOURCE_LANGUAGE]
        self.tokenizer = AutoTokenizer.from_pretrained(
            self.profile["output_dir"],
            src_lang=default_src,
            tgt_lang=self.profile["tgt_lang"],
        )
        base_model = AutoModelForSeq2SeqLM.from_pretrained(
            self.profile["model_name"],
            torch_dtype=model_dtype(self.device),
        ).to(self.device)
        self.model = PeftModel.from_pretrained(base_model, self.profile["output_dir"]).to(self.device)
        self.model.eval()

    def _translate_causal_chat(self, text: str, context: str, source_language: str) -> str:
        payload = {
            "user_message": text,
            "source_language": source_language,
            "optional_context": context,
        }
        messages = [
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
        ]
        prompt = self.tokenizer.apply_chat_template(
            messages,
            tokenize=False,
            add_generation_prompt=True,
        )
        inputs = self.tokenizer(prompt, return_tensors="pt").to(self.device)
        input_length = inputs["input_ids"].shape[1]

        with torch.no_grad():
            outputs = self.model.generate(
                **inputs,
                max_new_tokens=80,
                do_sample=False,
                pad_token_id=self.tokenizer.eos_token_id,
            )

        generated_ids = outputs[0][input_length:]
        raw_result = self.tokenizer.decode(generated_ids, skip_special_tokens=True).strip()
        try:
            parsed = json.loads(raw_result)
            return parsed["final_answer"]
        except Exception:
            return raw_result

    def _translate_seq2seq(self, text: str, source_language: str) -> str:
        self.tokenizer.src_lang = SOURCE_LANGUAGE_TO_NLLB[source_language]
        inputs = self.tokenizer(text, return_tensors="pt").to(self.device)
        with torch.no_grad():
            generated_tokens = self.model.generate(
                **inputs,
                forced_bos_token_id=self.tokenizer.convert_tokens_to_ids(self.profile["tgt_lang"]),
                max_new_tokens=80,
            )
        return self.tokenizer.batch_decode(generated_tokens, skip_special_tokens=True)[0].strip()
