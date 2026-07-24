<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The stated services list — exhaustive, including zero-volume work — with per-service enrichment (the same form the service pages build from). Interview-seeded services are chipped; saving the enrichment confirms them.'])

        <div class="g-card">
            <h3>Add a service</h3>
            <div class="g-row">
                <input class="g-input" style="max-width:360px" type="text" placeholder="e.g. French Drain Installation" wire:model="newService" wire:keydown.enter="addService">
                <button class="g-btn primary" wire:click="addService">Add</button>
                <button class="g-btn" wire:click="suggest" wire:loading.attr="disabled" wire:target="suggest"
                    title="Services a {{ $this->trade !== '' ? $this->trade : 'business in your trade' }} commonly also offers — advisory, from the trade on the Business step.">
                    <span wire:loading.remove wire:target="suggest">✨ Suggest from trade</span>
                    <span wire:loading wire:target="suggest">Thinking…</span>
                </button>
            </div>
            @if ($suggestions !== [])
                <div class="g-hint" style="margin-top:2px">Commonly also offered by a {{ $this->trade }} business — add what applies, dismiss the rest:</div>
                <div class="g-list">
                    @foreach ($suggestions as $i => $s)
                        <div class="g-item" wire:key="sug-{{ $i }}-{{ \Illuminate\Support\Str::slug($s['name']) }}">
                            <strong>{{ $s['name'] }}</strong>
                            <span class="g-muted">{{ $s['why'] }}</span>
                            <span class="g-row" style="margin-left:auto">
                                <button class="g-btn primary" wire:click="addSuggestion({{ $i }})">Add</button>
                                <button class="g-btn" wire:click="dismissSuggestion({{ $i }})">Dismiss</button>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="g-card">
            @php $thinCount = $this->services->filter(fn ($s) => $s->isThin())->count(); @endphp
            <div class="g-row" style="justify-content:space-between;align-items:center">
                <h3 style="margin:0">Stated services</h3>
                <span class="g-row" style="gap:8px">
                @if ($this->serviceTree->count() > 1)
                    <button class="g-btn" wire:click="suggestGrouping" wire:loading.attr="disabled" wire:target="suggestGrouping"
                        title="Let AI propose which services group under which (each sub-service a page or a section). A starting point you then edit — nothing is built or changed until you apply.">
                        <span wire:loading.remove wire:target="suggestGrouping">✨ Suggest grouping</span>
                        <span wire:loading wire:target="suggestGrouping">Grouping…</span>
                    </button>
                @endif
                @if ($thinCount > 0)
                    <button class="g-btn" wire:click="aiEnrichAll" wire:loading.attr="disabled" wire:target="aiEnrichAll"
                        title="Draft the empty fields (symptoms, what's included, process, cost) for every thin service with generic trade knowledge — no prices or guarantees. You review each before it counts.">
                        <span wire:loading.remove wire:target="aiEnrichAll">✨ Enrich all thin ({{ $thinCount }})</span>
                        <span wire:loading wire:target="aiEnrichAll">Queuing…</span>
                    </button>
                @endif
                </span>
            </div>
            <p class="g-muted" style="font-size:12px;margin:2px 0 10px">
                Group sub-services under a parent to shape the site. Each top-level service gets its own page;
                a sub-service is a <strong>section</strong> on the parent page by default, or promote it to its
                <strong>own page</strong>. A parent with at least one own-page sub-service becomes a category hub.
            </p>
            <div class="g-list">
                @forelse ($this->serviceTree as $service)
                    @php
                        $pageKids = $service->childServices->where('page_treatment', \App\Enums\ServicePageTreatment::Page);
                        $sectionKids = $service->childServices->where('page_treatment', \App\Enums\ServicePageTreatment::Section);
                        $becomes = $pageKids->isNotEmpty()
                            ? 'Hub · '.$pageKids->count().' service page'.($pageKids->count() === 1 ? '' : 's').($sectionKids->isNotEmpty() ? ' + '.$sectionKids->count().' section'.($sectionKids->count() === 1 ? '' : 's') : '')
                            : ($sectionKids->isNotEmpty() ? 'Service page + '.$sectionKids->count().' section'.($sectionKids->count() === 1 ? '' : 's') : 'Service page');
                        $canGroup = $service->childServices->isEmpty();
                    @endphp
                    <div class="g-item" wire:key="svc-{{ $service->id }}" style="flex-wrap:wrap">
                        @include('filament.gathering._service-row', ['service' => $service])
                        <span class="g-seed" style="background:rgba(37,99,235,.1);color:#2563eb;border-color:rgba(37,99,235,.22)">becomes: {{ $becomes }}</span>
                        {{-- Group this (childless) top-level service under another. --}}
                        @if ($canGroup && $this->groupTargets->count() > 1)
                            <select class="g-input" style="max-width:200px;font-size:12px" wire:key="grp-{{ $service->id }}"
                                onchange="if(this.value){ @this.call('groupUnder', '{{ $service->id }}', this.value); this.value=''; }">
                                <option value="">Group under…</option>
                                @foreach ($this->groupTargets as $target)
                                    @if ($target->id !== $service->id)
                                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        @endif

                        {{-- Sub-services (indented). --}}
                        <div style="flex-basis:100%;padding-left:22px;margin-top:6px;display:flex;flex-direction:column;gap:6px">
                            @foreach ($service->childServices as $child)
                                <div class="g-item" wire:key="svc-{{ $child->id }}" style="background:rgba(0,0,0,.02)">
                                    <span class="g-muted" style="font-size:11px">└─</span>
                                    @include('filament.gathering._service-row', ['service' => $child])
                                    <span class="g-row" style="flex-basis:100%;padding-left:18px;margin-top:4px;gap:6px;align-items:center">
                                        @php $childLive = $child->page_treatment === \App\Enums\ServicePageTreatment::Page && $this->hasLivePage($child->id); @endphp
                                        <div class="g-toggle" role="group" style="display:inline-flex;border:1px solid rgba(0,0,0,.15);border-radius:6px;overflow:hidden">
                                            <button class="g-btn {{ $child->page_treatment === \App\Enums\ServicePageTreatment::Section ? 'primary' : '' }}" style="border:0;border-radius:0;font-size:11px"
                                                wire:click="setTreatment('{{ $child->id }}', 'section')"
                                                @if ($childLive) wire:confirm="'{{ $child->name }}' has a published page. Making it a section will take that page down and 301-redirect its URL to the parent. Continue?" @endif>Section</button>
                                            <button class="g-btn {{ $child->page_treatment === \App\Enums\ServicePageTreatment::Page ? 'primary' : '' }}" style="border:0;border-radius:0;font-size:11px"
                                                wire:click="setTreatment('{{ $child->id }}', 'page')">Its own page</button>
                                        </div>
                                        <button class="g-btn" style="font-size:11px" wire:click="promoteToTop('{{ $child->id }}')"
                                            title="Detach — make this its own top-level service page.">↥ Ungroup</button>
                                    </span>
                                </div>
                            @endforeach

                            {{-- Add a sub-service under this parent. --}}
                            <div class="g-row" style="gap:6px">
                                <input class="g-input" style="max-width:280px;font-size:12px" type="text" placeholder="Add a sub-service…"
                                    wire:model="newChild.{{ $service->id }}" wire:keydown.enter="addSubService('{{ $service->id }}')">
                                <button class="g-btn" style="font-size:12px" wire:click="addSubService('{{ $service->id }}')">+ Sub-service</button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="g-empty">No services yet — add them above, or let the interview seed the stated list.</div>
                @endforelse
            </div>
        </div>
        @include('filament.gathering._next')
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
