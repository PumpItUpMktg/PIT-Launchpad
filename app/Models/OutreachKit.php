<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The copy a person pastes to get one link (§ Links), drafted once and kept. `payload` holds the NAP
 * verbatim from our data, the link URL, and the model's descriptions / categories / pitch.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $location_id
 * @property string $target_kind directory | prospect
 * @property string $target_domain
 * @property array<string, mixed> $payload
 * @property string|null $model
 * @property bool $model_ok
 * @property Carbon $drafted_at
 */
class OutreachKit extends Model
{
    use BelongsToSite, HasUlids;

    public const DIRECTORY = 'directory';

    public const PROSPECT = 'prospect';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'model_ok' => 'boolean',
            'drafted_at' => 'datetime',
        ];
    }
}
