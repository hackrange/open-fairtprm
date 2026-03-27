<?php
/**
 * Configuration Manager - The Settings Librarian
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * Loads settings from config.php and makes them available throughout the app
 * via dot-notation access (e.g., 'database.host'). Validates that all required
 * sections exist so you find out about missing config at boot time instead of
 * three hours into production when some random feature blows up. Uses singleton
 * pattern because loading the config file 47 times per request would be silly.
 * Think of it as the settings junk drawer, but organized. With a lock on it.
 */

class Config {
    // Singleton instance -- one config to rule them all
    private static $instance = null;

    // The actual config data -- a big nested associative array
    private $config = [];

    // Where the config file lives on disk
    private $configPath;

    /**
     * Private constructor. Figures out where config.php lives
     * (two directories up from this file, in /config/) and loads it.
     * If the file doesn't exist or is malformed, we throw immediately.
     * Better to fail fast than to limp along with missing settings.
     */
    private function __construct() {
        // Navigate up from includes/classes/ to project root, then into config/
        $this->configPath = dirname(__DIR__, 2) . '/config/config.php';
        $this->loadConfig();
    }

    /**
     * Singleton accessor. First call loads the config, every subsequent
     * call just returns the cached instance. No file I/O after the first hit.
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load the config file from disk.
     *
     * The config file is expected to be a PHP file that returns an associative
     * array. If it doesn't exist, we yell about it and suggest copying from
     * the sample. If it exists but returns something weird, we yell about that
     * too. After loading, we validate the contents.
     */
    private function loadConfig() {
        // First things first -- does the file actually exist?
        if (!file_exists($this->configPath)) {
            throw new Exception('Configuration file not found. Please copy config.sample.php to config.php and configure it.');
        }

        // require the file -- it should return an array
        $this->config = require $this->configPath;

        // Sanity check: did we actually get an array back?
        if (!is_array($this->config)) {
            throw new Exception('Invalid configuration file format.');
        }

        // Make sure all the important sections are present
        $this->validateConfig();
    }

    /**
     * Validate that the config has all required sections and that
     * placeholder values have been replaced.
     *
     * Checks for: database, encryption, auth, security, app sections.
     * Also catches the classic "I deployed without changing the default
     * encryption key and database password" mistake. These are the
     * config equivalent of leaving your house keys under the doormat.
     */
    private function validateConfig() {
        // These sections MUST exist or we can't function
        $required = ['database', 'encryption', 'auth', 'security', 'app'];

        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new Exception("Missing required configuration section: {$key}");
            }
        }

        // Catch the "oops I forgot to change the defaults" scenario
        if ($this->config['encryption']['key'] === 'CHANGE_THIS_TO_A_RANDOM_32_BYTE_BASE64_STRING') {
            throw new Exception('Please set a secure encryption key in config.php');
        }

        if ($this->config['database']['password'] === 'CHANGE_THIS_PASSWORD') {
            throw new Exception('Please set database password in config.php');
        }
    }

    /**
     * Get a config value using dot notation.
     *
     * Pass 'database.host' and it'll drill down into $config['database']['host'].
     * Returns $default if any key in the chain doesn't exist, so you can do
     * things like $config->get('feature.flag.that.might.not.exist', false)
     * without worrying about undefined index errors.
     *
     * This is basically the most-called method in the entire app.
     *
     * @param string $key Dot-notation key (e.g., 'database.host', 'auth.session_lifetime')
     * @param mixed $default Fallback value if the key doesn't exist
     * @return mixed The config value, or the default
     */
    public function get($key, $default = null) {
        // Split the dot-notation key into segments and walk the array
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set a config value at runtime using dot notation.
     *
     * Lets you override config values on the fly. Creates intermediate
     * array keys if they don't exist. Note: this only affects the in-memory
     * config -- it does NOT write back to config.php. So your changes
     * disappear when the request ends. Handy for testing and overrides.
     *
     * @param string $key Dot-notation key
     * @param mixed $value The value to set
     */
    public function set($key, $value) {
        $keys = explode('.', $key);
        // Use a reference so we can drill down and modify in-place
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * Get the entire config array. All of it. The whole enchilada.
     * Useful for debugging or when you need to dump everything at once.
     *
     * @return array The complete config array
     */
    public function all() {
        return $this->config;
    }

    /**
     * Check if a config key exists (is not null).
     * Quick way to ask "is this setting defined?" without caring about its value.
     *
     * @param string $key Dot-notation key
     * @return bool True if the key exists and is not null
     */
    public function has($key) {
        return $this->get($key) !== null;
    }

    // No cloning -- one config, one truth
    private function __clone() {}

    // No unserializing -- config should be loaded fresh each time
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
