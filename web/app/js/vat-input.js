/*
 * VAT input widget — EU-format VAT number, entered twice to catch typos, with an
 * optional live check against the official EU VIES service.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The canonical (hidden) value is only populated when the two entries match AND
 * the value passes a light EU format check, so a half-typed or mismatched number
 * is never saved. The VIES lookup is ADVISORY: its outcome is shown to the user
 * but never blocks saving (VIES has outages and the occasional false negative).
 */
(function () {
    'use strict';

    function normalize(s) {
        return (s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    // Prefix + 2..13 alphanumerics. EL = Greece, XI = Northern Ireland.
    var FORMAT_RE = /^(AT|BE|BG|HR|CY|CZ|DK|EE|FI|FR|DE|EL|HU|IE|IT|LV|LT|LU|MT|NL|PL|PT|RO|SK|SI|ES|SE|XI)[0-9A-Z]{2,13}$/;

    function initWidget(widget) {
        if (widget.__vatInit) { return; }
        widget.__vatInit = true;

        var primary = widget.querySelector('.vat-primary');
        var confirm = widget.querySelector('.vat-confirm');
        var hidden = widget.querySelector('input[type="hidden"]');
        var msg = widget.querySelector('.vat-validation-msg');
        var endpoint = widget.getAttribute('data-vies-endpoint');
        if (!primary || !confirm || !hidden) { return; }

        var viesTimer = null;
        var lastChecked = null;

        function setMsg(text, color) {
            if (!msg) { return; }
            if (text) {
                msg.textContent = text;
                msg.style.color = color || '#ef4444';
                msg.style.display = 'block';
            } else {
                msg.style.display = 'none';
            }
        }

        function fireSave() {
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function checkVies(vat) {
            if (!endpoint || vat === lastChecked) { return; }
            lastChecked = vat;
            setMsg('Checking VAT number with VIES…', '#6b7280');
            fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'vat=' + encodeURIComponent(vat), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || d.reachable === false) {
                        setMsg('Could not reach VIES right now — the number was saved; please double-check it.', '#b45309');
                        return;
                    }
                    if (d.valid) {
                        setMsg('✓ Valid VAT number' + (d.name ? ' — ' + d.name : ''), '#065f46');
                    } else {
                        // Advisory: the number IS saved; we just warn that VIES could
                        // not confirm it as valid.
                        setMsg('⚠ VAT number not valid — saved anyway. Please verify it is correct.', '#b45309');
                    }
                })
                .catch(function () {
                    setMsg('Could not reach VIES right now — the number was saved; please double-check it.', '#b45309');
                });
        }

        function evaluate(runVies) {
            var pv = normalize(primary.value);
            var cv = normalize(confirm.value);
            // Reflect the normalised value back so the user sees what is stored.
            if (primary.value !== pv) { primary.value = pv; }
            if (confirm.value !== cv) { confirm.value = cv; }

            if (!pv && !cv) {
                hidden.value = '';
                setMsg('');
                fireSave();
                return;
            }
            if (pv !== cv) {
                hidden.value = '';
                setMsg('The two VAT numbers do not match.');
                return;
            }
            if (!FORMAT_RE.test(pv)) {
                hidden.value = '';
                setMsg('Enter a valid EU VAT number, including the country prefix (e.g. DE123456789).');
                return;
            }

            // Confirmed match + valid format -> store it.
            hidden.value = pv;
            fireSave();

            if (runVies) {
                clearTimeout(viesTimer);
                viesTimer = setTimeout(function () { checkVies(pv); }, 400);
            } else {
                setMsg('✓ Format looks valid.', '#065f46');
            }
        }

        primary.addEventListener('input', function () { evaluate(false); });
        confirm.addEventListener('input', function () { evaluate(false); });
        primary.addEventListener('blur', function () { evaluate(true); });
        confirm.addEventListener('blur', function () { evaluate(true); });

        // Prefilled (returning user) — validate quietly and confirm with VIES once.
        if (normalize(primary.value) && normalize(primary.value) === normalize(confirm.value)) {
            evaluate(true);
        }
    }

    function initAll(root) {
        (root || document).querySelectorAll('[data-vat-widget]').forEach(initWidget);
    }

    if (document.readyState !== 'loading') { initAll(); }
    else { document.addEventListener('DOMContentLoaded', function () { initAll(); }); }

    window.initVatWidgets = initAll;
})();
