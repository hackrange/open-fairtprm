<?php
/**
 * SRS Edit Modals - The pop-up forms for editing vendor details
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Extracted from vendor-srs-details.php because 287 lines of modal HTML
 * in the middle of a page file is how you get lost scrolling. These are
 * the inline edit forms for vendor tier, type, status, and stakeholder
 * assignment. Each modal has its own open/close JavaScript and keyboard
 * handling (Escape to close, because we are civilized).
 *
 * Required variables from vendor-srs-details.php:
 *   $csrfToken, $vendor, $assignedStakeholder
 */
?>
    <!-- Tier Change Modal -->
    <div id="tierChangeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Change Vendor Risk Tier</h3>

            <form method="POST" id="tierChangeForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_tier" value="1">

                <div style="margin-bottom: 20px;">
                    <label for="new_tier" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">New Tier:</label>
                    <select id="new_tier" name="new_tier" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Not Assigned</option>
                        <option value="1" <?php echo ($vendor['vendor_tier'] ?? '') === '1' ? 'selected' : ''; ?>>Tier 1 - Critical (Monthly rescoring)</option>
                        <option value="2" <?php echo ($vendor['vendor_tier'] ?? '') === '2' ? 'selected' : ''; ?>>Tier 2 - Standard (90-day rescoring)</option>
                        <option value="3" <?php echo ($vendor['vendor_tier'] ?? '') === '3' ? 'selected' : ''; ?>>Tier 3 - Low Priority (Annual rescoring)</option>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="tier_justification" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">Justification: <span style="color: #dc2626;">*</span></label>
                    <textarea id="tier_justification" name="tier_justification" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit;" placeholder="Enter reason for tier change..." required></textarea>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="tierCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Tier</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openTierModal() {
            document.getElementById('tierChangeModal').style.display = 'flex';
            document.getElementById('tier_justification').value = '';
            document.getElementById('tier_justification').focus();
        }

        function closeTierModal() {
            document.getElementById('tierChangeModal').style.display = 'none';
        }

        document.getElementById('tierCancelBtn').addEventListener('click', closeTierModal);

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTierModal();
            }
        });

        // Validate form before submit
        document.getElementById('tierChangeForm').addEventListener('submit', function(e) {
            const justification = document.getElementById('tier_justification').value.trim();
            if (!justification) {
                e.preventDefault();
                alert('Please provide a justification for the tier change.');
                document.getElementById('tier_justification').focus();
            }
        });
    </script>

    <!-- Vendor Name Change Modal -->
    <div id="vendorNameModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Edit Vendor Name</h3>

            <form method="POST" id="vendorNameForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_vendor_name" value="1">

                <div style="margin-bottom: 20px;">
                    <label for="new_vendor_name" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">Vendor Name: <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="new_vendor_name" name="new_vendor_name" value="<?php echo e($vendor['vendor_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" required>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="vendorNameCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Name</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openVendorNameModal() {
            document.getElementById('vendorNameModal').style.display = 'flex';
            var input = document.getElementById('new_vendor_name');
            input.focus();
            input.select();
        }

        function closeVendorNameModal() {
            document.getElementById('vendorNameModal').style.display = 'none';
        }

        document.getElementById('vendorNameCancelBtn').addEventListener('click', closeVendorNameModal);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeVendorNameModal();
            }
        });

        document.getElementById('vendorNameForm').addEventListener('submit', function(e) {
            var name = document.getElementById('new_vendor_name').value.trim();
            if (!name) {
                e.preventDefault();
                alert('Vendor name cannot be empty.');
                document.getElementById('new_vendor_name').focus();
            }
        });
    </script>

    <!-- Contact Information Modal -->
    <div id="contactModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Edit Contact Information</h3>

            <form method="POST" id="contactForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_contact_info" value="1">

                <div style="margin-bottom: 15px;">
                    <label for="new_contact_name" style="display: block; margin-bottom: 6px; font-weight: 500; color: #333;">Primary Contact</label>
                    <input type="text" id="new_contact_name" name="new_contact_name" value="<?php echo e($vendor['primary_contact_details'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" placeholder="Full name">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="new_contact_title" style="display: block; margin-bottom: 6px; font-weight: 500; color: #333;">Title</label>
                    <input type="text" id="new_contact_title" name="new_contact_title" value="<?php echo e($vendor['primary_contact_title'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" placeholder="Job title">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="new_contact_email" style="display: block; margin-bottom: 6px; font-weight: 500; color: #333;">Email</label>
                    <input type="email" id="new_contact_email" name="new_contact_email" value="<?php echo e($vendor['primary_contact_email'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" placeholder="email@example.com">
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="new_contact_phone" style="display: block; margin-bottom: 6px; font-weight: 500; color: #333;">Phone</label>
                    <input type="text" id="new_contact_phone" name="new_contact_phone" value="<?php echo e($vendor['primary_contact_phone'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" placeholder="Phone number">
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="contactCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Contact</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openContactModal() {
            document.getElementById('contactModal').style.display = 'flex';
            document.getElementById('new_contact_name').focus();
        }

        function closeContactModal() {
            document.getElementById('contactModal').style.display = 'none';
        }

        document.getElementById('contactCancelBtn').addEventListener('click', closeContactModal);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeContactModal();
            }
        });
    </script>

    <!-- Vendor Type Change Modal -->
    <div id="vendorTypeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Change Vendor Type</h3>

            <form method="POST" id="vendorTypeForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_vendor_type" value="1">

                <div style="margin-bottom: 20px;">
                    <label for="new_vendor_type" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">Vendor Type:</label>
                    <select id="new_vendor_type" name="new_vendor_type" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Not specified</option>
                        <?php
                        $vendorTypes = [
                            'GENERAL OPERATIONS',
                            'TECHNOLOGY',
                            'PROFESSIONAL SERVICES',
                            'FINANCIAL SERVICES',
                            'MARKETING',
                            'HR/BENEFITS',
                            'FACILITIES',
                            'LEGAL',
                            'OTHER'
                        ];
                        foreach ($vendorTypes as $type):
                        ?>
                            <option value="<?php echo e($type); ?>" <?php echo ($vendor['vendor_type'] ?? '') === $type ? 'selected' : ''; ?>>
                                <?php echo e($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="vendorTypeCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Type</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openVendorTypeModal() {
            document.getElementById('vendorTypeModal').style.display = 'flex';
            document.getElementById('new_vendor_type').focus();
        }

        function closeVendorTypeModal() {
            document.getElementById('vendorTypeModal').style.display = 'none';
        }

        document.getElementById('vendorTypeCancelBtn').addEventListener('click', closeVendorTypeModal);

        // Close vendor type modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeVendorTypeModal();
            }
        });
    </script>

    <!-- Status Change Modal -->
    <div id="statusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Change Vendor Status</h3>

            <form method="POST" id="statusForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_status" value="1">

                <div style="margin-bottom: 20px;">
                    <label for="new_status" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">Status:</label>
                    <select id="new_status" name="new_status" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="draft" <?php echo ($vendor['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="submitted" <?php echo ($vendor['status'] ?? '') === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="in_review" <?php echo ($vendor['status'] ?? '') === 'in_review' ? 'selected' : ''; ?>>In Review</option>
                        <option value="approved" <?php echo ($vendor['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo ($vendor['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="inactive" <?php echo ($vendor['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="statusCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openStatusModal() {
            document.getElementById('statusModal').style.display = 'flex';
            document.getElementById('new_status').focus();
        }

        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        document.getElementById('statusCancelBtn').addEventListener('click', closeStatusModal);

        // Close status modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStatusModal();
            }
        });
    </script>

    <!-- Stakeholder Change Modal -->
    <div id="stakeholderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #333; font-size: 20px;">Assign Stakeholder</h3>

            <form method="POST" id="stakeholderForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <input type="hidden" name="update_stakeholder" value="1">
                <input type="hidden" id="stakeholder_user_id" name="stakeholder_user_id" value="<?php echo e($assignedStakeholder['id'] ?? ''); ?>">

                <div style="margin-bottom: 20px; position: relative;">
                    <label for="stakeholder_name" style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">Stakeholder Name:</label>
                    <input type="text" id="stakeholder_name" autocomplete="off" value="<?php echo e($assignedStakeholder['full_name'] ?? $assignedStakeholder['username'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" placeholder="Start typing to search users...">
                    <div id="stakeholder_suggestions" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-radius: 4px; max-height: 200px; overflow-y: auto; display: none; z-index: 1000; margin-top: 2px;"></div>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" id="stakeholderCancelBtn" style="padding: 10px 20px; border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; font-size: 14px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; border: none; background: var(--theme-header-color); color: white; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500;">Update Stakeholder</button>
                </div>
            </form>
        </div>
    </div>

    <script nonce="<?php echo cspNonce(); ?>">
        function openStakeholderModal() {
            document.getElementById('stakeholderModal').style.display = 'flex';
            document.getElementById('stakeholder_name').focus();
        }

        function closeStakeholderModal() {
            document.getElementById('stakeholderModal').style.display = 'none';
            document.getElementById('stakeholder_suggestions').style.display = 'none';
        }

        document.getElementById('stakeholderCancelBtn').addEventListener('click', closeStakeholderModal);

        // Close stakeholder modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStakeholderModal();
            }
        });

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Stakeholder autocomplete
        (function() {
            const input = document.getElementById('stakeholder_name');
            const hiddenInput = document.getElementById('stakeholder_user_id');
            const suggestionsBox = document.getElementById('stakeholder_suggestions');
            let debounceTimer = null;

            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();

                if (query.length < 2) {
                    suggestionsBox.style.display = 'none';
                    return;
                }

                debounceTimer = setTimeout(function() {
                    fetch('api/search-users.php?q=' + encodeURIComponent(query))
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                console.error('API error:', data.error);
                                suggestionsBox.innerHTML = '<div style="padding: 10px; color: #dc2626;">Error loading users</div>';
                                suggestionsBox.style.display = 'block';
                                return;
                            }

                            const users = data.users || [];

                            if (users.length === 0) {
                                suggestionsBox.innerHTML = '<div style="padding: 10px; color: #999;">No users found</div>';
                                suggestionsBox.style.display = 'block';
                                return;
                            }

                            suggestionsBox.innerHTML = users.map(user =>
                                '<div class="suggestion-item" data-id="' + user.id + '" data-name="' + escapeHtml(user.full_name || user.username) + '" data-username="' + escapeHtml(user.username) + '" style="padding: 10px; cursor: pointer; border-bottom: 1px solid #f0f0f0;">' +
                                '<div style="font-weight: 500;">' + escapeHtml(user.full_name || user.username) + '</div>' +
                                '<div style="font-size: 12px; color: #666;">@' + escapeHtml(user.username) + ' &middot; ' + escapeHtml(user.email) + '</div>' +
                                '</div>'
                            ).join('');

                            suggestionsBox.style.display = 'block';

                            // Add click handlers
                            suggestionsBox.querySelectorAll('.suggestion-item').forEach(item => {
                                item.addEventListener('click', function() {
                                    hiddenInput.value = this.dataset.id;
                                    input.value = this.dataset.name;
                                    suggestionsBox.style.display = 'none';
                                });
                            });
                        })
                        .catch(error => {
                            console.error('Error fetching users:', error);
                            suggestionsBox.innerHTML = '<div style="padding: 10px; color: #dc2626;">Network error</div>';
                            suggestionsBox.style.display = 'block';
                        });
                }, 300);
            });

            // Close suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !suggestionsBox.contains(e.target)) {
                    suggestionsBox.style.display = 'none';
                }
            });

            // Validate form
            document.getElementById('stakeholderForm').addEventListener('submit', function(e) {
                if (!hiddenInput.value) {
                    e.preventDefault();
                    alert('Please select a valid stakeholder from the suggestions.');
                    input.focus();
                }
            });
        })();
    </script>
