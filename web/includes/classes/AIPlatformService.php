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
             WHERE config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ? OR config_key LIKE ?',
            ['openwebui_%', 'librechat_%', 'ai_platform_%', 'anthropic_%']
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
        return $this->config['openwebui_jwt_token'] ?? '';
    }

    /**
     * Get the default model for the active platform.
     * LibreChat uses agent IDs; the fair_ai_model override is only
     * applicable to OpenWebUI where raw model names are supported.
     */
    public function getModel(?string $purpose = null): string {
        $platform = $this->getActivePlatform();

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
            error_log("AI platform HTTP {$httpCode}: " . substr($response, 0, 500));
            return ['success' => false, 'content' => null, 'error' => "API returned HTTP {$httpCode}"];
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
     * Call the Anthropic Messages API directly with web_search tool enabled.
     * Used for OSINT research that requires live web searching.
     * Falls back to the standard chatCompletion if no Anthropic API key is configured.
     *
     * Requires app_config keys:
     *   anthropic_api_key (encrypted) - Direct Anthropic API key
     *   anthropic_osint_model (optional) - Model to use, defaults to claude-sonnet-4-6-20250514
     */
    public function anthropicWebSearchCompletion(array $messages, array $options = []): array {
        $apiKey = $this->config['anthropic_api_key'] ?? '';

        if (empty($apiKey)) {
            // No direct Anthropic key — fall back to standard chatCompletion via LibreChat
            error_log('AIPlatformService: No anthropic_api_key configured, falling back to standard chatCompletion for OSINT');
            return $this->chatCompletion($messages, $options);
        }

        $model = $options['model'] ?? ($this->config['anthropic_osint_model'] ?? 'claude-sonnet-4-5-20250929');
        $maxTokens = $options['max_tokens'] ?? 16000;
        $temperature = $options['temperature'] ?? 0.1;
        $timeout = $options['timeout'] ?? 480;

        // Separate system message from user/assistant messages (Anthropic API format)
        $systemText = '';
        $apiMessages = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemText .= ($systemText ? "\n\n" : '') . $msg['content'];
            } else {
                $apiMessages[] = $msg;
            }
        }

        // Build Anthropic Messages API payload
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $apiMessages,
        ];

        // Only enable web_search tool for OSINT research (not breach research)
        $purpose = $options['purpose'] ?? '';
        if ($purpose === 'osint_research') {
            $payload['tools'] = [
                [
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                    'max_uses' => 50,
                ],
            ];
        }

        if (!empty($systemText)) {
            $payload['system'] = $systemText;
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if ($curlError) {
            error_log('Anthropic API cURL error: ' . $curlError);
            return ['success' => false, 'content' => null, 'error' => 'Connection failed: ' . $curlError];
        }

        if ($httpCode !== 200) {
            error_log("Anthropic API HTTP {$httpCode}: " . substr($response, 0, 500));
            return ['success' => false, 'content' => null, 'error' => "Anthropic API returned HTTP {$httpCode}"];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return ['success' => false, 'content' => null, 'error' => 'Invalid JSON response from Anthropic'];
        }

        // Anthropic Messages API returns content as an array of content blocks
        // Extract text from all text blocks (web_search results are interleaved)
        $textParts = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $textParts[] = $block['text'];
            }
        }

        $content = implode("\n", $textParts);
        if (empty($content)) {
            return ['success' => false, 'content' => null, 'error' => 'No text content in Anthropic response'];
        }

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'usage' => $data['usage'] ?? null,
        ];
    }

    /**
     * Test connection to the active AI platform.
     */
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
