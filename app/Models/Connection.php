<?php

namespace App\Models;

use App\Enums\ConnectionProvider;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property ConnectionProvider $provider
 * @property array<string, mixed>|null $credentials
 * @property bool $compromised
 * @property string|null $compromised_reason
 * @property Carbon|null $exposed_at
 * @property Carbon|null $last_rotated_at
 */
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * Flag the credential as exposed (chat / screen / screenshot / shared doc).
     * Idempotent; records the reason and the first exposure time.
     */
    public function markCompromised(?string $reason = null): static
    {
        $this->compromised = true;
        $this->compromised_reason = $reason;
        $this->exposed_at ??= now();
        $this->save();

        return $this;
    }

    /**
     * Clear the compromised flag — only valid after a verified rotation, so
     * callers set last_rotated_at in the same transaction.
     */
    public function clearCompromised(): static
    {
        $this->compromised = false;
        $this->compromised_reason = null;
        $this->save();

        return $this;
    }

    /**
     * Whether this credential still fails the launch gate: compromised, never
     * rotated, or last rotated before it was exposed.
     */
    public function needsRotation(): bool
    {
        if ($this->compromised) {
            return true;
        }

        if ($this->last_rotated_at === null) {
            return true;
        }

        return $this->exposed_at !== null && $this->last_rotated_at->lt($this->exposed_at);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => ConnectionProvider::class,
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
            'last_rotated_at' => 'datetime',
            'compromised' => 'boolean',
            'exposed_at' => 'datetime',
        ];
    }
}
