<?php
/**
 * AI Platform Service - Unified OpenWebUI & LibreChat Integration
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Abstraction layer that talks to either OpenWebUI or LibreChat using the
 * same prompt logic. Both platforms expose OpenAI-compatible chat completion
 * endpoints, so the same prompts work on either. The admin configures which
 * platform is active (only one at a time), and this service handles the
 * differences in authentication and endpoint structure.
 *
 * OpenWebUI: Uses JWT bearer token auth, /api/chat/completions endpoint.
 * LibreChat: Uses API key auth, /api/agents/v1/chat/completions endpoint.
 *            Uses agent IDs as the model parameter. System messages are
 *            merged into user context since the agent has its own instructions.
 * Both accept the same { model, messages, temperature, max_tokens } payload.
 */

class AIPlatformService {
    private static $instance = null;
    private $db;
    private $encryption;
    private array $config = [];

    private function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->loadConfig();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig(): void {
        // Load all AI-related config keys
        $rows = $this->db->fetchAll(
            'SELECT config_key, config_value, is_encrypted FROM app_config
             WHERE config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ?
                OR config_key LIKE ? OR config_key LIKE ?',
            ['openwebui_%', 'librechat_%', 'custom_%', 'ai_platform_%', 'anthropic_%', 'openai_%']
        );

        foreach ($rows as $row) {
            $value = ($row['is_encrypted'] && !empty($row['config_value']))
                ? $this->encryption->decrypt($row['config_value'])
                : $row['config_value'];
            $this->config[$row['config_key']] = $value;
        }

        // Also load fair_ai_model
        $fairModel = $this->db->fetchOne(
            'SELECT config_value FROM app_config WHERE config_key = ?', ['fair_ai_model']
        );
        if ($fairModel) {
            $this->config['fair_ai_model'] = $fairModel['config_value'];
        }
    }

    /**
     * Get a config value by key.
     */
    public function getConfig(string $key): string {
        return $this->config[$key] ?? '';
    }

    /**
     * Get the active platform name.
     * @return string 'openwebui' or 'librechat'
     */
    public function getActivePlatform(): string {
        return $this->config['ai_platform_active'] ?? 'openwebui';
    }

    /**
     * Check if AI integration is enabled.
     */
    public function isEnabled(): bool {
        $platform = $this->getActivePlatform();
        if ($platform === 'disabled') {
            return false;
        }
        if ($platform === 'librechat') {
            return ($this->config['librechat_enabled'] ?? '0') === '1';
        }
        if ($platform === 'custom') {
            return ($this->config['custom_enabled'] ?? '0') === '1';
        }
        if ($platform === 'anthropic') {
            return ($this->config['anthropic_enabled'] ?? '0') === '1';
        }
        if ($platform === 'openai') {
            return ($this->config['openai_enabled'] ?? '0') === '1';
        }
        return ($this->config['openwebui_enabled'] ?? '0') === '1';
    }

    /**
     * Get the API URL for the active platform.
     */
    public function getApiUrl(): string {
        $platform = $this->getActivePlatform();
        if ($platform === 'librechat') {
            return $this->config['librechat_api_url'] ?? '';
        }
        if ($platform === 'anthropic') {
            $u = $this->config['anthropic_api_url'] ?? '';
            return $u !== '' ? $u : 'https://api.anthropic.com/v1/messages';
        }
        if ($platform === 'openai') {
            $u = $this->config['openai_api_url'] ?? '';
            return $u !== '' ? $u : 'https://api.openai.com/v1/chat/completions';
        }
        return $this->config['openwebui_api_url'] ?? '';
    }

    /**
     * Get the auth token for the active platform.
     */
    public function getAuthToken(): string {
        $platform = $this->getActivePlatform();
        if ($platform === 'librechat') {
            return $this->config['librechat_api_key'] ?? '';
        }
        if ($platform === 'anthropic') {
            return $this->config['anthropic_api_key'] ?? '';
        }
        if ($platform === 'openai') {
            return $this->config['openai_api_key'] ?? '';
        }
        return $this->config['openwebui_jwt_token'] ?? '';
    }

    /**
     * Get the default model for the active platform.
     * LibreChat uses agent IDs; the fair_ai_model override is only
     * applicable to OpenWebUI where raw model names are supported.
     */
    public function getModel(?string $purpose = null): string {
        $platform = $this->getActivePlatform();

        // Native Anthropic / OpenAI platforms carry their own model field. The
        // fair_ai_model override is an OpenWebUI raw-model convenience and must
        // not leak an incompatible model name into these APIs.
        if ($platform === 'anthropic') {
            $m = $this->config['anthropic_model'] ?? '';
            return $m !== '' ? $m : 'claude-opus-4-8';
        }
        if ($platform === 'openai') {
            $m = $this->config['openai_model'] ?? '';
            return $m !== '' ? $m : 'gpt-4o';
        }

        // fair_ai_model override only works for OpenWebUI (raw model names)
        if ($platform !== 'librechat' && ($purpose === 'fair' || $purpose === 'structured')) {
            $model = $this->config['fair_ai_model'] ?? '';
            if (!empty($model)) return $model;
        }

        if ($platform === 'librechat') {
            return $this->config['librechat_model'] ?? '';
        }
        return $this->config['openwebui_model'] ?? '';
    }

    /**
     * Get temperature setting.
     */
    public function getTemperature(): float {
        $platform = $this->getActivePlatform();
        if ($platform === 'librechat') {
            return (float)($this->config['librechat_temperature'] ?? 0.7);
        }
        if ($platform === 'openai') {
            return (float)($this->config['openai_temperature'] ?? 0.7);
        }
        if ($platform === 'anthropic') {
            // Not sent to the API (current Claude models reject sampling params);
            // returned only for completeness.
            return (float)($this->config['anthropic_temperature'] ?? 1.0);
        }
        return (float)($this->config['openwebui_temperature'] ?? 0.7);
    }

    /**
     * Get max tokens setting.
     */
    public function getMaxTokens(): int {
        $platform = $this->getActivePlatform();
        if ($platform === 'librechat') {
            return (int)($this->config['librechat_max_tokens'] ?? 500);
        }
        if ($platform === 'anthropic') {
            return (int)($this->config['anthropic_max_tokens'] ?? 4096);
        }
        if ($platform === 'openai') {
            return (int)($this->config['openai_max_tokens'] ?? 4096);
        }
        return (int)($this->config['openwebui_max_tokens'] ?? 500);
    }

    /**
     * Prepare messages for the active platform.
     * LibreChat Agents API does not allow system messages (the agent's
     * built-in instructions serve as the system prompt). Any system
     * messages are converted to a user-role context preamble.
     */
    private function prepareMessages(array $messages): array {
        if ($this->getActivePlatform() !== 'librechat') {
            return $messages;
        }

        $systemParts = [];
        $otherMessages = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = $msg['content'];
            } else {
                $otherMessages[] = $msg;
            }
        }

        if (empty($systemParts)) {
            return $messages;
        }

        // Prepend system context as a user message before the real user messages
        $contextMsg = [
            'role' => 'user',
            'content' => "Context and instructions:\n" . implode("\n\n", $systemParts)
        ];

        return array_merge([$contextMsg], $otherMessages);
    }

    /**
     * Send a chat completion request to the active AI platform.
     * This is the unified method that works with both OpenWebUI and LibreChat.
     *
     * @param array $messages Array of { role, content } message objects
     * @param array $options Override defaults: model, temperature, max_tokens
     * @return array{ success: bool, content: ?string, error: ?string, usage: ?array }
     */
    public function chatCompletion(array $messages, array $options = []): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'content' => null, 'error' => 'AI integration is not enabled'];
        }

        // Custom platform uses an admin-supplied curl-style template; dispatch separately.
        if ($this->getActivePlatform() === 'custom') {
            return $this->customChatCompletion($messages, $options);
        }

        // Native Anthropic (Claude) and OpenAI (ChatGPT) platforms each speak
        // their own wire format (and ground breach/OSINT scans via a built-in
        // web-search tool), so they dispatch separately too.
        if ($this->getActivePlatform() === 'anthropic') {
            return $this->anthropicChatCompletion($messages, $options);
        }
        if ($this->getActivePlatform() === 'openai') {
            return $this->openaiChatCompletion($messages, $options);
        }

        $apiUrl = $this->getApiUrl();
        $authToken = $this->getAuthToken();
        $model = $options['model'] ?? $this->getModel($options['purpose'] ?? null);
        $temperature = $options['temperature'] ?? $this->getTemperature();
        $maxTokens = $options['max_tokens'] ?? $this->getMaxTokens();

        if (empty($apiUrl) || empty($authToken)) {
            return ['success' => false, 'content' => null, 'error' => 'AI platform not configured'];
        }

        // Adapt messages for the active platform
        $messages = $this->prepareMessages($messages);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];

        // LibreChat agents manage their own sampling parameters (temperature/top_p/top_k)
        // via the agent config. Sending temperature from our side conflicts with top_p
        // set in the agent, causing Anthropic to reject the request. Only send temperature
        // for non-LibreChat platforms (OpenWebUI).
        if ($this->getActivePlatform() !== 'librechat') {
            $payload['temperature'] = $temperature;
        }

        // Both platforms accept the same JSON payload format
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log('AI platform cURL error: ' . $curlError);
            return ['success' => false, 'content' => null, 'error' => 'Connection failed: ' . $curlError];
        }

        if ($httpCode !== 200) {
            error_log("AI platform HTTP {$httpCode}: " . substr((string)$response, 0, 500));
            // Surface the platform's own error detail (e.g. OpenWebUI's
            // {"detail":"..."} for an invalid token or unknown model) so the
            // operator can see *why* a request failed, not just the status code.
            $detail = '';
            $decoded = json_decode((string)$response, true);
            if (is_array($decoded)) {
                $detail = is_string($decoded['detail'] ?? null)
                    ? $decoded['detail']
                    : ($decoded['error']['message'] ?? ($decoded['message'] ?? ''));
            }
            if ($detail === '') {
                $detail = trim(strip_tags((string)$response));
            }
            if (strlen($detail) > 300) {
                $detail = substr($detail, 0, 300) . '…';
            }
            $error = "API returned HTTP {$httpCode}";
            if ($detail !== '') {
                $error .= ': ' . $detail;
            }
            return ['success' => false, 'content' => null, 'error' => $error];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return ['success' => false, 'content' => null, 'error' => 'Invalid JSON response'];
        }

        // Both platforms return OpenAI-compatible response format
        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            return ['success' => false, 'content' => null, 'error' => 'No content in response'];
        }

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'usage' => $data['usage'] ?? null,
        ];
    }

    /**
     * Dispatch a chat completion via the admin-supplied custom curl template.
     *
     * The admin pastes a block like:
     *     -H 'Content-Type: application/json'
     *     -H 'x-app-id: ...'
     *     -H 'x-app-secret: ...'
     *     --data-raw '{"model_class":"strong-fast","input_text":"SAMPLE"}'
     *
     * We extract -H lines as request headers, pull the --data-raw '...' body
     * template, JSON-string-escape the flattened prompt, and substitute it
     * for the literal token "SAMPLE" inside the body before sending.
     *
     * Response parsing prefers FairTPRM Orchestrator's top-level output_text,
     * then falls back to OpenAI-compatible choices[0].message.content for
     * other custom APIs.
     */
    private function customChatCompletion(array $messages, array $options): array {
        $apiUrl      = $this->config['custom_api_url'] ?? '';
        $headersBlob = $this->config['custom_headers']  ?? '';

        if (empty($apiUrl)) {
            return ['success' => false, 'content' => null, 'error' => 'Custom AI: API URL not configured'];
        }
        if (empty($headersBlob)) {
            return ['success' => false, 'content' => null, 'error' => 'Custom AI: headers/body template not configured'];
        }

        $httpHeaders = $this->buildCustomHeaders($headersBlob);

        // Web grounding: when the caller provides search_queries (e.g., breach
        // research), hit the orchestrator's /v1/safe-search for each query and
        // prepend the joined results as a user-role context message. The model
        // gets the same kind of fresh evidence a LibreChat agent would gather
        // from its built-in browsing tool, but we stay entirely on Custom.
        //
        // search_max_age_days (optional): drop search hits older than N days
        // before the model sees them. Filtering at the input is more reliable
        // than asking the model to honor a date rule — observed failure mode
        // was Haiku reporting months-old breaches because they appeared in
        // search results despite a 7-day prompt rule.
        if (!empty($options['search_queries']) && is_array($options['search_queries'])) {
            $searchUrl = $this->getCustomSearchUrl();
            if ($searchUrl === '') {
                error_log('Custom AI: search_queries supplied but no safe-search URL is configured or derivable; proceeding without web grounding.');
            } else {
                $maxResults = (int)($options['search_max_results']  ?? 8);
                $maxAgeDays = (int)($options['search_max_age_days'] ?? 0);
                $context = $this->fetchCustomSearchContext($searchUrl, $httpHeaders, $options['search_queries'], $maxResults, $maxAgeDays);

                $insertAt = 0;
                foreach ($messages as $i => $m) {
                    if (($m['role'] ?? 'user') !== 'system') { $insertAt = $i; break; }
                    $insertAt = $i + 1;
                }
                $ageNote = $maxAgeDays > 0
                    ? "pre-filtered to articles published in the last {$maxAgeDays} days"
                    : 'unfiltered';
                // Always inject the preamble — even when the context is empty
                // after filtering — so the "use ONLY these results, do not
                // recall from training data" instruction is always in scope.
                $body = $context !== ''
                    ? $context
                    : '(No results within the time window. Return an empty result set; do NOT recall historical incidents from training data.)';
                array_splice($messages, $insertAt, 0, [[
                    'role' => 'user',
                    'content' => "[Web Search Results — {$ageNote}. Use ONLY URLs and facts that appear below; do not invent sources or dates and do not recall content from your training data. If the results are empty or do not contain a credible incident matching the request, return an empty result set — do NOT fall back to historical incidents from memory.]\n\n" . $body,
                ]]);
            }
        }

        // Extract --data-raw '...' body template. Greedy to last quote on a
        // trailing line, with /s for multiline JSON.
        if (!preg_match("/--data-raw\s+'([\s\S]*?)'\s*\\\\?\s*$/", $headersBlob, $bm)) {
            return ['success' => false, 'content' => null, 'error' => "Custom AI: --data-raw '...' body template not found"];
        }
        $bodyTemplate = $bm[1];

        // Flatten the messages array into a single prompt string. System
        // messages get a [System] preamble; user/assistant content is joined
        // verbatim. This mirrors how prepareMessages() folds system context
        // for LibreChat agents.
        $promptParts = [];
        foreach ($messages as $msg) {
            $role    = $msg['role']    ?? 'user';
            $content = $msg['content'] ?? '';
            if ($content === '' || !is_string($content)) continue;
            if ($role === 'system') {
                $promptParts[] = "[System]\n" . $content;
            } else {
                $promptParts[] = $content;
            }
        }
        $prompt = implode("\n\n", $promptParts);

        // JSON-string-escape so the prompt is safe to drop inside a JSON
        // string literal (handles quotes, backslashes, newlines, unicode).
        // json_encode wraps in quotes; strip the surrounding pair.
        $jsonEncoded = json_encode($prompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $escaped     = ($jsonEncoded === false) ? '' : substr($jsonEncoded, 1, -1);

        // Substitute SAMPLE token. str_replace touches every occurrence so
        // templates can echo the prompt in multiple JSON fields if desired.
        $body = str_replace('SAMPLE', $escaped, $bodyTemplate);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $options['timeout'] ?? 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $httpHeaders);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log('Custom AI cURL error: ' . $curlError);
            return ['success' => false, 'content' => null, 'error' => 'Connection failed: ' . $curlError];
        }
        if ($httpCode !== 200) {
            error_log("Custom AI HTTP {$httpCode}: " . substr((string)$response, 0, 500));
            return ['success' => false, 'content' => null, 'error' => "Custom AI returned HTTP {$httpCode}"];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['success' => false, 'content' => null, 'error' => 'Custom AI: invalid JSON response'];
        }

        // FairTPRM Orchestrator returns output_text at the top level. Fall back
        // to OpenAI-compatible chat completion shape so this dispatcher also
        // works against other custom endpoints.
        $content = $data['output_text']
            ?? $data['choices'][0]['message']['content']
            ?? null;

        if ($content === null || $content === '') {
            return ['success' => false, 'content' => null, 'error' => 'Custom AI: no content in response'];
        }

        return [
            'success' => true,
            'content' => $content,
            'error'   => null,
            'usage'   => $data['usage'] ?? null,
        ];
    }

    /**
     * Parse the curl-style headers blob into the array libcurl expects, with
     * the encrypted secret header spliced back in at request time. Shared by
     * the inference call and any safe-search calls so they authenticate the
     * same way against the orchestrator.
     */
    private function buildCustomHeaders(string $headersBlob): array {
        $httpHeaders = [];
        if (preg_match_all("/-H\s+'([^']+)'/", $headersBlob, $hm)) {
            foreach ($hm[1] as $line) {
                $httpHeaders[] = $line;
            }
        }

        $secretName  = trim($this->config['custom_secret_header_name']  ?? '');
        $secretValue = $this->config['custom_secret_header_value']      ?? '';
        if ($secretName !== '' && $secretValue !== '') {
            $secretNameLower = strtolower($secretName);
            $httpHeaders = array_values(array_filter($httpHeaders, function ($h) use ($secretNameLower) {
                $colon = strpos($h, ':');
                if ($colon === false) return true;
                return strtolower(trim(substr($h, 0, $colon))) !== $secretNameLower;
            }));
            $httpHeaders[] = $secretName . ': ' . $secretValue;
        }
        return $httpHeaders;
    }

    /**
     * Resolve the safe-search endpoint for the Custom platform. Admins can set
     * `custom_search_url` explicitly; otherwise we auto-derive it by swapping
     * `/v1/infer` for `/v1/safe-search` on the configured API URL (the FairTPRM
     * orchestrator convention). Returns '' if neither is available — callers
     * should fall back to inference-only when this is empty.
     */
    private function getCustomSearchUrl(): string {
        $explicit = trim($this->config['custom_search_url'] ?? '');
        if ($explicit !== '') return $explicit;

        $apiUrl = trim($this->config['custom_api_url'] ?? '');
        if ($apiUrl === '') return '';

        if (preg_match('#^(https?://[^/]+)/v1/infer/?$#i', $apiUrl, $m)) {
            return $m[1] . '/v1/safe-search';
        }
        return '';
    }

    /**
     * Run each search query against the orchestrator's /v1/safe-search and
     * concatenate the result_text blocks. Best-effort: a failed query is
     * logged and skipped so a transient orchestrator hiccup can't block a
     * whole breach scan. Returns '' if every query failed.
     *
     * When $maxAgeDays > 0, each result_text is parsed and entries older than
     * the threshold (or with no parseable Age) are dropped before the model
     * sees them.
     */
    private function fetchCustomSearchContext(string $searchUrl, array $httpHeaders, array $queries, int $maxResults, int $maxAgeDays = 0): string {
        $blocks = [];
        foreach ($queries as $q) {
            if (!is_string($q) || trim($q) === '') continue;
            $payload = json_encode(['query' => $q, 'max_results' => $maxResults], JSON_UNESCAPED_SLASHES);

            $ch = curl_init($searchUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     $httpHeaders);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError || $httpCode !== 200) {
                error_log("Custom AI safe-search [{$q}]: HTTP {$httpCode} " . ($curlError ?: substr((string)$response, 0, 300)));
                continue;
            }
            $data = json_decode((string)$response, true);
            $text = $data['result_text'] ?? '';
            if (!is_string($text) || $text === '') continue;

            if ($maxAgeDays > 0) {
                $text = $this->filterSearchResultsByAge($text, $maxAgeDays);
                if ($text === '') continue;
            }

            $blocks[] = "Query: {$q}\n{$text}";
        }
        return implode("\n\n---\n\n", $blocks);
    }

    /**
     * Drop entries older than $maxAgeDays from a /v1/safe-search result_text
     * block. The orchestrator emits each hit as a `[N] Title` block followed
     * by indented `URL:`, `Age:`, and snippet lines; entries are blank-line
     * separated, wrapped between `--- WEB SEARCH RESULTS ---` markers.
     *
     * Entries with no `Age:` line are dropped — without a date we can't tell
     * whether they fall in window, and the user-reported failure mode was
     * the model citing undated/old hits as if they were current. Better to
     * lose a hit than feed the model misleading evidence.
     */
    private function filterSearchResultsByAge(string $resultText, int $maxAgeDays): string {
        $kept = [];
        // Split on blank lines and keep only chunks that look like numbered hits.
        foreach (preg_split('/\n\n+/', $resultText) as $block) {
            $block = rtrim($block);
            if ($block === '' || !preg_match('/^\[\d+\]\s/', $block)) continue;
            if (!preg_match('/^\s*Age:\s*(.+?)\s*$/m', $block, $m)) continue;
            $days = $this->parseAgeToDays(trim($m[1]));
            if ($days === null || $days > $maxAgeDays) continue;
            $kept[] = $block;
        }

        if (empty($kept)) return '';

        // Renumber so the model sees a clean sequence after filtering.
        $renumbered = [];
        foreach ($kept as $i => $entry) {
            $renumbered[] = preg_replace('/^\[\d+\]/', '[' . ($i + 1) . ']', $entry, 1);
        }

        return "--- WEB SEARCH RESULTS (filtered to last {$maxAgeDays} days) ---\n\n"
             . implode("\n\n", $renumbered)
             . "\n\n--- END SEARCH RESULTS ---";
    }

    /**
     * Convert the orchestrator's Age field to whole days since publication.
     * Handles relative phrases ("3 days ago", "1 month", "2 weeks ago",
     * "1 hour ago") and absolute dates ("October 31, 2025", "March 3, 2026").
     * Returns null when the format isn't recognized — caller treats null as
     * "drop the entry" rather than "keep an undated hit."
     */
    private function parseAgeToDays(string $ageStr): ?int {
        $s = trim($ageStr);
        if ($s === '') return null;

        // Sub-day buckets — always within window.
        if (preg_match('/^(today|just now|moments? ago)\b/i', $s)) return 0;
        if (preg_match('/^\d+\s+(seconds?|minutes?|hours?)\b/i', $s)) return 0;

        if (preg_match('/^(\d+)\s+days?\b/i', $s, $m))   return (int)$m[1];
        if (preg_match('/^(\d+)\s+weeks?\b/i', $s, $m))  return (int)$m[1] * 7;
        // Months/years are coarse but always exceed any week-scale window
        // we'd actually filter on, so the approximation is safe.
        if (preg_match('/^(\d+)\s+months?\b/i', $s, $m)) return (int)$m[1] * 30;
        if (preg_match('/^(\d+)\s+years?\b/i', $s, $m))  return (int)$m[1] * 365;

        // Absolute date — fall back to PHP's date parser. Day-precision math
        // avoids timezone drift between the orchestrator and the PHP host.
        $ts = strtotime($s);
        if ($ts !== false && $ts > 0) {
            $today = strtotime(date('Y-m-d'));
            $pub   = strtotime(date('Y-m-d', $ts));
            $days  = (int)floor(($today - $pub) / 86400);
            return max(0, $days);
        }
        return null;
    }

    /**
     * Test connection to the active AI platform.
     */
    /**
     * Query the active platform for the list of models it exposes.
     *
     * Both OpenWebUI and OpenAI-compatible backends serve a model list at the
     * sibling "/models" path of the chat-completions endpoint, authenticated
     * with the same bearer token. We derive that URL from the configured chat
     * URL so the admin only ever maintains one URL.
     *
     * @return array{ success: bool, models: array<int,array{id:string,name:string}>, error: ?string }
     */
    public function fetchAvailableModels(?string $platform = null): array {
        // Panel-aware: callers pass the platform whose model list they want (the
        // box the admin is editing), so "Load Models" follows the panel rather
        // than the saved active platform. Falls back to the active platform.
        if ($platform === null || $platform === '') {
            $platform = $this->getActivePlatform();
        }
        if ($platform === 'custom') {
            return ['success' => false, 'models' => [], 'error' => 'Model listing is not available for the Custom platform.'];
        }
        if ($platform === 'anthropic') {
            // Anthropic's models endpoint uses x-api-key + anthropic-version, not
            // the OpenAI bearer + /chat/completions→/models derivation below.
            return ['success' => false, 'models' => [], 'error' => 'Auto-listing is not available for Anthropic — type the model name (e.g. claude-opus-4-8).'];
        }

        // OpenAI-compatible /models listing for OpenWebUI / LibreChat / OpenAI.
        // Resolve the URL + token for the requested platform from saved config.
        switch ($platform) {
            case 'librechat':
                $apiUrl    = $this->config['librechat_api_url'] ?? '';
                $authToken = $this->config['librechat_api_key'] ?? '';
                break;
            case 'openai':
                $apiUrl    = ($this->config['openai_api_url'] ?? '') ?: 'https://api.openai.com/v1/chat/completions';
                $authToken = $this->config['openai_api_key'] ?? '';
                break;
            default: // openwebui
                $apiUrl    = $this->config['openwebui_api_url'] ?? '';
                $authToken = $this->config['openwebui_jwt_token'] ?? '';
                break;
        }
        if (empty($apiUrl) || empty($authToken)) {
            return ['success' => false, 'models' => [], 'error' => 'Set the API URL and token (and Save) before loading models.'];
        }

        // Derive ".../models" from ".../chat/completions"; fall back to appending.
        $modelsUrl = preg_replace('#/chat/completions/?$#i', '/models', $apiUrl);
        if ($modelsUrl === $apiUrl) {
            $modelsUrl = rtrim($apiUrl, '/') . '/models';
        }

        $ch = curl_init($modelsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $authToken,
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'models' => [], 'error' => 'Connection failed: ' . $curlError];
        }
        if ($httpCode !== 200) {
            $detail = '';
            $decoded = json_decode((string)$response, true);
            if (is_array($decoded)) {
                $detail = is_string($decoded['detail'] ?? null)
                    ? $decoded['detail']
                    : ($decoded['error']['message'] ?? ($decoded['message'] ?? ''));
            }
            if ($detail === '') { $detail = trim(strip_tags((string)$response)); }
            if (strlen($detail) > 200) { $detail = substr($detail, 0, 200) . '…'; }
            $msg = "Models request returned HTTP {$httpCode}";
            if ($detail !== '') { $msg .= ': ' . $detail; }
            return ['success' => false, 'models' => [], 'error' => $msg];
        }

        $data = json_decode((string)$response, true);
        // OpenAI/OpenWebUI shape: { "data": [ { "id": ..., "name": ... }, ... ] }
        $list = $data['data'] ?? (is_array($data) ? $data : []);
        $models = [];
        $seen = [];
        foreach ((array)$list as $m) {
            if (is_array($m) && !empty($m['id'])) {
                $id = (string)$m['id'];
                $name = (string)($m['name'] ?? $id);
            } elseif (is_string($m) && $m !== '') {
                $id = $name = $m;
            } else {
                continue;
            }
            if (isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $models[] = ['id' => $id, 'name' => $name];
        }
        usort($models, fn($a, $b) => strcasecmp($a['id'], $b['id']));

        if (empty($models)) {
            return ['success' => false, 'models' => [], 'error' => 'The platform returned no models.'];
        }
        return ['success' => true, 'models' => $models, 'error' => null];
    }

    /**
     * Native Anthropic (Claude) Messages API dispatch.
     *
     * Differs from the OpenAI-compatible platforms: auth is the x-api-key
     * header (not a bearer), the endpoint is /v1/messages, system prompts are a
     * top-level field (not a message role), the response is a content[] block
     * array (not choices[]), and sampling params are rejected by current models
     * so we omit temperature. When the caller supplies search_queries
     * (breach/OSINT research) we attach Anthropic's server-side web_search tool
     * so Claude grounds on live, cited results — this is what makes Breach
     * Alerts work natively, with no external safe-search shim. The server runs
     * the search loop and may emit stop_reason "pause_turn"; we resend the
     * accumulated assistant turn to let it resume, up to a small bound.
     */
    private function anthropicChatCompletion(array $messages, array $options): array {
        $apiUrl = $this->getApiUrl();
        $apiKey = $this->getAuthToken();
        $model  = $options['model'] ?? $this->getModel($options['purpose'] ?? null);
        $maxTokens = (int)($options['max_tokens'] ?? $this->getMaxTokens());
        if ($maxTokens < 1) { $maxTokens = 4096; }

        if (empty($apiUrl) || empty($apiKey)) {
            return ['success' => false, 'content' => null, 'error' => 'Anthropic: API URL or key not configured'];
        }

        // Split system messages into the top-level "system" field; the rest stay
        // as user/assistant turns (Anthropic rejects a system role in messages[]).
        $systemParts = [];
        $apiMessages = [];
        foreach ($messages as $msg) {
            $role    = $msg['role']    ?? 'user';
            $content = $msg['content'] ?? '';
            if (!is_string($content) || $content === '') { continue; }
            if ($role === 'system') {
                $systemParts[] = $content;
            } else {
                $apiMessages[] = ['role' => ($role === 'assistant' ? 'assistant' : 'user'), 'content' => $content];
            }
        }
        if (empty($apiMessages)) {
            $apiMessages[] = ['role' => 'user', 'content' => ' '];
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $apiMessages,
        ];
        if (!empty($systemParts)) {
            $payload['system'] = implode("\n\n", $systemParts);
        }

        // Grounded research: enable Claude's server-side web search so it can
        // cite live sources. Triggered by the same search_queries signal the
        // Custom platform uses, so Breach/OSINT scans light it up automatically.
        if (!empty($options['search_queries'])) {
            $payload['tools'] = [[
                'type' => 'web_search_20260209',
                'name' => 'web_search',
            ]];
        }

        $headers = [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];

        $timeout   = (int)($options['timeout'] ?? 120);
        $maxRounds = 6;
        $carry     = null; // accumulated assistant content blocks for pause_turn resume

        for ($round = 0; $round < $maxRounds; $round++) {
            $payload['messages'] = ($carry === null)
                ? $apiMessages
                : array_merge($apiMessages, [['role' => 'assistant', 'content' => $carry]]);

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError) {
                error_log('Anthropic cURL error: ' . $curlError);
                return ['success' => false, 'content' => null, 'error' => 'Connection failed: ' . $curlError];
            }
            if ($httpCode !== 200) {
                error_log("Anthropic HTTP {$httpCode}: " . substr((string)$response, 0, 500));
                $detail  = '';
                $decoded = json_decode((string)$response, true);
                if (is_array($decoded)) {
                    $detail = $decoded['error']['message'] ?? ($decoded['message'] ?? '');
                }
                if ($detail === '') { $detail = trim(strip_tags((string)$response)); }
                if (strlen($detail) > 300) { $detail = substr($detail, 0, 300) . '…'; }
                $err = "API returned HTTP {$httpCode}";
                if ($detail !== '') { $err .= ': ' . $detail; }
                return ['success' => false, 'content' => null, 'error' => $err];
            }

            $data = json_decode((string)$response, true);
            if (!is_array($data)) {
                return ['success' => false, 'content' => null, 'error' => 'Invalid JSON response'];
            }

            $stopReason    = $data['stop_reason'] ?? null;
            $contentBlocks = (array)($data['content'] ?? []);

            if ($stopReason === 'refusal') {
                return ['success' => false, 'content' => null, 'error' => 'Anthropic declined the request (refusal).'];
            }

            // pause_turn: server-tool loop hit its per-turn cap. Accumulate the
            // assistant content and resend so it resumes (no extra user turn).
            if ($stopReason === 'pause_turn' && !empty($contentBlocks)) {
                $carry = array_merge((array)($carry ?? []), $contentBlocks);
                continue;
            }

            // Terminal turn: concatenate text blocks across all rounds; skip
            // server_tool_use / web_search_tool_result / thinking blocks.
            $allBlocks = array_merge((array)($carry ?? []), $contentBlocks);
            $text = '';
            foreach ($allBlocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $text .= $block['text'];
                }
            }
            $text = trim($text);
            if ($text === '') {
                return ['success' => false, 'content' => null, 'error' => 'No content in response'];
            }

            return [
                'success' => true,
                'content' => $text,
                'error'   => null,
                'usage'   => $data['usage'] ?? null,
            ];
        }

        return ['success' => false, 'content' => null, 'error' => 'Anthropic: exceeded web-search continuation rounds'];
    }

    /**
     * Native OpenAI (ChatGPT) chat-completions dispatch.
     *
     * Standard OpenAI shape: bearer auth, /v1/chat/completions, choices[] back.
     * For grounded research (search_queries present) we switch to a web-search
     * -capable model (openai_search_model, default gpt-4o-search-preview) and
     * attach web_search_options so Breach/OSINT scans get live, cited results.
     * The search-preview models reject temperature, so it is omitted there.
     */
    private function openaiChatCompletion(array $messages, array $options): array {
        $apiUrl = $this->getApiUrl();
        $apiKey = $this->getAuthToken();
        $maxTokens = (int)($options['max_tokens'] ?? $this->getMaxTokens());
        if ($maxTokens < 1) { $maxTokens = 4096; }

        if (empty($apiUrl) || empty($apiKey)) {
            return ['success' => false, 'content' => null, 'error' => 'OpenAI: API URL or key not configured'];
        }

        $useSearch = !empty($options['search_queries']);
        if ($useSearch) {
            $searchModel = $this->config['openai_search_model'] ?? '';
            $model = $searchModel !== '' ? $searchModel : 'gpt-4o-search-preview';
        } else {
            $model = $options['model'] ?? $this->getModel($options['purpose'] ?? null);
        }

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ];
        if ($useSearch) {
            // Built-in web search for the search-preview models. These models
            // reject temperature, so it is intentionally omitted on this path.
            $payload['web_search_options'] = new stdClass();
        } else {
            $payload['temperature'] = $options['temperature'] ?? $this->getTemperature();
        }

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($options['timeout'] ?? 120));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log('OpenAI cURL error: ' . $curlError);
            return ['success' => false, 'content' => null, 'error' => 'Connection failed: ' . $curlError];
        }
        if ($httpCode !== 200) {
            error_log("OpenAI HTTP {$httpCode}: " . substr((string)$response, 0, 500));
            $detail  = '';
            $decoded = json_decode((string)$response, true);
            if (is_array($decoded)) {
                $detail = $decoded['error']['message'] ?? ($decoded['message'] ?? '');
            }
            if ($detail === '') { $detail = trim(strip_tags((string)$response)); }
            if (strlen($detail) > 300) { $detail = substr($detail, 0, 300) . '…'; }
            $err = "API returned HTTP {$httpCode}";
            if ($detail !== '') { $err .= ': ' . $detail; }
            return ['success' => false, 'content' => null, 'error' => $err];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['success' => false, 'content' => null, 'error' => 'Invalid JSON response'];
        }
        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null || $content === '') {
            return ['success' => false, 'content' => null, 'error' => 'No content in response'];
        }
        return [
            'success' => true,
            'content' => $content,
            'error'   => null,
            'usage'   => $data['usage'] ?? null,
        ];
    }

    public function testConnection(): array {
        $result = $this->chatCompletion([
            ['role' => 'user', 'content' => 'Say "Connection successful" and nothing else.']
        ], ['max_tokens' => 20]);

        return [
            'platform' => $this->getActivePlatform(),
            'success' => $result['success'],
            'message' => $result['success'] ? 'Connected successfully' : ($result['error'] ?? 'Unknown error'),
            'model' => $this->getModel(),
        ];
    }
}
