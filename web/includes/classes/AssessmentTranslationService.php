<?php
/**
 * AssessmentTranslationService
 *
 * On-demand, cached machine translation of admin-authored assessment content
 * (template/section/question prose and answer-option labels) for the public
 * vendor assessment form. Translations are produced by the existing OpenWebUI
 * AI integration (AIPlatformService) and cached in the assessment_translations
 * table keyed by a SHA-256 of the source text, so each string is translated
 * once per language and re-translated automatically when an admin edits it.
 *
 * Everything degrades gracefully: if the AI platform is disabled, a call fails,
 * the cache table is missing, or the target language is English/empty, the
 * caller gets the original English text back -- the form is never left blank.
 *
 * NOTE: only answer-option LABELS are translated for display. The stored
 * response value always remains the original (English) option, so existing
 * responses, scoring, and conditional logic are unaffected.
 */
class AssessmentTranslationService
{
    private static $instance = null;
    private $db;

    /** English language names used to instruct the translation model. */
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

    /** Technical terms / product names kept verbatim across all languages. */
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

    /**
     * True only when we can actually translate into $lang: a known, non-English
     * code and an enabled AI platform.
     */
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
     * Translate a single prose string (question_text, help_text, section name,
     * etc.). Returns the original text unchanged on any miss/failure.
     */
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

    /**
     * Translate a list of answer-option labels. Returns an associative map of
     * [originalOption => translatedLabel]; any option that can't be translated
     * maps to itself, so callers can safely do ($map[$opt] ?? $opt).
     */
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
            // Table not present yet (pre-migration) -- treat as cache miss/disabled.
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
            $result = $ai->chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $text],
                ],
                ['max_tokens' => 1500, 'purpose' => 'translation']
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
        try {
            $ai = AIPlatformService::getInstance();
            if (!$ai->isEnabled()) {
                return null;
            }
            $system = 'You are a professional translator for a TPRM/GRC application. Translate each string in '
                . 'the user JSON array into ' . $this->languageName($lang) . '. Keep these terms verbatim: '
                . self::KEEP_VERBATIM . '. Return ONLY a JSON array of strings with the same length and order '
                . 'as the input -- no commentary, no code fences.';
            $result = $ai->chatCompletion(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode(array_values($options), JSON_UNESCAPED_UNICODE)],
                ],
                ['max_tokens' => 1500, 'purpose' => 'translation']
            );
            if (empty($result['success']) || empty($result['content'])) {
                return null;
            }
            $content = trim($result['content']);
            // Tolerate a model that wraps the array in a ```json code fence.
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
            $decoded = json_decode($content, true);
            return is_array($decoded) ? array_values($decoded) : null;
        } catch (Throwable $e) {
            error_log('AssessmentTranslationService options translate failed: ' . $e->getMessage());
            return null;
        }
    }
}
