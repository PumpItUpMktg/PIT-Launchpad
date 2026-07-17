{{-- The step footer: Save & continue (pages with a primary save) / Next. Last step renders nothing. --}}
@php $next = $this->nextStep(); @endphp
@if ($next !== null)
    <div class="g-row" style="justify-content:flex-end; border-top:1px solid rgba(148,163,184,.2); padding-top:12px">
        <button class="g-btn primary" wire:click="continueToNext" wire:loading.attr="disabled" wire:target="continueToNext">
            <span wire:loading.remove wire:target="continueToNext">{{ $this->savesOnContinue() ? 'Save & continue' : 'Next' }} → {{ $next['label'] }}</span>
            <span wire:loading wire:target="continueToNext">Saving…</span>
        </button>
    </div>
@endif
