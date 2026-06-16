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
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <h3 class="mb-1 text-sm font-semibold text-gray-700 dark:text-gray-200">Here's what I captured</h3>
                    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                        Sanity-check and edit before saving. Leave markets or exclusions blank if the owner didn't give any.
                    </p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="sm:col-span-2">
                            <span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Trade</span>
                            <input type="text" wire:model="editTrade"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Anchor services <span class="text-gray-400">(one per line)</span></span>
                            <textarea wire:model="editAnchors" rows="4"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Markets <span class="text-gray-400">(one per line)</span></span>
                            <textarea wire:model="editMarkets" rows="4"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        </label>
                        <label class="sm:col-span-2">
                            <span class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Exclusions <span class="text-gray-400">(one per line)</span></span>
                            <textarea wire:model="editExclusions" rows="3"
                                class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"></textarea>
                        </label>
                    </div>

                    <details class="mt-4">
                        <summary class="cursor-pointer text-xs font-medium text-gray-600 dark:text-gray-300">Voice profile</summary>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-950/90 p-3 text-xs text-gray-100">{{ json_encode($voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>

                    <div class="mt-4">
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
