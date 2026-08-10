<?php
/**
 * Supplementary CSP-safe UI behaviors.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The bulk of the app's declarative behaviors already live in
 * app/js/event-handlers.js, which is the delegation layer that let the CSP drop
 * 'unsafe-inline'. Anything it already supports -- data-confirm,
 * data-stop-propagation, data-toggle, data-close, data-href, data-print,
 * data-disable-on-click and friends -- belongs there, NOT here. Defining them
 * twice would bind two listeners and, for data-confirm, prompt twice.
 *
 * This file carries only the few hooks event-handlers.js does not implement,
 * for pages that need them:
 *
 *   data-disable-on-submit     on a <form>: disable its submit button once
 *                              submitted, so it cannot be double-posted.
 *                              (event-handlers.js has data-disable-on-click,
 *                              which is a different trigger.)
 *   data-hide-on-error         on an <img>: hide it if the image fails to load.
 *   data-set-field="name"      on click, write data-set-value into the named
 *   data-set-value="value"     field of the owning form before it submits.
 *
 * Include it before </body> ONLY on pages that use one of the above:
 *
 *     <?php include __DIR__ . '/includes/csp_behaviors.php'; ?>
 */
?>
<script nonce="<?php echo cspNonce(); ?>">
(function () {
    document.querySelectorAll('form[data-disable-on-submit]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; }
        });
    });

    document.querySelectorAll('img[data-hide-on-error]').forEach(function (img) {
        img.addEventListener('error', function () { img.style.display = 'none'; });
        // The image may already have failed before this script ran, in which
        // case the error event is long gone; catch that case directly.
        if (img.complete && img.naturalWidth === 0) { img.style.display = 'none'; }
    });

    document.querySelectorAll('[data-set-field]').forEach(function (el) {
        el.addEventListener('click', function () {
            var form = el.form || el.closest('form');
            if (!form) { return; }
            var target = form.querySelector('[name="' + el.getAttribute('data-set-field') + '"]');
            if (target) { target.value = el.getAttribute('data-set-value'); }
        });
    });
})();
</script>
