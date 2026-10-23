<?php

namespace App\JobCapture\Enhancement;

use App\Enums\JobStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Job;
use App\Models\JobType;
use App\Models\JobTypeAssignment;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * Turns a captured job's operator-editable `source_description` into a publish-ready write-up (§7): the
 * enhanced description PLUS a post title, meta description, and one alt text per photo — all from ONE model
 * call. The three-field model is honoured: `raw_description` is never touched, `source_description` is what
 * every call reads (so an operator edit + re-enhance corrects a bad pass without compounding drift), and
 * `enhanced_description` is overwritten each run.
 *
 * Real job facts (job type, city, county, the tech's own notes, brand) are varied INTO the prompt rather
 * than a generic instruction — the biggest lever against the near-duplicate / doorway-page pattern Google
 * penalises when many terse inputs are enhanced by one template. Best-effort but honest: if the call yields
 * no description the job is NOT advanced (it stays re-enhanceable) and a {@see JobEnhancementException} is
 * thrown for the caller to surface.
 *
 * **Facts-only mode.** A job with no notes at all (a previous job imported from a CRM: name, address, date,
 * service) is written from what is true — the service and the catalog's own account of what it involves,
 * the town and county, the season — under strict rules: describe what this kind of visit covers and why it
 * matters for a home there and then; never invent findings, parts, measurements, causes, prices or quotes.
 * Each such job takes an angle from a rotating set so the pages read differently, and the result is checked
 * against the last facts-only write-ups for the same service on the site: too alike → one retry on a
 * different angle; still too alike → the job is flagged `similar_to_job_id` and cannot be approved until
 * it is edited or re-enhanced. Nothing is embellished; the stretch is in the angle, not in claims.
 */
final class JobEnhancer
{
    private const SYSTEM = 'You are an expert local-SEO copywriter for a home-services company. You write specific, '
        .'concrete web copy documenting a REAL completed job — never generic filler, never templated boilerplate. '
        .'Ground every sentence in the actual job details provided. Reply with ONLY a JSON object, no prose around it.';

    private const SYSTEM_FACTS = 'You are a careful local-SEO copywriter for a home-services company. You are writing the '
        .'page for a completed job of which ONLY the service, the town and the date are known — there are no notes. '
        .'You describe what this kind of visit covers and why it matters for a home in that town at that time of year. '
        .'You NEVER invent what was found or done on this particular job: no parts, model numbers, measurements, '
        .'causes, prices, timings, or anything the homeowner said. Plain, useful, specific to the service and the place. '
        .'Reply with ONLY a JSON object, no prose around it.';

    /** The angles a facts-only write-up rotates through — the lever for variety without claims. */
    public const ANGLES = ['prevents', 'covers', 'season', 'watch_for', 'area'];

    private const ANGLE_BRIEFS = [
        'prevents' => 'Angle: what this service PREVENTS — the water, damage and cost a home avoids by having it done; open on the risk, close on the outcome.',
        'covers' => 'Angle: what the visit COVERS, step by step — what a technician checks, tests and does on this kind of job; a reader should finish knowing what to expect.',
        'season' => 'Angle: the SEASON — why this work matters at this time of year in this area (rain, snowmelt, storms, freezes, dry spells), and what the timing buys the homeowner.',
        'watch_for' => 'Angle: what a homeowner should WATCH FOR — the signs that call for this service, and what the service does about each; practical, no scare tactics.',
        'area' => 'Angle: the AREA — how homes, basements, soils and water tables around this town bear on this service; speak to a neighbour, not a search engine.',
    ];

    /** Facts-only write-ups for the same service on the same site that a new one is checked against. */
    private const SIMILARITY_WINDOW = 25;

    /** Word-trigram Jaccard above this reads as the same page. */
    private const SIMILARITY_LIMIT = 0.35;

    public function __construct(private readonly ClaudeClient $claude) {}

    public function enhance(Job $job): void
    {
        $source = trim((string) ($job->source_description ?? '')) ?: trim((string) ($job->raw_description ?? ''));
        $factsOnly = $source === '';
        if ($factsOnly && ! self::canWriteFromFacts($job)) {
            throw new JobEnhancementException("Job {$job->id} has no description and no service to write from.");
        }

        $job->forceFill(['status' => JobStatus::Enhancing])->save();

        $photoCount = is_array($job->photos) ? count($job->photos) : 0;
        $angle = $factsOnly ? $this->angleFor($job) : null;

        try {
            $data = $factsOnly
                ? $this->parse($this->claude->complete($this->factsPrompt($job, $angle ?? self::ANGLES[0], $photoCount), self::SYSTEM_FACTS))
                : $this->parse($this->claude->complete($this->prompt($job, $source, $photoCount), self::SYSTEM));
            $similar = null;
            if ($factsOnly) {
                $description = trim((string) ($data['description'] ?? ''));
                $similar = $description !== '' ? $this->mostSimilar($job, $description) : null;
                if ($similar !== null) {
                    // Too like an earlier page: one retry on the next angle, structured differently.
                    $angle = self::nextAngle($angle ?? self::ANGLES[0]);
                    $retry = $this->parse($this->claude->complete($this->factsPrompt($job, $angle, $photoCount, true), self::SYSTEM_FACTS));
                    $retryDescription = trim((string) ($retry['description'] ?? ''));
                    if ($retryDescription !== '') {
                        $data = $retry;
                        $similar = $this->mostSimilar($job, $retryDescription);
                    }
                }
            }
        } catch (\Throwable $e) {
            // The model call errored or timed out — revert to Captured so the job stays visible and
            // re-enhanceable in review rather than stranded at Enhancing (which the queue doesn't surface
            // as a decision-ready row). Then re-throw for the caller / failed_jobs to record.
            $job->forceFill(['status' => JobStatus::Captured])->save();

            throw $e;
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            // No write-up → do not advance; leave it re-enhanceable and surface the failure.
            $job->forceFill(['status' => JobStatus::Captured])->save();

            throw new JobEnhancementException("Enhancement produced no description for job {$job->id}.");
        }

        $photos = is_array($job->photos) ? $job->photos : [];
        $alts = array_values(array_filter((array) ($data['alts'] ?? []), 'is_string'));
        foreach ($photos as $i => $photo) {
            if (isset($alts[$i]) && trim($alts[$i]) !== '') {
                $photos[$i]['alt'] = trim($alts[$i]);
            }
        }

        $job->forceFill([
            'enhanced_description' => $description,
            'post_title' => trim((string) ($data['title'] ?? '')) ?: $job->post_title,
            'meta_description' => mb_substr(trim((string) ($data['meta'] ?? '')), 0, 320) ?: $job->meta_description,
            'photos' => $photos ?: null,
            'status' => JobStatus::Review,
            'facts_only' => $factsOnly,
            'write_up_angle' => $factsOnly ? $angle : null,
            'similar_to_job_id' => $factsOnly ? $similar : null,
        ])->save();
    }

    /** A facts-only write-up needs at least a service (the town and date make it better, not possible). */
    public static function canWriteFromFacts(Job $job): bool
    {
        return $job->jobTypes()->exists();
    }

    /** The prompt — real job facts varied in, an explicit anti-templating instruction, JSON contract. */
    private function prompt(Job $job, string $source, int $photoCount): string
    {
        $brand = trim((string) ($job->site->brand_name ?? '')) ?: 'the company';
        $types = $job->jobTypes->pluck('label')->filter()->implode(', ');
        // Geography FKs are nullable (an un-resolved / walk-in job) — guard on the id, not the relation.
        $city = $job->job_city_id !== null ? trim((string) $job->city->name) : '';
        $county = $job->job_county_id !== null ? trim((string) $job->county->name) : '';
        $where = trim($city.($county !== '' ? ($city !== '' ? ', ' : '').$county : ''));

        $facts = array_filter([
            "Company: {$brand}",
            $types !== '' ? "Job type(s): {$types}" : null,
            $where !== '' ? "Location served: {$where}" : null,
            "Technician's notes (the source of truth — do not contradict): {$source}",
            $photoCount > 0 ? "Photos on this job: {$photoCount} (write one alt text each, describing what a viewer sees)." : null,
        ]);

        return implode("\n", [
            'Write the web copy for this completed job. Make it specific to THESE details so it never reads as a',
            'templated variation of another job on a similar page.',
            '',
            implode("\n", $facts),
            '',
            'Return ONLY this JSON object:',
            '{',
            '  "title": "an original, specific post title (~55-65 chars, no company name stuffing)",',
            '  "meta": "a compelling meta description (~150 chars)",',
            '  "description": "2-3 short paragraphs in the company voice, concrete and grounded in the notes",',
            $photoCount > 0
                ? '  "alts": ['.implode(', ', array_fill(0, $photoCount, '"alt text"')).']'
                : '  "alts": []',
            '}',
        ]);
    }

    /** The facts-only prompt: service (+ the catalog's account of it), place, season, brand, one angle, strict rules. */
    private function factsPrompt(Job $job, string $angle, int $photoCount, bool $retry = false): string
    {
        $brand = trim((string) ($job->site->brand_name ?? '')) ?: 'the company';
        $types = $job->jobTypes;
        $city = $job->job_city_id !== null ? trim((string) $job->city->name) : '';
        $county = $job->job_county_id !== null ? trim((string) $job->county->name) : '';
        $where = trim($city.($county !== '' ? ($city !== '' ? ', ' : '').$county.' County' : ''));
        $when = $job->performed_at;
        $season = $when !== null ? self::season((int) $when->month).' '.$when->year.' ('.$when->format('F').')' : '';

        $serviceLines = [];
        foreach ($types as $type) {
            $label = trim((string) $type->label);
            $catalog = self::catalogFor($type);
            $serviceLines[] = "Service: {$label}".($catalog !== '' ? " — {$catalog}" : '');
        }

        $facts = array_filter([
            "Company: {$brand}",
            ...$serviceLines,
            $where !== '' ? "Town served: {$where}" : null,
            $season !== '' ? "When: {$season}" : null,
            $photoCount > 0 ? "Photos on this job: {$photoCount} (write one alt text each, describing what a viewer sees)." : null,
        ]);

        $lengths = ['prevents' => '130-170', 'covers' => '160-200', 'season' => '120-160', 'watch_for' => '150-190', 'area' => '130-170'];

        return implode("\n", [
            'Write the web page for a completed job. There are NO notes from the job: only the service, the town and the date are known.',
            'Write about what this kind of visit covers and why it matters for a home in this town at this time of year.',
            '',
            implode("\n", $facts),
            '',
            self::ANGLE_BRIEFS[$angle] ?? self::ANGLE_BRIEFS['covers'],
            'Length: '.($lengths[$angle] ?? '140-180').' words, 2-3 short paragraphs. '.($retry ? 'Use a DIFFERENT structure from a standard job write-up: open with a question or a season, vary paragraph lengths, do not begin with the company name.' : 'Do not begin with the company name.'),
            '',
            'Rules — every one is a hard rule:',
            '- Say only what this kind of service involves in general; write "a sump pump maintenance visit covers…", never "we replaced…" or "we found…".',
            '- Never invent parts, brands, model numbers, measurements, causes, prices, timings, warranties, or anything the homeowner said or felt.',
            '- Name the town and county naturally, once or twice, not in every sentence; no keyword stuffing, no list of nearby towns.',
            '- No superlatives, no guarantees, no exclamation marks, no "trusted / leading / best".',
            '- The title names the service and the town (~55-65 chars); the meta description is ~150 chars and makes the same honest promise as the page.',
            '',
            'Return ONLY this JSON object:',
            '{',
            '  "title": "the post title",',
            '  "meta": "the meta description",',
            '  "description": "the page copy",',
            $photoCount > 0 ? '  "alts": ['.implode(', ', array_fill(0, $photoCount, '"alt text"')).']' : '  "alts": []',
            '}',
        ]);
    }

    /** The catalog's own account of the service — scope, signs — when the applied type mirrors a catalog row. */
    private static function catalogFor(JobTypeAssignment $type): string
    {
        $vocab = $type->job_type_id !== null ? JobType::withoutGlobalScope(SiteScope::class)->find($type->job_type_id) : null;
        if ($vocab === null || $vocab->service_id === null) {
            return '';
        }
        $service = Service::withoutGlobalScope(SiteScope::class)->find($vocab->service_id);
        if ($service === null) {
            return '';
        }
        $parts = [];
        $short = trim((string) $service->short_description);
        if ($short !== '') {
            $parts[] = $short;
        }
        $scope = array_values(array_filter(array_map('strval', is_array($service->scope_items) ? $service->scope_items : [])));
        if ($scope !== []) {
            $parts[] = 'what it includes: '.implode('; ', array_slice($scope, 0, 8));
        }
        $signs = array_values(array_filter(array_map('strval', is_array($service->symptoms) ? $service->symptoms : [])));
        if ($signs !== []) {
            $parts[] = 'signs a home needs it: '.implode('; ', array_slice($signs, 0, 6));
        }

        return Str::limit(implode('. ', $parts), 900, '');
    }

    /** The next angle in the rotation for this site: the count of facts-only write-ups so far decides it. */
    private function angleFor(Job $job): string
    {
        $n = Job::withoutGlobalScopes()->where('site_id', $job->site_id)->where('facts_only', true)->whereNotNull('write_up_angle')->whereKeyNot($job->id)->count();

        return self::ANGLES[$n % count(self::ANGLES)];
    }

    public static function nextAngle(string $angle): string
    {
        $i = array_search($angle, self::ANGLES, true);

        return self::ANGLES[(($i === false ? 0 : $i) + 1) % count(self::ANGLES)];
    }

    /** The id of the recent facts-only write-up (same site, same service) this one reads too much like, or null. */
    private function mostSimilar(Job $job, string $description): ?string
    {
        $labels = $job->jobTypes->pluck('label')->filter()->all();
        $q = Job::withoutGlobalScopes()->where('site_id', $job->site_id)->where('facts_only', true)->whereKeyNot($job->id)
            ->whereNotNull('enhanced_description')->orderByDesc('updated_at')->limit(self::SIMILARITY_WINDOW);
        if ($labels !== []) {
            $q->whereHas('jobTypes', fn ($t) => $t->whereIn('label', $labels));
        }
        $mine = self::shingles($description);
        if ($mine === []) {
            return null;
        }
        $worst = null;
        $worstScore = 0.0;
        foreach ($q->get(['id', 'enhanced_description']) as $other) {
            $theirs = self::shingles((string) $other->enhanced_description);
            if ($theirs === []) {
                continue;
            }
            $inter = count(array_intersect_key($mine, $theirs));
            $union = count($mine) + count($theirs) - $inter;
            $score = $union > 0 ? $inter / $union : 0.0;
            if ($score > $worstScore) {
                $worstScore = $score;
                $worst = (string) $other->id;
            }
        }

        return $worstScore > self::SIMILARITY_LIMIT ? $worst : null;
    }

    /** @return array<string, true> word trigrams */
    public static function shingles(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => $w !== ''));
        $out = [];
        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) {
            $out[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $out;
    }

    private static function season(int $month): string
    {
        return match (true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'autumn',
            default => 'winter',
        };
    }

    /**
     * Tolerant JSON parse: strip ``` fences and take the outermost {...} before decoding, so a model that
     * wraps or pads the object still decodes. Returns [] on any failure (the caller treats an empty
     * description as "no draft").
     *
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($response)) ?? '');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
