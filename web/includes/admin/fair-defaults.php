<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: FAIR Calculation Defaults
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Where you set the price tags on data breaches, define your organization's
 * financial profile for revenue-based loss capping, and optionally enable
 * AI-assisted FAIR calculations. These defaults feed into the FAIR risk
 * calculations so we can put a dollar sign on what a vendor breach would
 * actually cost. Because nothing motivates executive action like seeing
 * a number with lots of zeros.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// ============================================================================
// POST Handler: update_calculation_defaults
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_calculation_defaults'])) {
    if (!isset($_POST['csrf_token']) || !$security->validateCSRFToken($_POST['csrf_token'])) {
        $error = t('admin_fair-defaults.error_invalid_request');
    } else {
        try {
            $db->beginTransaction();

            $calculationDefaults = [
                'pii_breach_cost_per_record' => floatval($_POST['pii_breach_cost_per_record'] ?? 160),
                'spii_breach_cost_per_record' => floatval($_POST['spii_breach_cost_per_record'] ?? 200),
                'sox_breach_penalty' => floatval($_POST['sox_breach_penalty'] ?? 5000000),
                'fair_annual_revenue' => max(0, floatval($_POST['fair_annual_revenue'] ?? 0)),
                'fair_revenue_cap_pct' => max(0, min(100, floatval($_POST['fair_revenue_cap_pct'] ?? 10))),
                'fair_ai_enabled' => isset($_POST['fair_ai_enabled']) ? '1' : '0',
            ];

            foreach ($calculationDefaults as $key => $value) {
                $existing = $db->fetchOne(
                    'SELECT id FROM app_config WHERE config_key = :key',
                    [':key' => $key]
                );

                if ($existing) {
                    $db->update(
                        'app_config',
                        ['config_value' => $value],
                        'config_key = :key',
                        [':key' => $key]
                    );
                } else {
                    $db->insert('app_config', [
                        'config_key' => $key,
                        'config_value' => $value,
                        'is_encrypted' => 0
                    ]);
                }
            }

            $db->commit();
            $auth->audit($user['id'], 'config_update_fair_defaults', 'app_config', null, [
                'new' => $calculationDefaults
            ]);
            $success = t('admin_fair-defaults.success_saved');
        } catch (Exception $e) {
            $db->rollback();
            error_log('Error updating calculation defaults: ' . $e->getMessage());
            $error = t('admin_fair-defaults.error_save_failed');
        }
    }
}

// ============================================================================
// Data Loading
// ============================================================================
$appConfig = [];
$configRows = $db->fetchAll('SELECT config_key, config_value FROM app_config');
foreach ($configRows as $row) {
    $appConfig[$row['config_key']] = $row['config_value'];
}

// Check if any AI platform is enabled (controls visibility of AI checkbox)
$aiPlatformEnabled = AIPlatformService::getInstance()->isEnabled();

// Compute display value for revenue cap example
$annualRev = floatval($appConfig['fair_annual_revenue'] ?? 0);
$capPct = floatval($appConfig['fair_revenue_cap_pct'] ?? 10);
$maxLossExample = ($annualRev > 0) ? '$' . number_format($annualRev * ($capPct / 100), 0) : '';

// ============================================================================
// HTML: FAIR Defaults Form
// ============================================================================
?>
<div class="page-header-bar">
    <h1 class="page-title"><?php echo e(t('admin_fair-defaults.page_title')); ?></h1>
    <p><?php echo e(t('admin_fair-defaults.page_subtitle')); ?></p>
</div>

<form method="POST" action="admin.php?section=fair">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

    <!-- Card 1: Breach Cost Defaults -->
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: #1e3a5f;"><?php echo e(t('admin_fair-defaults.breach_cost_defaults_heading')); ?></h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;"><?php echo e(t('admin_fair-defaults.breach_cost_defaults_help')); ?></p>
        <div class="form-row">
            <div class="form-group">
                <label for="pii_breach_cost_per_record"><?php echo e(t('admin_fair-defaults.pii_cost_per_record_label')); ?></label>
                <input type="number" id="pii_breach_cost_per_record" name="pii_breach_cost_per_record" class="form-control" step="0.01" min="0" value="<?php echo e($appConfig['pii_breach_cost_per_record'] ?? '160'); ?>" required>
                <div class="form-help"><?php echo e(t('admin_fair-defaults.pii_cost_per_record_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="spii_breach_cost_per_record"><?php echo e(t('admin_fair-defaults.spii_cost_per_record_label')); ?></label>
                <input type="number" id="spii_breach_cost_per_record" name="spii_breach_cost_per_record" class="form-control" step="0.01" min="0" value="<?php echo e($appConfig['spii_breach_cost_per_record'] ?? '200'); ?>" required>
                <div class="form-help"><?php echo e(t('admin_fair-defaults.spii_cost_per_record_help')); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label for="sox_breach_penalty"><?php echo e(t('admin_fair-defaults.sox_penalty_label')); ?></label>
            <input type="number" id="sox_breach_penalty" name="sox_breach_penalty" class="form-control" step="0.01" min="0" value="<?php echo e($appConfig['sox_breach_penalty'] ?? '5000000'); ?>" required>
            <div class="form-help"><?php echo e(t('admin_fair-defaults.sox_penalty_help')); ?></div>
        </div>
    </div>

    <!-- Card 2: Organization Financial Profile -->
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: #1e3a5f;"><?php echo e(t('admin_fair-defaults.org_financial_profile_heading')); ?></h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;"><?php echo e(t('admin_fair-defaults.org_financial_profile_help')); ?></p>
        <div class="form-row">
            <div class="form-group">
                <label for="fair_annual_revenue"><?php echo e(t('admin_fair-defaults.annual_revenue_label')); ?></label>
                <input type="number" id="fair_annual_revenue" name="fair_annual_revenue" class="form-control" step="1" min="0" value="<?php echo e($appConfig['fair_annual_revenue'] ?? '0'); ?>">
                <div class="form-help"><?php echo e(t('admin_fair-defaults.annual_revenue_help')); ?></div>
            </div>
            <div class="form-group">
                <label for="fair_revenue_cap_pct"><?php echo e(t('admin_fair-defaults.revenue_cap_pct_label')); ?></label>
                <input type="number" id="fair_revenue_cap_pct" name="fair_revenue_cap_pct" class="form-control" step="0.1" min="0" max="100" value="<?php echo e($appConfig['fair_revenue_cap_pct'] ?? '10'); ?>">
                <div class="form-help"><?php echo e(t('admin_fair-defaults.revenue_cap_pct_help')); ?></div>
            </div>
        </div>
        <div id="revCapPreview" style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #0c4a6e; display: <?php echo ($annualRev > 0) ? 'block' : 'none'; ?>;">
            <?php echo e(t('admin_fair-defaults.revenue_cap_preview_prefix')); ?> <strong id="revCapAmount"><?php echo $maxLossExample; ?></strong> <?php echo e(t('admin_fair-defaults.revenue_cap_preview_suffix')); ?>
        </div>
    </div>

    <!-- Card 3: AI-Assisted Analysis (only shown if an AI platform is enabled) -->
    <?php if ($aiPlatformEnabled): ?>
    <div class="card" style="margin-bottom: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: #1e3a5f;"><?php echo e(t('admin_fair-defaults.ai_analysis_heading')); ?></h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 15px;"><?php echo e(t('admin_fair-defaults.ai_analysis_help')); ?></p>
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="fair_ai_enabled" value="1" <?php echo ($appConfig['fair_ai_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                <span><?php echo e(t('admin_fair-defaults.ai_enable_checkbox_label')); ?></span>
            </label>
            <div class="form-help"><?php echo e(t('admin_fair-defaults.ai_enable_help')); ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom: 20px; opacity: 0.6;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: #1e3a5f;"><?php echo e(t('admin_fair-defaults.ai_analysis_heading')); ?></h3>
        <p style="color: #64748b; font-size: 13px; margin-bottom: 0;"><?php echo t('admin_fair-defaults.ai_disabled_help'); ?></p>
    </div>
    <?php endif; ?>

    <button type="submit" name="update_calculation_defaults" class="btn btn-primary"><?php echo e(t('admin_fair-defaults.save_button')); ?></button>
</form>

<script>
// Live preview of revenue cap calculation
(function() {
    var revInput = document.getElementById('fair_annual_revenue');
    var capInput = document.getElementById('fair_revenue_cap_pct');
    var preview = document.getElementById('revCapPreview');
    var amountEl = document.getElementById('revCapAmount');

    function updatePreview() {
        var rev = parseFloat(revInput.value) || 0;
        var cap = parseFloat(capInput.value) || 0;
        if (rev > 0 && cap > 0) {
            var maxLoss = rev * (cap / 100);
            amountEl.textContent = '$' + maxLoss.toLocaleString('en-US', {maximumFractionDigits: 0});
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
        }
    }

    if (revInput && capInput) {
        revInput.addEventListener('input', updatePreview);
        capInput.addEventListener('input', updatePreview);
    }
})();
</script>
