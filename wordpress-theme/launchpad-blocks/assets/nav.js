/**
 * Grouped-nav interaction for the header services menu (.lp-services-nav).
 *
 * Progressive enhancement over the CSS hover/focus dropdown: makes each hub group CLICK-to-open (hover
 * fails on touch, and this is a mobile-heavy audience). The hub heading stays a real link to the hub page;
 * a disclosure toggle button is injected beside it to open/close the spokes panel. Keyboard accessible with
 * proper ARIA (aria-expanded/aria-controls), closes on Escape and on outside click, and only one group is
 * open at a time. No framework; self-inits (readyState-aware). If the script never runs, the CSS hover/
 * focus fallback still reveals the panel.
 */
(function () {
    'use strict';

    var OPEN = 'is-open';
    var uid = 0;

    function init() {
        var navs = document.querySelectorAll('.lp-services-nav');
        for (var n = 0; n < navs.length; n++) {
            enhance(navs[n]);
        }
    }

    function enhance(nav) {
        nav.classList.add('lp-js');   // CSS drops the ::after caret in favour of the injected toggle
        var groups = nav.querySelectorAll('.lp-has-sub');
        for (var i = 0; i < groups.length; i++) {
            wire(nav, groups[i]);
        }

        // Close on outside click / focus leaving the whole menu.
        document.addEventListener('click', function (e) {
            if (!nav.contains(e.target)) {
                closeAll(nav);
            }
        });
        // Escape closes the open group and returns focus to its toggle.
        nav.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                var open = nav.querySelector('.lp-has-sub.' + OPEN);
                if (open) {
                    var toggle = open.querySelector('.lp-subnav-toggle');
                    close(open);
                    if (toggle) { toggle.focus(); }
                }
            }
        });
    }

    function wire(nav, group) {
        var panel = group.querySelector('.lp-subnav');
        if (!panel) { return; }

        if (!panel.id) { panel.id = 'lp-subnav-' + (++uid); }
        panel.setAttribute('role', 'region');

        var heading = group.querySelector('a, .lp-nav-head');
        var name = heading ? heading.textContent.trim() : 'menu';
        panel.setAttribute('aria-label', name);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'lp-subnav-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', panel.id);
        toggle.setAttribute('aria-label', 'Toggle ' + name);
        toggle.innerHTML = '<span class="lp-caret" aria-hidden="true"></span>';

        // Insert the toggle right after the heading (or at the group start if none).
        if (heading && heading.nextSibling) {
            group.insertBefore(toggle, heading.nextSibling);
        } else {
            group.appendChild(toggle);
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (group.classList.contains(OPEN)) {
                close(group);
            } else {
                closeAll(nav);
                open(group);
            }
        });
    }

    function open(group) {
        group.classList.add(OPEN);
        setState(group, true);
    }

    function close(group) {
        group.classList.remove(OPEN);
        setState(group, false);
    }

    function closeAll(nav) {
        var open = nav.querySelectorAll('.lp-has-sub.' + OPEN);
        for (var i = 0; i < open.length; i++) {
            close(open[i]);
        }
    }

    function setState(group, expanded) {
        var toggle = group.querySelector('.lp-subnav-toggle');
        if (toggle) { toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false'); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
