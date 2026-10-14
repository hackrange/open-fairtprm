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
