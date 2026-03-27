/**
 * Vendor Search Type-Ahead
 *
 * Shared vendor search component used across multiple pages.
 * Looks for #vendorSearchInput and #vendorSearchResults on the page;
 * if both exist it wires up a debounced type-ahead against the
 * api/search-vendors.php endpoint.
 *
 * This is an external script so it is allowed by script-src 'self'
 * without needing a CSP nonce.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('vendorSearchInput');
        var searchResults = document.getElementById('vendorSearchResults');
        var searchTimeout = null;

        if (!searchInput || !searchResults) return;

        // Allow pages to override the link target via data attribute
        var linkBase = searchInput.getAttribute('data-link-base') || 'vendor-srs-details.php';

        searchInput.addEventListener('input', function() {
            var query = this.value.trim();

            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            if (query.length < 2) {
                searchResults.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(function() {
                performSearch(query);
            }, 300);
        });

        function performSearch(query) {
            fetch('api/search-vendors.php?q=' + encodeURIComponent(query))
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.success) {
                        console.error('Search error:', data.error);
                        return;
                    }
                    displayResults(data.vendors);
                })
                .catch(function(error) {
                    console.error('Search failed:', error);
                });
        }

        function displayResults(vendors) {
            if (vendors.length === 0) {
                var html = '<div style="padding: 15px; text-align: center; color: #999; font-size: 13px;">No vendors found</div>';
                searchResults.innerHTML = html;
                searchResults.style.display = 'block';
                return;
            }

            var html = '<div style="padding: 8px 0;">';

            vendors.forEach(function(vendor) {
                var isShadow = vendor.is_shadow_saas === true;
                var hasScore = vendor.combined_score !== null && vendor.combined_score !== undefined;
                var grade = vendor.grade;
                var isInactive = vendor.status === 'inactive';
                var href = isShadow ? 'shadow-saas.php?search=' + encodeURIComponent(vendor.vendor_name) : linkBase + '?id=' + vendor.id;

                html += '<a href="' + href + '" class="hover-bg-light" style="display: block; padding: 12px 15px; text-decoration: none; border-bottom: 1px solid #f3f4f6; transition: background 0.2s;' + (isInactive ? ' opacity: 0.6;' : '') + (isShadow ? ' background: #fffbeb;' : '') + '">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">';

                // Left side - Vendor info
                html += '<div style="flex: 1; min-width: 0;">';
                html += '<div style="font-weight: 500; color: #333; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">';
                if (isShadow) {
                    html += '<img src="app/icons/alert-square.svg" alt="" width="14" height="14" style="vertical-align: middle; margin-right: 5px; opacity: 0.7;">';
                }
                html += escapeHtml(vendor.vendor_name || 'Unnamed');
                if (isInactive) {
                    html += ' <span style="display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: #f3f4f6; color: #6b7280; vertical-align: middle; margin-left: 6px;">Inactive</span>';
                }
                html += '</div>';
                if (vendor.vendor_domain) {
                    html += '<div style="font-size: 12px; color: #6b7280; margin-top: 2px;">' + escapeHtml(vendor.vendor_domain) + '</div>';
                }
                if (vendor.stakeholder_name) {
                    html += '<div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">' + (isShadow ? 'RM: ' : 'Stakeholder: ') + escapeHtml(vendor.stakeholder_name) + '</div>';
                }
                html += '</div>';

                // Right side - Score and Tier
                html += '<div style="display: flex; align-items: center; gap: 10px;">';

                if (hasScore) {
                    html += '<div style="display: flex; align-items: center; gap: 6px;">';
                    html += '<span style="font-weight: 600; font-size: 14px; color: #374151;">' + vendor.combined_score + '%</span>';
                    html += '<span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; font-weight: 700; font-size: 12px; ' + getGradeStyle(grade) + '">' + grade + '</span>';
                    html += '</div>';
                } else if (!isShadow) {
                    html += '<span style="color: #9ca3af; font-size: 12px; font-style: italic;">Not scored</span>';
                }

                if (isShadow) {
                    html += '<span style="padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 500; white-space: nowrap; background: #fef3c7; color: #92400e;">Shadow SaaS</span>';
                } else {
                    html += '<span style="padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 500; white-space: nowrap; ' + getTierStyle(vendor.vendor_tier) + '">' + escapeHtml(vendor.tier_name) + '</span>';
                }

                html += '</div>';
                html += '</div>';
                html += '</a>';
            });

            html += '</div>';

            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
        }

        function getGradeStyle(grade) {
            var styles = {
                'A': 'background: #dcfce7; color: #166534;',
                'B': 'background: #d1fae5; color: #065f46;',
                'C': 'background: #fef3c7; color: #92400e;',
                'D': 'background: #fed7aa; color: #9a3412;',
                'F': 'background: #fecaca; color: #991b1b;'
            };
            return styles[grade] || '';
        }

        function getTierStyle(tier) {
            var styles = {
                '1': 'background: #fef2f2; color: #991b1b;',
                '2': 'background: #fef3c7; color: #92400e;',
                '3': 'background: #ecfdf5; color: #065f46;'
            };
            return styles[tier] || 'background: #f3f4f6; color: #6b7280;';
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close results when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });

        // Show results when focusing on input if there's content
        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2 && searchResults.innerHTML) {
                searchResults.style.display = 'block';
            }
        });
    });
})();
