<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * ProductModel
 *
 * Model for managing products data
 */
class ProductModel extends Model
{
    // Increment when the product schema changes so migrations run once again.
    private const PRODUCT_SCHEMA_VERSION = 1;

    /**
     * Table associated with the model
     *
     * @var string
     */
    protected $table = 'products';

    /**
     * Primary key of the table
     *
     * @var string
     */
    protected $primary_key = 'id';

    /**
     * Fillable attributes for mass assignment
     *
     * @var array
     */
    protected $fillable = [
        'product_name',
        'description',
        'price',
        'quantity',
        'category',
        'sku',
        'barcode',
        'reorder_level',
        'supplier_id',
        'image_url',
        'estimated_unit_cost'
    ];

    public function ensureTable()
    {
        if ($this->productSchemaIsCurrent()) return;

        $lock = $this->db->raw("SELECT GET_LOCK('manuel_friends_product_schema', 10) AS acquired")->fetch();
        if (empty($lock['acquired'])) {
            throw new RuntimeException('Could not acquire the database product schema initialization lock.');
        }

        try {
            if ($this->productSchemaIsCurrent()) return;

            $this->createOrUpdateProductSchema();
            $this->db->raw("CREATE TABLE IF NOT EXISTS product_schema_version (
                version INT UNSIGNED NOT NULL PRIMARY KEY,
                installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->raw('INSERT IGNORE INTO product_schema_version (version) VALUES (' . self::PRODUCT_SCHEMA_VERSION . ')');
        } finally {
            $this->db->raw("SELECT RELEASE_LOCK('manuel_friends_product_schema')");
        }
    }

    private function productSchemaIsCurrent()
    {
        if (!$this->db->raw("SHOW TABLES LIKE 'product_schema_version'")->fetch()) return false;
        $version = $this->db->raw('SELECT MAX(version) AS version FROM product_schema_version')->fetch();
        return (int) ($version['version'] ?? 0) >= self::PRODUCT_SCHEMA_VERSION;
    }

    private function createOrUpdateProductSchema()
    {
        $this->db->raw(
            'CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                price DECIMAL(10,2) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                category VARCHAR(120) NULL,
                sku VARCHAR(80) NULL UNIQUE,
                barcode VARCHAR(80) NULL UNIQUE,
                reorder_level INT UNSIGNED NOT NULL DEFAULT 5,
                supplier_id INT UNSIGNED NULL,
                image_url VARCHAR(1000) NULL,
                estimated_unit_cost DECIMAL(10,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $columns = $this->db->raw('SHOW COLUMNS FROM products')->fetchAll();
        $existing = array_map(function ($column) { return $column->Field ?? $column['Field'] ?? ''; }, $columns);
        $missing = [
            'category' => 'ALTER TABLE products ADD COLUMN category VARCHAR(120) NULL AFTER quantity',
            'sku' => 'ALTER TABLE products ADD COLUMN sku VARCHAR(80) NULL UNIQUE AFTER category',
            'barcode' => 'ALTER TABLE products ADD COLUMN barcode VARCHAR(80) NULL UNIQUE AFTER sku',
            'reorder_level' => 'ALTER TABLE products ADD COLUMN reorder_level INT UNSIGNED NOT NULL DEFAULT 5 AFTER barcode',
            'supplier_id' => 'ALTER TABLE products ADD COLUMN supplier_id INT UNSIGNED NULL AFTER reorder_level',
            'image_url' => 'ALTER TABLE products ADD COLUMN image_url VARCHAR(1000) NULL AFTER supplier_id',
            'estimated_unit_cost' => 'ALTER TABLE products ADD COLUMN estimated_unit_cost DECIMAL(10,2) NULL AFTER image_url'
        ];
        foreach ($missing as $column => $sql) {
            if (!in_array($column, $existing, true)) $this->db->raw($sql);
        }
        $this->db->raw(
            'CREATE TABLE IF NOT EXISTS stock_adjustments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity_before INT UNSIGNED NOT NULL,
                quantity_change INT NOT NULL,
                quantity_after INT UNSIGNED NOT NULL,
                reason VARCHAR(255) NOT NULL,
                actor_id INT UNSIGNED NULL,
                actor_name VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_stock_adjustments_product_created (product_id, created_at),
                INDEX idx_stock_adjustments_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function listStockAdjustments($limit = 100, $productId = null)
    {
        $limit = max(1, min(500, (int) $limit));
        if ($productId !== null) {
            return $this->db->raw(
                "SELECT id, product_id, product_name, quantity_before, quantity_change,
                    quantity_after, reason, actor_id, actor_name, created_at
                 FROM stock_adjustments WHERE product_id = ? ORDER BY id DESC LIMIT {$limit}",
                [(int) $productId]
            )->fetchAll();
        }
        return $this->db->raw(
            "SELECT id, product_id, product_name, quantity_before, quantity_change,
                quantity_after, reason, actor_id, actor_name, created_at
             FROM stock_adjustments ORDER BY id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    public function adjustStock($productId, $quantityChange, $reason, $actorId, $actorName)
    {
        $this->db->transaction();
        try {
            $product = $this->db->raw(
                'SELECT id, product_name, quantity FROM products WHERE id = ? LIMIT 1 FOR UPDATE',
                [(int) $productId]
            )->fetch();
            if (!$product) throw new InvalidArgumentException('Product not found.');

            $before = (int) $product['quantity'];
            $after = $before + (int) $quantityChange;
            if ($after < 0 || $after > 4294967295) {
                throw new InvalidArgumentException('The stock change would put the quantity outside the supported range.');
            }

            $this->db->raw('UPDATE products SET quantity = ? WHERE id = ?', [$after, (int) $productId]);
            $this->db->raw(
                'INSERT INTO stock_adjustments
                    (product_id, product_name, quantity_before, quantity_change, quantity_after, reason, actor_id, actor_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $productId,
                    $product['product_name'],
                    $before,
                    (int) $quantityChange,
                    $after,
                    $reason,
                    $actorId === null ? null : (int) $actorId,
                    mb_substr((string) $actorName, 0, 120)
                ]
            );
            $this->db->commit();
            return [
                'product_id' => (int) $productId,
                'product_name' => $product['product_name'],
                'quantity_before' => $before,
                'quantity_change' => (int) $quantityChange,
                'quantity_after' => $after,
                'reason' => $reason,
                'actor_id' => $actorId === null ? null : (int) $actorId,
                'actor_name' => mb_substr((string) $actorName, 0, 120)
            ];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function shopCatalog()
    {
        return $this->db->raw(
            'SELECT p.id, p.product_name, p.description, p.price, p.quantity, p.category, p.sku, p.barcode,
                p.reorder_level, p.supplier_id, p.image_url,
                (SELECT COALESCE(AVG(r.rating), 0) FROM product_reviews r WHERE r.product_id = p.id) AS average_rating,
                (SELECT COUNT(*) FROM product_reviews r WHERE r.product_id = p.id) AS review_count,
                COALESCE(sales_summary.units_sold, 0) AS units_sold
             FROM products p
             LEFT JOIN (
                SELECT sold_items.product_id, SUM(sold_items.quantity) AS units_sold
                FROM (
                    SELECT si.product_id, si.quantity
                    FROM sale_items si
                    JOIN sales s ON s.id = si.sale_id AND s.status = \'completed\'
                    UNION ALL
                    SELECT oi.product_id, oi.quantity
                    FROM customer_order_items oi
                    JOIN customer_orders o ON o.id = oi.order_id
                    WHERE o.status IN (\'completed\', \'delivered\')
                ) sold_items
                GROUP BY sold_items.product_id
             ) sales_summary ON sales_summary.product_id = p.id
             WHERE p.quantity > 0
             ORDER BY p.product_name'
        )->fetchAll();
    }
}
