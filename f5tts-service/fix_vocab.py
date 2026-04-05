"""Ensure space is at index 0 in vocab.txt (required by F5-TTS get_tokenizer)."""
import sys

path = "/workspace/f5tts-uz-data_char/vocab.txt"
if len(sys.argv) > 1:
    path = sys.argv[1]

with open(path, "r") as f:
    chars = f.read().splitlines()

if " " in chars:
    chars.remove(" ")
chars = [" "] + chars

with open(path, "w") as f:
    f.write("\n".join(chars) + "\n")

print(f"Fixed {path}. First 5: {repr(chars[:5])}")
