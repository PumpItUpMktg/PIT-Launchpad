<?php

namespace App\Http\Controllers\JobCapture;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateTechDevice;
use App\JobCapture\Auth\DeviceAuthenticator;
use App\JobCapture\Capture\CaptureData;
use App\JobCapture\Capture\CaptureIntake;
use App\JobCapture\Types\JobTypeVocabulary;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\TechDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The capture-PWA JSON API (§5). Two unauthenticated auth endpoints (request a login code, redeem it for a
 * device token) and two device-authenticated endpoints (list this tech's jobs, submit a captured job).
 * Photos arrive base64-encoded (the PWA downscales in-browser and holds them in its offline queue). The
 * device-authenticated routes run behind {@see AuthenticateTechDevice}, which has
 * already bound the current tenant from the token.
 */
class CaptureController extends Controller
{
    /** POST /capture/api/auth/request-code — issue a login code for a device (magic-link entry point). */
    public function requestCode(Request $request, DeviceAuthenticator $auth): JsonResponse
    {
        $device = $this->findDevice($request->input('device'));
        if ($device === null || ! $device->isActive()) {
            return response()->json(['message' => 'Unknown device.'], 404);
        }

        $code = $auth->issueLoginCode($device);

        // Delivery (SMS / email magic link) is wired later; the code is only echoed in local/testing.
        $payload = ['message' => 'Code sent.'];
        if (app()->environment('local', 'testing')) {
            $payload['code'] = $code;
        }

        return response()->json($payload);
    }

    /** POST /capture/api/auth/redeem — exchange a login code for a long-lived device token. */
    public function redeem(Request $request, DeviceAuthenticator $auth): JsonResponse
    {
        $device = $this->findDevice($request->input('device'));
        $token = $device === null ? null : $auth->redeemLoginCode($device, (string) $request->input('code'));

        if ($token === null || $device === null) {
            return response()->json(['message' => 'Invalid code.'], 401);
        }

        return response()->json(['token' => $token, 'tech' => $device->name]);
    }

    /** GET /capture/api/jobs — this tech's actionable jobs (assigned + captured-today), newest first. */
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $jobs = Job::query()
            ->where('tech_id', $device->id)
            ->whereIn('status', [JobStatus::Assigned->value, JobStatus::Captured->value])
            ->latest()
            ->get()
            ->map(fn (Job $job): array => $this->present($job))
            ->all();

        return response()->json(['jobs' => $jobs]);
    }

    /**
     * GET /capture/api/options — the site's pickable service list for the capture form (synced from the
     * Service catalog), so the tech tags a job with the services performed instead of free-typing them.
     */
    public function options(Request $request, JobTypeVocabulary $vocabulary): JsonResponse
    {
        $device = $this->device($request);

        return response()->json(['job_types' => $vocabulary->options((string) $device->site_id)]);
    }

    /** POST /capture/api/jobs — submit a captured job. */
    public function store(Request $request, CaptureIntake $intake): JsonResponse
    {
        $device = $this->device($request);

        $validated = $request->validate([
            'client_name_full' => ['nullable', 'string', 'max:255'],
            'client_name_display' => ['nullable', 'string', 'max:255'],
            'raw_description' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            // A past job: the typed street address (geocoded server-side, preferred over the device fix)
            // and the date the work was really done — never in the future.
            'address' => ['nullable', 'string', 'max:255'],
            'performed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'primary_photo_index' => ['nullable', 'integer', 'min:0', 'max:2'],
            'photos' => ['array', 'max:3'],
            'photos.*.data' => ['required', 'string'],
            'photos.*.filename' => ['nullable', 'string', 'max:255'],
            'job_types' => ['array', 'max:3'],
            'job_types.*.label' => ['required', 'string', 'max:255'],
            'job_types.*.slug' => ['required', 'string', 'max:255'],
            'job_types.*.job_type_id' => ['nullable', 'string'],
        ]);

        $photos = [];
        foreach ($validated['photos'] ?? [] as $photo) {
            $bytes = base64_decode((string) $photo['data'], true);
            if ($bytes === false) {
                continue; // skip an undecodable slot rather than fail the whole capture
            }
            $entry = ['bytes' => $bytes];
            if (isset($photo['filename'])) {
                $entry['filename'] = (string) $photo['filename'];
            }
            $photos[] = $entry;
        }

        $job = $intake->capture($device, new CaptureData(
            clientNameFull: $validated['client_name_full'] ?? null,
            clientNameDisplay: $validated['client_name_display'] ?? null,
            rawDescription: $validated['raw_description'] ?? null,
            lat: isset($validated['lat']) ? (float) $validated['lat'] : null,
            lng: isset($validated['lng']) ? (float) $validated['lng'] : null,
            photos: $photos,
            jobTypes: $validated['job_types'] ?? [],
            primaryPhotoIndex: (int) ($validated['primary_photo_index'] ?? 0),
            address: isset($validated['address']) ? trim((string) $validated['address']) : null,
            performedAt: $validated['performed_at'] ?? null,
        ));

        // `placed`: whether the job has a point (device fix or a geocoded address). False means the office
        // sets the location in review — the phone says so instead of implying it is done.
        return response()->json([
            'id' => $job->id,
            'status' => $job->status->value,
            'placed' => $job->lat_true !== null && $job->lng_true !== null,
        ], 201);
    }

    /** A device by id, resolved cross-tenant (auth happens before a site is bound). */
    private function findDevice(mixed $id): ?TechDevice
    {
        $id = is_string($id) ? trim($id) : '';

        return $id === '' ? null : TechDevice::withoutGlobalScope(SiteScope::class)->find($id);
    }

    /** The device the middleware authenticated onto this request. */
    private function device(Request $request): TechDevice
    {
        $device = $request->attributes->get('tech_device');
        abort_unless($device instanceof TechDevice, 401);

        return $device;
    }

    /** @return array<string, mixed> */
    private function present(Job $job): array
    {
        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'client' => $job->client_name_display,
            'city' => $job->city?->name,
            'job_types' => $job->jobTypes->pluck('label')->all(),
            'photo_count' => is_array($job->photos) ? count($job->photos) : 0,
            'captured_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
