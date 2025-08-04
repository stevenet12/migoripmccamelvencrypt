<?php
/**
 * Database Wrapper for automatic encryption/decryption
 */

require_once __DIR__ . '/EncryptionManager.php';

class DatabaseWrapper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Insert with automatic encryption
     */
    public function insert($table, $data) {
        $processedData = EncryptionManager::processDataForStorage($table, $data);
        
        $columns = implode(', ', array_keys($processedData));
        $placeholders = ':' . implode(', :', array_keys($processedData));
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        
        return $stmt->execute($processedData);
    }
    
    /**
     * Update with automatic encryption
     */
    public function update($table, $data, $where) {
        $processedData = EncryptionManager::processDataForStorage($table, $data);
        
        $setClause = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($processedData)));
        $whereClause = implode(' AND ', array_map(fn($key) => "$key = :where_$key", array_keys($where)));
        
        $sql = "UPDATE $table SET $setClause WHERE $whereClause";
        $stmt = $this->pdo->prepare($sql);
        
        // Combine data and where parameters
        $params = $processedData;
        foreach ($where as $key => $value) {
            $params["where_$key"] = $value;
        }
        
        return $stmt->execute($params);
    }
    
    /**
     * Select with automatic decryption
     */
    public function select($table, $where = [], $columns = '*') {
        $whereClause = '';
        if (!empty($where)) {
            $whereClause = 'WHERE ' . implode(' AND ', array_map(fn($key) => "$key = :$key", array_keys($where)));
        }
        
        $sql = "SELECT $columns FROM $table $whereClause";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process results for reading
        return array_map(function($row) use ($table) {
            return EncryptionManager::processDataForReading($table, $row);
        }, $results);
    }
    
    /**
     * Select single row with automatic decryption
     */
    public function selectOne($table, $where = [], $columns = '*') {
        $results = $this->select($table, $where, $columns);
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * Raw query with manual encryption handling
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Get PDO instance for complex queries
     */
    public function getPDO() {
        return $this->pdo;
    }
}

// Global database wrapper instance
$dbWrapper = new DatabaseWrapper($pdo);
?>
