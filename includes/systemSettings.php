<?php
/**
 * System Settings Management
 * Handles initialization and management of system-wide settings
 */

class SystemSettings {
    private static $pdo;
    private static $settings_cache = [];
    
    public static function init($pdo_connection) {
        self::$pdo = $pdo_connection;
        self::createSystemSettingsTable();
        self::initializeDefaultSettings();
    }
    
    /**
     * Create site_settings table if it doesn't exist
     */
    private static function createSystemSettingsTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS site_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(255) UNIQUE NOT NULL,
                setting_value TEXT,
                setting_description TEXT,
                setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
                is_encrypted BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT,
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        try {
            self::$pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to create site_settings table: " . $e->getMessage());
            throw new Exception("System settings initialization failed");
        }
    }
    
    /**
     * Initialize default system settings
     */
    private static function initializeDefaultSettings() {
        $default_settings = [
            [
                'key' => 'encryption_mode',
                'value' => '1',
                'description' => 'Enable/disable automatic data encryption (1=enabled, 0=disabled)',
                'type' => 'boolean'
            ],
            [
                'key' => 'site_name',
                'value' => 'Migori County Project Management',
                'description' => 'Official site name',
                'type' => 'string'
            ],
            [
                'key' => 'max_login_attempts',
                'value' => '5',
                'description' => 'Maximum login attempts before lockout',
                'type' => 'integer'
            ],
            [
                'key' => 'session_timeout',
                'value' => '3600',
                'description' => 'Session timeout in seconds',
                'type' => 'integer'
            ],
            [
                'key' => 'maintenance_mode',
                'value' => '0',
                'description' => 'Enable maintenance mode (1=enabled, 0=disabled)',
                'type' => 'boolean'
            ],
            [
                'key' => 'email_notifications',
                'value' => '1',
                'description' => 'Enable email notifications (1=enabled, 0=disabled)',
                'type' => 'boolean'
            ]
        ];
        
        foreach ($default_settings as $setting) {
            self::initializeSetting(
                $setting['key'], 
                $setting['value'], 
                $setting['description'], 
                $setting['type']
            );
        }
    }
    
    /**
     * Initialize a single setting if it doesn't exist
     */
    private static function initializeSetting($key, $default_value, $description = '', $type = 'string') {
        try {
            $stmt = self::$pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            
            if (!$stmt->fetch()) {
                $stmt = self::$pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, setting_description, setting_type) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$key, $default_value, $description, $type]);
            }
        } catch (PDOException $e) {
            error_log("Failed to initialize setting '$key': " . $e->getMessage());
        }
    }
    
    /**
     * Get a setting value
     */
    public static function get($key, $default = null) {
        // Check cache first
        if (isset(self::$settings_cache[$key])) {
            return self::$settings_cache[$key];
        }
        
        try {
            $stmt = self::$pdo->prepare("SELECT setting_value, setting_type FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            
            if ($result) {
                $value = $result['setting_value'];
                
                // Type conversion
                switch ($result['setting_type']) {
                    case 'boolean':
                        $value = ($value === '1' || $value === 'true');
                        break;
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                // Cache the value
                self::$settings_cache[$key] = $value;
                return $value;
            }
        } catch (PDOException $e) {
            error_log("Failed to get setting '$key': " . $e->getMessage());
        }
        
        return $default;
    }
    
    /**
     * Set a setting value
     */
    public static function set($key, $value, $admin_id = null) {
        try {
            // Handle type conversion for storage
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            }
            
            $stmt = self::$pdo->prepare("
                UPDATE site_settings 
                SET setting_value = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE setting_key = ?
            ");
            
            $result = $stmt->execute([$value, $admin_id, $key]);
            
            if ($result) {
                // Clear cache
                unset(self::$settings_cache[$key]);
                
                // Log the change
                if (function_exists('log_activity')) {
                    log_activity('system_setting_changed', "Setting '$key' updated", $admin_id, 'system');
                }
                
                return true;
            }
        } catch (PDOException $e) {
            error_log("Failed to set setting '$key': " . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Get all settings
     */
    public static function getAll() {
        try {
            $stmt = self::$pdo->query("
                SELECT setting_key, setting_value, setting_description, setting_type, updated_at 
                FROM site_settings 
                ORDER BY setting_key
            ");
            
            $settings = [];
            while ($row = $stmt->fetch()) {
                $value = $row['setting_value'];
                
                // Type conversion
                switch ($row['setting_type']) {
                    case 'boolean':
                        $value = ($value === '1' || $value === 'true');
                        break;
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                $settings[$row['setting_key']] = [
                    'value' => $value,
                    'description' => $row['setting_description'],
                    'type' => $row['setting_type'],
                    'updated_at' => $row['updated_at']
                ];
            }
            
            return $settings;
        } catch (PDOException $e) {
            error_log("Failed to get all settings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if encryption is enabled
     */
    public static function isEncryptionEnabled() {
        return self::get('encryption_mode', true); // Default to enabled for security
    }
    
    /**
     * Enable/disable encryption mode
     */
    public static function setEncryptionMode($enabled, $admin_id = null) {
        return self::set('encryption_mode', $enabled ? '1' : '0', $admin_id);
    }
    
    /**
     * Clear settings cache
     */
    public static function clearCache() {
        self::$settings_cache = [];
    }
}
?>
