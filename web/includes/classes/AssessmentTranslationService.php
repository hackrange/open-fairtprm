<?php
/**
 * AssessmentTranslationService
 *
 * On-demand, cached machine translation of admin-authored assessment content
 * (template/section/question prose and answer-option labels) for the public
 * vendor assessment form. Translations are produced by the AIPlatformService
 * integration and cached in the assessment_translations table keyed by a
 * SHA-256 of the source text, so each string is translated once per language
 * and re-translated automatically when an admin edits it.
 *
 * PERFORMANCE / SAFETY (v2.6.1 fix): a page render must never fan out into many
 * blocking AI calls. Two guards enforce this:
 *   1. warmAssessment() pre-translates EVERY string the page needs in ONE
 *      batched AI call, then caches each piece. The per-string translate()/
 *      translateOptions() calls that follow are pure cache reads.
 *   2. A hard per-request budget (MAX_AI_CALLS_PER_REQUEST) plus a short
 *      AI_TIMEOUT bound the worst case: at most one AI call, capped in time.
 *      Anything not translated falls back to the original English -- the form
 *      is never blank and never hangs.
 *
 * NOTE: only answer-option LABELS are translated for display. The stored
 * response value always remains the original (English) option, so existing
 * responses, scoring, and conditional logic are unaffected.
 */
class AssessmentTranslationService
{
    private static $instance = null;
    private $db;

    /** Per-request AI-call budget. Bounds worst-case latency / resource use. */
    private static $aiCallsThisRequest = 0;
    private const MAX_AI_CALLS_PER_REQUEST = 1;
    private const AI_TIMEOUT = 25;   // seconds, per AI call

    private static $languageNames = [
        'en'      => 'English',
        'es'      => 'Spanish',
        'it'      => 'Italian',
        'uk'      => 'Ukrainian',
        'zh-Hans' => 'Simplified Chinese',
        'hi'      => 'Hindi',
        'fr'      => 'French',
        'pt'      => 'Portuguese',
    ];

    private const KEEP_VERBATIM = 'FAIR, SRS, GRC, CVE, CSF, ISO, SOC, NIST, SaaS, Shadow SaaS';

    private function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function canTranslate(string $lang): bool
    {
        if ($lang === '' || $lang === 'en' || !isset(self::$languageNames[$lang])) {
            return false;
        }
        try {
            return AIPlatformService::getInstance()->isEnabled();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Pre-translate, in a SINGLE batched AI call, every string the assessment
     * page will render for $lang, and cache each piece. After this returns, the
     * per-string translate()/translateOptions() calls below are cache hits.
     * Strings already cached are skipped; if nothing is missing, no AI call is
     * made. Degrades to English silently on any failure.
     */
    public function warmAssessment(array $assessment, array $sections, $currentSection, array $currentQuestions, string $lang): void
    {
        if (!$this->canTranslate($lang)) {
            return;
        }

        // Collect (type,id,text) prose units + per-question option arrays, keeping
        // only cache MISSES. Dedupe distinct source strings to minimise payload.
        $prose = [];          // list of [type,id,text,hash]
        $optionGroups = [];   // list of [qid, options[], hash]
        $distinct = [];       // sourceText => true  (the set we must translate)

        $addProse = function ($type, $id, $text) use (&$prose, &$distinct, $lang) {
            $text = (string)$text;
            if (trim($text) === '') return;
            $hash = hash('sha256', $text);
            if ($this->cacheGet($type, (int)$id, $lang, $hash) !== null) return; // already cached
            $prose[] = [$type, (int)$id, $text, $hash];
            $distinct[$text] = true;
        };

        if (!empty($assessment['template_name'])) {
            $addProse('template_name', (int)($assessment['template_id'] ?? 0), $assessment['template_name']);
        }
        foreach ($sections as $sec) {
            $addProse('section_name', (int)($sec['id'] ?? 0), $sec['name'] ?? '');
        }
        if (is_array($currentSection)) {
            $addProse('section_name', (int)($currentSection['id'] ?? 0), $currentSection['name'] ?? '');
            $addProse('section_description', (int)($currentSection['id'] ?? 0), $currentSection['description'] ?? '');
        }
        foreach ($currentQuestions as $q) {
            $addProse('question_text', (int)($q['id'] ?? 0), $q['question_text'] ?? '');
            if (!empty($q['help_text'])) {
                $addProse('help_text', (int)($q['id'] ?? 0), $q['help_text']);
            }
            if (!empty($q['options']) && is_array($q['options'])) {
                $opts = array_values($q['options']);
                $ohash = hash('sha256', json_encode($opts, JSON_UNESCAPED_UNICODE));
                if ($this->cacheGet('options', (int)($q['id'] ?? 0), $lang, $ohash) === null) {
                    $optionGroups[] = [(int)($q['id'] ?? 0), $opts, $ohash];
                    foreach ($opts as $o) {
                        $o = (string)$o;
                        if (trim($o) !== '') $distinct[$o] = true;
                    }
                }
            }
        }

        if (empty($distinct)) {
            return; // everything already cached -- no AI call needed
        }

        // ONE batched AI call translating every distinct source string.
        $sources = array_keys($distinct);
        $translatedByText = $this->batchTranslate($sources, $lang);
        if (empty($translatedByText)) {
            return; // budget exhausted / failure -> leave English (cache untouched)
        }

        // Write prose caches.
        foreach ($prose as [$type, $id, $text, $hash]) {
            if (isset($translatedByText[$text]) && $translatedByText[$text] !== '') {
                $this->cachePut($type, $id, $lang, $hash, $translatedByText[$text]);
            }
        }
        // Write option-group caches (reassemble translated arrays by index).
        foreach ($optionGroups as [$qid, $opts, $ohash]) {
            $arr = [];
            foreach ($opts as $o) {
                $o = (string)$o;
                $arr[] = ($translatedByText[$o] ?? '') !== '' ? $translatedByText[$o] : $o;
            }
            $this->cachePut('options', $qid, $lang, $ohash, json_encode($arr, JSON_UNESCAPED_UNICODE));
        }
    }

    public function translate(string $type, int $id, ?string $text, string $lang): string
    {
        $text = (string)$text;
        if (trim($text) === '' || $lang === '' || $lang === 'en') {
            return $text;
        }
        $hash = hash('sha256', $text);
        $cached = $this->cacheGet($type, $id, $lang, $hash);
        if ($cached !== null) {
            return $cached;
        }
        $translated = $this->aiTranslateText($text, $lang);
        if ($translated === null) {
            return $text;
        }
        $this->cachePut($type, $id, $lang, $hash, $translated);
        return $translated;
    }

    public function translateOptions(int $questionId, array $options, string $lang): array
    {
        $map = [];
        foreach ($options as $opt) {
            $map[$opt] = $opt;
        }
        if (empty($options) || $lang === '' || $lang === 'en') {
            return $map;
        }
        $payload = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $payload);
        $cached = $this->cacheGet('options', $questionId, $lang, $hash);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && count($decoded) === count($options)) {
                return $this->zipOptions($options, $decoded);
            }
        }
        $translated = $this->aiTranslateArray($options, $lang);
        if ($translated === null || count($translated) !== count($options)) {
            return $map;
        }
        $this->cachePut('options', $questionId, $lang, $hash, json_encode(array_values($translated), JSON_UNESCAPED_UNICODE));
        return $this->zipOptions($options, $translated);
    }

    // ------------------------------------------------------------------ helpers

    /** True while we may still spend an AI call this request. */
    private function aiBudgetAvailable(): bool
    {
        return self::$aiCallsThisRequest < self::MAX_AI_CALLS_PER_REQUEST;
    }

    /**
     * Translate a flat list of distinct strings in one call. Returns
     * [sourceText => translatedText] (only for strings the model returned).
     */
    private function batchTranslate(array $sources, string $lang): array
    {
        if (empty($sources) || !$this->aiBudgetAvailable()) {
            return [];
        }
        try {
            $ai = AIPlatformService::getInstance();
            if (!$ai->isEnabled()) {
                return [];
            }
            // Index by number so values can contain any characters safely.
            $map = [];
            foreach (array_values($sources) as $i => $s) {
                $map[(string)$i] = $s;
            }
            $system = 'You are a professional translator for a third-party risk management (TPRM) / GRC application. '
                . 'You are given a JSON object whose values are UI strings in English. Translate every VALUE into '
                . $this->languageName($lang) . ', keeping each KEY exactly the same. Keep these terms verbatim (do not '
                . 'translate): ' . self::KEEP_VERBATIM . '. Preserve %s/%d placeholders and HTML entities. '
                . 'Return ONLY a JSON object with the same keys -- no commentary, no code fences.';

            self::$aiCallsThisRequest++;
            $result = $ai->chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($map, JSON_UNESCAPED_UNICODE)],
                ],
                ['max_tokens' => 4000, 'timeout' => self::AI_TIMEOUT, 'purpose' => 'translation']
            );
            if (empty($result['success']) || empty($result['content'])) {
                return [];
            }
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($result['content'])));
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                return [];
            }
            $out = [];
            foreach ($map as $k => $src) {
                if (isset($decoded[$k]) && is_string($decoded[$k]) && $decoded[$k] !== '') {
                    $out[$src] = $decoded[$k];
                }
            }
            return $out;
        } catch (Throwable $e) {
            error_log('AssessmentTranslationService batch translate failed: ' . $e->getMessage());
            return [];
        }
    }

    private function zipOptions(array $options, array $translated): array
    {
        $map = [];
        $i = 0;
        foreach ($options as $opt) {
            $map[$opt] = isset($translated[$i]) && $translated[$i] !== '' ? $translated[$i] : $opt;
            $i++;
        }
        return $map;
    }

    private function languageName(string $lang): string
    {
        return self::$languageNames[$lang] ?? $lang;
    }

    private function cacheGet(string $type, int $id, string $lang, string $hash): ?string
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT translated_text, source_hash FROM assessment_translations
                 WHERE source_type = :t AND source_id = :i AND language_code = :l',
                [':t' => $type, ':i' => $id, ':l' => $lang]
            );
        } catch (Exception $e) {
            return null;
        }
        if ($row && $row['source_hash'] === $hash) {
            return $row['translated_text'];
        }
        return null;
    }

    private function cachePut(string $type, int $id, string $lang, string $hash, string $text): void
    {
        try {
            $this->db->query(
                'INSERT INTO assessment_translations
                    (source_type, source_id, language_code, source_hash, translated_text)
                 VALUES (:t, :i, :l, :h, :x)
                 ON DUPLICATE KEY UPDATE source_hash = VALUES(source_hash), translated_text = VALUES(translated_text)',
                [':t' => $type, ':i' => $id, ':l' => $lang, ':h' => $hash, ':x' => $text]
            );
        } catch (Exception $e) {
            error_log('AssessmentTranslationService cache write failed: ' . $e->getMessage());
        }
    }

    private function aiTranslateText(string $text, string $lang): ?string
    {
        if (!$this->aiBudgetAvailable()) {
            return null;
        }
        try {
            $ai = AIPlatformService::getInstance();
            if (!$ai->isEnabled()) {
                return null;
            }
            $system = 'You are a professional translator for a third-party risk management (TPRM) and '
                . 'governance, risk & compliance (GRC) application. Translate the user message into '
                . $this->languageName($lang) . '. Preserve meaning, tone, and formatting. Keep these terms '
                . 'verbatim (do not translate): ' . self::KEEP_VERBATIM . '. '
                . 'Return ONLY the translated text -- no quotes, labels, or commentary.';
            self::$aiCallsThisRequest++;
            $result = $ai->chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $text],
                ],
                ['max_tokens' => 1500, 'timeout' => self::AI_TIMEOUT, 'purpose' => 'translation']
            );
            if (empty($result['success']) || empty($result['content'])) {
                return null;
            }
            return trim($result['content']);
        } catch (Throwable $e) {
            error_log('AssessmentTranslationService text translate failed: ' . $e->getMessage());
            return null;
        }
    }

    private function aiTranslateArray(array $options, string $lang): ?array
    {
        if (!$this->aiBudgetAvailable()) {
            return null;
        }
        try {
            $ai = AIPlatformService::getInstance();
            if (!$ai->isEnabled()) {
                return null;
            }
            $system = 'You are a professional translator for a TPRM/GRC application. Translate each string in '
                . 'the user JSON array into ' . $this->languageName($lang) . '. Keep these terms verbatim: '
                . self::KEEP_VERBATIM . '. Return ONLY a JSON array of strings with the same length and order '
                . 'as the input -- no commentary, no code fences.';
            self::$aiCallsThisRequest++;
            $result = $ai->chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode(array_values($options), JSON_UNESCAPED_UNICODE)],
                ],
                ['max_tokens' => 1500, 'timeout' => self::AI_TIMEOUT, 'purpose' => 'translation']
            );
            if (empty($result['success']) || empty($result['content'])) {
                return null;
            }
            $content = trim($result['content']);
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
            $decoded = json_decode($content, true);
            return is_array($decoded) ? array_values($decoded) : null;
        } catch (Throwable $e) {
            error_log('AssessmentTranslationService options translate failed: ' . $e->getMessage());
            return null;
        }
    }
}
