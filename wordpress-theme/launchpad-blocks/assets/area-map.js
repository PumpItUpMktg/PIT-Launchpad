/*
 * Launchpad — "Areas we serve" interactive map.
 *
 * Draws the served-county polygons + tiered town markers from window.lpAreaMap (printed by the
 * companion plugin) into .lp-areas-map. Counties fill with the theme's brand primary; the default
 * view shows counties only and towns reveal as the visitor zooms in (bigger towns first). The county
 * + town text beneath the map stays as the crawlable, no-JS fallback — this script only enhances.
 */
(function () {
    'use strict';

    function cssVar(el, name, fallback) {
        var v = getComputedStyle(el).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    function init() {
        var el = document.querySelector('.lp-areas-map');
        if (!el || el.dataset.lpMapReady === '1' || !window.L || !window.lpAreaMap) {
            return;
        }

        var data = window.lpAreaMap;
        var counties = Array.isArray(data.counties) ? data.counties : [];
        var cities = Array.isArray(data.cities) ? data.cities : [];
        var pin = data.pin && typeof data.pin.lat === 'number' && typeof data.pin.lng === 'number' ? data.pin : null;
        if (!counties.length && !cities.length && !pin) {
            return; // nothing to draw — leave the text fallback as-is
        }

        el.dataset.lpMapReady = '1';
        // Give the container height only now (JS-confirmed) so a no-JS visitor never sees an empty box.
        el.classList.add('lp-areas-map--live');

        var primary = cssVar(el, '--wp--preset--color--primary', '#123B6B');
        var accent = cssVar(el, '--wp--preset--color--accent', '#1D6FD6');

        var map = L.map(el, { scrollWheelZoom: false, zoomControl: true, attributionControl: true });

        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            subdomains: 'abcd',
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
        }).addTo(map);

        var bounds = L.latLngBounds([]);

        counties.forEach(function (county) {
            var rings = Array.isArray(county.rings) ? county.rings : [];
            var latlngs = rings
                .map(function (ring) {
                    return (Array.isArray(ring) ? ring : []).map(function (pt) { return [pt.lat, pt.lng]; });
                })
                .filter(function (ring) { return ring.length > 0; });
            if (!latlngs.length) {
                return;
            }
            var poly = L.polygon(latlngs, {
                color: primary,
                weight: 2,
                opacity: 0.9,
                fillColor: primary,
                fillOpacity: 0.12
            }).addTo(map);
            if (county.name) {
                poly.bindTooltip(county.name, { sticky: true, className: 'lp-map-tip' });
            }
            poly.on('mouseover', function () { poly.setStyle({ fillOpacity: 0.28 }); });
            poly.on('mouseout', function () { poly.setStyle({ fillOpacity: 0.12 }); });
            bounds.extend(poly.getBounds());
        });

        var markers = [];
        cities.forEach(function (city) {
            if (typeof city.lat !== 'number' || typeof city.lng !== 'number') {
                return;
            }
            var isMajor = city.tier === 'major';
            var isLarge = city.tier === 'large';
            var marker = L.circleMarker([city.lat, city.lng], {
                radius: isMajor ? 6 : (isLarge ? 5 : 4),
                color: accent,
                weight: 2,
                fillColor: '#ffffff',
                fillOpacity: 1
            });
            if (city.name) {
                marker.bindTooltip(city.name, { direction: 'top', className: 'lp-map-tip' });
            }
            marker._lpTier = city.tier;
            markers.push(marker);
            bounds.extend([city.lat, city.lng]);
        });

        // The location PIN (a Contact-page storefront) — a standout marker; the pin wins the view.
        if (pin) {
            var pinMarker = L.circleMarker([pin.lat, pin.lng], {
                radius: 9,
                color: accent,
                weight: 3,
                fillColor: accent,
                fillOpacity: 0.9
            }).addTo(map);
            if (pin.label) {
                pinMarker.bindTooltip(pin.label, { direction: 'top', permanent: false, className: 'lp-map-tip' });
            }
        }

        if (pin && !counties.length) {
            map.setView([pin.lat, pin.lng], 14); // storefront-only: street-level "find us"
        } else if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [24, 24] });
            if (pin) {
                bounds.extend([pin.lat, pin.lng]);
                map.fitBounds(bounds, { padding: [24, 24] });
            }
        } else if (data.center) {
            map.setView([data.center.lat, data.center.lng], 9);
        }

        // Counties-only by default; towns reveal on zoom-in — thresholds are RELATIVE to the fitted
        // view (bigger towns one step in, smaller towns two), so the default always reads uncluttered.
        var base = map.getZoom();
        var offset = { major: 1, large: 1, medium: 2, small: 2 };
        markers.forEach(function (m) { m._lpMinZoom = base + (offset[m._lpTier] || 2); });

        function applyZoom() {
            var z = map.getZoom();
            markers.forEach(function (m) {
                if (z >= m._lpMinZoom) {
                    if (!map.hasLayer(m)) { m.addTo(map); }
                } else if (map.hasLayer(m)) {
                    map.removeLayer(m);
                }
            });
        }

        map.on('zoomend', applyZoom);
        applyZoom();
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();
