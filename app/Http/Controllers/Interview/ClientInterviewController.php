<?php

namespace App\Http\Controllers\Interview;

use App\Enums\InterviewAudience;
use App\Enums\InterviewSection;
use App\Enums\InterviewStatus;
use App\Gathering\InterviewEngine;
use App\Http\Controllers\Controller;
use App\Interview\Invites\InterviewInvites;
use App\Models\Interview;
use App\Models\InterviewInvite;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The public, no-auth owner-interview surface (relay PR 2). Reached only by a multi-use, expiring, hashed
 * token ({@see InterviewInvites}); the token carries the tenant, which is bound for the request before
 * anything tenant-scoped is read. The page drives the SAME {@see InterviewEngine} and the SAME `Interview`
 * row the operator's Setup step shows — the owner's answers land as `owner` turns, the operator sees them
 * live. Plain Blade + POST forms (post/redirect/get); the model call happens in the POST, and a failure
 * after the answer was saved offers a retry rather than losing anything.
 *
 * What this page can do: answer, retry a missing question, finish. What it cannot: extract, skip a section,
 * add operator notes, reach any other tenant, or activate anything — extraction stays an operator action
 * and is draft-only by construction.
 */
class ClientInterviewController extends Controller
{
    public function __construct(private readonly InterviewInvites $invites, private readonly InterviewEngine $engine) {}

    public function show(string $token): View
    {
        $bound = $this->bind($token);
        if ($bound === null) {
            return view('interview.expired');
        }
        [$invite, $site] = $bound;

        $interview = $this->interviewFor($invite, $site);
        $brand = $this->brand($site);

        if ($interview->status !== InterviewStatus::InProgress) {
            return view('interview.done', ['brand' => $brand, 'site' => $site]);
        }

        $turns = $interview->turns()->get();
        $ownerAnswers = $turns->where('role', 'owner')->count();
        $last = $turns->last();

        return view('interview.show', [
            'token' => $token,
            'brand' => $brand,
            'site' => $site,
            'turns' => $turns,
            'question' => $last !== null && $last->role === 'assistant' ? $last : null,
            'awaiting' => $last === null || $last->role !== 'assistant',
            'answered' => $ownerAnswers,
            'returning' => $ownerAnswers > 0,
            'progress' => $this->progress($interview),
        ]);
    }

    public function answer(Request $request, string $token): RedirectResponse|View
    {
        $bound = $this->bind($token);
        if ($bound === null) {
            return view('interview.expired');
        }
        [$invite, $site] = $bound;

        $validated = $request->validate(['answer' => ['required', 'string', 'max:4000']]);

        $interview = $this->interviewFor($invite, $site);
        if ($interview->status !== InterviewStatus::InProgress) {
            return redirect()->route('interview.show', ['token' => $token]);
        }

        // The answer persists BEFORE the model call (inside the engine), so a failed question never loses it;
        // the page then shows "your answer is saved" with a retry.
        try {
            $this->engine->answer($interview, (string) $validated['answer'], InterviewAudience::Owner);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('interview.show', ['token' => $token]);
    }

    public function retry(string $token): RedirectResponse|View
    {
        $bound = $this->bind($token);
        if ($bound === null) {
            return view('interview.expired');
        }
        [$invite, $site] = $bound;

        $interview = $this->interviewFor($invite, $site);
        if ($interview->status === InterviewStatus::InProgress) {
            try {
                $this->engine->resume($interview, InterviewAudience::Owner);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('interview.show', ['token' => $token]);
    }

    /** The owner is done: the interview completes (thin sections allowed). Extraction stays with the operator. */
    public function finish(string $token): RedirectResponse|View
    {
        $bound = $this->bind($token);
        if ($bound === null) {
            return view('interview.expired');
        }
        [$invite, $site] = $bound;

        $interview = $this->interviewFor($invite, $site);
        if ($interview->status === InterviewStatus::InProgress) {
            $this->engine->end($interview);
        }

        return redirect()->route('interview.show', ['token' => $token]);
    }

    /**
     * Resolve + bind the tenant from the token alone. Null when the link is unknown, expired, or revoked —
     * the caller renders the tenant-neutral expired page (no brand, nothing to learn from a bad token).
     *
     * @return array{0: InterviewInvite, 1: Site}|null
     */
    private function bind(string $token): ?array
    {
        $invite = $this->invites->find($token);
        if ($invite === null || ! $invite->isLive()) {
            return null;
        }

        return [$invite, $this->invites->bind($invite)];
    }

    /**
     * The interview this link is for. Once pinned, the link always opens THAT interview whatever its status
     * (a finished one shows the done page — it must never spawn a fresh interview on the next visit). Only
     * an unpinned link starts one: the site's open interview — the same row the operator's step reads —
     * created with the owner-facing opener when none exists.
     */
    private function interviewFor(InterviewInvite $invite, Site $site): Interview
    {
        if ($invite->interview_id !== null) {
            $pinned = Interview::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->find($invite->interview_id);
            if ($pinned !== null) {
                return $pinned;
            }
        }

        $interview = $this->engine->start($site, InterviewAudience::Owner);
        $invite->forceFill(['interview_id' => $interview->id])->save();

        return $interview;
    }

    /** @return list<array{label: string, state: string}> */
    private function progress(Interview $interview): array
    {
        $coverage = (array) ($interview->coverage ?? []);

        return collect(InterviewSection::cases())
            ->map(fn (InterviewSection $s) => [
                'label' => $s->label(),
                'state' => in_array($coverage[$s->value] ?? 'empty', ['filled', 'thin', 'empty'], true) ? (string) $coverage[$s->value] : 'empty',
            ])
            ->all();
    }

    /**
     * The tenant's own look, from existing site data only: name, logo (account logo, else the site's
     * branding logo set), primary + accent colours (the account palette). Nothing here is hardcoded per page.
     *
     * @return array{name: string, logo_url: string|null, primary: string, accent: string, phone: string|null}
     */
    private function brand(Site $site): array
    {
        $account = $site->account?->branding() ?? ['name' => $site->brand_name, 'logo_url' => null, 'primary' => '#0B2545', 'accent' => '#5BC0EB'];
        $logoSet = $site->branding?->logo_set;
        $logo = $account['logo_url'] ?: (is_array($logoSet) && is_string($logoSet['url'] ?? null) ? $logoSet['url'] : null);

        return [
            'name' => $site->brand_name !== '' ? $site->brand_name : $account['name'],
            'logo_url' => $logo,
            'primary' => $account['primary'],
            'accent' => $account['accent'],
            'phone' => $site->phone,
        ];
    }
}
