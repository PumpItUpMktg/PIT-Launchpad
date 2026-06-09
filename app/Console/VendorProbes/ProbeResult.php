<?php

namespace App\Console\VendorProbes;

use Illuminate\Support\Str;
use Throwable;

/**
 * The result of a vendor probe — a status plus a one-line detail. Knows how to
 * render itself as an aligned report line ("Claude     : LIVE — …").
 */
final class ProbeResult
{
    private function __construct(
        public readonly ProbeStatus $status,
        public readonly string $detail,
    ) {}

    public static function live(string $detail): self
    {
        return new self(ProbeStatus::Live, $detail);
    }

    public static function ready(string $detail): self
    {
        return new self(ProbeStatus::Ready, $detail);
    }

    public static function skip(string $detail): self
    {
        return new self(ProbeStatus::Skip, $detail);
    }

    public static function fail(string $detail): self
    {
        return new self(ProbeStatus::Fail, $detail);
    }

    /**
     * A FAIL distilled from a thrown error (single-line, bounded).
     */
    public static function failFrom(Throwable $e): self
    {
        return new self(ProbeStatus::Fail, Str::limit(str_replace(["\r", "\n"], ' ', $e->getMessage()), 160));
    }

    public function line(string $label, int $pad = 10): string
    {
        return sprintf('%-'.$pad.'s : %s — %s', $label, $this->status->value, $this->detail);
    }
}
