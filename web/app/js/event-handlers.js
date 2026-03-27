/**
 * Delegated Event Handlers for Open TPRM
 *
 * Replaces inline onclick/onchange/onsubmit event handlers with
 * data-attribute-driven event delegation. This allows the Content
 * Security Policy to drop 'unsafe-inline' from script-src.
 *
 * Supported data attributes:
 *   data-confirm="message"       - Confirmation dialog before action
 *   data-toggle="elementId"      - Toggle element visibility
 *   data-toggle-arrow="arrowId"  - Update arrow indicator on toggle (use with data-toggle)
 *   data-close="elementId"       - Hide element
 *   data-action="functionName"   - Call a global function
 *   data-arg="value"             - Argument for data-action function
 *   data-submit-form             - Submit parent form on change
 *   data-file-display="id"       - Show selected filename in target element
 *   data-stop-propagation        - Stop event propagation
 *   data-disable-on-click        - Disable button after click
 *   data-href="url"              - Navigate to URL on click
 *   data-print                   - Trigger window.print()
 *   data-close-window            - Trigger window.close()
 *   data-clipboard="text"        - Copy text to clipboard
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // =================================================================
        // Click: Confirmation dialogs
        // Handles both buttons and form submit buttons with data-confirm
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-confirm]');
            if (!el) return;

            // For form submit buttons, the form's submit event handles it
            if (el.tagName === 'FORM') return;

            if (!confirm(el.getAttribute('data-confirm'))) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);

        // =================================================================
        // Submit: Confirmation dialogs on forms
        // =================================================================
        document.body.addEventListener('submit', function(e) {
            var msg = e.target.getAttribute('data-confirm');
            if (msg && !confirm(msg)) {
                e.preventDefault();
            }
        });

        // =================================================================
        // Click: Toggle element visibility
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-toggle]');
            if (!el) return;

            var targetId = el.getAttribute('data-toggle');
            var target = document.getElementById(targetId);
            if (target) {
                target.style.display = (target.style.display === 'none' || target.style.display === '') ? 'block' : 'none';

                // Optional: update an arrow indicator element
                var arrowId = el.getAttribute('data-toggle-arrow');
                if (arrowId) {
                    var arrow = document.getElementById(arrowId);
                    if (arrow) {
                        arrow.innerHTML = (target.style.display === 'none') ? '&#9654;' : '&#9660;';
                    }
                }
            }
        });

        // =================================================================
        // Click: Close/hide element
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-close]');
            if (!el) return;

            var targetId = el.getAttribute('data-close');
            var target = document.getElementById(targetId);
            if (target) {
                target.style.display = 'none';
            }
        });

        // =================================================================
        // Click: Call global function by name
        // Supports data-arg for a single argument, data-args for JSON array
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-action]');
            if (!el) return;

            // Don't handle if this is a select/input (handled by change event)
            if (el.tagName === 'SELECT' || el.tagName === 'INPUT') return;

            var fnName = el.getAttribute('data-action');
            var fn = window[fnName];
            if (typeof fn !== 'function') return;

            var arg = el.getAttribute('data-arg');
            var argsJson = el.getAttribute('data-args');

            if (argsJson) {
                try {
                    var args = JSON.parse(argsJson);
                    fn.apply(null, args);
                } catch (ex) {
                    fn(el);
                }
            } else if (arg !== null) {
                fn(arg);
            } else {
                fn(el);
            }
        });

        // =================================================================
        // Change: Call global function by name (for select/input elements)
        // =================================================================
        document.body.addEventListener('change', function(e) {
            var el = e.target.closest('[data-action]');
            if (!el) return;

            var fnName = el.getAttribute('data-action');
            var fn = window[fnName];
            if (typeof fn === 'function') {
                fn(el.value);
            }
        });

        // =================================================================
        // Input: Call global function by name (for real-time input updates)
        // =================================================================
        document.body.addEventListener('input', function(e) {
            var el = e.target.closest('[data-action]');
            if (!el) return;

            // Only handle input elements (not selects, which use change)
            if (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA') return;

            var fnName = el.getAttribute('data-action');
            var fn = window[fnName];
            if (typeof fn === 'function') {
                fn(el.value);
            }
        });

        // =================================================================
        // Change: Submit parent form
        // =================================================================
        document.body.addEventListener('change', function(e) {
            if (e.target.hasAttribute('data-submit-form')) {
                e.target.form.submit();
            }
        });

        // =================================================================
        // Change: Display selected filename
        // =================================================================
        document.body.addEventListener('change', function(e) {
            var el = e.target.closest('[data-file-display]');
            if (!el || !el.files || !el.files[0]) return;

            var targetId = el.getAttribute('data-file-display');
            var target = document.getElementById(targetId);
            if (target) {
                var file = el.files[0];
                var sizeMB = (file.size / 1024 / 1024).toFixed(2);
                target.textContent = file.name + ' (' + sizeMB + ' MB)';
            }
        });

        // =================================================================
        // Click: Stop propagation
        // =================================================================
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('[data-stop-propagation]')) {
                e.stopPropagation();
            }
        }, true);

        // =================================================================
        // Click: Disable button after click (prevent double-submit)
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-disable-on-click]');
            if (!el) return;

            var label = el.getAttribute('data-disable-on-click');
            el.disabled = true;
            if (label) {
                el.textContent = label;
            }

            // If inside a form, submit it
            if (el.form) {
                el.form.submit();
            }
        });

        // =================================================================
        // Click: Navigate to URL
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-href]');
            if (!el) return;

            window.location.href = el.getAttribute('data-href');
        });

        // =================================================================
        // Click: Print page
        // =================================================================
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('[data-print]')) {
                window.print();
            }
        });

        // =================================================================
        // Click: Close window
        // =================================================================
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('[data-close-window]')) {
                window.close();
            }
        });

        // =================================================================
        // Click: Copy to clipboard
        // =================================================================
        document.body.addEventListener('click', function(e) {
            var el = e.target.closest('[data-clipboard]');
            if (!el) return;

            var text = el.getAttribute('data-clipboard');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    var original = el.textContent;
                    el.textContent = 'Copied!';
                    setTimeout(function() { el.textContent = original; }, 2000);
                });
            }
        });

        // =================================================================
        // Click: Close modals on outside click (window.onclick pattern)
        // Elements with data-modal-backdrop close when clicking the backdrop
        // =================================================================
        document.body.addEventListener('click', function(e) {
            if (e.target.hasAttribute('data-modal-backdrop')) {
                e.target.style.display = 'none';
            }
        });

        // =================================================================
        // Image error: Hide broken images
        // =================================================================
        document.body.addEventListener('error', function(e) {
            if (e.target.tagName === 'IMG' && e.target.hasAttribute('data-hide-on-error')) {
                e.target.style.display = 'none';
            }
        }, true);

        // =================================================================
        // Auto-fill: Copyright year
        // =================================================================
        var copyrightEl = document.querySelector('.copyright-year');
        if (copyrightEl) {
            copyrightEl.textContent = new Date().getFullYear();
        }

    });
})();
