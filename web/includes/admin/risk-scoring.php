<?php
if (!defined('ADMIN_DISPATCH')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin Section: Risk Scoring Configuration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Configures risk band thresholds, labels, colors, and axis display names for
 * the GRC risk register and FairScore heatmap. Admin-only. Single-tenant.
 *
 * Available variables from admin.php:
 *   $db, $auth, $security, $config, $user, $theme, $session, $acl,
 *   $section, $isAdmin, $error, $success, $csrfToken
 */

// Valid config keys — strict whitelist, nothing outside this list is ever written.
const RISK_CONFIG_KEYS = [
    'risk_threshold_critical', 'risk_threshold_high', 'risk_threshold_medium',
    'risk_label_critical', 'risk_label_high', 'risk_label_medium', 'risk_label_low',
    'risk_color_critical', 'risk_color_high', 'risk_color_medium', 'risk_color_low',
    'risk_likelihood_rare', 'risk_likelihood_unlikely', 'risk_likelihood_possible',
    'risk_likelihood_likely', 'risk_likelihood_almost_certain',
    'risk_impact_insignificant', 'risk_impact_minor', 'risk_impact_moderate',
    'risk_impact_major', 'risk_impact_catastrophic',
    'risk_category_strategic', 'risk_category_operational', 'risk_category_financial',
    'risk_category_compliance', 'risk_category_reputational', 'risk_category_technology',
    'risk_category_third_party',
];

// ============================================================================
// POST Handler: reset_risk_config — delete all risk_* rows, revert to defaults
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_risk_config'])) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        try {
            $db->beginTransaction();
            foreach (RISK_CONFIG_KEYS as $k) {
                $db->query("DELETE FROM app_config WHERE config_key = :k", [':k' => $k]);
            }
            $db->commit();
            getAppConfig('', null, true); // bust getAppConfig per-key cache
            // Also bust getRiskConfig static cache via a fresh request redirect.
            header('Location: admin.php?section=risk-scoring&reset=1');
            exit;
        } catch (Exception $e) {
            $db->rollback();
            error_log('Risk config reset failed: ' . $e->getMessage());
            $error = 'Reset failed. Please try again.';
        }
    }
}

// ============================================================================
// POST Handler: save_risk_config
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_risk_config'])) {
    if (!$security->validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        // Sanitize and validate every field explicitly.
        $rawLabel = function(string $key): string {
            return strip_tags(mb_substr(trim($_POST[$key] ?? ''), 0, 60));
        };

        $tCrit = max(2, min(25, intval($_POST['risk_threshold_critical'] ?? 20)));
        $tHigh = max(2, min(24, intval($_POST['risk_threshold_high'] ?? 12)));
        $tMed  = max(1, min(23, intval($_POST['risk_threshold_medium'] ?? 6)));

        // Enforce ordering server-side — cannot trust JS validation alone.
        $tHigh = min($tHigh, $tCrit - 1);
        $tMed  = min($tMed,  $tHigh - 1);
        $tCrit = max($tCrit, 3);
        $tHigh = max($tHigh, 2);
        $tMed  = max($tMed,  1);

        $configs = [
            'risk_threshold_critical' => (string)$tCrit,
            'risk_threshold_high'     => (string)$tHigh,
            'risk_threshold_medium'   => (string)$tMed,

            'risk_label_critical' => $rawLabel('risk_label_critical') ?: 'Critical',
            'risk_label_high'     => $rawLabel('risk_label_high')     ?: 'High',
            'risk_label_medium'   => $rawLabel('risk_label_medium')   ?: 'Medium',
            'risk_label_low'      => $rawLabel('risk_label_low')      ?: 'Low',

            'risk_color_critical' => safeColor($_POST['risk_color_critical'] ?? '', '#991b1b'),
            'risk_color_high'     => safeColor($_POST['risk_color_high']     ?? '', '#dc3545'),
            'risk_color_medium'   => safeColor($_POST['risk_color_medium']   ?? '', '#f59e0b'),
            'risk_color_low'      => safeColor($_POST['risk_color_low']      ?? '', '#28a745'),

            'risk_likelihood_rare'           => $rawLabel('risk_likelihood_rare')           ?: 'Rare',
            'risk_likelihood_unlikely'       => $rawLabel('risk_likelihood_unlikely')       ?: 'Unlikely',
            'risk_likelihood_possible'       => $rawLabel('risk_likelihood_possible')       ?: 'Possible',
            'risk_likelihood_likely'         => $rawLabel('risk_likelihood_likely')         ?: 'Likely',
            'risk_likelihood_almost_certain' => $rawLabel('risk_likelihood_almost_certain') ?: 'Almost Certain',

            'risk_impact_insignificant' => $rawLabel('risk_impact_insignificant') ?: 'Insignificant',
            'risk_impact_minor'         => $rawLabel('risk_impact_minor')         ?: 'Minor',
            'risk_impact_moderate'      => $rawLabel('risk_impact_moderate')      ?: 'Moderate',
            'risk_impact_major'         => $rawLabel('risk_impact_major')         ?: 'Major',
            'risk_impact_catastrophic'  => $rawLabel('risk_impact_catastrophic')  ?: 'Catastrophic',

            'risk_category_strategic'    => $rawLabel('risk_category_strategic')    ?: 'Strategic',
            'risk_category_operational'  => $rawLabel('risk_category_operational')  ?: 'Operational',
            'risk_category_financial'    => $rawLabel('risk_category_financial')    ?: 'Financial',
            'risk_category_compliance'   => $rawLabel('risk_category_compliance')   ?: 'Compliance',
            'risk_category_reputational' => $rawLabel('risk_category_reputational') ?: 'Reputational',
            'risk_category_technology'   => $rawLabel('risk_category_technology')   ?: 'Technology',
            'risk_category_third_party'  => $rawLabel('risk_category_third_party')  ?: 'Third Party',
        ];

        // Confirm every key we are about to write is in the whitelist (defense in depth).
        foreach (array_keys($configs) as $k) {
            if (!in_array($k, RISK_CONFIG_KEYS, true)) {
                $error = 'Unexpected config key detected.';
                break;
            }
        }

        if (!isset($error)) {
            try {
                $db->beginTransaction();
                foreach ($configs as $key => $value) {
                    $existing = $db->fetchOne(
                        'SELECT id FROM app_config WHERE config_key = :key',
                        [':key' => $key]
                    );
                    if ($existing) {
                        $db->update('app_config', ['config_value' => $value], 'config_key = :key', [':key' => $key]);
                    } else {
                        $db->insert('app_config', ['config_key' => $key, 'config_value' => $value, 'is_encrypted' => 0]);
                    }
                }
                $db->commit();
                $auth->audit($user['id'], 'config_update', 'risk_scoring', null, ['new' => $configs]);
                getAppConfig('', null, true); // bust per-key cache
                $success = 'Risk scoring settings saved.';
            } catch (Exception $e) {
                $db->rollback();
                error_log('Risk config save failed: ' . $e->getMessage());
                $error = 'Save failed. Please try again.';
            }
        }
    }
}

// ============================================================================
// Load current config for rendering
// ============================================================================
$rc = getRiskConfig();

$bandKeys = ['critical', 'high', 'medium', 'low'];
$thresholdDefaults = ['critical' => 20, 'high' => 12, 'medium' => 6];

// Likelihood in ascending order for the form (matches the 1-5 scale)
$likelihoodKeys = ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'];
$impactKeys     = ['insignificant', 'minor', 'moderate', 'major', 'catastrophic'];
$categoryKeys   = ['strategic', 'operational', 'financial', 'compliance', 'reputational', 'technology', 'third_party'];
?>

<?php if (isset($_GET['reset'])): ?>
<div class="alert alert-success" style="margin-bottom:20px;">Risk scoring settings reset to defaults.</div>
<?php endif; ?>

<div class="page-header" style="margin-bottom:24px;">
    <h1 class="page-title">Risk Scoring</h1>
    <p style="color:#6b7280;font-size:14px;margin:4px 0 0;">Customize risk band thresholds, labels, colors, and axis definitions for the risk register and heatmap.</p>
</div>

<form method="POST" action="admin.php?section=risk-scoring" id="riskScoringForm">
<input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

<!-- =========================================================
     Section 1: Risk Bands
     ========================================================= -->
<div class="card" style="margin-bottom:24px;">
    <h2 style="font-size:15px;font-weight:600;color:#111;margin:0 0 6px;">Risk Bands</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 20px;">A risk score is Likelihood × Impact (range 1–25). Scores at or above a threshold are assigned that band. Thresholds must be in descending order.</p>

    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:0.5px;">
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Band</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Label</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Score Threshold (≥)</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Color</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Preview</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (['critical','high','medium'] as $band): ?>
        <tr style="border-bottom:1px solid #f3f4f6;">
            <td style="padding:12px;font-size:13px;color:#6b7280;font-weight:500;text-transform:capitalize;"><?php echo ucfirst($band); ?></td>
            <td style="padding:12px;">
                <input type="text" name="risk_label_<?php echo e($band); ?>"
                       value="<?php echo e($rc['labels'][$band]); ?>"
                       maxlength="60" required
                       style="width:140px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            </td>
            <td style="padding:12px;">
                <input type="number" name="risk_threshold_<?php echo e($band); ?>"
                       value="<?php echo e($rc['thresholds'][$band]); ?>"
                       min="1" max="25" required
                       style="width:80px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            </td>
            <td style="padding:12px;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" id="color_picker_<?php echo e($band); ?>"
                           name="risk_color_<?php echo e($band); ?>"
                           value="<?php echo e($rc['colors'][$band]); ?>"
                           style="width:50px;height:36px;border:2px solid #ddd;border-radius:4px;cursor:pointer;padding:2px;">
                    <input type="text" id="color_text_<?php echo e($band); ?>"
                           value="<?php echo e($rc['colors'][$band]); ?>"
                           maxlength="7" readonly
                           style="width:90px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                </div>
            </td>
            <td style="padding:12px;">
                <span id="preview_<?php echo e($band); ?>"
                      style="display:inline-block;padding:4px 14px;border-radius:6px;font-weight:700;font-size:13px;color:#fff;background:<?php echo e($rc['colors'][$band]); ?>;">
                    <?php echo e($rc['labels'][$band]); ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <!-- Low band: label + color only (no threshold — it's the catch-all) -->
        <tr>
            <td style="padding:12px;font-size:13px;color:#6b7280;font-weight:500;">Low</td>
            <td style="padding:12px;">
                <input type="text" name="risk_label_low"
                       value="<?php echo e($rc['labels']['low']); ?>"
                       maxlength="60" required
                       style="width:140px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            </td>
            <td style="padding:12px;font-size:13px;color:#6b7280;">&lt; medium threshold</td>
            <td style="padding:12px;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" id="color_picker_low"
                           name="risk_color_low"
                           value="<?php echo e($rc['colors']['low']); ?>"
                           style="width:50px;height:36px;border:2px solid #ddd;border-radius:4px;cursor:pointer;padding:2px;">
                    <input type="text" id="color_text_low"
                           value="<?php echo e($rc['colors']['low']); ?>"
                           maxlength="7" readonly
                           style="width:90px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                </div>
            </td>
            <td style="padding:12px;">
                <span id="preview_low"
                      style="display:inline-block;padding:4px 14px;border-radius:6px;font-weight:700;font-size:13px;color:#fff;background:<?php echo e($rc['colors']['low']); ?>;">
                    <?php echo e($rc['labels']['low']); ?>
                </span>
            </td>
        </tr>
        </tbody>
    </table>
</div>

<!-- =========================================================
     Section 2: Likelihood Definitions
     ========================================================= -->
<div class="card" style="margin-bottom:24px;">
    <h2 style="font-size:15px;font-weight:600;color:#111;margin:0 0 6px;">Likelihood Definitions</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 20px;">Display labels for the five likelihood levels (ascending: 1=lowest, 5=highest). Internal keys are fixed.</p>
    <div style="display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:center;max-width:480px;">
    <?php foreach ($likelihoodKeys as $idx => $key): ?>
        <label style="font-size:13px;color:#374151;">Level <?php echo $idx + 1; ?> <span style="color:#9ca3af;font-size:11px;">(<?php echo e($key); ?>)</span></label>
        <input type="text" name="risk_likelihood_<?php echo e($key); ?>"
               value="<?php echo e($rc['likelihood_labels'][$key]); ?>"
               maxlength="60" required
               style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
    <?php endforeach; ?>
    </div>
</div>

<!-- =========================================================
     Section 3: Consequence / Impact Definitions
     ========================================================= -->
<div class="card" style="margin-bottom:24px;">
    <h2 style="font-size:15px;font-weight:600;color:#111;margin:0 0 6px;">Consequence / Impact Definitions</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 20px;">Display labels for the five impact levels (ascending: 1=lowest, 5=highest). Internal keys are fixed.</p>
    <div style="display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:center;max-width:480px;">
    <?php foreach ($impactKeys as $idx => $key): ?>
        <label style="font-size:13px;color:#374151;">Level <?php echo $idx + 1; ?> <span style="color:#9ca3af;font-size:11px;">(<?php echo e($key); ?>)</span></label>
        <input type="text" name="risk_impact_<?php echo e($key); ?>"
               value="<?php echo e($rc['impact_labels'][$key]); ?>"
               maxlength="60" required
               style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
    <?php endforeach; ?>
    </div>
</div>

<!-- =========================================================
     Section 4: Risk Category Names
     ========================================================= -->
<div class="card" style="margin-bottom:24px;">
    <h2 style="font-size:15px;font-weight:600;color:#111;margin:0 0 6px;">Risk Category Names</h2>
    <p style="font-size:13px;color:#6b7280;margin:0 0 20px;">Display names for each risk category. Internal keys are fixed.</p>
    <div style="display:grid;grid-template-columns:140px 1fr;gap:10px;align-items:center;max-width:480px;">
    <?php foreach ($categoryKeys as $key): ?>
        <label style="font-size:13px;color:#374151;"><span style="color:#9ca3af;font-size:11px;"><?php echo e($key); ?></span></label>
        <input type="text" name="risk_category_<?php echo e($key); ?>"
               value="<?php echo e($rc['category_labels'][$key]); ?>"
               maxlength="60" required
               style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
    <?php endforeach; ?>
    </div>
</div>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:40px;">
    <button type="submit" name="save_risk_config" class="btn btn-primary">Save Settings</button>
    <button type="button" id="resetBtn" class="btn btn-outline" style="color:#991b1b;border-color:#fca5a5;">Reset to Defaults</button>
</div>
</form>

<!-- Reset form (separate POST to avoid CSRF confusion) -->
<form method="POST" action="admin.php?section=risk-scoring" id="resetForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
    <input type="hidden" name="reset_risk_config" value="1">
</form>

<script nonce="<?php echo cspNonce(); ?>">
(function() {
    // Sync color pickers with readonly text fields and live-preview badge
    ['critical', 'high', 'medium', 'low'].forEach(function(band) {
        var picker  = document.getElementById('color_picker_' + band);
        var textBox = document.getElementById('color_text_' + band);
        var preview = document.getElementById('preview_' + band);
        if (!picker) return;
        picker.addEventListener('input', function() {
            var hex = this.value.toUpperCase();
            textBox.value = hex;
            if (preview) preview.style.background = hex;
        });
    });

    // Live-update preview label text when label input changes
    ['critical', 'high', 'medium', 'low'].forEach(function(band) {
        var labelInput = document.querySelector('[name="risk_label_' + band + '"]');
        var preview    = document.getElementById('preview_' + band);
        if (!labelInput || !preview) return;
        labelInput.addEventListener('input', function() {
            preview.textContent = this.value || band.charAt(0).toUpperCase() + band.slice(1);
        });
    });

    // Threshold ordering enforcement (client-side hint only; server also enforces)
    var crit = document.querySelector('[name="risk_threshold_critical"]');
    var high = document.querySelector('[name="risk_threshold_high"]');
    var med  = document.querySelector('[name="risk_threshold_medium"]');
    function enforceOrder() {
        if (!crit || !high || !med) return;
        var c = parseInt(crit.value) || 20;
        var h = parseInt(high.value) || 12;
        var m = parseInt(med.value)  || 6;
        if (h >= c) { high.value = c - 1; h = c - 1; }
        if (m >= h) { med.value  = h - 1; }
    }
    [crit, high, med].forEach(function(el) {
        if (el) el.addEventListener('change', enforceOrder);
    });

    // Reset confirmation
    document.getElementById('resetBtn').addEventListener('click', function() {
        if (confirm('Reset all risk scoring settings to factory defaults? This cannot be undone.')) {
            document.getElementById('resetForm').submit();
        }
    });
})();
</script>
