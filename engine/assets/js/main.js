/**
 * AIGF Network — progressive enhancement only.
 *
 * Everything on these sites works without JavaScript: the menu is a plain list,
 * the FAQ is <details>/<summary>, tables are real tables with every row in
 * them. This file improves the mobile menu, adds search, the comparison
 * filters, the "on this page" highlight and the back-to-top button, and
 * reports outbound affiliate clicks.
 *
 * The production Content-Security-Policy forbids inline styles and form
 * submission: styling goes through classes and CSS custom properties set via
 * the CSSOM, and no form here is ever submitted.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    root.classList.add('js');

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* ---- sticky offsets ------------------------------------------------ */
    // The section bar sticks under the header, and the back-to-top button sits
    // above the review's offer bar: both need heights that depend on content.
    var header = document.querySelector('.site-header');
    var stickyCta = document.querySelector('.sticky-cta');

    function measure() {
        if (header) root.style.setProperty('--header-height', header.offsetHeight + 'px');
        if (stickyCta) root.style.setProperty('--sticky-cta-height', stickyCta.offsetHeight + 'px');
    }
    measure();
    if (window.ResizeObserver) {
        var sizes = new ResizeObserver(measure);
        if (header) sizes.observe(header);
        if (stickyCta) sizes.observe(stickyCta);
    }

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

    /* ---- search -------------------------------------------------------- */
    // One JSON file per site, fetched the first time someone types. Every
    // search box on the page shares it.
    var indexes = {};

    function loadIndex(url) {
        if (!indexes[url]) {
            indexes[url] = fetch(url, { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(function (docs) {
                    docs.forEach(function (d) {
                        d.nt = fold(d.t); d.ns = fold(d.s); d.nd = fold(d.d);
                        d.nh = fold(d.h); d.nx = fold(d.x);
                    });
                    return docs;
                })
                .catch(function (err) { delete indexes[url]; throw err; });
        }
        return indexes[url];
    }

    // Character ranges, built from code points so that no editor or minifier
    // can turn them into invisible literal characters.
    function range(from, to) { return String.fromCharCode(from) + '-' + String.fromCharCode(to); }
    var accents = new RegExp('[' + range(0x300, 0x36f) + ']', 'g');
    // Japanese, Chinese and Korean are written without spaces between words:
    // there a word may start anywhere. Elsewhere it has to start a word, so
    // "old" does not find "hold" and "ai" does not find "again".
    var unspaced = new RegExp('[' + range(0x3040, 0x30ff) + range(0x3400, 0x9fff) + range(0xac00, 0xd7af) + ']');

    // Lower case, accents off ("Données" finds "donnees" and the other way
    // round), punctuation turned into spaces one for one, and a leading space
    // so that " word" marks the start of a word anywhere in the string.
    function fold(s) {
        return ' ' + String(s || '').toLowerCase().normalize('NFD').replace(accents, '').replace(/[^\p{L}\p{N}]/gu, ' ');
    }

    function tokens(query) {
        return fold(query).split(' ').filter(function (t) { return t.length > 0; });
    }

    function find(text, word) {
        return unspaced.test(word) ? text.indexOf(word) : text.indexOf(' ' + word);
    }

    function search(docs, query) {
        var words = tokens(query);
        if (!words.length) return [];
        var phrase = fold(query).trim();
        var hits = [];
        var most = 0;
        docs.forEach(function (d) {
            var score = 0, matched = 0;
            for (var i = 0; i < words.length; i++) {
                var w = words[i], s = 0;
                var inTitle = find(d.nt, w);
                if (inTitle !== -1) s += inTitle === 0 ? 14 : 10;
                if (find(d.nh, w) !== -1) s += 4;
                if (find(d.nd, w) !== -1) s += 3;
                if (find(d.ns, w) !== -1) s += 2;
                if (find(d.nx, w) !== -1) s += 1;
                if (s > 0) matched++;
                score += s;
            }
            if (!matched) return;
            if (words.length > 1 && d.nt.indexOf(phrase) !== -1) score += 15;
            most = Math.max(most, matched);
            hits.push({ doc: d, score: score, matched: matched });
        });
        // Pages with every word when there are any; otherwise the pages that
        // come closest (a 404 address rarely matches a title word for word).
        return hits
            .filter(function (h) { return h.matched === most; })
            .sort(function (a, b) { return b.score - a.score; })
            .map(function (h) { return h.doc; });
    }

    // The description, unless the words only occur further down the text: then
    // the passage around the first of them.
    function snippet(doc, query) {
        var words = tokens(query);
        var inDescription = words.some(function (w) { return find(doc.nd, w) !== -1; });
        if (doc.d && (inDescription || !doc.x)) return doc.d;
        for (var i = 0; i < words.length; i++) {
            var at = find(doc.nx, words[i]);
            if (at !== -1) {
                var start = Math.max(0, at - 70);
                return (start > 0 ? '… ' : '') + doc.x.slice(start, at + 110).trim() + ' …';
            }
        }
        return doc.d || doc.x.slice(0, 160);
    }

    // Text with the searched words wrapped in <mark>, built from text nodes so
    // nothing from the index is ever parsed as HTML.
    // Words are marked where they start a word, as search() matches them.
    function highlight(el, text, query) {
        var words = query.split(/[^\p{L}\p{N}]+/u).filter(Boolean).map(function (w) {
            var escaped = w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return unspaced.test(w) ? escaped : '(?<![\\p{L}\\p{N}])' + escaped;
        });
        if (!words.length) { el.textContent = text; return; }
        var pattern;
        try {
            pattern = new RegExp('(' + words.join('|') + ')', 'giu');
        } catch (e) {
            el.textContent = text; // a browser without lookbehind: no marks
            return;
        }
        var parts = text.split(pattern);
        parts.forEach(function (part, i) {
            if (!part) return;
            if (i % 2 === 1) {
                var mark = document.createElement('mark');
                mark.textContent = part;
                el.appendChild(mark);
            } else {
                el.appendChild(document.createTextNode(part));
            }
        });
    }

    function render(box, docs, query) {
        var list = box.querySelector('.search-results');
        var status = box.querySelector('.search-box__status');
        var limit = parseInt(box.getAttribute('data-limit'), 10) || 8;
        list.textContent = '';
        if (!query.trim()) {
            list.hidden = true;
            status.textContent = '';
            return;
        }
        var found = search(docs, query);
        found.slice(0, limit).forEach(function (doc) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.className = 'search-result';
            a.href = doc.u;
            if (doc.s) {
                var section = document.createElement('span');
                section.className = 'search-result__section';
                section.textContent = doc.s;
                a.appendChild(section);
            }
            var title = document.createElement('span');
            title.className = 'search-result__title';
            highlight(title, doc.t, query);
            a.appendChild(title);
            var text = snippet(doc, query);
            if (text) {
                var p = document.createElement('span');
                p.className = 'search-result__text';
                highlight(p, text, query);
                a.appendChild(p);
            }
            li.appendChild(a);
            list.appendChild(li);
        });
        list.hidden = found.length === 0;
        status.textContent = found.length ? '' : (box.getAttribute('data-empty') || '').replace('%s', query.trim());
    }

    function initSearchBox(box) {
        var form = box.querySelector('form');
        var input = box.querySelector('input[type="search"]');
        var page = box.getAttribute('data-page');
        var timer = null;

        function run() {
            var query = input.value;
            loadIndex(box.getAttribute('data-index')).then(function (docs) {
                if (input.value === query) render(box, docs, query);
            }, function () {
                // No index (offline, blocked): fall back to the results page.
                box.querySelector('.search-box__status').textContent = '';
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 120);
        });
        // Warm the index up as soon as the reader shows intent.
        input.addEventListener('focus', function () {
            loadIndex(box.getAttribute('data-index')).catch(function () {});
        }, { once: true });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var query = input.value.trim();
            if (!query) { input.focus(); return; }
            if (location.pathname === page) {
                history.replaceState(null, '', page + '?q=' + encodeURIComponent(query));
                run();
            } else {
                location.href = page + '?q=' + encodeURIComponent(query);
            }
        });

        // Arrow down from the box walks into the results.
        box.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            var links = Array.prototype.slice.call(box.querySelectorAll('.search-result'));
            if (!links.length) return;
            var at = links.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                (links[at + 1] || links[0]).focus();
            } else if (at !== -1) {
                e.preventDefault();
                (at === 0 ? input : links[at - 1]).focus();
            }
        });

        // Results page: the query comes in the address. The 404 page searches
        // for the words of the address that did not exist.
        var initial = '';
        if (location.pathname === page) {
            initial = new URLSearchParams(location.search).get('q') || '';
        } else if (box.hasAttribute('data-from-path')) {
            var parts = location.pathname.split('/').filter(Boolean);
            initial = decodeURIComponent(parts[parts.length - 1] || '').replace(/\.html?$/, '').replace(/[-_]+/g, ' ');
        }
        if (initial) {
            input.value = initial;
            run();
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-search-box]'), initSearchBox);

    var searchToggle = document.querySelector('[data-search-toggle]');
    var searchPanel = document.querySelector('[data-search-panel]');
    if (searchToggle && searchPanel) {
        var setPanel = function (open) {
            searchPanel.hidden = !open;
            searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                applyNavState(false);
                searchPanel.querySelector('input').focus();
            }
        };
        searchToggle.addEventListener('click', function (e) {
            e.preventDefault();
            setPanel(searchPanel.hidden);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !searchPanel.hidden) {
                setPanel(false);
                searchToggle.focus();
            }
        });
        document.addEventListener('click', function (e) {
            if (!searchPanel.hidden && !searchPanel.contains(e.target) && !searchToggle.contains(e.target)) {
                setPanel(false);
            }
        });
    }

    /* ---- comparison table: filters and sorting ------------------------- */
    Array.prototype.forEach.call(document.querySelectorAll('[data-compare-tools]'), function (tools) {
        var section = tools.closest('.compare');
        var lists = section.querySelectorAll('[data-compare-list]');
        var status = tools.querySelector('[data-compare-status]');
        var empty = section.querySelector('[data-compare-empty]');
        var checks = tools.querySelectorAll('input[data-filter]');
        var selects = tools.querySelectorAll('select[data-filter]');
        var sorter = tools.querySelector('[data-sort]');
        var total = lists.length ? lists[0].querySelectorAll('[data-compare-item]').length : 0;

        function num(el, name, fallback) {
            var v = el.getAttribute('data-' + name);
            return v === null || v === '' ? fallback : parseFloat(v);
        }

        function matches(item) {
            for (var i = 0; i < checks.length; i++) {
                if (checks[i].checked && item.getAttribute('data-' + checks[i].getAttribute('data-filter')) !== '1') return false;
            }
            for (var j = 0; j < selects.length; j++) {
                var value = selects[j].value;
                if (!value) continue;
                var kind = selects[j].getAttribute('data-filter');
                if (kind === 'platform' && (' ' + item.getAttribute('data-platforms') + ' ').indexOf(' ' + value + ' ') === -1) return false;
                if (kind === 'rating' && num(item, 'rating', 0) < parseFloat(value)) return false;
                // An app that does not publish its price cannot be under a limit.
                if (kind === 'price' && num(item, 'price', Infinity) > parseFloat(value) + 0.001) return false;
            }
            return true;
        }

        function compare(a, b) {
            var mode = sorter ? sorter.value : 'rank';
            var pa = num(a, 'position', 0), pb = num(b, 'position', 0);
            var d = 0;
            if (mode === 'rating') d = num(b, 'rating', 0) - num(a, 'rating', 0);
            if (mode === 'price-asc' || mode === 'price-desc') {
                var xa = num(a, 'price', null), xb = num(b, 'price', null);
                if (xa === null || xb === null) {
                    d = xa === null && xb === null ? 0 : (xa === null ? 1 : -1); // unknown price last
                } else {
                    d = mode === 'price-asc' ? xa - xb : xb - xa;
                }
            }
            return d || pa - pb;
        }

        function apply() {
            var shown = 0;
            Array.prototype.forEach.call(lists, function (list) {
                var items = Array.prototype.slice.call(list.querySelectorAll('[data-compare-item]'));
                items.sort(compare).forEach(function (item) {
                    item.hidden = !matches(item);
                    list.appendChild(item);
                });
                if (list === lists[0]) shown = items.filter(function (i) { return !i.hidden; }).length;
            });
            var filtered = shown < total;
            status.textContent = filtered ? status.getAttribute('data-template').replace('%1', shown).replace('%2', total) : '';
            empty.hidden = shown > 0;
        }

        function reset() {
            Array.prototype.forEach.call(checks, function (c) { c.checked = false; });
            Array.prototype.forEach.call(selects, function (s) { s.value = ''; });
            if (sorter) sorter.value = 'rank';
            apply();
        }

        tools.addEventListener('change', apply);
        Array.prototype.forEach.call(section.querySelectorAll('[data-compare-reset]'), function (b) {
            b.addEventListener('click', reset);
        });
        tools.hidden = false;
    });

    /* ---- "on this page" bar ------------------------------------------- */
    var sectionNav = document.querySelector('[data-section-nav]');
    if (sectionNav && window.IntersectionObserver) {
        var links = Array.prototype.slice.call(sectionNav.querySelectorAll('a[href^="#"]'));
        var targets = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
        var visible = {};

        var mark = function () {
            // The first section still on screen is the one being read.
            var current = null;
            for (var i = 0; i < targets.length; i++) {
                if (targets[i] && visible[targets[i].id]) { current = i; break; }
            }
            links.forEach(function (a, i) {
                var active = i === current;
                a.classList.toggle('is-active', active);
                if (active) {
                    a.setAttribute('aria-current', 'location');
                    var list = a.closest('ul');
                    var left = a.offsetLeft - (list.clientWidth - a.offsetWidth) / 2;
                    if (list.scrollWidth > list.clientWidth) list.scrollTo({ left: left, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
                } else {
                    a.removeAttribute('aria-current');
                }
            });
        };

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
            mark();
        }, { rootMargin: '-30% 0px -60% 0px' });
        targets.forEach(function (t) { if (t) observer.observe(t); });
    }

    /* ---- back to top --------------------------------------------------- */
    var toTop = document.querySelector('[data-to-top]');
    if (toTop) {
        var ticking = false;
        var update = function () {
            ticking = false;
            // Shown once the reader is more than two screens down.
            toTop.hidden = window.scrollY < window.innerHeight * 2;
        };
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
        }, { passive: true });
        update();
        toTop.addEventListener('click', function (e) {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
            var target = document.querySelector('.brand');
            if (target) target.focus({ preventScroll: true });
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
