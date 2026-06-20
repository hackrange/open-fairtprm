/*
 * Phone input widget — international dialling code + national number, normalised
 * to canonical E.164 ("+13144445544").
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * For every [data-phone-widget] on the page we wire the country <select> and the
 * national-number <input> so that any combination the user types — "3144445544",
 * "314-444-5544", "(314) 444-5544" — collapses to "+<dial><digits>" in the hidden
 * canonical input. We then dispatch input/change on that hidden input so existing
 * autosave handlers (which listen on .autosave-input) pick the value up unchanged.
 */
(function () {
    'use strict';

    function digitsOnly(s) {
        return (s || '').replace(/\D+/g, '');
    }

    function initWidget(widget) {
        if (widget.__phoneInit) { return; }
        widget.__phoneInit = true;

        var cc = widget.querySelector('.phone-cc');
        var nat = widget.querySelector('.phone-national');
        var hidden = widget.querySelector('input[type="hidden"]');
        var msg = widget.querySelector('.phone-validation-msg');
        if (!cc || !nat || !hidden) { return; }

        function currentDial() {
            var opt = cc.options[cc.selectedIndex];
            return opt ? digitsOnly(opt.getAttribute('data-dial')) : '';
        }

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

        cc.addEventListener('change', function () { recompute(true); });
        nat.addEventListener('input', function () { recompute(true); });
        nat.addEventListener('blur', function () { recompute(true); });
    }

    function initAll(root) {
        (root || document).querySelectorAll('[data-phone-widget]').forEach(initWidget);
    }

    if (document.readyState !== 'loading') { initAll(); }
    else { document.addEventListener('DOMContentLoaded', function () { initAll(); }); }

    // Expose for dynamically-injected widgets (e.g. modal forms).
    window.initPhoneWidgets = initAll;
})();
