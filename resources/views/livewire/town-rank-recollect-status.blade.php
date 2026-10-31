@php $rc = $this->progress; @endphp
<div class="t-runall" @if ($rc['running']) wire:poll.15s @endif>
    @if ($rc['running'])
        <span class="t-note" style="margin:0">
            <strong>Re-reading masked towns:</strong> {{ $rc['total'] > 0 ? number_format($rc['remaining']).' of '.number_format($rc['total']).' left' : 'counting the backlog…' }}
            · {{ number_format($rc['ranked']) }} ranks recovered · {{ number_format($rc['not_found']) }} confirmed not found
            @if ($rc['skipped'] > 0) · {{ number_format($rc['skipped']) }} unreadable @endif
            · started {{ $rc['started_at'] }} · last update {{ $rc['updated_at'] }}
        </span>
    @else
        <button type="button" wire:click="recollect" wire:loading.attr="disabled" wire:target="recollect"
                wire:confirm="Re-read every town recorded as not found by a failed or masked read? Free — the provider's stored results are read again; nothing is posted. The worker counts the backlog first.">
            <span wire:loading.remove wire:target="recollect">Re-read masked towns</span>
            <span wire:loading wire:target="recollect">Queueing…</span>
        </button>
        @if ($rc['status'] === 'done')
            @php
                // One sentence with an optional clause — built here, never an @if spliced mid-word.
                $doneLine = number_format($rc['read']).' read · '.number_format($rc['ranked']).' ranks recovered · '.number_format($rc['not_found']).' confirmed not found'
                    .($rc['skipped'] > 0 ? ' · '.number_format($rc['skipped']).' unreadable (left as they were)' : '').'.';
            @endphp
            <span class="t-note" style="margin:0"><strong>Re-read finished</strong> {{ $rc['finished_at'] }}: {{ $doneLine }}</span>
        @elseif ($rc['status'] === 'failed')
            <span class="t-note" style="margin:0"><strong>Re-read stopped</strong> {{ $rc['finished_at'] }}: {{ $rc['error'] }} — run it again to continue.</span>
        @endif
    @endif
</div>
