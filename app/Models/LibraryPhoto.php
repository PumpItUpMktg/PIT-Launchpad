<?php

namespace App\Models;

use App\JobCapture\Photos\JobPhotoStore;
use App\Publishing\TenantStorage;
use Database\Factories\LibraryPhotoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A reusable Job Capture photo owned by an Account (§ Job Capture). The source original lives in R2; attaching
 * it to a job copies the bytes through {@see JobPhotoStore}, which geotags a per-job copy
 * — so one library photo can carry many jobs, each stamped with its own approximate location. Account-scoped
 * (NOT site-scoped): the library spans every site under the account.
 *
 * @property string $r2_key
 * @property string $hash
 * @property array<int, string>|null $tags
 * @property string|null $label
 */
class LibraryPhoto extends Model
{
    /** @use HasFactory<LibraryPhotoFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The public R2/CDN URL of the source original. */
    public function url(): string
    {
        return Storage::disk(TenantStorage::DISK)->url($this->r2_key);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }
}
