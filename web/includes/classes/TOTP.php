<?php
/**
 * TOTP (Time-based One-Time Password) - The Second Factor Handshake
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * RFC 6238 implementation for two-factor authentication. This is the engine
 * behind those six-digit codes your authenticator app spits out every 30 seconds.
 * Generates shared secrets, creates time-based codes using HMAC-SHA1, verifies
 * user-submitted codes with a configurable time window, and produces QR code
 * URLs so users can set up their authenticator apps by pointing their phone at
 * the screen like a barcode scanner at the grocery store. If you've ever wondered
 * "how do those rotating codes actually work?" -- well, you're about to find out.
 */

class TOTP {
    // Config and encryption dependencies
    private $config;
    private $encryption;

    /**
     * Constructor. Grabs config and encryption instances.
     * Unlike most of the other classes, TOTP is NOT a singleton because
     * you might want multiple instances with different settings. But in
     * practice we usually just new one up when we need it.
     */
    public function __construct() {
        $this->config = Config::getInstance();
        $this->encryption = new Encryption();
    }

    /**
     * Generate a new TOTP secret.
     * Delegates to Encryption::generateTOTPSecret() which creates 20 random
     * bytes and base32-encodes them. This secret is what gets shared between
     * the server and the user's authenticator app. Guard it with your life
     * (or at least with AES-256 encryption).
     *
     * @return string Base32 encoded secret (ready for QR codes and authenticator apps)
     */
    public function generateSecret() {
        return $this->encryption->generateTOTPSecret();
    }

    /**
     * Generate a TOTP code for a given secret and time slice.
     *
     * This is the core of the TOTP algorithm (RFC 6238 / RFC 4226):
     * 1. Take the current time, divide by 30 to get the "time slice"
     * 2. Pack that into an 8-byte big-endian counter
     * 3. HMAC-SHA1 the counter with the decoded secret key
     * 4. Dynamic truncation: grab 4 bytes at an offset determined by the last nibble
     * 5. Mod 10^6 to get a 6-digit code, zero-padded
     *
     * It sounds complicated, but it's actually pretty elegant. The same math
     * happens in your authenticator app, which is why the codes match without
     * any network communication. Cryptography is basically magic.
     *
     * @param string $secret Base32 encoded secret
     * @param int $timeSlice Time slice (null = current 30-second window)
     * @return string 6-digit TOTP code, zero-padded
     */
    public function generateCode($secret, $timeSlice = null) {
        // Default to current time slice (floor(now / 30))
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        // Decode the base32 secret back to raw bytes
        $secretKey = $this->base32Decode($secret);

        // Pack the time slice as an 8-byte big-endian value
        // (leading 4 bytes of zeros + the actual counter)
        $time = pack('N*', 0) . pack('N*', $timeSlice);

        // HMAC-SHA1 the time counter with the secret key
        $hash = hash_hmac('sha1', $time, $secretKey, true);

        // Dynamic truncation: the last nibble of the hash tells us
        // where to grab our 4-byte code from. This is the clever part.
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |  // Strip the sign bit
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;  // Mod to get 6 digits

        // Zero-pad to ensure we always return exactly 6 characters
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-submitted TOTP code.
     *
     * Doesn't just check the current time slice -- also checks the time
     * slices immediately before and after (controlled by $window). This
     * handles the case where the user's clock is slightly off, or they
     * were slow to type the code and it just expired. A window of 1 means
     * we check current, -30 seconds, and +30 seconds (90-second total window).
     *
     * Uses hash_equals for constant-time comparison because even TOTP
     * codes deserve protection from timing attacks.
     *
     * @param string $secret Base32 encoded secret
     * @param string $code The 6-digit code the user typed in
     * @param int $window Number of adjacent time periods to check (default 1)
     * @return bool True if the code is valid in any of the checked time slices
     */
    public function verifyCode($secret, $code, $window = 1) {
        $timeSlice = floor(time() / 30);

        // Check the current time slice and $window slices on either side
        for ($i = -$window; $i <= $window; $i++) {
            $generatedCode = $this->generateCode($secret, $timeSlice + $i);
            // Constant-time comparison -- no timing side-channel leaks here
            if (hash_equals($generatedCode, $code)) {
                return true;
            }
        }

        // None of the time slices matched. Sad trombone.
        return false;
    }

    /**
     * Generate a QR code URL for setting up authenticator apps.
     *
     * Builds an otpauth:// URI (the standard format that authenticator apps
     * understand) and wraps it in a URL to the qrserver.com API to generate
     * a scannable QR code image. The user just points their phone camera at
     * it and their authenticator app automatically imports the secret.
     *
     * The issuer is included so the entry in the authenticator app shows
     * "TPRM FAIR Analysis: username" instead of just a random string.
     *
     * @param string $secret Base32 encoded secret
     * @param string $username User's username or email
     * @param string $issuer Application name (defaults to config value)
     * @return string URL to a QR code image
     */
    public function getQRCodeUrl($secret, $username, $issuer = null) {
        // Fall back to the configured issuer name if none provided
        if ($issuer === null) {
            $issuer = $this->config->get('auth.totp.issuer', 'TPRM FAIR Analysis');
        }

        // Build the label -- "Issuer:username" format
        $label = $username;
        if ($issuer) {
            $label = $issuer . ':' . $username;
        }

        // TOTP parameters that go into the otpauth URI
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',     // Standard algorithm for TOTP
            'digits' => 6,             // 6-digit codes
            'period' => 30             // New code every 30 seconds
        ];

        // Build the otpauth:// URI that authenticator apps understand
        $otpauth = 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query($params);

        // Return the provisioning URI directly for client-side QR rendering.
        // Previously this used a third-party QR service (api.qrserver.com) which
        // leaked the TOTP secret to an external server. Now the QR code is
        // generated client-side so the secret never leaves the browser.
        return $otpauth;
    }

    /**
     * Get the raw provisioning URI for authenticator apps.
     *
     * Same as getQRCodeUrl but returns the raw otpauth:// URI instead
     * of wrapping it in a QR code service URL. Useful for manual entry
     * or if you want to generate QR codes client-side.
     *
     * @param string $secret Base32 encoded secret
     * @param string $username User's username or email
     * @param string $issuer Application name (defaults to config value)
     * @return string The otpauth:// provisioning URI
     */
    public function getProvisioningUri($secret, $username, $issuer = null) {
        if ($issuer === null) {
            $issuer = $this->config->get('auth.totp.issuer', 'TPRM FAIR Analysis');
        }

        $label = $username;
        if ($issuer) {
            $label = $issuer . ':' . $username;
        }

        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30
        ];

        return 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query($params);
    }

    /**
     * Base32 decode -- turns base32-encoded strings back into raw bytes.
     *
     * This is the reverse of the base32 encoding in the Encryption class.
     * Accumulates 5 bits at a time and emits bytes when we have 8 or more
     * bits buffered. Standard RFC 4648 base32 alphabet (A-Z, 2-7).
     *
     * Fun fact: base32 was chosen for TOTP secrets because it avoids
     * ambiguous characters (no 0 vs O, 1 vs l), making manual entry
     * less error-prone. Thoughtful, right?
     *
     * @param string $data Base32 encoded string
     * @return string Decoded raw bytes
     */
    private function base32Decode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;      // Bit accumulator
        $vbits = 0;  // How many bits are currently in the accumulator

        for ($i = 0, $j = strlen($data); $i < $j; $i++) {
            // Each base32 character represents 5 bits
            $v <<= 5;
            $v += stripos($alphabet, $data[$i]);
            $vbits += 5;

            // Emit bytes whenever we have enough bits
            while ($vbits >= 8) {
                $vbits -= 8;
                $output .= chr(($v >> $vbits) & 0xFF);
            }
        }

        return $output;
    }
}
