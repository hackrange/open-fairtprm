<?php
/**
 * Small helpers shared by the Shadow SaaS pages and the row-details partial.
 * Every function is guarded so a page that already defines one keeps its own.
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 */

if (!function_exists('displayUpguardScore')) {
    function displayUpguardScore(int $rawScore, string $mode, int $maxScore): string
    {
        if ($mode === 'percentage') {
            $pct = $maxScore > 0 ? (int)floor(($rawScore / $maxScore) * 100) : 0;
            return $pct . '%';
        }
        return (string)$rawScore;
    }
}

if (!function_exists('formatBytes')) {
    function formatBytes($bytes) {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
}

if (!function_exists('shadowSaasAiDeclared')) {
    /** The AI level a row declares: Grip's AI depth, else the "AI: ..." part of risk_type. */
    function shadowSaasAiDeclared(array $entry): ?string
    {
        $level = trim((string)($entry['grip_ai_depth_level'] ?? ''));
        if ($level !== '') return $level;
        if (preg_match('/(?:^|;)\s*AI:\s*([^;]+)/i', (string)($entry['risk_type'] ?? ''), $m)) {
            $level = trim($m[1]);
            if ($level !== '') return $level;
        }
        return null;
    }
}

if (!function_exists('shadowSaasAiLevel')) {
    /**
     * The app's AI level when it uses AI, else null. "No AI" (any case) means no
     * AI; any other AI telemetry (AI-Native App, AI Copilot Experience, AI
     * Features/Add-on, ...) counts as AI.
     */
    function shadowSaasAiLevel(array $entry): ?string
    {
        $level = shadowSaasAiDeclared($entry);
        if ($level === null || preg_match('/^no\s*ai$/i', $level)) return null;
        return $level;
    }
}

if (!function_exists('shadowSaasDetailCss')) {
    /** CSS for the row-details panel and badges, for pages other than shadow-saas.php. */
    function shadowSaasDetailCss(): string
    {
        return <<<CSS
        .detail-row { display: none; }
        .detail-row.open { display: table-row; }
        .detail-row > td { padding: 0 16px 16px 56px !important; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .detail-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 24px; font-size: 12px; padding-top: 12px; }
        .detail-grid .detail-item { display: flex; flex-direction: column; gap: 2px; }
        .detail-grid .detail-label { color: #6b7280; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
        .detail-grid .detail-value { color: #374151; font-size: 13px; }
        .score-grade { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; font-weight: 700; font-size: 11px; margin-left: 3px; }
        .grade-a { background: #dcfce7; color: #166534; }
        .grade-b { background: #d1fae5; color: #065f46; }
        .grade-c { background: #fef3c7; color: #92400e; }
        .grade-d { background: #fed7aa; color: #9a3412; }
        .grade-f { background: #fecaca; color: #991b1b; }
        .grip-breach-list { margin: 4px 0 0; padding-left: 16px; font-size: 12px; }
        .grip-breach-list li { margin: 2px 0; }
        .ai-badge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; vertical-align: middle; white-space: nowrap; background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
        .subproc-badge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; vertical-align: middle; white-space: nowrap; background: #f5f3ff; color: #6d28d9; border: 1px solid #c4b5fd; }
CSS . vendorPrivacyCss() . <<<CSS
        @media (max-width: 768px) { .detail-grid { grid-template-columns: 1fr; } }
CSS;
    }
}

if (!function_exists('shadowSaasNormDomain')) {
    /** Lowercase host with scheme, "www.", port and path removed ("https://www.Example.com/x" -> "example.com"). */
    function shadowSaasNormDomain($domain): string
    {
        $d = strtolower(trim((string)$domain));
        $d = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d);
        $d = preg_replace('#[/?\#:].*$#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);
        return trim($d, '.');
    }
}

if (!function_exists('shadowSaasStatusLabel')) {
    /** Display label for a Shadow SaaS status, in Grip's wording when Grip is on. */
    function shadowSaasStatusLabel(string $status, bool $gripEnabled): string
    {
        switch ($status) {
            case 'dismissed':    return t($gripEnabled ? 'shadow-saas.stat_tolerated' : 'shadow-saas.stat_dismissed');
            case 'unsanctioned': return t('shadow-saas.stat_unsanctioned');
            case 'pending':      return t('shadow-saas.stat_pending');
        }
        return ucfirst($status);
    }
}

if (!function_exists('shadowSaasSubprocessorOf')) {
    /**
     * The vendors that list this Shadow SaaS app as a subprocessor, as
     * [['id' => vendor id, 'name' => vendor name], ...]. A subprocessor matches
     * by domain; one recorded without a domain matches by exact name. The
     * mapping table is read once per request.
     */
    function shadowSaasSubprocessorOf(array $entry): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                $rows = Database::getInstance()->fetchAll(
                    "SELECT s.subprocessor_name, s.subprocessor_domain, v.id AS vendor_id, v.vendor_name
                       FROM vendor_subprocessor_mappings m
                       JOIN vendor_subprocessors s ON s.id = m.subprocessor_id
                       JOIN vendor_onboarding_requests v ON v.id = m.vendor_onboarding_id
                      ORDER BY v.vendor_name");
            } catch (Throwable $e) {
                error_log('Shadow SaaS subprocessor lookup failed: ' . $e->getMessage());
                $rows = [];
            }
            foreach ($rows as $r) {
                $d = shadowSaasNormDomain($r['subprocessor_domain'] ?? '');
                $key = $d !== '' ? 'd:' . $d : 'n:' . strtolower(trim((string)$r['subprocessor_name']));
                $map[$key][(int)$r['vendor_id']] = ['id' => (int)$r['vendor_id'], 'name' => (string)$r['vendor_name']];
            }
        }
        $out = [];
        $d = shadowSaasNormDomain($entry['vendor_domain'] ?? '');
        if ($d !== '' && isset($map['d:' . $d])) $out += $map['d:' . $d];
        $n = strtolower(trim((string)($entry['vendor_name'] ?? '')));
        if ($n !== '' && isset($map['n:' . $n])) $out += $map['n:' . $n];
        return array_values($out);
    }
}

if (!function_exists('vendorPrivacyCss')) {
    /** Badges and panel styles for the vendor privacy review (Shadow SaaS rows and vendor pages). */
    function vendorPrivacyCss(): string
    {
        return <<<CSS

        .vp-badge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; vertical-align: middle; white-space: nowrap; border: 1px solid transparent; }
        .vp-High { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .vp-Medium { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .vp-Low { background: #dcfce7; color: #166534; border-color: #86efac; }
        .vp-Unknown { background: #f3f4f6; color: #4b5563; border-color: #d1d5db; }
        .vp-panel { font-size: 13px; color: #374151; }
        .vp-panel .vp-facts { margin: 6px 0 0; padding-left: 16px; }
        .vp-panel .vp-quote { margin: 6px 0 0; padding: 6px 10px; border-left: 3px solid #d1d5db; background: #fff; font-size: 12px; color: #374151; }
        .vp-panel .vp-meta { color: #6b7280; font-size: 11px; margin-top: 6px; }
        .vp-panel .vp-knowledge { border-left-style: dashed; font-style: italic; }
CSS;
    }
}

if (!function_exists('vendorPrivacyReviewFor')) {
    /**
     * The privacy review row for a domain, or null (table missing, not yet
     * reviewed). One indexed lookup per domain, memoized for the request.
     */
    function vendorPrivacyReviewFor($domain): ?array
    {
        static $cache = [];
        $d = shadowSaasNormDomain($domain);
        if ($d === '') return null;
        if (!array_key_exists($d, $cache)) {
            try {
                $cache[$d] = Database::getInstance()->fetchOne("SELECT * FROM vendor_privacy_reviews WHERE domain = :d", [':d' => $d]) ?: null;
            } catch (Throwable $e) {
                $cache[$d] = null;
            }
        }
        return $cache[$d];
    }
}

if (!function_exists('vendorPrivacyBadges')) {
    /** Row badges for a reviewed vendor: Privacy / Sharing, only when High or Medium. HTML-escaped. */
    function vendorPrivacyBadges(?array $review): string
    {
        if (!$review || $review['status'] === 'error') return '';
        $out = '';
        foreach (['privacy' => $review['privacy_risk'], 'sharing' => $review['sharing_risk']] as $kind => $lvl) {
            if (!in_array($lvl, ['High', 'Medium'], true)) continue;
            $out .= ' <span class="vp-badge vp-' . $lvl . '" data-vp="' . $kind . '" title="' . e(t('vendor-privacy.badge_title_' . $kind, t('vendor-privacy.level_' . strtolower($lvl)))) . '">'
                . e(t('vendor-privacy.badge_' . $kind, t('vendor-privacy.level_' . strtolower($lvl)))) . '</span>';
        }
        return $out;
    }
}

if (!function_exists('vendorPrivacyQueuedPill')) {
    /** "Review queued" pill while a manual privacy review is waiting or running. HTML-escaped. */
    function vendorPrivacyQueuedPill($domain): string
    {
        static $queued = null;
        if ($queued === null) {
            $queued = [];
            try {
                foreach (Database::getInstance()->fetchAll("SELECT domain FROM vendor_privacy_queue") as $r) $queued[$r['domain']] = true;
            } catch (Throwable $e) {}
        }
        $d = shadowSaasNormDomain($domain);
        if ($d === '' || empty($queued[$d])) return '';
        return ' <span class="vp-badge vp-Unknown" data-vp="queued" title="' . e(t('vendor-privacy.queued_title')) . '">' . e(t('vendor-privacy.queued_pill')) . '</span>';
    }
}

if (!function_exists('shadowSaasOnboardDraftData')) {
    /**
     * The draft vendor_onboarding_requests row for a Shadow SaaS app: name,
     * domain, RM, users, and the onboarding answers implied by its risk types.
     * Shared by Onboard / Allow (shadow-saas.php) and the Business Use path of
     * a Shadow SaaS request (ShadowSaasRequestService).
     */
    function shadowSaasOnboardDraftData(array $entry, int $userId): array
    {
        $onboardData = [
            'status' => 'draft',
            'created_by' => $userId,
            'vendor_name' => $entry['vendor_name'],
            'vendor_domain' => $entry['vendor_domain'],
            'relationship_manager' => $entry['relationship_manager'],
            'vendor_tier' => '3',
            'rescore_status' => 'rescoring',
            'rescore_started_at' => date('Y-m-d H:i:s'),
        ];

        if (!empty($entry['description'])) {
            $onboardData['product_service_description'] = $entry['description'];
        }
        if (!empty($entry['number_of_users'])) {
            $onboardData['target_user_count'] = (int)$entry['number_of_users'];
        }

        if (!empty($entry['risk_type'])) {
            $riskTypes = array_map('trim', explode(';', $entry['risk_type']));
            foreach ($riskTypes as $rt) {
                $rtLower = strtolower($rt);
                if ($rtLower === 'shadow it') {
                    $onboardData['is_saas'] = 'yes';
                } elseif ($rtLower === 'data leakage') {
                    $onboardData['confidential_info_shared'] = 'yes';
                    $onboardData['confidential_info_justification'] = 'Shadow SaaS risk assessment identified data leakage risk.';
                } elseif ($rtLower === 'account takeover risk') {
                    $onboardData['remote_network_access'] = 'yes';
                    $onboardData['remote_access_justification'] = 'Shadow SaaS risk assessment identified account takeover risk.';
                } elseif ($rtLower === 'intellectual property exposure') {
                    $onboardData['source_code_access'] = 'yes';
                    $onboardData['source_code_justification'] = 'Shadow SaaS risk assessment identified intellectual property exposure risk.';
                } elseif ($rtLower === 'third-party breach risk') {
                    $onboardData['offsite_data_hosting'] = 'yes';
                    $onboardData['offsite_data_justification'] = 'Shadow SaaS risk assessment identified third-party breach risk.';
                }
            }
        }

        if (!empty($entry['breaches_in_three_years']) && (int)$entry['breaches_in_three_years'] > 0) {
            $breachCount = (int)$entry['breaches_in_three_years'];
            $onboardData['pii_phi_exchange'] = 'yes';
            $onboardData['pii_phi_justification'] = "Vendor has had {$breachCount} breach(es) in the past 3 years.";
        }

        if (!empty($entry['filesharing']) && strtolower($entry['filesharing']) === 'yes') {
            if (empty($onboardData['confidential_info_shared'])) {
                $onboardData['confidential_info_shared'] = 'yes';
            }
        }

        if (!empty($entry['mfasupport'])) {
            $mfaLower = strtolower($entry['mfasupport']);
            if ($mfaLower === 'no') {
                $onboardData['saml_sso_support'] = 'no';
            } elseif ($mfaLower === 'yes') {
                $onboardData['saml_sso_support'] = 'yes';
            }
        }

        return $onboardData;
    }
}

if (!function_exists('shadowSaasRowToggleJs')) {
    /**
     * JS for clickable Shadow SaaS rows with a details row after each. Opening a
     * row keeps it and its details on screen (scrolls when they do not fit), and
     * the open row is remembered for 10 minutes so that after an action reloads
     * the page it opens again where it was. $tableSel / $idAttr are fixed
     * strings from our own code (no user data); output goes in a nonce script.
     */
    function shadowSaasRowToggleJs(string $tableSel, string $idAttr): string
    {
        $t = json_encode($tableSel);
        $a = json_encode($idAttr);
        return <<<JS
(function () {
    var table = document.querySelector($t);
    if (!table) return;
    var attr = $a, key = 'ssOpenRow:' + location.pathname;
    function remember(id) {
        try { if (id) sessionStorage.setItem(key, JSON.stringify({ id: id, t: Date.now() })); else sessionStorage.removeItem(key); } catch (e) {}
    }
    // Keep the row and its open details in view: scroll only when they do not fit.
    function anchor(row, smooth) {
        var detail = row.nextElementSibling, top = row.getBoundingClientRect().top;
        var bottom = (detail && detail.classList.contains('open')) ? detail.getBoundingClientRect().bottom : row.getBoundingClientRect().bottom;
        if (top < 8 || bottom > window.innerHeight - 8) {
            window.scrollTo({ top: Math.max(0, window.scrollY + top - 80), behavior: smooth ? 'smooth' : 'auto' });
        }
    }
    table.addEventListener('click', function (e) {
        if (e.target.closest('a, button, input, select, textarea, label')) return;
        var row = e.target.closest('tr');
        if (!row || row.classList.contains('detail-row') || !row.hasAttribute(attr)) return;
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('detail-row')) return;
        var open = detail.classList.toggle('open');
        remember(open ? row.getAttribute(attr) : null);
        if (open) anchor(row, true);
    });
    // After a reload (an action on the row), reopen the row the user had open.
    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) {}
    if (saved && saved.id && Date.now() - saved.t < 600000) {
        var rows = table.querySelectorAll('tr[' + attr + ']');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].getAttribute(attr) !== String(saved.id)) continue;
            var d = rows[i].nextElementSibling;
            if (d && d.classList.contains('detail-row')) { d.classList.add('open'); anchor(rows[i], false); }
            break;
        }
    }
})();
JS;
    }
}
