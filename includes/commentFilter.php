<?php
/**
 * Advanced Comment Filtering System
 * Handles language detection, banned words, and flagged words filtering
 */

class CommentFilter {
    private $banned_words = [];
    private $flagged_words = [];

    public function __construct() {
        $this->loadWordLists();
    }

    /**
     * Load banned and flagged words from JSON file
     */
    private function loadWordLists() {
        $json_file = __DIR__ . '/../banned_and_flagged_words.json';

        if (file_exists($json_file)) {
            $word_data = json_decode(file_get_contents($json_file), true);
            $this->banned_words = array_map('strtolower', $word_data['banned_words'] ?? []);
            $this->flagged_words = array_map('strtolower', $word_data['flagged_words'] ?? []);
        } else {
            error_log("Word list file not found: $json_file");
        }
    }

    /**
     * Enhanced language detection with better accuracy
     */
    private function detectLanguage($text) {
        $text = strtolower(trim($text));
        $word_count = str_word_count($text);

        // Common Swahili words/patterns (expanded list)
        $swahili_indicators = [
            'habari', 'asante', 'karibu', 'hujambo', 'pole', 'sawa', 'ndiyo', 'hapana',
            'kitu', 'mtu', 'watu', 'nyumba', 'shule', 'kazi', 'pesa', 'chakula',
            'maji', 'jina', 'hali', 'mahali', 'wakati', 'siku', 'wiki', 'mwezi',
            'mwaka', 'leo', 'jana', 'kesho', 'asubuhi', 'mchana', 'jioni', 'usiku',
            'ninyi', 'wewe', 'yeye', 'sisi', 'mimi', 'wao', 'hii', 'hiyo', 'hizi',
            'na', 'ya', 'wa', 'za', 'la', 'cha', 'kwa', 'katika', 'kwenye', 'ni',
            'au', 'lakini', 'pia', 'tu', 'ndio', 'bado', 'sana', 'kwanza', 'baada',
            'kabla', 'hata', 'ikiwa', 'endapo', 'jambo', 'mambo', 'vizuri',
            'mradi', 'serikali', 'kaunti', 'rais', 'gavana', 'mheshimiwa', 'bunge',
            'shirikisho', 'uchaguzi', 'kura', 'siasa', 'demokrasia', 'harambee'
        ];

        // Common English words (expanded with project-related terms)
        $english_indicators = [
            'the', 'and', 'is', 'are', 'was', 'were', 'have', 'has', 'had',
            'will', 'would', 'could', 'should', 'can', 'may', 'must', 'shall',
            'this', 'that', 'these', 'those', 'with', 'from', 'they', 'them',
            'their', 'there', 'where', 'when', 'what', 'why', 'how', 'who',
            'but', 'or', 'not', 'yes', 'no', 'good', 'bad', 'very', 'also',
            'just', 'only', 'like', 'well', 'now', 'get', 'make', 'do', 'go',
            'project', 'government', 'county', 'progress', 'development', 'budget',
            'construction', 'road', 'school', 'hospital', 'water', 'infrastructure'
        ];

        // Count word boundaries to avoid false positives
        $swahili_count = 0;
        $english_count = 0;
        $words_found = [];

        foreach ($swahili_indicators as $indicator) {
            if (preg_match('/\b' . preg_quote($indicator, '/') . '\b/', $text)) {
                $swahili_count++;
                $words_found['swahili'][] = $indicator;
            }
        }

        foreach ($english_indicators as $indicator) {
            if (preg_match('/\b' . preg_quote($indicator, '/') . '\b/', $text)) {
                $english_count++;
                $words_found['english'][] = $indicator;
            }
        }

        // Log detection results for debugging
        error_log("Language detection - Text: " . substr($text, 0, 100) . "..., Swahili: $swahili_count, English: $english_count, Words: $word_count");

        // For very short text (less than 5 words), be more permissive
        if ($word_count < 5) {
            if ($swahili_count > 0 && $swahili_count >= $english_count) {
                return 'sw';
            }
            if ($english_count > 0) {
                return 'en';
            }
            // Default to English for very short texts without clear indicators
            return 'en';
        }

        // For short text (5-10 words), require at least 1 clear indicator
        if ($word_count <= 10) {
            if ($swahili_count >= 1 && $swahili_count > $english_count) {
                return 'sw';
            }
            if ($english_count >= 1 && $english_count > $swahili_count) {
                return 'en';
            }
            // If equal or no clear indicators, default to English
            return 'en';
        }

        // For longer texts, require stronger evidence
        $swahili_ratio = $swahili_count / $word_count;
        $english_ratio = $english_count / $word_count;

        if ($swahili_count >= 2 && $swahili_ratio > 0.1 && $swahili_count > $english_count) {
            return 'sw';
        }

        if ($english_count >= 2 && $english_ratio > 0.1 && $english_count > $swahili_count) {
            return 'en';
        }

        // If we have some indicators but they're close, lean towards English
        if ($swahili_count > 0 || $english_count > 0) {
            return $english_count >= $swahili_count ? 'en' : 'sw';
        }

        // If no clear indicators found, mark as unknown for review
        return 'unknown';
    }

    /**
     * Check if text contains banned words
     */
    private function containsBannedWords($text) {
        $text = strtolower($text);
        $found_words = [];

        foreach ($this->banned_words as $banned_word) {
            // Use word boundaries to avoid false positives
            if (preg_match('/\b' . preg_quote($banned_word, '/') . '\b/i', $text)) {
                $found_words[] = $banned_word;
            }
        }

        return !empty($found_words) ? $found_words : false;
    }

    /**
     * Check if text contains flagged words
     */
    private function containsFlaggedWords($text) {
        $text = strtolower($text);
        $found_words = [];

        foreach ($this->flagged_words as $flagged_word) {
            // Use word boundaries to avoid false positives
            if (preg_match('/\b' . preg_quote($flagged_word, '/') . '\b/i', $text)) {
                $found_words[] = $flagged_word;
            }
        }

        return !empty($found_words) ? $found_words : false;
    }

    /**
     * Validate comment length and word count
     */
    private function validateCommentLength($text) {
        $words = str_word_count($text);

        if ($words < 3) {
            return [
                'valid' => false,
                'message' => 'Comment must contain at least 3 words.'
            ];
        }

        if ($words > 100) {
            return [
                'valid' => false,
                'message' => 'Comment cannot exceed 100 words. Current word count: ' . $words
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check for spam indicators
     */
    private function isSpam($text) {
        // Check for common spam indicators
        $spamPhrases = [
            'click here', 'free money', 'guaranteed', 'limited time',
            'act now', 'special offer', 'risk free', 'no obligation'
        ];

        $lowercaseText = strtolower($text);
        foreach ($spamPhrases as $phrase) {
            if (strpos($lowercaseText, $phrase) !== false) {
                return true;
            }
        }

        // Check for URLs
        if (preg_match('/https?:\/\/|www\./i', $text)) {
            return true;
        }

        // Check for email patterns
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check for excessive repetition
     */
    private function hasExcessiveRepetition($text) {
        // Check for repeated characters (more than 3 in a row)
        if (preg_match('/(.)\1{3,}/', $text)) {
            return true;
        }

        // Check for repeated words
        $words = explode(' ', strtolower($text));
        $wordCounts = array_count_values($words);
        foreach ($wordCounts as $count) {
            if ($count > 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * Main filtering function
     */
    public function filterComment($comment_text) {
        $result = [
            'status' => '',
            'message' => '',
            'reason' => '',
            'details' => []
        ];

        // 1. Validate comment length and word count
        $length_validation = $this->validateCommentLength($comment_text);
        if (!$length_validation['valid']) {
            $result['status'] = 'rejected';
            $result['message'] = $length_validation['message'];
            $result['reason'] = 'invalid_length';
            $result['details']['reason'] = 'invalid_length';
            return $result;
        }

        // 2. Check for banned words first (highest priority)
        $banned_words_found = $this->containsBannedWords($comment_text);
        if ($banned_words_found) {
            $result['status'] = 'rejected';
            $result['message'] = 'Your comment contains inappropriate language and cannot be posted. Please revise your comment and try again.';
            $result['reason'] = 'banned_words';
            $result['details']['reason'] = 'banned_words';
            $result['details']['banned_words_found'] = $banned_words_found;
            return $result;
        }

        // 3. Check for spam indicators
        if ($this->isSpam($comment_text)) {
            $result['status'] = 'rejected';
            $result['message'] = 'Your comment appears to contain spam content and cannot be posted.';
            $result['reason'] = 'spam_detected';
            $result['details']['reason'] = 'spam_detected';
            return $result;
        }

        // 4. Check for excessive repetition
        if ($this->hasExcessiveRepetition($comment_text)) {
            $result['status'] = 'pending_review';
            $result['message'] = 'Your comment has been submitted for review due to excessive repetition.';
            $result['reason'] = 'excessive_repetition';
            $result['details']['reason'] = 'excessive_repetition';
            return $result;
        }

        // 5. Check for flagged words
        $flagged_words_found = $this->containsFlaggedWords($comment_text);
        if ($flagged_words_found) {
            $result['status'] = 'pending_review';
            $result['message'] = 'Your comment has been submitted for review due to potentially sensitive content. It will be published after approval by our moderation team.';
            $result['reason'] = 'flagged_words';
            $result['details']['reason'] = 'flagged_words';
            $result['details']['flagged_words_found'] = $flagged_words_found;
            return $result;
        }

        // 6. Language Detection
        $detected_language = $this->detectLanguage($comment_text);
        $result['details']['detected_language'] = $detected_language;

        // Only allow English (en) and Swahili (sw)
        if (!in_array($detected_language, ['en', 'sw'])) {
            // If language is not English or Swahili, send for review
            $result['status'] = 'pending_review';
            $result['message'] = 'Your comment has been submitted for review to verify it meets our language requirements (English or Kiswahili only).';
            $result['reason'] = 'language_review';
            $result['details']['reason'] = 'language_review';
            return $result;
        }

        // 7. Comment is clean - auto-approve
        $result['status'] = 'approved';
        $result['message'] = 'Your comment has been posted successfully!';
        $result['reason'] = 'clean_content';
        $result['details']['reason'] = 'clean_content';

        return $result;
    }

    /**
     * Get filtering statistics
     */
    public function getFilteringStats() {
        return [
            'banned_words_count' => count($this->banned_words),
            'flagged_words_count' => count($this->flagged_words),
            'supported_languages' => ['en', 'sw']
        ];
    }
}

/**
 * Global wrapper function for backward compatibility
 */
if (!function_exists('filter_comment')) {
    function filter_comment($message, $citizen_name = '') {
        try {
            if (class_exists('CommentFilter')) {
                $filter = new CommentFilter();
                return $filter->filterComment($message);
            }
        } catch (Exception $e) {
            error_log("Filter comment error: " . $e->getMessage());
        }
        
        return ['status' => 'pending_review', 'message' => 'Submitted for review', 'reason' => 'system_error'];
    }
}

/**
 * Save comment with proper status
 */
function save_comment_with_filtering($project_id, $comment_text, $user_name, $user_email = null, $parent_id = 0, $filter_result = null) {
    global $pdo;

    try {
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $subject = $parent_id > 0 ? "Reply to comment" : "Project Comment";

        // Determine status based on filtering result
        $status = $filter_result ? $filter_result['status'] : 'pending_review';

        // Map filtering statuses to database statuses
        $db_status = match($status) {
            'approved' => 'approved',
            'pending_review' => 'pending',
            'rejected' => 'rejected',
            default => 'pending'
        };

        // Add filtering metadata
        $filtering_metadata = $filter_result ? json_encode($filter_result['details']) : null;

        $sql = "INSERT INTO feedback 
                (project_id, citizen_name, citizen_email, subject, message, status, 
                 parent_comment_id, user_ip, user_agent, filtering_metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            $project_id, $user_name, $user_email, $subject, $comment_text, 
            $db_status, $parent_id, $user_ip, $user_agent, $filtering_metadata
        ]);

        if ($result) {
            $comment_id = $pdo->lastInsertId();

            // Log the filtering action if function exists
            if (function_exists('log_activity')) {
                log_activity(
                    'comment_filtered', 
                    "Comment filtered with status: $status for project ID: $project_id", 
                    null, 
                    'comment', 
                    $comment_id,
                    $filter_result['details'] ?? []
                );
            }

            return [
                'success' => true, 
                'comment_id' => $comment_id,
                'status' => $status,
                'message' => $filter_result['message'] ?? 'Comment saved successfully'
            ];
        }

        return ['success' => false, 'message' => 'Failed to save comment'];

    } catch (Exception $e) {
        error_log("Save filtered comment error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}
?>