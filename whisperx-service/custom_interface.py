import torch
from speechbrain.pretrained import Pretrained


class CustomEncoderWav2vec2Classifier(Pretrained):
    """
    Generic utterance-level classifier used by:
      speechbrain/emotion-recognition-wav2vec2-IEMOCAP

    It expects modules defined in the HF SpeechBrain yaml:
      - wav2vec2 encoder
      - avg_pool
      - output_mlp
      - label_encoder in hparams
    """

    def encode_batch(self, wavs, wav_lens=None, normalize=False):
        # Accept [time] => [1, time]
        if wavs.dim() == 1:
            wavs = wavs.unsqueeze(0)

        if wav_lens is None:
            wav_lens = torch.ones(wavs.shape[0], device=self.device)

        wavs = wavs.to(self.device).float()
        wav_lens = wav_lens.to(self.device)

        # encoder
        feats = self.mods.wav2vec2(wavs)

        # attentive/stat pooling
        pooled = self.mods.avg_pool(feats, wav_lens)

        pooled = pooled.view(pooled.shape[0], -1)
        return pooled

    def classify_batch(self, wavs, wav_lens=None):
        emb = self.encode_batch(wavs, wav_lens)
        logits = self.mods.output_mlp(emb)
        out_prob = self.hparams.softmax(logits)

        score, index = torch.max(out_prob, dim=-1)
        text_lab = self.hparams.label_encoder.decode_torch(index)
        return out_prob, score, index, text_lab

    def classify_file(self, path: str):
        waveform = self.load_audio(path)  # SpeechBrain helper
        batch = waveform.unsqueeze(0)     # [1, time]
        rel_length = torch.tensor([1.0])

        emb = self.encode_batch(batch, rel_length)
        logits = self.mods.output_mlp(emb).squeeze(1) if logits_has_dim1(self.mods.output_mlp(emb)) else self.mods.output_mlp(emb)
        out_prob = self.hparams.softmax(logits)

        score, index = torch.max(out_prob, dim=-1)
        text_lab = self.hparams.label_encoder.decode_torch(index)
        return out_prob, score, index, text_lab

    def forward(self, wavs, wav_lens=None, normalize=False):
        return self.encode_batch(wavs=wavs, wav_lens=wav_lens, normalize=normalize)


def logits_has_dim1(x):
    # defensive helper: some configs return [B, 1, C]
    return hasattr(x, "dim") and x.dim() == 3 and x.size(1) == 1
