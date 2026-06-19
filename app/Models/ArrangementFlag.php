<?php

namespace App\Models;

use App\Enums\ArrangeFlagType;
use App\Interview\Arrange\ArrangeFlag;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted auto-arrange flag (the durable twin of {@see ArrangeFlag}).
 * Written by a run (replace-on-run, per site) so the prune surface can render accept/dismiss
 * without re-running the embedding passes. Site-scoped by explicit `site_id` (operator panel
 * queries cross-tenant), not the global SiteScope.
 *
 * @property string $id
 * @property string $site_id
 * @property string|null $spoke_id
 * @property ArrangeFlagType $type
 * @property string $message
 * @property array<int, array{id: string, name: string, score: float}>|null $candidates
 * @property float|null $score
 */
class ArrangementFlag extends Model
{
    use HasUlids;

    protected $table = 'arrange_flags';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ArrangeFlagType::class,
            'candidates' => 'array',
            'score' => 'float',
        ];
    }
}
