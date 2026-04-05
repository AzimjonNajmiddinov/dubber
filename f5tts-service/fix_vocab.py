"""
Fix vocab.txt for F5-TTS:
  - Remove empty strings / blank lines (cause duplicate keys → index out of bounds)
  - Deduplicate while preserving order
  - Ensure space ' ' is at index 0
"""
import sys

path = "/workspace/f5tts-uz-data_char/vocab.txt"
if len(sys.argv) > 1:
    path = sys.argv[1]

with open(path, "r") as f:
    chars = f.read().splitlines()

# Remove empty strings and deduplicate (preserve order)
seen = set()
clean = []
for c in chars:
    if c == "" or c in seen:
        continue
    seen.add(c)
    clean.append(c)

# Ensure space is first
if " " in clean:
    clean.remove(" ")
clean = [" "] + clean

with open(path, "w") as f:
    f.write("\n".join(clean) + "\n")

print(f"Fixed {path}. {len(clean)} unique chars. First 5: {repr(clean[:5])}")
