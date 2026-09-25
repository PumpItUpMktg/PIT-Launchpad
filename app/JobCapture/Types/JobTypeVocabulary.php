<?php

namespace App\JobCapture\Types;

use App\Enums\JobTypeSource;
use App\Enums\ServiceSiloRole;
use App\Models\Job;
use App\Models\JobType;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use Illuminate\Support\Str;

/**
 * The tenant's pickable service list for Job Capture, kept in step with its Service catalog. Every surface
 * that offers "which service was this?" (the phone's chips, the operator add-job / edit forms, the CSV
 * import) reads {@see options()}, which first mirrors the catalog into `job_types` so a site never shows an
 * empty list. Free-typed labels are still allowed everywhere — {@see resolve()} links a label to its
 * vocabulary row when one matches (by slug, case-insensitive) and otherwise keeps it as a bare snapshot.
 *
 * Sync is idempotent and additive-safe: catalog services upsert their `service`-sourced row (a native row
 * with the same slug is adopted rather than duplicated — the site-scoped slug is unique), a removed service
 * drops only its own mirrored row, and hand-added native rows are never touched.
 */
final class JobTypeVocabulary
{
    /** Mirror the site's Service catalog into its job-type vocabulary. Returns the number of rows created. */
    public function sync(string $siteId): int
    {
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderByRaw('CASE silo_role WHEN ? THEN 0 ELSE 1 END', [ServiceSiloRole::Pillar->value])
            ->orderBy('name')
            ->get();

        $existing = JobType::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->get();
        $created = 0;
        $seenServiceIds = [];

        foreach ($services as $service) {
            $label = trim((string) $service->name);
            $slug = Str::slug($label);
            if ($label === '' || $slug === '') {
                continue;
            }
            $seenServiceIds[] = (string) $service->id;

            $row = $existing->first(fn (JobType $t): bool => $t->service_id === (string) $service->id)
                ?? $existing->first(fn (JobType $t): bool => $t->slug === $slug);

            if ($row === null) {
                $row = JobType::withoutGlobalScope(SiteScope::class)->create([
                    'site_id' => $siteId,
                    'label' => $label,
                    'slug' => $slug,
                    'service_id' => (string) $service->id,
                    'source' => JobTypeSource::Service,
                ]);
                $existing->push($row);
                $created++;

                continue;
            }

            // A renamed service keeps its row (the slug follows the name unless that would collide).
            $slugTaken = $existing->contains(fn (JobType $t): bool => $t->slug === $slug && $t->id !== $row->id);
            $row->forceFill([
                'label' => $label,
                'slug' => $slugTaken ? $row->slug : $slug,
                'service_id' => (string) $service->id,
                'source' => JobTypeSource::Service,
            ])->save();
        }

        // A service removed from the catalog takes its mirrored row with it; jobs keep their snapshots.
        foreach ($existing as $row) {
            if ($row->source === JobTypeSource::Service && $row->service_id !== null && ! in_array($row->service_id, $seenServiceIds, true)) {
                $row->delete();
            }
        }

        return $created;
    }

    /**
     * The pickable list for a site — synced from the catalog first, then every row (catalog + native),
     * alphabetical.
     *
     * @return list<array{id: string, label: string, slug: string}>
     */
    public function options(string $siteId): array
    {
        $this->sync($siteId);

        return JobType::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderBy('label')
            ->get()
            ->map(fn (JobType $t): array => ['id' => (string) $t->id, 'label' => (string) $t->label, 'slug' => (string) $t->slug])
            ->unique('slug')
            ->values()
            ->all();
    }

    /**
     * Normalize a submitted set of job types into snapshot rows: link each to its vocabulary row when the
     * slug matches (taking the vocabulary's canonical label), keep a free-typed one as-is, de-dupe by slug,
     * and cap at {@see Job::MAX_JOB_TYPES}. Syncs the catalog first so the link never depends on a screen
     * having been opened.
     *
     * @param  list<array{label?: mixed, slug?: mixed, job_type_id?: mixed}|string>  $types
     * @return list<array{label: string, slug: string, job_type_id: string|null}>
     */
    public function resolve(string $siteId, array $types): array
    {
        if ($types === []) {
            return [];
        }
        $this->sync($siteId);   // a CSV row or a phone capture may arrive before any screen has synced the list

        $vocabulary = JobType::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get()
            ->keyBy('slug');

        $out = [];
        foreach ($types as $type) {
            $label = trim((string) (is_array($type) ? ($type['label'] ?? '') : $type));
            $slug = is_array($type) ? trim((string) ($type['slug'] ?? '')) : '';
            $slug = $slug !== '' ? Str::slug($slug) : Str::slug($label);
            if ($label === '' || $slug === '' || isset($out[$slug])) {
                continue;
            }

            $row = $vocabulary->get($slug);
            $out[$slug] = [
                'label' => $row !== null ? (string) $row->label : $label,
                'slug' => $slug,
                'job_type_id' => $row !== null ? (string) $row->id : (is_array($type) && is_string($type['job_type_id'] ?? null) ? $type['job_type_id'] : null),
            ];
            if (count($out) >= Job::MAX_JOB_TYPES) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Replace a job's applied types with the given set (resolved against the vocabulary). Used by the
     * review edit — the job's snapshot rows are rewritten, nothing else changes.
     *
     * @param  list<array{label?: mixed, slug?: mixed, job_type_id?: mixed}|string>  $types
     */
    public function apply(Job $job, array $types): void
    {
        $resolved = $this->resolve((string) $job->site_id, $types);

        $job->jobTypes()->delete();
        foreach ($resolved as $type) {
            $job->jobTypes()->create($type);
        }
        $job->unsetRelation('jobTypes');
    }
}
