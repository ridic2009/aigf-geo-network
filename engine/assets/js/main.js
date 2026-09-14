/**
 * AIGF Network — progressive enhancement only.
 *
 * Everything on these sites works without JavaScript: the menu is a plain list,
 * the FAQ is <details>/<summary>, tables are real tables. This file only
 * improves the mobile menu and reports outbound affiliate clicks.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    /* ---- mobile navigation -------------------------------------------- */
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('site-nav');
    var mobile = window.matchMedia('(max-width: 859px)');

    function applyNavState(open) {
        if (!toggle || !nav) return;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (mobile.matches) {
            nav.hidden = !open;
        } else {
            nav.hidden = false;
        }
    }

    if (toggle && nav) {
        applyNavState(false);
        toggle.addEventListener('click', function () {
            applyNavState(toggle.getAttribute('aria-expanded') !== 'true');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                applyNavState(false);
                toggle.focus();
            }
        });
        mobile.addEventListener('change', function () {
            applyNavState(false);
        });
    }

    /* ---- outbound affiliate clicks ------------------------------------ */
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[data-product]') : null;
        if (!link) return;
        if (typeof window.aigfTrack === 'function') {
            window.aigfTrack('affiliate_click', {
                product: link.getAttribute('data-product'),
                href: link.getAttribute('href'),
                page: window.location.pathname
            });
        }
    }, { passive: true });
})();
