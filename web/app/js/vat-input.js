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
 * When VIES confirms the number, an info (i) button reveals the registered
 * company name/address in a modal.
 */
(function () {
    'use strict';

    function normalize(s) {
        return (s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    // Prefix + 2..13 alphanumerics. EL = Greece, XI = Northern Ireland.
    var FORMAT_RE = /^(AT|BE|BG|HR|CY|CZ|DK|EE|FI|FR|DE|EL|HU|IE|IT|LV|LT|LU|MT|NL|PL|PT|RO|SK|SI|ES|SE|XI)[0-9A-Z]{2,13}$/;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    // ---- Shared details modal -------------------------------------------------
    var modal = null;
    function ensureModal() {
        if (modal) return modal;
        var overlay = document.createElement('div');
        overlay.className = 'vat-info-overlay';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(17,24,39,0.55); display:none; align-items:center; justify-content:center; z-index:10000; padding:20px;';
        overlay.innerHTML =
            '<div class="vat-info-card" role="dialog" aria-modal="true" aria-label="VAT registration details" style="background:#fff; border-radius:10px; max-width:460px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">' +
              '<div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e5e7eb;">' +
                '<strong style="font-size:15px; color:#111827;">VAT registration details</strong>' +
                '<button type="button" class="vat-info-close" aria-label="Close" style="border:none; background:none; font-size:22px; line-height:1; cursor:pointer; color:#6b7280;">&times;</button>' +
              '</div>' +
              '<div class="vat-info-body" style="padding:18px; font-size:14px; color:#374151; line-height:1.5;"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        function close() { overlay.style.display = 'none'; }
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        overlay.querySelector('.vat-info-close').addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        modal = { overlay: overlay, body: overlay.querySelector('.vat-info-body'), close: close };
        return modal;
    }

    function showModal(result) {
        var m = ensureModal();
        function row(label, value) {
            if (!value) return '';
            return '<div style="margin-bottom:10px;"><div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af;">' +
                   esc(label) + '</div><div style="white-space:pre-line;">' + esc(value) + '</div></div>';
        }
        var status;
        if (result.loading) {
            status = '<span style="color:#6b7280;">Checking with VIES…</span>';
        } else if (result.reachable === false) {
            status = '<span style="color:#b45309; font-weight:600;">Could not reach VIES right now</span>';
        } else if (result.unavailable) {
            status = '<span style="color:#b45309; font-weight:600;">VIES temporarily unavailable for this country</span>';
        } else if (result.valid) {
            status = '<span style="color:#065f46; font-weight:600;">&#10003; Valid (VIES)</span>';
        } else {
            status = '<span style="color:#b45309; font-weight:600;">Not valid in VIES</span>';
        }
        var when = '';
        if (result.requestDate) {
            var d = new Date(result.requestDate);
            when = isNaN(d.getTime()) ? result.requestDate : d.toLocaleString();
        }
        m.body.innerHTML =
            row('VAT number', result.vat) +
            '<div style="margin-bottom:10px;"><div style="font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af;">Status</div><div>' + status + '</div></div>' +
            row('Registered name', result.name) +
            row('Registered address', result.address) +
            row('Checked', when) +
            '<div style="margin-top:14px; font-size:12px; color:#9ca3af;">Source: EU VIES (ec.europa.eu)</div>';
        m.overlay.style.display = 'flex';
    }

    // Look up a VAT number against VIES and show the details modal. Used by the
    // standalone info (ⓘ) buttons rendered next to a stored VAT number (in both
    // view and edit mode), so details are available on demand regardless of
    // whether an inline live-check already ran.
    function fetchAndShow(vat, endpoint) {
        vat = (vat || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!vat) { return; }
        endpoint = endpoint || 'api/vat-validate.php';
        showModal({ vat: vat, loading: true });
        fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'vat=' + encodeURIComponent(vat), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                showModal({
                    vat: vat,
                    reachable: !d || d.reachable !== false,
                    unavailable: !!(d && (d.unavailable || d.valid === null)),
                    valid: !!(d && d.valid),
                    name: (d && d.name) || '',
                    address: (d && d.address) || '',
                    requestDate: (d && d.requestDate) || ''
                });
            })
            .catch(function () { showModal({ vat: vat, reachable: false }); });
    }
    window.showVatInfo = fetchAndShow;

    // ---- Widget ---------------------------------------------------------------
    function initWidget(widget) {
        if (widget.__vatInit) { return; }
        widget.__vatInit = true;

        var primary = widget.querySelector('.vat-primary');
        var confirm = widget.querySelector('.vat-confirm');
        var hidden = widget.querySelector('input[type="hidden"]');
        var msg = widget.querySelector('.vat-validation-msg');
        var infoBtn = widget.querySelector('.vat-info-btn');
        var endpoint = widget.getAttribute('data-vies-endpoint');
        if (!primary || !confirm || !hidden) { return; }

        var viesTimer = null;
        var lastChecked = null;
        var lastResult = null;

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

        function hideInfo() {
            lastResult = null;
            if (infoBtn) infoBtn.style.display = 'none';
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
                    if (d.unavailable || d.valid === null) {
                        // VIES is up but the country's registry was momentarily unavailable.
                        lastChecked = null; // allow a re-check on next blur
                        setMsg('VIES is temporarily unavailable for this country — the number was saved; please verify it later.', '#b45309');
                        return;
                    }
                    if (d.valid) {
                        lastResult = { vat: vat, valid: true, name: d.name || '', address: d.address || '', requestDate: d.requestDate || '' };
                        setMsg('✓ Valid VAT number' + (d.name ? ' — ' + d.name : '') + (d.name ? '  (ⓘ for details)' : ''), '#065f46');
                        if (infoBtn) infoBtn.style.display = 'inline-flex';
                    } else {
                        hideInfo();
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
            if (primary.value !== pv) { primary.value = pv; }
            if (confirm.value !== cv) { confirm.value = cv; }

            if (!pv && !cv) {
                hidden.value = '';
                hideInfo();
                setMsg('');
                fireSave();
                return;
            }
            if (pv !== cv) {
                hidden.value = '';
                hideInfo();
                setMsg('The two VAT numbers do not match.');
                return;
            }
            if (!FORMAT_RE.test(pv)) {
                hidden.value = '';
                hideInfo();
                setMsg('Enter a valid EU VAT number, including the country prefix (e.g. DE123456789).');
                return;
            }

            hidden.value = pv;
            fireSave();

            if (runVies) {
                clearTimeout(viesTimer);
                viesTimer = setTimeout(function () { checkVies(pv); }, 400);
            } else {
                // Typing changed the number -> any prior VIES detail is stale.
                if (lastResult && lastResult.vat !== pv) hideInfo();
                setMsg('✓ Format looks valid.', '#065f46');
            }
        }

        primary.addEventListener('input', function () { evaluate(false); });
        confirm.addEventListener('input', function () { evaluate(false); });
        primary.addEventListener('blur', function () { evaluate(true); });
        confirm.addEventListener('blur', function () { evaluate(true); });

        if (infoBtn) {
            infoBtn.addEventListener('click', function () {
                if (lastResult) showModal(lastResult);
            });
        }

        // Prefilled (returning user) — validate quietly and confirm with VIES once.
        if (normalize(primary.value) && normalize(primary.value) === normalize(confirm.value)) {
            evaluate(true);
        }
    }

    function initAll(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-vat-widget]').forEach(initWidget);
        // Standalone info buttons (e.g. next to a stored VAT number in view mode):
        // fetch the VIES details on click and open the modal.
        scope.querySelectorAll('[data-vat-info]').forEach(function (btn) {
            if (btn.__vatInfoInit) { return; }
            btn.__vatInfoInit = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                fetchAndShow(btn.getAttribute('data-vat-info'), btn.getAttribute('data-vies-endpoint'));
            });
        });
    }

    if (document.readyState !== 'loading') { initAll(); }
    else { document.addEventListener('DOMContentLoaded', function () { initAll(); }); }

    window.initVatWidgets = initAll;
})();
