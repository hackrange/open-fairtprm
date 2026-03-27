/**
 * Mobile Navigation and UI Handler for Open TPRM
 *
 * Provides hamburger menu functionality and mobile-optimized pagination.
 * Include this script at the bottom of the page body.
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        initMobileNav();
        initMobilePagination();
    });

    function initMobileNav() {
        // Find or create mobile menu toggle button
        let menuToggle = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        const topBar = document.querySelector('.top-bar');

        // If no sidebar exists, no need for mobile nav
        if (!sidebar) {
            return;
        }

        // Create mobile menu toggle if it doesn't exist
        if (!menuToggle) {
            menuToggle = document.createElement('button');
            menuToggle.className = 'mobile-menu-toggle';
            menuToggle.setAttribute('aria-label', 'Toggle navigation menu');
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.innerHTML = '<span></span><span></span><span></span>';

            // Insert at the start of body or before top-bar
            if (topBar) {
                topBar.parentNode.insertBefore(menuToggle, topBar);
            } else {
                document.body.insertBefore(menuToggle, document.body.firstChild);
            }
        }

        // Create overlay if it doesn't exist
        let overlay = document.querySelector('.mobile-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'mobile-overlay';
            document.body.appendChild(overlay);
        }

        // Toggle menu on button click
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu();
        });

        // Close menu when clicking overlay (not sidebar)
        overlay.addEventListener('click', function(e) {
            // Only close if clicking the overlay itself, not the sidebar
            var sidebarRect = sidebar.getBoundingClientRect();
            if (e.clientX > sidebarRect.right) {
                closeMenu();
            }
        });

        // Handle sidebar link clicks
        sidebar.addEventListener('click', function(e) {
            e.stopPropagation();
            e.stopImmediatePropagation();

            // Find if click was on or inside a link
            var link = e.target.closest('a');
            if (link) {
                var href = link.getAttribute('href');
                if (href && href !== '#' && href !== '') {
                    // Navigate to the link
                    closeMenu();
                    window.location.href = href;
                    return;
                }
            }
        }, true);

        // Close menu on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                closeMenu();
            }
        });

        // Handle window resize - close menu when switching to desktop
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth > 992 && sidebar.classList.contains('mobile-open')) {
                    closeMenu();
                }
            }, 250);
        });

        // Prevent body scroll when menu is open
        function toggleMenu() {
            const isOpen = sidebar.classList.toggle('mobile-open');
            menuToggle.classList.toggle('active', isOpen);
            overlay.classList.toggle('active', isOpen);
            menuToggle.setAttribute('aria-expanded', isOpen);

            if (isOpen) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        function closeMenu() {
            sidebar.classList.remove('mobile-open');
            menuToggle.classList.remove('active');
            overlay.classList.remove('active');
            menuToggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        // Swipe to close disabled - was interfering with link clicks
    }

    /**
     * Mobile Pagination Handler
     * Limits visible page numbers on mobile devices:
     * - Phones (< 576px): Show 4 page numbers max
     * - Tablets (< 768px): Show 6 page numbers max
     */
    function initMobilePagination() {
        function updatePagination() {
            const screenWidth = window.innerWidth;
            const isMobile = screenWidth < 576;
            const isTablet = screenWidth >= 576 && screenWidth < 768;
            const maxVisible = isMobile ? 3 : (isTablet ? 5 : 999);

            document.querySelectorAll('.pagination').forEach(function(pagination) {
                const items = Array.from(pagination.querySelectorAll('li'));
                if (items.length === 0) return;

                // Reset all items first
                items.forEach(function(item) {
                    item.classList.remove('mobile-hidden');
                });

                // On desktop, don't modify
                if (!isMobile && !isTablet) return;

                // Collect page number items (exclude nav buttons and ellipsis)
                const pageItems = [];
                let activePageIdx = -1;

                items.forEach(function(item, index) {
                    const link = item.querySelector('a');
                    const span = item.querySelector('span');
                    const text = (link ? link.textContent : (span ? span.textContent : '')).trim();

                    // Skip navigation symbols
                    if (text === '«' || text === '»' || text === '‹' || text === '›' ||
                        text === '&laquo;' || text === '&raquo;' || text === '&lsaquo;' || text === '&rsaquo;') {
                        return;
                    }

                    // Skip and hide ellipsis on mobile
                    if (text === '...' || text === '…') {
                        if (isMobile) {
                            item.classList.add('mobile-hidden');
                        }
                        return;
                    }

                    // This is a page number
                    const isActive = item.classList.contains('active');
                    if (isActive) {
                        activePageIdx = pageItems.length;
                    }
                    pageItems.push(item);
                });

                // If we have more pages than allowed, hide extras
                if (pageItems.length > maxVisible) {
                    if (activePageIdx === -1) activePageIdx = 0;

                    // Calculate visible range centered on active page
                    let startIdx = Math.max(0, activePageIdx - Math.floor(maxVisible / 2));
                    let endIdx = startIdx + maxVisible;

                    if (endIdx > pageItems.length) {
                        endIdx = pageItems.length;
                        startIdx = Math.max(0, endIdx - maxVisible);
                    }

                    // Hide pages outside the visible range
                    pageItems.forEach(function(item, idx) {
                        if (idx < startIdx || idx >= endIdx) {
                            item.classList.add('mobile-hidden');
                        }
                    });
                }
            });
        }

        // Run on load
        updatePagination();

        // Run on resize (debounced)
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(updatePagination, 150);
        });

        // Also observe DOM changes for dynamically generated pagination
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function(mutations) {
                let shouldUpdate = false;
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1 && (
                                node.classList?.contains('pagination') ||
                                node.querySelector?.('.pagination') ||
                                node.id?.includes('Pagination')
                            )) {
                                shouldUpdate = true;
                            }
                        });
                    }
                });
                if (shouldUpdate) {
                    setTimeout(updatePagination, 50);
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        // Handle inline JS pagination (like in vendor-srs-details.php)
        function updateInlinePagination() {
            const isMobile = window.innerWidth < 576;
            if (!isMobile) return;

            // Find pagination containers by ID pattern
            document.querySelectorAll('[id$="Pagination"]').forEach(function(container) {
                // Check if already processed
                if (container.dataset.mobileProcessed === 'true') return;

                const buttons = container.querySelectorAll('button');
                const spans = container.querySelectorAll('span');

                if (buttons.length === 0) return;

                // Find "Showing X-Y of Z" span and page buttons
                let showingSpan = null;
                let buttonWrapper = null;

                spans.forEach(function(span) {
                    if (span.textContent.includes('Showing')) {
                        showingSpan = span;
                    }
                    if (span.style.display === 'flex' || span.querySelectorAll('button').length > 0) {
                        buttonWrapper = span;
                    }
                });

                // Restructure for mobile: buttons on top, showing text below
                if (showingSpan && buttonWrapper) {
                    container.style.flexDirection = 'column';
                    container.style.alignItems = 'center';
                    container.style.gap = '8px';

                    // Move buttons before showing text
                    container.insertBefore(buttonWrapper, showingSpan);

                    showingSpan.style.order = '2';
                    showingSpan.style.fontSize = '11px';
                    buttonWrapper.style.order = '1';
                    buttonWrapper.style.flexWrap = 'wrap';
                    buttonWrapper.style.justifyContent = 'center';
                }

                // Limit visible page number buttons to 3 on mobile phones
                const maxPageButtons = 3;
                const pageButtons = [];
                let activeIdx = -1;

                buttons.forEach(function(btn, idx) {
                    const text = btn.textContent.trim();
                    // Skip Prev/Next buttons - just make them smaller
                    if (text.includes('Prev') || text.includes('Next') ||
                        text === '«' || text === '»' || text === '‹' || text === '›' ||
                        text.includes('laquo') || text.includes('raquo')) {
                        btn.style.padding = '6px 8px';
                        btn.style.fontSize = '11px';
                        return;
                    }
                    // This is a page number button
                    pageButtons.push(btn);
                    // Check if this is the active/current page button
                    const bgColor = btn.style.background || btn.style.backgroundColor || '';
                    if (bgColor && bgColor !== 'white' && bgColor !== 'rgb(255, 255, 255)') {
                        activeIdx = pageButtons.length - 1;
                    }
                    btn.style.padding = '6px 8px';
                    btn.style.fontSize = '11px';
                    btn.style.minWidth = '32px';
                });

                // Hide extra page buttons beyond maxPageButtons (3)
                if (pageButtons.length > maxPageButtons) {
                    if (activeIdx === -1) activeIdx = 0;

                    // Center around active page
                    let startIdx = Math.max(0, activeIdx - Math.floor(maxPageButtons / 2));
                    let endIdx = startIdx + maxPageButtons;

                    if (endIdx > pageButtons.length) {
                        endIdx = pageButtons.length;
                        startIdx = Math.max(0, endIdx - maxPageButtons);
                    }

                    pageButtons.forEach(function(btn, idx) {
                        if (idx < startIdx || idx >= endIdx) {
                            btn.style.display = 'none';
                        } else {
                            btn.style.display = '';
                        }
                    });
                }

                container.dataset.mobileProcessed = 'true';
            });
        }

        // Run inline pagination update
        updateInlinePagination();

        // Also run when dynamic content loads
        if (typeof MutationObserver !== 'undefined') {
            const inlineObserver = new MutationObserver(function() {
                setTimeout(updateInlinePagination, 100);
            });
            document.querySelectorAll('[id$="Pagination"]').forEach(function(el) {
                inlineObserver.observe(el, { childList: true });
            });
        }

        // Re-run on resize
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                // Reset processed flag on resize
                document.querySelectorAll('[id$="Pagination"]').forEach(function(el) {
                    el.dataset.mobileProcessed = 'false';
                });
                updateInlinePagination();
            }, 150);
        });
    }

    /**
     * Mobile Description Cleanup
     * Removes redundant "--- Closed" or "--- Closed." from description text
     * on mobile devices since we show the closed date separately.
     */
    function initMobileDescriptionCleanup() {
        function cleanupDescriptions() {
            if (window.innerWidth >= 576) return; // Only on mobile phones

            var table = document.getElementById('completedActionsTable');
            if (!table) return;

            // Find all description cells (2nd column)
            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                var descCell = row.querySelector('td:nth-child(2)');
                if (!descCell) return;

                var descDiv = descCell.querySelector('div:first-child');
                if (!descDiv || descDiv.dataset.mobileCleaned === 'true') return;

                // Remove "--- Closed" or "--- Closed." patterns
                var text = descDiv.textContent;
                var cleanedText = text
                    .replace(/\s*---\s*Closed\.?\s*$/i, '')
                    .replace(/\s*--\s*Closed\.?\s*$/i, '')
                    .trim();

                if (cleanedText !== text) {
                    descDiv.textContent = cleanedText;
                }
                descDiv.dataset.mobileCleaned = 'true';
            });
        }

        // Run on load
        cleanupDescriptions();

        // Run on resize (in case switching to mobile)
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(cleanupDescriptions, 150);
        });

        // Run when DOM changes (for dynamically loaded content)
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function(mutations) {
                var shouldCleanup = false;
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1 && (
                                node.id === 'completedActionsTable' ||
                                node.querySelector && node.querySelector('#completedActionsTable')
                            )) {
                                shouldCleanup = true;
                            }
                        });
                    }
                });
                if (shouldCleanup) {
                    setTimeout(cleanupDescriptions, 50);
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    // Initialize mobile description cleanup
    document.addEventListener('DOMContentLoaded', function() {
        initMobileDescriptionCleanup();
        initMobileCompletedActionsTap();
    });

    /**
     * Mobile Completed Actions Tap Handler
     * On mobile, the vendor cell has stopPropagation which blocks row clicks.
     * This adds tap handlers to make the entire card clickable.
     */
    function initMobileCompletedActionsTap() {
        if (window.innerWidth >= 576) return; // Only on mobile phones

        var table = document.getElementById('completedActionsTable');
        if (!table) return;

        // Add click handler to each row's cells (except links)
        var rows = table.querySelectorAll('tbody > tr[id^="completed-row-"]');
        rows.forEach(function(row) {
            var cells = row.querySelectorAll('td');
            cells.forEach(function(cell) {
                // Add tap handler to the cell
                cell.addEventListener('click', function(e) {
                    // Don't trigger if clicking a link
                    if (e.target.tagName === 'A') return;

                    // Get the row index from the row id
                    var rowId = row.id;
                    var index = rowId.replace('completed-row-', '');

                    // Call the toggle function if it exists
                    if (typeof toggleCompletedAction === 'function') {
                        toggleCompletedAction(parseInt(index));
                    }
                });
            });
        });
    }

    // Re-init on resize
    var mobileResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(mobileResizeTimer);
        mobileResizeTimer = setTimeout(function() {
            initMobileCompletedActionsTap();
        }, 250);
    });
})();
