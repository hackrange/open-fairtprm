<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
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
// POST Handler: WAF quick toggle (convenience for the template editors)
// ============================================================================
// The template editor legitimately authors {{placeholder}} markers and HTML,
// which a strict WAF can flag (HTTP 403 on save). This lets an admin disable and
// re-enable the WAF without leaving the page. Mirrors the canonical controls in
// admin.php?section=lockdown (disable_waf -> engine off, enable_waf -> enforcing).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waf_action'])) {
    require_once __DIR__ . '/../classes/LockdownService.php';
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $wafToggle = new LockdownService($db);
        if (!$wafToggle->isModSecurityAvailable()) {
            $error = t('admin_email.modsecurity_unavailable');
        } elseif ($_POST['waf_action'] === 'disable_waf') {
            if ($wafToggle->setMode('disabled')) {
                $success = t('admin_email.waf_disabled_success');
                $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                    'new' => ['mode' => 'disabled', 'via' => 'email_templates'],
                ]);
            } else {
                $error = t('admin_email.waf_disable_failed');
            }
        } elseif ($_POST['waf_action'] === 'enable_waf') {
            if ($wafToggle->setMode('enforcing')) {
                $success = t('admin_email.waf_enabled_success');
                $auth->audit($user['id'], 'waf_mode_change', 'app_config', null, [
                    'new' => ['mode' => 'enforcing', 'via' => 'email_templates'],
                ]);
            } else {
                $error = t('admin_email.waf_enable_failed');
            }
        }
    }
}

// ============================================================================
// POST Handler: update_email_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email_settings'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
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
            $success = t('admin_email.settings_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating email settings: ' . $e->getMessage());
            $error = t('admin_email.settings_update_failed');
        }
    }
}

// ============================================================================
// POST Handler: send_test_email
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $testRecipient = filter_var(trim($_POST['test_email_recipient'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$testRecipient) {
            $error = t('admin_email.invalid_email');
        } else {
            $encryption = new Encryption();
            $emailService = new EmailService($db, $encryption);
            if (!$emailService->isEnabled()) {
                $error = t('admin_email.disabled_enable_save');
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
        $error = t('admin_email.invalid_request');
    } else {
        $breachEnabled = $_POST['breach_alert_enabled'] === '1' ? '1' : '0';
        $breachRecipients = trim($_POST['breach_alert_recipients'] ?? '');

        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('breach_alert_enabled', :v, 'Enable/disable breach alert monitoring') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $breachEnabled, ':v2' => $breachEnabled]);
        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('breach_alert_recipients', :v, 'Comma-separated email addresses for breach alerts') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $breachRecipients, ':v2' => $breachRecipients]);

        $success = t('admin_email.breach_settings_saved');
    }
}

// ============================================================================
// POST Handler: save_procurement_digest_settings
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_procurement_digest_settings'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $digestEnabled = ($_POST['procurement_digest_enabled'] ?? '0') === '1' ? '1' : '0';
        $digestRecipients = trim($_POST['procurement_digest_recipients'] ?? '');

        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('procurement_digest_enabled', :v, 'Enable/disable the procurement update digest email') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $digestEnabled, ':v2' => $digestEnabled]);
        $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('procurement_digest_recipients', :v, 'Comma-separated email addresses for the procurement update digest') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => $digestRecipients, ':v2' => $digestRecipients]);

        $success = t('admin_email.digest_settings_saved');
    }
}

// ============================================================================
// POST Handler: send_procurement_digest_now
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_procurement_digest_now'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        // Canonical "vendors in review with their latest update" query.
        $digestVendors = $db->fetchAll(
            "SELECT r.id, r.vendor_name, r.status, u.update_text, u.created_at AS update_date
             FROM vendor_onboarding_requests r
             JOIN vendor_procurement_updates u
               ON u.id = (SELECT u2.id FROM vendor_procurement_updates u2 WHERE u2.request_id = r.id ORDER BY u2.created_at DESC, u2.id DESC LIMIT 1)
             WHERE r.status IN ('in_review','ai_review')
             ORDER BY u.created_at DESC"
        );

        if (empty($digestVendors)) {
            $error = t('admin_email.no_vendors_in_review');
        } else {
            $digestRecipientStr = getAppConfig('procurement_digest_recipients', '');
            $digestRecipients = array_filter(array_map('trim', explode(',', $digestRecipientStr)));

            if (empty($digestRecipients)) {
                $error = t('admin_email.no_digest_recipients');
            } else {
                $emailService = new EmailService($db, new Encryption());
                if (!$emailService->isEnabled()) {
                    $error = t('admin_email.email_disabled_settings');
                } else {
                    $sentCount = 0;
                    foreach ($digestRecipients as $digestEmail) {
                        if (!filter_var($digestEmail, FILTER_VALIDATE_EMAIL)) continue;
                        $result = $emailService->sendProcurementDigest($digestVendors, $digestEmail);
                        if ($result['success'] ?? false) {
                            $sentCount++;
                        }
                    }

                    $db->query("INSERT INTO app_config (config_key, config_value, description) VALUES ('procurement_digest_last_run', :v, 'Last time the procurement update digest ran') ON DUPLICATE KEY UPDATE config_value = :v2", [':v' => date('Y-m-d H:i:s'), ':v2' => date('Y-m-d H:i:s')]);

                    if ($sentCount > 0) {
                        $success = t('admin_email.digest_sent_prefix') . $sentCount . t('admin_email.digest_sent_suffix');
                    } else {
                        $error = t('admin_email.digest_send_failed');
                    }
                }
            }
        }
    }
}

// POST Handler: update_email_template
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email_template'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        $emailSubject = trim($_POST['email_subject'] ?? '');
        $emailBodyHtml = $_POST['email_body_html'] ?? '';
        $emailBodyText = $_POST['email_body_text'] ?? '';

        if (!$templateId || empty($emailSubject)) {
            $error = t('admin_email.template_id_subject_required');
        } else {
            try {
                $db->query(
                    "UPDATE email_templates SET email_subject = ?, email_body_html = ?, email_body_text = ?, updated_at = NOW() WHERE id = ?",
                    [$emailSubject, $emailBodyHtml, $emailBodyText, $templateId]
                );
                $auth->audit($user['id'], 'email_template_update', 'email_templates', $templateId, [
                    'new' => ['subject' => $emailSubject]
                ]);
                $success = t('admin_email.template_updated');
            } catch (Exception $e) {
                error_log('Error updating email template: ' . $e->getMessage());
                $error = t('admin_email.template_update_failed');
            }
        }
    }
}

// ============================================================================
// POST Handler: reset_email_template
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_email_template'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        if (!$templateId) {
            $error = t('admin_email.template_id_required');
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
                        $success = t('admin_email.template_reset');
                    } else {
                        $error = t('admin_email.no_default_template');
                    }
                } else {
                    $error = t('admin_email.template_not_found');
                }
            } catch (Exception $e) {
                error_log('Error resetting email template: ' . $e->getMessage());
                $error = t('admin_email.template_reset_failed');
            }
        }
    }
}

// ============================================================================
// POST Handler: send_template_test
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_template_test'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_email.invalid_request');
    } else {
        $templateId = intval($_POST['template_id'] ?? 0);
        $testRecipient = filter_var(trim($_POST['test_recipient'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$templateId || !$testRecipient) {
            $error = t('admin_email.template_id_email_required');
        } else {
            $encryption = new Encryption();
            $emailService = new EmailService($db, $encryption);
            if (!$emailService->isEnabled()) {
                $error = t('admin_email.disabled_enable_settings');
            } else {
                $tpl = $db->fetchOne("SELECT * FROM email_templates WHERE id = ?", [$templateId]);
                if (!$tpl) {
                    $error = t('admin_email.template_not_found');
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
        $error = t('admin_email.invalid_request');
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
            $success = t('admin_email.proc_settings_saved');
            $activeTab = 'procurement';
        } catch (Exception $e) {
            error_log('Error updating procurement settings: ' . $e->getMessage());
            $error = t('admin_email.proc_settings_failed');
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
} elseif (isset($_POST['update_procurement_settings']) || isset($_POST['save_procurement_digest_settings']) || isset($_POST['send_procurement_digest_now'])) {
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
} elseif (isset($_POST['waf_action']) && isset($_POST['email_tab'])) {
    // Stay on the template tab the admin toggled the WAF from.
    $wafTabs = ['vendor', 'stakeholder', 'procurement', 'grc', 'breach_alert'];
    if (in_array($_POST['email_tab'], $wafTabs, true)) {
        $activeTab = $_POST['email_tab'];
    }
}

// ============================================================================
// WAF status + reusable convenience banner for the template editors
// ============================================================================
require_once __DIR__ . '/../classes/LockdownService.php';
$wafService  = new LockdownService($db);
$wafAvailable = false;
$wafModeNow   = 'disabled';
try {
    $wafAvailable = $wafService->isModSecurityAvailable();
    $wafModeNow   = $wafService->getStatus()['mode'] ?? 'disabled';
} catch (Exception $e) {}
$wafActive = ($wafModeNow !== 'disabled');

// Renders the disable/enable WAF helper banner for a given template tab. Returns
// '' when ModSecurity is not installed (nothing to toggle).
$renderWafNotice = function (string $tabKey) use ($wafAvailable, $wafActive, $wafModeNow, $csrfToken) {
    if (!$wafAvailable) {
        return '';
    }
    ob_start();
    ?>
    <div class="card" style="border-left: 4px solid #f59e0b; background: #fffbeb; margin-bottom: 16px;">
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px; justify-content: space-between;">
            <div style="flex: 1; min-width: 260px;">
                <strong style="color: #92400e;"><?php echo e(t('admin_email.waf_notice_title')); ?></strong>
                <p style="margin: 6px 0 0; color: #78350f; font-size: 13px; line-height: 1.5;">
                    <?php echo t('admin_email.waf_notice_body'); ?>
                </p>
                <div style="margin-top: 8px; font-size: 12px; color: #78350f;">
                    <?php echo e(t('admin_email.waf_current_status')); ?>
                    <span style="font-weight: 600; padding: 1px 8px; border-radius: 10px; color: #fff; background: <?php echo $wafActive ? '#16a34a' : '#dc2626'; ?>;">
                        <?php echo $wafActive ? e(t('admin_email.status_enabled')) . ' (' . e($wafModeNow) . ')' : e(t('admin_email.status_disabled')); ?>
                    </span>
                </div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <form method="POST" action="admin.php?section=email" style="display: inline; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="email_tab" value="<?php echo e($tabKey); ?>">
                    <input type="hidden" name="waf_action" value="disable_waf">
                    <button type="submit" class="btn btn-sm" data-confirm="<?php echo e(t('admin_email.confirm_disable_waf')); ?>"
                        style="background: #dc2626; color: #fff; padding: 8px 14px; white-space: nowrap; <?php echo $wafActive ? '' : 'opacity: 0.5; cursor: not-allowed;'; ?>"
                        <?php echo $wafActive ? '' : 'disabled'; ?>><?php echo e(t('admin_email.btn_disable_waf')); ?></button>
                </form>
                <form method="POST" action="admin.php?section=email" style="display: inline; margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="email_tab" value="<?php echo e($tabKey); ?>">
                    <input type="hidden" name="waf_action" value="enable_waf">
                    <button type="submit" class="btn btn-sm"
                        style="background: #16a34a; color: #fff; padding: 8px 14px; white-space: nowrap; <?php echo $wafActive ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>"
                        <?php echo $wafActive ? 'disabled' : ''; ?>><?php echo e(t('admin_email.btn_enable_waf')); ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

// ============================================================================
// HTML
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_email.page_title')); ?></h1>
    <p><?php echo e(t('admin_email.page_desc')); ?></p>
</div>

<!-- Tab Navigation (same pattern as Shodan SRS) -->
<div style="display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; flex-wrap: wrap;">
    <button type="button" data-action="showEmailSection" data-arg="settings" id="emailTab_settings" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'settings' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'settings' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'settings' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_settings')); ?></button>
    <button type="button" data-action="showEmailSection" data-arg="vendor" id="emailTab_vendor" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'vendor' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'vendor' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_vendor')); ?></button>
    <button type="button" data-action="showEmailSection" data-arg="stakeholder" id="emailTab_stakeholder" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'stakeholder' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'stakeholder' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'stakeholder' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_stakeholder')); ?></button>
    <button type="button" data-action="showEmailSection" data-arg="procurement" id="emailTab_procurement" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'procurement' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'procurement' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'procurement' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_procurement')); ?></button>
    <button type="button" data-action="showEmailSection" data-arg="grc" id="emailTab_grc" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'grc' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'grc' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'grc' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_grc')); ?></button>
    <button type="button" data-action="showEmailSection" data-arg="breach_alert" id="emailTab_breach_alert" class="email-section-tab"
        style="padding: 8px 16px; border: none; border-bottom: 2px solid <?php echo $activeTab === 'breach_alert' ? 'var(--theme-header-color, #2563eb)' : 'transparent'; ?>; background: none; cursor: pointer; font-size: 13px; font-weight: <?php echo $activeTab === 'breach_alert' ? '600' : '500'; ?>; color: <?php echo $activeTab === 'breach_alert' ? 'var(--theme-header-color, #2563eb)' : '#6b7280'; ?>; margin-bottom: -2px;"><?php echo e(t('admin_email.tab_breach_alerts')); ?></button>
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
                <span><?php echo e(t('admin_email.enable_notifications')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_email.enable_notifications_help')); ?></div>
        </div>

        <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_email.delivery_method')); ?></h4>

        <div class="form-group">
            <label style="display: inline-flex; align-items: center; gap: 6px; margin-right: 20px; cursor: pointer;">
                <input type="radio" name="email_method" value="smtp" <?php echo ($emailConfig['email_method'] ?? 'smtp') === 'smtp' ? 'checked' : ''; ?> data-action="toggleEmailMethod">
                <span><?php echo e(t('admin_email.method_smtp')); ?></span>
            </label>
            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="radio" name="email_method" value="microsoft_graph" <?php echo ($emailConfig['email_method'] ?? 'smtp') === 'microsoft_graph' ? 'checked' : ''; ?> data-action="toggleEmailMethod">
                <span><?php echo e(t('admin_email.method_graph')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_email.method_help')); ?></div>
        </div>

        <!-- SMTP Settings Section -->
        <div id="smtp_settings_section" style="<?php echo ($emailConfig['email_method'] ?? 'smtp') === 'microsoft_graph' ? 'display:none;' : ''; ?>">
            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_email.smtp_config_heading')); ?></h4>

            <div class="form-group">
                <label for="email_provider"><?php echo e(t('admin_email.label_provider')); ?></label>
                <select id="email_provider" class="form-control" data-action="fillProviderSettings">
                    <option value=""><?php echo e(t('admin_email.opt_select_provider')); ?></option>
                    <option value="mailgun"><?php echo e(t('admin_email.provider_mailgun')); ?></option>
                    <option value="office365"><?php echo e(t('admin_email.provider_office365')); ?></option>
                    <option value="gmail"><?php echo e(t('admin_email.provider_gmail')); ?></option>
                    <option value="sendgrid"><?php echo e(t('admin_email.provider_sendgrid')); ?></option>
                    <option value="amazon_ses"><?php echo e(t('admin_email.provider_amazon_ses')); ?></option>
                    <option value="custom"><?php echo e(t('admin_email.provider_custom')); ?></option>
                </select>
                <div class="form-help"><?php echo e(t('admin_email.provider_help')); ?></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_host"><?php echo e(t('admin_email.label_smtp_host')); ?></label>
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?php echo e($emailConfig['smtp_host'] ?? 'localhost'); ?>" placeholder="smtp.mailgun.org">
                    <div class="form-help"><?php echo e(t('admin_email.help_smtp_host')); ?></div>
                </div>
                <div class="form-group">
                    <label for="smtp_port"><?php echo e(t('admin_email.label_smtp_port')); ?></label>
                    <input type="number" id="smtp_port" name="smtp_port" class="form-control" min="1" max="65535" value="<?php echo e($emailConfig['smtp_port'] ?? '25'); ?>">
                    <div class="form-help"><?php echo e(t('admin_email.help_smtp_port')); ?></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_encryption"><?php echo e(t('admin_email.label_encryption_type')); ?></label>
                    <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                        <option value="none" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'none' ? 'selected' : ''; ?>><?php echo e(t('admin_email.enc_none')); ?></option>
                        <option value="tls" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'tls' ? 'selected' : ''; ?>><?php echo e(t('admin_email.enc_tls')); ?></option>
                        <option value="ssl" <?php echo ($emailConfig['smtp_encryption'] ?? 'none') === 'ssl' ? 'selected' : ''; ?>><?php echo e(t('admin_email.enc_ssl')); ?></option>
                    </select>
                    <div class="form-help"><?php echo e(t('admin_email.help_encryption')); ?></div>
                </div>
                <div class="form-group">
                    <label for="smtp_username"><?php echo e(t('admin_email.label_smtp_username')); ?></label>
                    <input type="text" id="smtp_username" name="smtp_username" class="form-control" value="<?php echo e($emailConfig['smtp_username'] ?? ''); ?>" placeholder="postmaster@mg.yourdomain.com">
                    <div class="form-help"><?php echo e(t('admin_email.help_smtp_username')); ?></div>
                </div>
            </div>

            <div class="form-group">
                <label for="smtp_password"><?php echo e(t('admin_email.label_smtp_password')); ?></label>
                <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="<?php echo e(t('admin_email.placeholder_keep_password')); ?>">
                <div class="form-help">
                    <?php echo t('admin_email.smtp_password_help'); ?>
                </div>
            </div>

            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_email.from_config_heading')); ?></h4>

            <div class="form-row">
                <div class="form-group">
                    <label for="email_from_email"><?php echo e(t('admin_email.label_from_email')); ?></label>
                    <input type="email" id="email_from_email" name="email_from_email" class="form-control" value="<?php echo e($emailConfig['email_from_email'] ?? 'noreply@example.com'); ?>" placeholder="noreply@hackrange.com">
                    <div class="form-help"><?php echo e(t('admin_email.help_from_email')); ?></div>
                </div>
                <div class="form-group">
                    <label for="email_from_name"><?php echo e(t('admin_email.label_from_name')); ?></label>
                    <input type="text" id="email_from_name" name="email_from_name" class="form-control" value="<?php echo e($emailConfig['email_from_name'] ?? 'TPRM System'); ?>" placeholder="OPEN TPRM System">
                    <div class="form-help"><?php echo e(t('admin_email.help_from_name')); ?></div>
                </div>
            </div>
        </div><!-- end smtp_settings_section -->

        <!-- Microsoft 365 Graph API Settings Section -->
        <div id="graph_settings_section" style="<?php echo ($emailConfig['email_method'] ?? 'smtp') !== 'microsoft_graph' ? 'display:none;' : ''; ?>">
            <h4 style="margin: 25px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_email.graph_config_heading')); ?></h4>

            <div class="form-row">
                <div class="form-group">
                    <label for="email_ms_tenant_id"><?php echo e(t('admin_email.label_tenant_id')); ?></label>
                    <input type="text" id="email_ms_tenant_id" name="email_ms_tenant_id" class="form-control" value="<?php echo e($emailConfig['email_ms_tenant_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-help"><?php echo e(t('admin_email.help_tenant_id')); ?></div>
                </div>
                <div class="form-group">
                    <label for="email_ms_client_id"><?php echo e(t('admin_email.label_client_id')); ?></label>
                    <input type="text" id="email_ms_client_id" name="email_ms_client_id" class="form-control" value="<?php echo e($emailConfig['email_ms_client_id'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-help"><?php echo e(t('admin_email.help_client_id')); ?></div>
                </div>
            </div>

            <div class="form-group">
                <label for="email_ms_client_secret"><?php echo e(t('admin_email.label_client_secret')); ?></label>
                <input type="password" id="email_ms_client_secret" name="email_ms_client_secret" class="form-control" placeholder="<?php echo !empty($emailConfig['email_ms_client_secret']) ? e(t('admin_email.placeholder_keep_secret')) : e(t('admin_email.placeholder_enter_secret')); ?>">
                <div class="form-help"><?php echo e(t('admin_email.help_client_secret')); ?></div>
            </div>

            <div class="form-group">
                <label for="email_ms_sender"><?php echo e(t('admin_email.label_sender_email')); ?></label>
                <input type="email" id="email_ms_sender" name="email_ms_sender" class="form-control" value="<?php echo e($emailConfig['email_ms_sender'] ?? ''); ?>" placeholder="noreply@yourdomain.com">
                <div class="form-help"><?php echo e(t('admin_email.help_sender_email')); ?></div>
            </div>

            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px; margin: 15px 0;">
                <strong style="color: #1e40af;"><?php echo e(t('admin_email.azure_setup_heading')); ?></strong>
                <ol style="margin: 8px 0 0; color: #1e3a5f; font-size: 13px; padding-left: 20px; line-height: 1.8;">
                    <li><?php echo t('admin_email.azure_step_1'); ?></li>
                    <li><?php echo t('admin_email.azure_step_2'); ?></li>
                    <li><?php echo t('admin_email.azure_step_3'); ?></li>
                    <li><?php echo t('admin_email.azure_step_4'); ?></li>
                    <li><?php echo t('admin_email.azure_step_5'); ?></li>
                    <li><?php echo t('admin_email.azure_step_6'); ?></li>
                </ol>
            </div>
        </div><!-- end graph_settings_section -->

        <button type="submit" name="update_email_settings" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_settings')); ?></button>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <h4 style="margin: 0 0 15px; color: #333; font-size: 16px; font-weight: 600;"><?php echo e(t('admin_email.send_test_heading')); ?></h4>
    <p style="color: #666; font-size: 13px; margin: 0 0 15px;"><?php echo e(t('admin_email.send_test_desc')); ?></p>
    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group" style="display: flex; gap: 10px; align-items: flex-end;">
            <div style="flex: 1;">
                <label for="test_email_recipient"><?php echo e(t('admin_email.label_recipient_email')); ?></label>
                <input type="email" id="test_email_recipient" name="test_email_recipient" class="form-control" placeholder="you@example.com" required>
            </div>
            <button type="submit" name="send_test_email" class="btn btn-primary" style="white-space: nowrap; height: 38px;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
        </div>
    </form>
</div>

<div class="card" style="margin-top: 20px;">
    <h4 style="margin: 0 0 10px; color: #333; font-size: 16px; font-weight: 600;"><?php echo e(t('admin_email.provider_notes_heading')); ?></h4>
    <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 15px; margin-bottom: 10px;">
        <strong style="color: #92400e;"><?php echo e(t('admin_email.mailgun_notes_heading')); ?></strong>
        <p style="margin: 8px 0 0; color: #78350f; font-size: 13px;">
            <?php echo t('admin_email.mailgun_notes_body'); ?>
        </p>
    </div>
    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 15px;">
        <strong style="color: #1e40af;"><?php echo e(t('admin_email.ms365_notes_heading')); ?></strong>
        <p style="margin: 8px 0 0; color: #1e3a5f; font-size: 13px;">
            <?php echo t('admin_email.ms365_notes_body'); ?>
        </p>
    </div>
</div>
</div><!-- end emailSection_settings -->

<!-- ================================================================== -->
<!-- VENDOR TAB -->
<!-- ================================================================== -->
<div id="emailSection_vendor" <?php echo $activeTab !== 'vendor' ? 'style="display: none;"' : ''; ?>>
<?php echo $renderWafNotice('vendor'); ?>
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.vendor_templates_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo t('admin_email.vendor_templates_desc'); ?>
    </p>

    <?php if (empty($emailTemplates['vendor'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;"><?php echo e(t('admin_email.no_vendor_templates')); ?></p>
        <p style="font-size: 13px; color: #999;"><?php echo e(t('admin_email.run_migration')); ?></p>
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
                    <strong style="color: #3730a3;"><?php echo e(t('admin_email.available_variables')); ?></strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_subject_line')); ?></label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_html_body')); ?></label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_v_<?php echo $tplId; ?>" data-source="htmlSource_v_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;"><?php echo e(t('admin_email.btn_switch_source')); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_v_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;"><?php echo e(t('admin_email.btn_preview')); ?></button>
                        </div>
                        <div id="htmlEditor_v_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_v_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help"><?php echo e(t('admin_email.help_html_editor')); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_plain_text_body')); ?></label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help"><?php echo e(t('admin_email.help_plain_text')); ?></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_template')); ?></button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_email.confirm_reset')); ?>"><?php echo e(t('admin_email.btn_reset_default')); ?></button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;"><?php echo e(t('admin_email.label_send_test_to')); ?></label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
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
<?php echo $renderWafNotice('stakeholder'); ?>
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.stakeholder_templates_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo e(t('admin_email.stakeholder_templates_desc')); ?>
    </p>

    <?php if (empty($emailTemplates['stakeholder'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;"><?php echo e(t('admin_email.no_stakeholder_templates')); ?></p>
        <p style="font-size: 13px; color: #999;"><?php echo e(t('admin_email.run_migration')); ?></p>
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
                    <strong style="color: #3730a3;"><?php echo e(t('admin_email.available_variables')); ?></strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_subject_line')); ?></label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_html_body')); ?></label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_s_<?php echo $tplId; ?>" data-source="htmlSource_s_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;"><?php echo e(t('admin_email.btn_switch_source')); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_s_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;"><?php echo e(t('admin_email.btn_preview')); ?></button>
                        </div>
                        <div id="htmlEditor_s_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_s_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help"><?php echo e(t('admin_email.help_html_editor')); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_plain_text_body')); ?></label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help"><?php echo e(t('admin_email.help_plain_text')); ?></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_template')); ?></button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_email.confirm_reset')); ?>"><?php echo e(t('admin_email.btn_reset_default')); ?></button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;"><?php echo e(t('admin_email.label_send_test_to')); ?></label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
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
<?php echo $renderWafNotice('procurement'); ?>

<!-- Notification Settings Card -->
<div class="card" style="margin-bottom: 20px;">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.contract_expiry_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo e(t('admin_email.contract_expiry_desc')); ?>
    </p>

    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="procurement_notifications_enabled" value="1" <?php echo ($procConfig['procurement_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_email.enable_proc_notifications')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_email.enable_proc_help')); ?></div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="procurement_notification_warning_days"><?php echo e(t('admin_email.label_warning_window')); ?></label>
                <input type="number" id="procurement_notification_warning_days" name="procurement_notification_warning_days" class="form-control" min="1" max="365" value="<?php echo e($procConfig['procurement_notification_warning_days'] ?? '30'); ?>">
                <div class="form-help"><?php echo e(t('admin_email.help_warning_window')); ?></div>
            </div>
            <div class="form-group">
                <label for="procurement_notification_recipients"><?php echo e(t('admin_email.label_recipient_mode')); ?></label>
                <select id="procurement_notification_recipients" name="procurement_notification_recipients" class="form-control" data-action="toggleProcurementUserSelect">
                    <option value="all" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'all' ? 'selected' : ''; ?>><?php echo e(t('admin_email.recipient_all')); ?></option>
                    <option value="selected" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'selected' ? 'selected' : ''; ?>><?php echo e(t('admin_email.recipient_selected')); ?></option>
                    <option value="none" <?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'none' ? 'selected' : ''; ?>><?php echo e(t('admin_email.recipient_none')); ?></option>
                </select>
                <div class="form-help"><?php echo e(t('admin_email.help_recipient_mode')); ?></div>
            </div>
        </div>

        <div id="procurementUserSelect" style="<?php echo ($procConfig['procurement_notification_recipients'] ?? 'all') === 'selected' ? '' : 'display: none;'; ?>">
            <div class="form-group">
                <label><?php echo e(t('admin_email.label_select_recipients')); ?></label>
                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px;">
                    <?php if (empty($procurementUsers)): ?>
                    <p style="color: #999; font-size: 13px; margin: 0;"><?php echo e(t('admin_email.no_procurement_users')); ?></p>
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
                <div class="form-help"><?php echo e(t('admin_email.help_select_recipients')); ?></div>
            </div>
        </div>

        <button type="submit" name="update_procurement_settings" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_notifications')); ?></button>
    </form>
</div>

<!-- Procurement Update Digest Card -->
<div class="card" style="margin-bottom: 20px;">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.digest_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo t('admin_email.digest_desc'); ?>
    </p>

    <?php
    $digestEnabled = getAppConfig('procurement_digest_enabled', '0');
    $digestRecipients = getAppConfig('procurement_digest_recipients', '');
    $digestLastRun = getAppConfig('procurement_digest_last_run', '');
    ?>

    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label><?php echo e(t('admin_email.label_enable_digest')); ?></label>
            <select name="procurement_digest_enabled" class="form-control" style="max-width:200px;">
                <option value="1" <?php echo $digestEnabled === '1' ? 'selected' : ''; ?>><?php echo e(t('admin_email.opt_enabled')); ?></option>
                <option value="0" <?php echo $digestEnabled !== '1' ? 'selected' : ''; ?>><?php echo e(t('admin_email.opt_disabled')); ?></option>
            </select>
            <div class="form-help"><?php echo e(t('admin_email.help_enable_digest')); ?></div>
        </div>

        <div class="form-group">
            <label><?php echo e(t('admin_email.label_digest_recipients')); ?></label>
            <textarea name="procurement_digest_recipients" class="form-control" rows="4" placeholder="procurement@example.com, ciso@example.com" style="font-family: monospace; font-size: 13px;"><?php echo e($digestRecipients); ?></textarea>
            <div class="form-help"><?php echo e(t('admin_email.help_digest_recipients')); ?></div>
        </div>

        <?php if ($digestLastRun): ?>
        <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 13px;">
            <strong style="color: #3730a3;"><?php echo e(t('admin_email.last_run_label')); ?></strong> <span style="color: #4338ca;"><?php echo e($digestLastRun); ?></span>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="submit" name="save_procurement_digest_settings" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_settings_generic')); ?></button>
            <button type="submit" name="send_procurement_digest_now" class="btn btn-secondary"><?php echo e(t('admin_email.btn_send_digest_now')); ?></button>
        </div>
    </form>
</div>

<!-- Email Templates Card -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.procurement_templates_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo t('admin_email.procurement_templates_desc'); ?>
    </p>

    <?php if (empty($emailTemplates['procurement'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;"><?php echo e(t('admin_email.no_procurement_templates')); ?></p>
        <p style="font-size: 13px; color: #999;"><?php echo e(t('admin_email.run_migration')); ?></p>
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
                    <strong style="color: #3730a3;"><?php echo e(t('admin_email.available_variables')); ?></strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_subject_line')); ?></label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_html_body')); ?></label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_p_<?php echo $tplId; ?>" data-source="htmlSource_p_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;"><?php echo e(t('admin_email.btn_switch_source')); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_p_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;"><?php echo e(t('admin_email.btn_preview')); ?></button>
                        </div>
                        <div id="htmlEditor_p_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_p_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help"><?php echo e(t('admin_email.help_html_editor')); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_plain_text_body')); ?></label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help"><?php echo e(t('admin_email.help_plain_text')); ?></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_template')); ?></button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_email.confirm_reset')); ?>"><?php echo e(t('admin_email.btn_reset_default')); ?></button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;"><?php echo e(t('admin_email.label_send_test_to')); ?></label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
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
<?php echo $renderWafNotice('grc'); ?>

<!-- Email Templates Card -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.grc_templates_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo t('admin_email.grc_templates_desc'); ?>
    </p>

    <?php if (empty($emailTemplates['grc'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;"><?php echo e(t('admin_email.no_grc_templates')); ?></p>
        <p style="font-size: 13px; color: #999;"><?php echo e(t('admin_email.run_migration')); ?></p>
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
                    <strong style="color: #3730a3;"><?php echo e(t('admin_email.available_variables')); ?></strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_subject_line')); ?></label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_html_body')); ?></label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_g_<?php echo $tplId; ?>" data-source="htmlSource_g_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;"><?php echo e(t('admin_email.btn_switch_source')); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_g_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;"><?php echo e(t('admin_email.btn_preview')); ?></button>
                        </div>
                        <div id="htmlEditor_g_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_g_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help"><?php echo e(t('admin_email.help_html_editor')); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_plain_text_body')); ?></label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help"><?php echo e(t('admin_email.help_plain_text')); ?></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_template')); ?></button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_email.confirm_reset')); ?>"><?php echo e(t('admin_email.btn_reset_default')); ?></button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;"><?php echo e(t('admin_email.label_send_test_to')); ?></label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
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
<?php echo $renderWafNotice('breach_alert'); ?>

<!-- Breach Alert Recipients -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.breach_settings_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo e(t('admin_email.breach_settings_desc')); ?>
    </p>

    <?php
    $breachEnabled = getAppConfig('breach_alert_enabled', '1');
    $breachRecipients = getAppConfig('breach_alert_recipients', '');
    $breachLastRun = getAppConfig('breach_alert_last_run', '');
    ?>

    <form method="POST" action="admin.php?section=email">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <div class="form-group">
            <label><?php echo e(t('admin_email.label_enable_breach')); ?></label>
            <select name="breach_alert_enabled" class="form-control" style="max-width:200px;">
                <option value="1" <?php echo $breachEnabled === '1' ? 'selected' : ''; ?>><?php echo e(t('admin_email.opt_enabled')); ?></option>
                <option value="0" <?php echo $breachEnabled !== '1' ? 'selected' : ''; ?>><?php echo e(t('admin_email.opt_disabled')); ?></option>
            </select>
            <div class="form-help"><?php echo e(t('admin_email.help_enable_breach')); ?></div>
        </div>

        <div class="form-group">
            <label><?php echo e(t('admin_email.label_alert_recipients')); ?></label>
            <textarea name="breach_alert_recipients" class="form-control" rows="4" placeholder="security@example.com, ciso@example.com" style="font-family: monospace; font-size: 13px;"><?php echo e($breachRecipients); ?></textarea>
            <div class="form-help"><?php echo e(t('admin_email.help_alert_recipients')); ?></div>
        </div>

        <?php if ($breachLastRun): ?>
        <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: 10px 14px; margin-bottom: 15px; font-size: 13px;">
            <strong style="color: #3730a3;"><?php echo e(t('admin_email.last_scan_label')); ?></strong> <span style="color: #4338ca;"><?php echo e($breachLastRun); ?></span>
        </div>
        <?php endif; ?>

        <button type="submit" name="save_breach_alert_settings" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_breach')); ?></button>
    </form>
</div>

<!-- Breach Alert Email Template -->
<div class="card">
    <h3 style="color: var(--theme-header-color); margin: 0 0 10px; font-size: 16px;"><?php echo e(t('admin_email.breach_template_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;">
        <?php echo t('admin_email.breach_template_desc'); ?>
    </p>

    <?php if (empty($emailTemplates['breach_alert'])): ?>
    <div style="text-align: center; padding: 40px 20px; color: #666;">
        <p style="font-size: 14px;"><?php echo e(t('admin_email.no_breach_template')); ?></p>
        <p style="font-size: 13px; color: #999;"><?php echo e(t('admin_email.breach_template_migration')); ?></p>
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
                    <strong style="color: #3730a3;"><?php echo e(t('admin_email.available_variables')); ?></strong>
                    <?php foreach ($vars as $v): ?>
                    <code style="background: #e0e7ff; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">{{<?php echo e($v); ?>}}</code>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="admin.php?section=email">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_subject_line')); ?></label>
                        <input type="text" name="email_subject" class="form-control" value="<?php echo e($tpl['email_subject']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_html_body')); ?></label>
                        <div style="margin-bottom: 6px;">
                            <button type="button" class="btn btn-sm btn-secondary email-editor-toggle" data-target="htmlEditor_ba_<?php echo $tplId; ?>" data-source="htmlSource_ba_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px;"><?php echo e(t('admin_email.btn_switch_source')); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary email-preview-btn" data-source="htmlSource_ba_<?php echo $tplId; ?>" style="font-size: 11px; padding: 3px 10px; margin-left: 4px;"><?php echo e(t('admin_email.btn_preview')); ?></button>
                        </div>
                        <div id="htmlEditor_ba_<?php echo $tplId; ?>">
                            <textarea name="email_body_html" class="form-control tinymce-email" id="htmlSource_ba_<?php echo $tplId; ?>" rows="14"><?php echo e($tpl['email_body_html']); ?></textarea>
                        </div>
                        <div class="form-help"><?php echo e(t('admin_email.help_html_editor_short')); ?></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo e(t('admin_email.label_plain_text_body')); ?></label>
                        <textarea name="email_body_text" class="form-control" rows="8" style="font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4;"><?php echo e($tpl['email_body_text']); ?></textarea>
                        <div class="form-help"><?php echo e(t('admin_email.help_plain_text')); ?></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" name="update_email_template" class="btn btn-primary"><?php echo e(t('admin_email.btn_save_template')); ?></button>
                        <button type="submit" name="reset_email_template" class="btn btn-secondary" data-confirm="<?php echo e(t('admin_email.confirm_reset')); ?>"><?php echo e(t('admin_email.btn_reset_default')); ?></button>
                    </div>
                </form>
                <form method="POST" action="admin.php?section=email" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="template_id" value="<?php echo $tplId; ?>">
                    <div style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 1; max-width: 300px;">
                            <label style="font-size: 12px; color: #666; margin-bottom: 4px; display: block;"><?php echo e(t('admin_email.label_send_test_to')); ?></label>
                            <input type="email" name="test_recipient" class="form-control" placeholder="you@example.com" value="<?php echo e($user['email'] ?? ''); ?>" required style="padding: 7px 10px; font-size: 13px;">
                        </div>
                        <button type="submit" name="send_template_test" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 8px 16px; white-space: nowrap;"><?php echo e(t('admin_email.btn_send_test')); ?></button>
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
            <strong style="font-size:14px; color:#374151;"><?php echo e(t('admin_email.email_preview')); ?></strong>
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
            toggleBtn.textContent = <?php echo json_encode(t('admin_email.btn_switch_visual')); ?>;
        } else {
            // Switch to WYSIWYG
            var ta = document.getElementById(sourceId);
            if (ta) {
                ta.style.fontFamily = '';
                ta.style.fontSize = '';
                ta.style.lineHeight = '';
            }
            initTinyMCE(sourceId);
            toggleBtn.textContent = <?php echo json_encode(t('admin_email.btn_switch_source')); ?>;
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
            info: <?php echo json_encode(t('admin_email.provider_info_mailgun')); ?>
        },
        'office365': {
            host: 'smtp.office365.com',
            port: '587',
            encryption: 'tls',
            info: <?php echo json_encode(t('admin_email.provider_info_office365')); ?>,
            suggestGraph: true
        },
        'gmail': {
            host: 'smtp.gmail.com',
            port: '587',
            encryption: 'tls',
            info: <?php echo json_encode(t('admin_email.provider_info_gmail')); ?>
        },
        'sendgrid': {
            host: 'smtp.sendgrid.net',
            port: '587',
            encryption: 'tls',
            info: <?php echo json_encode(t('admin_email.provider_info_sendgrid')); ?>
        },
        'amazon_ses': {
            host: 'email-smtp.us-east-1.amazonaws.com',
            port: '587',
            encryption: 'tls',
            info: <?php echo json_encode(t('admin_email.provider_info_amazon_ses')); ?>
        },
        'custom': {
            host: '',
            port: '25',
            encryption: 'none',
            info: <?php echo json_encode(t('admin_email.provider_info_custom')); ?>
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
                if (confirm(<?php echo json_encode(t('admin_email.confirm_switch_graph')); ?>)) {
                    graphRadio.checked = true;
                    toggleEmailMethod();
                }
            }
        }
    }
}
</script>
