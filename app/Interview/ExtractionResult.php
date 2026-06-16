<?php

namespace App\Interview;

/**
 * The owner-interview extraction: a validated SiloSeed plus a VoiceProfile-shaped
 * payload (the same shape VoiceSynthesizer emits, ready for the wizard to persist as
 * a VoiceProfile in a later PR). One conversation, two outputs.
 */
final class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $voice  VoiceProfile payload (framing_model, tone_axes, persona, …)
     */
    public function __construct(
        public readonly SiloSeed $seed,
        public readonly array $voice,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed->toArray(),
            'voice' => $this->voice,
        ];
    }
}
