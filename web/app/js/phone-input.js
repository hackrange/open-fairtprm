/*
 * Phone input widget — international dialling code + national number, normalised
 * to canonical E.164 ("+13144445544").
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The country picker is a custom dropdown (a button + a searchable popup of flag
 * rows) rather than a native <select>, because flag icons cannot be rendered
 * inside a native <option> on every platform (Chrome/Windows shows no flag at
 * all). Whatever the user types in the national field — "3144445544",
 * "314-444-5544", "(314) 444-5544" — collapses to "+<dial><digits>" in the
 * hidden canonical input, and we dispatch input/change on that hidden input so
 * existing autosave handlers (which listen on .autosave-input) pick it up
 * unchanged. The hidden input is the only value submitted.
 */
(function () {
    'use strict';

    function digitsOnly(s) {
        return (s || '').replace(/\D+/g, '');
    }

    function initWidget(widget) {
        if (widget.__phoneInit) { return; }
        widget.__phoneInit = true;

        var ccWrap = widget.querySelector('[data-phone-cc]');
        var nat    = widget.querySelector('.phone-national');
        var hidden = widget.querySelector('input[type="hidden"]');
        var msg    = widget.querySelector('.phone-validation-msg');
        if (!ccWrap || !nat || !hidden) { return; }

        var toggle  = ccWrap.querySelector('.phone-cc-toggle');
        var search  = ccWrap.querySelector('.phone-cc-search');
        var togFlag = toggle ? toggle.querySelector('.phone-cc-flag') : null;
        var togDial = toggle ? toggle.querySelector('.phone-cc-dial') : null;
        var options = Array.prototype.slice.call(ccWrap.querySelectorAll('.phone-cc-option'));
        if (!toggle) { return; }

        // Option-row flags are lazy: their background-image is only applied the
        // first time the dropdown opens, so an unopened picker fetches nothing.
        var flagsHydrated = false;
        function hydrateFlags() {
            if (flagsHydrated) { return; }
            flagsHydrated = true;
            options.forEach(function (o) {
                var f = o.querySelector('.phone-cc-flag');
                var u = o.getAttribute('data-flag');
                if (f && u) { f.style.backgroundImage = "url('" + u + "')"; }
            });
        }

        function currentDial() { return digitsOnly(ccWrap.getAttribute('data-dial')); }

        function showMsg(text) {
            if (!msg) { return; }
            if (text) { msg.textContent = text; msg.style.display = 'block'; }
            else { msg.style.display = 'none'; }
        }

        function recompute(fireEvents) {
            var dial = currentDial();
            // National significant number: strip a leading trunk '0' commonly used
            // in domestic notation (e.g. UK "020..." -> "20...").
            var national = digitsOnly(nat.value).replace(/^0+/, '');
            var e164 = national ? ('+' + dial + national) : '';

            hidden.value = e164;

            if (national && (e164.length < 8 || e164.length > 16)) {
                showMsg('Please enter a valid phone number');
            } else {
                showMsg('');
            }

            if (fireEvents) {
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function selectOption(opt, fireEvents) {
            ccWrap.setAttribute('data-iso', opt.getAttribute('data-iso') || '');
            ccWrap.setAttribute('data-dial', opt.getAttribute('data-dial') || '');
            var u = opt.getAttribute('data-flag');
            if (togFlag && u) { togFlag.style.backgroundImage = "url('" + u + "')"; }
            if (togDial) { togDial.textContent = '+' + (opt.getAttribute('data-dial') || ''); }
            options.forEach(function (o) { o.classList.remove('active'); });
            opt.classList.add('active');
            recompute(fireEvents);
        }

        function visibleOptions() {
            return options.filter(function (o) { return !o.classList.contains('hidden'); });
        }

        function setActive(opt) {
            options.forEach(function (o) { o.classList.remove('active'); });
            if (opt) { opt.classList.add('active'); opt.scrollIntoView({ block: 'nearest' }); }
        }

        function filter(q) {
            q = (q || '').trim().toLowerCase();
            options.forEach(function (o) {
                var hay = o.getAttribute('data-search') || '';
                o.classList.toggle('hidden', !!q && hay.indexOf(q) === -1);
            });
        }

        function open() {
            hydrateFlags();
            ccWrap.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
            if (search) { search.value = ''; filter(''); }
            var act = ccWrap.querySelector('.phone-cc-option.active');
            if (act) { act.scrollIntoView({ block: 'nearest' }); }
            if (search) { search.focus(); }
        }

        function close() {
            ccWrap.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (ccWrap.classList.contains('open')) { close(); } else { open(); }
        });

        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (!ccWrap.classList.contains('open')) { open(); }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        options.forEach(function (o) {
            o.addEventListener('click', function () {
                selectOption(o, true);
                close();
                toggle.focus();
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                filter(this.value);
                var vis = visibleOptions();
                setActive(vis.length ? vis[0] : null);
            });
            search.addEventListener('keydown', function (e) {
                var vis = visibleOptions();
                var cur = ccWrap.querySelector('.phone-cc-option.active');
                var idx = vis.indexOf(cur);
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActive(vis[Math.min(idx + 1, vis.length - 1)] || vis[0]);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(vis[Math.max(idx - 1, 0)] || vis[0]);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (cur) { selectOption(cur, true); close(); toggle.focus(); }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    close();
                    toggle.focus();
                }
            });
        }

        // Close when clicking anywhere outside this widget's picker.
        document.addEventListener('click', function (e) {
            if (!ccWrap.contains(e.target)) { close(); }
        });

        nat.addEventListener('input', function () { recompute(true); });
        nat.addEventListener('blur', function () { recompute(true); });

        // Canonicalise a prefilled value once on load (no events fired).
        recompute(false);
    }

    function initAll(root) {
        (root || document).querySelectorAll('[data-phone-widget]').forEach(initWidget);
    }

    if (document.readyState !== 'loading') { initAll(); }
    else { document.addEventListener('DOMContentLoaded', function () { initAll(); }); }

    // Expose for dynamically-injected widgets (e.g. modal forms).
    window.initPhoneWidgets = initAll;
})();
