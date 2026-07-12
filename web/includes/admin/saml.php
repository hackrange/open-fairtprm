<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: SAML 2.0 Configuration & Group Mappings
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * SAML SSO setup and group-to-ACL mapping management. The top section handles
 * IdP/SP configuration (entity IDs, SSO URLs, certificates). The bottom section
 * manages SAML group mappings -- mapping IdP group claims (like Active Directory
 * groups) to local ACL roles so users get the right permissions on login.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// Config-file override for local login. When auth.local_login_enabled is set in
// config.php it WINS over the app_config value (a no-SQL break-glass control), so
// the toggle below is shown read-only and never written while the override is in
// effect. null = the key is absent from config.php, so the database toggle is
// authoritative and editable here. Mirrors the precedence logic in login.php.
$localLoginConfigOverride = Config::getInstance()->get('auth.local_login_enabled', null);
$localLoginOverridden = ($localLoginConfigOverride !== null);

// ============================================================================
// POST Handler: update_saml (IdP/SP configuration)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_saml'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_saml.invalid_request');
    } else {
        try {
            $db->beginTransaction();

            // Validate URL fields accept only http/https schemes
            $validateUrl = function($url) {
                $url = trim($url);
                return (!empty($url) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) ? $url : '';
            };

            $samlConfig = [
                'is_enabled' => isset($_POST['saml_enabled']) ? 1 : 0,
                'idp_entity_id' => trim($_POST['idp_entity_id'] ?? ''),
                'idp_sso_url' => $validateUrl($_POST['idp_sso_url'] ?? ''),
                'idp_slo_url' => $validateUrl($_POST['idp_slo_url'] ?? ''),
                'idp_certificate' => $this_idp_cert = (function($raw) {
                    $raw = trim($raw);
                    if (empty($raw)) return '';
                    // Strip PEM headers/footers and any stray dashes, then re-wrap cleanly
                    $base64 = preg_replace('/-----[A-Z ]+-----/', '', $raw);
                    $base64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $base64);
                    if (empty($base64)) return '';
                    return "-----BEGIN CERTIFICATE-----\n"
                         . chunk_split($base64, 64, "\n")
                         . "-----END CERTIFICATE-----";
                })($_POST['idp_certificate'] ?? ''),
                'sp_entity_id' => trim($_POST['sp_entity_id'] ?? ''),
                'sp_acs_url' => $validateUrl($_POST['sp_acs_url'] ?? ''),
                'sp_slo_url' => $validateUrl($_POST['sp_slo_url'] ?? ''),
                'group_attribute' => trim($_POST['group_attribute'] ?? 'groups'),
                'auto_activate' => isset($_POST['auto_activate']) ? 1 : 0
            ];

            // Sanitize group_attribute: only allow safe characters
            if (!preg_match('/^[a-zA-Z0-9_:.\-\/]{1,255}$/', $samlConfig['group_attribute'])) {
                $samlConfig['group_attribute'] = 'groups';
            }

            $existing = $db->fetchOne('SELECT id FROM saml_config LIMIT 1');

            if ($existing) {
                $db->update('saml_config', $samlConfig, 'id = :id', [':id' => $existing['id']]);
            } else {
                $db->insert('saml_config', $samlConfig);
            }

            // Persist the local-login toggle to app_config — but only when config.php
            // is NOT overriding it. A disabled checkbox submits no value, so writing it
            // while overridden would clobber the stored DB value with '0' on every save.
            $localLoginSaved = null;
            if (!$localLoginOverridden) {
                $localLoginSaved = isset($_POST['local_login_enabled']) ? '1' : '0';
                $existingLL = $db->fetchOne("SELECT id FROM app_config WHERE config_key = 'local_login_enabled'");
                if ($existingLL) {
                    $db->update('app_config', ['config_value' => $localLoginSaved], 'config_key = :key', [':key' => 'local_login_enabled']);
                } else {
                    $db->insert('app_config', ['config_key' => 'local_login_enabled', 'config_value' => $localLoginSaved]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update_saml', 'saml_config', null, [
                'new' => array_merge(
                    ['is_enabled' => $samlConfig['is_enabled'], 'idp_entity_id' => $samlConfig['idp_entity_id'], 'sp_entity_id' => $samlConfig['sp_entity_id']],
                    $localLoginSaved !== null ? ['local_login_enabled' => $localLoginSaved] : []
                )
            ]);
            $success = t('admin_saml.config_updated');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating SAML config: ' . $e->getMessage());
            $error = t('admin_saml.config_update_failed');
        }
    }
}

// ============================================================================
// POST Handler: add_group_mapping
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group_mapping'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_saml.invalid_request');
    } else {
        $samlGroupName = trim($_POST['saml_group_name'] ?? '');
        $aclGroupId = intval($_POST['acl_group_id'] ?? 0);
        $autoAssign = isset($_POST['auto_assign']) ? 1 : 0;

        if (empty($samlGroupName)) {
            $error = t('admin_saml.group_name_required');
        } elseif ($aclGroupId <= 0) {
            $error = t('admin_saml.select_acl_group');
        } else {
            try {
                // Check for duplicate
                $existing = $db->fetchOne(
                    'SELECT id FROM saml_group_mappings WHERE saml_group_name = :name',
                    [':name' => $samlGroupName]
                );
                if ($existing) {
                    $error = t('admin_saml.mapping_exists_prefix') . e($samlGroupName) . t('admin_saml.mapping_exists_suffix');
                } else {
                    $db->insert('saml_group_mappings', [
                        'saml_group_name' => $samlGroupName,
                        'acl_group_id' => $aclGroupId,
                        'auto_assign' => $autoAssign
                    ]);
                    $auth->audit($user['id'], 'saml_group_mapping_add', 'saml_group_mappings', null, [
                        'new' => ['saml_group_name' => $samlGroupName, 'acl_group_id' => $aclGroupId]
                    ]);
                    $success = t('admin_saml.mapping_added');
                }
            } catch (Exception $e) {
                error_log('Error adding SAML group mapping: ' . $e->getMessage());
                $error = t('admin_saml.mapping_add_failed');
            }
        }
    }
}

// ============================================================================
// POST Handler: delete_group_mapping
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group_mapping'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_saml.invalid_request');
    } else {
        $mappingId = intval($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            try {
                $mapping = $db->fetchOne('SELECT saml_group_name FROM saml_group_mappings WHERE id = :id', [':id' => $mappingId]);
                $db->delete('saml_group_mappings', 'id = :id', [':id' => $mappingId]);
                $auth->audit($user['id'], 'saml_group_mapping_delete', 'saml_group_mappings', $mappingId, [
                    'old' => $mapping
                ]);
                $success = t('admin_saml.mapping_deleted');
            } catch (Exception $e) {
                error_log('Error deleting SAML group mapping: ' . $e->getMessage());
                $error = t('admin_saml.mapping_delete_failed');
            }
        }
    }
}

// ============================================================================
// POST Handler: generate_scim_token
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_scim_token'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_saml.invalid_request');
    } else {
        try {
            // Generate a cryptographically secure token
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plainToken);

            // Store the hash in app_config
            $existing = $db->fetchOne("SELECT id FROM app_config WHERE config_key = 'scim_bearer_token_hash'");
            if ($existing) {
                $db->update('app_config', ['config_value' => $tokenHash], "config_key = 'scim_bearer_token_hash'");
            } else {
                $db->insert('app_config', [
                    'config_key' => 'scim_bearer_token_hash',
                    'config_value' => $tokenHash,
                    'is_encrypted' => 0
                ]);
            }

            $auth->audit($user['id'], 'scim_token_generated', 'app_config', null, [
                'new' => ['action' => 'token_generated']
            ]);

            // Store the plain token in session so we can display it once
            $session->set('scim_new_token', $plainToken);
            $success = t('admin_saml.scim_token_generated');
        } catch (Exception $e) {
            error_log('Error generating SCIM token: ' . $e->getMessage());
            $error = t('admin_saml.scim_token_generate_failed');
        }
    }
}

// ============================================================================
// POST Handler: revoke_scim_token
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_scim_token'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_saml.invalid_request');
    } else {
        try {
            $db->delete('app_config', "config_key = 'scim_bearer_token_hash'");
            $auth->audit($user['id'], 'scim_token_revoked', 'app_config', null, [
                'new' => ['action' => 'token_revoked']
            ]);
            $success = t('admin_saml.scim_token_revoked');
        } catch (Exception $e) {
            error_log('Error revoking SCIM token: ' . $e->getMessage());
            $error = t('admin_saml.scim_token_revoke_failed');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$samlConfig = $db->fetchOne('SELECT * FROM saml_config LIMIT 1');
if (!$samlConfig) {
    $samlConfig = [
        'is_enabled' => 0, 'idp_entity_id' => '', 'idp_sso_url' => '', 'idp_slo_url' => '',
        'idp_certificate' => '', 'sp_entity_id' => '', 'sp_acs_url' => '', 'sp_slo_url' => '',
        'group_attribute' => 'groups', 'auto_activate' => 1
    ];
}

// Local-login toggle: current database value (default enabled), and the effective
// state shown in the UI (the config.php override wins when present).
$localLoginDbEnabled = true;
$rowLL = $db->fetchOne("SELECT config_value FROM app_config WHERE config_key = 'local_login_enabled'");
if ($rowLL && isset($rowLL['config_value'])) {
    $localLoginDbEnabled = ((string)$rowLL['config_value'] !== '0');
}
$localLoginEffective = $localLoginOverridden ? (bool)$localLoginConfigOverride : $localLoginDbEnabled;

// Compute base URL for auto-filling SP values
$appBaseUrl = rtrim(baseUrl(), '/');

// Check if SCIM token is configured
$scimTokenExists = (bool)$db->fetchOne("SELECT id FROM app_config WHERE config_key = 'scim_bearer_token_hash'");
// Guard against admin.php's double-include pattern: the first pass runs POST
// handlers then discards HTML; the second pass renders. If we read+remove the
// session flash in pass 1, it's gone by pass 2. So only read it if we haven't already.
if (empty($scimNewToken)) {
    $scimNewToken = $session->get('scim_new_token');
    if ($scimNewToken) {
        $session->remove('scim_new_token');
    }
}

$allGroups = $acl->getAllGroups();
$groupMappings = $db->fetchAll(
    'SELECT sgm.id, sgm.saml_group_name, sgm.acl_group_id, sgm.auto_assign, sgm.created_at,
            ag.group_name, ag.display_name
     FROM saml_group_mappings sgm
     JOIN acl_groups ag ON ag.id = sgm.acl_group_id
     ORDER BY sgm.saml_group_name'
);

// ============================================================================
// HTML: SAML Configuration Form
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_saml.page_title')); ?></h1>
    <p><?php echo e(t('admin_saml.page_subtitle')); ?></p>
</div>

<!-- SAML IdP/SP Configuration -->
<div class="card">
    <h3 style="margin: 0 0 20px; font-size: 16px; font-weight: 600; color: #333;"><?php echo e(t('admin_saml.connection_settings_heading')); ?></h3>
    <form method="POST" action="admin.php?section=saml">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="saml_enabled" value="1" <?php echo $samlConfig['is_enabled'] ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_saml.enable_saml')); ?></span>
            </label>
        </div>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="local_login_enabled" value="1" <?php echo $localLoginEffective ? 'checked' : ''; ?> <?php echo $localLoginOverridden ? 'disabled' : ''; ?>>
                <span><?php echo e(t('admin_saml.allow_local_login')); ?></span>
            </label>
            <small style="color: #666; margin-top: 4px; display: block;">
                <?php echo e(t('admin_saml.local_login_help')); ?>
                <?php if ($localLoginOverridden): ?>
                    <br><strong><?php echo t('admin_saml.local_login_config_controlled'); ?></strong> (<code>auth.local_login_enabled</code> = <?php echo $localLoginConfigOverride ? 'true' : 'false'; ?>); <?php echo e(t('admin_saml.local_login_remove_line')); ?>
                <?php endif; ?>
            </small>
        </div>
        <h4 style="margin: 20px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_saml.idp_heading')); ?></h4>
        <div class="form-group">
            <label for="idp_entity_id"><?php echo e(t('admin_saml.idp_entity_id_label')); ?></label>
            <input type="text" id="idp_entity_id" name="idp_entity_id" class="form-control" value="<?php echo e($samlConfig['idp_entity_id']); ?>" placeholder="http://www.okta.com/YOUR_IDP_ENTITY_ID">
        </div>
        <div class="form-group">
            <label for="idp_sso_url"><?php echo e(t('admin_saml.idp_sso_url_label')); ?></label>
            <input type="text" id="idp_sso_url" name="idp_sso_url" class="form-control" value="<?php echo e($samlConfig['idp_sso_url']); ?>" placeholder="https://YOUR_OKTA_DOMAIN.okta.com/app/YOUR_APP_ID/sso/saml">
        </div>
        <div class="form-group">
            <label for="idp_slo_url"><?php echo e(t('admin_saml.idp_slo_url_label')); ?></label>
            <input type="text" id="idp_slo_url" name="idp_slo_url" class="form-control" value="<?php echo e($samlConfig['idp_slo_url']); ?>">
        </div>
        <div class="form-group">
            <label for="idp_certificate"><?php echo e(t('admin_saml.idp_certificate_label')); ?></label>
            <textarea id="idp_certificate" name="idp_certificate" class="form-control" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?php echo e($samlConfig['idp_certificate']); ?></textarea>
        </div>
        <h4 style="margin: 20px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_saml.sp_heading')); ?></h4>
        <div class="form-group">
            <label for="sp_entity_id"><?php echo e(t('admin_saml.sp_entity_id_label')); ?></label>
            <input type="text" id="sp_entity_id" name="sp_entity_id" class="form-control" value="<?php echo e($samlConfig['sp_entity_id'] ?: $appBaseUrl . '/saml/metadata'); ?>">
        </div>
        <div class="form-group">
            <label for="sp_acs_url"><?php echo e(t('admin_saml.sp_acs_url_label')); ?></label>
            <input type="text" id="sp_acs_url" name="sp_acs_url" class="form-control" value="<?php echo e($samlConfig['sp_acs_url'] ?: $appBaseUrl . '/saml/acs'); ?>">
        </div>
        <div class="form-group">
            <label for="sp_slo_url"><?php echo e(t('admin_saml.sp_slo_url_label')); ?></label>
            <input type="text" id="sp_slo_url" name="sp_slo_url" class="form-control" value="<?php echo e($samlConfig['sp_slo_url'] ?: $appBaseUrl . '/saml/sls'); ?>">
        </div>
        <h4 style="margin: 20px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_saml.user_provisioning_heading')); ?></h4>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="auto_activate" value="1" <?php echo ($samlConfig['auto_activate'] ?? 1) ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_saml.auto_activate_label')); ?></span>
            </label>
            <small style="color: #666; margin-top: 4px; display: block;"><?php echo e(t('admin_saml.auto_activate_help')); ?></small>
        </div>
        <h4 style="margin: 20px 0 15px; color: #666; font-size: 14px; font-weight: 600;"><?php echo e(t('admin_saml.group_attribute_heading')); ?></h4>
        <div class="form-group">
            <label for="group_attribute"><?php echo e(t('admin_saml.group_attribute_label')); ?></label>
            <input type="text" id="group_attribute" name="group_attribute" class="form-control" value="<?php echo e($samlConfig['group_attribute'] ?? 'groups'); ?>" placeholder="groups">
            <small style="color: #666; margin-top: 4px; display: block;"><?php echo t('admin_saml.group_attribute_help'); ?></small>
        </div>
        <button type="submit" name="update_saml" class="btn btn-primary"><?php echo e(t('admin_saml.save_config_button')); ?></button>
    </form>
</div>

<!-- IdP Configuration Reference: values to enter in your IdP -->
<?php
$refSpEntityId = $appBaseUrl . '/saml/metadata';
$refSpAcsUrl = $appBaseUrl . '/saml/acs';
$refSpSloUrl = $appBaseUrl . '/saml/sls';
$refMetadataUrl = $appBaseUrl . '/saml/metadata';
$refScimBaseUrl = $appBaseUrl . '/scim/v2';
?>
<div class="card" style="margin-top: 20px; background: #f0fdf4; border: 1px solid #bbf7d0;">
    <h3 style="margin: 0 0 5px; font-size: 16px; font-weight: 600; color: #14532d;"><?php echo e(t('admin_saml.idp_config_reference_heading')); ?></h3>
    <p style="color: #166534; font-size: 13px; margin: 0 0 20px;"><?php echo e(t('admin_saml.idp_config_reference_subtitle')); ?></p>

    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; width: 220px; vertical-align: top;"><?php echo e(t('admin_saml.sp_entity_id_label')); ?><br><span style="font-weight: 400; font-size: 11px; color: #166534;"><?php echo e(t('admin_saml.audience_uri_sublabel')); ?></span></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-entity-id" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;"><?php echo e($refSpEntityId); ?></code>
                    <button type="button" data-copy-target="ref-entity-id" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.acs_url_label')); ?><br><span style="font-weight: 400; font-size: 11px; color: #166534;"><?php echo e(t('admin_saml.acs_url_sublabel')); ?></span></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-acs-url" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;"><?php echo e($refSpAcsUrl); ?></code>
                    <button type="button" data-copy-target="ref-acs-url" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.sls_url_label')); ?><br><span style="font-weight: 400; font-size: 11px; color: #166534;"><?php echo e(t('admin_saml.sls_url_sublabel')); ?></span></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-slo-url" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;"><?php echo e($refSpSloUrl); ?></code>
                    <button type="button" data-copy-target="ref-slo-url" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.sp_metadata_url_label')); ?></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-metadata-url" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;"><?php echo e($refMetadataUrl); ?></code>
                    <button type="button" data-copy-target="ref-metadata-url" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.nameid_format_label')); ?></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-nameid" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;">urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</code>
                    <button type="button" data-copy-target="ref-nameid" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.authncontext_class_label')); ?></td>
            <td style="padding: 10px 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <code id="ref-authnctx" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;">urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</code>
                    <button type="button" data-copy-target="ref-authnctx" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                </div>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.signature_algorithm_label')); ?></td>
            <td style="padding: 10px 12px;">
                <code style="background: #dcfce7; padding: 4px 8px; border-radius: 4px;">RSA-SHA256</code>
            </td>
        </tr>
        <tr style="border-bottom: 1px solid #bbf7d0;">
            <td style="padding: 10px 12px; font-weight: 600; color: #14532d; vertical-align: top;"><?php echo e(t('admin_saml.binding_label')); ?></td>
            <td style="padding: 10px 12px;">
                <code style="background: #dcfce7; padding: 4px 8px; border-radius: 4px;">HTTP-POST</code>
                <span style="color: #166534; font-size: 12px; margin-left: 6px;"><?php echo e(t('admin_saml.binding_for_acs_note')); ?></span>
            </td>
        </tr>
    </table>

    <!-- SCIM Provisioning -->
    <div style="border-top: 2px solid #bbf7d0; margin-top: 20px; padding-top: 20px;">
        <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #14532d;"><?php echo e(t('admin_saml.scim_provisioning_heading')); ?></h4>
        <p style="color: #166534; font-size: 12px; margin: 0 0 15px;"><?php echo e(t('admin_saml.scim_provisioning_subtitle')); ?></p>

        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <tr style="border-bottom: 1px solid #bbf7d0;">
                <td style="padding: 10px 12px; font-weight: 600; color: #14532d; width: 220px; vertical-align: top;"><?php echo e(t('admin_saml.scim_base_url_label')); ?><br><span style="font-weight: 400; font-size: 11px; color: #166534;"><?php echo e(t('admin_saml.tenant_url_sublabel')); ?></span></td>
                <td style="padding: 10px 12px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <code id="ref-scim-url" style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; word-break: break-all; flex: 1;"><?php echo e($refScimBaseUrl); ?></code>
                        <button type="button" data-copy-target="ref-scim-url" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                    </div>
                </td>
            </tr>
            <tr style="border-bottom: 1px solid #bbf7d0;">
                <td style="padding: 10px 12px; font-weight: 600; color: #14532d; width: 220px; vertical-align: top;"><?php echo e(t('admin_saml.unique_identifier_label')); ?></td>
                <td style="padding: 10px 12px;">
                    <code style="background: #dcfce7; padding: 4px 8px; border-radius: 4px;">userName</code>
                </td>
            </tr>
            <tr style="border-bottom: 1px solid #bbf7d0;">
                <td style="padding: 10px 12px; font-weight: 600; color: #14532d; width: 220px; vertical-align: top;"><?php echo e(t('admin_saml.authentication_mode_label')); ?></td>
                <td style="padding: 10px 12px;">
                    <code style="background: #dcfce7; padding: 4px 8px; border-radius: 4px;">OAuth Bearer Token</code>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 12px; font-weight: 600; color: #14532d; width: 220px; vertical-align: top;"><?php echo e(t('admin_saml.scim_bearer_token_label')); ?></td>
                <td style="padding: 10px 12px;">
                    <?php if ($scimNewToken): ?>
                        <!-- Show newly generated token (one time only) -->
                        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 12px; margin-bottom: 10px;">
                            <div style="font-size: 12px; font-weight: 600; color: #92400e; margin-bottom: 6px;"><?php echo e(t('admin_saml.new_token_generated_note')); ?></div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <code id="ref-scim-token" style="background: #fff; padding: 6px 10px; border-radius: 4px; word-break: break-all; flex: 1; font-size: 13px; border: 1px solid #fbbf24;"><?php echo e($scimNewToken); ?></code>
                                <button type="button" data-copy-target="ref-scim-token" class="btn-copy" title="<?php echo e(t('admin_saml.copy')); ?>"><?php echo e(t('admin_saml.copy')); ?></button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($scimTokenExists): ?>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="color: #059669; font-size: 13px; font-weight: 500;"><?php echo e(t('admin_saml.token_configured')); ?></span>
                            <form method="POST" action="admin.php?section=saml" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <button type="submit" name="generate_scim_token" class="btn btn-sm" style="background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; padding: 4px 12px; font-size: 12px; border-radius: 4px; cursor: pointer;"><?php echo e(t('admin_saml.regenerate_button')); ?></button>
                            </form>
                            <form method="POST" action="admin.php?section=saml" style="display: inline;" data-confirm="<?php echo e(t('admin_saml.revoke_confirm')); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <button type="submit" name="revoke_scim_token" class="btn btn-sm" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 4px 12px; font-size: 12px; border-radius: 4px; cursor: pointer;"><?php echo e(t('admin_saml.revoke_button')); ?></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="color: #9ca3af; font-size: 13px;"><?php echo e(t('admin_saml.no_token_configured')); ?></span>
                            <form method="POST" action="admin.php?section=saml" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                <button type="submit" name="generate_scim_token" class="btn btn-sm" style="background: #dcfce7; color: #14532d; border: 1px solid #86efac; padding: 4px 12px; font-size: 12px; border-radius: 4px; cursor: pointer;"><?php echo e(t('admin_saml.generate_token_button')); ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<style>
.btn-copy {
    background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; padding: 3px 10px;
    font-size: 12px; border-radius: 4px; cursor: pointer; white-space: nowrap;
    transition: all 0.2s;
}
.btn-copy:hover { background: #a7f3d0; }
</style>
<script nonce="<?php echo cspNonce(); ?>">
// Copy-to-clipboard via data attributes (inline onclick blocked by CSP nonce)
document.querySelectorAll('[data-copy-target]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var el = document.getElementById(this.getAttribute('data-copy-target'));
        if (!el) return;
        var text = (el.textContent || el.innerText || '').trim();
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        document.execCommand('copy');
        document.body.removeChild(ta);
        var orig = this.textContent;
        this.textContent = <?php echo json_encode(t('admin_saml.copied')); ?>;
        this.style.background = '#86efac';
        var self = this;
        setTimeout(function() { self.textContent = orig; self.style.background = ''; }, 1500);
    });
});

// Confirm dialog via data attribute (inline onsubmit blocked by CSP nonce)
document.querySelectorAll('[data-confirm]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        if (!confirm(this.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
});
</script>

<!-- SAML Group Mappings -->
<div class="card" style="margin-top: 20px;">
    <h3 style="margin: 0 0 5px; font-size: 16px; font-weight: 600; color: #333;"><?php echo e(t('admin_saml.group_mappings_heading')); ?></h3>
    <p style="color: #666; font-size: 13px; margin: 0 0 20px;"><?php echo e(t('admin_saml.group_mappings_subtitle')); ?></p>

    <?php if (empty($groupMappings)): ?>
        <div style="text-align: center; padding: 30px 20px; background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">
            <div style="font-size: 13px; color: #6b7280;"><?php echo e(t('admin_saml.no_mappings_empty')); ?></div>
            <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;"><?php echo e(t('admin_saml.no_mappings_hint')); ?></div>
        </div>
    <?php else: ?>
        <table class="data-table" style="width: 100%; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="text-align: left;"><?php echo e(t('admin_saml.th_idp_group_name')); ?></th>
                    <th style="text-align: left;"><?php echo e(t('admin_saml.th_application_role')); ?></th>
                    <th style="text-align: center;"><?php echo e(t('admin_saml.th_auto_assign')); ?></th>
                    <th style="text-align: center;"><?php echo e(t('admin_saml.th_added')); ?></th>
                    <th style="text-align: center; width: 80px;"><?php echo e(t('admin_saml.th_actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupMappings as $mapping): ?>
                <tr>
                    <td>
                        <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 12px;"><?php echo e($mapping['saml_group_name']); ?></code>
                    </td>
                    <td>
                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>
                            <?php echo e($mapping['display_name']); ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($mapping['auto_assign']): ?>
                            <span style="color: #059669; font-weight: 500;"><?php echo e(t('admin_saml.yes')); ?></span>
                        <?php else: ?>
                            <span style="color: #9ca3af;"><?php echo e(t('admin_saml.no')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-size: 12px; color: #6b7280;">
                        <?php echo date('M j, Y', strtotime($mapping['created_at'])); ?>
                    </td>
                    <td style="text-align: center;">
                        <form method="POST" action="admin.php?section=saml" style="display: inline;" data-confirm="<?php echo e(t('admin_saml.remove_confirm')); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="mapping_id" value="<?php echo (int)$mapping['id']; ?>">
                            <button type="submit" name="delete_group_mapping" class="btn btn-sm" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 4px 10px; font-size: 12px; border-radius: 4px; cursor: pointer;"><?php echo e(t('admin_saml.remove_button')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Add New Mapping Form -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 20px; margin-top: 10px;">
        <h4 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; color: #333;"><?php echo e(t('admin_saml.add_new_mapping_heading')); ?></h4>
        <form method="POST" action="admin.php?section=saml" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
            <div style="flex: 1; min-width: 200px;">
                <label for="saml_group_name" style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('admin_saml.th_idp_group_name')); ?></label>
                <input type="text" id="saml_group_name" name="saml_group_name" class="form-control" placeholder="<?php echo e(t('admin_saml.idp_group_name_placeholder')); ?>" required style="width: 100%;">
            </div>
            <div style="min-width: 180px;">
                <label for="acl_group_id" style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px;"><?php echo e(t('admin_saml.th_application_role')); ?></label>
                <select id="acl_group_id" name="acl_group_id" class="form-control" required style="width: 100%;">
                    <option value=""><?php echo e(t('admin_saml.select_role_option')); ?></option>
                    <?php foreach ($allGroups as $group): ?>
                        <option value="<?php echo (int)$group['id']; ?>"><?php echo e($group['display_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; align-items: center; gap: 6px; padding-bottom: 6px;">
                <label class="checkbox-label" style="margin: 0; white-space: nowrap;">
                    <input type="checkbox" name="auto_assign" value="1" checked>
                    <span style="font-size: 13px;"><?php echo e(t('admin_saml.auto_assign_on_login')); ?></span>
                </label>
            </div>
            <div style="padding-bottom: 2px;">
                <button type="submit" name="add_group_mapping" class="btn btn-primary" style="white-space: nowrap;"><?php echo e(t('admin_saml.add_mapping_button')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Help / Reference -->
<div class="card" style="margin-top: 20px; background: #f0f9ff; border: 1px solid #bae6fd;">
    <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #0c4a6e;"><?php echo e(t('admin_saml.common_idp_attributes_heading')); ?></h4>
    <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
        <tr style="border-bottom: 1px solid #bae6fd;">
            <td style="padding: 6px 10px; font-weight: 500; color: #0369a1;">Microsoft Entra ID (Azure AD)</td>
            <td style="padding: 6px 10px;"><code style="background: #e0f2fe; padding: 1px 5px; border-radius: 3px;">http://schemas.microsoft.com/ws/2008/06/identity/claims/groups</code></td>
        </tr>
        <tr style="border-bottom: 1px solid #bae6fd;">
            <td style="padding: 6px 10px; font-weight: 500; color: #0369a1;">Okta</td>
            <td style="padding: 6px 10px;"><code style="background: #e0f2fe; padding: 1px 5px; border-radius: 3px;">groups</code></td>
        </tr>
        <tr style="border-bottom: 1px solid #bae6fd;">
            <td style="padding: 6px 10px; font-weight: 500; color: #0369a1;">Ping Identity / PingOne</td>
            <td style="padding: 6px 10px;"><code style="background: #e0f2fe; padding: 1px 5px; border-radius: 3px;">memberOf</code></td>
        </tr>
        <tr>
            <td style="padding: 6px 10px; font-weight: 500; color: #0369a1;">Auth0</td>
            <td style="padding: 6px 10px;"><code style="background: #e0f2fe; padding: 1px 5px; border-radius: 3px;">http://schemas.auth0.com/roles</code></td>
        </tr>
    </table>
    <p style="margin: 12px 0 0; font-size: 12px; color: #0c4a6e;"><?php echo e(t('admin_saml.common_idp_attributes_note')); ?></p>
</div>
