/**
 * Assessment Template Builder JS
 *
 * AJAX-based section/question management for the admin template builder.
 * Uses up/down arrows for reordering (no external drag-and-drop library needed).
 */

// ============================================================
// HELPERS
// ============================================================

function builderFetch(url, data) {
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    for (const [key, val] of Object.entries(data)) {
        formData.append(key, val);
    }
    return fetch(url, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(result => {
            if (result.csrf_token) csrfToken = result.csrf_token;
            return result;
        });
}

function reloadPage() {
    window.location.reload();
}

// ============================================================
// ROLE VISIBILITY HELPERS (onboarding templates only)
// The .sec-visible-role / .q-visible-role checkboxes only exist when the
// template category is 'onboarding', so every helper is a no-op otherwise.
// ============================================================

function setRoleCheckboxes(selector, roles) {
    var arr = [];
    if (Array.isArray(roles)) {
        arr = roles;
    } else if (typeof roles === 'string' && roles) {
        try { var p = JSON.parse(roles); if (Array.isArray(p)) arr = p; } catch (e) {}
    }
    document.querySelectorAll(selector).forEach(function(cb) { cb.checked = arr.indexOf(cb.value) !== -1; });
}

function getRoleCheckboxes(selector) {
    var out = [];
    document.querySelectorAll(selector).forEach(function(cb) { if (cb.checked) out.push(cb.value); });
    return out;
}

// ============================================================
// SECTION OPERATIONS
// ============================================================

// Role controls (visible/editable) only apply to CUSTOM (added) sections/questions.
// Standard/default onboarding questions never show them (they are not gated).
function setRoleControlsVisible(containerId, show) {
    var el = document.getElementById(containerId);
    if (el) el.style.display = show ? 'block' : 'none';
}
function isCustomSectionId(id) {
    return typeof customSectionIds !== 'undefined' && customSectionIds.indexOf(parseInt(id, 10)) !== -1;
}
function isCustomQuestionId(id) {
    return typeof customQuestionIds !== 'undefined' && customQuestionIds.indexOf(parseInt(id, 10)) !== -1;
}

function addSection() {
    document.getElementById('sectionModalId').value = '';
    document.getElementById('sectionModalName').value = '';
    document.getElementById('sectionModalDesc').value = '';
    setRoleCheckboxes('.sec-visible-role', []);
    setRoleCheckboxes('.sec-editable-role', []);
    // A new section is an addition => custom => allow role controls.
    setRoleControlsVisible('sectionModalRoleControls', true);
    document.getElementById('sectionModalTitle').textContent = 'Add Section';
    document.getElementById('sectionModal').style.display = 'block';
}

function editSection(sectionId, name, description, visibleRoles, editableRoles) {
    document.getElementById('sectionModalId').value = sectionId;
    document.getElementById('sectionModalName').value = name;
    document.getElementById('sectionModalDesc').value = description;
    setRoleCheckboxes('.sec-visible-role', visibleRoles);
    setRoleCheckboxes('.sec-editable-role', editableRoles);
    setRoleControlsVisible('sectionModalRoleControls', isCustomSectionId(sectionId));
    document.getElementById('sectionModalTitle').textContent = 'Edit Section';
    document.getElementById('sectionModal').style.display = 'block';
}

function saveSection() {
    var sectionId = document.getElementById('sectionModalId').value;
    var name = document.getElementById('sectionModalName').value.trim();
    var desc = document.getElementById('sectionModalDesc').value.trim();

    if (!name) { alert('Section name is required.'); return; }

    var data = {
        template_id: templateId,
        name: name,
        description: desc,
        visible_roles: JSON.stringify(getRoleCheckboxes('.sec-visible-role')),
        editable_roles: JSON.stringify(getRoleCheckboxes('.sec-editable-role'))
    };
    if (sectionId) data.section_id = sectionId;

    builderFetch('api/template-section-save.php', data).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to save section.');
        }
    }).catch(function() {
        alert('Failed to save section.');
    });
}

function deleteSection(sectionId) {
    if (!confirm('Delete this section and all its questions? This cannot be undone.')) return;

    builderFetch('api/template-section-delete.php', {
        section_id: sectionId,
        template_id: templateId
    }).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to delete section.');
        }
    }).catch(function() {
        alert('Failed to delete section.');
    });
}

function reorderSection(sectionId, direction) {
    var sections = document.querySelectorAll('.builder-section');
    var ids = Array.from(sections).map(function(el) { return parseInt(el.dataset.sectionId); });
    var idx = ids.indexOf(sectionId);

    if (direction === 'up' && idx > 0) {
        ids.splice(idx, 1);
        ids.splice(idx - 1, 0, sectionId);
    } else if (direction === 'down' && idx < ids.length - 1) {
        ids.splice(idx, 1);
        ids.splice(idx + 1, 0, sectionId);
    } else {
        return;
    }

    builderFetch('api/template-section-reorder.php', {
        template_id: templateId,
        section_ids: JSON.stringify(ids)
    }).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to reorder sections.');
        }
    });
}

// ============================================================
// QUESTION OPERATIONS
// ============================================================

function addQuestion(sectionId) {
    document.getElementById('qModalId').value = '';
    document.getElementById('qModalSectionId').value = sectionId;
    document.getElementById('qModalText').value = '';
    document.getElementById('qModalType').value = 'text';
    document.getElementById('qModalRequired').value = '1';
    document.getElementById('qModalHelp').value = '';
    if (document.getElementById('qModalFieldName')) document.getElementById('qModalFieldName').value = '';
    document.getElementById('qModalOptions').value = '';
    document.getElementById('qModalDependsOn').value = '';
    document.getElementById('qModalDependsValue').innerHTML = '<option value="">--</option>';
    setRoleCheckboxes('.q-visible-role', []);
    setRoleCheckboxes('.q-editable-role', []);
    // A new question is an addition => custom => allow role controls.
    setRoleControlsVisible('qModalRoleControls', true);
    document.getElementById('questionModalTitle').textContent = 'Add Question';
    toggleOptionsEditor();
    document.getElementById('questionModal').style.display = 'block';
}

function editQuestion(questionId) {
    var q = questionDataCache[questionId];
    if (!q) { alert('Question data not found.'); return; }

    document.getElementById('qModalId').value = q.id;
    document.getElementById('qModalSectionId').value = q.section_id;
    document.getElementById('qModalText').value = q.question_text;
    document.getElementById('qModalType').value = q.question_type;
    document.getElementById('qModalRequired').value = q.is_required ? '1' : '0';
    document.getElementById('qModalHelp').value = q.help_text || '';
    if (document.getElementById('qModalFieldName')) document.getElementById('qModalFieldName').value = q.field_name || '';

    // Set options
    var opts = q.options;
    if (Array.isArray(opts)) {
        document.getElementById('qModalOptions').value = opts.join('\n');
    } else if (typeof opts === 'string' && opts) {
        try {
            var parsed = JSON.parse(opts);
            document.getElementById('qModalOptions').value = Array.isArray(parsed) ? parsed.join('\n') : opts;
        } catch(e) {
            document.getElementById('qModalOptions').value = opts;
        }
    } else {
        document.getElementById('qModalOptions').value = '';
    }

    // Set conditional logic
    document.getElementById('qModalDependsOn').value = q.depends_on_question_id || '';
    loadDependsOnValues();
    if (q.depends_on_value) {
        // Wait for options to be populated then set value
        setTimeout(function() {
            document.getElementById('qModalDependsValue').value = q.depends_on_value;
        }, 50);
    }

    setRoleCheckboxes('.q-visible-role', q.visible_roles);
    setRoleCheckboxes('.q-editable-role', q.editable_roles);
    setRoleControlsVisible('qModalRoleControls', isCustomQuestionId(questionId));

    document.getElementById('questionModalTitle').textContent = 'Edit Question';
    toggleOptionsEditor();
    document.getElementById('questionModal').style.display = 'block';
}

function toggleOptionsEditor() {
    var type = document.getElementById('qModalType').value;
    var show = ['select', 'radio', 'checkbox', 'button_group', 'button_group_multi'].indexOf(type) !== -1;
    document.getElementById('optionsEditorGroup').style.display = show ? 'block' : 'none';

    // Convenience mapping defaults: when the admin picks the VAT type, default the
    // field name to the dedicated vat_number column so the answer maps to the
    // vendor record automatically (only if a field name isn't already chosen).
    var fieldSel = document.getElementById('qModalFieldName');
    if (fieldSel && type === 'vat' && !fieldSel.value) {
        var hasVat = Array.prototype.some.call(fieldSel.options, function (o) { return o.value === 'vat_number'; });
        if (hasVat) fieldSel.value = 'vat_number';
    }
}

function loadDependsOnValues() {
    var sel = document.getElementById('qModalDependsOn');
    var valueSel = document.getElementById('qModalDependsValue');
    valueSel.innerHTML = '<option value="">--</option>';

    if (!sel.value) return;

    var opt = sel.options[sel.selectedIndex];
    var type = opt.dataset.type || '';
    var options = [];

    try {
        options = JSON.parse(opt.dataset.options || '[]');
    } catch(e) {}

    if (Array.isArray(options) && options.length > 0) {
        options.forEach(function(o) {
            var el = document.createElement('option');
            el.value = o;
            el.textContent = o;
            valueSel.appendChild(el);
        });
    } else {
        // For text/textarea, allow free-text entry
        var el = document.createElement('option');
        el.value = '';
        el.textContent = '(Enter value below)';
        valueSel.appendChild(el);
        // Convert to text input
        var textInput = document.createElement('input');
        textInput.type = 'text';
        textInput.id = 'qModalDependsValue';
        textInput.className = 'form-control';
        textInput.style.marginTop = '5px';
        textInput.placeholder = 'Enter the required value...';
        valueSel.parentNode.appendChild(textInput);
        valueSel.style.display = 'none';
    }
}

function saveQuestion() {
    var questionId = document.getElementById('qModalId').value;
    var sectionId = document.getElementById('qModalSectionId').value;
    var text = document.getElementById('qModalText').value.trim();
    var type = document.getElementById('qModalType').value;
    var required = document.getElementById('qModalRequired').value;
    var helpText = document.getElementById('qModalHelp').value.trim();
    var optionsRaw = document.getElementById('qModalOptions').value.trim();
    var dependsOn = document.getElementById('qModalDependsOn').value;

    // Get depends value from either select or text input
    var dependsValueEl = document.getElementById('qModalDependsValue');
    var dependsValue = dependsValueEl ? dependsValueEl.value : '';

    if (!text) { alert('Question text is required.'); return; }

    // Parse options
    var options = '';
    if (['select', 'radio', 'checkbox', 'button_group', 'button_group_multi'].indexOf(type) !== -1 && optionsRaw) {
        var lines = optionsRaw.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l; });
        if (lines.length === 0) {
            alert('Please add at least one option for this question type.');
            return;
        }
        options = JSON.stringify(lines);
    }

    var fieldNameEl = document.getElementById('qModalFieldName');
    var fieldName = fieldNameEl ? fieldNameEl.value.trim() : '';

    var data = {
        section_id: sectionId,
        question_text: text,
        question_type: type,
        is_required: required,
        help_text: helpText,
        options: options,
        field_name: fieldName,
        depends_on_question_id: dependsOn || '',
        depends_on_value: dependsValue || '',
        visible_roles: JSON.stringify(getRoleCheckboxes('.q-visible-role')),
        editable_roles: JSON.stringify(getRoleCheckboxes('.q-editable-role'))
    };
    if (questionId) data.question_id = questionId;

    builderFetch('api/template-question-save.php', data).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to save question.');
        }
    }).catch(function() {
        alert('Failed to save question.');
    });
}

function deleteQuestion(questionId, sectionId) {
    if (!confirm('Delete this field?\n\nWARNING: any data already stored for this field (across all vendors) will be permanently removed along with it. This cannot be undone.')) return;

    builderFetch('api/template-question-delete.php', {
        question_id: questionId,
        section_id: sectionId
    }).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to delete question.');
        }
    }).catch(function() {
        alert('Failed to delete question.');
    });
}

function reorderQuestion(questionId, sectionId, direction) {
    var container = document.querySelector('.questions-list[data-section-id="' + sectionId + '"]');
    if (!container) return;

    var rows = container.querySelectorAll('.question-row');
    var ids = Array.from(rows).map(function(el) { return parseInt(el.dataset.questionId); });
    var idx = ids.indexOf(questionId);

    if (direction === 'up' && idx > 0) {
        ids.splice(idx, 1);
        ids.splice(idx - 1, 0, questionId);
    } else if (direction === 'down' && idx < ids.length - 1) {
        ids.splice(idx, 1);
        ids.splice(idx + 1, 0, questionId);
    } else {
        return;
    }

    builderFetch('api/template-question-reorder.php', {
        section_id: sectionId,
        question_ids: JSON.stringify(ids)
    }).then(function(result) {
        if (result.success) {
            reloadPage();
        } else {
            alert(result.message || 'Failed to reorder questions.');
        }
    });
}

