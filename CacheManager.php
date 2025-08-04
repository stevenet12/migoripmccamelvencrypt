<?php
class CacheManager {
    public static function get($key) {
        $file = __DIR__ . "/cache/{$key}.json";
        if (file_exists($file) && (filemtime($file) > (time() - 86400))) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }

    public static function set($key, $data, $ttl = 86400) {
        $file = __DIR__ . "/cache/{$key}.json";
        file_put_contents($file, json_encode($data));
    }
}
