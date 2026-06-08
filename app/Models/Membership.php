<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
        ];
    }
}
