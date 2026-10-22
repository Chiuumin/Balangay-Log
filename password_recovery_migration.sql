-- Create server-side state for SMS password recovery.
-- Apply once to the BalangayLog database before enabling password_recovery.php.

CREATE TABLE IF NOT EXISTS `password_recovery_challenges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `flow_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `otp_hash` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `session_send_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `last_sent_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `reset_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `reset_expires_at` datetime DEFAULT NULL,
  `window_send_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `send_window_started_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_recovery_user` (`user_id`),
  KEY `idx_password_recovery_expiry` (`otp_expires_at`, `reset_expires_at`),
  CONSTRAINT `fk_password_recovery_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
