{{-- One work-lane row on an Operate pages board. Expects: $row, $rejecting. --}}
<div class="pb-row" wire:key="pbw-{{ $row['id'] }}">
    <div>
        <div class="pb-title">
            {{ $row['title'] }}
            @if (! empty($row['brick_mortar']))
                <span class="pb-bm" title="Brick-and-mortar location this page belongs to">
                    📍 {{ $row['is_brick_mortar'] ?? false ? 'This location' : $row['brick_mortar'] }}
                </span>
            @endif
            @if (! empty($row['needs_enrichment']))
                <a class="pb-enrich" href="{{ \App\Filament\Pages\Gathering\ServicesStep::getUrl() }}" wire:navigate
                   title="This service has no symptoms / what's-included / process / cost — its page will render thin. Enrich it, then regenerate.">
                    ⚠ Needs enrichment
                </a>
            @endif
            @if (! empty($row['needs_generation']))
                <span class="pb-generate"
                      title="This hub has no drafted body and/or no service pages in its silo to link — it renders thin and can't route to its children. Generate the hub (and its silo's service pages), then repush.">
                    ⚠ Needs generation
                </span>
            @endif
        </div>
        <div class="pb-perma">{{ $row['permalink'] }}</div>
        @if (! empty($row['operator_tail']))
            <div class="pb-tail {{ $row['tone'] === 'danger' ? 'err' : '' }}">{{ $row['operator_tail'] }}</div>
        @endif
    </div>
    <span class="pb-move">{{ $row['whose_move'] }}</span>
    <div class="pb-right">
        <span class="pb-tone {{ $row['tone'] }}">{{ $row['client_line'] }}</span>
        @foreach ($row['actions'] as $action)
            @if ($action === 'generate')
                <button class="lv-btn primary" wire:click="generate('{{ $row['id'] }}')">Generate</button>
            @elseif ($action === 'approve')
                <button class="lv-btn primary" wire:click="approve('{{ $row['id'] }}')">Approve</button>
            @elseif ($action === 'publish')
                <button class="lv-btn primary" wire:click="publish('{{ $row['id'] }}')">Publish</button>
            @elseif ($action === 'review')
                <a class="lv-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $row['id']]) }}" wire:navigate>Review</a>
            @elseif ($action === 'view' && $row['live_url'])
                <a class="lv-btn" href="{{ $row['live_url'] }}" target="_blank" rel="noopener">View</a>
            @endif
        @endforeach
        @if (in_array('regenerate', $row['menu'], true))
            <button class="lv-btn" wire:click="regenerate('{{ $row['id'] }}')">Regenerate</button>
        @endif
        @if (in_array('lock', $row['menu'], true))
            <button class="lv-btn" wire:click="lock('{{ $row['id'] }}')">Lock</button>
        @endif
        @if (in_array('reject', $row['menu'], true))
            <button class="lv-btn danger" wire:click="startReject('{{ $row['id'] }}')">Reject</button>
        @endif
        @if (in_array('remove', $row['menu'], true))
            <button class="lv-btn danger" wire:click="removePage('{{ $row['id'] }}')"
                wire:confirm="Remove '{{ $row['title'] }}' completely? It'll be deleted from the plan{{ $row['live_url'] ? ' and taken down from WordPress' : '' }} — not just parked. Rebuilding the structure can bring it back.">Remove</button>
        @endif
    </div>
    @if ($rejecting === $row['id'])
        <div class="pb-reject">
            <input type="text" placeholder="Reason (optional — improves the next draft)" wire:model="rejectReason" wire:keydown.enter="reject('{{ $row['id'] }}')">
            <button class="lv-btn danger" wire:click="reject('{{ $row['id'] }}')">Confirm reject</button>
        </div>
    @endif
</div>
