<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job type applied to a {@see Job} (§3) — the SNAPSHOT row in `job_capture_job_type`. `label` and `slug`
 * are copied at capture time and `job_type_id` is a SOFT reference back to the {@see JobType} vocabulary
 * (nullable, un-constrained), so a job keeps its types even after a silo regenerates or the vocabulary row
 * is removed. Not site-scoped directly — it is always reached through its owning Job (which is).
 *
 * @property string $id
 * @property string $job_capture_id
 * @property string|null $job_type_id soft reference to the JobType vocabulary
 * @property string $label snapshot label at capture time
 * @property string $slug snapshot slug at capture time
 */
class JobTypeAssignment extends Model
{
    use HasUlids;

    protected $table = 'job_capture_job_type';

    protected $guarded = [];

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_capture_id');
    }

    /** The vocabulary row this was snapshotted from — a soft reference; null after a silo rebuild.
     *
     * @return BelongsTo<JobType, $this>
     */
    public function jobType(): BelongsTo
    {
        return $this->belongsTo(JobType::class);
    }
}
