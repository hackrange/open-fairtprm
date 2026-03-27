<?php
/**
 * Vendor Tasks View Component - The Pretty Card Grid
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Renders the vendor assessment tasks as a filterable, searchable card-based UI.
 * Each card shows the vendor name, assessment type, status badge, contact info,
 * completion progress bar, and action buttons. Includes client-side filtering
 * by status and search term, clickable stat badges for quick filtering, and a
 * "copy link" button that puts the assessment URL on the clipboard. The CSS is
 * all inline in this partial because it's self-contained and only loaded on the
 * vendor tasks tab.
 *
 * Expects $assessments, $taskCounts, and $theme to be available from vendor-tasks-data.php.
 */

// This file is included after vendor-tasks-data.php
// Assumes $assessments, $taskCounts, $theme are available
?>

<!-- ======================================================================
     Styles for the task cards, filters, and stat badges.
     Everything is scoped under .vendor-tasks-container so it won't
     leak into the rest of the page. Responsive breakpoints at 768px.
     ====================================================================== -->
<style>
    .vendor-tasks-container {
        padding: 20px 0;
    }

    /* Header area with the clickable stat badges */
    .tasks-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .tasks-stats {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    /* Each stat badge -- clickable with a subtle hover effect */
    .stat-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
        cursor: pointer;
        padding: 10px 15px;
        border-radius: 8px;
        transition: all 0.2s;
        border: 2px solid transparent;
    }

    .stat-item:hover {
        background: rgba(0, 0, 0, 0.05);
        transform: translateY(-2px);  /* Little bounce on hover -- small detail, big UX */
    }

    .stat-item.active {
        background: rgba(255, 101, 67, 0.1);
        border-color: var(--theme-header-color);
    }

    .stat-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
        font-weight: 600;
    }

    .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: var(--theme-header-color);
    }

    .stat-item:hover .stat-label {
        color: #333;
    }

    /* Filter bar -- search input and status dropdown */
    .tasks-filters {
        background: #f8f9fa;
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
    }

    .tasks-filters input,
    .tasks-filters select {
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }

    .tasks-filters input {
        flex: 1;
        min-width: 250px;
    }

    /* The card grid -- auto-fill responsive grid, minimum 400px per card */
    .tasks-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    /* Individual task card styling */
    .task-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s;
        border-left: 4px solid var(--theme-header-color);  /* Colored left border for visual flair */
    }

    .task-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }

    .task-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .task-vendor-name {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }

    .task-assessment-type {
        font-size: 13px;
        color: #666;
        margin-bottom: 10px;
    }

    /* Status badges -- color-coded pill shapes for quick visual scanning */
    .task-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .task-status-pending {
        background: #ffc107;  /* Amber -- waiting for action */
        color: #000;
    }

    .task-status-in_progress {
        background: #17a2b8;  /* Teal -- work is happening */
        color: #fff;
    }

    .task-status-completed {
        background: #28a745;  /* Green -- all done */
        color: #fff;
    }

    .task-status-expired {
        background: #dc3545;  /* Red -- too late, buddy */
        color: #fff;
    }

    /* Contact info section inside each card */
    .task-contact {
        margin-bottom: 15px;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 4px;
    }

    .task-contact-label {
        font-size: 11px;
        color: #666;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 3px;
    }

    .task-contact-name {
        font-size: 14px;
        font-weight: 500;
        color: #333;
    }

    .task-contact-email {
        font-size: 13px;
        color: #666;
    }

    /* Progress bar -- shows completion percentage with a smooth fill animation */
    .task-progress {
        margin-bottom: 15px;
    }

    .task-progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
        font-size: 12px;
        color: #666;
    }

    .task-progress-bar {
        width: 100%;
        height: 8px;
        background: #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
    }

    .task-progress-fill {
        height: 100%;
        background: var(--theme-button-color);
        border-radius: 4px;
        transition: width 0.3s;
    }

    /* Metadata section -- created date, expiry, creator */
    .task-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 15px;
        padding-top: 10px;
        border-top: 1px solid #eee;
    }

    .task-meta-item {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .task-meta-label {
        font-size: 11px;
        color: #999;
        text-transform: uppercase;
    }

    .task-meta-value {
        font-size: 13px;
        color: #333;
    }

    /* Action buttons at the bottom of each card */
    .task-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .task-btn {
        flex: 1;
        min-width: 120px;
        padding: 10px 15px;
        border: none;
        border-radius: 4px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        text-align: center;
    }

    .task-btn-primary {
        background: var(--theme-button-color);
        color: white;
    }

    .task-btn-primary:hover {
        filter: brightness(1.1);
        transform: translateY(-1px);
    }

    .task-btn-secondary {
        background: white;
        color: var(--theme-header-color);
        border: 2px solid var(--theme-header-color);
    }

    .task-btn-secondary:hover {
        background: var(--theme-header-color);
        color: white;
    }

    .task-btn-copy {
        position: relative;
    }

    /* Green flash effect when link is copied to clipboard */
    .task-btn-copy.copied {
        background: #28a745;
        color: white;
        border-color: #28a745;
    }

    /* Empty state -- shown when there are no assessments */
    .tasks-empty {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
    }

    .tasks-empty h3 {
        color: #666;
        margin-bottom: 10px;
        font-size: 18px;
        font-weight: 600;
    }

    .tasks-empty p {
        color: #999;
        margin-bottom: 0;
    }

    /* Mobile responsive -- stack everything vertically on small screens */
    @media (max-width: 768px) {
        .tasks-grid {
            grid-template-columns: 1fr;
        }

        .tasks-filters {
            flex-direction: column;
        }

        .tasks-filters input {
            width: 100%;
        }

        .task-btn {
            width: 100%;
        }

        .tasks-stats {
            width: 100%;
        }
    }
</style>

<div class="vendor-tasks-container">
    <!-- ================================================================
         Stats Header - Clickable stat badges that double as filter buttons.
         Click "Pending" to filter the grid to only pending tasks. Neat.
         ================================================================ -->
    <div class="tasks-header">
        <div class="tasks-stats">
            <div class="stat-item active" data-filter="" title="Click to show all tasks">
                <div class="stat-label">Total Tasks</div>
                <div class="stat-value"><?php echo (int)$taskCounts['total']; ?></div>
            </div>
            <div class="stat-item" data-filter="pending" title="Click to show pending tasks only">
                <div class="stat-label">Pending</div>
                <div class="stat-value" style="color: #ffc107;"><?php echo (int)$taskCounts['pending']; ?></div>
            </div>
            <div class="stat-item" data-filter="in_progress" title="Click to show in progress tasks only">
                <div class="stat-label">In Progress</div>
                <div class="stat-value" style="color: #17a2b8;"><?php echo (int)$taskCounts['in_progress']; ?></div>
            </div>
            <div class="stat-item" data-filter="completed" title="Click to show completed tasks only">
                <div class="stat-label">Completed</div>
                <div class="stat-value" style="color: #28a745;"><?php echo (int)$taskCounts['completed']; ?></div>
            </div>
        </div>
    </div>

    <!-- ================================================================
         Filter Bar -- Text search and status dropdown.
         Both trigger filterTasks() on input change for instant filtering.
         ================================================================ -->
    <div class="tasks-filters">
        <input
            type="text"
            id="taskSearchInput"
            placeholder="Search by vendor name or contact..."
        >
        <select id="taskStatusFilter">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
            <option value="expired">Expired</option>
        </select>
    </div>

    <!-- ================================================================
         The Task Card Grid -- where the magic happens.
         Each card has data attributes for client-side filtering.
         ================================================================ -->
    <?php if (empty($assessments)): ?>
        <div class="tasks-empty">
            <h3>No Assessment Tasks</h3>
            <p>There are no vendor assessments assigned to you at this time.</p>
        </div>
    <?php else: ?>
        <div class="tasks-grid" id="tasksGrid">
            <?php foreach ($assessments as $task): ?>
                <!-- Each card carries data-* attributes for JS filtering -->
                <div class="task-card"
                     data-vendor="<?php echo e(strtolower($task['vendor_name'])); ?>"
                     data-contact="<?php echo e(strtolower($task['contact_name'] ?? '')); ?>"
                     data-status="<?php echo e($task['status']); ?>">

                    <!-- Card Header: vendor name + status badge -->
                    <div class="task-card-header">
                        <div style="flex: 1;">
                            <div class="task-vendor-name"><?php echo e($task['vendor_name']); ?></div>
                            <div class="task-assessment-type"><?php echo e($task['assessment_type'] ?: 'Assessment'); ?></div>
                        </div>
                        <span class="task-status-badge task-status-<?php echo e($task['status']); ?>">
                            <?php echo e(ucfirst(str_replace('_', ' ', $task['status']))); ?>
                        </span>
                    </div>

                    <!-- Contact Info: who at the vendor should we bug about this -->
                    <?php if ($task['contact_name'] || $task['contact_email']): ?>
                        <div class="task-contact">
                            <div class="task-contact-label">Vendor Contact</div>
                            <?php if ($task['contact_name']): ?>
                                <div class="task-contact-name"><?php echo e($task['contact_name']); ?></div>
                            <?php endif; ?>
                            <?php if ($task['contact_email']): ?>
                                <div class="task-contact-email"><?php echo e($task['contact_email']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Progress Bar: visual indicator of how far along the assessment is -->
                    <div class="task-progress">
                        <div class="task-progress-label">
                            <span>Completion</span>
                            <span><strong><?php echo (int)$task['completion_percentage']; ?>%</strong></span>
                        </div>
                        <div class="task-progress-bar">
                            <div class="task-progress-fill" style="width: <?php echo (int)$task['completion_percentage']; ?>%;"></div>
                        </div>
                    </div>

                    <!-- Metadata: dates and creator info -->
                    <div class="task-meta">
                        <div class="task-meta-item">
                            <div class="task-meta-label">Created</div>
                            <div class="task-meta-value">
                                <?php echo date('M j, Y', strtotime($task['created_at'])); ?>
                            </div>
                        </div>
                        <?php if ($task['expires_at']): ?>
                            <div class="task-meta-item">
                                <div class="task-meta-label">Expires</div>
                                <div class="task-meta-value">
                                    <?php echo date('M j, Y', strtotime($task['expires_at'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($task['created_by_name']): ?>
                            <div class="task-meta-item">
                                <div class="task-meta-label">Created By</div>
                                <div class="task-meta-value"><?php echo e($task['created_by_name']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons: copy link + view vendor -->
                    <div class="task-actions">
                        <?php if ($task['status'] !== 'completed'): ?>
                        <button
                            class="task-btn task-btn-secondary task-btn-copy"
                            data-copy-uuid="<?php echo e($task['uuid']); ?>"
                            title="Copy assessment link to clipboard"
                        >
                            Copy Link
                        </button>
                        <?php endif; ?>
                        <a href="vendor-onboarding.php?id=<?php echo (int)$task['vendor_request_id']; ?>"
                           class="task-btn task-btn-primary"
                           title="View vendor details">
                            View Vendor
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================
     JavaScript for client-side filtering, clipboard copy, and UI interactions.
     All functions are global since this is an included partial,
     not a module. Old school but it works.
     ====================================================================== -->
<script nonce="<?php echo cspNonce(); ?>">
/**
 * Handles clicks on the stat badges -- updates the dropdown to match,
 * highlights the clicked badge, and re-runs the filter.
 * This is the "click a number to filter" interaction pattern.
 */
function filterByStatus(status, clickedElement) {
    // Sync the dropdown with the clicked stat badge
    const statusDropdown = document.getElementById('taskStatusFilter');
    if (statusDropdown) {
        statusDropdown.value = status;
    }

    // Swap the active highlight to the clicked badge
    const statItems = document.querySelectorAll('.stat-item');
    statItems.forEach(function(item) {
        item.classList.remove('active');
    });

    if (clickedElement) {
        clickedElement.classList.add('active');
    }

    // Actually apply the filter
    filterTasks();
}

/**
 * Copies the assessment URL to the clipboard.
 * Tries the modern Clipboard API first (navigator.clipboard), falls back
 * to the old-school "create invisible textarea, select, execCommand" hack
 * for browsers that don't support the modern API. Both work, one is just
 * way less hacky than the other.
 */
function copyAssessmentLink(uuid, buttonElement) {
    const baseUrl = window.location.origin;
    const assessmentUrl = baseUrl + '/vendor-assessment.php?token=' + uuid;

    // Try modern clipboard API first
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(assessmentUrl).then(function() {
            showCopySuccess(buttonElement);
        }).catch(function(err) {
            // Modern API failed -- fall back to the textarea trick
            fallbackCopyToClipboard(assessmentUrl, buttonElement);
        });
    } else {
        // Browser too old for Clipboard API -- use the workaround
        fallbackCopyToClipboard(assessmentUrl, buttonElement);
    }
}

/**
 * The "invisible textarea" clipboard hack for older browsers.
 * Creates a hidden textarea, shoves the text in it, selects it,
 * runs document.execCommand('copy'), and cleans up after itself.
 * It's ugly but it's been working since IE9. A true survivor.
 */
function fallbackCopyToClipboard(text, buttonElement) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    // Position it off-screen so it's invisible but still selectable
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        document.execCommand('copy');
        showCopySuccess(buttonElement);
    } catch (err) {
        console.error('Failed to copy:', err);
        // Last resort -- just show the URL and let them copy manually
        alert('Failed to copy link. Please copy manually: ' + text);
    }

    document.body.removeChild(textArea);
}

/**
 * Visual feedback when copy succeeds -- swaps the button text to "Copied!"
 * with a green background for 2 seconds, then reverts. Satisfying little
 * micro-interaction that confirms the action worked.
 */
function showCopySuccess(buttonElement) {
    const originalText = buttonElement.textContent;
    buttonElement.textContent = '\u2713 Copied!';
    buttonElement.classList.add('copied');

    // Revert after 2 seconds -- enough time to see it, not long enough to be annoying
    setTimeout(function() {
        buttonElement.textContent = originalText;
        buttonElement.classList.remove('copied');
    }, 2000);
}

/**
 * The main filter function -- checks each card against the search term
 * and status dropdown, then shows/hides cards accordingly.
 * Runs on every keystroke and dropdown change. For a few hundred cards,
 * DOM manipulation like this is perfectly fast. If you've got 10,000 cards...
 * maybe consider server-side filtering instead.
 */
function filterTasks() {
    const searchTerm = document.getElementById('taskSearchInput').value.toLowerCase();
    const statusFilter = document.getElementById('taskStatusFilter').value;
    const cards = document.querySelectorAll('.task-card');

    let visibleCount = 0;

    cards.forEach(function(card) {
        const vendorName = card.getAttribute('data-vendor');
        const contactName = card.getAttribute('data-contact');
        const status = card.getAttribute('data-status');

        // Search matches if the term appears in vendor name or contact name
        const searchMatch = !searchTerm ||
            vendorName.includes(searchTerm) ||
            contactName.includes(searchTerm);

        // Status matches if no filter is set or it matches the card's status
        const statusMatch = !statusFilter || status === statusFilter;

        // Show the card only if both conditions pass
        if (searchMatch && statusMatch) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Keep the stat badges in sync with the current filter state
    syncStatItemsWithFilter(statusFilter);

    // If filtering resulted in zero visible cards, show an empty state message
    // with a "clear filters" link so users don't think the data is missing
    const tasksGrid = document.getElementById('tasksGrid');
    const emptyState = document.getElementById('filteredEmptyState');
    if (tasksGrid && visibleCount === 0 && cards.length > 0) {
        if (!emptyState) {
            const emptyDiv = document.createElement('div');
            emptyDiv.id = 'filteredEmptyState';
            emptyDiv.className = 'tasks-empty';
            emptyDiv.innerHTML = '<h3>No Matching Tasks</h3><p>No tasks match your current filters. <a href="#" class="clear-filters-link">Clear filters</a></p>';
            emptyDiv.querySelector('.clear-filters-link').addEventListener('click', function(e) { e.preventDefault(); clearFilters(); });
            tasksGrid.parentNode.insertBefore(emptyDiv, tasksGrid.nextSibling);
        }
        emptyState && (emptyState.style.display = 'block');
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
}

/**
 * Keeps the stat badge "active" state in sync with the current filter.
 * When the dropdown changes, the matching badge gets highlighted.
 */
function syncStatItemsWithFilter(currentFilter) {
    const statItems = document.querySelectorAll('.stat-item');
    statItems.forEach(function(item) {
        const itemFilter = item.getAttribute('data-filter');
        if (itemFilter === currentFilter) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

/**
 * Nuclear option -- clears all filters and shows everything again.
 * Resets the search input, dropdown, and stat badge highlights.
 */
function clearFilters() {
    document.getElementById('taskSearchInput').value = '';
    document.getElementById('taskStatusFilter').value = '';
    filterByStatus('', document.querySelector('.stat-item[data-filter=""]'));
}

// Bind event listeners for stat items, search, filter, and copy buttons
document.querySelectorAll('.stat-item[data-filter]').forEach(function(item) {
    item.addEventListener('click', function() { filterByStatus(this.getAttribute('data-filter'), this); });
});
document.getElementById('taskSearchInput').addEventListener('keyup', filterTasks);
document.getElementById('taskStatusFilter').addEventListener('change', filterTasks);
document.querySelectorAll('[data-copy-uuid]').forEach(function(btn) {
    btn.addEventListener('click', function() { copyAssessmentLink(this.getAttribute('data-copy-uuid'), this); });
});
</script>
