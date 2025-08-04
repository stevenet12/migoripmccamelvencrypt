<?php
/**
 * Performance Optimization Module
 * Implements caching, connection pooling, and performance monitoring
 */

class PerformanceManager {
    private static $query_cache = [];
    private static $start_time;
    private static $memory_start;
    
    public static function init() {
        self::$start_time = microtime(true);
        self::$memory_start = memory_get_usage(true);
        
        // Enable output compression
        if (!ob_get_level() && extension_loaded('zlib')) {
            ob_start('ob_gzhandler');
        }
        
        // Set performance headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        
        // Enable browser caching for static assets
        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg)$/i', $_SERVER['REQUEST_URI'])) {
            header('Cache-Control: public, max-age=31536000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        }
    }
    
    /**
     * Cache database queries to reduce load
     */
    public static function cacheQuery($key, $callback, $ttl = 300) {
        $cache_file = __DIR__ . '/../cache/query_' . md5($key) . '.cache';
        
        // Check if cache exists and is valid
        if (file_exists($cache_file)) {
            $cache_data = unserialize(file_get_contents($cache_file));
            if ($cache_data['expires'] > time()) {
                return $cache_data['data'];
            }
            unlink($cache_file);
        }
        
        // Execute query and cache result
        $result = $callback();
        
        if (!is_dir(dirname($cache_file))) {
            mkdir(dirname($cache_file), 0755, true);
        }
        
        $cache_data = [
            'data' => $result,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        file_put_contents($cache_file, serialize($cache_data));
        return $result;
    }
    
    /**
     * Optimize database queries
     */
    public static function optimizeQuery($sql, $params = []) {
        global $pdo;
        
        $query_key = md5($sql . serialize($params));
        
        // Cache prepared statements
        if (!isset(self::$query_cache[$query_key])) {
            self::$query_cache[$query_key] = $pdo->prepare($sql);
        }
        
        $stmt = self::$query_cache[$query_key];
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Monitor performance metrics
     */
    public static function getMetrics() {
        return [
            'execution_time' => round((microtime(true) - self::$start_time) * 1000, 2),
            'memory_usage' => round((memory_get_usage(true) - self::$memory_start) / 1024 / 1024, 2),
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'queries_cached' => count(self::$query_cache)
        ];
    }
    
    /**
     * Clear expired cache files
     */
    public static function clearExpiredCache() {
        $cache_dir = __DIR__ . '/../cache/';
        if (!is_dir($cache_dir)) return;

        $files = glob($cache_dir . '*.cache');
        $cleared = 0;

        foreach ($files as $file) {
            if (file_exists($file)) {
                $cache_data = @unserialize(file_get_contents($file));
                if (!$cache_data || $cache_data['expires'] < time()) {
                    unlink($file);
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * Check database connection health
     */
    public static function checkDatabaseHealth() {
        global $pdo;

        $health = [
            'status' => 'healthy',
            'response_time_ms' => 0,
            'connections' => 0,
            'max_connections' => 0,
            'uptime_seconds' => 0,
            'error' => null
        ];

        try {
            $start_time = microtime(true);

            // Test basic connection
            $stmt = $pdo->query("SELECT 1");
            $stmt->fetch();

            $health['response_time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

            // Get connection statistics
            try {
                $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
                $result = $stmt->fetch();
                $health['connections'] = (int)$result['Value'];

                $stmt = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
                $result = $stmt->fetch();
                $health['max_connections'] = (int)$result['Value'];

                $stmt = $pdo->query("SHOW STATUS LIKE 'Uptime'");
                $result = $stmt->fetch();
                $health['uptime_seconds'] = (int)$result['Value'];
            } catch (Exception $e) {
                // Non-critical stats failure
                error_log("Database stats collection failed: " . $e->getMessage());
            }

            // Check if response time is concerning
            if ($health['response_time_ms'] > 1000) {
                $health['status'] = 'degraded';
            }

        } catch (Exception $e) {
            $health['status'] = 'unhealthy';
            $health['error'] = $e->getMessage();
            error_log("Database health check failed: " . $e->getMessage());
        }

        return $health;
    }

    /**
     * Get detailed database statistics
     */
    public static function getDatabaseStats() {
        global $pdo;

        $stats = [
            'active_connections' => 0,
            'max_connections' => 0,
            'uptime_seconds' => 0,
            'queries_per_second' => 0,
            'innodb_buffer_pool_size' => 0,
            'table_locks_waited' => 0,
            'slow_queries' => 0
        ];

        try {
            // Get connection info
            $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_connected'");
            $result = $stmt->fetch();
            $stats['active_connections'] = (int)($result['Value'] ?? 0);

            $stmt = $pdo->query("SHOW VARIABLES LIKE 'max_connections'");
            $result = $stmt->fetch();
            $stats['max_connections'] = (int)($result['Value'] ?? 0);

            $stmt = $pdo->query("SHOW STATUS LIKE 'Uptime'");
            $result = $stmt->fetch();
            $stats['uptime_seconds'] = (int)($result['Value'] ?? 0);

            // Get queries per second (approximation)
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'");
            $result = $stmt->fetch();
            $questions = (int)($result['Value'] ?? 0);
            $stats['queries_per_second'] = round($questions / max(1, $stats['uptime_seconds']), 2);

            // Get InnoDB buffer pool size
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_pages_total'");
            $result = $stmt->fetch();
            $totalPages = (int)($result['Value'] ?? 0);

            $stmt = $pdo->query("SHOW GLOBAL VARIABLES LIKE 'Innodb_page_size'");
            $result = $stmt->fetch();
            $pageSize = (int)($result['Value'] ?? 16384);  // Default InnoDB page size is 16KB

            $stats['innodb_buffer_pool_size'] = round(($totalPages * $pageSize) / (1024 * 1024), 2);

             // Table Locks Waited
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Table_locks_waited'");
            $result = $stmt->fetch();
            $stats['table_locks_waited'] = (int)($result['Value'] ?? 0);
            
            // Slow Queries
            $stmt = $pdo->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'");
            $result = $stmt->fetch();
            $stats['slow_queries'] = (int)($result['Value'] ?? 0);

        } catch (Exception $e) {
            error_log("Database stats collection failed: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Optimize database performance
     */
    public static function optimizeDatabase() {
        global $pdo;

        $optimizations = [
            'tables_optimized' => 0,
            'indexes_analyzed' => 0,
            'cache_cleared' => 0,
            'errors' => []
        ];

        try {
            // Get all tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                try {
                    // Optimize table
                    $pdo->query("OPTIMIZE TABLE `$table`");
                    $optimizations['tables_optimized']++;

                    // Analyze table for better query planning
                    $pdo->query("ANALYZE TABLE `$table`");
                    $optimizations['indexes_analyzed']++;

                } catch (Exception $e) {
                    $optimizations['errors'][] = "Table $table: " . $e->getMessage();
                }
            }

            // Clear query cache if available
            try {
                $pdo->query("RESET QUERY CACHE");
                $optimizations['cache_cleared'] = 1;
            } catch (Exception $e) {
                // Query cache might not be available
            }

        } catch (Exception $e) {
            $optimizations['errors'][] = "General optimization error: " . $e->getMessage();
        }

        return $optimizations;
    }

    /**
     * Monitor slow queries
     */
    public static function getSlowQueries($limit = 10) {
        global $pdo;

        $slow_queries = [];

        try {
            // Try to get slow queries from performance schema
            $stmt = $pdo->prepare("
                SELECT 
                    SUBSTR(digest_text, 1, 200) as query_text,
                    count_star as exec_count,
                    avg_timer_wait/1000000000 as avg_time_seconds,
                    max_timer_wait/1000000000 as max_time_seconds
                FROM performance_schema.events_statements_summary_by_digest 
                WHERE avg_timer_wait > 1000000000
                ORDER BY avg_timer_wait DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $slow_queries = $stmt->fetchAll();

        } catch (Exception $e) {
            // Performance schema might not be available
            try {
                // Fallback to slow query log
                $stmt = $pdo->prepare("
                    SELECT 
                        sql_text as query_text,
                        query_time as avg_time_seconds,
                        start_time
                    FROM mysql.slow_log 
                    WHERE start_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    ORDER BY query_time DESC 
                    LIMIT ?
                ");
                $stmt->execute([$limit]);
                $slow_queries = $stmt->fetchAll();

            } catch (Exception $e2) {
                // Slow query log not available
                error_log("Could not retrieve slow queries: " . $e2->getMessage());
            }
        }

        return $slow_queries;
    }

    /**
     * Get database size and usage statistics
     */
    public static function getDatabaseStats_old() {
        global $pdo;

        $stats = [
            'total_size_mb' => 0,
            'data_size_mb' => 0,
            'index_size_mb' => 0,
            'table_count' => 0,
            'largest_tables' => []
        ];

        try {
            // Get database name
            $stmt = $pdo->query("SELECT DATABASE()");
            $db_name = $stmt->fetchColumn();

            // Get table statistics
            $stmt = $pdo->prepare("
                SELECT 
                    table_name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb,
                    ROUND((data_length / 1024 / 1024), 2) as data_mb,
                    ROUND((index_length / 1024 / 1024), 2) as index_mb,
                    table_rows
                FROM information_schema.tables
                WHERE table_schema = ?
                ORDER BY (data_length + index_length) DESC
            ");
            $stmt->execute([$db_name]);
            $tables = $stmt->fetchAll();

            $stats['table_count'] = count($tables);
            $stats['largest_tables'] = array_slice($tables, 0, 5);

            // Calculate totals
            foreach ($tables as $table) {
                $stats['total_size_mb'] += $table['size_mb'];
                $stats['data_size_mb'] += $table['data_mb'];
                $stats['index_size_mb'] += $table['index_mb'];
            }

        } catch (Exception $e) {
            error_log("Database stats collection failed: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Connection pool management
     */
    public static function optimizeConnections() {
        global $pdo;

        try {
            // Get connection statistics
            $stmt = $pdo->query("SHOW STATUS LIKE 'Threads_%'");
            $connection_stats = [];
            while ($row = $stmt->fetch()) {
                $connection_stats[$row['Variable_name']] = $row['Value'];
            }

            // Log connection health
            error_log("Database Connections - Connected: " . ($connection_stats['Threads_connected'] ?? 0) . 
                     ", Running: " . ($connection_stats['Threads_running'] ?? 0));

            return $connection_stats;

        } catch (Exception $e) {
            error_log("Connection optimization failed: " . $e->getMessage());
            return [];
        }
    }
}

// Global functions for backward compatibility
function check_database_health() {
    return PerformanceManager::checkDatabaseHealth();
}

function get_database_stats() {
    return PerformanceManager::getDatabaseStats();
}

// Auto-initialize performance optimizations
PerformanceManager::init();