<?php
/**
 * Project Subscription Management System
 * Handles email subscriptions for project updates
 */
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/EncryptionManager.php';

class ProjectSubscriptionManager {
    private $pdo;
    private $baseUrl;

    public function __construct($pdo, $baseUrl = '') {
        $this->pdo = $pdo;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        EncryptionManager::init($this->pdo);
    }

    public function subscribe($project_id, $email, $ip_address = null, $user_agent = null) {
        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Please enter a valid email address'];
            }

            // Check if already subscribed using the same logic as login system
            $existing = null;
            if (EncryptionManager::getEncryptionMode()) {
                // When encryption is enabled, search through all subscriptions for this project
                $stmt = $this->pdo->prepare("SELECT id, email, is_active FROM project_subscriptions WHERE project_id = ?");
                $stmt->execute([$project_id]);
                $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($subscriptions as $sub) {
                    $decrypted_email = EncryptionManager::decryptIfNeeded($sub['email']);
                    if (strtolower($decrypted_email) === strtolower($email)) {
                        $existing = $sub;
                        break;
                    }
                }
            } else {
                // When encryption is disabled, direct search works
                $stmt = $this->pdo->prepare("SELECT id, is_active FROM project_subscriptions WHERE project_id = ? AND LOWER(email) = LOWER(?)");
                $stmt->execute([$project_id, $email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($existing) {
                if ($existing['is_active']) {
                    return ['success' => false, 'message' => 'You are already subscribed to updates for this project'];
                } else {
                    // Reactivate existing subscription
                    $stmt = $this->pdo->prepare("UPDATE project_subscriptions SET is_active = 1, subscribed_at = NOW() WHERE id = ?");
                    if ($stmt->execute([$existing['id']])) {
                        return ['success' => true, 'message' => 'Your subscription has been reactivated'];
                    } else {
                        return ['success' => false, 'message' => 'Failed to reactivate subscription. Please try again.'];
                    }
                }
            }

            // Create new subscription
            $subscription_token = bin2hex(random_bytes(32));
            $verification_token = bin2hex(random_bytes(32));
            $email_hash = hash('sha256', strtolower(trim($email)));

            // Prepare data for insertion
            $data = [
                'project_id' => $project_id,
                'email' => $email,
                'email_hash' => $email_hash,
                'subscription_token' => $subscription_token,
                'verification_token' => $verification_token,
                'ip_address' => $ip_address ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'user_agent' => $user_agent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
                'is_active' => 1,
                'email_verified' => 0,
                'subscribed_at' => date('Y-m-d H:i:s')
            ];

            // Use EncryptionManager to insert with proper encryption handling
            if (EncryptionManager::insertEncrypted($this->pdo, 'project_subscriptions', $data)) {
                // Send verification email
                $verification_link = (defined('BASE_URL') ? BASE_URL : '') . "api/verifySubscription.php?token=" . $verification_token;
                $email_sent = $this->sendVerificationEmail($project_id, $email, $verification_token);
                $this->log_activity('project_subscription', "New subscription for project ID: $project_id from email: $email");
                
                if ($email_sent) {
                    return [
                        'success' => true,
                        'message' => 'Subscription successful! Please check your email to verify.',
                        'subscription_token' => $subscription_token
                    ];
                } else {
                    return [
                        'success' => true,
                        'message' => 'Subscription created but verification email failed to send. Please contact support.',
                        'subscription_token' => $subscription_token
                    ];
                }
            } else {
                error_log("Database error in subscription: " . print_r($stmt->errorInfo(), true));
                return ['success' => false, 'message' => 'Database error occurred. Please try again.'];
            }
        } catch (Exception $e) {
            error_log("Subscription error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred. Please try again later.'];
        }
    }

    public function verifyEmail($verification_token) {
        try {
            $subscription = null;
            
            if (EncryptionManager::getEncryptionMode()) {
                // When encryption is enabled, search through all unverified subscriptions
                $stmt = $this->pdo->prepare("SELECT id, verification_token FROM project_subscriptions WHERE email_verified = 0 AND verification_token IS NOT NULL");
                $stmt->execute();
                $all_subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($all_subscriptions as $sub) {
                    $decrypted_token = EncryptionManager::decryptIfNeeded($sub['verification_token']);
                    if ($decrypted_token === $verification_token) {
                        $subscription = $sub;
                        break;
                    }
                }
            } else {
                // When encryption is disabled, direct search works
                $stmt = $this->pdo->prepare("SELECT id FROM project_subscriptions WHERE verification_token = ? AND email_verified = 0");
                $stmt->execute([$verification_token]);
                $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($subscription) {
                $stmt = $this->pdo->prepare("UPDATE project_subscriptions SET email_verified = 1, verification_token = NULL WHERE id = ?");
                $stmt->execute([$subscription['id']]);
                return ['success' => true, 'message' => 'Email verified successfully.'];
            }
            
            return ['success' => false, 'message' => 'Invalid or expired verification token.'];
        } catch (Exception $e) {
            error_log("Verify error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Verification failed. Try again.'];
        }
    }

    public function unsubscribe($token) {
        try {
            $subscription = null;
            
            if (EncryptionManager::getEncryptionMode()) {
                // When encryption is enabled, search through all active subscriptions
                $stmt = $this->pdo->prepare("SELECT id, subscription_token FROM project_subscriptions WHERE is_active = 1");
                $stmt->execute();
                $all_subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($all_subscriptions as $sub) {
                    $decrypted_token = EncryptionManager::decryptIfNeeded($sub['subscription_token']);
                    if ($decrypted_token === $token) {
                        $subscription = $sub;
                        break;
                    }
                }
            } else {
                // When encryption is disabled, direct search works
                $stmt = $this->pdo->prepare("SELECT id FROM project_subscriptions WHERE subscription_token = ? AND is_active = 1");
                $stmt->execute([$token]);
                $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($subscription) {
                $stmt = $this->pdo->prepare("UPDATE project_subscriptions SET is_active = 0, unsubscribed_at = NOW() WHERE id = ?");
                $stmt->execute([$subscription['id']]);
                return ['success' => true, 'message' => 'You have been unsubscribed successfully.'];
            }
            
            return ['success' => false, 'message' => 'Invalid or expired token.'];
        } catch (Exception $e) {
            error_log("Unsubscribe error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Unsubscribe failed.'];
        }
    }

    public function getSubscriberCount($project_id) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM project_subscriptions WHERE project_id = ? AND is_active = 1 AND email_verified = 1");
            $stmt->execute([$project_id]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Count error: " . $e->getMessage());
            return 0;
        }
    }

    public function sendProjectUpdate($project_id, $type, $details) {
        try {
            $stmt = $this->pdo->prepare("SELECT id, email, subscription_token FROM project_subscriptions WHERE project_id = ? AND is_active = 1 AND email_verified = 1");
            $stmt->execute([$project_id]);
            $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$project_id]);
            $project = $stmt->fetch();
            if (!$project) return false;

            $subject = $this->getUpdateSubject($type, $project['project_name']);
            $count = 0;

            foreach ($subscribers as $sub) {
                // Decrypt email only when needed for sending
                $decrypted_email = EncryptionManager::decryptIfNeeded($sub['email']);
                $decrypted_token = EncryptionManager::decryptIfNeeded($sub['subscription_token']);
                
                $unsubscribe = $this->baseUrl . "api/unsubscribe.php?token=" . urlencode($decrypted_token);
                $body = $this->getEmailTemplate('update', [
                    'project_name' => $project['project_name'],
                    'update_type' => $type,
                    'update_details' => $details,
                    'project_url' => $this->baseUrl . "projectDetails.php?id=" . $project_id,
                    'unsubscribe_url' => $unsubscribe,
                    'project_status' => ucfirst($project['status'] ?? 'Unknown'),
                    'project_progress' => $project['progress_percentage'] ?? 0
                ]);
                if ($this->sendEmail($decrypted_email, $subject, $body)) {
                    $count++;
                    $this->logNotification($sub['id'], $project_id, $type, $subject, $details);
                    $update = $this->pdo->prepare("UPDATE project_subscriptions SET last_notification_sent = NOW() WHERE id = ?");
                    $update->execute([$sub['id']]);
                }
            }

            $this->log_activity('project_update_notification', "Sent $count updates for project $project_id");
            return true;
        } catch (Exception $e) {
            error_log("Send update error: " . $e->getMessage());
            return false;
        }
    }

    private function sendVerificationEmail($project_id, $email, $token) {
        $stmt = $this->pdo->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        if (!$project) return false;

        $url = $this->baseUrl . "api/verifySubscription.php?token=" . urlencode($token);
        $subject = "Verify Your Project Subscription - {$project['project_name']}";
        $message = $this->getEmailTemplate('verification', [
            'project_name' => $project['project_name'],
            'verification_url' => $url,
            'project_url' => $this->baseUrl . "projectDetails.php?id=" . $project_id
        ]);

        return $this->sendEmail($email, $subject, $message);
    }

    private function getEmailTemplate($type, $data) {
        $logo = $this->baseUrl . "migoriLogo.png";
        $header = "
        <div style='background:#1e40af;padding:30px;text-align:center;border-radius:10px 10px 0 0;'>
            <img src='$logo' style='height:60px;margin-bottom:15px;'>
            <h1 style='color:white;font-size:24px;'>Migori County</h1>
            <p style='color:#e5f3ff;font-size:14px;'>Public Project Management</p>
        </div>";
        $footer = "
        <div style='background:#f8fafc;padding:20px;text-align:center;border-radius:0 0 10px 10px;'>
            <p style='font-size:12px;color:#64748b;'>You can unsubscribe anytime.<br>
            &copy; " . date('Y') . " Migori County Government.</p>
        </div>";

        if ($type === 'verification') {
            return "<div style='font-family:sans-serif;max-width:600px;margin:auto;background:white;border-radius:10px;'>$header
            <div style='padding:30px;'>
                <h2 style='color:#1f2937;'>Verify Your Subscription</h2>
                <p style='color:#4b5563;'>Thank you for subscribing to <strong>{$data['project_name']}</strong>.</p>
                <p><a href='{$data['verification_url']}' style='background:#3b82f6;color:white;padding:12px 30px;border-radius:25px;text-decoration:none;'>Verify Email</a></p>
                <p style='font-size:12px;color:#6b7280;'>If the button doesn't work, click or paste this:<br><a href='{$data['verification_url']}'>{$data['verification_url']}</a></p>
            </div>$footer</div>";
        }

        if ($type === 'update') {
            $icon = $this->getUpdateIcon($data['update_type']);
            return "<div style='font-family:sans-serif;max-width:600px;margin:auto;background:white;border-radius:10px;'>$header
            <div style='padding:30px;'>
                <h2>$icon {$data['update_type']} - {$data['project_name']}</h2>
                <p>Status: {$data['project_status']}, Progress: {$data['project_progress']}%</p>
                <p>{$data['update_details']}</p>
                <p><a href='{$data['project_url']}' style='background:#10b981;color:white;padding:12px 30px;border-radius:25px;text-decoration:none;'>View Project</a></p>
                <p style='font-size:12px;color:#6b7280;'>Want to stop receiving emails? <a href='{$data['unsubscribe_url']}' style='color:#dc2626;'>Unsubscribe</a></p>
            </div>$footer</div>";
        }

        return '';
    }

    private function getUpdateIcon($type) {
        $map = ['project_update' => '📋', 'status_change' => '🔄', 'completion' => '✅', 'milestone' => '🎯'];
        return $map[$type] ?? '📋';
    }

    private function getUpdateSubject($type, $name) {
        $map = [
            'project_update' => "Project Update: $name",
            'status_change' => "Status Change: $name",
            'completion' => "Project Completed: $name",
            'milestone' => "Milestone Reached: $name"
        ];
        return $map[$type] ?? "Project Update: $name";
    }

    private function sendEmail($to, $subject, $message) {
        $headers = "From: Migori County PMC <noreply@migori.go.ke>\r\n";
        $headers .= "Reply-To: hamisi@lakeside.co.ke\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: Migori PMC System\r\n";
        return mail($to, $subject, $message, $headers);
    }

    private function logNotification($subscription_id, $project_id, $type, $subject, $message) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO subscription_notifications (subscription_id, project_id, notification_type, subject, message) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$subscription_id, $project_id, $type, $subject, $message]);
        } catch (Exception $e) {
            error_log("Log notification error: " . $e->getMessage());
        }
    }

    private function log_activity($type, $message) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO activity_logs (log_type, message, ip_address, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([$type, $message, $_SERVER['REMOTE_ADDR'] ?? 'system', $_SERVER['HTTP_USER_AGENT'] ?? 'system']);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
}