<?php

namespace App\Models;

use App\Enums\InterviewStatus;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\InterviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An adaptive owner interview (gathering relay). The transcript ({@see InterviewTurn}) is the
 * permanent source of truth — extraction re-runs against it at any time, at any status. The
 * `coverage` map is the live per-section self-assessment (filled/thin/empty) driving the
 * operator's coverage meter. Tenant-scoped ({@see BelongsToSite}): the context-aware SiteScope
 * filters to the locked tenant in the panel and is a no-op in the cross-tenant lobby.
 *
 * @property string $id
 * @property string $site_id
 * @property InterviewStatus $status
 * @property array<string, string>|null $coverage section value => filled|thin|empty
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class Interview extends Model
{
    /** @use HasFactory<InterviewFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return HasMany<InterviewTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(InterviewTurn::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InterviewStatus::class,
            'coverage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
