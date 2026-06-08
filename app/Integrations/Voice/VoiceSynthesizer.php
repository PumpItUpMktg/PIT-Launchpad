<?php

namespace App\Integrations\Voice;

/**
 * Capability role: synthesises a VoiceProfile draft from the operator's voice
 * interview answers (Claude in production). Deferred — mocked for now.
 */
interface VoiceSynthesizer
{
    /**
     * @param  array<string, mixed>  $interview
     * @return array<string, mixed> a VoiceProfile-shaped draft payload
     */
    public function synthesize(array $interview): array;
}
