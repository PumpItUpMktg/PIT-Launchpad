@props(['label', 'hint' => null])

<div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</span>
    {{ $slot }}
    @if ($hint)
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $hint }}</p>
    @endif
</div>
