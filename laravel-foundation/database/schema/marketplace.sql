-- Golden schema of the Marketplace and shared system tables (MySQL/MariaDB), captured from a database built by the legacy installer.
-- Applied by migration 2026_11_10_000003 with CREATE TABLE IF NOT EXISTS, so existing tables are never changed.

-- table: auth_users_v2
CREATE TABLE IF NOT EXISTS `auth_users_v2` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(120) NOT NULL,
  `name` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` varchar(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN',
  `status` varchar(32) NOT NULL DEFAULT 'Aktif',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_auth_v2_status` (`status`),
  KEY `idx_auth_v2_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_ad_spends
CREATE TABLE IF NOT EXISTS `marketplace_ad_spends` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `campaign_date` date NOT NULL,
  `campaign_name` varchar(150) NOT NULL,
  `spend_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_sales_generated` decimal(15,2) NOT NULL DEFAULT 0.00,
  `roas` decimal(8,2) GENERATED ALWAYS AS (case when `spend_amount` > 0 then `gross_sales_generated` / `spend_amount` else 0 end) STORED,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ads_store_date` (`store_id`,`campaign_date`),
  CONSTRAINT `fk_ads_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_api_capabilities
CREATE TABLE IF NOT EXISTS `marketplace_api_capabilities` (
  `connection_id` bigint(20) unsigned NOT NULL,
  `resource` varchar(30) NOT NULL,
  `state` varchar(30) NOT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `last_attempt_at` datetime NOT NULL,
  `last_error_code` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`connection_id`,`resource`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_api_credentials
CREATE TABLE IF NOT EXISTS `marketplace_api_credentials` (
  `id` varchar(50) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `platform` enum('Shopee','TikTok Shop') NOT NULL,
  `partner_id` varchar(100) DEFAULT NULL,
  `partner_key` varchar(255) DEFAULT NULL,
  `shop_id` varchar(100) DEFAULT NULL,
  `app_key` varchar(100) DEFAULT NULL,
  `app_secret` varchar(255) DEFAULT NULL,
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `cipher` varchar(255) DEFAULT NULL,
  `webhook_url` varchar(255) DEFAULT NULL,
  `webhook_secret` varchar(255) DEFAULT NULL,
  `connection_status` enum('Terhubung','Perlu Otorisasi','Token Expired','Error') NOT NULL DEFAULT 'Terhubung',
  `last_ping_at` datetime DEFAULT NULL,
  `last_sync_orders_at` datetime DEFAULT NULL,
  `last_sync_ads_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_id` (`store_id`),
  CONSTRAINT `fk_cred_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_app_settings
CREATE TABLE IF NOT EXISTS `marketplace_app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) NOT NULL DEFAULT 'financial',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_auto_sync
CREATE TABLE IF NOT EXISTS `marketplace_auto_sync` (
  `store_id` varchar(50) NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 0,
  `next_run_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_error_code` varchar(100) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`store_id`,`company_key`,`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_auto_sync_runtime
CREATE TABLE IF NOT EXISTS `marketplace_auto_sync_runtime` (
  `id` tinyint(4) NOT NULL,
  `heartbeat_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_branding
CREATE TABLE IF NOT EXISTS `marketplace_branding` (
  `company_key` varchar(120) NOT NULL,
  `logo_blob` mediumblob DEFAULT NULL,
  `logo_mime` varchar(40) DEFAULT NULL,
  `logo_size` smallint(6) NOT NULL DEFAULT 32,
  `logo_fit` varchar(12) NOT NULL DEFAULT 'contain',
  `logo_hash` varchar(64) DEFAULT NULL,
  `updated_by` varchar(120) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`company_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_catalog_variants
CREATE TABLE IF NOT EXISTS `marketplace_catalog_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `external_product_id` varchar(100) NOT NULL,
  `external_variant_id` varchar(100) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `parent_sku` varchar(100) DEFAULT NULL,
  `sku` varchar(100) NOT NULL,
  `variant_name` varchar(255) DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `price` decimal(15,2) DEFAULT NULL,
  `original_price` decimal(15,2) DEFAULT NULL,
  `stock_qty` decimal(15,3) DEFAULT NULL,
  `catalog_visible` tinyint(4) NOT NULL DEFAULT 1,
  `source_status` varchar(80) DEFAULT NULL,
  `source_updated_at` datetime NOT NULL,
  `base_price` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `catalog_identity` (`store_id`,`external_product_id`,`external_variant_id`),
  KEY `catalog_sku` (`store_id`,`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_expenses
CREATE TABLE IF NOT EXISTS `marketplace_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Biaya Iklan/Advertising Marketplace',
  `amount` decimal(15,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Transfer Bank',
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `affects_profit` tinyint(1) NOT NULL DEFAULT 1,
  `is_cashflow_only` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exp_store_date` (`store_id`,`expense_date`),
  CONSTRAINT `fk_exp_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_fulfillment_actions
CREATE TABLE IF NOT EXISTS `marketplace_fulfillment_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `package_id` varchar(120) NOT NULL,
  `order_id` varchar(120) NOT NULL,
  `user_key` varchar(120) NOT NULL,
  `state` varchar(30) NOT NULL,
  `offer_hash` char(64) DEFAULT NULL,
  `offer_json` text DEFAULT NULL,
  `offer_expires_at` datetime DEFAULT NULL,
  `handover_method` varchar(20) DEFAULT NULL,
  `last_error` varchar(80) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fulfillment_package` (`company_key`,`store_id`,`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_hpp_changes
CREATE TABLE IF NOT EXISTS `marketplace_hpp_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `old_hpp` decimal(15,2) DEFAULT NULL,
  `new_hpp` decimal(15,2) NOT NULL,
  `changed_by` varchar(120) NOT NULL,
  `affected_orders` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_hpp_history
CREATE TABLE IF NOT EXISTS `marketplace_hpp_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `marketplace_sku` varchar(100) NOT NULL,
  `product_variant_id` varchar(60) DEFAULT NULL,
  `unit_hpp` decimal(15,2) NOT NULL,
  `effective_from` date NOT NULL,
  `source` varchar(80) NOT NULL DEFAULT 'MANUAL',
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_hpp_effective` (`store_id`,`marketplace_sku`,`effective_from`),
  KEY `idx_marketplace_hpp_lookup` (`store_id`,`marketplace_sku`,`effective_from`),
  CONSTRAINT `fk_marketplace_hpp_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_integration_bindings
CREATE TABLE IF NOT EXISTS `marketplace_integration_bindings` (
  `connection_id` bigint(20) unsigned NOT NULL,
  `environment` varchar(20) NOT NULL,
  `context_hash` char(64) NOT NULL,
  `verified_at` datetime NOT NULL,
  PRIMARY KEY (`connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_journal_entries
CREATE TABLE IF NOT EXISTS `marketplace_journal_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `journal_no` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `ref_type` enum('ORDER_COMPLETED','ESCROW_PAYOUT','AD_EXPENSE','RETURN_LOSS','MANUAL') NOT NULL,
  `ref_id` varchar(100) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal` (`journal_no`),
  KEY `idx_ref` (`ref_id`),
  KEY `idx_store_date` (`store_id`,`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- table: marketplace_oauth_connections
CREATE TABLE IF NOT EXISTS `marketplace_oauth_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(191) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `external_store_id` varchar(191) NOT NULL,
  `external_shop_cipher` varchar(191) DEFAULT NULL,
  `external_store_name` varchar(191) NOT NULL,
  `access_token_encrypted` mediumtext NOT NULL,
  `refresh_token_encrypted` mediumtext DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `refresh_token_expires_at` datetime DEFAULT NULL,
  `granted_scopes` text DEFAULT NULL,
  `authorized_at` datetime NOT NULL,
  `verified_at` datetime NOT NULL,
  `last_successful_sync_at` datetime DEFAULT NULL,
  `last_sync_attempt_at` datetime DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'verified',
  `last_error_code` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_oauth_connection_store` (`store_id`),
  UNIQUE KEY `uq_marketplace_oauth_connection_external` (`platform`,`external_store_id`),
  KEY `idx_marketplace_oauth_connection_company` (`company_key`,`platform`,`status`),
  CONSTRAINT `fk_marketplace_oauth_connection_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_oauth_pending
CREATE TABLE IF NOT EXISTS `marketplace_oauth_pending` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pending_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `user_key` varchar(191) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `access_token_encrypted` mediumtext NOT NULL,
  `refresh_token_encrypted` mediumtext DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `refresh_token_expires_at` datetime DEFAULT NULL,
  `granted_scopes` text DEFAULT NULL,
  `shops_json` mediumtext NOT NULL,
  `return_path` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_oauth_pending_hash` (`pending_hash`),
  KEY `idx_marketplace_oauth_pending_lookup` (`company_key`,`user_key`,`platform`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_oauth_states
CREATE TABLE IF NOT EXISTS `marketplace_oauth_states` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `state_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `user_key` varchar(191) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `return_path` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_oauth_state_hash` (`state_hash`),
  KEY `idx_marketplace_oauth_state_lookup` (`company_key`,`user_key`,`platform`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_order_items
CREATE TABLE IF NOT EXISTS `marketplace_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` varchar(100) NOT NULL,
  `product_variant_id` varchar(60) DEFAULT NULL,
  `sku` varchar(100) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(15,2) NOT NULL,
  `unit_cogs_hpp` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal_price` decimal(15,2) NOT NULL,
  `subtotal_cogs` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_mitem_order` (`order_id`),
  CONSTRAINT `fk_mitem_order` FOREIGN KEY (`order_id`) REFERENCES `marketplace_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_orders
CREATE TABLE IF NOT EXISTS `marketplace_orders` (
  `id` varchar(100) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `order_date` datetime NOT NULL,
  `completion_date` datetime DEFAULT NULL,
  `buyer_username` varchar(100) DEFAULT NULL,
  `order_status` enum('Pending','Terkirim','Selesai','Dibatalkan','Retur') NOT NULL DEFAULT 'Selesai',
  `gross_sales` decimal(15,2) NOT NULL DEFAULT 0.00,
  `seller_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `platform_admin_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `service_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `shipping_adjustment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
  `affiliate_commission` decimal(15,2) NOT NULL DEFAULT 0.00,
  `live_commission` decimal(15,2) NOT NULL DEFAULT 0.00,
  `declared_weight_gram` int(11) NOT NULL DEFAULT 500,
  `actual_weight_gram` int(11) NOT NULL DEFAULT 500,
  `shipping_discrepancy_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_initiated_at` datetime DEFAULT NULL,
  `return_received_at` datetime DEFAULT NULL,
  `return_claim_status` enum('NONE','MENUNGGU_FISIK','KLAIM_DIAJUKAN','DISETUJUI_GANTI_RUGI','DITOLAK','FISIK_DITERIMA') NOT NULL DEFAULT 'NONE',
  `net_settlement_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cogs_hpp` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_margin_profit` decimal(15,2) GENERATED ALWAYS AS (`net_settlement_amount` - `total_cogs_hpp`) STORED,
  `escrow_status` enum('Tertahan','Sudah Dicairkan') NOT NULL DEFAULT 'Tertahan',
  `payout_batch_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `internal_state` enum('active','archived') NOT NULL DEFAULT 'active',
  `archived_at` datetime DEFAULT NULL,
  `archived_by` varchar(120) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  `source_kind` enum('api','import','manual') NOT NULL DEFAULT 'api',
  `transaction_detail_json` longtext DEFAULT NULL,
  `source_order_status` varchar(64) GENERATED ALWAYS AS (coalesce(convert(nullif(nullif(json_unquote(json_extract(if(json_valid(`transaction_detail_json`),`transaction_detail_json`,'{}'),'$.source_status')),'null'),'') using utf8mb4),`order_status`)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_mord_date` (`order_date`),
  KEY `idx_mord_store` (`store_id`,`order_status`),
  KEY `idx_store_order_operational` (`store_id`,`source_kind`,`order_date`,`source_order_status`),
  CONSTRAINT `fk_mord_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_partner_settings
CREATE TABLE IF NOT EXISTS `marketplace_partner_settings` (
  `platform` varchar(40) NOT NULL,
  `settings_encrypted` mediumtext NOT NULL,
  `last_test_status` varchar(20) DEFAULT NULL,
  `last_tested_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_payout_reconciliations
CREATE TABLE IF NOT EXISTS `marketplace_payout_reconciliations` (
  `id` varchar(100) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `payout_date` date NOT NULL,
  `total_payout_amount` decimal(15,2) NOT NULL,
  `bank_cash_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `matched_orders_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('Matched','Discrepancy','Pending') NOT NULL DEFAULT 'Matched',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payout_store` (`store_id`),
  KEY `fk_payout_acc` (`account_id`),
  CONSTRAINT `fk_payout_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk_payout_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_platform_cooldowns
CREATE TABLE IF NOT EXISTS `marketplace_platform_cooldowns` (
  `scope_hash` char(64) NOT NULL,
  `next_allowed_at` datetime NOT NULL,
  PRIMARY KEY (`scope_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_price_actions
CREATE TABLE IF NOT EXISTS `marketplace_price_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `product_id` varchar(100) NOT NULL,
  `operation_key` varchar(64) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `request_hash` varchar(64) NOT NULL,
  `payload_json` longtext NOT NULL,
  `status` varchar(30) NOT NULL,
  `remote_activity_id` varchar(100) DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_code` varchar(100) DEFAULT NULL,
  `changed_by` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `operation_identity` (`store_id`,`operation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_price_simulations
CREATE TABLE IF NOT EXISTS `marketplace_price_simulations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `cogs_hpp` decimal(15,2) NOT NULL,
  `target_net_margin_pct` decimal(5,2) NOT NULL,
  `admin_fee_pct` decimal(5,2) NOT NULL DEFAULT 11.00,
  `free_shipping_xtra_pct` decimal(5,2) NOT NULL DEFAULT 4.00,
  `payment_fee_pct` decimal(5,2) NOT NULL DEFAULT 1.00,
  `ad_budget_pct` decimal(5,2) NOT NULL DEFAULT 10.00,
  `simulated_normal_price` decimal(15,2) NOT NULL,
  `simulated_flash_sale_price` decimal(15,2) NOT NULL,
  `break_even_roas` decimal(6,2) NOT NULL,
  `net_profit_per_pcs` decimal(15,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- table: marketplace_product_ads
CREATE TABLE IF NOT EXISTS `marketplace_product_ads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `platform` enum('Shopee','TikTok Shop') NOT NULL DEFAULT 'Shopee',
  `ad_type` enum('Produk','Grup_Toko','Discovery','Live_Affiliate') NOT NULL DEFAULT 'Produk',
  `campaign_name` varchar(255) DEFAULT NULL,
  `product_id` varchar(60) DEFAULT NULL,
  `product_variant_id` varchar(60) DEFAULT NULL,
  `marketplace_item_id` varchar(100) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `ad_spend` decimal(15,2) NOT NULL DEFAULT 0.00,
  `gross_revenue` decimal(15,2) NOT NULL DEFAULT 0.00,
  `orders_count` int(11) NOT NULL DEFAULT 0,
  `qty_sold` int(11) NOT NULL DEFAULT 0,
  `impressions` int(11) NOT NULL DEFAULT 0,
  `clicks` int(11) NOT NULL DEFAULT 0,
  `conversions` int(11) NOT NULL DEFAULT 0,
  `unit_cogs_hpp` decimal(15,2) NOT NULL DEFAULT 0.00,
  `platform_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `affiliate_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ppn_rate` decimal(5,2) NOT NULL DEFAULT 11.00,
  `is_bocor` tinyint(1) NOT NULL DEFAULT 0,
  `bocor_reason` varchar(255) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `last_synced_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_store_sku` (`store_id`,`sku`),
  KEY `idx_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_return_claims
CREATE TABLE IF NOT EXISTS `marketplace_return_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `order_id` varchar(100) NOT NULL,
  `buyer_username` varchar(100) DEFAULT NULL,
  `product_summary` varchar(255) DEFAULT NULL,
  `courier_name` varchar(50) NOT NULL DEFAULT 'J&T Express',
  `tracking_number` varchar(100) DEFAULT NULL,
  `return_initiated_at` datetime NOT NULL,
  `days_pending` int(11) NOT NULL DEFAULT 0,
  `claim_status` enum('MENUNGGU_FISIK','KLAIM_DIAJUKAN','DISETUJUI_GANTI_RUGI','DITOLAK','FISIK_DITERIMA') NOT NULL DEFAULT 'MENUNGGU_FISIK',
  `claim_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `reimbursement_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `evidence_notes` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_store_status` (`store_id`,`claim_status`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- table: marketplace_shipping_document_audit
CREATE TABLE IF NOT EXISTS `marketplace_shipping_document_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `order_id` varchar(120) NOT NULL,
  `package_id` varchar(120) NOT NULL,
  `document_type` varchar(48) NOT NULL DEFAULT 'SHIPPING_LABEL',
  `user_key` varchar(120) NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `shipping_audit_store` (`company_key`,`store_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_shipping_documents
CREATE TABLE IF NOT EXISTS `marketplace_shipping_documents` (
  `id` char(48) NOT NULL,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `order_id` varchar(120) NOT NULL,
  `package_id` varchar(120) NOT NULL,
  `document_type` varchar(48) NOT NULL DEFAULT 'SHIPPING_LABEL',
  `user_key` varchar(120) NOT NULL,
  `pdf_encrypted` mediumblob NOT NULL,
  `pdf_hash` char(64) NOT NULL,
  `byte_size` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `shipping_cache` (`company_key`,`store_id`,`order_id`,`package_id`,`user_key`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sku_mappings
CREATE TABLE IF NOT EXISTS `marketplace_sku_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `marketplace_sku` varchar(100) NOT NULL,
  `marketplace_product_name` varchar(255) NOT NULL,
  `product_variant_id` varchar(60) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_sku` (`store_id`,`marketplace_sku`),
  KEY `fk_map_variant` (`product_variant_id`),
  CONSTRAINT `fk_map_store` FOREIGN KEY (`store_id`) REFERENCES `marketplace_stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_map_variant` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_stock_allocations
CREATE TABLE IF NOT EXISTS `marketplace_stock_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variant_id` varchar(60) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `total_physical_stock` int(11) NOT NULL DEFAULT 0,
  `shopee_quota_pct` decimal(5,2) NOT NULL DEFAULT 60.00,
  `tiktok_quota_pct` decimal(5,2) NOT NULL DEFAULT 30.00,
  `offline_quota_pct` decimal(5,2) NOT NULL DEFAULT 10.00,
  `shopee_allocated_qty` int(11) NOT NULL DEFAULT 0,
  `tiktok_allocated_qty` int(11) NOT NULL DEFAULT 0,
  `offline_allocated_qty` int(11) NOT NULL DEFAULT 0,
  `safety_buffer_threshold` int(11) NOT NULL DEFAULT 3,
  `is_safety_buffered` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('ACTIVE','BUFFERED_ZERO','OUT_OF_STOCK') NOT NULL DEFAULT 'ACTIVE',
  `last_synced_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku` (`sku`),
  KEY `idx_variant` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- table: marketplace_stock_minimum_audit
CREATE TABLE IF NOT EXISTS `marketplace_stock_minimum_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `product_id` varchar(100) NOT NULL,
  `variant_id` varchar(100) NOT NULL,
  `warehouse_id` varchar(100) NOT NULL,
  `previous_qty` decimal(16,3) DEFAULT NULL,
  `new_qty` decimal(16,3) NOT NULL,
  `actor` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_stock_minimums
CREATE TABLE IF NOT EXISTS `marketplace_stock_minimums` (
  `store_id` varchar(120) NOT NULL,
  `product_id` varchar(100) NOT NULL,
  `variant_id` varchar(100) NOT NULL,
  `warehouse_id` varchar(100) NOT NULL DEFAULT '',
  `minimum_qty` decimal(16,3) NOT NULL,
  `changed_by` varchar(120) NOT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`store_id`,`product_id`,`variant_id`,`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_stock_snapshots
CREATE TABLE IF NOT EXISTS `marketplace_stock_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(120) NOT NULL,
  `product_id` varchar(100) NOT NULL,
  `variant_id` varchar(100) NOT NULL,
  `warehouse_id` varchar(100) NOT NULL DEFAULT '',
  `quantity` decimal(16,3) DEFAULT NULL,
  `observed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_snapshot_lookup` (`store_id`,`product_id`,`variant_id`,`warehouse_id`,`observed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_store_deletions
CREATE TABLE IF NOT EXISTS `marketplace_store_deletions` (
  `store_id` varchar(191) NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `identity_hash` char(64) NOT NULL,
  `actor` varchar(191) NOT NULL,
  `requested_at` varchar(32) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `counts_json` longtext NOT NULL,
  `files_json` longtext NOT NULL,
  `error_code` varchar(60) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`store_id`),
  KEY `identity_lookup` (`company_key`,`identity_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_store_files
CREATE TABLE IF NOT EXISTS `marketplace_store_files` (
  `store_id` varchar(191) NOT NULL,
  `relative_path` varchar(240) NOT NULL,
  PRIMARY KEY (`store_id`,`relative_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_stores
CREATE TABLE IF NOT EXISTS `marketplace_stores` (
  `id` varchar(50) NOT NULL,
  `company_key` varchar(120) NOT NULL DEFAULT 'LOCAL-COMPANY',
  `platform` varchar(50) NOT NULL DEFAULT 'Shopee',
  `store_name` varchar(100) NOT NULL,
  `store_identifier` varchar(100) DEFAULT NULL,
  `api_app_key` varchar(255) DEFAULT NULL,
  `api_access_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `default_account_id` bigint(20) unsigned DEFAULT NULL,
  `default_admin_fee_pct` decimal(5,2) NOT NULL DEFAULT 11.00,
  `default_free_shipping_pct` decimal(5,2) NOT NULL DEFAULT 4.00,
  `default_payment_fee_pct` decimal(5,2) NOT NULL DEFAULT 1.00,
  `sync_mode` varchar(50) NOT NULL DEFAULT 'API',
  `total_transactions` int(11) NOT NULL DEFAULT 0,
  `last_synced_at` datetime DEFAULT NULL,
  `last_successful_sync_at` datetime DEFAULT NULL,
  `last_error_code` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Terhubung',
  `authorization_status` enum('pending','verified','reauthorize','disconnected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  `archived_by` varchar(120) DEFAULT NULL,
  `sync_history_start_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_store_account` (`default_account_id`),
  KEY `idx_marketplace_store_company` (`company_key`,`platform`,`status`),
  CONSTRAINT `fk_store_account` FOREIGN KEY (`default_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sync_checkpoints
CREATE TABLE IF NOT EXISTS `marketplace_sync_checkpoints` (
  `scope_key` char(64) NOT NULL,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `platform` varchar(30) NOT NULL,
  `environment` varchar(20) NOT NULL,
  `dataset` varchar(40) NOT NULL,
  `history_start` bigint(20) NOT NULL,
  `completed_until` bigint(20) DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_job_id` bigint(20) DEFAULT NULL,
  `connector_version` varchar(30) NOT NULL DEFAULT 'incremental-v1',
  PRIMARY KEY (`scope_key`),
  UNIQUE KEY `store_dataset` (`store_id`,`environment`,`dataset`),
  KEY `company_key` (`company_key`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sync_jobs
CREATE TABLE IF NOT EXISTS `marketplace_sync_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection_id` bigint(20) unsigned NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `store_id` varchar(50) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `job_type` varchar(30) NOT NULL,
  `run_key` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `checkpoint` text DEFAULT NULL,
  `success_count` int(10) unsigned NOT NULL DEFAULT 0,
  `failure_count` int(10) unsigned NOT NULL DEFAULT 0,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `last_error_code` varchar(100) DEFAULT NULL,
  `lease_token` varchar(64) DEFAULT NULL,
  `lease_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_marketplace_sync_job_run` (`connection_id`,`job_type`,`run_key`),
  KEY `idx_marketplace_sync_job_queue` (`status`,`requested_at`),
  KEY `idx_marketplace_sync_job_store` (`company_key`,`store_id`,`status`),
  CONSTRAINT `fk_marketplace_sync_job_connection` FOREIGN KEY (`connection_id`) REFERENCES `marketplace_oauth_connections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sync_object_checks
CREATE TABLE IF NOT EXISTS `marketplace_sync_object_checks` (
  `store_id` varchar(50) NOT NULL,
  `resource_type` varchar(30) NOT NULL,
  `external_id` varchar(150) NOT NULL,
  `checked_at` datetime NOT NULL,
  `next_check_at` datetime NOT NULL,
  PRIMARY KEY (`store_id`,`resource_type`,`external_id`),
  KEY `next_check_at` (`next_check_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sync_records
CREATE TABLE IF NOT EXISTS `marketplace_sync_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(50) NOT NULL,
  `resource_type` varchar(30) NOT NULL,
  `external_id` varchar(150) NOT NULL,
  `payload_json` longtext NOT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `fetched_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_identity` (`store_id`,`resource_type`,`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_sync_workers
CREATE TABLE IF NOT EXISTS `marketplace_sync_workers` (
  `store_id` varchar(50) NOT NULL,
  `company_key` varchar(191) NOT NULL,
  `launch_key` varchar(64) NOT NULL,
  `status` varchar(20) NOT NULL,
  `pid` int(11) DEFAULT NULL,
  `heartbeat_at` datetime NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `error_code` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`store_id`,`company_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_withdrawal_audit
CREATE TABLE IF NOT EXISTS `marketplace_withdrawal_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `external_id` varchar(120) NOT NULL,
  `actor` varchar(120) NOT NULL,
  `previous_hash` char(64) DEFAULT NULL,
  `new_hash` char(64) NOT NULL,
  `source_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: marketplace_withdrawals
CREATE TABLE IF NOT EXISTS `marketplace_withdrawals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_key` varchar(120) NOT NULL,
  `store_id` varchar(120) NOT NULL,
  `external_id` varchar(120) NOT NULL,
  `withdrawal_type` varchar(30) DEFAULT NULL,
  `requested_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `fee` decimal(18,2) DEFAULT NULL,
  `net_amount` decimal(18,2) DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `source_status` varchar(100) NOT NULL,
  `normalized_status` varchar(20) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_mask` varchar(30) DEFAULT NULL,
  `failure_reason` varchar(500) DEFAULT NULL,
  `source_kind` varchar(20) NOT NULL DEFAULT 'import',
  `source_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `imported_by` varchar(120) NOT NULL,
  `imported_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `source_sheet` varchar(100) DEFAULT NULL,
  `source_timezone` varchar(30) DEFAULT NULL,
  `source_date_precision` varchar(12) DEFAULT NULL,
  `completed_date_precision` varchar(12) DEFAULT NULL,
  `source_signed_amount` decimal(18,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `withdrawal_identity` (`company_key`,`store_id`,`external_id`),
  KEY `withdrawal_period` (`company_key`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: migration_log
CREATE TABLE IF NOT EXISTS `migration_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `migration_id` varchar(120) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `source_name` varchar(190) DEFAULT NULL,
  `target_name` varchar(190) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'RUNNING',
  `details_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_id` (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- table: system_users
CREATE TABLE IF NOT EXISTS `system_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_uuid` varchar(80) DEFAULT NULL,
  `username` varchar(120) NOT NULL,
  `name` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` varchar(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN',
  `status` varchar(32) NOT NULL DEFAULT 'Aktif',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
