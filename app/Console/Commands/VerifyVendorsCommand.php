<?php

namespace App\Console\Commands;

use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\Fal\FalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live vendor-path verification (diagnostic). Fires each committed vendor path
 * once against the REAL API and reports LIVE / SKIP / FAIL:
 *
 *  - Claude — one minimal Haiku completion (the real Anthropic SDK path).
 *  - fal    — one cheapest-possible image (512x512). One image maximum.
 *  - R2     — put a tiny object, read it back, delete it.
 *
 * It reads the same env vars as the app, no-ops with a clear message when a key
 * is absent (safe to run before keys land), never writes to domain tables, and
 * prints a 3-line LIVE/FAIL summary. This makes real outbound calls — it is
 * console-only and must never be wired into CI / test runs.
 */
class VerifyVendorsCommand extends Command
{
    protected $signature = 'launchpad:verify-vendors';

    protected $description = 'Fire each committed vendor path (Claude/fal/R2) once against LIVE and report LIVE/SKIP/FAIL. Makes real outbound calls — never run in CI.';

    public function handle(): int
    {
        $this->warn('Live vendor verification — real outbound calls: one Claude completion, up to one fal image, one R2 object (put → get → delete).');
        $this->newLine();

        $results = [
            $this->verifyClaude(),
            $this->verifyFal(),
            $this->verifyR2(),
        ];

        foreach ($results as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->info('--- LIVE/FAIL summary ---');
        foreach ($results as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function verifyClaude(): string
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            return 'Claude : SKIP — ANTHROPIC_API_KEY not set';
        }

        $model = (string) config('services.anthropic.scoring_model', 'claude-haiku-4-5');

        try {
            $text = (new AnthropicClaudeClient($key, $model, 256))
                ->complete('Reply with the single word: ok');

            return trim($text) !== ''
                ? "Claude : LIVE — model={$model}, completion returned"
                : 'Claude : FAIL — completion was empty';
        } catch (Throwable $e) {
            return 'Claude : FAIL — '.$this->short($e);
        }
    }

    private function verifyFal(): string
    {
        $key = (string) config('services.fal.key');
        if ($key === '') {
            return 'fal    : SKIP — FAL_KEY not set';
        }

        try {
            $fal = app(FalClient::class);
            $image = $fal->generate(
                'a plain light gray test square, minimal, flat color',
                ['width' => 512, 'height' => 512],
            );

            return "fal    : LIVE — image returned ({$image->width}x{$image->height}, ".strlen($image->bytes).' bytes)';
        } catch (Throwable $e) {
            return 'fal    : FAIL — '.$this->short($e);
        }
    }

    private function verifyR2(): string
    {
        if ((string) config('filesystems.disks.r2.key') === '' || (string) config('filesystems.disks.r2.bucket') === '') {
            return 'R2     : SKIP — R2_ACCESS_KEY_ID / R2_BUCKET not set';
        }

        $object = 'verify-vendors/ping-'.Str::ulid().'.txt';
        $payload = 'launchpad-verify-vendors';

        try {
            $disk = Storage::disk('r2');
            $disk->put($object, $payload);
            $readback = $disk->get($object);

            $publicNote = (string) config('services.r2.public_url') !== ''
                ? ' (public URL: '.$disk->url($object).')'
                : ' (R2_PUBLIC_URL not set)';

            $disk->delete($object);

            return $readback === $payload
                ? 'R2     : LIVE — put/get/delete ok'.$publicNote
                : 'R2     : FAIL — readback mismatch';
        } catch (Throwable $e) {
            return 'R2     : FAIL — '.$this->short($e);
        }
    }

    private function short(Throwable $e): string
    {
        return Str::limit(str_replace(["\r", "\n"], ' ', $e->getMessage()), 160);
    }
}
