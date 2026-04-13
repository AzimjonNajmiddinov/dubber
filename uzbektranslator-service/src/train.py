import argparse

import torch
from transformers import (
    AutoModelForCausalLM,
    AutoModelForSeq2SeqLM,
    AutoTokenizer,
    DataCollatorForSeq2Seq,
    Seq2SeqTrainer,
    Seq2SeqTrainingArguments,
)
from peft import LoraConfig, TaskType, get_peft_model
from trl import SFTConfig, SFTTrainer

from config import (
    DEFAULT_PROFILE,
    DUB_EVAL_FILES,
    DUB_TRAIN_FILES,
    EVAL_FILES,
    SOURCE_LANGUAGE_TO_NLLB,
    TRAIN_FILES,
    get_profile,
)
from data_utils import build_dub_text_dataset, build_seq2seq_dataset, build_text_dataset


def parse_args():
    parser = argparse.ArgumentParser(description="Train UzbekDublyaj model profiles.")
    parser.add_argument(
        "--profile",
        default=DEFAULT_PROFILE,
        help="Model profile to train: qwen3b, qwen7b, nllb600m, or qwen7b_dub.",
    )
    parser.add_argument("--dub-train", nargs="+", default=None, help="Override dub train files.")
    parser.add_argument("--dub-eval",  nargs="+", default=None, help="Override dub eval files.")
    return parser.parse_args()


def train_causal_chat(profile: dict):
    print("Loading tokenizer...")
    tokenizer = AutoTokenizer.from_pretrained(profile["model_name"], trust_remote_code=True)

    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    print("Loading model...")
    model = AutoModelForCausalLM.from_pretrained(
        profile["model_name"],
        trust_remote_code=True,
        dtype=torch.float16 if torch.cuda.is_available() else torch.float32,
    )

    print("Applying LoRA...")
    peft_config = LoraConfig(
        r=8,
        lora_alpha=16,
        lora_dropout=0.05,
        bias="none",
        task_type=TaskType.CAUSAL_LM,
        target_modules=["q_proj", "k_proj", "v_proj", "o_proj"],
    )
    model = get_peft_model(model, peft_config)
    model.print_trainable_parameters()

    print("Loading dataset...")
    train_dataset = build_text_dataset(TRAIN_FILES, tokenizer)
    eval_dataset = build_text_dataset(EVAL_FILES, tokenizer)
    print(f"Train samples: {len(train_dataset)}")
    print(f"Eval samples: {len(eval_dataset)}")

    training_args = SFTConfig(
        output_dir=profile["output_dir"],
        per_device_train_batch_size=1,
        per_device_eval_batch_size=1,
        gradient_accumulation_steps=4,
        num_train_epochs=5,
        learning_rate=1e-4,
        logging_steps=1,
        eval_strategy="steps",
        eval_steps=5,
        save_steps=10,
        dataset_text_field="text",
        max_length=512,
        report_to="none",
    )

    trainer = SFTTrainer(
        model=model,
        args=training_args,
        train_dataset=train_dataset,
        eval_dataset=eval_dataset,
        processing_class=tokenizer,
    )

    print("Starting training...")
    trainer.train()

    print("Saving model...")
    trainer.model.save_pretrained(profile["output_dir"])
    tokenizer.save_pretrained(profile["output_dir"])
    print("Done!")


def train_causal_chat_dub(profile: dict, train_files: list, eval_files: list):
    print("Loading tokenizer...")
    tokenizer = AutoTokenizer.from_pretrained(profile["model_name"], trust_remote_code=True)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    print("Loading model...")
    model = AutoModelForCausalLM.from_pretrained(
        profile["model_name"],
        trust_remote_code=True,
        dtype=torch.float16 if torch.cuda.is_available() else torch.float32,
    )

    print("Applying LoRA (r=16 for dubbing complexity)...")
    peft_config = LoraConfig(
        r=16,
        lora_alpha=32,
        lora_dropout=0.05,
        bias="none",
        task_type=TaskType.CAUSAL_LM,
        target_modules=["q_proj", "k_proj", "v_proj", "o_proj"],
    )
    model = get_peft_model(model, peft_config)
    model.print_trainable_parameters()

    print("Loading dubbing dataset...")
    train_dataset = build_dub_text_dataset(train_files, tokenizer)
    eval_dataset  = build_dub_text_dataset(eval_files, tokenizer)
    print(f"Train samples: {len(train_dataset)}")
    print(f"Eval samples:  {len(eval_dataset)}")

    training_args = SFTConfig(
        output_dir=profile["output_dir"],
        per_device_train_batch_size=1,
        per_device_eval_batch_size=1,
        gradient_accumulation_steps=4,
        num_train_epochs=3,
        learning_rate=1e-4,
        logging_steps=1,
        eval_strategy="steps",
        eval_steps=10,
        save_steps=20,
        dataset_text_field="text",
        max_length=768,
        report_to="none",
    )

    trainer = SFTTrainer(
        model=model,
        args=training_args,
        train_dataset=train_dataset,
        eval_dataset=eval_dataset,
        processing_class=tokenizer,
    )

    print("Starting training...")
    trainer.train()

    print("Saving model...")
    trainer.model.save_pretrained(profile["output_dir"])
    tokenizer.save_pretrained(profile["output_dir"])
    print("Done!")


def train_seq2seq(profile: dict):
    # Use English as default src_lang for tokenizer init; overridden per-batch during training.
    default_src_lang = SOURCE_LANGUAGE_TO_NLLB["en"]
    print("Loading tokenizer...")
    tokenizer = AutoTokenizer.from_pretrained(
        profile["model_name"],
        src_lang=default_src_lang,
        tgt_lang=profile["tgt_lang"],
    )

    print("Loading model...")
    model = AutoModelForSeq2SeqLM.from_pretrained(
        profile["model_name"],
        torch_dtype=torch.float16 if torch.cuda.is_available() else torch.float32,
    )
    model.config.forced_bos_token_id = tokenizer.convert_tokens_to_ids(profile["tgt_lang"])

    print("Applying LoRA...")
    peft_config = LoraConfig(
        r=8,
        lora_alpha=16,
        lora_dropout=0.05,
        bias="none",
        task_type=TaskType.SEQ_2_SEQ_LM,
        target_modules=["q_proj", "k_proj", "v_proj", "o_proj"],
    )
    model = get_peft_model(model, peft_config)
    model.print_trainable_parameters()

    print("Loading dataset...")
    train_dataset = build_seq2seq_dataset(TRAIN_FILES)
    eval_dataset = build_seq2seq_dataset(EVAL_FILES)
    print(f"Train samples: {len(train_dataset)}")
    print(f"Eval samples: {len(eval_dataset)}")

    def preprocess(batch):
        input_ids = []
        attention_masks = []
        for text, source_language in zip(batch["source_text"], batch["source_language"]):
            tokenizer.src_lang = SOURCE_LANGUAGE_TO_NLLB[source_language]
            encoded = tokenizer(text, max_length=256, truncation=True)
            input_ids.append(encoded["input_ids"])
            attention_masks.append(encoded["attention_mask"])

        labels = tokenizer(text_target=batch["target_text"], max_length=256, truncation=True)
        return {
            "input_ids": input_ids,
            "attention_mask": attention_masks,
            "labels": labels["input_ids"],
        }

    train_dataset = train_dataset.map(
        preprocess,
        batched=True,
        remove_columns=train_dataset.column_names,
    )
    eval_dataset = eval_dataset.map(
        preprocess,
        batched=True,
        remove_columns=eval_dataset.column_names,
    )

    data_collator = DataCollatorForSeq2Seq(tokenizer=tokenizer, model=model)
    training_args = Seq2SeqTrainingArguments(
        output_dir=profile["output_dir"],
        per_device_train_batch_size=4,
        per_device_eval_batch_size=4,
        gradient_accumulation_steps=2,
        num_train_epochs=5,
        learning_rate=1e-4,
        logging_steps=5,
        eval_strategy="steps",
        eval_steps=10,
        save_steps=10,
        predict_with_generate=True,
        generation_max_length=128,
        report_to="none",
    )

    trainer = Seq2SeqTrainer(
        model=model,
        args=training_args,
        train_dataset=train_dataset,
        eval_dataset=eval_dataset,
        data_collator=data_collator,
    )

    print("Starting training...")
    trainer.train()

    print("Saving model...")
    trainer.model.save_pretrained(profile["output_dir"])
    tokenizer.save_pretrained(profile["output_dir"])
    print("Done!")


def main():
    args    = parse_args()
    profile = get_profile(args.profile)
    print(f"Using profile: {args.profile} -> {profile['model_name']}")

    if profile["architecture"] == "causal_chat":
        train_causal_chat(profile)
        return

    if profile["architecture"] == "causal_chat_dub":
        train_files = args.dub_train or list(DUB_TRAIN_FILES)
        eval_files  = args.dub_eval  or list(DUB_EVAL_FILES)
        train_causal_chat_dub(profile, train_files, eval_files)
        return

    if profile["architecture"] == "seq2seq_translation":
        train_seq2seq(profile)
        return

    raise ValueError(f"Unsupported architecture: {profile['architecture']}")


if __name__ == "__main__":
    main()
