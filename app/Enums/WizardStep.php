<?php

namespace App\Enums;

/**
 * The dependency-ordered onboarding steps. Hybrid authorship: the client
 * self-serves the factual buckets (identity → assets); the operator runs
 * account/WP setup, the voice interview, silo selection (later), and launch.
 */
enum WizardStep: string
{
    case Account = 'account';
    case Identity = 'identity';
    case ServiceCatalog = 'service_catalog';
    case Markets = 'markets';
    case Proof = 'proof';
    case Targets = 'targets';
    case Assets = 'assets';
    case Voice = 'voice';
    case SiloSelection = 'silo_selection';
    case Launch = 'launch';

    public function order(): int
    {
        return match ($this) {
            self::Account => 1,
            self::Identity => 2,
            self::ServiceCatalog => 3,
            self::Markets => 4,
            self::Proof => 5,
            self::Targets => 6,
            self::Assets => 7,
            self::Voice => 8,
            self::SiloSelection => 9,
            self::Launch => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account & WordPress',
            self::Identity => 'Identity',
            self::ServiceCatalog => 'Service Catalog',
            self::Markets => 'Markets & Geo',
            self::Proof => 'Proof',
            self::Targets => 'Targets & Conversion',
            self::Assets => 'Assets',
            self::Voice => 'Voice Interview',
            self::SiloSelection => 'Silo Selection',
            self::Launch => 'Review & Launch',
        };
    }

    /**
     * Factual buckets the client may self-serve (steps 2–7).
     */
    public function isClientStep(): bool
    {
        return in_array($this, [
            self::Identity, self::ServiceCatalog, self::Markets,
            self::Proof, self::Targets, self::Assets,
        ], true);
    }

    /**
     * Step 9 is gated on §4 + §6a and is only a wired placeholder here.
     */
    public function isPlaceholder(): bool
    {
        return $this === self::SiloSelection;
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $steps = self::cases();
        usort($steps, fn (self $a, self $b) => $a->order() <=> $b->order());

        return $steps;
    }
}
