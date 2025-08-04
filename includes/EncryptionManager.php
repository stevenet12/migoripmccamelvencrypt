<?php
require_once __DIR__ . '/encryption.php';

class EncryptionManager {
    private static $pdo = null;
    private static $encryptionMode = null;
    private static $sensitiveFields = [
        'admins' => ['name', 'email', 'two_factor_secret', 'password_reset_token'],
        'login_attempts' => ['email', 'ip_address', 'user_agent', 'session_id'],
        'admin_activity_log' => ['ip_address', 'user_agent'],
        'unified_logs' => ['ip_address', 'user_agent'],
        'security_logs' => ['ip_address', 'user_agent'],
        'password_reset_tokens' => ['token'],
        'publication_logs' => ['ip_address'],
        'project_subscriptions' => ['email', 'subscription_token', 'verification_token', 'ip_address', 'user_agent'],
        'session_management' => ['session_id', 'ip_address', 'user_agent'],
        'feedback' => [
            'citizen_name', 
            'citizen_email', 
            'citizen_phone', 
            'subject',
            'message',
            'user_ip', 
            'user_agent',
            'filtering_metadata'
        ],
        'feedback_notifications' => ['recipient_email']
    ];

    public static function init($pdo) {
        self::$pdo = $pdo;
    }

    public static function getEncryptionMode() {
        if (self::$encryptionMode === null) {
            try {
                $stmt = self::$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'encryption_mode'");
                $stmt->execute();
                self::$encryptionMode = ($stmt->fetchColumn() === 'true');
            } catch (Exception $e) {
                error_log("Failed to get encryption mode: " . $e->getMessage());
                self::$encryptionMode = false;
            }
        }
        return self::$encryptionMode;
    }

    private static function setEncryptionMode($mode, $adminId = null) {
        try {
            $stmt = self::$pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = 'encryption_mode'");
            $stmt->execute([$mode ? 'true' : 'false', $adminId]);
            self::$encryptionMode = $mode;
            self::logEncryptionOperation($mode ? 'encryption_enabled' : 'encryption_disabled', $adminId);
        } catch (Exception $e) {
            throw new Exception("Failed to update encryption mode: " . $e->getMessage());
        }
    }

    public static function getMaintenanceMode() {
        try {
            $stmt = self::$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
            $stmt->execute();
            return ($stmt->fetchColumn() === 'true');
        } catch (Exception $e) {
            return false;
        }
    }

    public static function setMaintenanceMode($mode, $adminId = null) {
        try {
            $stmt = self::$pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = 'maintenance_mode'");
            $stmt->execute([$mode ? 'true' : 'false', $adminId]);
        } catch (Exception $e) {
            throw new Exception("Failed to update maintenance mode: " . $e->getMessage());
        }
    }

    public static function encryptIfEnabled($data) {
        return (self::getEncryptionMode() && !empty($data)) ? DataEncryption::encrypt($data) : $data;
    }

    public static function decryptIfNeeded($data) {
        return (!empty($data) && DataEncryption::isEncrypted($data)) ? DataEncryption::decrypt($data) : $data;
    }

    private static function updateDatabaseSchema() {
        $schemaUpdates = [
            "ALTER TABLE `admin_activity_log` MODIFY COLUMN `ip_address` TEXT",
            "ALTER TABLE `admin_activity_log` MODIFY COLUMN `user_agent` TEXT",
            "ALTER TABLE `login_attempts` MODIFY COLUMN `email` TEXT",
            "ALTER TABLE `login_attempts` MODIFY COLUMN `ip_address` TEXT",
            "ALTER TABLE `login_attempts` MODIFY COLUMN `user_agent` TEXT",
            "ALTER TABLE `login_attempts` MODIFY COLUMN `session_id` TEXT",
            "ALTER TABLE `security_logs` MODIFY COLUMN `ip_address` TEXT",
            "ALTER TABLE `security_logs` MODIFY COLUMN `user_agent` TEXT",
            "ALTER TABLE `publication_logs` MODIFY COLUMN `ip_address` TEXT",
            "ALTER TABLE `project_subscriptions` MODIFY COLUMN `email` TEXT",
            "ALTER TABLE `project_subscriptions` MODIFY COLUMN `subscription_token` TEXT",
            "ALTER TABLE `project_subscriptions` MODIFY COLUMN `verification_token` TEXT",
            "ALTER TABLE `project_subscriptions` MODIFY COLUMN `ip_address` TEXT",
            "ALTER TABLE `project_subscriptions` MODIFY COLUMN `user_agent` TEXT",
            "ALTER TABLE `project_subscriptions` ADD COLUMN `email_hash` VARCHAR(64) DEFAULT NULL",
            "ALTER TABLE `project_subscriptions` ADD INDEX `idx_project_email_hash` (`project_id`, `email_hash`)",
            "ALTER TABLE `feedback` MODIFY COLUMN `citizen_name` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `citizen_email` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `citizen_phone` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `subject` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `message` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `user_ip` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `user_agent` TEXT",
            "ALTER TABLE `feedback` MODIFY COLUMN `filtering_metadata` TEXT",
            "ALTER TABLE `feedback_notifications` MODIFY COLUMN `recipient_email` TEXT",
            "ALTER TABLE `admins` MODIFY COLUMN `name` TEXT",
            "ALTER TABLE `admins` MODIFY COLUMN `email` TEXT",
            "ALTER TABLE `admins` MODIFY COLUMN `two_factor_secret` TEXT",
            "ALTER TABLE `admins` MODIFY COLUMN `password_reset_token` TEXT",
            "ALTER TABLE `password_reset_tokens` MODIFY COLUMN `token` TEXT"
        ];

        foreach ($schemaUpdates as $sql) {
            try {
                self::$pdo->exec($sql);
            } catch (Exception $e) {
                error_log("Schema update warning: " . $e->getMessage());
            }
        }
    }

    public static function encryptAllSensitiveData($adminId = null) {
        if (self::getEncryptionMode()) throw new Exception("Data is already encrypted");

        $transactionStarted = false;
        try {
            self::$pdo->beginTransaction();
            $transactionStarted = true;
            
            self::setMaintenanceMode(true, $adminId);
            self::updateDatabaseSchema();

            foreach (self::$sensitiveFields as $table => $fields) {
                self::encryptTableData($table, $fields);
            }

            self::setEncryptionMode(true, $adminId);
            self::setMaintenanceMode(false, $adminId);
            self::$pdo->commit();
            $transactionStarted = false;

            return ['success' => true, 'message' => 'All sensitive data has been encrypted successfully'];
        } catch (Exception $e) {
            if ($transactionStarted && self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            self::setMaintenanceMode(false, $adminId);
            throw new Exception("Encryption failed: " . $e->getMessage());
        }
    }

    public static function decryptAllSensitiveData($adminId = null) {
        if (!self::getEncryptionMode()) throw new Exception("Data is already decrypted");

        $transactionStarted = false;
        try {
            self::$pdo->beginTransaction();
            $transactionStarted = true;
            
            self::setMaintenanceMode(true, $adminId);

            foreach (self::$sensitiveFields as $table => $fields) {
                self::decryptTableData($table, $fields);
            }

            self::setEncryptionMode(false, $adminId);
            self::setMaintenanceMode(false, $adminId);
            self::$pdo->commit();
            $transactionStarted = false;

            return ['success' => true, 'message' => 'All sensitive data has been decrypted successfully'];
        } catch (Exception $e) {
            if ($transactionStarted && self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            self::setMaintenanceMode(false, $adminId);
            throw new Exception("Decryption failed: " . $e->getMessage());
        }
    }

    private static function encryptTableData($table, $fields) {
        try {
            $check = self::$pdo->query("SHOW TABLES LIKE " . self::$pdo->quote($table));
            if (!$check->fetch()) return;

            $records = self::$pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($records as $record) {
                $updates = [];
                foreach ($fields as $field) {
                    if (!empty($record[$field]) && !DataEncryption::isEncrypted($record[$field])) {
                        $updates[$field] = DataEncryption::encrypt($record[$field]);
                    }
                }

                if ($table === 'project_subscriptions' && !empty($record['email']) && empty($record['email_hash'])) {
                    $email = $record['email'];
                    if (DataEncryption::isEncrypted($email)) {
                        $email = DataEncryption::decrypt($email);
                    }
                    $updates['email_hash'] = hash('sha256', strtolower(trim($email)));
                }

                if ($updates) {
                    $set = implode(', ', array_map(function($f) { return "`$f` = ?"; }, array_keys($updates)));
                    $sql = "UPDATE `$table` SET $set WHERE `id` = ?";
                    $stmt = self::$pdo->prepare($sql);
                    $stmt->execute(array_merge(array_values($updates), [$record['id']]));
                }
            }
        } catch (Exception $e) {
            throw new Exception("Failed to encrypt table $table: " . $e->getMessage());
        }
    }

    private static function decryptTableData($table, $fields) {
        try {
            $check = self::$pdo->query("SHOW TABLES LIKE " . self::$pdo->quote($table));
            if (!$check->fetch()) return;

            $records = self::$pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($records as $record) {
                $updates = [];
                foreach ($fields as $field) {
                    if (!empty($record[$field]) && DataEncryption::isEncrypted($record[$field])) {
                        $decrypted = DataEncryption::decrypt($record[$field]);
                        if ($decrypted !== null) $updates[$field] = $decrypted;
                    }
                }
                if ($updates) {
                    $set = implode(', ', array_map(function($f) { return "`$f` = ?"; }, array_keys($updates)));
                    $sql = "UPDATE `$table` SET $set WHERE `id` = ?";
                    $stmt = self::$pdo->prepare($sql);
                    $stmt->execute(array_merge(array_values($updates), [$record['id']]));
                }
            }
        } catch (Exception $e) {
            throw new Exception("Failed to decrypt table $table: " . $e->getMessage());
        }
    }

    private static function logEncryptionOperation($operation, $adminId) {
        try {
            $stmt = self::$pdo->prepare("
                INSERT INTO security_logs (event_type, admin_id, ip_address, user_agent, details, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $operation,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? 'system',
                $_SERVER['HTTP_USER_AGENT'] ?? 'system',
                json_encode(['operation' => $operation])
            ]);
        } catch (Exception $e) {
            error_log("Failed to log encryption operation: " . $e->getMessage());
        }
    }

    public static function getEncryptionStatus() {
        return [
            'encryption_enabled' => self::getEncryptionMode(),
            'maintenance_enabled' => self::getMaintenanceMode(),
            'last_updated' => self::getLastEncryptionUpdate(),
            'total_sensitive_fields' => self::getTotalSensitiveFields()
        ];
    }

    private static function getLastEncryptionUpdate() {
        try {
            $stmt = self::$pdo->prepare("SELECT updated_at FROM system_settings WHERE setting_key = 'encryption_mode'");
            $stmt->execute();
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            return null;
        }
    }

    private static function getTotalSensitiveFields() {
        return array_sum(array_map('count', self::$sensitiveFields));
    }

    public static function processDataForReading($table, $data) {
        if (!isset(self::$sensitiveFields[$table])) return $data;
        if (isset($data[0])) {
            return array_map(function($row) use ($table) {
                return self::processDataForReading($table, $row);
            }, $data);
        }
        foreach (self::$sensitiveFields[$table] as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::decryptIfNeeded($data[$field]);
            }
        }
        return $data;
    }

    public static function processDataForStorage($table, $data) {
        if (!isset(self::$sensitiveFields[$table])) return $data;
        foreach (self::$sensitiveFields[$table] as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::encryptIfEnabled($data[$field]);
            }
        }
        return $data;
    }

    public static function insertEncrypted($pdo, $table, $data) {
        if (!is_array($data) || empty($data)) {
            throw new InvalidArgumentException("Invalid data for insertEncrypted.");
        }

        $data = self::processDataForStorage($table, $data);

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));

        return true;
    }
}
