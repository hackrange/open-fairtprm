<?php
/**
 * Admin Section: Email / SMTP Settings & Email Templates
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The email plumbing AND the templates that ride on it. The Settings tab handles
 * SMTP host, port, encryption, credentials -- all the fun stuff. The Vendor,
 * Stakeholder, and Procurement tabs let admins customize the actual email content
 * sent to each audience. Templates use {{placeholder}} syntax for dynamic values.
 *
 * Passwords are stored encrypted because we're not animals.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

require_once __DIR__ . '/../classes/EmailService.php';

// ============================================================================
// POST Handler: update_email_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email_settings'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $db->beginTransaction();
            $encryption = new Encryption();

            $emailMethod = in_array($_POST['email_method'] ?? 'smtp', ['smtp', 'microsoft_graph']) ? $_POST['email_method'] : 'smtp';

            $emailSettings = [
                'email_enabled' => isset($_POST['email_enabled']) ? '1' : '0',
                'email_method' => $emailMethod,
                'smtp_host' => filter_var(trim($_POST['smtp_host'] ?? 'localhost'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ?: 'localhost',
                'smtp_port' => (string)max(1, min(65535, intval($_POST['smtp_port'] ?? 25))),
                'smtp_encryption' => in_array($_POST['smtp_encryption'] ?? 'none', ['none', 'tls', 'ssl']) ? $_POST['smtp_encryption'] : 'none',
                'smtp_username' => trim($_POST['smtp_username'] ?? ''),
                'email_from_email' => filter_var(trim($_POST['email_from_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
                'email_from_name' => trim($_POST['email_from_name'] ?? 'TPRM System')
            ];

            // Microsoft 365 Graph API fields
            $msTenantId = trim($_POST['email_ms_tenant_id'] ?? '');
            $msClientId = trim($_POST['email_ms_client_id'] ?? '');
            $msSender = trim($_POST['email_ms_sender'] ?? '');
            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

            if ($emailMethod === 'microsoft_graph') {
                if (!empty($msTenantId) && !preg_match($uuidPattern, $msTenantId)) {
                    throw new Exception('Tenant ID must be a valid UUID format.');
                }
                if (!empty($msClientId) && !preg_match($uuidPattern, $msClientId)) {
                    throw new Exception('Client ID must be a valid UUID format.');
                }
            }

            $emailSettings['email_ms_tenant_id'] = $msTenantId;
            $emailSettings['email_ms_client_id'] = $msClientId;
            $emailSettings['email_ms_sender'] = $msSender;

            // Handle SMTP password (encrypt if provided)
            $smtpPassword = trim($_POST['smtp_password'] ?? '');
            if (!empty($smtpPassword)) {
                $emailSettings['smtp_password'] = $encryption->encrypt($smtpPassword);
            } else {
                $existingPassword = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'smtp_password']
                );
                if ($existingPassword) {
                    $emailSettings['smtp_password'] = $existingPassword['config_value'];
                }
            }

            // Handle Graph client secret (encrypt if provided)
            $msClientSecret = trim($_POST['email_ms_client_secret'] ?? '');
            if (!empty($msClientSecret)) {
                $emailSettings['email_ms_client_secret'] = $encryption->encrypt($msClientSecret);
            } else {
                $existingSecret = $db->fetchOne(
                    'SELECT config_value FROM app_config WHERE config_key = :key',
                    [':key' => 'email_ms_client_secret']
                );
                if ($existingSecret) {
                    $emailSettings['email_ms_client_secret'] = $existingSecret['config_value'];
                }
            }

            foreach ($emailSettings as $key => $value) {
                $isEncrypted = ($key === 'smtp_password' || $key === 'email_ms_client_secret') ? 1 : 0;

                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        [
                            'config_value' => $value,
                            'is_encrypted' => $isEncrypted
                        ],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => $isEncrypted
                    ]);
                }
            }

            $db->commit();
            $auditSettings = $emailSettings;
            unset($auditSettings['smtp_password']);
            unset($auditSettings['email_ms_client_secret']);
            $auth->audit($user['id'], 'config_update_email', 'app_config', null, [
                'new' => $auditSettings
            ]);
            $success = 'Email settings updated successfully';
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating email settings: ' . $e->getMessage());
            $error = 'Failed to update email settings';
        }
    }
}

// ============================================================================
// POST Handler: send_test_email
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $testRecipient = filter_var(trim($_POST['test_email_recipient'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$testRecipient) {
            $error = 'Please enter a valid email address.';
        } else {
            $encryption = new Encryption();
            $emailService = new EmailService($db, $encryption);
            if (!$emailService->isEnabled()) {
                $error = 'Email notifications are currently disabled. Enable them and save your settings first.';
            } else {
                $result = $emailService->sendTestEmail($testRecipient);
                if ($result['success']) {
                    $auth->audit($user['id'], 'test_email_sent', 'app_config', null, [
                        'new' => ['recipient' => $testRecipient]
                    ]);
                    $success = $result['message'];
                } else {
                    $error = $result['message'];
                }
            }
        }
    }
}

// ============================================================================
// POST Handler: save_breach_alert_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_breach_alert_settings'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $breachEnabled = $_POST['breach_alert_enabled'] === '1' ? '1' : '0';
        $breachRecipients = trim($_POST['breach_alert_recipients'] ?? '');

        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('breach_alert_enabled', :v, 'Enable/disable breach alert monitoring') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $breachEnabled, ':v2' => $breachEnabled]);
        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('breach_alert_recipients', :v, 'Comma-separated email addresses for breach alerts') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $breachRecipients, ':v2' => $breachRecipients]);

        $success = 'Breach/Cyber Alert settings saved.';
    }
}

// POST Handler: update_email_template
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email_template'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        $emailSubject = trim($_POST['email_subject'] ?? '');
        $emailBodyHtml = $_POST['email_body_html'] ?? '';
        $emailBodyText = $_POST['email_body_text'] ?? '';

        if (!$templateId || empty($emailSubject)) {
            $error = 'Template ID and subject are required.';
        } else {
            try {
                $db->query(
                    "UPDATE email_templates SET email_subject = ?, email_body_html = ?, email_body_text = ?, updated_at = NOW() WHERE id = ?",
                    [$emailSubject, $emailBodyHtml, $emailBodyText, $templateId]
                );
                $auth->audit($user['id'], 'email_template_update', 'email_templates', $templateId, [
                    'new' => ['subject' => $emailSubject]
                ]);
                $success = 'Email template updated successfully.';
            } catch (Exception $e) {
                error_log('Error updating email template: ' . $e->getMessage());
                $error = 'Failed to update email template.';
            }
        }
    }
}

// ============================================================================
// POST Handler: reset_email_template
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_email_template'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        if (!$templateId) {
            $error = 'Template ID is required.';
        } else {
            try {
                $tpl = $db->fetchOne("SELECT template_category, template_key FROM email_templates WHERE id = ?", [$templateId]);
                if ($tpl) {
                    $defaults = EmailService::getDefaultTemplateContent($tpl['template_category'], $tpl['template_key']);
                    if ($defaults) {
                        $db->query(
                            "UPDATE email_templates SET email_subject = ?, email_body_html = ?, email_body_text = ?, updated_at = NOW() WHERE id = ?",
                            [$defaults['subject'], $defaults['html'], $defaults['text'], $templateId]
                        );
                        $success = 'Email template reset to default.';
                    } else {
                        $error = 'No default template found for this type.';
                    }
                } else {
                    $error = 'Template not found.';
                }
            } catch (Exception $e) {
                error_log('Error resetting email template: ' . $e->getMessage());
                $error = 'Failed to reset email template.';
            }
        }
    }
}

// ============================================================================
// POST Handler: send_template_test
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_template_test'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        $testRecipient = filter_var(trim($_POST['test_recipient'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$templateId || !$testRecipient) {
            $error = 'Template ID and valid email address are required.';
        } else {
            $encryption = new Encryption();
            $emailService = new EmailService($db, $encryption);
            if (!$emailService->isEnabled()) {
                $error = 'Email notifications are currently disabled. Enable them in Settings first.';
            } else {
                $tpl = $db->fetchOne("SELECT * FROM email_templates WHERE id = ?", [$templateId]);
                if (!$tpl) {
                    $error = 'Template not found.';
                } else {
                    $result = $emailService->sendTemplateTest($tpl, $testRecipient);
                    if ($result['success']) {
                        $auth->audit($user['id'], 'template_test_sent', 'email_templates', $templateId, [
                            'new' => ['recipient' => $testRecipient, 'template' => $tpl['template_key']]
                        ]);
                        $success = $result['message'];
                    } else {
                        $error = $result['message'];
                    }
                }
            }
        }
    }
}

// ============================================================================
// POST Handler: update_procurement_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_procurement_settings'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $procSettings = [
                'procurement_notifications_enabled' => isset($_POST['procurement_notifications_enabled']) ? '1' : '0',
                'procurement_notification_recipients' => in_array($_POST['procurement_notification_recipients'] ?? 'all', ['all', 'selected', 'none']) ? $_POST['procurement_notification_recipients'] : 'all',
                'procurement_notification_warning_days' => (string)max(1, min(365, intval($_POST['procurement_notification_warning_days'] ?? 30))),
            ];

            // Handle selected users (JSON array of user IDs)
            $selectedUsers = [];
            if (!empty($_POST['procurement_notification_selected_users']) && is_array($_POST['procurement_notification_selected_users'])) {
                $selectedUsers = array_map('intval', $_POST['procurement_notification_selected_users']);
            }
            $procSettings['procurement_notification_selected_users'] = json_encode($selectedUsers);

            foreach ($procSettings as $key => $value) {
                $existing = $db->fetchOne('SELECT id FROM app_config WHERE config_key = :key', [':key' => $key]);
                if ($existing) {
                    $db->update('app_config', ['config_value' => $value], 'config_key = :key', [':key' => $key]);
                } else {
                    $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => 0]);
                }
            }

            $auth->audit($user['id'], 'config_update_procurement_email', 'app_config', null, ['new' => $procSettings]);
            $success = 'Procurement notification settings saved.';
            $activeTab = 'procurement';
        } catch (Exception $e) {
            error_log('Error updating procurement settings: ' . $e->getMessage());
            $error = 'Failed to update procurement notification settings.';
        }
    }
}

// ============================================================================
// Data Loading: Email/SMTP configuration
// ============================================================================
$emailConfig = [];
$encryption = new Encryption();
$emailRows = $db->fetchAll('SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE ? OR config_key LIKE ?', ['email_%', 'smtp_%']);
foreach ($emailRows as $row) {
    $key = $row['config_key'];
    // Don't decrypt secrets for display (show placeholder)
    if (($key === 'smtp_password' || $key === 'email_ms_client_secret') && !empty($row['config_value'])) {
        $emailConfig[$key] = '********'; // Show masked
    } elseif ($row['is_encrypted'] && !empty($row['config_value'])) {
        $emailConfig[$key] = $encryption->decrypt($row['config_value']);
    } else {
        $emailConfig[$key] = $row['config_value'];
    }
}
// Set defaults if not configured
if (!isset($emailConfig['email_enabled'])) $emailConfig['email_enabled'] = '0';
if (!isset($emailConfig['smtp_host'])) $emailConfig['smtp_host'] = 'localhost';
if (!isset($emailConfig['smtp_port'])) $emailConfig['smtp_port'] = '25';
if (!isset($emailConfig['smtp_encryption'])) $emailConfig['smtp_encryption'] = 'none';
if (!isset($emailConfig['smtp_username'])) $emailConfig['smtp_username'] = '';
if (!isset($emailConfig['smtp_password'])) $emailConfig['smtp_password'] = '';
if (!isset($emailConfig['email_from_email'])) $emailConfig['email_from_email'] = 'noreply@example.com';
if (!isset($emailConfig['email_from_name'])) $emailConfig['email_from_name'] = 'TPRM System';
if (!isset($emailConfig['email_method'])) $emailConfig['email_method'] = 'smtp';
if (!isset($emailConfig['email_ms_tenant_id'])) $emailConfig['email_ms_tenant_id'] = '';
if (!isset($emailConfig['email_ms_client_id'])) $emailConfig['email_ms_client_id'] = '';
if (!isset($emailConfig['email_ms_client_secret'])) $emailConfig['email_ms_client_secret'] = '';
if (!isset($emailConfig['email_ms_sender'])) $emailConfig['email_ms_sender'] = '';

// ============================================================================
// Data Loading: Email templates
// ============================================================================
$emailTemplates = [];
try {
    $templateRows = $db->fetchAll(
        "SELECT et.*, at.name as assessment_name, at.slug as assessment_slug
         FROM email_templates et
         LEFT JOIN assessment_templates at ON et.assessment_template_id = at.id
         ORDER BY et.template_category, et.display_name"
    );

    // Seed defaults on first access after migration (table exists but is empty),
    // or update available_variables on existing rows to pick up new placeholders
    $seeded = EmailService::seedDefaultTemplates($db);
    if ($seeded || empty($templateRows)) {
        $templateRows = $db->fetchAll(
            "SELECT et.*, at.name as assessment_name, at.slug as assessment_slug
             FROM email_templates et
             LEFT JOIN assessment_templates at ON et.assessment_template_id = at.id
             ORDER BY et.template_category, et.display_name"
        );
    }

    foreach ($templateRows as $row) {
        $emailTemplates[$row['template_category']][] = $row;
    }
} catch (Exception $e) {
    // Table might not exist yet -- silently handle
    $emailTemplates = [];
}

// ============================================================================
// Data Loading: Procurement notification settings
// ============================================================================
$procConfig = [];
try {
    $procRows = $db->fetchAll("SELECT config_key, config_value FROM app_config WHERE config_key LIKE 'procurement_%'");
    foreach ($procRows as $row) {
        $procConfig[$row['config_key']] = $row['config_value'];
    }
} catch (Exception $e) {}

// Defaults
if (!isset($procConfig['procurement_notifications_enabled'])) $procConfig['procurement_notifications_enabled'] = '0';
if (!isset($procConfig['procurement_notification_recipients'])) $procConfig['procurement_notification_recipients'] = 'all';
if (!isset($procConfig['procurement_notification_warning_days'])) $procConfig['procurement_notification_warning_days'] = '30';
if (!isset($procConfig['procurement_notification_selected_users'])) $procConfig['procurement_notification_selected_users'] = '[]';

$procSelectedUsers = json_decode($procConfig['procurement_notification_selected_users'], true) ?: [];

// Fetch procurement group members for the multi-select
$procurementUsers = [];
try {
    $procurementUsers = $db->fetchAll(
        "SELECT DISTINCT u.id, u.full_name, u.email
         FROM users u
         JOIN user_acl_groups uag ON u.id = uag.user_id
         JOIN acl_groups ag ON uag.group_id = ag.id
         WHERE ag.group_name IN ('procurement', 'administrator', 'cyber_tprm')
           AND u.is_active = 1
         ORDER BY u.full_name"
    );
} catch (Exception $e) {}

// Determine which tab to show after POST.
// Note: admin.php includes this file twice (first for POST handling, second for
// rendering) and sets $_SERVER['REQUEST_METHOD'] to GET between passes. We check
// $_POST keys directly since $_POST persists across both includes.
$activeTab = 'settings';
if (isset($_POST['save_breach_alert_settings'])) {
    $activeTab = 'breach_alert';
} elseif (isset($_POST['update_procurement_settings'])) {
    $activeTab = 'procurement';
} elseif (isset($_POST['update_email_template']) || isset($_POST['reset_email_template'])) {
    // Figure out which tab the template belongs to
    $postTemplateId = intval($_POST['template_id'] ?? 0);
    if ($postTemplateId) {
        try {
            $postTpl = $db->fetchOne("SELECT template_category FROM email_templates WHERE id = ?", [$postTemplateId]);
            if ($postTpl) $activeTab = $postTpl['template_category'];
        } catch (Exception $e) {}
    }
}

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title">Email Settings</h1>
    <p>Configure email delivery settings and customize email templates for vendors and stakeholders.</p>
</div>

<!-- Tab Navigation (same pattern as Shodan SRS) -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showEmailSection" data-arg="settings" id="emailTab_settings" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'settings' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'settings' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'settings' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Settings</button>
    <button type="button" data-action="showEmailSection" data-arg="vendor" id="emailTab_vendor" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'vendor' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Vendor</button>
    <button type="button" data-action="showEmailSection" data-arg="stakeholder" id="emailTab_stakeholder" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'stakeholder' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'stakeholder' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'stakeholder' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Stakeholder</button>
    <button type="button" data-action="showEmailSection" data-arg="procurement" id="emailTab_procurement" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'procurement' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'procurement' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'procurement' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Procurement</button>
    <button type="button" data-action="showEmailSection" data-arg="grc" id="emailTab_grc" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'grc' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'grc' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'grc' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">GRC</button>
    <button type="button" data-action="showEmailSection" data-arg="breach_alert" id="emailTab_breach_alert" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'breach_alert' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'breach_alert' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'breach_alert' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;">Breach/Cyber Alerts</button>
</div>

<!-- ================================================================== -->
<!-- SETTINGS TAB -->
<!-- ================================================================== -->
<div id="emailSection_settings" <?php echo $activeTab !== 'settings' ? 'style="display: none;"' : ''; ?>>
<div class="card">
    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="email_enabled" value="1" <?php echo ($emailConfig['email_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable Email Notifications</span>
            </label>
            <div class="form-help">Enable automated email reminders for annual vendor reviews</div>
        </div>

        <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;">Email Delivery Method</h4>

        <div class="form-group">
            <label style="display: inline-flex; align-items: center; gap: 6px; margin-right: 20px; cursor: pointer;">
                <input type="radio" name="email_method" value="smtp" <?php echo ($emailConfig['email_method'] ?? 'smtp') === 'smtp' ? 'checked' : ''; ?> data-action="toggleEmailMethod">
                <span>SMTP</span>
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="radio" name="email_method" value="microsoft_graph" <?php echo ($emailConfig['email_method'] ?? 'smtp') === 'microsoft_graph' ? 'checked' : ''; ?> data-action="toggleEmailMethod">
                <span>Microsoft 365 (Graph API)</span>
            </label>
            <div class="form-help">Choose how emails are sent. Microsoft 365 Graph API uses OAuth2 client credentials -- no SMTP passwords needed.</div>
        </div>

        <!-- SMTP Settings Section -->
        <div id="smtp_settings_section" style="<?php echo ($emailConfig['email_method'] ?? 'smtp') === 'microsoft_graph' ? 'display:none;' : ''; ?>">
            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;">SMTP Server Configuration</h4>

            <div class="form-group">
                <label for="email_provider">Email Provider (Quick Setup)</label>
                <select id="email_provider" class="form-control" data-action="fillProviderSettings">
                    <option value="">-- Select a provider or configure manually --</option>
                    <option value="mailgun">Mailgun</option>
                    <option value="office365">Microsoft 365 / Outlook (SMTP)</option>
                    <option value="gmail">Gmail</option>
                    <option value="sendgrid">SendGrid</option>
                    <option value="amazon_ses">Amazon SES</option>
                    <option value="custom">Custom SMTP Server</option>
                </select>
                <div class="form-help">Select a provider to auto-fill SMTP settings below</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_host">SMTP Host</label>
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?php echo e($emailConfig['smtp_host'] ?? 'localhost'); ?>" placeholder="smtp.mailgun.org">
                    <div class="form-help">SMTP server hostname</div>
                </div>
                <div class="form-group">
                    <label for="smtp_port">SMTP Port</label>
                    <input type="number" id="smtp_port" name="smtp_port" class="form-control" min="1" max="65535" value="<?php echo e($emailConfig['smtp_port'] ?? '25'); ?>">
                    <div class="form-help">Common ports: 25 (unencrypted), 587 (TLS), 465 (SSL)</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_encryption">Encryption Type</label>
                    <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                        <option value="none" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'none' ? 'selected' : ''; ?>>None</option>
                        <option value="tls" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                        <option value="ssl" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    </select>
                    <div class="form-help">TLS is recommended for modern SMTP servers (port 587)</div>
                </div>
                <div class="form-group">
                    <label for="smtp_username">SMTP Username</label>
                    <input type="text" id="smtp_username" name="smtp_username" class="form-control" value="<?php echo e($emailConfig['smtp_username'] ?? ''); ?>" placeholder="postmaster@mg.yourdomain.com">
                    <div class="form-help">Email address or username for SMTP authentication</div>
                </div>
            </div>

            <div class="form-group">
                <label for="smtp_password">SMTP Password</label>
                <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="Leave blank to keep existing password">
                <div class="form-help">
                    Password or app-specific password (stored encrypted)<br>
                    <small><strong>Mailgun:</strong> Use the Default Password from Mailgun Dashboard &rarr; Sending &rarr; Domain Settings &rarr; SMTP Credentials</small>
                </div>
            </div>

            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;">"From" Address Configuration</h4>

            <div class="form-row">
                <div class="form-group">
                    <label for="email_from_email">From Email Address</label>
                    <input type="email" id="email_from_email" name="email_from_email" class="form-control" value="<?php echo e($emailConfig['email_from_email'] ?? 'noreply@example.com'); ?>" placeholder="noreply@hackrange.com">
                    <div class="form-help">Email address that appears in the "From" field</div>
                </div>
                <div class="form-group">
                    <label for="email_from_name">From Name</label>
                    <input type="text" id="email_from_name" name="email_from_name" class="form-control" value="<?php echo e($emailConfig['email_from_name'] ?? 'TPRM System'); ?>" placeholder="OPEN TPRM System">
                    <div class="form-help">Name that appears in the "From" field</div>
                </div>
            </div>
        </div><!-- end smtp_settings_section -->

        <!-- Microsoft 365 Graph API Settings Section -->
        <div id="graph_settings_section" style="<?php echo ($emailConfig['email_method'] ?? 'smtp') !== 'microsoft_graph' ? 'display:none;' : ''; ?>">
            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;">Microsoft 365 Graph API Configuration</h4>

            <div class="form-row">
                <div class="form-group">
                    <label for="email_ms_tenant_id">Tenant ID</label>
                    <input type="text" id="email_ms_tenant_id" name="email_ms_tenant_id" class="form-control" value="<?php echo e($emailConfig['email_ms_tenant_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-help">Azure AD Tenant ID (UUID format)</div>
                </div>
                <div class="form-group">
                    <label for="email_ms_client_id">Client (Application) ID</label>
                    <input type="text" id="email_ms_client_id" name="email_ms_client_id" class="form-control" value="<?php echo e($emailConfig['email_ms_client_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-help">Application (client) ID from Azure App Registration</div>
                </div>
            </div>

            <div class="form-group">
                <label for="email_ms_client_secret">Client Secret</label>
                <input type="password" id="email_ms_client_secret" name="email_ms_client_secret" class="form-control" placeholder="<?php echo !empty($emailConfig['email_ms_client_secret']) ? 'Leave blank to keep existing secret' : 'Enter client secret'; ?>">
                <div class="form-help">Client secret value from Azure App Registration &rarr; Certificates &amp; Secrets (stored encrypted)</div>
            </div>

            <div class="form-group">
                <label for="email_ms_sender">Sender Email Address</label>
                <input type="email" id="email_ms_sender" name="email_ms_sender" class="form-control" value="<?php echo e($emailConfig['email_ms_sender'] ?? ''); ?>" placeholder="noreply@yourdomain.com">
                <div class="form-help">The Microsoft 365 mailbox (UPN) to send emails from. This mailbox must exist in your tenant.</div>
            </div>

            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px; margin: 15px 0;">
                <strong style="color: #1e40af;">Azure Setup Instructions</strong>
                <ol style="margin: 8px 0 0; color: #1e3a5f; font-size: 13px; padding-left: 20px; line-height: 1.8;">
                    <li>Go to <strong>Azure Portal</strong> &rarr; <strong>App Registrations</strong> &rarr; <strong>New Registration</strong></li>
                    <li>Name it (e.g., "FairTPRM Email") and register</li>
                    <li>Copy the <strong>Application (client) ID</strong> and <strong>Directory (tenant) ID</strong></li>
                    <li>Go to <strong>Certificates &amp; Secrets</strong> &rarr; <strong>New Client Secret</strong> &rarr; copy the <strong>Value</strong></li>
                    <li>Go to <strong>API Permissions</strong> &rarr; <strong>Add a permission</strong> &rarr; <strong>Microsoft Graph</strong> &rarr; <strong>Application permissions</strong></li>
                    <li>Add <code>Mail.Send</code> permission and click <strong>Grant admin consent</strong></li>
                </ol>
            </div>
        </div><!-- end graph_settings_section -->

        <button type="submit" name="update_email_settings" class="btn btn-primary">Save Email Settings</button>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <h4 style="margin: 0 0 15px; color: #333; font-size: 16px; font-weight: 600;">Send Test Email</h4>
    <p style="color: #666; font-size: 13px; margin: 0 0 15px;">Send a test email to verify your email configuration is working correctly. Make sure to save your settings first.</p>
    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group" style="display: flex; gap: 10px; align-items: flex-end;">
            <div style="flex: 1;">
                <label for="test_email_recipient">Recipient Email Address</label>
                <input type="email" id="test_email_recipient" name="test_email_recipient" class="form-control" placeholder="you@example.com" required>
            </div>
            <button type="submit" name="send_test_email" class="btn btn-primary" style="white-space: nowrap; height: 38px;">Send Test Email</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <h4 style="margin: 0 0 10px; color: #333; font-size: 16px; font-weight: 600;">Provider Setup Notes</h4>
    <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 10px;">
        <strong style="color: #92400e;">Mailgun Setup Notes</strong>
        <p style="margin: 8px 0 0; color: #78350f; font-size: 13px;">
            1. <strong>Domain Verification:</strong> Verify your sending domain in Mailgun first<br>
            2. <strong>From Address:</strong> Must use a verified domain (e.g., noreply@mg.yourdomain.com or noreply@yourdomain.com)<br>
            3. <strong>Sandbox Mode:</strong> If using a sandbox domain, you can only send to authorized recipients<br>
            4. <strong>API Alternative:</strong> Mailgun also supports HTTP API if you prefer (requires custom integration)
        </p>
    </div>
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px;">
        <strong style="color: #1e40af;">Microsoft 365 Notes</strong>
        <p style="margin: 8px 0 0; color: #1e3a5f; font-size: 13px;">
            <strong>Graph API (Recommended):</strong> Select "Microsoft 365 (Graph API)" delivery method above. Uses OAuth2 client credentials -- no SMTP passwords, no basic auth. Microsoft is deprecating SMTP basic auth.<br>
            <strong>SMTP (Legacy):</strong> Still available via the SMTP method with smtp.office365.com, but requires an account with SMTP AUTH enabled.
        </p>
    </div>
</div>
</div><!-- end emailSection_settings -->

<!-- ================================================================== -->
<!-- VENDOR TAB -->
<!-- ================================================================== -->
<div id="emailSection_vendor" <?php echo $activeTab !== 'vendor' ? 'style="display: none;"' : ''; ?>>
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Vendor Email Templates</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Templates for emails sent to vendors when requesting security assessments. Use <code>{{placeholder}}</code> syntax for dynamic values.
    </p>

    <?php if (empty($emailTemplates['vendor'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;">No vendor email templates found.</p>
        <p style="font-size: 13px; color: #999;">Run the database migration (setup.php) to create default templates.</p>
    </div>
    <?php else: ?>
        <?php foreach ($emailTemplates['vendor'] as $tpl): ?>
        <?php
            $tplId = (int)$tpl['id'];
            $vars = json_decode($tpl['available_variables'] ?? '[]', true) ?: [];
        ?>
        <div style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleEmailTemplate" data-arg="emailTpl_<?php echo $tplId; ?>"
                 style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="font-weight: 600; color: #374151;"><?php echo e($tpl['display_name']); ?></span>
                    <?php if (!empty($tpl['assessment_name'])): ?>
                    <span style="background: #e0e7ff; color: #3730a3; padding: 1px 6px; border-radius: 8px; font-size: 10px; font-weight: 600; margin-left: 6px;"><?php echo e($tpl['assessment_name']); ?></span>
                    <?php endif; ?>
                </div>
                <span id="arrow_emailTpl_<?php echo $tplId; ?>" style="color: #9ca3af; font-size: 12px;">&#9660;</span>
            </div>
            <div id="emailTpl_<?php echo $tplId; ?>" style="display: none; padding: 16px;">
                <?php if (!empty($vars)): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 12px;">
                    <strong style="color: #3730a3;">Available Variables:</strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label>Subject Line</label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>HTML Body</label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_v_<?php echo $tplId; ?>" data-source="htmlSource_v_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;">Switch to Source Code</button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_v_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;">Preview</button>
                        </div>
                        <div id="htmlEditor_v_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_v_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help">Visual editor for email template. Use the toolbar to format content. Click "Switch to Source Code" to edit raw HTML. Use {{variable}} placeholders for dynamic content.</div>
                    </div>

                    <div class="form-group">
                        <label>Plain Text Body</label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help">Fallback for email clients that don't support HTML.</div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary">Save Template</button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="Reset this template to the factory default? Your customizations will be lost.">Reset to Default</button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;">Send Test To</label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- end emailSection_vendor -->

<!-- ================================================================== -->
<!-- STAKEHOLDER TAB -->
<!-- ================================================================== -->
<div id="emailSection_stakeholder" <?php echo $activeTab !== 'stakeholder' ? 'style="display: none;"' : ''; ?>>
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Stakeholder Email Templates</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Templates for annual review reminder emails sent to stakeholders. These are triggered automatically based on vendor review due dates.
    </p>

    <?php if (empty($emailTemplates['stakeholder'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;">No stakeholder email templates found.</p>
        <p style="font-size: 13px; color: #999;">Run the database migration (setup.php) to create default templates.</p>
    </div>
    <?php else: ?>
        <?php foreach ($emailTemplates['stakeholder'] as $tpl): ?>
        <?php
            $tplId = (int)$tpl['id'];
            $vars = json_decode($tpl['available_variables'] ?? '[]', true) ?: [];
        ?>
        <div style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleEmailTemplate" data-arg="emailTpl_<?php echo $tplId; ?>"
                 style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: #374151;"><?php echo e($tpl['display_name']); ?></span>
                <span id="arrow_emailTpl_<?php echo $tplId; ?>" style="color: #9ca3af; font-size: 12px;">&#9660;</span>
            </div>
            <div id="emailTpl_<?php echo $tplId; ?>" style="display: none; padding: 16px;">
                <?php if (!empty($vars)): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 12px;">
                    <strong style="color: #3730a3;">Available Variables:</strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label>Subject Line</label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>HTML Body</label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_s_<?php echo $tplId; ?>" data-source="htmlSource_s_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;">Switch to Source Code</button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_s_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;">Preview</button>
                        </div>
                        <div id="htmlEditor_s_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_s_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help">Visual editor for email template. Use the toolbar to format content. Click "Switch to Source Code" to edit raw HTML. Use {{variable}} placeholders for dynamic content.</div>
                    </div>

                    <div class="form-group">
                        <label>Plain Text Body</label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help">Fallback for email clients that don't support HTML.</div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary">Save Template</button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="Reset this template to the factory default? Your customizations will be lost.">Reset to Default</button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;">Send Test To</label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- end emailSection_stakeholder -->

<!-- ================================================================== -->
<!-- PROCUREMENT TAB -->
<!-- ================================================================== -->
<div id="emailSection_procurement" <?php echo $activeTab !== 'procurement' ? 'style="display: none;"' : ''; ?>>

<!-- Notification Settings Card -->
<div class="card" style="margin-bottom: 20px;">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Contract Expiry Notification Settings</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Configure automated email notifications for expiring procurement contracts. The cron job checks daily and sends reminders based on these settings.
    </p>

    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="procurement_notifications_enabled" value="1" <?php echo ($procConfig['procurement_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span>Enable Procurement Contract Notifications</span>
            </label>
            <div class="form-help">When enabled, the system will automatically send email reminders for expiring and expired contracts.</div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="procurement_notification_warning_days">Warning Window (Days)</label>
                <input type="number" id="procurement_notification_warning_days" name="procurement_notification_warning_days" class="form-control" min="1" max="365" value="<?php echo e($procConfig['procurement_notification_warning_days'] ?? '30'); ?>">
                <div class="form-help">How many days before expiration to start sending warnings (default: 30)</div>
            </div>
            <div class="form-group">
                <label for="procurement_notification_recipients">Recipient Mode</label>
                <select id="procurement_notification_recipients" name="procurement_notification_recipients" class="form-control" data-action="toggleProcurementUserSelect">
                    <option value="all" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Procurement Members</option>
                    <option value="selected" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'selected' ? 'selected' : ''; ?>>Selected Members Only</option>
                    <option value="none" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'none' ? 'selected' : ''; ?>>None (Cases only, no emails)</option>
                </select>
                <div class="form-help">Who receives contract expiry email notifications</div>
            </div>
        </div>

        <div id="procurementUserSelect" style="<?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'selected' ? '' : 'display: none;'; ?>">
            <div class="form-group">
                <label>Select Recipients</label>
                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px;">
                    <?php if (empty($procurementUsers)): ?>
                    <p style="color: #999; font-size: 13px; margin: 0;">No procurement users found. Users must be in the Procurement, Administrator, or Cyber TPRM group.</p>
                    <?php else: ?>
                    <?php foreach ($procurementUsers as $pu): ?>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; font-size: 13px;">
                        <input type="checkbox" name="procurement_notification_selected_users[]" value="<?php echo $pu['id']; ?>"
                            <?php echo in_array($pu['id'], $procSelectedUsers) ? 'checked' : ''; ?>>
                        <span><?php echo e($pu['full_name']); ?></span>
                        <span style="color: #9ca3af; font-size: 12px;">(<?php echo e($pu['email']); ?>)</span>
                    </label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="form-help">Select which users should receive contract expiry notifications.</div>
            </div>
        </div>

        <button type="submit" name="update_procurement_settings" class="btn btn-primary">Save Notification Settings</button>
    </form>
</div>

<!-- Email Templates Card -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Procurement Email Templates</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Templates for contract expiry notifications and case assignments. Use <code>{{placeholder}}</code> syntax for dynamic values.
    </p>

    <?php if (empty($emailTemplates['procurement'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;">No procurement email templates found.</p>
        <p style="font-size: 13px; color: #999;">Run the database migration (setup.php) to create default templates.</p>
    </div>
    <?php else: ?>
        <?php foreach ($emailTemplates['procurement'] as $tpl): ?>
        <?php
            $tplId = (int)$tpl['id'];
            $vars = json_decode($tpl['available_variables'] ?? '[]', true) ?: [];
        ?>
        <div style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleEmailTemplate" data-arg="emailTpl_<?php echo $tplId; ?>"
                 style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: #374151;"><?php echo e($tpl['display_name']); ?></span>
                <span id="arrow_emailTpl_<?php echo $tplId; ?>" style="color: #9ca3af; font-size: 12px;">&#9660;</span>
            </div>
            <div id="emailTpl_<?php echo $tplId; ?>" style="display: none; padding: 16px;">
                <?php if (!empty($vars)): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 12px;">
                    <strong style="color: #3730a3;">Available Variables:</strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label>Subject Line</label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>HTML Body</label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_p_<?php echo $tplId; ?>" data-source="htmlSource_p_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;">Switch to Source Code</button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_p_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;">Preview</button>
                        </div>
                        <div id="htmlEditor_p_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_p_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help">Visual editor for email template. Use the toolbar to format content. Click "Switch to Source Code" to edit raw HTML. Use {{variable}} placeholders for dynamic content.</div>
                    </div>

                    <div class="form-group">
                        <label>Plain Text Body</label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help">Fallback for email clients that don't support HTML.</div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary">Save Template</button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="Reset this template to the factory default? Your customizations will be lost.">Reset to Default</button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;">Send Test To</label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- end emailSection_procurement -->

<!-- ================================================================== -->
<!-- GRC TAB -->
<!-- ================================================================== -->
<div id="emailSection_grc" <?php echo $activeTab !== 'grc' ? 'style="display: none;"' : ''; ?>>

<!-- Email Templates Card -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">GRC Email Templates</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Templates for GRC task digests and compliance notifications. Use <code>{{placeholder}}</code> syntax for dynamic values.
    </p>

    <?php if (empty($emailTemplates['grc'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;">No GRC email templates found.</p>
        <p style="font-size: 13px; color: #999;">Run the database migration (setup.php) to create default templates.</p>
    </div>
    <?php else: ?>
        <?php foreach ($emailTemplates['grc'] as $tpl): ?>
        <?php
            $tplId = (int)$tpl['id'];
            $vars = json_decode($tpl['available_variables'] ?? '[]', true) ?: [];
        ?>
        <div style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleEmailTemplate" data-arg="emailTpl_<?php echo $tplId; ?>"
                 style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: #374151;"><?php echo e($tpl['display_name']); ?></span>
                <span id="arrow_emailTpl_<?php echo $tplId; ?>" style="color: #9ca3af; font-size: 12px;">&#9660;</span>
            </div>
            <div id="emailTpl_<?php echo $tplId; ?>" style="display: none; padding: 16px;">
                <?php if (!empty($vars)): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 12px;">
                    <strong style="color: #3730a3;">Available Variables:</strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label>Subject Line</label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>HTML Body</label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_g_<?php echo $tplId; ?>" data-source="htmlSource_g_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;">Switch to Source Code</button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_g_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;">Preview</button>
                        </div>
                        <div id="htmlEditor_g_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_g_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help">Visual editor for email template. Use the toolbar to format content. Click "Switch to Source Code" to edit raw HTML. Use {{variable}} placeholders for dynamic content.</div>
                    </div>

                    <div class="form-group">
                        <label>Plain Text Body</label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help">Fallback for email clients that don't support HTML.</div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary">Save Template</button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="Reset this template to the factory default? Your customizations will be lost.">Reset to Default</button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;">Send Test To</label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- end emailSection_grc -->

<!-- ================================================================== -->
<!-- BREACH/CYBER ALERTS TAB -->
<!-- ================================================================== -->
<div id="emailSection_breach_alert" <?php echo $activeTab !== 'breach_alert' ? 'style="display: none;"' : ''; ?>>

<!-- Breach Alert Recipients -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Breach / Cyber Alert Settings</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Configure email recipients and notification settings for breach and cyber threat alerts.
    </p>

    <?php
    $breachEnabled = getAppConfig('breach_alert_enabled', '1');
    $breachRecipients = getAppConfig('breach_alert_recipients', '');
    $breachLastRun = getAppConfig('breach_alert_last_run', '');
    ?>

    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label>Enable Breach/Cyber Alerts</label>
            <select name="breach_alert_enabled" class="form-control" style="max-width:200px;">
                <option value="1" <?php echo $breachEnabled === '1' ? 'selected' : ''; ?>>Enabled</option>
                <option value="0" <?php echo $breachEnabled !== '1' ? 'selected' : ''; ?>>Disabled</option>
            </select>
            <div class="form-help">When enabled, the system will run daily automated breach monitoring and send email alerts.</div>
        </div>

        <div class="form-group">
            <label>Alert Recipients</label>
            <textarea name="breach_alert_recipients" class="form-control" rows="4" placeholder="security@example.com, ciso@example.com" style="font-family: monospace; font-size: 13px;"><?php echo e($breachRecipients); ?></textarea>
            <div class="form-help">Comma-separated list of email addresses to receive breach/cyber alert notifications. Leave blank to disable email notifications.</div>
        </div>

        <?php if ($breachLastRun): ?>
        <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 13px;">
            <strong style="color: #3730a3;">Last Scan:</strong> <span style="color: #4338ca;"><?php echo e($breachLastRun); ?></span>
        </div>
        <?php endif; ?>

        <button type="submit" name="save_breach_alert_settings" class="btn btn-primary">Save Settings</button>
    </form>
</div>

<!-- Breach Alert Email Template -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;">Breach Alert Email Template</h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        Customize the email template used for breach/cyber alert notifications. Use <code>{{placeholder}}</code> syntax for dynamic values.
    </p>

    <?php if (empty($emailTemplates['breach_alert'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;">No breach alert email template found.</p>
        <p style="font-size: 13px; color: #999;">The template will be created automatically when the database migration runs.</p>
    </div>
    <?php else: ?>
        <?php foreach ($emailTemplates['breach_alert'] as $tpl): ?>
        <?php
            $tplId = (int)$tpl['id'];
            $vars = json_decode($tpl['available_variables'] ?? '[]', true) ?: [];
        ?>
        <div style="margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <div data-action="toggleEmailTemplate" data-arg="emailTpl_<?php echo $tplId; ?>"
                 style="cursor: pointer; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; color: #374151;"><?php echo e($tpl['display_name']); ?></span>
                <span id="arrow_emailTpl_<?php echo $tplId; ?>" style="color: #9ca3af; font-size: 12px;">&#9660;</span>
            </div>
            <div id="emailTpl_<?php echo $tplId; ?>" style="display: none; padding: 16px;">
                <?php if (!empty($vars)): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 12px;">
                    <strong style="color: #3730a3;">Available Variables:</strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label>Subject Line</label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>HTML Body</label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_ba_<?php echo $tplId; ?>" data-source="htmlSource_ba_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;">Switch to Source Code</button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_ba_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;">Preview</button>
                        </div>
                        <div id="htmlEditor_ba_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_ba_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help">Visual editor for email template. Use {{variable}} placeholders for dynamic content.</div>
                    </div>

                    <div class="form-group">
                        <label>Plain Text Body</label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help">Fallback for email clients that don't support HTML.</div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary">Save Template</button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="Reset this template to the factory default? Your customizations will be lost.">Reset to Default</button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;">Send Test To</label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;">Send Test Email</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div><!-- end emailSection_breach_alert -->

<script nonce="<?php echo cspNonce(); ?>" src="<?php echo e(baseUrl('app/vendor/tinymce/tinymce.min.js')); ?>"></script>

<!-- Email Preview Modal -->
<div id="emailPreviewModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:10000; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:700px; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 8px 32px rgba(0,0,0,0.3);">
        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:14px; color:#374151;">Email Preview</strong>
            <button type="button" id="emailPreviewClose" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280; padding:0 4px;">&times;</button>
        </div>
        <div style="flex:1; overflow:auto; padding:0;">
            <iframe id="emailPreviewFrame" style="width:100%; height:600px; border:none;"></iframe>
        </div>
    </div>
</div>

<?php
// Get branding colors for TinyMCE visual preview
$emailBranding = EmailService::getEmailBrandingVars();
?>
<script nonce="<?php echo cspNonce(); ?>">
// Branding colors for visual preview in editor
var emailBranding = {
    header_color: <?php echo json_encode($emailBranding['header_color']); ?>,
    button_color: <?php echo json_encode($emailBranding['button_color']); ?>,
    footer_color: <?php echo json_encode($emailBranding['footer_color']); ?>,
    system_title: <?php echo json_encode($emailBranding['system_title']); ?>,
    logo_img: <?php echo json_encode($emailBranding['logo_img']); ?>
};

// Initialize TinyMCE on email template textareas when their parent becomes visible
var tinymceInitialized = {};

// Replace branding placeholders with actual values for visual display
function resolveBrandingPlaceholders(html) {
    // Clean up any mce:protected corruption from previous TinyMCE protect option
    html = html.replace(/&lt;!--mce:protected %7[Bb]%7[Bb](\w+)%7[Dd]%7[Dd]--&gt;/g, '{{$1}}');
    html = html.replace(/<!--mce:protected %7[Bb]%7[Bb](\w+)%7[Dd]%7[Dd]-->/g, '{{$1}}');

    return html
        .replace(/\{\{header_color\}\}/g, emailBranding.header_color)
        .replace(/\{\{button_color\}\}/g, emailBranding.button_color)
        .replace(/\{\{footer_color\}\}/g, emailBranding.footer_color)
        .replace(/\{\{system_title\}\}/g, '<span data-tprm-var="system_title">' + emailBranding.system_title + '</span>')
        .replace(/\{\{logo_img\}\}/g, '<span data-tprm-var="logo_img">' + emailBranding.logo_img + '</span>')
        .replace(/\{\{company_name\}\}/g, '<span data-tprm-var="company_name">' + (emailBranding.company_name || '') + '</span>');
}

// Restore branding placeholders from actual values back to {{tokens}}
function restoreBrandingPlaceholders(html) {
    // Restore wrapped spans first (these are unambiguous)
    html = html.replace(/<span data-tprm-var="system_title">[^<]*<\/span>/g, '{{system_title}}');
    html = html.replace(/<span data-tprm-var="logo_img">.*?<\/span>/g, '{{logo_img}}');
    html = html.replace(/<span data-tprm-var="company_name">[^<]*<\/span>/g, '{{company_name}}');

    // For colors, we need context-aware replacement since header/button may share a value.
    // Replace colors only within style attributes to avoid false positives in text.
    // Strategy: find style="...color..." and replace known hex values back to placeholders
    // based on CSS property context (background-color in header td vs button a).
    // Simpler approach: replace all occurrences - the template engine handles duplicates fine.
    if (emailBranding.footer_color !== emailBranding.header_color && emailBranding.footer_color !== emailBranding.button_color) {
        html = html.replace(new RegExp(emailBranding.footer_color.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '{{footer_color}}');
    }
    if (emailBranding.header_color === emailBranding.button_color) {
        // When they're the same, just use one placeholder - renderWithBranding resolves both
        html = html.replace(new RegExp(emailBranding.header_color.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '{{button_color}}');
    } else {
        html = html.replace(new RegExp(emailBranding.header_color.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '{{header_color}}');
        html = html.replace(new RegExp(emailBranding.button_color.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '{{button_color}}');
    }
    if (emailBranding.footer_color === emailBranding.header_color || emailBranding.footer_color === emailBranding.button_color) {
        // Already handled above
    } else {
        // Already replaced above
    }
    return html;
}

function initTinyMCE(textareaId) {
    if (tinymceInitialized[textareaId]) return;
    var el = document.getElementById(textareaId);
    if (!el) return;

    // Pre-resolve branding placeholders so colors render visually in the editor
    var resolvedHtml = resolveBrandingPlaceholders(el.value);
    el.value = resolvedHtml;

    tinymceInitialized[textareaId] = true;
    tinymce.init({
        selector: '#' + textareaId,
        height: 450,
        menubar: 'edit view insert format table',
        plugins: 'code table link lists fullscreen preview searchreplace',
        toolbar: 'undo redo | styles | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link table | removeformat | code fullscreen',
        content_css: false,
        content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; max-width: 600px; margin: 10px auto; background-color: #f4f4f4; }',
        valid_elements: '*[*]',
        valid_children: '+body[style|table|div|p|a|img|h1|h2|h3|h4|h5|h6|ul|ol|li|br|hr|strong|em|span]',
        extended_valid_elements: 'table[*],tr[*],td[*],th[*],a[*],img[*],div[*],p[*],span[*],h1[*],h2[*],h3[*],ul[*],ol[*],li[*],strong[*],em[*],br,hr',
        entity_encoding: 'raw',
        verify_html: false,
        convert_urls: false,
        relative_urls: false,
        remove_script_host: false,
        forced_root_block: '',
        setup: function(editor) {
            editor.on('change', function() {
                editor.save();
            });
            editor.on('init', function() {
                // Force resolved HTML into editor after init to ensure colors render
                editor.setContent(resolvedHtml);
            });
        }
    });
}

// Initialize TinyMCE when a template accordion is opened
var origToggle = typeof toggleEmailTemplate === 'function' ? toggleEmailTemplate : null;

// Toggle between WYSIWYG and source code view
document.addEventListener('click', function(e) {
    var toggleBtn = e.target.closest('.email-editor-toggle');
    if (toggleBtn) {
        var sourceId = toggleBtn.getAttribute('data-source');
        var editor = tinymce.get(sourceId);
        if (editor) {
            // Switch to source code - remove TinyMCE
            editor.save();
            editor.remove();
            delete tinymceInitialized[sourceId];
            var ta = document.getElementById(sourceId);
            if (ta) {
                // Restore {{placeholder}} tokens for source editing
                ta.value = restoreBrandingPlaceholders(ta.value);
                ta.style.display = '';
                ta.style.fontFamily = "'Courier New', Courier, monospace";
                ta.style.fontSize = '12px';
                ta.style.lineHeight = '1.4';
                ta.rows = 14;
            }
            toggleBtn.textContent = 'Switch to Visual Editor';
        } else {
            // Switch to WYSIWYG
            var ta = document.getElementById(sourceId);
            if (ta) {
                ta.style.fontFamily = '';
                ta.style.fontSize = '';
                ta.style.lineHeight = '';
            }
            initTinyMCE(sourceId);
            toggleBtn.textContent = 'Switch to Source Code';
        }
    }

    // Preview button
    var previewBtn = e.target.closest('.email-preview-btn');
    if (previewBtn) {
        var sourceId = previewBtn.getAttribute('data-source');
        var editor = tinymce.get(sourceId);
        var html = editor ? editor.getContent() : document.getElementById(sourceId).value;
        // Wrap in a proper HTML document if not already a full document
        if (html.indexOf('<html') === -1) {
            html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0; padding:0; font-family: Arial, sans-serif; background-color: #f4f4f4;">' + html + '</body></html>';
        }
        var modal = document.getElementById('emailPreviewModal');
        var frame = document.getElementById('emailPreviewFrame');
        modal.style.display = 'flex';
        frame.setAttribute('srcdoc', html);
    }
});

// Close preview modal on backdrop click or X button
document.getElementById('emailPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.getElementById('emailPreviewClose').addEventListener('click', function() {
    document.getElementById('emailPreviewModal').style.display = 'none';
});

// Sync TinyMCE content before form submit and restore placeholders
document.addEventListener('submit', function(e) {
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
    // Restore branding placeholders in all email template textareas before POST
    document.querySelectorAll('.tinymce-email').forEach(function(ta) {
        ta.value = restoreBrandingPlaceholders(ta.value);
    });
});

// Tab switching (same pattern as Shodan SRS)
function showEmailSection(section) {
    var sections = ['settings', 'vendor', 'stakeholder', 'procurement', 'grc', 'breach_alert'];
    sections.forEach(function(s) {
        var el = document.getElementById('emailSection_' + s);
        var tab = document.getElementById('emailTab_' + s);
        if (el) el.style.display = s === section ? '' : 'none';
        if (tab) {
            tab.style.borderBottomColor = s === section ? 'var(--theme-header-color, #2563eb)' : 'transparent';
            tab.style.color = s === section ? 'var(--theme-header-color, #2563eb)' : '#6b7280';
            tab.style.fontWeight = s === section ? '600' : '500';
        }
    });
}

// Toggle procurement user select based on recipient mode dropdown
function toggleProcurementUserSelect(value) {
    var el = document.getElementById('procurementUserSelect');
    if (el) el.style.display = value === 'selected' ? '' : 'none';
}

// Toggle template editor cards
function toggleEmailTemplate(id) {
    var el = document.getElementById(id);
    var arrow = document.getElementById('arrow_' + id);
    if (!el) return;
    if (el.style.display === 'none') {
        el.style.display = 'block';
        if (arrow) arrow.innerHTML = '&#9650;';
        // Auto-init TinyMCE on the textarea inside this panel
        var ta = el.querySelector('.tinymce-email');
        if (ta && ta.id && typeof tinymce !== 'undefined') {
            setTimeout(function() { initTinyMCE(ta.id); }, 100);
        }
    } else {
        el.style.display = 'none';
        if (arrow) arrow.innerHTML = '&#9660;';
    }
}

// Toggle SMTP vs Graph settings sections based on radio selection
function toggleEmailMethod() {
    var method = document.querySelector('input[name="email_method"]:checked');
    var val = method ? method.value : 'smtp';
    var smtpSection = document.getElementById('smtp_settings_section');
    var graphSection = document.getElementById('graph_settings_section');
    if (smtpSection) smtpSection.style.display = val === 'smtp' ? '' : 'none';
    if (graphSection) graphSection.style.display = val === 'microsoft_graph' ? '' : 'none';
}

// Email provider presets
function fillProviderSettings(provider) {
    var presets = {
        'mailgun': {
            host: 'smtp.mailgun.org',
            port: '587',
            encryption: 'tls',
            info: 'Get your SMTP credentials from Mailgun Dashboard &rarr; Sending &rarr; Domain Settings &rarr; SMTP Credentials'
        },
        'office365': {
            host: 'smtp.office365.com',
            port: '587',
            encryption: 'tls',
            info: 'SMTP mode selected. For OAuth2 (recommended), switch to "Microsoft 365 (Graph API)" delivery method above.',
            suggestGraph: true
        },
        'gmail': {
            host: 'smtp.gmail.com',
            port: '587',
            encryption: 'tls',
            info: 'Use your Gmail address and an App Password (not your regular password). Generate at: https://myaccount.google.com/apppasswords'
        },
        'sendgrid': {
            host: 'smtp.sendgrid.net',
            port: '587',
            encryption: 'tls',
            info: 'Username is "apikey" and password is your SendGrid API key'
        },
        'amazon_ses': {
            host: 'email-smtp.us-east-1.amazonaws.com',
            port: '587',
            encryption: 'tls',
            info: 'Use your SMTP username and password from AWS SES console. Adjust region in hostname if needed.'
        },
        'custom': {
            host: '',
            port: '25',
            encryption: 'none',
            info: 'Configure your custom SMTP server settings manually'
        }
    };

    if (provider && presets[provider]) {
        var preset = presets[provider];
        document.getElementById('smtp_host').value = preset.host;
        document.getElementById('smtp_port').value = preset.port;
        document.getElementById('smtp_encryption').value = preset.encryption;

        var helpText = document.getElementById('smtp_host').parentElement.querySelector('.form-help');
        if (helpText) {
            helpText.innerHTML = '<strong style="color: #0369a1;">' + preset.info + '</strong>';
        }

        // If provider suggests Graph API, offer to switch
        if (preset.suggestGraph) {
            var graphRadio = document.querySelector('input[name="email_method"][value="microsoft_graph"]');
            if (graphRadio && !graphRadio.checked) {
                if (confirm('Microsoft recommends using the Graph API instead of SMTP basic auth. Switch to Microsoft 365 (Graph API) delivery method?')) {
                    graphRadio.checked = true;
                    toggleEmailMethod();
                }
            }
        }
    }
}
</script>
