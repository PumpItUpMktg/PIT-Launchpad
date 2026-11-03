<?php

namespace App\Models;

use App\Enums\ColdContactStage;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One contact in the cold outreach pipeline (import → Bird verify → Instantly warm-up → Brevo).
 * `email` is kept exactly as imported (Bird treats the local part as case-sensitive); `email_key`
 * is the lower-cased dedupe key, unique per site.
 *
 * @property string $id
 * @property string $site_id
 * @property string $email
 * @property string $email_key
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company
 * @property string|null $job_title
 * @property string|null $phone
 * @property string|null $website
 * @property array<string, mixed>|null $custom
 * @property string|null $source
 * @property ColdContactStage $stage
 * @property string|null $verify_result
 * @property int|null $verify_confidence
 * @property list<string>|null $verify_flags
 * @property string|null $verify_reason
 * @property string|null $did_you_mean
 * @property Carbon|null $verified_at
 * @property string|null $instantly_lead_id
 * @property string|null $instantly_campaign_id
 * @property Carbon|null $warming_started_at
 * @property int|null $instantly_status
 * @property int $opens
 * @property int $replies
 * @property int $clicks
 * @property int|null $interest_status
 * @property Carbon|null $last_event_at
 * @property Carbon|null $warm_at
 * @property string|null $warm_reason
 * @property string|null $disqualified_reason
 * @property string|null $brevo_contact_id
 * @property Carbon|null $promoted_at
 * @property string|null $last_error
 */
class ColdContact extends Model
{
    use BelongsToSite, HasUlids;

    protected $guarded = [];

    public static function keyFor(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stage' => ColdContactStage::class,
            'custom' => 'array',
            'verify_flags' => 'array',
            'verify_confidence' => 'integer',
            'verified_at' => 'datetime',
            'warming_started_at' => 'datetime',
            'instantly_status' => 'integer',
            'opens' => 'integer',
            'replies' => 'integer',
            'clicks' => 'integer',
            'interest_status' => 'integer',
            'last_event_at' => 'datetime',
            'warm_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }
}
