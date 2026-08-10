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

    // Allowlist of global function names that may be invoked via data-action.
    // Without this gate, an injected element with data-action="eval" or any
    // other window-resident function name would execute attacker-controlled
    // JavaScript. The list is the union of every camelCase data-action value
    // currently used in the codebase. Kebab-case data-action values (e.g.
    // "save-pdf", "open-task") are handled by per-page listeners and never
    // reach this dispatcher, so they don't need to be listed here. New
    // data-action functions must be added here when introduced.
    var SAFE_ACTIONS = {
        addQuestion: 1, addSection: 1, aiAutofillAssessment: 1, changePerPage: 1,
        clearReassignUser: 1, clearScoreHistory: 1, clearUserSearch: 1,
        closeAssignGroupsModal: 1, closeCreateUserModal: 1, closeCronInfoModal: 1,
        closeDeleteUserModal: 1, closeEditModal: 1, closeReassignModal: 1,
        closeRestoreModal: 1, closeRetierModal: 1, closeTierModal: 1,
        closeUploadRestoreModal: 1, confirmApplyUpdate: 1, confirmDeleteUser: 1,
        confirmImpersonate: 1, confirmRestore: 1, confirmUploadRestore: 1,
        copyLink: 1, deleteDocument: 1, deleteQuestion: 1, deleteSection: 1,
        deleteVendor: 1, editDocumentMeta: 1, editQuestion: 1, editRule: 1,
        editSection: 1, editSubprocessor: 1, emailLink: 1, evalSelected: 1,
        exportOldFindings: 1, exportRawData: 1, exportRawFindings: 1,
        exportScoreHistory: 1, exportShodanSignals: 1, exportVendorDomainsCSV: 1,
        exportVendorTechCSV: 1, fillProviderSettings: 1, filterByGroup: 1,
        filterByStatus: 1, filterByType: 1, generateExecSummary: 1, goToPage: 1,
        identifiedRisksPage: 1, loadDependsOnValues: 1, oldFindingsPage: 1,
        onQuestionChange: 1, onSourceTemplateChange: 1, openAssignGroupsModal: 1,
        openCaseReassignModal: 1, openContactModal: 1, openCreateRule: 1,
        openCreateUserModal: 1, openCronInfoModal: 1, openEditModal: 1,
        openReassignModal: 1, openRetierModal: 1, openStakeholderModal: 1,
        openStatusModal: 1, openTierModal: 1, openVendorNameModal: 1,
        openVendorTypeModal: 1, previewSqlUpdate: 1, rawFindingsPage: 1,
        removeSubprocessor: 1, reorderQuestion: 1, reorderSection: 1,
        saveGroupAssignments: 1, saveQuestion: 1, saveSection: 1,
        scoreSelected: 1, scrollToCategory: 1, scrollToSection: 1,
        searchTechConcentration: 1, showEmailSection: 1, showShodanSection: 1,
        showSrsSection: 1, showVendorTab: 1, toggleBlastVendor: 1,
        toggleCatSubdomains: 1, toggleCompletedAction: 1, toggleDocumentStatus: 1,
        toggleEmailMethod: 1, toggleEmailTemplate: 1, toggleHistoryTab: 1,
        toggleIdentifiedRisks: 1, toggleOldFindings: 1, toggleOnbScoreMenu: 1,
        toggleOptionsEditor: 1, toggleProcurementUserSelect: 1, toggleProof: 1,
        toggleRawFindings: 1, toggleReplyForm: 1, toggleScoreHistory: 1,
        toggleScoringMethod: 1, toggleShodanScoringMethod: 1,
        toggleSignalCategory: 1, toggleTodo: 1, toggleVendorDomains: 1,
        toggleVendorScoreMenu: 1, toggleVendorTech: 1, updateWeightTotal: 1,
        vdSort: 1, vendorDomainsPage: 1, vendorTechPage: 1, vtSort: 1
    };

    /**
     * Resolve a data-action attribute value to a callable function reference,
     * or null if the name is not allowlisted. This is the security boundary
     * that prevents arbitrary global functions (eval, Function, setTimeout
     * with a string, etc.) from being invoked through DOM injection.
     */
    function resolveAction(fnName) {
        if (!fnName || !Object.prototype.hasOwnProperty.call(SAFE_ACTIONS, fnName)) {
            return null;
        }
        var fn = window[fnName];
        return (typeof fn === 'function') ? fn : null;
    }

    // Reject javascript:, data:, vbscript:, etc. before assigning to location.href.
    // Allows relative URLs and http(s)/mailto/tel — the only schemes the app emits.
    function isSafeNavigationUrl(url) {
        if (typeof url !== 'string') return false;
        var trimmed = url.trim();
        if (trimmed === '' || /[\x00-\x1F\x7F]/.test(trimmed)) return false;
        var m = trimmed.match(/^([a-z][a-z0-9+\-.]*):/i);
        if (!m) return true;
        var s = m[1].toLowerCase();
        return s === 'http' || s === 'https' || s === 'mailto' || s === 'tel';
    }

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
                        arrow.textContent = (target.style.display === 'none') ? '▶' : '▼';
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

            var fn = resolveAction(el.getAttribute('data-action'));
            if (!fn) return;

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

            var fn = resolveAction(el.getAttribute('data-action'));
            if (fn) fn(el.value);
        });

        // =================================================================
        // Input: Call global function by name (for real-time input updates)
        // =================================================================
        document.body.addEventListener('input', function(e) {
            var el = e.target.closest('[data-action]');
            if (!el) return;

            // Only handle input elements (not selects, which use change)
            if (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA') return;

            var fn = resolveAction(el.getAttribute('data-action'));
            if (fn) fn(el.value);
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

            var href = el.getAttribute('data-href');
            if (isSafeNavigationUrl(href)) {
                window.location.href = href;
            }
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
