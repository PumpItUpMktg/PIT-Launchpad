<?php

namespace App\Models;

use App\Enums\WizardStep;
use Database\Factories\OnboardingStateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumable onboarding progress for a Site. The collected data lives in the §1
 * entities; this just tracks where the wizard is. Operator-level (cross-tenant
 * during setup), so it does not use the BelongsToSite global scope.
 *
 * @property WizardStep $current_step
 * @property list<string>|null $completed_steps
 */
class OnboardingState extends Model
{
    /** @use HasFactory<OnboardingStateFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isComplete(WizardStep $step): bool
    {
        return in_array($step->value, $this->completed_steps ?? [], true);
    }

    public function markComplete(WizardStep $step): void
    {
        $completed = $this->completed_steps ?? [];
        if (! in_array($step->value, $completed, true)) {
            $completed[] = $step->value;
        }

        $next = collect(WizardStep::ordered())->first(fn (WizardStep $s) => ! in_array($s->value, $completed, true));

        $this->update([
            'completed_steps' => $completed,
            'current_step' => ($next ?? WizardStep::Launch)->value,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_step' => WizardStep::class,
            'completed_steps' => 'array',
            'launched_at' => 'datetime',
        ];
    }
}
