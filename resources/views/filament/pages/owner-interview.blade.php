<x-filament-panels::page>
    @unless ($started)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                Pick the site you're onboarding, then talk through the business the way the owner would.
                When there's enough, you'll extract, sanity-check, and save the seed + voice profile.
            </p>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="flex-1">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Site</span>
                    <select
                        wire:model.live="siteId"
                        class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                    >
                        <option value="">Select a site…</option>
                        @foreach ($this->siteOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <x-filament::button wire:click="start" icon="heroicon-m-play">
                    Start interview
                </x-filament::button>

                @if ($this->hasSaved)
                    <x-filament::button wire:click="resume" color="gray" icon="heroicon-m-arrow-uturn-left">
                        Resume saved
                    </x-filament::button>
                @endif
            </div>
        </div>
    @else
        <div class="flex flex-col gap-4">
            {{-- Transcript --}}
            <div class="flex flex-col gap-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @foreach ($messages as $message)
                    <div @class([
                        'max-w-[80%] rounded-2xl px-4 py-2 text-sm',
                        'self-start bg-white text-gray-800 ring-1 ring-gray-950/5 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10' => $message['role'] === 'assistant',
                        'self-end bg-primary-600 text-white' => $message['role'] !== 'assistant',
                    ])>
                        {{ $message['text'] }}
                    </div>
                @endforeach
            </div>

            {{-- Composer --}}
            @unless ($ready)
                <form wire:submit="send" class="flex flex-col gap-2">
                    <textarea
                        wire:model="draft"
                        rows="3"
                        placeholder="Answer as the owner…"
                        class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                    ></textarea>
                    <div>
                        <x-filament::button type="submit" icon="heroicon-m-paper-airplane">
                            Send
                        </x-filament::button>
                    </div>
                </form>
            @elseunless ($extracted)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm font-medium text-success-600 dark:text-success-400">
                        Enough gathered — ready to extract.
                    </span>
                    <x-filament::button wire:click="extract" icon="heroicon-m-sparkles">
                        Extract seed + voice
                    </x-filament::button>
                </div>
            @endunless

            {{-- Editable confirm --}}
            @if ($extracted)
                @php
                    $tone = $voice['tone_axes'] ?? [];
                    $persona = $voice['persona'] ?? [];
                    $audience = $voice['audience'] ?? [];
                    $lang = $voice['language_rules'] ?? [];
                    $preferred = implode(', ', $lang['preferred'] ?? []);
                    $banned = implode(', ', $lang['banned'] ?? []);
                @endphp

                <div class="flex flex-col gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Here's what I captured</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Glance over each field, edit anything, then save. Empty is fine — blanks won't be invented.
                        </p>
                    </div>

                    {{-- Seed fields, one labeled box each --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <x-interview-field label="Trade">
                            <input type="text" wire:model="editTrade"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                        </x-interview-field>

                        <x-interview-field label="Region (broad)"
                            hint="Positioning only — the broad area served. Specific towns/service areas live in Locations, not here.">
                            <input type="text" wire:model="editRegion" placeholder="e.g. NJ, eastern PA"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                        </x-interview-field>

                        <x-interview-field label="Anchor services" hint="One per line — a few core services, not exhaustive.">
                            <textarea wire:model="editAnchors" rows="5"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        </x-interview-field>

                        <x-interview-field label="Exclusions" hint="One per line — work they will NOT do.">
                            <textarea wire:model="editExclusions" rows="5"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        </x-interview-field>
                    </div>

                    {{-- Voice — readable summary --}}
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <h4 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">Voice profile</h4>
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Identity</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $persona['identity'] ?? '—' }}@if (!empty($persona['credibility'])) <span class="text-gray-500">· {{ $persona['credibility'] }}</span>@endif</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Audience</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $audience['primary'] ?? '—' }}</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Tone</dt>
                                <dd class="text-gray-800 dark:text-gray-100">
                                    @if (isset($tone['formality'])) formality {{ $tone['formality'] }}@endif@if (isset($tone['warmth'])) · warmth {{ $tone['warmth'] }}@endif
                                    @if (!empty($voice['cta_voice'])) · CTA {{ $voice['cta_voice'] }}@endif
                                    @if (!empty($voice['reading_level'])) · {{ str_replace('_', ' ', $voice['reading_level']) }}@endif
                                </dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Framing</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ str_replace('_', ' ', $voice['framing_model'] ?? '—') }}</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Preferred language</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $preferred !== '' ? $preferred : '—' }}</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Banned language</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $banned !== '' ? $banned : '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div>
                        @if ($persisted)
                            <span class="text-sm font-medium text-success-600 dark:text-success-400">Saved to the site.</span>
                        @else
                            <x-filament::button wire:click="persist" color="success" icon="heroicon-m-check">
                                Save to site
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endunless
</x-filament-panels::page>
