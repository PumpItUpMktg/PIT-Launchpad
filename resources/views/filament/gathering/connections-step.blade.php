<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Operator-only: the WordPress connection, keys, and news feeds. State summarized here; management flows open the existing surfaces.'])

        @php $summary = $this->summary; @endphp

        <div class="g-grid2">
            <div class="g-card">
                <h3>WordPress & credentials</h3>
                <div class="g-list">
                    <div class="g-item">
                        <span>WordPress connection</span>
                        <span class="g-seed {{ $summary['wp']['present'] && ! $summary['wp']['compromised'] ? 'confirmed' : '' }}">
                            {{ $summary['wp']['present'] ? ($summary['wp']['compromised'] ? 'flagged compromised' : 'connected') : 'not connected' }}
                        </span>
                    </div>
                    <div class="g-item"><span>Credentials on file</span><span class="g-muted">{{ $summary['wp']['provider_count'] }}</span></div>
                </div>
                <a class="g-btn" href="{{ $this->connectionsUrl() }}" wire:navigate>Open Connections →</a>
            </div>

            <div class="g-card">
                <h3>News feeds</h3>
                <div class="g-list">
                    <div class="g-item"><span>Feeds</span><span class="g-muted">{{ $summary['feeds']['total'] }} total · {{ $summary['feeds']['enabled'] }} enabled</span></div>
                </div>
                <a class="g-btn" href="{{ $this->feedsUrl() }}" wire:navigate>Open Feeds →</a>
            </div>
        </div>

        <div class="g-card" style="margin-top:16px">
            <div style="display:flex;align-items:center;gap:10px">
                <h3 style="margin:0">Lead-capture form (embedded)</h3>
                <span class="g-seed {{ $this->leadFormSet() ? 'confirmed' : '' }}">{{ $this->leadFormSet() ? 'on file' : 'not set' }}</span>
            </div>
            <p class="g-muted" style="margin:8px 0 10px">
                Forms live in <strong>Settings → Lead forms</strong> (one per office, one per service — the form itself tells the
                CRM where the lead came from). Each Office and Service picks its own form; this is the <strong>site default</strong>
                the home page and any page without an office / service form render — in the hero on home, office and town pages,
                beside the description on service pages.
            </p>
            @php $leadFormOptions = $this->leadFormOptions; @endphp
            <label class="g-muted" style="display:block;margin:0 0 4px;font-weight:600">Site default form</label>
            <select wire:model="defaultLeadFormId" class="g-input" style="width:100%;max-width:420px;margin-bottom:12px">
                <option value="">— none (use the pasted embed below, else call-button only) —</option>
                @foreach ($leadFormOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <p class="g-muted" style="margin:0 0 6px">
                Legacy single paste — the last fallback when no library form applies. The <code>&lt;iframe&gt;</code> (plus any
                loader <code>&lt;script&gt;</code>) from any provider. Leave blank and pages with no form show the “Call Now” button alone.
            </p>
            <textarea
                wire:model="formEmbed"
                rows="5"
                class="g-input"
                style="width:100%;font-family:ui-monospace,monospace;font-size:12.5px"
                placeholder="&lt;iframe src=&quot;https://your-form-provider.com/embed/…&quot; …&gt;&lt;/iframe&gt;&lt;script src=&quot;…form loader…&quot;&gt;&lt;/script&gt;"
            ></textarea>
            <div style="margin-top:10px">
                <button type="button" class="g-btn" wire:click="saveLeadForm" wire:loading.attr="disabled" wire:target="saveLeadForm">
                    <span wire:loading.remove wire:target="saveLeadForm">Save lead form</span>
                    <span wire:loading wire:target="saveLeadForm">Saving…</span>
                </button>
            </div>

            <label style="display:flex;align-items:flex-start;gap:8px;margin-top:16px;cursor:pointer">
                <input type="checkbox" wire:model="referralDefault" style="margin-top:3px">
                <span>
                    <strong>New services default to referral mode</strong>
                    <span class="g-muted" style="display:block;font-size:12.5px">
                        For a tenant that refers work rather than performing it: any service added after this is set to
                        referral mode by default (no price range, no Service-provider schema, a referral CTA). You can still
                        turn it off on an individual service, and this never changes services that already exist.
                    </span>
                </span>
            </label>
        </div>

        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
