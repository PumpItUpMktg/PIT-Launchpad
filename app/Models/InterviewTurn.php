<?php

namespace App\Models;

use App\Enums\InterviewSection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of an owner interview (gathering relay): an assistant question (tagged with the
 * {@see InterviewSection} it probes), the operator-typed owner answer, or a free-form
 * operator note. Append-only in practice — the transcript is what extraction runs against.
 *
 * @property string $id
 * @property string $interview_id
 * @property string $role assistant | operator
 * @property string $content
 * @property string|null $section_tag
 */
class InterviewTurn extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }
}
