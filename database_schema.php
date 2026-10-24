<?php
// --- DATABASE SCHEMA V2 (Robust Method) ---
// This file is for reference and documentation.
// Execute the queries in two steps as described below.

/*

-- =============================================
-- STEP 1: Create all tables without foreign keys.
-- This avoids errors related to creation order.
-- Copy and run all the `CREATE TABLE` queries below.
-- =============================================

-- `warehouses` table
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL COMMENT 'اسم المخزن أو الموقع',
  `location` VARCHAR(255) DEFAULT NULL COMMENT 'موقع المخزن (مدينة، منطقة)',
  `manager_id` INT DEFAULT NULL COMMENT 'معرف الموظف المسؤول',
  `is_active` BOOLEAN DEFAULT TRUE COMMENT 'هل المخزن نشط؟',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `inventory_items` table
CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_code` VARCHAR(100) NOT NULL UNIQUE COMMENT 'كود المنتج أو الخامة (SKU)',
  `name` VARCHAR(255) NOT NULL COMMENT 'اسم المنتج أو الخامة',
  `description` TEXT DEFAULT NULL COMMENT 'وصف تفصيلي للمنتج',
  `category` VARCHAR(100) DEFAULT 'Uncategorized' COMMENT 'فئة المنتج (خامات، منتج نهائي، ...)',
  `unit` VARCHAR(50) NOT NULL COMMENT 'وحدة القياس (قطعة، كيلو، متر)',
  `low_stock_threshold` DECIMAL(10, 2) DEFAULT 10.00 COMMENT 'حد التنبيه للمخزون المنخفض',
  `supplier_id` INT DEFAULT NULL COMMENT 'معرف المورد الافتراضي (سيتم ربطه لاحقاً)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `inventory_stock` table (without foreign keys)
CREATE TABLE IF NOT EXISTS `inventory_stock` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT NOT NULL,
  `warehouse_id` INT NOT NULL,
  `quantity` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية الحالية في المخزن',
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `item_warehouse_unique` (`item_id`, `warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `inventory_transactions` table (without foreign keys)
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT NOT NULL,
  `warehouse_id` INT NOT NULL,
  `user_id` INT NOT NULL COMMENT 'الموظف الذي قام بالحركة',
  `transaction_type` ENUM('in', 'out', 'transfer', 'adjustment') NOT NULL COMMENT 'نوع الحركة',
  `quantity` DECIMAL(10, 2) NOT NULL COMMENT 'الكمية التي تم حركتها',
  `related_order_id` INT DEFAULT NULL COMMENT 'رقم أمر الشغل أو الشراء المرتبط بالحركة',
  `notes` TEXT DEFAULT NULL,
  `transaction_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `inventory_audit_sessions` table
CREATE TABLE IF NOT EXISTS `inventory_audit_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `warehouse_id` INT NOT NULL,
  `audit_date` DATE NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT '',
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','applied') NOT NULL DEFAULT 'draft',
  `created_by_user_id` INT DEFAULT NULL,
  `applied_by_user_id` INT DEFAULT NULL,
  `applied_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `inventory_audit_lines` table
CREATE TABLE IF NOT EXISTS `inventory_audit_lines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `system_qty` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `counted_qty` DECIMAL(12, 2) DEFAULT NULL,
  `variance_qty` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `notes` VARCHAR(255) DEFAULT NULL,
  `counted_by_user_id` INT DEFAULT NULL,
  `counted_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_inventory_audit_session_item` (`session_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =============================================
-- STEP 2: Add all foreign keys using ALTER TABLE.
-- Now that all tables exist, these queries will run without issues.
-- Copy and run all the `ALTER TABLE` queries below.
-- =============================================

-- Add keys to `inventory_stock`
ALTER TABLE `inventory_stock`
  ADD CONSTRAINT `fk_stock_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE;

-- Add keys to `inventory_transactions`
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `fk_trans_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_trans_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_trans_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT;

-- (Optional) Add key for supplier in `inventory_items`
-- This might fail if the `suppliers` table has a different character set (e.g. latin1).
-- If it fails, you can ignore it for now.
-- ALTER TABLE `inventory_items`
--   ADD CONSTRAINT `fk_item_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL;

*/

echo "Database schema V2 has been created in database_schema.php. Please apply it in two steps as described in the file comments.";

/*
Additional taxation fields added by the application upgrader:

-- invoices
ALTER TABLE `invoices`
  ADD COLUMN `invoice_kind` VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER `due_date`,
  ADD COLUMN `tax_law_key` VARCHAR(60) DEFAULT NULL AFTER `invoice_kind`,
  ADD COLUMN `source_quote_id` INT DEFAULT NULL AFTER `job_id`,
  ADD COLUMN `tax_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `tax`,
  ADD COLUMN `taxes_json` LONGTEXT DEFAULT NULL AFTER `items_json`;

-- quotes
ALTER TABLE `quotes`
  ADD COLUMN `quote_kind` VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER `valid_until`,
  ADD COLUMN `tax_law_key` VARCHAR(60) DEFAULT NULL AFTER `quote_kind`,
  ADD COLUMN `tax_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN `taxes_json` LONGTEXT DEFAULT NULL AFTER `items_json`,
  ADD COLUMN `converted_invoice_id` INT DEFAULT NULL AFTER `taxes_json`,
  ADD COLUMN `converted_at` DATETIME DEFAULT NULL AFTER `converted_invoice_id`;
*/

?>
