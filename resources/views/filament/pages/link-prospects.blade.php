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
                        <x-filament::button size="sm" color="gray" wire:click="readProfiles" wire:confirm="Read who / where / contacts / offer for up to 40 unread opportunities in this view? Two page reads and one short model call each.">Read profiles</x-filament::button>
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

            @if ($this->office === '')
                {{-- One card per service area --}}
                @php($cards = $this->officeCards)
                @if ($cards === [])
                    <x-filament::section><p style="font-size:.85rem;color:var(--gray-500)">Nothing in this view.</p></x-filament::section>
                @else
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
                        @foreach ($cards as $card)
                            <div style="border:1px solid var(--gray-200);border-radius:.75rem;background:#fff;padding:1rem;display:flex;flex-direction:column;gap:.6rem">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem">
                                    <div style="min-width:0">
                                        <div style="font-weight:600;font-size:.95rem">{{ $card['key'] === \App\Links\OfficeAttribution::CORPORATE ? 'Corporate · state-wide' : ($card['key'] === \App\Links\OfficeAttribution::COMPETITORS ? 'Competitor links' : $card['label'].' office') }}</div>
                                        <div style="font-size:.72rem;color:var(--gray-500)">
                                            @if ($card['key'] === \App\Links\OfficeAttribution::CORPORATE) {{ $card['label'] }}
                                            @elseif ($card['key'] === \App\Links\OfficeAttribution::COMPETITORS) sites linking to competitors near you — no office
                                            @else local sponsorships, memberships, dealer locators and job boards near this office @endif
                                        </div>
                                    </div>
                                    <x-filament::button size="xs" wire:click="openOffice('{{ $card['key'] }}')">Open</x-filament::button>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:.35rem;font-size:.72rem">
                                    <x-filament::badge color="gray">{{ $card['count'] }} total</x-filament::badge>
                                    @foreach ($card['by_kind'] as $label => $n)<x-filament::badge color="primary">{{ $n }} {{ strtolower($label) }}</x-filament::badge>@endforeach
                                    @if ($card['interested'] > 0)<x-filament::badge color="success">{{ $card['interested'] }} client interested</x-filament::badge>@endif
                                </div>
                                <div style="display:flex;flex-direction:column;gap:.5rem;border-top:1px solid var(--gray-100);padding-top:.6rem">
                                    @foreach ($card['top'] as $p)
                                        <div style="display:flex;gap:.5rem;align-items:flex-start;font-size:.78rem">
                                            @if ($p->thumbnail_url !== null)
                                                <img src="{{ $p->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:36px;height:36px;object-fit:cover;border-radius:.375rem;border:1px solid var(--gray-200);flex:none;background:#fff" onerror="this.style.display='none'">
                                            @else
                                                <div style="width:36px;height:36px;border-radius:.375rem;background:var(--gray-100);flex:none"></div>
                                            @endif
                                            <div style="min-width:0">
                                                <div style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $p->profile['name'] ?? $p->domain }} <span style="font-weight:400;color:var(--gray-500)">· {{ $p->kind->label() }}</span></div>
                                                @php($offerLine = \App\Links\Profile\ProspectProfiler::offerLine($p->profile))
                                                <div style="color:var(--gray-500);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $offerLine ?? ($p->profile['overview'] ?? ($p->kind->isLane() ? ($p->offices !== null && $p->offices !== [] ? implode(', ', $p->offices) : 'state-wide') : 'links to '.implode(', ', $p->competitors))) }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                {{-- One service area: every opportunity as a card --}}
                @php($g = $this->officeGroup)
                <x-filament::section>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
                        <div>
                            <button type="button" wire:click="closeOffice" style="border:0;background:none;color:#2563eb;cursor:pointer;font-size:.8rem;padding:0">← All service areas</button>
                            <h2 style="font-size:1.05rem;font-weight:600;margin-top:.15rem">{{ $g['key'] === \App\Links\OfficeAttribution::CORPORATE ? 'Corporate · state-wide' : ($g['key'] === \App\Links\OfficeAttribution::COMPETITORS ? 'Competitor links' : $g['label'].' office') }} <span style="font-weight:400;color:var(--gray-500);font-size:.85rem">· {{ count($g['rows']) }}</span></h2>
                            @if ($g['key'] === \App\Links\OfficeAttribution::CORPORATE)<div style="font-size:.75rem;color:var(--gray-500)">{{ $g['label'] }}</div>@endif
                        </div>
                    </div>
                    @forelse ($g['rows'] as $p)
                        @php($pf = $p->profile)
                        @php($offerLine = \App\Links\Profile\ProspectProfiler::offerLine($pf))
                        <div style="display:flex;gap:1rem;align-items:flex-start;padding:.9rem 0;border-top:1px solid var(--gray-100)">
                            @if ($p->thumbnail_url !== null)
                                <img src="{{ $p->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:112px;height:84px;object-fit:cover;border-radius:.5rem;border:1px solid var(--gray-200);flex:none;background:#fff" onerror="this.style.display='none'">
                            @else
                                <div style="width:112px;height:84px;border-radius:.5rem;background:var(--gray-100);flex:none;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--gray-400)">no image</div>
                            @endif
                            <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:.3rem;font-size:.8rem">
                                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem">
                                    <span style="font-weight:600;font-size:.95rem">{{ $pf['name'] ?? $p->domain }}</span>
                                    @if (($pf['name'] ?? null) !== null)<span style="color:var(--gray-500)">{{ $p->domain }}</span>@endif
                                    <x-filament::badge :color="$kindColor($p->kind)">{{ $p->kind->label() }}</x-filament::badge>
                                    @if ($p->rank !== null)<span style="color:var(--gray-500);font-size:.72rem">DR {{ $p->rank }}</span>@endif
                                    @if ($p->kind->isLane() && $p->locality > 0)<span title="how local the page reads" style="font-size:.68rem;color:#15803d">{{ str_repeat('●', $p->locality) }}</span>@endif
                                </div>
                                @if (($pf['overview'] ?? null) !== null)<div style="color:var(--gray-700)">{{ $pf['overview'] }}</div>@endif
                                <div style="color:var(--gray-500)">
                                    @if ($p->kind->isLane()) Near {{ $p->offices !== null && $p->offices !== [] ? implode(', ', $p->offices) : 'state-wide' }}
                                    @else Links to {{ implode(', ', $p->competitors) }} @endif
                                    @if (($pf['address'] ?? $pf['town'] ?? null) !== null) · {{ $pf['address'] ?? $pf['town'] }}@endif
                                    @if (($pf['phone'] ?? null) !== null) · {{ $pf['phone'] }}@endif
                                </div>
                                @if (($pf['contacts'] ?? []) !== [] || $p->contact_email !== null)
                                    <div style="color:var(--gray-600)">
                                        @foreach ($pf['contacts'] ?? [] as $c){{ $c['name'] }}@if (($c['role'] ?? null) !== null) <em style="color:var(--gray-500)">({{ $c['role'] }})</em>@endif@if (! $loop->last), @endif @endforeach
                                        @if ($p->contact_email !== null)<span style="color:var(--gray-500)">{{ ($pf['contacts'] ?? []) !== [] ? ' · ' : '' }}writing to {{ $p->contact_email }}</span>@endif
                                    </div>
                                @endif
                                @if ($offerLine !== null)
                                    <div style="font-weight:500;color:{{ ($pf['offer']['website_link'] ?? '') === 'offered' ? '#15803d' : 'var(--gray-700)' }}">{{ $pf['offer']['summary'] ?? $offerLine }}@if (($pf['offer']['summary'] ?? null) !== null) <span style="font-weight:400;color:var(--gray-500)">· {{ $offerLine }}</span>@endif</div>
                                @elseif ($p->profile_read_at !== null && $p->kind->isLane())
                                    <div style="color:var(--gray-400)">{{ $pf === null ? $p->profile_error : 'No offer stated on the page.' }}</div>
                                @elseif ($p->kind->isLane())
                                    <div style="color:var(--gray-400)">Profile not read yet — open Pitch → Read profile, or Read profiles above.</div>
                                @endif
                                <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.2rem">
                                    <select wire:change="setDecision('{{ $p->id }}', $event.target.value)" style="font-size:.78rem;border:1px solid {{ $p->client_decision === \App\Enums\ClientDecision::Interested ? '#16a34a' : 'var(--gray-300)' }};border-radius:.375rem;padding:.2rem .4rem">
                                        <option value="" @selected($p->client_decision === null)>Client: undecided</option>
                                        @foreach (\App\Enums\ClientDecision::cases() as $d)<option value="{{ $d->value }}" @selected($p->client_decision === $d)>Client: {{ $d->label() }}</option>@endforeach
                                    </select>
                                    <select wire:change="setStatus('{{ $p->id }}', $event.target.value)" style="font-size:.78rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem">
                                        @foreach ($statuses as $st)<option value="{{ $st->value }}" @selected($p->status === $st)>{{ $st->label() }}</option>@endforeach
                                    </select>
                                    <input type="text" value="{{ $p->notes }}" placeholder="who, when, what they said" wire:change="setNotes('{{ $p->id }}', $event.target.value)" style="flex:1;min-width:12rem;font-size:.78rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem">
                                    @if ($p->emails_sent > 0)<span style="font-size:.7rem;color:var(--gray-500)">{{ $p->emails_sent }} sent · {{ $p->last_emailed_at?->diffForHumans() }}</span>@endif
                                    <a href="{{ $p->url ?? 'https://'.$p->domain }}" target="_blank" rel="noopener" style="font-size:.78rem;color:#2563eb;text-decoration:underline">Open ↗</a>
                                    <x-filament::button size="xs" color="gray" wire:click="openKit('{{ $p->id }}')">Pitch</x-filament::button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p style="font-size:.85rem;color:var(--gray-500)">Nothing in this view for this service area.</p>
                    @endforelse
                </x-filament::section>
            @endif
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
                    @if ($this->stateLevel)
                        <div style="font-size:.78rem;color:var(--gray-500);padding:.5rem .6rem;border-radius:.375rem;background:var(--gray-100)"><strong>Speaks for the company</strong> from the corporate address. A state-level organisation is approached once by the business, never once per office — one sponsorship or membership per organisation.</div>
                    @else
                        <label style="font-size:.8rem;display:block"><span style="color:var(--gray-500)">Speak for</span>
                            <select wire:model.live="kitLocationId" style="width:100%;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.3rem .4rem">
                                <option value="">The company (corporate address, links to the home page)</option>
                                @foreach ($this->offices as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }} office</option>@endforeach
                            </select>
                            <span style="font-size:.7rem;color:var(--gray-400)">One email per organisation for the whole business, whichever office speaks.</span>
                        </label>
                    @endif
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

                    @php($pf = $prospect->profile)
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.5rem">
                        <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500)">The organisation</h3>
                        <x-filament::button size="xs" color="gray" wire:click="readProfile">{{ $prospect->profile_read_at === null ? 'Read profile' : 'Re-read' }}</x-filament::button>
                    </div>
                    @if ($pf !== null)
                        <div style="display:flex;gap:.75rem;align-items:flex-start;font-size:.8rem">
                            @if ($prospect->thumbnail_url !== null)
                                <img src="{{ $prospect->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:96px;height:72px;object-fit:cover;border-radius:.375rem;border:1px solid var(--gray-200);flex:none;background:#fff" onerror="this.style.display='none'">
                            @endif
                            <div style="display:flex;flex-direction:column;gap:.25rem;min-width:0">
                                <div><strong>{{ $pf['name'] ?? $prospect->domain }}</strong>@if (($pf['org_kind'] ?? null) !== null) <span style="color:var(--gray-500)">· {{ $pf['org_kind'] }}</span>@endif</div>
                                @if (($pf['overview'] ?? null) !== null)<div style="color:var(--gray-600)">{{ $pf['overview'] }}</div>@endif
                                @if (($pf['address'] ?? $pf['town'] ?? null) !== null)<div style="color:var(--gray-500)">{{ $pf['address'] ?? $pf['town'] }}</div>@endif
                                @if (($pf['phone'] ?? null) !== null)<div style="color:var(--gray-500)">{{ $pf['phone'] }}</div>@endif
                                @if (($pf['contacts'] ?? []) !== [])
                                    <div>People:
                                        @foreach ($pf['contacts'] as $c)
                                            <span style="white-space:nowrap">{{ $c['name'] }}@if (($c['role'] ?? null) !== null) <em style="color:var(--gray-500)">({{ $c['role'] }})</em>@endif
                                            @if (($c['email'] ?? null) !== null) <button type="button" wire:click="pickContact('{{ $c['email'] }}')" style="border:0;background:none;color:#2563eb;text-decoration:underline;cursor:pointer;font-size:.75rem">{{ $c['email'] }}</button>@endif</span>@if (! $loop->last), @endif
                                        @endforeach
                                    </div>
                                @endif
                                @if (($pf['emails'] ?? []) !== [])
                                    <div>Emails on the page:
                                        @foreach ($pf['emails'] as $e)<button type="button" wire:click="pickContact('{{ $e }}')" style="border:0;background:none;color:#2563eb;text-decoration:underline;cursor:pointer;font-size:.75rem">{{ $e }}</button>@if (! $loop->last), @endif @endforeach
                                    </div>
                                @endif
                                @if (($pf['social'] ?? []) !== [])
                                    <div style="color:var(--gray-500)">@foreach ($pf['social'] as $k => $u)<a href="{{ $u }}" target="_blank" rel="noopener" style="margin-right:.5rem;text-decoration:underline">{{ ucfirst($k) }}</a>@endforeach</div>
                                @endif
                                @php($offer = $pf['offer'] ?? null)
                                @if (is_array($offer) && ($offer['kind'] ?? 'none') !== 'none')
                                    <div style="margin-top:.25rem;padding:.5rem .6rem;border-radius:.375rem;background:#fff;border:1px solid var(--gray-200)">
                                        <div style="font-weight:600">{{ ucfirst($offer['kind']) }} offer · <span style="color:{{ ($offer['website_link'] ?? '') === 'offered' ? '#15803d' : (($offer['website_link'] ?? '') === 'implied' ? '#b45309' : 'var(--gray-500)') }}">{{ match ($offer['website_link'] ?? '') { 'offered' => 'website link offered', 'implied' => 'link implied (logo / listing on their site)', default => 'no link mentioned' } }}</span></div>
                                        @if (($offer['summary'] ?? null) !== null)<div style="color:var(--gray-600)">{{ $offer['summary'] }}</div>@endif
                                        @foreach ($offer['levels'] ?? [] as $l)
                                            <div style="margin-top:.2rem"><strong>{{ $l['name'] }}</strong>@if (($l['price'] ?? '') !== '') <span style="color:var(--gray-600)">{{ $l['price'] }}</span>@endif
                                                @if (($l['includes'] ?? []) !== [])<span style="color:var(--gray-500)"> — {{ implode('; ', $l['includes']) }}</span>@endif</div>
                                        @endforeach
                                        @if (($offer['benefits'] ?? []) !== [])<div style="color:var(--gray-500);margin-top:.2rem">Includes: {{ implode(', ', array_map(fn ($b) => str_replace('_', ' ', $b), $offer['benefits'])) }}</div>@endif
                                        @if (($offer['deadline'] ?? null) !== null)<div style="color:var(--gray-500)">Deadline: {{ $offer['deadline'] }}</div>@endif
                                        @if (($offer['apply_url'] ?? null) !== null)<div><a href="{{ $offer['apply_url'] }}" target="_blank" rel="noopener" style="text-decoration:underline;color:#2563eb">Application / details ↗</a></div>@endif
                                    </div>
                                @elseif ($prospect->kind->isLane())
                                    <div style="color:var(--gray-400)">No sponsorship or membership offer stated on the page{{ ($pf['pages_read'] ?? []) !== [] ? 's read' : '' }}.</div>
                                @endif
                                <div style="font-size:.7rem;color:var(--gray-400)">Read {{ $prospect->profile_read_at?->diffForHumans() }}{{ $prospect->profile_model_ok === false ? ' · '.$prospect->profile_error : '' }}@if (($pf['pages_read'] ?? []) !== []) · {{ count($pf['pages_read']) }} page(s)@endif</div>
                            </div>
                        </div>
                    @elseif ($prospect->profile_read_at !== null)
                        <p style="font-size:.78rem;color:#b45309">{{ $prospect->profile_error }}</p>
                    @else
                        <p style="font-size:.78rem;color:var(--gray-400)">Who they are, where, who to talk to, and what a sponsor or member gets — read from their page, nothing added.</p>
                    @endif

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
