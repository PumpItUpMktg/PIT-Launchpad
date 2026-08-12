<?php

namespace App\JobCapture\Enhancement;

use App\Enums\JobStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Job;

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
 */
final class JobEnhancer
{
    private const SYSTEM = 'You are an expert local-SEO copywriter for a home-services company. You write specific, '
        .'concrete web copy documenting a REAL completed job — never generic filler, never templated boilerplate. '
        .'Ground every sentence in the actual job details provided. Reply with ONLY a JSON object, no prose around it.';

    public function __construct(private readonly ClaudeClient $claude) {}

    public function enhance(Job $job): void
    {
        $source = trim((string) ($job->source_description ?? '')) ?: trim((string) ($job->raw_description ?? ''));
        if ($source === '') {
            throw new JobEnhancementException("Job {$job->id} has no description to enhance.");
        }

        $job->forceFill(['status' => JobStatus::Enhancing])->save();

        $photoCount = is_array($job->photos) ? count($job->photos) : 0;
        $data = $this->parse($this->claude->complete($this->prompt($job, $source, $photoCount), self::SYSTEM));

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
        ])->save();
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
