<?php
/**
 * Encryption utility for sensitive user data
 * Uses AES-256-GCM for secure encryption with authentication
 */
class DataEncryption {
    private static $encryption_key = null;
    private static $cipher_method = 'aes-256-gcm';

    /**
     * Initialize encryption with secure key
     */
    public static function init() {
        // Get encryption key from config or environment
        $key = defined('DATA_ENCRYPTION_KEY') ? DATA_ENCRYPTION_KEY : ($_ENV['DATA_ENCRYPTION_KEY'] ?? null);

        if (!$key) {
            // For development, use a default key (should be changed in production)
            $key = 'dev_key_change_in_production_' . hash('sha256', 'migori_pmc_2024');
        }

        self::$encryption_key = hash('sha256', $key, true);
    }

    /**
     * Encrypt sensitive data
     */
    public static function encrypt($data) {
        if (empty($data)) {
            return null;
        }

        if (self::$encryption_key === null) {
            self::init();
        }

        $iv = random_bytes(12); // 96-bit IV for GCM
        $tag = '';

        $encrypted = openssl_encrypt(
            $data,
            self::$cipher_method,
            self::$encryption_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }

        // Combine IV, tag, and encrypted data
        $result = base64_encode($iv . $tag . $encrypted);
        return $result;
    }

    /**
     * Decrypt sensitive data
     */
    public static function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return null;
        }

        if (self::$encryption_key === null) {
            self::init();
        }

        $data = base64_decode($encrypted_data, true);
        if ($data === false || strlen($data) < 28) {
            return null; // Invalid data
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::$cipher_method,
            self::$encryption_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Decrypt an array of data
     */
    public static function decryptArray($data) {
        if (!is_array($data)) {
            return $data;
        }

        $decrypted = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value)) {
                $decrypted_value = self::decrypt($value);
                $decrypted[$key] = $decrypted_value !== null ? $decrypted_value : $value;
            } else {
                $decrypted[$key] = $value;
            }
        }
        return $decrypted;
    }

    /**
     * Check if data appears to be encrypted
     */
    public static function isEncrypted($data) {
        if (empty($data) || !is_string($data)) {
            return false;
        }

        // Check if it's base64 encoded with expected length
        $decoded = base64_decode($data, true);
        return $decoded !== false && strlen($decoded) >= 28; // IV + tag + some data
    }
}

// Initialize encryption on load
DataEncryption::init();
?>