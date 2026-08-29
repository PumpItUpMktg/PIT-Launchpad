<?php

namespace App\Models;

use App\Citations\CitationApplicability;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\TenantDirectoryExclusionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's "not relevant" ruling on a directory (§ Citations). Tenant-scoped (BelongsToSite); applies to
 * every location the tenant owns — {@see CitationApplicability} drops an excluded directory
 * from eligibility for all of them.
 *
 * @property Carbon|null $excluded_at
 */
class TenantDirectoryExclusion extends Model
{
    /** @use HasFactory<TenantDirectoryExclusionFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['excluded_at' => 'datetime'];
    }
}
