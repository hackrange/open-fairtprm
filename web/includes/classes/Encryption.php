<?php
/**
 * Encryption Utilities - The Cryptographic Swiss Army Knife
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This class handles all the cryptographic heavy lifting: password hashing with
 * Argon2id (falling back to bcrypt for PHP builds that skipped arm day), AES-256-CBC
 * encryption for sensitive data at rest, HMAC verification to catch any tampering
 * funny business, file encryption/decryption, and random token generation.
 * Basically, if data needs to be secret, scrambled, verified, or randomized,
 * this is your guy. Don't roll your own crypto -- let this class handle it
 * so you can sleep at night.
 */

class Encryption {
    // The 256-bit encryption key, decoded from base64 config value
    private $key;

    // Cipher algorithm -- AES-256-CBC by default, because it's battle-tested
    private $cipher;

    // Hash digest for HMACs -- SHA-256, because SHA-1 is living on borrowed time
    private $digest;

    /**
     * Constructor: pulls encryption settings from config and validates them.
     *
     * The key MUST be exactly 32 bytes (256 bits) after base64 decoding.
     * If it's not, we throw an exception right away rather than silently
     * producing weak encryption. This is one of those "fail loud or regret
     * it later" situations.
     */
    public function __construct(?string $keyOverride = null) {
        $config = Config::getInstance();
        if ($keyOverride !== null) {
            $this->key = base64_decode($keyOverride);
        } else {
            $this->key = base64_decode($config->get('encryption.key'));
        }
        $this->cipher = $config->get('encryption.cipher', 'AES-256-CBC');
        $this->digest = $config->get('encryption.digest', 'sha256');

        // 32 bytes or bust. No exceptions. Literally, well, one exception.
        if (strlen($this->key) !== 32) {
            throw new Exception('Encryption key must be 32 bytes (256 bits)');
        }
    }

    /**
     * Hash a password using Argon2id (preferred) or bcrypt (fallback).
     *
     * Argon2id is the gold standard for password hashing -- it's memory-hard,
     * time-hard, and parallelism-resistant. It's basically designed to make
     * GPU crackers cry. If your PHP build doesn't have Argon2id support
     * (looking at you, some shared hosting), we fall back to bcrypt with
     * a cost factor of 12, which is still perfectly respectable.
     *
     * @param string $password Plain text password (handle with care!)
     * @return string The hashed password, ready for database storage
     */
    public function hashPassword($password) {
        if (defined('PASSWORD_ARGON2ID')) {
            // Argon2id with tuned parameters:
            // 64MB memory, 4 iterations, 3 threads. Adjust to taste.
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);
        }

        // Bcrypt fallback -- still solid, just not as fancy
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => 12
        ]);
    }

    /**
     * Verify a password against its stored hash.
     * Uses PHP's built-in password_verify which does constant-time comparison
     * internally, so timing attacks can go take a hike.
     *
     * @param string $password Plain text password to check
     * @param string $hash The stored hash to compare against
     * @return bool True if the password matches
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Check if a password hash needs to be re-hashed.
     *
     * This handles the case where you upgrade from bcrypt to Argon2id,
     * or change your hashing parameters. When the user logs in with the
     * correct password, you can check this and silently upgrade their hash.
     * Smooth, painless migration -- the users never even know.
     *
     * @param string $hash Current password hash
     * @return bool True if the hash algorithm/params are outdated
     */
    public function needsRehash($hash) {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);
        }

        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Encrypt raw binary data (for files and binary content).
     *
     * Similar to encrypt() but skips the JSON encoding and base64 wrapping.
     * Returns raw binary: IV + HMAC + ciphertext, concatenated together.
     * The HMAC covers both the IV and ciphertext to detect any tampering.
     *
     * Format: [16-byte IV][32-byte HMAC][encrypted data]
     *
     * @param string $data Raw binary data to encrypt
     * @return string Raw encrypted binary data (NOT base64 encoded)
     */
    public function encryptRaw($data) {
        if ($data === null || $data === '') {
            return '';
        }

        // Generate a random IV -- never reuse IVs, that's crypto 101
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // Do the actual encryption
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }

        // Generate HMAC over IV + ciphertext -- this is our tamper seal
        $hmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);

        // Pack it all together: IV first, then HMAC, then ciphertext
        return $iv . $hmac . $encrypted;
    }

    /**
     * Decrypt raw binary data that was encrypted with encryptRaw().
     *
     * Unpacks the IV, HMAC, and ciphertext, verifies the HMAC first
     * (verify-then-decrypt pattern), and only then attempts decryption.
     * If the HMAC doesn't match, someone's been messing with the data.
     *
     * @param string $encryptedData Raw encrypted binary data
     * @return string Decrypted raw binary data
     */
    public function decryptRaw($encryptedData) {
        if ($encryptedData === null || $encryptedData === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $hmacLength = 32; // SHA-256 produces 32 bytes

        // Make sure we have enough data to even contain an IV + HMAC
        if (strlen($encryptedData) < $ivLength + $hmacLength) {
            throw new Exception('Invalid encrypted data length');
        }

        // Slice apart the three components
        $iv = substr($encryptedData, 0, $ivLength);
        $hmac = substr($encryptedData, $ivLength, $hmacLength);
        $encrypted = substr($encryptedData, $ivLength + $hmacLength);

        // Verify HMAC BEFORE decrypting -- always verify first!
        $calculatedHmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);

        if (!hash_equals($calculatedHmac, $hmac)) {
            throw new Exception('HMAC verification failed - data may be corrupted or tampered');
        }

        // HMAC checks out, safe to decrypt
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }

        return $decrypted;
    }

    /**
     * Encrypt data using AES-256-CBC with HMAC authentication.
     *
     * This is the general-purpose encryption method. Data gets JSON-encoded first
     * (so you can encrypt arrays, objects, whatever), then encrypted, then the
     * whole package (IV + HMAC + ciphertext) gets base64-encoded for safe
     * storage in text fields. It's like putting your data in a safe, wrapping
     * the safe in tamper-evident tape, and then gift-wrapping it.
     *
     * @param mixed $data Data to encrypt (will be JSON encoded first)
     * @return string Base64 encoded encrypted blob
     */
    public function encrypt($data) {
        if ($data === null || $data === '') {
            return '';
        }

        // JSON encode first -- this lets us encrypt any serializable PHP type
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new Exception('Failed to encode data for encryption');
        }

        // Fresh random IV for each encryption operation
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt(
            $jsonData,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }

        // HMAC for tamper detection
        $hmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);

        // Combine and base64 encode for safe storage in text/varchar columns
        $combined = $iv . $hmac . $encrypted;

        return base64_encode($combined);
    }

    /**
     * Decrypt data that was encrypted with encrypt().
     *
     * Reverses the whole process: base64 decode, split out IV/HMAC/ciphertext,
     * verify HMAC (reject if tampered), decrypt, JSON decode back to the
     * original PHP data type. If any step fails, we throw rather than
     * returning garbage. Garbage in, exception out.
     *
     * @param string $encryptedData Base64 encoded encrypted blob
     * @return mixed The original decrypted data
     */
    public function decrypt($encryptedData) {
        if ($encryptedData === null || $encryptedData === '') {
            return '';
        }

        // Unwrap the base64 layer
        $combined = base64_decode($encryptedData, true);
        if ($combined === false) {
            throw new Exception('Invalid encrypted data format');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $hmacLength = 32;

        if (strlen($combined) < $ivLength + $hmacLength) {
            throw new Exception('Invalid encrypted data length');
        }

        // Slice and dice: IV, HMAC, ciphertext
        $iv = substr($combined, 0, $ivLength);
        $hmac = substr($combined, $ivLength, $hmacLength);
        $encrypted = substr($combined, $ivLength + $hmacLength);

        // Verify before decrypt -- the golden rule of authenticated encryption
        $calculatedHmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);

        if (!hash_equals($calculatedHmac, $hmac)) {
            throw new Exception('HMAC verification failed - data may be corrupted or tampered');
        }

        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }

        // Parse the JSON back into a PHP value
        $data = json_decode($decrypted, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to decode decrypted data');
        }

        return $data;
    }

    /**
     * Generate a cryptographically secure random token.
     * Uses random_bytes() which pulls from the OS CSPRNG.
     * Returns hex-encoded string (so 32 bytes = 64 character hex string).
     * Great for password reset tokens, API keys, etc.
     *
     * @param int $length Token length in bytes (default 32)
     * @return string Hex encoded random token
     */
    public function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a secure random string, base64 encoded.
     * Similar to generateToken but uses base64 instead of hex encoding.
     * Base64 is more compact (shorter strings for the same entropy).
     *
     * @param int $length Number of random bytes (default 32)
     * @return string Base64 encoded random string
     */
    public function generateRandomString($length = 32) {
        return base64_encode(random_bytes($length));
    }

    /**
     * Constant-time string comparison.
     *
     * Wraps hash_equals() for a nicer API. This prevents timing attacks
     * where an attacker measures response time to figure out how many
     * characters of a token matched. With constant-time comparison, the
     * comparison always takes the same amount of time regardless of where
     * the strings differ. Take that, side-channel attackers.
     *
     * @param string $known The known/expected string
     * @param string $user The user-provided string to check
     * @return bool True if the strings are identical
     */
    public function constantTimeCompare($known, $user) {
        return hash_equals($known, $user);
    }

    /**
     * Encrypt a file and save the encrypted version to disk.
     *
     * Reads the entire file into memory, encrypts it (AES-256-CBC + HMAC),
     * and writes the result to a new file with .enc extension. If you're
     * encrypting multi-gigabyte files, you might want a streaming approach
     * instead, but for reasonable file sizes this works great.
     *
     * The encrypted file format is: [IV][HMAC][ciphertext] -- same pattern
     * as our other encryption methods.
     *
     * @param string $sourcePath Path to the file to encrypt
     * @param string $destPath Base path for encrypted file (will get .enc appended)
     * @return string Path to the encrypted file
     */
    public function encryptFile($sourcePath, $destPath = null) {
        if (!file_exists($sourcePath)) {
            throw new Exception('Source file not found');
        }

        // Slurp the whole file into memory
        $data = file_get_contents($sourcePath);
        if ($data === false) {
            throw new Exception('Failed to read source file');
        }

        // Standard encrypt routine: random IV, encrypt, HMAC
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('File encryption failed');
        }

        $hmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);
        $combined = $iv . $hmac . $encrypted;

        // Write to destination path + .enc extension
        $encryptedPath = ($destPath ?: $sourcePath) . '.enc';

        if (file_put_contents($encryptedPath, $combined) === false) {
            throw new Exception('Failed to write encrypted file');
        }

        return $encryptedPath;
    }

    /**
     * Decrypt a file that was encrypted with encryptFile().
     *
     * Reads the encrypted file, verifies HMAC integrity, decrypts, and
     * either returns the content or writes it to a destination path.
     * If the HMAC check fails, the file has been tampered with and we
     * refuse to decrypt it. No negotiation.
     *
     * @param string $encryptedPath Path to the encrypted file
     * @param string $destPath Optional: where to write decrypted output
     * @return string|bool Decrypted content (or true if written to file)
     */
    public function decryptFile($encryptedPath, $destPath = null) {
        if (!file_exists($encryptedPath)) {
            throw new Exception('Encrypted file not found');
        }

        $combined = file_get_contents($encryptedPath);
        if ($combined === false) {
            throw new Exception('Failed to read encrypted file');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $hmacLength = 32;

        if (strlen($combined) < $ivLength + $hmacLength) {
            throw new Exception('Invalid encrypted file format');
        }

        // Extract the three parts
        $iv = substr($combined, 0, $ivLength);
        $hmac = substr($combined, $ivLength, $hmacLength);
        $encrypted = substr($combined, $ivLength + $hmacLength);

        // Verify HMAC -- the trust-but-verify step
        $calculatedHmac = hash_hmac($this->digest, $iv . $encrypted, $this->key, true);

        if (!hash_equals($calculatedHmac, $hmac)) {
            throw new Exception('HMAC verification failed - file may be corrupted or tampered');
        }

        // All clear, decrypt away
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('File decryption failed');
        }

        // Either write to file or return the content
        if ($destPath) {
            if (file_put_contents($destPath, $decrypted) === false) {
                throw new Exception('Failed to write decrypted file');
            }
            return true;
        }

        return $decrypted;
    }

    /**
     * Generate a TOTP secret.
     * Creates 20 random bytes and base32-encodes them. This is the shared
     * secret that goes into the user's authenticator app and gets stored
     * (encrypted!) in our database. 20 bytes = 160 bits of entropy, which
     * is the standard for TOTP per RFC 6238.
     *
     * @return string Base32 encoded secret (32 characters)
     */
    public function generateTOTPSecret() {
        $bytes = random_bytes(20);
        return $this->base32Encode($bytes);
    }

    /**
     * Base32 encoding for TOTP secrets.
     *
     * TOTP requires base32-encoded secrets (not base64) because the RFC says so
     * and authenticator apps expect it. Base32 uses A-Z and 2-7, which is nice
     * because there's no ambiguity between similar-looking characters (no 0/O or
     * 1/l confusion). The algorithm works by accumulating bits and emitting 5-bit
     * chunks. It's like base64's less popular but equally valid cousin.
     *
     * @param string $data Raw binary data to encode
     * @return string Base32 encoded string
     */
    private function base32Encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;      // Bit accumulator
        $vbits = 0;  // Number of bits currently in the accumulator

        for ($i = 0, $j = strlen($data); $i < $j; $i++) {
            // Shift in 8 new bits from the next byte
            $v <<= 8;
            $v += ord($data[$i]);
            $vbits += 8;

            // Emit 5-bit chunks as base32 characters
            while ($vbits >= 5) {
                $vbits -= 5;
                $output .= $alphabet[($v >> $vbits) & 0x1F];
            }
        }

        // Handle any leftover bits (pad the last chunk)
        if ($vbits > 0) {
            $v <<= (5 - $vbits);
            $output .= $alphabet[$v & 0x1F];
        }

        return $output;
    }
}
