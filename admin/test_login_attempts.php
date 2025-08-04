
<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Test the login attempt logging
echo "Testing login attempt logging...\n";

// Check if login_attempts table exists
try {
    $stmt = $pdo->prepare("DESCRIBE login_attempts");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Login attempts table structure:\n";
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']}\n";
    }
    echo "\n";
    
} catch (Exception $e) {
    echo "Error checking table structure: " . $e->getMessage() . "\n";
}

// Test logging a login attempt
try {
    echo "Testing log_login_attempt function...\n";
    log_login_attempt('test@example.com', 'fail', null, 'Test failure reason');
    echo "Login attempt logged successfully!\n";
    
    // Check if it was actually inserted using proper decryption
    $latest = pdo_select_one($pdo, "SELECT * FROM login_attempts ORDER BY timestamp DESC LIMIT 1", [], 'login_attempts');
    
    if ($latest) {
        echo "Latest login attempt record (decrypted):\n";
        foreach ($latest as $key => $value) {
            // Truncate long values for readability
            $display_value = (strlen($value) > 50) ? substr($value, 0, 50) . '...' : $value;
            echo "- $key: $display_value\n";
        }
    } else {
        echo "No login attempt record found!\n";
    }
    
} catch (Exception $e) {
    echo "Error testing login attempt: " . $e->getMessage() . "\n";
}

// Test the record_login_attempt function from auth.php
try {
    echo "\nTesting record_login_attempt function...\n";
    record_login_attempt('test2@example.com', 'success', 1, null);
    echo "record_login_attempt executed successfully!\n";
    
} catch (Exception $e) {
    echo "Error testing record_login_attempt: " . $e->getMessage() . "\n";
}

// Test raw database count to verify insertion
try {
    echo "\nChecking raw database count...\n";
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM login_attempts");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    echo "Total login attempts in database: $count\n";
    
    if ($count > 0) {
        echo "Records exist in database - issue is with decryption/retrieval\n";
        
        // Show raw encrypted data (first record)
        $stmt = $pdo->prepare("SELECT email, status, timestamp FROM login_attempts ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute();
        $raw = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($raw) {
            echo "Raw database record (may be encrypted):\n";
            foreach ($raw as $key => $value) {
                $display_value = (strlen($value) > 50) ? substr($value, 0, 50) . '...' : $value;
                echo "- $key: $display_value\n";
            }
        }
    } else {
        echo "No records found - insertion is failing\n";
    }
} catch (Exception $e) {
    echo "Error checking database: " . $e->getMessage() . "\n";
}
?>
