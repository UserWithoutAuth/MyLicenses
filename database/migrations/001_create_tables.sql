-- License Server Database Schema
-- Version: 1.0.0
-- Description: Complete database schema for license management system

-- ============================================
-- PRODUCTS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `products` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `description` TEXT,
    `version` VARCHAR(50),
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_products_slug` (`slug`),
    INDEX `idx_products_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LICENSE TIERS/PLANS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `license_tiers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'Basic, Pro, Enterprise, etc.',
    `slug` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `max_activations` INT UNSIGNED DEFAULT 1,
    `duration_days` INT UNSIGNED DEFAULT 365,
    `price` DECIMAL(10, 2),
    `currency` VARCHAR(3) DEFAULT 'USD',
    `features` JSON COMMENT 'Feature flags for this tier',
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    INDEX `idx_tiers_product` (`product_id`),
    INDEX `idx_tiers_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CUSTOMERS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `customers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` CHAR(36) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255),
    `company` VARCHAR(255),
    `phone` VARCHAR(50),
    `country` VARCHAR(2) COMMENT 'ISO 3166-1 alpha-2',
    `address` TEXT,
    `password_hash` VARCHAR(255) COMMENT 'For customer portal login',
    `email_verified_at` TIMESTAMP NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `notes` TEXT,
    `metadata` JSON COMMENT 'Additional custom fields',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customers_email` (`email`),
    INDEX `idx_customers_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LICENSES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `licenses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `license_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Human-readable license key',
    `product_id` BIGINT UNSIGNED NOT NULL,
    `tier_id` BIGINT UNSIGNED,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `license_type` ENUM('standard', 'trial', 'floating', 'node_locked') DEFAULT 'standard',
    `status` ENUM('active', 'expired', 'suspended', 'revoked', 'pending') DEFAULT 'active',
    `max_activations` INT UNSIGNED DEFAULT 1,
    `current_activations` INT UNSIGNED DEFAULT 0,
    `issued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL,
    `grace_period_days` INT UNSIGNED DEFAULT 7,
    `expiry_message` TEXT COMMENT 'Custom message shown when license expires',
    `allow_offline` BOOLEAN DEFAULT TRUE,
    `allow_transfer` BOOLEAN DEFAULT TRUE,
    `transfer_count` INT UNSIGNED DEFAULT 0,
    `max_transfers` INT UNSIGNED DEFAULT 3,
    `last_transfer_at` TIMESTAMP NULL,
    `custom_fields` JSON COMMENT 'Product-specific metadata',
    `features` JSON COMMENT 'Enabled feature flags',
    `ip_restrictions` JSON COMMENT 'Allowed IP addresses or ranges',
    `geo_restrictions` JSON COMMENT 'Allowed/blocked countries',
    `notes` TEXT COMMENT 'Internal notes',
    `revoked_at` TIMESTAMP NULL,
    `revoked_reason` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tier_id`) REFERENCES `license_tiers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    INDEX `idx_licenses_key` (`license_key`),
    INDEX `idx_licenses_customer` (`customer_id`),
    INDEX `idx_licenses_product` (`product_id`),
    INDEX `idx_licenses_status` (`status`),
    INDEX `idx_licenses_expires` (`expires_at`),
    INDEX `idx_licenses_type` (`license_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MACHINES/DEVICES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `machines` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fingerprint` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique hardware fingerprint',
    `fingerprint_hash` VARCHAR(64) NOT NULL COMMENT 'SHA256 hash for quick lookup',
    `public_key` TEXT NOT NULL COMMENT 'Machine RSA public key',
    `machine_name` VARCHAR(255),
    `os_info` VARCHAR(255),
    `cpu_info` VARCHAR(255),
    `mac_address` VARCHAR(255),
    `disk_serial` VARCHAR(255),
    `motherboard_id` VARCHAR(255),
    `first_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_blacklisted` BOOLEAN DEFAULT FALSE,
    `blacklist_reason` TEXT,
    `metadata` JSON,
    INDEX `idx_machines_fingerprint` (`fingerprint_hash`),
    INDEX `idx_machines_blacklist` (`is_blacklisted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- LICENSE ACTIVATIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `activations` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `license_id` BIGINT UNSIGNED NOT NULL,
    `machine_id` BIGINT UNSIGNED NOT NULL,
    `activation_token` VARCHAR(255) UNIQUE COMMENT 'Unique activation identifier',
    `activation_type` ENUM('online', 'offline') DEFAULT 'online',
    `status` ENUM('active', 'deactivated', 'expired', 'transferred') DEFAULT 'active',
    `activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deactivated_at` TIMESTAMP NULL,
    `last_ping_at` TIMESTAMP NULL,
    `ping_count` INT UNSIGNED DEFAULT 0,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `app_version` VARCHAR(50),
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_license_machine` (`license_id`, `machine_id`, `status`),
    INDEX `idx_activations_license` (`license_id`),
    INDEX `idx_activations_machine` (`machine_id`),
    INDEX `idx_activations_status` (`status`),
    INDEX `idx_activations_token` (`activation_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- PING/HEARTBEAT TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `pings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `activation_id` BIGINT UNSIGNED NOT NULL,
    `license_id` BIGINT UNSIGNED NOT NULL,
    `machine_id` BIGINT UNSIGNED NOT NULL,
    `ping_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45),
    `app_version` VARCHAR(50),
    `uptime_seconds` BIGINT UNSIGNED COMMENT 'Application uptime',
    `status_code` SMALLINT DEFAULT 200,
    `response_time_ms` INT UNSIGNED,
    `metadata` JSON COMMENT 'Custom ping data',
    FOREIGN KEY (`activation_id`) REFERENCES `activations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE,
    INDEX `idx_pings_activation` (`activation_id`),
    INDEX `idx_pings_license` (`license_id`),
    INDEX `idx_pings_timestamp` (`ping_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USERS TABLE (Admin & Portal Users)
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` CHAR(36) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `username` VARCHAR(100) UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255),
    `role` ENUM('super_admin', 'admin', 'support', 'viewer') DEFAULT 'viewer',
    `is_active` BOOLEAN DEFAULT TRUE,
    `email_verified_at` TIMESTAMP NULL,
    `two_factor_secret` VARCHAR(255) NULL COMMENT 'TOTP secret',
    `two_factor_enabled` BOOLEAN DEFAULT FALSE,
    `two_factor_recovery_codes` JSON,
    `password_changed_at` TIMESTAMP NULL,
    `last_login_at` TIMESTAMP NULL,
    `last_login_ip` VARCHAR(45),
    `failed_login_attempts` INT UNSIGNED DEFAULT 0,
    `locked_until` TIMESTAMP NULL,
    `ip_whitelist` JSON COMMENT 'Allowed IP addresses',
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- API TOKENS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED,
    `name` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL UNIQUE,
    `scopes` JSON COMMENT 'API permissions',
    `ip_restrictions` JSON,
    `rate_limit` INT UNSIGNED DEFAULT 60,
    `last_used_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_api_tokens_token` (`token`),
    INDEX `idx_api_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUDIT LOG TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(255) NOT NULL,
    `entity_type` VARCHAR(100) COMMENT 'license, customer, user, etc.',
    `entity_id` BIGINT UNSIGNED,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- WEBHOOKS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `webhooks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `url` VARCHAR(512) NOT NULL,
    `events` JSON NOT NULL COMMENT 'Events to listen for',
    `secret` VARCHAR(255) COMMENT 'Signature verification secret',
    `is_active` BOOLEAN DEFAULT TRUE,
    `last_triggered_at` TIMESTAMP NULL,
    `failed_attempts` INT UNSIGNED DEFAULT 0,
    `metadata` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_webhooks_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- WEBHOOK DELIVERIES TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `webhook_id` BIGINT UNSIGNED NOT NULL,
    `event` VARCHAR(100) NOT NULL,
    `payload` JSON,
    `response_status` SMALLINT,
    `response_body` TEXT,
    `attempts` INT UNSIGNED DEFAULT 1,
    `delivered_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`webhook_id`) REFERENCES `webhooks`(`id`) ON DELETE CASCADE,
    INDEX `idx_webhook_deliveries_webhook` (`webhook_id`),
    INDEX `idx_webhook_deliveries_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SESSIONS TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(255) PRIMARY KEY,
    `user_id` BIGINT UNSIGNED,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `payload` TEXT,
    `device_fingerprint` VARCHAR(255),
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sessions_user` (`user_id`),
    INDEX `idx_sessions_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- BLACKLIST TABLE (Revoked licenses, machines, IPs)
-- ============================================
CREATE TABLE IF NOT EXISTS `blacklist` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('license', 'machine', 'ip', 'email') NOT NULL,
    `value` VARCHAR(255) NOT NULL,
    `reason` TEXT,
    `expires_at` TIMESTAMP NULL,
    `is_permanent` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_blacklist_type` (`type`),
    INDEX `idx_blacklist_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATIONS QUEUE TABLE
-- ============================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('email', 'sms', 'webhook') NOT NULL,
    `recipient` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255),
    `message` TEXT,
    `metadata` JSON,
    `status` ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    `attempts` INT UNSIGNED DEFAULT 0,
    `sent_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notifications_status` (`status`),
    INDEX `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
