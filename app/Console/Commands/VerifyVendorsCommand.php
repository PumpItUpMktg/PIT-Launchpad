<?php

namespace App\Console\Commands;

use App\Integrations\Claude\AnthropicClaudeClient;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\Fal\FalClient;
use App\Integrations\News\NewsProvider;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live vendor-path verification (diagnostic). Fires each committed vendor path
 * once against the REAL API and reports LIVE / SKIP / FAIL:
 *
 *  - Claude     — one minimal Haiku completion (the real Anthropic SDK path).
 *  - fal        — one cheapest-possible image (512x512). One image maximum.
 *  - R2         — put a tiny object, read it back, delete it.
 *  - DataForSEO — the zero-cost account endpoint (appendix/user_data): confirms
 *                 Basic auth + connectivity without spending, reports balance.
 *  - News       — the configured §6a source (GDELT default / NewsAPI): one trivial
 *                 recent query (maxrecords=1), confirms connectivity + parse.
 *
 * It reads the same env vars as the app, no-ops with a clear message when a key
 * is absent (safe to run before keys land), never writes to domain tables, and
 * prints a 3-line LIVE/FAIL summary. This makes real outbound calls — it is
 * console-only and must never be wired into CI / test runs.
 */
class VerifyVendorsCommand extends Command
{
    protected $signature = 'launchpad:verify-vendors';

    protected $description = 'Fire each committed vendor path (Claude/fal/R2/DataForSEO/News) once against LIVE and report LIVE/SKIP/FAIL. Makes real outbound calls — never run in CI.';

    public function handle(): int
    {
        $this->warn('Live vendor verification — real outbound calls: one Claude completion, up to one fal image, one R2 object (put → get → delete), one zero-cost DataForSEO account read, one trivial news query.');
        $this->newLine();

        $results = [
            $this->verifyClaude(),
            $this->verifyFal(),
            $this->verifyR2(),
            $this->verifyDataForSeo(),
            $this->verifyNews(),
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
            // Mirror the real scoring call site: Haiku with no extended thinking.
            $text = (new AnthropicClaudeClient($key, $model, 256, thinking: null))
                ->complete('Reply with the single word: ok');

            return trim($text) !== ''
                ? "Claude : LIVE — model={$model} (scoring path, no thinking), completion returned"
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

    private function verifyDataForSeo(): string
    {
        $login = (string) config('services.dataforseo.login');
        $password = (string) config('services.dataforseo.password');
        if ($login === '' || $password === '') {
            return 'DFSEO  : SKIP — DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD not set';
        }

        try {
            // Zero-cost account read: confirms Basic auth + connectivity, no spend.
            $client = new DataForSeoClient(
                app(Http::class),
                $login,
                $password,
                (string) config('services.dataforseo.base_url', 'https://api.dataforseo.com'),
                (int) config('services.dataforseo.timeout', 30),
            );

            $data = $client->userData();
            $balance = $data['balance'] !== null ? '$'.number_format($data['balance'], 2) : 'n/a';

            return "DFSEO  : LIVE — appendix/user_data ok (login={$data['login']}, balance={$balance})";
        } catch (Throwable $e) {
            return 'DFSEO  : FAIL — '.$this->short($e);
        }
    }

    private function verifyNews(): string
    {
        $provider = (string) config('services.news.provider', 'gdelt');

        if ($provider === 'newsapi' && (string) config('services.news.key') === '') {
            return 'News   : SKIP — NEWS_PROVIDER=newsapi but NEWSAPI_KEY not set';
        }

        try {
            // One trivial, recent, single-record query against the bound source.
            // GDELT rejects very short windows ("Timespan is too short"), so use a
            // comfortably-valid recent window for the probe.
            $since = new DateTimeImmutable('-1 day');
            $items = app(NewsProvider::class)->fetch(['query' => 'plumbing', 'max' => 1], $since);

            return "News   : LIVE — provider={$provider}, ".count($items).' item(s) returned';
        } catch (Throwable $e) {
            return 'News   : FAIL — '.$this->short($e);
        }
    }

    private function short(Throwable $e): string
    {
        return Str::limit(str_replace(["\r", "\n"], ' ', $e->getMessage()), 160);
    }
}
