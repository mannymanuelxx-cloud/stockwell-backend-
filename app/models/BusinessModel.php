<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/OrderReturnPolicy.php';
require_once __DIR__ . '/../libraries/DeliveryPricingPolicy.php';
require_once __DIR__ . '/../libraries/PaymentPolicy.php';

class BusinessModel extends Model
{
    // Increment when the business schema changes so migrations run once again.
    private const SCHEMA_VERSION = 4;

    private function ensureSalesSchema()
    {
        if ($this->db->raw("SHOW TABLES LIKE 'sales'")->fetch() && !$this->db->raw("SHOW COLUMNS FROM sales LIKE 'status'")->fetch()) {
            $this->db->raw("ALTER TABLE sales ADD COLUMN status ENUM('completed','refunded') NOT NULL DEFAULT 'completed' AFTER customer_id");
        }
        if ($this->db->raw("SHOW TABLES LIKE 'sales'")->fetch()) {
            if (!$this->db->raw("SHOW COLUMNS FROM sales LIKE 'payment_status'")->fetch()) {
                $this->db->raw("ALTER TABLE sales ADD COLUMN payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'paid' AFTER status");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM sales LIKE 'payment_method'")->fetch()) {
                $this->db->raw("ALTER TABLE sales ADD COLUMN payment_method ENUM('cash','card','gcash','bank_transfer','other') NOT NULL DEFAULT 'cash' AFTER payment_status");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM sales LIKE 'amount_paid'")->fetch()) {
                $this->db->raw("ALTER TABLE sales ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method");
            }
            $this->db->raw("UPDATE sales SET amount_paid = total WHERE payment_status = 'paid' AND amount_paid = 0");
            $this->db->raw("UPDATE sales SET payment_status = 'refunded', amount_paid = 0
                WHERE status = 'refunded' AND (payment_status <> 'refunded' OR amount_paid <> 0)");
        }
    }

    public function ensureSchema()
    {
        if ($this->businessSchemaIsCurrent()) return;

        $lock = $this->db->raw("SELECT GET_LOCK('manuel_friends_business_schema', 10) AS acquired")->fetch();
        if (empty($lock['acquired'])) {
            throw new RuntimeException('Could not acquire the database schema initialization lock.');
        }

        try {
            if ($this->businessSchemaIsCurrent()) return;

            if ($this->db->raw("SHOW TABLES LIKE 'business_schema_version'")->fetch()) {
                $this->ensureSalesSchema();
                $this->ensureShopSchema();
                $this->ensureActivitySchema();
                $this->db->raw('INSERT IGNORE INTO business_schema_version (version) VALUES (' . self::SCHEMA_VERSION . ')');
                return;
            }

        $this->db->raw("CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(40) NULL,
            address VARCHAR(500) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS suppliers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(40) NULL,
            address VARCHAR(500) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_suppliers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS sales (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NULL,
            status ENUM('completed','refunded') NOT NULL DEFAULT 'completed',
            payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'paid',
            payment_method ENUM('cash','card','gcash','bank_transfer','other') NOT NULL DEFAULT 'cash',
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sales_created_at (created_at),
            CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS sale_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(12,2) NOT NULL,
            CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            INDEX idx_sale_items_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureSalesSchema();
        $this->db->raw("CREATE TABLE IF NOT EXISTS customer_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
            stock_reserved TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            delivery_address VARCHAR(500) NULL,
            total DECIMAL(12,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_orders_status_created (status, created_at),
            CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS customer_order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(12,2) NOT NULL,
            CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
            INDEX idx_order_items_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS purchases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT UNSIGNED NOT NULL,
            status ENUM('pending','received','cancelled') NOT NULL DEFAULT 'pending',
            total DECIMAL(12,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_purchases_status_created (status, created_at),
            CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS purchase_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(12,2) NOT NULL,
            CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
            INDEX idx_purchase_items_purchase (purchase_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->raw("CREATE TABLE business_schema_version (
                version INT UNSIGNED NOT NULL PRIMARY KEY,
                installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->raw('INSERT INTO business_schema_version (version) VALUES (1)');
            $this->ensureShopSchema();
            $this->ensureActivitySchema();
            $this->db->raw('INSERT IGNORE INTO business_schema_version (version) VALUES (' . self::SCHEMA_VERSION . ')');
        } finally {
            $this->db->raw("SELECT RELEASE_LOCK('manuel_friends_business_schema')");
        }
    }

    private function businessSchemaIsCurrent()
    {
        if (!$this->db->raw("SHOW TABLES LIKE 'business_schema_version'")->fetch()) return false;
        $version = $this->db->raw('SELECT MAX(version) AS version FROM business_schema_version')->fetch();
        return (int) ($version['version'] ?? 0) >= self::SCHEMA_VERSION;
    }

    private function ensureShopSchema()
    {
        $addedOrderPaymentStatus = false;
        if ($this->db->raw("SHOW TABLES LIKE 'customers'")->fetch()
            && !$this->db->raw("SHOW COLUMNS FROM customers LIKE 'user_id'")->fetch()) {
            $this->db->raw('ALTER TABLE customers ADD COLUMN user_id INT UNSIGNED NULL UNIQUE AFTER id');
        }
        if ($this->db->raw("SHOW TABLES LIKE 'customer_orders'")->fetch()) {
            $statusColumn = $this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'status'")->fetch();
            $statusType = strtolower($statusColumn['Type'] ?? $statusColumn['type'] ?? '');
            if ($statusType !== "enum('pending','processing','ready_to_ship','shipped','delivered','completed','cancelled')") {
                $this->db->raw("ALTER TABLE customer_orders MODIFY status ENUM('pending','processing','ready_to_ship','shipped','delivered','completed','cancelled') NOT NULL DEFAULT 'pending'");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'stock_reserved'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN stock_reserved TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_address'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_address VARCHAR(500) NULL');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_phone'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_phone VARCHAR(40) NULL AFTER delivery_address');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_name'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_name VARCHAR(160) NULL AFTER delivery_phone');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_notes'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_notes TEXT NULL AFTER delivery_name');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'courier'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN courier VARCHAR(120) NULL');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'tracking_number'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN tracking_number VARCHAR(160) NULL');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'tracking_url'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN tracking_url VARCHAR(1000) NULL AFTER tracking_number');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'estimated_delivery_at'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN estimated_delivery_at DATE NULL');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivered_at'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivered_at DATETIME NULL');
                $this->db->raw("UPDATE customer_orders SET delivered_at = updated_at WHERE status IN ('delivered', 'completed')");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'completed_at'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN completed_at DATETIME NULL');
            }
            $this->db->raw("UPDATE customer_orders SET completed_at = COALESCE(delivered_at, updated_at, created_at)
                WHERE status = 'completed' AND completed_at IS NULL");
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'subtotal'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0');
                $this->db->raw('UPDATE customer_orders SET subtotal = total');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_zone'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_zone VARCHAR(120) NULL');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_fee'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0');
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'delivery_discount'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN delivery_discount DECIMAL(12,2) NOT NULL DEFAULT 0');
            }
            $paymentStatusColumn = $this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'payment_status'")->fetch();
            if (!$paymentStatusColumn) {
                $this->db->raw("ALTER TABLE customer_orders ADD COLUMN payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid' AFTER stock_reserved");
                $addedOrderPaymentStatus = true;
            } else {
                $paymentStatusType = strtolower($paymentStatusColumn['Type'] ?? $paymentStatusColumn['type'] ?? '');
                if ($paymentStatusType !== "enum('unpaid','partial','paid','refunded')") {
                    $this->db->raw("ALTER TABLE customer_orders MODIFY payment_status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid'");
                }
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'payment_method'")->fetch()) {
                $this->db->raw("ALTER TABLE customer_orders ADD COLUMN payment_method ENUM('cash','card','gcash','bank_transfer','other') NOT NULL DEFAULT 'cash' AFTER payment_status");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'amount_paid'")->fetch()) {
                $this->db->raw("ALTER TABLE customer_orders ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method");
            }
            if (!$this->db->raw("SHOW COLUMNS FROM customer_orders LIKE 'gcash_payment_reference'")->fetch()) {
                $this->db->raw('ALTER TABLE customer_orders ADD COLUMN gcash_payment_reference VARCHAR(100) NULL');
            }
            if ($addedOrderPaymentStatus) {
                $this->db->raw("UPDATE customer_orders SET payment_status = 'paid', amount_paid = total
                    WHERE status IN ('completed','delivered') AND payment_status = 'unpaid' AND amount_paid = 0");
            }
        }
        if ($this->db->raw("SHOW TABLES LIKE 'customers'")->fetch()) {
            $this->db->raw("CREATE TABLE IF NOT EXISTS customer_addresses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                label VARCHAR(80) NOT NULL,
                recipient_name VARCHAR(160) NOT NULL,
                phone VARCHAR(40) NOT NULL,
                address VARCHAR(500) NOT NULL,
                is_default TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer_addresses_customer (customer_id),
                CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->raw("INSERT INTO customer_addresses (customer_id, label, recipient_name, phone, address, is_default)
                SELECT c.id, 'Home', c.name, COALESCE(c.phone, ''), c.address, 1
                FROM customers c
                WHERE c.address IS NOT NULL AND c.address <> ''
                AND NOT EXISTS (SELECT 1 FROM customer_addresses a WHERE a.customer_id = c.id)");
            $this->db->raw("CREATE TABLE IF NOT EXISTS customer_order_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL,
                message VARCHAR(500) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                INDEX idx_customer_notifications (customer_id, id),
                CONSTRAINT fk_customer_order_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_customer_order_notifications_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $this->db->raw("CREATE TABLE IF NOT EXISTS order_return_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            reason VARCHAR(120) NOT NULL,
            details TEXT NULL,
            status ENUM('requested','approved','rejected','received','refunded') NOT NULL DEFAULT 'requested',
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_return_order_status (order_id, status),
            INDEX idx_return_customer (customer_id, created_at),
            CONSTRAINT fk_return_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE RESTRICT,
            CONSTRAINT fk_return_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS order_return_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            return_request_id INT UNSIGNED NOT NULL,
            order_item_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(12,2) NOT NULL,
            UNIQUE KEY uq_return_request_item (return_request_id, order_item_id),
            INDEX idx_return_item_product (product_id),
            CONSTRAINT fk_return_items_request FOREIGN KEY (return_request_id) REFERENCES order_return_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS product_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            review TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_product_review_customer (product_id, customer_id),
            INDEX idx_product_reviews_product (product_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->raw("CREATE TABLE IF NOT EXISTS delivery_zones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            area_name VARCHAR(120) NOT NULL UNIQUE,
            fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            free_delivery_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            free_delivery_minimum DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $zoneCount = $this->db->raw('SELECT COUNT(*) AS count FROM delivery_zones')->fetch();
        if ((int) $zoneCount['count'] === 0) {
            $this->db->raw("INSERT INTO delivery_zones (area_name, fee) VALUES
                ('Oriental Mindoro - Calapan & nearby', 99.00),
                ('Other MIMAROPA', 149.00),
                ('Metro Manila', 199.00),
                ('Other Luzon', 219.00),
                ('Visayas', 249.00),
                ('Mindanao', 299.00)");
        }
    }

    private function ensureActivitySchema()
    {
        $this->db->raw("CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id INT UNSIGNED NULL,
            actor_name VARCHAR(120) NOT NULL DEFAULT 'System',
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(80) NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_created (created_at),
            INDEX idx_activity_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function logActivity($actorId, $actorName, $action, $entityType, $entityId = null, $details = null)
    {
        $this->db->table('activity_logs')->insert([
            'actor_id' => $actorId ?: null,
            'actor_name' => is_string($actorName) && trim($actorName) !== '' ? mb_substr(trim($actorName), 0, 120) : 'System',
            'action' => mb_substr((string) $action, 0, 80),
            'entity_type' => mb_substr((string) $entityType, 0, 80),
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'details' => $details === null ? null : mb_substr((string) $details, 0, 65535)
        ]);
    }

    public function listActivity($limit = 500)
    {
        return $this->db->raw(
            'SELECT id, actor_id, actor_name, action, entity_type, entity_id, details, created_at
             FROM activity_logs ORDER BY id DESC LIMIT ?',
            [(int) $limit]
        )->fetchAll();
    }

    public function adminNotificationData()
    {
        $pendingOrders = $this->db->raw(
            "SELECT o.id, c.name AS customer_name, o.created_at
             FROM customer_orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.status = 'pending'
             ORDER BY o.created_at DESC LIMIT 10"
        )->fetchAll();
        $summary = $this->db->raw(
            "SELECT COUNT(*) AS unpaid_orders,
                    COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0) AS outstanding_balance
             FROM customer_orders
             WHERE status NOT IN ('cancelled', 'completed', 'delivered')
               AND payment_status IN ('unpaid', 'partial')"
        )->fetch();
        $pendingCount = $this->db->raw(
            "SELECT COUNT(*) AS count FROM customer_orders WHERE status = 'pending'"
        )->fetch();
        $paymentUpdates = $this->db->raw(
            "SELECT id, actor_name, entity_id, details, created_at
             FROM activity_logs
             WHERE entity_type = 'order' AND action = 'updated payment'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             ORDER BY id DESC LIMIT 10"
        )->fetchAll();
        return [
            'pending_orders' => $pendingOrders,
            'pending_order_count' => (int) ($pendingCount['count'] ?? 0),
            'unpaid_orders' => (int) ($summary['unpaid_orders'] ?? 0),
            'outstanding_balance' => (float) ($summary['outstanding_balance'] ?? 0),
            'payment_updates' => $paymentUpdates
        ];
    }

    public function sqlBackup()
    {
        $allowedTables = [
            'users', 'products', 'customers', 'customer_addresses', 'suppliers', 'sales', 'sale_items',
            'customer_orders', 'customer_order_items', 'order_return_requests', 'order_return_items', 'purchases', 'purchase_items',
            'delivery_zones', 'business_schema_version', 'activity_logs', 'stock_adjustments'
        ];
        $existingTables = array_map('array_values', $this->db->raw('SHOW TABLES')->fetchAll());
        $existingNames = array_map(function ($row) { return (string) ($row[0] ?? ''); }, $existingTables);
        $tables = array_values(array_intersect($allowedTables, $existingNames));
        $sql = "-- Stockwell business-data backup. Store this file securely.\n"
            . "-- Generated: " . gmdate('Y-m-d\\TH:i:s\\Z') . "\n"
            . "-- Restore into an empty database with: mysql DATABASE_NAME < backup.sql\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n";

        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $schema = $this->db->raw("SHOW CREATE TABLE {$quotedTable}")->fetch();
            if (!$schema) throw new RuntimeException('Could not read the backup schema for ' . $table . '.');
            $sql .= "\n" . array_values($schema)[1] . ";\n";

            $columns = $this->db->raw("SHOW COLUMNS FROM {$quotedTable}")->fetchAll();
            $columnNames = array_map(function ($column) {
                return (string) ($column['Field'] ?? $column->Field ?? '');
            }, $columns);
            if (!$columnNames) continue;

            $quotedColumns = array_map(function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, $columnNames);
            $quotedExpressions = [];
            foreach ($columnNames as $index => $column) {
                $quotedExpressions[] = 'QUOTE(' . $quotedColumns[$index] . ') AS `c' . $index . '`';
            }
            $rows = $this->db->raw("SELECT " . implode(', ', $quotedExpressions) . " FROM {$quotedTable}")->fetchAll();
            foreach ($rows as $row) {
                $values = [];
                foreach ($columnNames as $index => $column) {
                    $value = $row['c' . $index] ?? null;
                    $values[] = $value === null ? 'NULL' : $value;
                }
                $sql .= 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
                    . implode(', ', $values) . ");\n";
            }
        }

        return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
    }

    public function listContacts($table)
    {
        if ($table === 'customers') {
            return $this->db->raw("SELECT c.*, (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id) + (SELECT COUNT(*) FROM customer_orders o WHERE o.customer_id = c.id) AS order_count,
                (SELECT MAX(created_at) FROM customer_orders o WHERE o.customer_id = c.id) AS last_order_at
                FROM customers c ORDER BY c.name ASC")->fetchAll();
        }
        return $this->db->table($table)->order_by('name', 'ASC')->get_all();
    }

    public function contact($table, $id)
    {
        return $this->db->table($table)->where('id', $id)->get();
    }

    public function saveContact($table, $data, $id = null)
    {
        if ($id) {
            $this->db->table($table)->where('id', $id)->update($data);
            return $id;
        }

        $this->db->table($table)->insert($data);
        return (int) $this->db->last_id();
    }

    public function deleteContact($table, $id)
    {
        if ($table === 'customers') {
            $linked = $this->db->raw('SELECT id FROM sales WHERE customer_id = ? UNION SELECT id FROM customer_orders WHERE customer_id = ? LIMIT 1', [$id, $id])->fetch();
            if ($linked) throw new InvalidArgumentException('This customer is linked to existing activity and cannot be deleted.');
        } else {
            $linked = $this->db->raw('SELECT id FROM purchases WHERE supplier_id = ? LIMIT 1', [$id])->fetch();
            if ($linked) throw new InvalidArgumentException('This supplier is linked to existing purchases and cannot be deleted.');
        }
        $this->db->table($table)->where('id', $id)->delete();
    }

    public function listDocuments($table, $itemsTable, $foreignKey)
    {
        $party = $table === 'sales' ? 'customers' : ($table === 'customer_orders' ? 'customers' : 'suppliers');
        $partyKey = $table === 'purchases' ? 'supplier_id' : 'customer_id';
        return $this->db->raw("SELECT d.*, p.name AS party_name,
            (SELECT COUNT(*) FROM {$itemsTable} i WHERE i.{$foreignKey} = d.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity), 0) FROM {$itemsTable} i WHERE i.{$foreignKey} = d.id) AS unit_count
            FROM {$table} d JOIN {$party} p ON p.id = d.{$partyKey} ORDER BY d.id DESC")->fetchAll();
    }

    public function completedOnlineSales()
    {
        return $this->db->raw(
            "SELECT o.id, c.name AS party_name, o.created_at, o.total, o.status,
                o.payment_status, o.payment_method, o.amount_paid,
                (SELECT COUNT(*) FROM customer_order_items i WHERE i.order_id = o.id) AS item_count,
                (SELECT COALESCE(SUM(i.quantity), 0) FROM customer_order_items i WHERE i.order_id = o.id) AS unit_count
             FROM customer_orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.status IN ('delivered', 'completed')
             ORDER BY o.id DESC"
        )->fetchAll();
    }

    public function document($table, $itemsTable, $foreignKey, $id)
    {
        $party = $table === 'sales' ? 'customers' : ($table === 'customer_orders' ? 'customers' : 'suppliers');
        $partyKey = $table === 'purchases' ? 'supplier_id' : 'customer_id';
        $document = $this->db->raw("SELECT d.*, p.name AS party_name FROM {$table} d
            JOIN {$party} p ON p.id = d.{$partyKey} WHERE d.id = ?", [$id])->fetch();
        if (!$document) return null;
        $document['items'] = $this->db->raw("SELECT * FROM {$itemsTable} WHERE {$foreignKey} = ? ORDER BY id", [$id])->fetchAll();
        return $document;
    }

    public function productOptions()
    {
        return $this->db->raw('SELECT id, product_name, price, quantity FROM products ORDER BY product_name')->fetchAll();
    }

    public function customerIdForUser($userId)
    {
        $customer = $this->db->raw('SELECT id FROM customers WHERE user_id = ? LIMIT 1', [$userId])->fetch();
        return $customer ? (int) $customer['id'] : null;
    }

    public function customerOrders($customerId)
    {
        return $this->db->raw(
            "SELECT o.id, o.status, o.total, o.subtotal, o.delivery_zone, o.delivery_fee, o.delivery_discount,
                o.payment_status, o.payment_method, o.amount_paid, o.gcash_payment_reference,
                o.delivery_address, o.estimated_delivery_at, o.created_at,
                (SELECT COUNT(*) FROM customer_order_items i WHERE i.order_id = o.id) AS item_count,
                (SELECT i.product_name FROM customer_order_items i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS preview_product_name,
                (SELECT i.product_id FROM customer_order_items i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS preview_product_id,
                (SELECT p.image_url FROM customer_order_items i
                    LEFT JOIN products p ON p.id = i.product_id
                    WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS preview_image_url,
                (SELECT COALESCE(SUM(GREATEST(0, i.quantity - COALESCE((
                    SELECT SUM(ri.quantity)
                    FROM order_return_items ri
                    JOIN order_return_requests rr ON rr.id = ri.return_request_id
                    WHERE ri.order_item_id = i.id AND rr.status <> 'rejected'
                ), 0))), 0)
                 FROM customer_order_items i WHERE i.order_id = o.id) AS returnable_quantity
             FROM customer_orders o
             WHERE o.customer_id = ?
             ORDER BY o.id DESC",
            [$customerId]
        )->fetchAll();
    }

    public function customerOrderNotifications($customerId)
    {
        return $this->db->raw(
            'SELECT id, order_id, title, message, created_at, read_at
             FROM customer_order_notifications
             WHERE customer_id = ?
             ORDER BY id DESC LIMIT 50',
            [(int) $customerId]
        )->fetchAll();
    }

    public function markCustomerOrderNotificationsRead($customerId)
    {
        $this->db->raw(
            'UPDATE customer_order_notifications SET read_at = NOW()
             WHERE customer_id = ? AND read_at IS NULL',
            [(int) $customerId]
        );
    }

    private function recordCustomerOrderNotification($customerId, $orderId, $status)
    {
        $messages = [
            'pending' => ['Order received', 'Your order has been received and is awaiting processing.'],
            'processing' => ['Order processing', 'Your order is now being prepared.'],
            'ready_to_ship' => ['Order ready to ship', 'Your order is packed and ready to ship.'],
            'shipped' => ['Order shipped', 'Your order has been shipped.'],
            'delivered' => ['Order delivered', 'Your order has been delivered.'],
            'completed' => ['Order completed', 'Your order has been completed.'],
            'cancelled' => ['Order cancelled', 'Your order has been cancelled.']
        ];
        if (!isset($messages[$status])) {
            return;
        }
        $customer = $this->db->raw(
            'SELECT id FROM customers WHERE id = ? AND user_id IS NOT NULL',
            [(int) $customerId]
        )->fetch();
        if (!$customer) {
            return;
        }
        [$title, $message] = $messages[$status];
        $this->db->table('customer_order_notifications')->insert([
            'customer_id' => (int) $customerId,
            'order_id' => (int) $orderId,
            'title' => $title,
            'message' => 'Order #' . (int) $orderId . ': ' . $message
        ]);
    }

    public function orderNotificationInfo($orderId)
    {
        return $this->db->raw(
            'SELECT o.id, o.status, o.payment_status, o.amount_paid, o.total, o.delivery_address, o.delivery_name, o.delivery_phone,
                o.courier, o.tracking_number, o.tracking_url, o.estimated_delivery_at,
                COALESCE(NULLIF(TRIM(c.email), \'\'), u.email) AS email, c.name
             FROM customer_orders o JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE o.id = ? LIMIT 1',
            [(int) $orderId]
        )->fetch();
    }

    public function returnNotificationInfo($returnId)
    {
        return $this->db->raw(
            'SELECT r.id, r.order_id, r.reason, r.status, r.refund_amount, r.updated_at,
                o.status AS order_status, c.name, COALESCE(NULLIF(TRIM(c.email), \'\'), u.email) AS email
             FROM order_return_requests r
             JOIN customer_orders o ON o.id = r.order_id
             JOIN customers c ON c.id = r.customer_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE r.id = ? LIMIT 1',
            [(int) $returnId]
        )->fetch();
    }

    public function customerOrderDetails($customerId, $orderId)
    {
        $order = $this->db->raw(
            'SELECT id, status, total, subtotal, delivery_zone, delivery_fee, delivery_discount,
                payment_status, payment_method, amount_paid, gcash_payment_reference, delivery_address,
                delivery_phone, delivery_name, delivery_notes, courier, tracking_number, tracking_url,
                estimated_delivery_at, delivered_at, created_at, updated_at, stock_reserved
             FROM customer_orders WHERE id = ? AND customer_id = ? LIMIT 1',
            [(int) $orderId, (int) $customerId]
        )->fetch();
        if (!$order) return null;
        $order['items'] = $this->db->raw(
            "SELECT i.id, i.product_id, i.product_name, i.quantity, i.unit_price, i.line_total,
                p.image_url, p.category,
                COALESCE((SELECT SUM(ri.quantity) FROM order_return_items ri
                    JOIN order_return_requests rr ON rr.id = ri.return_request_id
                    WHERE ri.order_item_id = i.id AND rr.status <> 'rejected'), 0) AS returned_quantity
             FROM customer_order_items i
             LEFT JOIN products p ON p.id = i.product_id
             WHERE i.order_id = ? ORDER BY i.id",
            [(int) $orderId]
        )->fetchAll();
        return $order;
    }

    public function productReviewSummary($productId)
    {
        return $this->db->raw(
            'SELECT COUNT(*) AS review_count, COALESCE(AVG(rating), 0) AS average_rating
             FROM product_reviews WHERE product_id = ?',
            [(int) $productId]
        )->fetch();
    }

    public function productReviews($productId, $customerId = null)
    {
        $reviews = $this->db->raw(
            'SELECT r.id, r.rating, r.title, r.review, r.created_at, r.updated_at
             FROM product_reviews r
             WHERE r.product_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 100',
            [(int) $productId]
        )->fetchAll();
        $summary = $this->productReviewSummary($productId);
        $ownReview = null;
        $eligibleOrderId = null;
        if ($customerId) {
            $ownReview = $this->db->raw(
                'SELECT id, rating, title, review, order_id, created_at, updated_at
                 FROM product_reviews WHERE product_id = ? AND customer_id = ? LIMIT 1',
                [(int) $productId, (int) $customerId]
            )->fetch();
            $eligibleOrder = $this->db->raw(
                "SELECT o.id, o.status
                 FROM customer_orders o
                 JOIN customer_order_items i ON i.order_id = o.id
                 WHERE o.customer_id = ? AND i.product_id = ?
                    AND o.status IN (?, ?)
                 ORDER BY o.id DESC LIMIT 1",
                array_merge(
                    [(int) $customerId, (int) $productId],
                    ProductReviewPolicy::eligibleOrderStatuses()
                )
            )->fetch();
            $eligibleOrderId = $eligibleOrder
                && ProductReviewPolicy::isEligibleOrderStatus($eligibleOrder['status'])
                ? (int) $eligibleOrder['id']
                : null;
        }
        return [
            'average_rating' => round((float) ($summary['average_rating'] ?? 0), 1),
            'review_count' => (int) ($summary['review_count'] ?? 0),
            'reviews' => $reviews,
            'can_review' => $eligibleOrderId !== null,
            'own_review' => $ownReview ?: null
        ];
    }

    public function saveProductReview($customerId, $productId, $rating, $title, $review)
    {
        $eligibleOrder = $this->db->raw(
            "SELECT o.id, o.status
             FROM customer_orders o
             JOIN customer_order_items i ON i.order_id = o.id
             WHERE o.customer_id = ? AND i.product_id = ?
                AND o.status IN (?, ?)
             ORDER BY o.id DESC LIMIT 1",
            array_merge(
                [(int) $customerId, (int) $productId],
                ProductReviewPolicy::eligibleOrderStatuses()
            )
        )->fetch();
        if (!$eligibleOrder || !ProductReviewPolicy::isEligibleOrderStatus($eligibleOrder['status'])) {
            throw new InvalidArgumentException('A delivered or completed purchase is required before reviewing this product.');
        }
        $this->db->raw(
            'INSERT INTO product_reviews (product_id, customer_id, order_id, rating, title, review)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE order_id = VALUES(order_id), rating = VALUES(rating),
                title = VALUES(title), review = VALUES(review)',
            [(int) $productId, (int) $customerId, (int) $eligibleOrder['id'], (int) $rating, $title, $review]
        );
        return $this->db->raw(
            'SELECT id, product_id, customer_id, order_id, rating, title, review, created_at, updated_at
             FROM product_reviews WHERE product_id = ? AND customer_id = ? LIMIT 1',
            [(int) $productId, (int) $customerId]
        )->fetch();
    }

    public function deliveryZones($activeOnly = false)
    {
        $where = $activeOnly ? ' WHERE is_active = 1' : '';
        return $this->db->raw(
            'SELECT id, area_name, fee, is_active, free_delivery_enabled, free_delivery_minimum
             FROM delivery_zones' . $where . ' ORDER BY area_name'
        )->fetchAll();
    }

    public function saveDeliveryZone(array $zone, $id = null)
    {
        $name = trim((string) ($zone['area_name'] ?? ''));
        $fee = $zone['fee'] ?? null;
        $minimum = $zone['free_delivery_minimum'] ?? 0;
        if ($name === '' || mb_strlen($name) > 120 || !is_numeric($fee) || !is_numeric($minimum)) {
            throw new InvalidArgumentException('Enter an area name and valid delivery amounts.');
        }
        $fee = round((float) $fee, 2);
        $minimum = round((float) $minimum, 2);
        if ($fee < 0 || $fee > 99999999.99 || $minimum < 0 || $minimum > 9999999999.99) {
            throw new InvalidArgumentException('Delivery fee and free-delivery minimum are outside supported limits.');
        }
        $isActive = filter_var($zone['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $freeDeliveryEnabled = filter_var($zone['free_delivery_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null || $freeDeliveryEnabled === null) {
            throw new InvalidArgumentException('Delivery availability and free-delivery settings must be true or false.');
        }
        $data = [
            'area_name' => $name,
            'fee' => $fee,
            'is_active' => $isActive ? 1 : 0,
            'free_delivery_enabled' => $freeDeliveryEnabled ? 1 : 0,
            'free_delivery_minimum' => $minimum
        ];
        $duplicate = $id === null
            ? $this->db->raw('SELECT id FROM delivery_zones WHERE area_name = ? LIMIT 1', [$name])->fetch()
            : $this->db->raw('SELECT id FROM delivery_zones WHERE area_name = ? AND id <> ? LIMIT 1', [$name, (int) $id])->fetch();
        if ($duplicate) throw new InvalidArgumentException('A delivery area with that name already exists.');
        if ($id === null) {
            $this->db->table('delivery_zones')->insert($data);
            return (int) $this->db->last_id();
        }
        $exists = $this->db->raw('SELECT id FROM delivery_zones WHERE id = ? LIMIT 1', [(int) $id])->fetch();
        if (!$exists) throw new InvalidArgumentException('Delivery area not found.');
        if (!$data['is_active']) {
            $active = $this->db->raw('SELECT COUNT(*) AS count FROM delivery_zones WHERE is_active = 1 AND id <> ?', [(int) $id])->fetch();
            if ((int) $active['count'] === 0) throw new InvalidArgumentException('At least one delivery area must remain active.');
        }
        $this->db->table('delivery_zones')->where('id', (int) $id)->update($data);
        return (int) $id;
    }

    public function deleteDeliveryZone($id)
    {
        $zone = $this->db->raw('SELECT id, is_active FROM delivery_zones WHERE id = ? LIMIT 1', [(int) $id])->fetch();
        if (!$zone) return false;
        if (!empty($zone['is_active'])) {
            $active = $this->db->raw('SELECT COUNT(*) AS count FROM delivery_zones WHERE is_active = 1')->fetch();
            if ((int) $active['count'] <= 1) throw new InvalidArgumentException('At least one delivery area must remain active.');
        }
        $this->db->table('delivery_zones')->where('id', (int) $id)->delete();
        return true;
    }

    public function customerProfile($userId)
    {
        return $this->db->raw(
            'SELECT u.id, u.firstname, u.lastname, u.email, u.username, c.id AS customer_id, c.phone, c.address
             FROM users u JOIN customers c ON c.user_id = u.id WHERE u.id = ? LIMIT 1',
            [(int) $userId]
        )->fetch();
    }

    public function customerAddresses($customerId)
    {
        return $this->db->raw(
            'SELECT id, label, recipient_name, phone, address, is_default FROM customer_addresses
             WHERE customer_id = ? ORDER BY is_default DESC, id',
            [(int) $customerId]
        )->fetchAll();
    }

    public function updateCustomerProfile($userId, array $profile)
    {
        $this->db->transaction();
        try {
            $existing = $this->db->raw(
                'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1',
                [$profile['email'], (int) $userId]
            )->fetch();
            if ($existing) throw new InvalidArgumentException('That email address is already in use.');
            $customer = $this->db->raw('SELECT id FROM customers WHERE user_id = ? LIMIT 1', [(int) $userId])->fetch();
            if (!$customer) throw new InvalidArgumentException('Your customer profile could not be found.');

            $this->db->table('users')->where('id', (int) $userId)->update([
                'firstname' => $profile['firstname'],
                'lastname' => $profile['lastname'],
                'email' => $profile['email']
            ]);
            $this->db->table('customers')->where('id', (int) $customer['id'])->update([
                'name' => trim($profile['firstname'] . ' ' . $profile['lastname']),
                'email' => $profile['email'],
                'phone' => $profile['phone'],
                'address' => $profile['address']
            ]);

            $default = $this->db->raw(
                'SELECT id FROM customer_addresses WHERE customer_id = ? AND is_default = 1 LIMIT 1',
                [(int) $customer['id']]
            )->fetch();
            if ($default) {
                $this->db->table('customer_addresses')->where('id', (int) $default['id'])->update([
                    'recipient_name' => trim($profile['firstname'] . ' ' . $profile['lastname']),
                    'phone' => $profile['phone'],
                    'address' => $profile['address']
                ]);
            } elseif (trim($profile['address']) !== '') {
                $this->db->table('customer_addresses')->insert([
                    'customer_id' => (int) $customer['id'],
                    'label' => 'Home',
                    'recipient_name' => trim($profile['firstname'] . ' ' . $profile['lastname']),
                    'phone' => $profile['phone'],
                    'address' => $profile['address'],
                    'is_default' => 1
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function addCustomerAddress($customerId, array $address)
    {
        $this->db->transaction();
        try {
            $current = $this->db->raw(
                'SELECT id FROM customer_addresses WHERE customer_id = ? LIMIT 1',
                [(int) $customerId]
            )->fetch();
            $isDefault = !empty($address['is_default']) || !$current;
            if ($isDefault) {
                $this->db->raw('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [(int) $customerId]);
            }
            $this->db->table('customer_addresses')->insert([
                'customer_id' => (int) $customerId,
                'label' => $address['label'],
                'recipient_name' => $address['recipient_name'],
                'phone' => $address['phone'],
                'address' => $address['address'],
                'is_default' => $isDefault ? 1 : 0
            ]);
            $id = (int) $this->db->last_id();
            if ($isDefault) {
                $this->db->table('customers')->where('id', (int) $customerId)->update([
                    'name' => $address['recipient_name'],
                    'phone' => $address['phone'],
                    'address' => $address['address']
                ]);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function setDefaultCustomerAddress($customerId, $addressId)
    {
        $address = $this->db->raw(
            'SELECT id, recipient_name, phone, address FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1',
            [(int) $addressId, (int) $customerId]
        )->fetch();
        if (!$address) return false;
        $this->db->transaction();
        try {
            $this->db->raw('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [(int) $customerId]);
            $this->db->table('customer_addresses')->where('id', (int) $addressId)->update(['is_default' => 1]);
            $this->db->table('customers')->where('id', (int) $customerId)->update([
                'name' => $address['recipient_name'],
                'phone' => $address['phone'],
                'address' => $address['address']
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function deleteCustomerAddress($customerId, $addressId)
    {
        $this->db->transaction();
        try {
            $address = $this->db->raw(
                'SELECT id, is_default FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1 FOR UPDATE',
                [(int) $addressId, (int) $customerId]
            )->fetch();
            if (!$address) {
                $this->db->roll_back();
                return false;
            }
            $this->db->table('customer_addresses')->where('id', (int) $addressId)->delete();
            if (!empty($address['is_default'])) {
                $next = $this->db->raw(
                    'SELECT id, recipient_name, phone, address FROM customer_addresses WHERE customer_id = ? ORDER BY id LIMIT 1',
                    [(int) $customerId]
                )->fetch();
                if ($next) {
                    $this->db->table('customer_addresses')->where('id', (int) $next['id'])->update(['is_default' => 1]);
                    $this->db->table('customers')->where('id', (int) $customerId)->update([
                        'name' => $next['recipient_name'], 'phone' => $next['phone'], 'address' => $next['address']
                    ]);
                } else {
                    $this->db->table('customers')->where('id', (int) $customerId)->update(['address' => null]);
                }
            }
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function cancelCustomerOrder($customerId, $orderId)
    {
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT id, status, stock_reserved, payment_status, payment_method, amount_paid,
                    gcash_payment_reference
                 FROM customer_orders WHERE id = ? AND customer_id = ? FOR UPDATE',
                [(int) $orderId, (int) $customerId]
            )->fetch();
            if (!$order) throw new InvalidArgumentException('Order not found.');
            if ($order['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending orders can be cancelled. Contact the store if you need help with an order already being processed.');
            }
            if ($order['payment_status'] !== 'unpaid' || (float) $order['amount_paid'] > 0) {
                throw new InvalidArgumentException('This order has a payment recorded. Contact the store to arrange cancellation and any applicable refund.');
            }
            if ($order['payment_method'] === 'gcash' && !empty($order['gcash_payment_reference'])) {
                throw new InvalidArgumentException('This order has a submitted GCash reference. Contact the store before cancelling so the payment can be checked.');
            }
            if (!empty($order['stock_reserved'])) {
                $items = $this->db->raw(
                    'SELECT product_id, quantity FROM customer_order_items WHERE order_id = ?',
                    [(int) $orderId]
                )->fetchAll();
                foreach ($items as $item) {
                    $this->db->raw('UPDATE products SET quantity = quantity + ? WHERE id = ?', [
                        (int) $item['quantity'], (int) $item['product_id']
                    ]);
                }
            }
            $this->db->table('customer_orders')->where('id', (int) $orderId)->update([
                'status' => 'cancelled',
                'stock_reserved' => 0
            ]);
            $this->recordCustomerOrderNotification((int) $customerId, (int) $orderId, 'cancelled');
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function updateOrderTracking($orderId, array $tracking)
    {
        $order = $this->db->raw(
            'SELECT id, status FROM customer_orders WHERE id = ? LIMIT 1',
            [(int) $orderId]
        )->fetch();
        if (!$order) throw new InvalidArgumentException('Record not found.');
        if (in_array($order['status'], ['cancelled', 'completed'], true)) {
            throw new InvalidArgumentException('Tracking details cannot be changed for a cancelled or completed order.');
        }
        $this->db->table('customer_orders')->where('id', (int) $orderId)->update([
            'courier' => $tracking['courier'] ?: null,
            'tracking_number' => $tracking['tracking_number'] ?: null,
            'tracking_url' => $tracking['tracking_url'] ?: null,
            'estimated_delivery_at' => $tracking['estimated_delivery_at'] ?: null
        ]);
    }

    public function listReturnRequests()
    {
        $returns = $this->db->raw(
            'SELECT r.*, o.status AS order_status, c.name AS customer_name, c.email AS customer_email,
                (SELECT COUNT(*) FROM order_return_items i WHERE i.return_request_id = r.id) AS item_count
             FROM order_return_requests r
             JOIN customer_orders o ON o.id = r.order_id
             JOIN customers c ON c.id = r.customer_id
             ORDER BY r.id DESC'
        )->fetchAll();
        foreach ($returns as &$request) {
            $request['items'] = $this->db->raw(
                'SELECT product_name, quantity, unit_price, line_total FROM order_return_items
                 WHERE return_request_id = ? ORDER BY id',
                [(int) $request['id']]
            )->fetchAll();
        }
        unset($request);
        return $returns;
    }

    public function customerReturns($customerId)
    {
        $returns = $this->db->raw(
            'SELECT id, order_id, reason, details, status, refund_amount, created_at, updated_at
             FROM order_return_requests WHERE customer_id = ? ORDER BY id DESC',
            [(int) $customerId]
        )->fetchAll();
        foreach ($returns as &$request) {
            $request['items'] = $this->db->raw(
                'SELECT product_name, quantity, unit_price, line_total
                 FROM order_return_items WHERE return_request_id = ? ORDER BY id',
                [(int) $request['id']]
            )->fetchAll();
        }
        unset($request);
        return $returns;
    }

    public function createCustomerReturn($customerId, $orderId, $reason, $details, array $items)
    {
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT id, status, payment_status, amount_paid, delivered_at, updated_at
                 FROM customer_orders WHERE id = ? AND customer_id = ? FOR UPDATE',
                [(int) $orderId, (int) $customerId]
            )->fetch();
            if (!$order) throw new InvalidArgumentException('Order not found.');
            if (!OrderReturnPolicy::isEligibleOrderStatus($order['status'])) {
                throw new InvalidArgumentException('Returns can only be requested for delivered orders.');
            }
            $deliveredAt = $order['delivered_at'] ?: $order['updated_at'];
            if (!OrderReturnPolicy::isWithinReturnWindow($deliveredAt)) {
                throw new InvalidArgumentException('Return requests must be submitted within 14 days of delivery.');
            }
            $reason = trim((string) $reason);
            $details = trim((string) $details);
            if ($reason === '' || mb_strlen($reason) > 120 || mb_strlen($details) > 2000) {
                throw new InvalidArgumentException('Choose a reason and keep return details within 2,000 characters.');
            }
            if (!$items) throw new InvalidArgumentException('Choose at least one item to return.');

            $this->db->table('order_return_requests')->insert([
                'order_id' => (int) $orderId,
                'customer_id' => (int) $customerId,
                'reason' => $reason,
                'details' => $details ?: null
            ]);
            $returnId = (int) $this->db->last_id();
            $refundAmount = 0;
            $seen = [];
            foreach ($items as $requested) {
                $itemId = filter_var($requested['order_item_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($requested['quantity'] ?? null, FILTER_VALIDATE_INT);
                if (!$itemId || !$quantity || isset($seen[$itemId])) {
                    throw new InvalidArgumentException('Return items must have unique valid order item IDs and quantities.');
                }
                $seen[$itemId] = true;
                $orderItem = $this->db->raw(
                    'SELECT id, product_id, product_name, quantity, unit_price FROM customer_order_items
                     WHERE id = ? AND order_id = ? LIMIT 1',
                    [(int) $itemId, (int) $orderId]
                )->fetch();
                if (!$orderItem) throw new InvalidArgumentException('A selected item is not part of this order.');
                $previous = $this->db->raw(
                    "SELECT COALESCE(SUM(i.quantity), 0) AS quantity
                     FROM order_return_items i JOIN order_return_requests r ON r.id = i.return_request_id
                     WHERE r.order_id = ? AND i.order_item_id = ? AND r.status <> 'rejected'",
                    [(int) $orderId, (int) $itemId]
                )->fetch();
                if ($quantity > OrderReturnPolicy::remainingQuantity((int) $orderItem['quantity'], (int) $previous['quantity'])) {
                    throw new InvalidArgumentException('The requested return quantity exceeds the unreturned quantity for an item.');
                }
                $lineTotal = round((float) $orderItem['unit_price'] * $quantity, 2);
                $refundAmount += $lineTotal;
                $this->db->table('order_return_items')->insert([
                    'return_request_id' => $returnId,
                    'order_item_id' => (int) $itemId,
                    'product_id' => (int) $orderItem['product_id'],
                    'product_name' => $orderItem['product_name'],
                    'quantity' => $quantity,
                    'unit_price' => (float) $orderItem['unit_price'],
                    'line_total' => $lineTotal
                ]);
            }
            $this->db->table('order_return_requests')->where('id', $returnId)->update(['refund_amount' => $refundAmount]);
            $this->db->commit();
            return $returnId;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function updateReturnStatus($returnId, $status)
    {
        $allowed = ['approved', 'rejected', 'received', 'refunded'];
        if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('Choose a valid return status.');
        $this->db->transaction();
        try {
            $request = $this->db->raw(
                'SELECT r.*, o.payment_status, o.amount_paid FROM order_return_requests r
                 JOIN customer_orders o ON o.id = r.order_id WHERE r.id = ? FOR UPDATE',
                [(int) $returnId]
            )->fetch();
            if (!$request) throw new InvalidArgumentException('Return request not found.');
            if (!OrderReturnPolicy::canTransition($request['status'], $status)) {
                throw new InvalidArgumentException('This return request cannot move to the selected status.');
            }
            if ($status === 'received') {
                $items = $this->db->raw(
                    'SELECT product_id, SUM(quantity) AS quantity FROM order_return_items
                     WHERE return_request_id = ? GROUP BY product_id ORDER BY product_id',
                    [(int) $returnId]
                )->fetchAll();
                foreach ($items as $item) {
                    if (!$this->db->raw('SELECT id FROM products WHERE id = ? FOR UPDATE', [(int) $item['product_id']])->fetch()) {
                        throw new InvalidArgumentException('A returned product no longer exists in inventory.');
                    }
                    $this->db->raw('UPDATE products SET quantity = quantity + ? WHERE id = ?', [
                        (int) $item['quantity'], (int) $item['product_id']
                    ]);
                }
            }
            if ($status === 'refunded') {
                $paid = (float) $request['amount_paid'];
                $refundAmount = OrderReturnPolicy::refundAmount((float) $request['refund_amount'], $paid);
                if ($refundAmount <= 0) {
                    throw new InvalidArgumentException('Record a customer payment before recording a refund.');
                }
                $requestStatus = OrderReturnPolicy::paymentStatusAfterRefund($paid, $refundAmount, $request['payment_status']);
                $this->db->table('customer_orders')->where('id', (int) $request['order_id'])->update([
                    'amount_paid' => max(0, $paid - $refundAmount),
                    'payment_status' => $requestStatus
                ]);
                $this->db->table('order_return_requests')->where('id', (int) $returnId)->update([
                    'refund_amount' => $refundAmount
                ]);
            }
            $this->db->table('order_return_requests')->where('id', (int) $returnId)->update(['status' => $status]);
            $this->db->commit();
            return $request;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function createShopOrder($customerId, array $items, $address, $phone = '', $notes = '', $recipientName = '', $deliveryZoneId = null, $paymentMethod = 'cash')
    {
        if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
            throw new InvalidArgumentException('Choose Cash on delivery or GCash.');
        }
        if (!is_string($address) || trim($address) === '' || mb_strlen(trim($address)) > 500) {
            throw new InvalidArgumentException('Enter a delivery address of 500 characters or fewer.');
        }
        if (!is_string($phone) || trim($phone) === '' || mb_strlen(trim($phone)) > 40) {
            throw new InvalidArgumentException('Enter a contact phone number of 40 characters or fewer.');
        }
        if (!is_string($recipientName) || trim($recipientName) === '' || mb_strlen(trim($recipientName)) > 160) {
            throw new InvalidArgumentException('Enter a recipient name of 160 characters or fewer.');
        }
        if (!is_string($notes) || mb_strlen(trim($notes)) > 1000) {
            throw new InvalidArgumentException('Delivery notes must be 1,000 characters or fewer.');
        }

        $this->db->transaction();
        try {
            if (!$this->partyExists('customers', $customerId)) {
                throw new InvalidArgumentException('Your customer profile could not be found.');
            }

            $normalized = $this->normalizeItems($items, 'orders');
            usort($normalized, function ($left, $right) {
                return $left['product_id'] <=> $right['product_id'];
            });
            $total = 0;
            foreach ($normalized as &$item) {
                $product = $this->db->raw(
                    'SELECT id, product_name, price, quantity FROM products WHERE id = ? FOR UPDATE',
                    [$item['product_id']]
                )->fetch();
                if (!$product) {
                    throw new InvalidArgumentException('One of the selected products no longer exists.');
                }
                if ((int) $product['quantity'] < $item['quantity']) {
                    throw new InvalidArgumentException('Not enough stock for ' . $product['product_name'] . '.');
                }
                $item['product_name'] = $product['product_name'];
                $item['unit_price'] = (float) $product['price'];
                $item['line_total'] = round($item['unit_price'] * $item['quantity'], 2);
                if ($item['line_total'] > 9999999999.99 || $total + $item['line_total'] > 9999999999.99) {
                    throw new InvalidArgumentException('The total exceeds the supported transaction amount.');
                }
                $total += $item['line_total'];
            }
            unset($item);

            $zoneId = filter_var($deliveryZoneId, FILTER_VALIDATE_INT);
            if (!$zoneId || $zoneId < 1) throw new InvalidArgumentException('Choose a valid delivery area.');
            $zone = $this->db->raw(
                'SELECT id, area_name, fee, free_delivery_enabled, free_delivery_minimum
                 FROM delivery_zones WHERE id = ? AND is_active = 1 LIMIT 1 FOR UPDATE',
                [(int) $zoneId]
            )->fetch();
            if (!$zone) throw new InvalidArgumentException('This delivery area is no longer available. Choose another area.');
            $quote = DeliveryPricingPolicy::quote(
                $total,
                $zone['fee'],
                $zone['free_delivery_enabled'],
                $zone['free_delivery_minimum']
            );

            $this->db->table('customer_orders')->insert([
                'customer_id' => $customerId,
                'status' => 'pending',
                'stock_reserved' => 1,
                'payment_status' => 'unpaid',
                'payment_method' => $paymentMethod,
                'amount_paid' => 0,
                'delivery_address' => trim($address),
                'delivery_phone' => trim($phone),
                'delivery_name' => trim($recipientName),
                'delivery_notes' => trim($notes) ?: null,
                'delivery_zone' => $zone['area_name'],
                'subtotal' => $quote['subtotal'],
                'delivery_fee' => $quote['delivery_fee'],
                'delivery_discount' => $quote['delivery_discount'],
                'total' => $quote['total']
            ]);
            $orderId = (int) $this->db->last_id();
            foreach ($normalized as $item) {
                $this->insertDocumentItem('orders', $orderId, $item);
                $this->db->raw(
                    'UPDATE products SET quantity = quantity - ? WHERE id = ?',
                    [$item['quantity'], $item['product_id']]
                );
            }

            $this->recordCustomerOrderNotification((int) $customerId, $orderId, 'pending');
            $this->db->commit();
            return $orderId;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function submitCustomerGcashReference($customerId, $orderId, $reference)
    {
        if (!is_string($reference)) {
            throw new InvalidArgumentException('Enter the GCash transaction reference.');
        }
        $reference = trim($reference);
        if (mb_strlen($reference) < 6 || mb_strlen($reference) > 100
            || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new InvalidArgumentException('Transaction reference must be 6 to 100 printable characters.');
        }
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT id, status, payment_status, payment_method
                 FROM customer_orders WHERE id = ? AND customer_id = ? FOR UPDATE',
                [(int) $orderId, (int) $customerId]
            )->fetch();
            if (!$order) throw new InvalidArgumentException('Order not found.');
            if ($order['payment_method'] !== 'gcash') {
                throw new InvalidArgumentException('This order does not use GCash.');
            }
            if ($order['status'] === 'cancelled'
                || in_array($order['payment_status'], ['paid', 'refunded'], true)) {
                throw new InvalidArgumentException('A payment reference cannot be submitted for a cancelled or already paid order.');
            }
            $this->db->table('customer_orders')->where('id', (int) $orderId)->update([
                'gcash_payment_reference' => $reference
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function productHasHistory($id)
    {
        $result = $this->db->raw("SELECT
            EXISTS(SELECT 1 FROM sale_items WHERE product_id = ?) OR
            EXISTS(SELECT 1 FROM customer_order_items WHERE product_id = ?) OR
            EXISTS(SELECT 1 FROM purchase_items WHERE product_id = ?) OR
            EXISTS(SELECT 1 FROM product_reviews WHERE product_id = ?) AS linked",
            [$id, $id, $id, $id])->fetch();
        return !empty($result['linked']);
    }

    public function partyExists($table, $id)
    {
        return (bool) $this->db->raw("SELECT id FROM {$table} WHERE id = ?", [$id])->fetch();
    }

    public function createDocument($kind, $partyId, array $items, array $payment = [])
    {
        $config = $this->documentConfig($kind);
        $this->db->transaction();
        try {
            if (!$this->partyExists($config['party_table'], $partyId)) {
                throw new InvalidArgumentException('Select a valid ' . $config['party_label'] . '.');
            }

            $normalized = $this->normalizeItems($items, $kind);
            usort($normalized, function ($left, $right) {
                return $left['product_id'] <=> $right['product_id'];
            });
            $total = 0;
            foreach ($normalized as &$item) {
                $product = $this->db->raw('SELECT id, product_name, price, quantity FROM products WHERE id = ? FOR UPDATE', [$item['product_id']])->fetch();
                if (!$product) throw new InvalidArgumentException('One of the selected products no longer exists.');
                if ($kind === 'sales' && (int) $product['quantity'] < $item['quantity']) {
                    throw new InvalidArgumentException('Not enough stock for ' . $product['product_name'] . '.');
                }
                $item['product_name'] = $product['product_name'];
                $item['unit_price'] = $kind === 'purchases' ? $item['unit_cost'] : (float) $product['price'];
                $item['line_total'] = round($item['unit_price'] * $item['quantity'], 2);
                if ($item['line_total'] > 9999999999.99 || $total + $item['line_total'] > 9999999999.99) {
                    throw new InvalidArgumentException('The total exceeds the supported transaction amount.');
                }
                $total += $item['line_total'];
            }
            unset($item);

            $status = $kind === 'sales' ? 'completed' : 'pending';
            $paymentData = $kind === 'sales' || $kind === 'orders'
                ? $this->paymentData($payment, $total, $kind === 'sales' ? 'paid' : 'unpaid')
                : [];
            if ($kind === 'orders') {
                PaymentPolicy::assertOrderPaymentTiming($paymentData['payment_status'], $status);
            }
            $table = $config['table'];
            if ($kind === 'sales' || $kind === 'orders') {
                $documentData = [$config['party_key'] => $partyId, 'status' => $status, 'total' => $total];
                if ($kind === 'orders') $documentData['subtotal'] = $total;
                $this->db->table($table)->insert(array_merge(
                    $documentData,
                    $paymentData
                ));
            } else {
                $this->db->table($table)->insert([$config['party_key'] => $partyId, 'status' => $status, 'total' => $total]);
            }
            $documentId = (int) $this->db->last_id();
            foreach ($normalized as $item) {
                $this->insertDocumentItem($kind, $documentId, $item);
                if ($kind === 'sales') {
                    $this->db->raw('UPDATE products SET quantity = quantity - ? WHERE id = ?', [$item['quantity'], $item['product_id']]);
                }
            }
            $this->db->commit();
            return $documentId;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function updateDocument($kind, $id, $partyId, array $items, $payment = null)
    {
        $config = $this->documentConfig($kind);
        $this->db->transaction();
        try {
            $document = $this->db->raw("SELECT * FROM {$config['table']} WHERE id = ? FOR UPDATE", [$id])->fetch();
            if (!$document) throw new InvalidArgumentException('Record not found.');
            if ($kind === 'sales' && $document['status'] !== 'completed') {
                throw new InvalidArgumentException('Refunded sales cannot be edited.');
            }
            if ($kind !== 'sales' && $document['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending records can be edited.');
            }
            if (!$this->partyExists($config['party_table'], $partyId)) {
                throw new InvalidArgumentException('Select a valid ' . $config['party_label'] . '.');
            }

            $unitField = $kind === 'purchases' ? 'unit_cost' : 'unit_price';
            $oldItems = $this->db->raw(
                "SELECT product_id, SUM(quantity) AS quantity, MAX({$unitField}) AS unit_price FROM {$config['items_table']}
                 WHERE {$config['foreign_key']} = ? GROUP BY product_id",
                [$id]
            )->fetchAll();
            $oldQuantities = [];
            $oldUnitPrices = [];
            foreach ($oldItems as $oldItem) {
                $oldQuantities[(int) $oldItem['product_id']] = (int) $oldItem['quantity'];
                if ($kind !== 'purchases') {
                    $oldUnitPrices[(int) $oldItem['product_id']] = (float) $oldItem['unit_price'];
                }
            }

            $normalized = $this->normalizeItems($items, $kind);
            $newQuantities = [];
            foreach ($normalized as &$item) {
                $newQuantities[$item['product_id']] = $item['quantity'];
            }
            unset($item);

            $productIds = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));
            sort($productIds, SORT_NUMERIC);
            $lockedProducts = [];
            foreach ($productIds as $productId) {
                $product = $this->db->raw(
                    'SELECT id, product_name, price, quantity FROM products WHERE id = ? FOR UPDATE',
                    [$productId]
                )->fetch();
                if (!$product) throw new InvalidArgumentException('A product in this record no longer exists.');
                $lockedProducts[$productId] = $product;
            }

            $total = 0;
            foreach ($normalized as &$item) {
                $product = $lockedProducts[$item['product_id']];
                $item['product_name'] = $product['product_name'];
                $item['unit_price'] = $kind === 'purchases'
                    ? $item['unit_cost']
                    : ($oldUnitPrices[$item['product_id']] ?? (float) $product['price']);
                $item['line_total'] = round($item['unit_price'] * $item['quantity'], 2);
                if ($item['line_total'] > 9999999999.99 || $total + $item['line_total'] > 9999999999.99) {
                    throw new InvalidArgumentException('The total exceeds the supported transaction amount.');
                }
                $total += $item['line_total'];
            }
            unset($item);

            $documentTotal = $kind === 'orders'
                ? round($total + (float) $document['delivery_fee'] - (float) $document['delivery_discount'], 2)
                : $total;
            $paymentData = [];
            if ($kind === 'sales' || $kind === 'orders') {
                $payment = $payment ?? [
                    'payment_status' => $document['payment_status'],
                    'payment_method' => $document['payment_method'],
                    'amount_paid' => $document['amount_paid']
                ];
                $paymentData = $this->paymentData($payment, $documentTotal, $kind === 'sales' ? 'paid' : 'unpaid');
                if ($kind === 'orders') {
                    PaymentPolicy::assertOrderPaymentTiming(
                        $paymentData['payment_status'],
                        $document['status'],
                        $this->hasVerifiedGcashReference($document, $paymentData)
                    );
                }
            }

            if ($kind === 'sales' || ($kind === 'orders' && !empty($document['stock_reserved']))) {
                foreach ($productIds as $productId) {
                    $stockDelta = ($oldQuantities[$productId] ?? 0) - ($newQuantities[$productId] ?? 0);
                    if ($stockDelta < 0 && (int) $lockedProducts[$productId]['quantity'] < abs($stockDelta)) {
                        throw new InvalidArgumentException('Not enough stock for ' . $lockedProducts[$productId]['product_name'] . '.');
                    }
                    if ($stockDelta !== 0) {
                        $operator = $stockDelta > 0 ? '+' : '-';
                        $this->db->raw(
                            "UPDATE products SET quantity = quantity {$operator} ? WHERE id = ?",
                            [abs($stockDelta), $productId]
                        );
                    }
                }
            }

            $documentData = [$config['party_key'] => $partyId, 'total' => $documentTotal];
            if ($kind === 'orders') $documentData['subtotal'] = $total;
            $this->db->table($config['table'])
                ->where('id', $id)
                ->update(array_merge($documentData, $paymentData));
            $this->db->table($config['items_table'])->where($config['foreign_key'], $id)->delete();
            foreach ($normalized as $item) {
                $this->insertDocumentItem($kind, $id, $item);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function deleteDocument($kind, $id)
    {
        $config = $this->documentConfig($kind);
        $this->db->transaction();
        try {
            $document = $this->db->raw(
                "SELECT * FROM {$config['table']} WHERE id = ? FOR UPDATE",
                [$id]
            )->fetch();
            if (!$document) {
                $this->db->roll_back();
                return false;
            }
            if ($kind === 'orders' && in_array($document['status'], ['processing', 'ready_to_ship', 'shipped', 'delivered', 'completed'], true)) {
                throw new InvalidArgumentException('Cancel the order before processing ends; shipped or delivered orders cannot be deleted.');
            }

            $itemTotals = $this->db->raw(
                "SELECT product_id, SUM(quantity) AS quantity FROM {$config['items_table']}
                 WHERE {$config['foreign_key']} = ? GROUP BY product_id ORDER BY product_id",
                [$id]
            )->fetchAll();
            $adjustStock = ($kind === 'sales' && $document['status'] === 'completed')
                || ($kind === 'orders' && ($document['status'] === 'completed' || !empty($document['stock_reserved'])))
                || ($kind === 'purchases' && $document['status'] === 'received');

            if ($adjustStock) {
                foreach ($itemTotals as $item) {
                    $product = $this->db->raw(
                        'SELECT id, product_name, quantity FROM products WHERE id = ? FOR UPDATE',
                        [$item['product_id']]
                    )->fetch();
                    if (!$product) throw new InvalidArgumentException('A product in this record no longer exists.');
                    if ($kind === 'purchases' && (int) $product['quantity'] < (int) $item['quantity']) {
                        throw new InvalidArgumentException(
                            'Cannot delete this received purchase because ' . $product['product_name'] . ' stock has already been used.'
                        );
                    }
                    $operator = $kind === 'purchases' ? '-' : '+';
                    $this->db->raw(
                        "UPDATE products SET quantity = quantity {$operator} ? WHERE id = ?",
                        [$item['quantity'], $item['product_id']]
                    );
                }
            }

            $this->db->table($config['table'])->where('id', $id)->delete();
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function updateDocumentStatus($kind, $id, $newStatus)
    {
        $config = $this->documentConfig($kind);
        $table = $config['table'];
        $this->db->transaction();
        try {
            $document = $this->db->raw("SELECT * FROM {$table} WHERE id = ? FOR UPDATE", [$id])->fetch();
            if (!$document) throw new InvalidArgumentException('Record not found.');
            $current = $document['status'];
            $allowed = $kind === 'sales' ? ['completed', 'refunded'] : ($kind === 'orders'
                ? ['pending', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'completed', 'cancelled']
                : ['pending', 'received', 'cancelled']);
            if (!in_array($newStatus, $allowed, true)) throw new InvalidArgumentException('Choose a valid status.');
            if ($current === $newStatus) {
                $this->db->commit();
                return;
            }
            if ($kind === 'sales' && $current === 'completed' && $newStatus === 'refunded') {
                $items = $this->db->raw("SELECT product_id, quantity FROM {$config['items_table']} WHERE {$config['foreign_key']} = ?", [$id])->fetchAll();
                foreach ($items as $item) $this->db->raw('UPDATE products SET quantity = quantity + ? WHERE id = ?', [$item['quantity'], $item['product_id']]);
                $this->db->raw("UPDATE {$table} SET status = ?, payment_status = 'refunded', amount_paid = 0 WHERE id = ?", [$newStatus, $id]);
                $this->db->commit();
                return;
            }
            if ($kind === 'orders') {
                $transitions = [
                    'pending' => ['processing', 'cancelled'],
                    'processing' => ['ready_to_ship', 'cancelled'],
                    'ready_to_ship' => ['shipped', 'cancelled'],
                    'shipped' => ['delivered'],
                    'delivered' => ['completed']
                ];
                if (!in_array($newStatus, $transitions[$current] ?? [], true)) {
                    throw new InvalidArgumentException('Orders must move through the fulfillment steps in order; only delivered orders can be completed.');
                }
            } elseif ($current !== 'pending') {
                throw new InvalidArgumentException('Only pending records can change status.');
            }

            $reservedOrder = $kind === 'orders' && !empty($document['stock_reserved']);
            $deductOrderStock = $kind === 'orders' && in_array($newStatus, ['processing', 'completed'], true) && !$reservedOrder;
            if (($deductOrderStock
                    || ($kind === 'purchases' && $newStatus === 'received'))
                || ($reservedOrder && $newStatus === 'cancelled')) {
                $items = $this->db->raw(
                    "SELECT product_id, SUM(quantity) AS quantity FROM {$config['items_table']}
                     WHERE {$config['foreign_key']} = ? GROUP BY product_id ORDER BY product_id",
                    [$id]
                )->fetchAll();
                foreach ($items as $item) {
                    $product = $this->db->raw('SELECT id, quantity, product_name FROM products WHERE id = ? FOR UPDATE', [$item['product_id']])->fetch();
                    if (!$product) throw new InvalidArgumentException('A product in this record no longer exists.');
                    if ($deductOrderStock && (int) $product['quantity'] < (int) $item['quantity']) {
                        throw new InvalidArgumentException('Not enough stock for ' . $product['product_name'] . '.');
                    }
                    $operator = $deductOrderStock ? '-' : '+';
                    $this->db->raw("UPDATE products SET quantity = quantity {$operator} ? WHERE id = ?", [$item['quantity'], $item['product_id']]);
                }
            }
            if ($deductOrderStock) {
                $this->db->raw("UPDATE {$table} SET status = ?, stock_reserved = 1 WHERE id = ?", [$newStatus, $id]);
            } elseif ($reservedOrder && $newStatus === 'cancelled') {
                $this->db->raw("UPDATE {$table} SET status = ?, stock_reserved = 0 WHERE id = ?", [$newStatus, $id]);
            } elseif ($kind === 'orders' && $newStatus === 'completed') {
                $this->db->raw("UPDATE {$table} SET status = ?, completed_at = NOW() WHERE id = ?", [$newStatus, $id]);
            } elseif ($kind === 'orders' && $newStatus === 'delivered') {
                $payment = PaymentPolicy::cashOnDeliveryPayment(
                    $document['payment_status'],
                    $document['payment_method'],
                    $newStatus,
                    $document['total']
                );
                if ($payment !== null) {
                    $this->db->raw(
                        "UPDATE {$table}
                         SET status = ?, delivered_at = NOW(), payment_status = ?, amount_paid = ?
                         WHERE id = ?",
                        [$newStatus, $payment['payment_status'], $payment['amount_paid'], $id]
                    );
                } else {
                    $this->db->raw("UPDATE {$table} SET status = ?, delivered_at = NOW() WHERE id = ?", [$newStatus, $id]);
                }
            } else {
                $this->db->raw("UPDATE {$table} SET status = ? WHERE id = ?", [$newStatus, $id]);
            }
            if ($kind === 'orders') {
                $this->recordCustomerOrderNotification((int) $document['customer_id'], (int) $id, $newStatus);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function deletePendingDocument($kind, $id)
    {
        $config = $this->documentConfig($kind);
        $row = $this->db->raw("SELECT status FROM {$config['table']} WHERE id = ?", [$id])->fetch();
        if (!$row) return false;
        if ($row['status'] !== 'pending' && $row['status'] !== 'cancelled') {
            throw new InvalidArgumentException('Only pending or cancelled records can be deleted.');
        }
        $this->db->raw("DELETE FROM {$config['table']} WHERE id = ?", [$id]);
        return true;
    }

    private function insertDocumentItem($kind, $documentId, array $item)
    {
        $config = $this->documentConfig($kind);
        $priceField = $kind === 'purchases' ? 'unit_cost' : 'unit_price';
        $price = $kind === 'purchases' ? $item['unit_cost'] : $item['unit_price'];
        $this->db->table($config['items_table'])->insert([
            $config['foreign_key'] => $documentId,
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity'],
            $priceField => $price,
            'line_total' => $item['line_total']
        ]);
    }

    private function paymentData(array $payment, $total, $defaultStatus = 'unpaid')
    {
        $status = $payment['payment_status'] ?? $defaultStatus;
        $method = $payment['payment_method'] ?? 'cash';
        return PaymentPolicy::normalize($status, $method, $total);
    }

    private function hasVerifiedGcashReference(array $order, array $paymentData)
    {
        return ($order['payment_method'] ?? null) === 'gcash'
            && ($paymentData['payment_method'] ?? null) === 'gcash'
            && trim((string) ($order['gcash_payment_reference'] ?? '')) !== '';
    }

    public function updateOrderPayment($id, array $payment)
    {
        $this->db->transaction();
        try {
            $order = $this->db->raw(
                'SELECT id, total, status, payment_method, gcash_payment_reference
                 FROM customer_orders WHERE id = ? FOR UPDATE',
                [$id]
            )->fetch();
            if (!$order) throw new InvalidArgumentException('Record not found.');
            $paymentData = $this->paymentData($payment, (float) $order['total']);
            PaymentPolicy::assertOrderPaymentTiming(
                $paymentData['payment_status'],
                $order['status'],
                $this->hasVerifiedGcashReference($order, $paymentData)
            );
            $this->db->table('customer_orders')->where('id', $id)->update($paymentData);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function reportData($start, $end)
    {
        $salesSummary = $this->db->raw("SELECT COUNT(*) AS count, COALESCE(SUM(total), 0) AS total
            FROM (
                SELECT total, created_at FROM sales WHERE status = 'completed'
                UNION ALL
                SELECT total,
                    CASE WHEN status = 'completed'
                        THEN COALESCE(completed_at, delivered_at, created_at)
                        ELSE COALESCE(delivered_at, created_at)
                    END AS created_at
                FROM customer_orders WHERE status IN ('completed','delivered')
            ) completed_sales
            WHERE DATE(created_at) BETWEEN ? AND ?", [$start, $end])->fetch();
        $purchaseSummary = $this->db->raw("SELECT COUNT(*) AS count, COALESCE(SUM(total), 0) AS total
            FROM purchases WHERE status = 'received' AND DATE(created_at) BETWEEN ? AND ?", [$start, $end])->fetch();
        $profitSummary = $this->db->raw("SELECT
                COALESCE(SUM(sold.total), 0) AS revenue,
                COALESCE(SUM(sold.quantity * COALESCE(p.estimated_unit_cost, costs.avg_cost)), 0) AS cost,
                COALESCE(SUM(CASE WHEN p.estimated_unit_cost IS NULL AND costs.avg_cost IS NOT NULL THEN sold.total ELSE 0 END), 0) AS recorded_cost_revenue,
                COALESCE(SUM(CASE WHEN p.estimated_unit_cost IS NOT NULL THEN sold.total ELSE 0 END), 0) AS estimated_cost_revenue,
                COALESCE(SUM(CASE WHEN costs.avg_cost IS NULL AND p.estimated_unit_cost IS NULL THEN sold.total ELSE 0 END), 0) AS uncosted_revenue
            FROM (
                SELECT product_id, SUM(quantity) AS quantity, SUM(line_total) AS total
                FROM (
                    SELECT si.product_id, si.quantity, si.line_total, s.created_at
                    FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
                    UNION ALL
                    SELECT oi.product_id, oi.quantity, oi.line_total,
                        CASE WHEN o.status = 'completed'
                            THEN COALESCE(o.completed_at, o.delivered_at, o.created_at)
                            ELSE COALESCE(o.delivered_at, o.created_at)
                        END AS created_at
                    FROM customer_order_items oi JOIN customer_orders o ON o.id = oi.order_id
                    WHERE o.status IN ('completed','delivered')
                ) sold_lines
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY product_id
            ) sold
            JOIN products p ON p.id = sold.product_id
            LEFT JOIN (
                SELECT pi.product_id, SUM(pi.quantity * pi.unit_cost) / NULLIF(SUM(pi.quantity), 0) AS avg_cost
                FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                WHERE p.status = 'received'
                GROUP BY pi.product_id
            ) costs ON costs.product_id = sold.product_id", [$start, $end])->fetch();
        $stockActivity = $this->db->raw("SELECT activity_date, activity_type, reference_id, product_name, quantity
            FROM (
                SELECT s.created_at AS activity_date, 'Sale' AS activity_type, s.id AS reference_id, si.product_name, -si.quantity AS quantity
                FROM sales s JOIN sale_items si ON si.sale_id = s.id
                WHERE s.status = 'completed'
                UNION ALL
                SELECT CASE WHEN o.status = 'completed'
                    THEN COALESCE(o.completed_at, o.delivered_at, o.created_at)
                    ELSE COALESCE(o.delivered_at, o.created_at)
                END, 'Order', o.id, oi.product_name, -oi.quantity
                FROM customer_orders o JOIN customer_order_items oi ON oi.order_id = o.id
                WHERE o.status IN ('completed','delivered')
                UNION ALL
                SELECT p.created_at, 'Purchase', p.id, pi.product_name, pi.quantity
                FROM purchases p JOIN purchase_items pi ON pi.purchase_id = p.id
                WHERE p.status = 'received'
            ) activity
            WHERE DATE(activity_date) BETWEEN ? AND ?
            ORDER BY activity_date DESC, reference_id DESC LIMIT 30", [$start, $end])->fetchAll();
        $topProducts = $this->db->raw("SELECT product_name, SUM(quantity) AS quantity, SUM(line_total) AS total
            FROM (
                SELECT si.product_id, si.product_name, si.quantity, si.line_total, s.created_at
                FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
                UNION ALL
                SELECT oi.product_id, oi.product_name, oi.quantity, oi.line_total,
                    CASE WHEN o.status = 'completed'
                        THEN COALESCE(o.completed_at, o.delivered_at, o.created_at)
                        ELSE COALESCE(o.delivered_at, o.created_at)
                    END AS created_at
                FROM customer_order_items oi JOIN customer_orders o ON o.id = oi.order_id
                WHERE o.status IN ('completed','delivered')
            ) completed_items
            WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY product_id, product_name
            ORDER BY quantity DESC LIMIT 10", [$start, $end])->fetchAll();
        $dailySales = $this->db->raw("SELECT DATE(created_at) AS date, SUM(total) AS total
            FROM (
                SELECT total, created_at FROM sales WHERE status = 'completed'
                UNION ALL
                SELECT total,
                    CASE WHEN status = 'completed'
                        THEN COALESCE(completed_at, delivered_at, created_at)
                        ELSE COALESCE(delivered_at, created_at)
                    END AS created_at
                FROM customer_orders WHERE status IN ('completed','delivered')
            ) completed_sales
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 31", [$start, $end])->fetchAll();
        $stock = $this->db->raw("SELECT COUNT(*) AS products, COALESCE(SUM(quantity), 0) AS units,
            COALESCE(SUM(quantity * price), 0) AS value, SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock
            FROM products")->fetch();
        $lowStockProducts = $this->db->raw(
            'SELECT id, product_name, quantity, reorder_level
             FROM products WHERE quantity <= reorder_level
             ORDER BY quantity ASC, product_name ASC LIMIT 100'
        )->fetchAll();
        return [
            'sales' => $salesSummary,
            'purchases' => $purchaseSummary,
            'profit' => $this->profitSummary($profitSummary),
            'stock' => $stock,
            'low_stock_products' => $lowStockProducts,
            'stock_activity' => $stockActivity,
            'top_products' => $topProducts,
            'daily_sales' => $dailySales
        ];
    }

    public function googleSheetsSyncData($start, $end)
    {
        $sales = $this->db->raw(
            'SELECT id, created_at, status, payment_status, payment_method, amount_paid, total
             FROM sales WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at, id',
            [$start, $end]
        )->fetchAll();
        $orders = $this->db->raw(
            'SELECT id, created_at, status, payment_status, payment_method, amount_paid, total
             FROM customer_orders WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at, id',
            [$start, $end]
        )->fetchAll();
        $inventory = $this->db->raw(
            'SELECT id, product_name, sku, category, price, quantity, reorder_level
             FROM products ORDER BY product_name, id'
        )->fetchAll();

        $documentRows = function (array $documents) {
            return array_map(function ($document) {
                return [
                    (int) $document['id'],
                    (string) $document['created_at'],
                    (string) $document['status'],
                    (string) $document['payment_status'],
                    (string) $document['payment_method'],
                    (float) $document['amount_paid'],
                    (float) $document['total']
                ];
            }, $documents);
        };

        return [
            'Stockwell Sales' => [
                'headers' => ['Sale ID', 'Date', 'Status', 'Payment status', 'Payment method', 'Amount paid', 'Total'],
                'rows' => $documentRows($sales)
            ],
            'Stockwell Orders' => [
                'headers' => ['Order ID', 'Date', 'Status', 'Payment status', 'Payment method', 'Amount paid', 'Total'],
                'rows' => $documentRows($orders)
            ],
            'Stockwell Inventory' => [
                'headers' => ['Product ID', 'Product', 'SKU', 'Category', 'Price', 'Quantity', 'Reorder level'],
                'rows' => array_map(function ($product) {
                    return [
                        (int) $product['id'],
                        (string) $product['product_name'],
                        (string) ($product['sku'] ?? ''),
                        (string) ($product['category'] ?? ''),
                        (float) $product['price'],
                        (int) $product['quantity'],
                        (int) $product['reorder_level']
                    ];
                }, $inventory)
            ]
        ];
    }

    private function profitSummary(array $summary)
    {
        $revenue = (float) ($summary['revenue'] ?? 0);
        $recordedCostRevenue = (float) ($summary['recorded_cost_revenue'] ?? 0);
        $estimatedCostRevenue = (float) ($summary['estimated_cost_revenue'] ?? 0);
        $costedRevenue = $recordedCostRevenue + $estimatedCostRevenue;
        $cost = (float) ($summary['cost'] ?? 0);
        $grossProfit = $costedRevenue - $cost;
        return [
            'revenue' => $revenue,
            'costed_revenue' => $costedRevenue,
            'recorded_cost_revenue' => $recordedCostRevenue,
            'estimated_cost_revenue' => $estimatedCostRevenue,
            'uncosted_revenue' => (float) ($summary['uncosted_revenue'] ?? 0),
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $costedRevenue > 0 ? ($grossProfit / $costedRevenue) * 100 : null
        ];
    }

    private function normalizeItems(array $items, $kind)
    {
        if (count($items) < 1 || count($items) > 100) throw new InvalidArgumentException('Add between 1 and 100 products.');
        $byProduct = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Each item must be a product and quantity.');
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || $quantity === false || $quantity < 1 || $quantity > 1000000) {
                throw new InvalidArgumentException('Select a product and enter a quantity of at least one.');
            }
            if ($kind === 'purchases') {
                $cost = $item['unit_cost'] ?? null;
                if (!is_numeric($cost) || (float) $cost <= 0 || (float) $cost > 99999999.99) {
                    throw new InvalidArgumentException('Purchase unit cost must be a positive amount.');
                }
            }
            if ($kind === 'purchases') {
                $byProduct[] = ['product_id' => $productId, 'quantity' => $quantity, 'unit_cost' => (float) $cost];
                continue;
            }
            if (!isset($byProduct[$productId])) {
                $byProduct[$productId] = ['product_id' => $productId, 'quantity' => 0];
            }
            $byProduct[$productId]['quantity'] += $quantity;
        }
        return array_values($byProduct);
    }

    private function documentConfig($kind)
    {
        $config = [
            'sales' => ['table' => 'sales', 'items_table' => 'sale_items', 'foreign_key' => 'sale_id', 'party_table' => 'customers', 'party_key' => 'customer_id', 'party_label' => 'customer'],
            'orders' => ['table' => 'customer_orders', 'items_table' => 'customer_order_items', 'foreign_key' => 'order_id', 'party_table' => 'customers', 'party_key' => 'customer_id', 'party_label' => 'customer'],
            'purchases' => ['table' => 'purchases', 'items_table' => 'purchase_items', 'foreign_key' => 'purchase_id', 'party_table' => 'suppliers', 'party_key' => 'supplier_id', 'party_label' => 'supplier']
        ];
        if (!isset($config[$kind])) throw new InvalidArgumentException('Unknown record type.');
        return $config[$kind];
    }
}
