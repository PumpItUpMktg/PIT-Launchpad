<?php

namespace App\Models;

use App\Enums\VoiceStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\VoiceProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $version
 * @property VoiceStatus $status
 * @property string|null $framing_model
 */
class VoiceProfile extends Model
{
    /** @use HasFactory<VoiceProfileFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VoiceStatus::class,
            'version' => 'integer',
            'tone_axes' => 'array',
            'format_conventions' => 'array',
            'language_rules' => 'array',
            'audience' => 'array',
            'persona' => 'array',
            'calibration_refs' => 'array',
        ];
    }
}
