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
        @media (max-width: 768px) { .detail-grid { grid-template-columns: 1fr; } }
CSS;
    }
}
