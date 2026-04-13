SYSTEM_PROMPT = (
    "You are UzbekDublyaj, a strict translation engine. "
    "Translate the user's text into natural Uzbek. Preserve meaning and tone. "
    'Return only valid JSON in this exact format: {"final_answer":"..."}'
)

DUB_SYSTEM_PROMPT = (
    "Siz UzbekDublyaj, kino dublyaji uchun maxsus tarjimon dasturisiz. "
    "Sahna kontekstini o'qib, har bir replikani tabiiy so'zlashuv o'zbek tilida qayta yozing. "
    "Har bir qator uchun [Ns, max M chars] vaqt uyasi beriladi. "
    "Matn shu vaqt ichida aytilishi SHART. Qisqa uyasi = qisqa matn. Uzun uyasi = tabiiy gap. "
    "QOIDALAR: "
    "1. Faqat lotin alifbosi. Hech qachon kirill harflari ishlatma. "
    "2. So'zlashuv shakli: qilyapman (qilayotirman EMAS), boryapman (borayotirman EMAS). "
    "3. Emotsiya belgisi: ! = g'azab/hayajon, ... = ikkilanish, — = to'xtash, ? = savol. "
    "4. [music] [laughing] kabi izohlarni o'chir, faqat so'zlangan so'zlarni yoz. "
    "5. Ismlarni tarjima qilma. "
    'JSON formatida qayt: {"translations": [{"speaker": "M1", "text": "..."}]}'
)

DUB_MAX_NEW_TOKENS = 512

SUPPORTED_CONTEXTS = ("polite", "formal", "casual", "work")
SUPPORTED_SOURCE_LANGUAGES = ("en", "ru")
SOURCE_LANGUAGE_TO_NLLB = {
    "en": "eng_Latn",
    "ru": "rus_Cyrl",
}
DEFAULT_SOURCE_LANGUAGE = "en"
LEGACY_CONTEXT_MAP = {
    "warning": "work",
}

TRAIN_FILES = (
    "data/train_en.jsonl",
    "data/train_ru.jsonl",
)
EVAL_FILES = (
    "data/eval_en.jsonl",
    "data/eval_ru.jsonl",
)

DUB_TRAIN_FILES = ("data/train_dub.jsonl",)
DUB_EVAL_FILES  = ("data/eval_dub.jsonl",)

MODEL_PROFILES = {
    "qwen3b": {
        "model_name": "Qwen/Qwen2.5-3B-Instruct",
        "output_dir": "outputs/qwen3b",
        "architecture": "causal_chat",
    },
    "qwen7b": {
        "model_name": "Qwen/Qwen2.5-7B-Instruct",
        "output_dir": "outputs/qwen7b",
        "architecture": "causal_chat",
    },
    "nllb600m": {
        "model_name": "facebook/nllb-200-distilled-600M",
        "output_dir": "outputs/nllb600m",
        "architecture": "seq2seq_translation",
        "tgt_lang": "uzn_Latn",
    },
    "qwen7b_dub": {
        "model_name": "Qwen/Qwen2.5-7B-Instruct",
        "output_dir": "outputs/qwen7b_dub",
        "architecture": "causal_chat_dub",
    },
}

DEFAULT_PROFILE = "nllb600m"


def get_profile(profile_name: str) -> dict:
    try:
        return MODEL_PROFILES[profile_name]
    except KeyError as exc:
        supported = ", ".join(sorted(MODEL_PROFILES))
        raise ValueError(f"Unknown profile '{profile_name}'. Expected one of: {supported}") from exc
