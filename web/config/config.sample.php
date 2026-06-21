<?php
/**
 * Application Configuration Template
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This is the config TEMPLATE -- aka the "fill in the blanks" version.
 * Copy this bad boy to config.php and replace all the placeholder values
 * with your real credentials. Then guard config.php with your life because
 * it'll have all your database passwords, encryption keys, and other secrets
 * that would make a hacker's day if they ever got a hold of them.
 *
 * DO NOT commit config.php to version control. Seriously. Don't.
 * This sample file is fine to commit -- it's just a template with fake values.
 *
 * Quick setup:
 *   cp config.sample.php config.php
 *   openssl rand -base64 32   (for the encryption key)
 *   chmod 600 config.php      (lock it down)
 */

return [
    // =========================================================================
    // DATABASE CONFIGURATION
    // The stuff that connects you to your data. Get these wrong and you'll be
    // staring at "connection refused" errors wondering what you did to deserve this.
    // =========================================================================
    'database' => [
        'host' => 'localhost',          // Usually localhost unless you're fancy
        'port' => 3306,                 // MySQL default port. Change if you're hiding.
        'database' => 'tprm',          // The database name -- tprm = Third Party Risk Management
        'username' => 'tprm_user',     // DB username -- please don't use 'root' in prod
        'password' => 'CHANGE_THIS_PASSWORD',  // Change this. Seriously. Right now.
        'charset' => 'utf8mb4',        // utf8mb4 because emojis in vendor names are a thing now
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Throw exceptions on DB errors -- fail loud
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // Associative arrays by default, like civilized people
            PDO::ATTR_EMULATE_PREPARES => false,                 // Real prepared statements, not fake ones
            (class_exists('Pdo\Mysql') ? Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    ],

    // =========================================================================
    // ENCRYPTION CONFIGURATION
    // This is how we encrypt sensitive data at rest (API keys, breach costs, etc.)
    // If you lose this key, you lose access to all encrypted data. No pressure.
    // =========================================================================
    'encryption' => [
        'key' => 'CHANGE_THIS_TO_A_RANDOM_32_BYTE_BASE64_STRING',  // Run: openssl rand -base64 32
        'cipher' => 'AES-256-CBC',   // The gold standard. Don't downgrade this.
        'digest' => 'sha256'          // Hash algorithm for HMAC verification
    ],

    // =========================================================================
    // AUTHENTICATION CONFIGURATION
    // Controls how users log in and how we punish brute-force attempts.
    // =========================================================================
    'auth' => [
        'type' => 'local', // 'local' = username/password in our DB, 'saml' = SSO via OKTA/etc.
        // When true AND SAML is configured/enabled, the local username/password form is hidden
        // and /login.php returns 404. If SAML is NOT configured, this flag is ignored so admins
        // can still get in via local login (lockout safety net).
        'force_saml' => false,
        // Break-glass override for the database `local_login_enabled` toggle (admin > SAML).
        // Lets you control local login from this file alone -- no SQL, no admin UI -- which is
        // handy as an anti-lockout safety net when SSO is misbehaving.
        //   true  = local username/password login is ALWAYS available, alongside SSO (both on)
        //   false = SSO-only mode (only the break-glass admin may use local login)
        // When this key is present it WINS over the database setting. Comment it out (or remove
        // it) to defer to the database toggle, which is the default behavior. SAML must actually
        // be configured for this to matter; with no SAML, local login is always available anyway.
        'local_login_enabled' => true,
        'session_lifetime' => 3600, // 1 hour in seconds. Adjust for paranoia level.
        'session_name' => '__Host-tprm_session',  // __Host- prefix enforces Secure, no Domain, Path=/
        'max_login_attempts' => 5,         // 5 strikes and you're locked out
        'lockout_duration' => 1800,        // 30 minutes in the penalty box

        // Password rules -- because "password123" is not a password
        'password_requirements' => [
            'min_length' => 12,              // 12 chars minimum. Sorry, "hunter2" fans.
            'require_uppercase' => true,     // At least one BIG letter
            'require_lowercase' => true,     // At least one smol letter
            'require_numbers' => true,       // Gotta have digits
            'require_special_chars' => true  // !@#$%^&* -- the whole circus
        ],

        // TOTP (Time-based One-Time Password) aka those 6-digit codes from your authenticator app
        'totp' => [
            'enabled' => true,             // MFA is on by default because we're not savages
            'issuer' => 'TPRM FAIR Analysis',  // Shows up in authenticator apps
            'digits' => 6,                 // Standard 6-digit codes
            'period' => 30,                // New code every 30 seconds
            'algorithm' => 'sha1'          // SHA1 for TOTP compatibility (this is fine for TOTP)
        ]
    ],

    // =========================================================================
    // SAML 2.0 CONFIGURATION (OKTA / other Identity Providers)
    // Only matters if auth.type is set to 'saml'. Otherwise ignore this whole block.
    // Setting this up is a rite of passage. May the XML gods be kind to you.
    // =========================================================================
    'saml' => [
        'enabled' => false,  // Flip to true when you've got your IdP configured
        'sp' => [
            // Service Provider settings (that's us, the app)
            'entityId' => 'https://your-domain.com/saml/metadata',
            'assertionConsumerService' => [
                'url' => 'https://your-domain.com/saml/acs',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
            ],
            'singleLogoutService' => [
                'url' => 'https://your-domain.com/saml/sls',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
            ],
            'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => '', // SP certificate (optional)
            'privateKey' => '' // SP private key (optional)
        ],
        'idp' => [
            // Identity Provider settings (OKTA, Azure AD, etc.)
            'entityId' => 'http://www.okta.com/YOUR_IDP_ENTITY_ID',
            'singleSignOnService' => [
                'url' => 'https://YOUR_OKTA_DOMAIN.okta.com/app/YOUR_APP_ID/sso/saml',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
            ],
            'singleLogoutService' => [
                'url' => 'https://YOUR_OKTA_DOMAIN.okta.com/app/YOUR_APP_ID/slo/saml',
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
            ],
            'x509cert' => '' // Paste your IdP's X.509 certificate here (the big base64 blob)
        ],
        'security' => [
            // SAML security settings. These are mostly "false" for basic setups.
            // Turn them on as your security team requires.
            'nameIdEncrypted' => false,
            'authnRequestsSigned' => false,
            'logoutRequestSigned' => false,
            'logoutResponseSigned' => false,
            'signMetadata' => false,
            'wantMessagesSigned' => false,
            'wantAssertionsSigned' => false,
            'wantAssertionsEncrypted' => false,
            'wantNameIdEncrypted' => false,
            'requestedAuthnContext' => true,
            'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256'
        ],
        // Map IdP attribute names to our user fields
        'attribute_mapping' => [
            'email' => 'email',
            'username' => 'username',
            'full_name' => 'displayName',
            'first_name' => 'firstName',
            'last_name' => 'lastName'
        ]
    ],

    // =========================================================================
    // SECURITY CONFIGURATION
    // All the headers and cookie settings that make security scanners happy.
    // =========================================================================
    'security' => [
        'csrf_token_name' => 'csrf_token',     // The hidden form field name for CSRF protection
        'csrf_token_lifetime' => 7200,          // 2 hours -- long enough to fill out a form during a meeting
        'secure_cookies' => true,               // HTTPS only cookies. Set to false for local dev (but true in prod!)
        'httponly_cookies' => true,              // JS can't touch these cookies. Take that, XSS.
        'samesite_cookies' => 'Strict',         // Cookies only sent for same-site requests
        'content_security_policy' => true,       // CSP headers enabled
        'x_frame_options' => 'DENY',            // No iframes allowed. Clickjacking? Not today.
        'x_content_type_options' => 'nosniff',  // Don't sniff my MIME types, browser
        'x_xss_protection' => '1; mode=block',  // Legacy XSS protection header
        'strict_transport_security' => 'max-age=31536000; includeSubDomains',  // HSTS for a full year

        // Antivirus scanning for file uploads (ClamAV)
        // Scans every uploaded file before encryption/storage.
        'antivirus' => [
            'enabled' => true,                              // Master switch — set to false to skip scanning entirely
            'socket' => '/var/run/clamav/clamd.sock',       // Unix socket for clamd
            'fail_open' => false,                           // If clamd is down: false = block uploads (fail closed, secure default); true = allow (insecure)
        ],
    ],

    // =========================================================================
    // APPLICATION CONFIGURATION
    // General app settings -- name, URL, timezone, that sort of thing.
    // =========================================================================
    'app' => [
        'name' => 'TPRM FAIR Analysis',       // The app name shown in headers and emails
        'url' => 'https://your-domain.com',    // Your actual domain -- used for generating links
        'timezone' => 'America/New_York',       // Set to your timezone. We're not all on the East Coast.
        'locale' => 'en_US',                    // Language/locale
        'debug' => false,                       // NEVER enable in production. I mean it. NEVER.
        'log_file' => __DIR__ . '/logs/app.log', // Where logs go to live (and sometimes die)
        'log_level' => 'error' // emergency, alert, critical, error, warning, notice, info, debug
    ]
];
