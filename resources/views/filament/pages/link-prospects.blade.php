<x-filament-panels::page>
@php
    $rows = $this->prospects;
    $summary = $this->summary;
    $statuses = \App\Enums\LinkProspectStatus::cases();
    $kindColor = fn (\App\Enums\LinkProspectKind $k): string => match ($k) {
        \App\Enums\LinkProspectKind::Directory => 'info',
        \App\Enums\LinkProspectKind::Local => 'success',
        \App\Enums\LinkProspectKind::Editorial => 'warning',
        \App\Enums\LinkProspectKind::Sponsorship => 'primary',
        \App\Enums\LinkProspectKind::Membership => 'primary',
        \App\Enums\LinkProspectKind::DealerLocator => 'primary',
        \App\Enums\LinkProspectKind::JobBoard => 'primary',
        default => 'gray',
    };
@endphp

    @if ($this->siteId === null)
        <x-filament::section>Choose a working tenant first.</x-filament::section>
    @else
        <div style="display:flex;flex-direction:column;gap:1rem">
            <x-filament::section>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <div style="font-size:.85rem;color:var(--gray-500)">
                        Two sources: domains that link to the local competitors Town Rank finds above you and not to you (the intersect), and local opportunity pages — sponsorships, memberships, dealer locators, job and training boards — found by searching each office's town and county.
                        @php($usage = $this->monthUsage)
                        @php($sender = $this->sender)
                        <div style="margin-top:.35rem">Outreach this month: <strong>{{ $usage['used'] }} of {{ $usage['cap'] }}</strong> ·
                            @if ($sender !== null) sends from <strong>{{ $sender['email'] }}</strong> @else <span style="color:#b91c1c">no sender on the client's own domain — set one on the Business step; nothing can send until then</span> @endif
                            · only prospects the client marked <strong>Interested</strong> can be emailed, one at a time.</div>
                        @if ($summary['count'] > 0)
                            <strong>{{ $summary['count'] }}</strong> on the list · last found {{ $summary['last_found_at']?->diffForHumans() ?? '—' }}
                            @foreach ($summary['by_status'] as $st => $n) · {{ \App\Enums\LinkProspectStatus::from($st)->label() }} {{ $n }} @endforeach
                        @else
                            No prospects yet — run the intersect once Town Rank has scanned.
                        @endif
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        <x-filament::button size="sm" color="gray" wire:click="findProspects" wire:confirm="Run the competitor intersect for this site? One backlink-index call.">Find prospects</x-filament::button>
                        <x-filament::button size="sm" wire:click="findLocalOpportunities" wire:confirm="Search every office's town and county for sponsorship, membership, dealer-locator and job-board pages? A few dozen searches.">Find local opportunities</x-filament::button>
                    </div>
                </div>
                @php($run = $this->lastRun)
                @if ($run !== null)
                    <div style="margin-top:.75rem;padding:.6rem .75rem;border-radius:.5rem;background:var(--gray-50);font-size:.78rem;line-height:1.5">
                        <div><strong>Last run</strong> {{ $run->ran_at->diffForHumans() }}
                            @if ($run->status === \App\Models\LinkProspectRun::FAILED)
                                · <span style="color:#b91c1c">failed: {{ $run->error }}</span>
                            @elseif ($run->scope === 'opportunities')
                                · local opportunities: <strong>{{ $run->queries }}</strong> searches returned <strong>{{ $run->returned }}</strong> results · <strong>{{ $run->kept }}</strong> opportunity domains kept ({{ $run->dropped_rank }} national / aggregator and {{ $run->dropped_spam }} platform dropped) · {{ $run->new }} new, {{ $run->refreshed }} refreshed
                            @else
                                · the index returned <strong>{{ $run->returned }}</strong> linking domains · <strong>{{ $run->kept }}</strong> kept ({{ $run->dropped_spam }} dropped as spam, {{ $run->dropped_rank }} below the authority floor) · {{ $run->new }} new, {{ $run->refreshed }} refreshed, {{ $run->pruned }} untouched from an earlier run dropped
                            @endif
                        </div>
                        <div style="color:var(--gray-500)" @if ($run->scope === 'opportunities') hidden @endif>Competitors asked about:
                            @forelse ($run->targets as $t)
                                <span style="white-space:nowrap">{{ $t['domain'] }} <em>({{ $t['source'] === 'map_pack' ? 'map pack, '.$t['appearances'].' cells' : 'organic, above you in '.$t['appearances'].' town results' }})</em></span>@if (! $loop->last), @endif
                            @empty
                                none — Town Rank has not found a local competitor yet
                            @endforelse
                        </div>
                        @if ($run->excluded !== [])
                            <div style="color:var(--gray-500)">Excluded as territory-wide (not a local company):
                                @foreach ($run->excluded as $d)<span style="white-space:nowrap">{{ $d['domain'] }} <em>({{ (int) round($d['share'] * 100) }}% of one scan's towns)</em></span>@if (! $loop->last), @endif @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                <div style="display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap">
                    @foreach (['open' => 'Open', 'interested' => 'Client interested', 'new' => 'New', 'contacted' => 'Contacted', 'replied' => 'Replied', 'acquired' => 'Won', 'declined' => 'Declined', 'dismissed' => 'Dismissed', 'all' => 'All'] as $key => $label)
                        <x-filament::button size="xs" wire:click="$set('status', '{{ $key }}')" :color="$this->status === $key ? 'primary' : 'gray'">{{ $label }}</x-filament::button>
                    @endforeach
                    <span style="width:1px;background:var(--gray-200);margin:0 .25rem"></span>
                    @foreach (['all' => 'Every kind', 'competitors' => 'Competitor links'] + collect(\App\Enums\LinkProspectKind::lanes())->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all() as $key => $label)
                        <x-filament::button size="xs" wire:click="$set('kind', '{{ $key }}')" :color="$this->kind === $key ? 'primary' : 'gray'">{{ $label }}</x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                    <thead>
                        <tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase">
                            <th style="padding:.5rem">Domain</th><th>DR</th><th>Links to / near</th><th>Kind</th><th>Client</th><th>Status</th><th>Notes</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $p)
                            <tr style="border-top:1px solid var(--gray-100)">
                                <td style="padding:.5rem;font-weight:500">{{ $p->domain }}
                                    @if ($p->url !== null)
                                        <div style="font-size:.72rem;font-weight:400;color:var(--gray-500);max-width:28rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $p->url }}">{{ preg_replace('#^https?://(www\.)?#', '', $p->url) }}</div>
                                    @endif
                                </td>
                                <td style="color:var(--gray-500)">{{ $p->rank ?? '—' }}</td>
                                <td style="font-size:.78rem">
                                    @if ($p->kind->isLane())
                                        <span style="color:var(--gray-500)">{{ $p->offices !== null && $p->offices !== [] ? implode(', ', $p->offices) : 'state-wide' }}</span>
                                        @if ($p->locality > 0)<span title="how local the page reads: town +2, state +1" style="margin-left:.25rem;font-size:.68rem;color:#15803d">{{ str_repeat('●', $p->locality) }}</span>@endif
                                    @else
                                        <strong>{{ $p->competitor_count }}</strong> <span style="color:var(--gray-500)">{{ implode(', ', $p->competitors) }}</span>
                                    @endif
                                </td>
                                <td><x-filament::badge :color="$kindColor($p->kind)">{{ $p->kind->label() }}</x-filament::badge></td>
                                <td>
                                    <select wire:change="setDecision('{{ $p->id }}', $event.target.value)" style="font-size:.8rem;border:1px solid {{ $p->client_decision === \App\Enums\ClientDecision::Interested ? '#16a34a' : 'var(--gray-300)' }};border-radius:.375rem;padding:.2rem .4rem">
                                        <option value="" @selected($p->client_decision === null)>Undecided</option>
                                        @foreach (\App\Enums\ClientDecision::cases() as $d)
                                            <option value="{{ $d->value }}" @selected($p->client_decision === $d)>{{ $d->label() }}</option>
                                        @endforeach
                                    </select>
                                    @if ($p->emails_sent > 0)<div style="font-size:.68rem;color:var(--gray-500)">{{ $p->emails_sent }} sent · {{ $p->last_emailed_at?->diffForHumans() }}</div>@endif
                                </td>
                                <td>
                                    <select wire:change="setStatus('{{ $p->id }}', $event.target.value)" style="font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem">
                                        @foreach ($statuses as $st)
                                            <option value="{{ $st->value }}" @selected($p->status === $st)>{{ $st->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" value="{{ $p->notes }}" placeholder="who, when, what they said" wire:change="setNotes('{{ $p->id }}', $event.target.value)" style="width:100%;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem"></td>
                                <td style="text-align:right;white-space:nowrap">
                                    <a href="{{ $p->url ?? 'https://'.$p->domain }}" target="_blank" rel="noopener" style="font-size:.75rem;color:#2563eb;text-decoration:underline;margin-right:.5rem">Open ↗</a>
                                    <x-filament::button size="xs" color="gray" wire:click="openKit('{{ $p->id }}')">Pitch</x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="padding:1rem;color:var(--gray-500)">Nothing in this view.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        @php($ak = $this->activeKit)
        @if ($ak !== null)
            <div wire:click="closeKit" style="position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:40"></div>
            <aside style="position:fixed;top:0;right:0;bottom:0;width:min(560px,100%);background:var(--gray-50);z-index:41;overflow:auto;box-shadow:-8px 0 24px rgba(0,0,0,.1)">
                <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <h2 style="font-size:1.1rem;font-weight:600">{{ $ak['prospect']->domain }}</h2>
                            <div style="font-size:.78rem;color:var(--gray-500)">{{ $ak['prospect']->kind->label() }} · {{ $ak['prospect']->kind->isLane() ? ($ak['prospect']->offices !== null && $ak['prospect']->offices !== [] ? 'near '.implode(', ', $ak['prospect']->offices) : 'state-wide') : 'links to '.implode(', ', $ak['prospect']->competitors) }}</div>
                        </div>
                        <button wire:click="closeKit" style="border:0;background:none;font-size:1.4rem;cursor:pointer;color:var(--gray-500)">&times;</button>
                    </div>
                    <label style="font-size:.8rem;display:block"><span style="color:var(--gray-500)">Speak for</span>
                        <select wire:model.live="kitLocationId" style="width:100%;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.3rem .4rem">
                            <option value="">The brand (links to the home page)</option>
                            @foreach ($this->offices as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }} office</option>@endforeach
                        </select>
                    </label>
                    @php($kit = $ak['kit'])
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500)">Pitch</h3>
                        <x-filament::button size="xs" :color="$kit ? 'gray' : 'primary'" wire:click="draftKit({{ $kit ? 'true' : 'false' }})">{{ $kit ? 'Redraft' : 'Draft pitch' }}</x-filament::button>
                    </div>
                    @if ($kit)
                        @php($k = $kit->payload)
                        @if (! $kit->model_ok)<p style="font-size:.75rem;color:#b45309">Plain fallback copy — the model did not answer. Redraft to try again.</p>@endif
                        <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.8rem">
                            <label style="display:block"><span style="color:var(--gray-500)">Subject</span><input readonly value="{{ $k['pitch_subject'] ?? '' }}" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></label>
                            <label style="display:block"><span style="color:var(--gray-500)">Email</span><textarea readonly rows="12" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff">{{ $k['pitch_body'] ?? '' }}</textarea></label>
                            <label style="display:block"><span style="color:var(--gray-500)">Link to ask for</span><input readonly value="{{ $k['link_url'] ?? '' }}" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></label>
                            <label style="display:block"><span style="color:var(--gray-500)">One-line description (for a listing or a bio)</span><textarea readonly rows="2" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff">{{ $k['short_description'] ?? '' }}</textarea></label>
                        </div>
                        <p style="font-size:.7rem;color:var(--gray-400)">Drafted {{ $kit->drafted_at->diffForHumans() }}. Click a field to select it, or send it from the box below.</p>
                    @else
                        <p style="font-size:.8rem;color:var(--gray-400)">A short email asking for one specific thing, in your voice, with the page to link — draft it, then review and send it below.</p>
                    @endif

                    @php($prospect = $ak['prospect'])
                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin-top:.5rem">Contact</h3>
                    <div style="display:flex;gap:.4rem;align-items:center">
                        <input type="email" wire:model="contactEmailInput" placeholder="who to write to" style="flex:1;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.3rem .5rem;background:#fff">
                        <x-filament::button size="xs" color="gray" wire:click="setContact">Set</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="findContact">Find contact</x-filament::button>
                    </div>
                    <div style="font-size:.72rem;color:var(--gray-500)">
                        @if ($prospect->contact_email !== null)
                            Writing to <strong>{{ $prospect->contact_email }}</strong> ({{ $prospect->contact_source === 'manual' ? 'entered by hand' : ($prospect->contact_source === 'mailto' ? 'mailto link on the page' : 'found on the page') }}).
                        @elseif ($prospect->contact_checked_at !== null)
                            Checked {{ $prospect->contact_checked_at->diffForHumans() }} — no address on the page or its contact page. Enter one by hand.
                        @else
                            No contact yet — Find contact reads the page and its contact page.
                        @endif
                        @if (is_array($prospect->contact_candidates) && count($prospect->contact_candidates) > 1)
                            <div>Also seen:
                                @foreach ($prospect->contact_candidates as $c)
                                    @if ($c !== $prospect->contact_email)<button type="button" wire:click="pickContact('{{ $c }}')" style="border:0;background:none;color:#2563eb;text-decoration:underline;cursor:pointer;font-size:.72rem">{{ $c }}</button>@endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @php($guard = $this->sendGuard)
                    @php($sent = $this->sentEmails)
                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin-top:.5rem">{{ $this->sendKind === \App\Models\OutreachEmail::FOLLOW_UP ? 'The one follow-up' : 'Send the pitch' }}</h3>
                    @if ($sent !== [])
                        <div style="font-size:.72rem;color:var(--gray-500);display:flex;flex-direction:column;gap:.15rem">
                            @foreach ($sent as $e)
                                <div>{{ $e->kind === \App\Models\OutreachEmail::FOLLOW_UP ? 'Follow-up' : 'Pitch' }} · {{ $e->approved_at->toFormattedDateString() }} · to {{ $e->to_email }} ·
                                    @if ($e->status === \App\Models\OutreachEmail::SENT)<span style="color:#15803d">sent</span>
                                    @elseif ($e->status === \App\Models\OutreachEmail::FAILED)<span style="color:#b91c1c">failed: {{ $e->error }}</span>
                                    @else queued @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($guard !== null)
                        <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.8rem">
                            <label style="display:block"><span style="color:var(--gray-500)">Subject</span><input wire:model="sendSubject" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></label>
                            <label style="display:block"><span style="color:var(--gray-500)">Email (sent exactly as shown, then the signature with your address and a way out)</span><textarea wire:model="sendBody" rows="10" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></textarea></label>
                        </div>
                        <ul style="font-size:.72rem;list-style:none;padding:0;display:flex;flex-direction:column;gap:.15rem">
                            @foreach ($guard->checks as $c)
                                <li style="color:{{ $c['ok'] ? '#15803d' : '#b91c1c' }}">{{ $c['ok'] ? '✓' : '✗' }} {{ $c['ok'] ? match ($c['key']) { 'enabled' => 'Outreach is on', 'sender' => 'Sender on the client\'s own domain', 'decision' => 'Client marked Interested', 'contact' => 'Contact known', 'open' => 'Prospect is open', 'cap' => 'Cap has room ('.$guard->usedThisMonth.' of '.$guard->cap.' used)', 'pitched' => 'Pitch was sent', 'once' => 'Not sent before', 'waited' => 'Waited long enough', default => $c['key'] } : $c['text'] }}</li>
                            @endforeach
                        </ul>
                        <div>
                            <x-filament::button size="sm" :color="$guard->ok() ? 'primary' : 'gray'" :disabled="! $guard->ok()" wire:click="approveAndSend" wire:confirm="Send this email now, from {{ $this->sender['email'] ?? 'the client\'s address' }} to {{ $prospect->contact_email ?? '—' }}? It counts one against this month's cap.">Approve &amp; send</x-filament::button>
                        </div>
                    @endif
                </div>
            </aside>
        @endif
    @endif
</x-filament-panels::page>
