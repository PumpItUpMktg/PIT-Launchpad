{{-- Shared coverage map (pins per base + county outlines + flagged directed towns) — ONE view of
     everywhere the business works, across all locations. Leaflet loads lazily; failures degrade
     to "no map" without breaking Livewire. --}}
<div class="lp-card" style="padding:8px">
    <div wire:ignore
        x-data="coverageMap(@js($this->mapData), @js($this->manualMarkers), @js($this->countyPolygons))"
        x-init="init()"
        x-on:locations-updated.window="render($event.detail.data ?? [], $event.detail.manual ?? [], $event.detail.polygons ?? [])">
        <div x-ref="map" class="lp-map"></div>
    </div>
</div>

{{-- Leaflet (OSM/CARTO tiles, no API key) --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script>
    // Defined as a plain global at parse time (NOT via alpine:init) so x-data can never
    // evaluate `coverageMap` before it exists — a throw in x-data would halt Alpine and,
    // with it, ALL Livewire interactivity. Every Leaflet touch is guarded so a failure
    // degrades to "no map", never a thrown init.
    window.coverageMap = (initial, initialManual, initialPolygons) => ({
            map: null,
            group: null,
            init() {
                try {
                    this.ensureLeaflet(() => {
                        try {
                            const el = this.$refs.map;
                            if (el._lpMap) {
                                this.map = el._lpMap;
                            } else {
                                this.map = L.map(el, { scrollWheelZoom: false }).setView([40.3, -74.6], 8);
                                L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                                    attribution: '© OpenStreetMap, © CARTO', maxZoom: 19,
                                }).addTo(this.map);
                                el._lpMap = this.map;
                            }
                            this.render(initial, initialManual, initialPolygons);
                        } catch (e) { console.error('coverage map init', e); }
                    });
                } catch (e) { console.error('coverage map', e); }
            },
            ensureLeaflet(cb) {
                if (window.L) return cb();
                const existing = document.getElementById('lp-leaflet-js');
                if (existing) { existing.addEventListener('load', cb); return; }
                const s = document.createElement('script');
                s.id = 'lp-leaflet-js';
                s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                s.onload = cb;
                s.onerror = () => console.error('Leaflet failed to load');
                document.head.appendChild(s);
            },
            render(data, manual, polygons) {
                if (!this.map || !window.L) return;
                if (this.group) this.map.removeLayer(this.group);
                this.group = L.layerGroup().addTo(this.map);
                const pts = [];
                (polygons || []).forEach((c) => {
                    (c.rings || []).forEach((ring) => {
                        if (!ring || !ring.length) return;
                        const latlngs = ring.map((p) => [p.lat, p.lng]);
                        L.polygon(latlngs, { color: '#0E6B6B', weight: 2, fillColor: '#0E6B6B', fillOpacity: 0.07 })
                            .bindTooltip((c.name ? c.name + ' County' : 'County'), { permanent: false }).addTo(this.group);
                        latlngs.forEach((ll) => pts.push(ll));
                    });
                });
                (data || []).forEach((d) => {
                    if (d.lat == null || d.lng == null) return;
                    L.circleMarker([d.lat, d.lng], { radius: 6, color: d.color, fillColor: d.color, fillOpacity: 1 })
                        .bindTooltip(d.name, { permanent: false }).addTo(this.group);
                    pts.push([d.lat, d.lng]);
                });
                (manual || []).forEach((d) => {
                    if (d.lat == null || d.lng == null) return;
                    L.marker([d.lat, d.lng], {
                        icon: L.divIcon({ html: '🚩', className: 'lp-flag', iconSize: [18, 18], iconAnchor: [4, 16] }),
                    }).bindTooltip(d.name + ' (added)', { permanent: false }).addTo(this.group);
                    pts.push([d.lat, d.lng]);
                });
                if (pts.length) this.map.fitBounds(L.latLngBounds(pts).pad(0.3));
            },
    });
</script>
