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
    private const PRODUCT_SCHEMA_VERSION = 2;

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
        'estimated_unit_cost',
        'parent_product_id',
        'variant_name'
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
                parent_product_id INT UNSIGNED NULL,
                variant_name VARCHAR(120) NULL,
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
            'estimated_unit_cost' => 'ALTER TABLE products ADD COLUMN estimated_unit_cost DECIMAL(10,2) NULL AFTER image_url',
            'parent_product_id' => 'ALTER TABLE products ADD COLUMN parent_product_id INT UNSIGNED NULL AFTER estimated_unit_cost',
            'variant_name' => 'ALTER TABLE products ADD COLUMN variant_name VARCHAR(120) NULL AFTER parent_product_id'
        ];
        foreach ($missing as $column => $sql) {
            if (!in_array($column, $existing, true)) $this->db->raw($sql);
        }
        $this->db->raw("UPDATE products SET category = CASE
            WHEN product_name = 'Laptop' THEN 'Computers'
            WHEN product_name IN ('Mechanical Keyboard') THEN 'Keyboards'
            WHEN product_name IN ('Monitor 4K', 'Monitor Stand') THEN 'Monitors & Stands'
            WHEN product_name IN ('Wireless Mouse', 'Webcam HD', 'Laptop Cooling Pad', 'USB-C Docking Station', 'USB-C Hub', 'Laptop Backpack') THEN 'Computer Accessories'
            WHEN product_name IN ('Bluetooth Speaker', 'Noise-Cancelling Headphones', 'Wireless Headset') THEN 'Audio'
            WHEN product_name IN ('Portable SSD 1TB', 'USB Flash Drive 128GB') THEN 'Storage'
            WHEN product_name IN ('65W USB-C Charger') THEN 'Chargers'
            WHEN product_name IN ('USB-C Cable', 'HDMI 2.1 Cable 2m') THEN 'Cables'
            WHEN product_name IN ('Wi-Fi 6 USB Adapter') THEN 'Networking'
            WHEN product_name IN ('6-Outlet Surge Protector') THEN 'Power'
            ELSE 'Computer Accessories'
        END
        WHERE category IS NULL OR TRIM(category) = ''");
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
                'SELECT id, product_name, variant_name, quantity FROM products WHERE id = ? LIMIT 1 FOR UPDATE',
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
                    $product['product_name'] . (!empty($product['variant_name']) ? ' · ' . $product['variant_name'] : ''),
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
                'product_name' => $product['product_name'] . (!empty($product['variant_name']) ? ' · ' . $product['variant_name'] : ''),
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
                p.image_url, p.parent_product_id, p.variant_name,
                (SELECT COALESCE(AVG(r.rating), 0) FROM product_reviews r WHERE r.product_id = p.id) AS average_rating,
                (SELECT COUNT(*) FROM product_reviews r WHERE r.product_id = p.id) AS review_count
             FROM products p
             LEFT JOIN products parent ON parent.id = p.parent_product_id
             WHERE p.quantity > 0
                OR (p.parent_product_id IS NULL AND EXISTS (
                    SELECT 1 FROM products child
                    WHERE child.parent_product_id = p.id AND child.quantity > 0
                ))
             ORDER BY COALESCE(parent.product_name, p.product_name), p.variant_name'
        )->fetchAll();
    }

    public function inventoryCatalog()
    {
        return $this->db->raw(
            'SELECT p.*, p.parent_product_id, p.variant_name
             FROM products p
             ORDER BY COALESCE(p.parent_product_id, p.id), p.parent_product_id IS NOT NULL, p.variant_name, p.product_name'
        )->fetchAll();
    }

    public function hasVariants($parentId)
    {
        return (bool) $this->db->raw(
            'SELECT id FROM products WHERE parent_product_id = ? LIMIT 1',
            [(int) $parentId]
        )->fetch();
    }

    public function addVariant($parentId, array $variant)
    {
        $parent = $this->db->raw(
            'SELECT * FROM products WHERE id = ? AND parent_product_id IS NULL LIMIT 1',
            [(int) $parentId]
        )->fetch();
        if (!$parent) throw new InvalidArgumentException('Parent product not found.');
        $name = trim((string) ($variant['variant_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('Variant name is required and must be 120 characters or fewer.');
        }
        $price = $variant['price'] ?? $parent['price'];
        $quantity = filter_var($variant['quantity'] ?? null, FILTER_VALIDATE_INT);
        $sku = trim((string) ($variant['sku'] ?? ''));
        if (!is_numeric($price) || (float) $price <= 0 || (float) $price > 99999999.99) {
            throw new InvalidArgumentException('Variant price must be a positive amount within the supported range.');
        }
        if ($quantity === false || $quantity < 0 || $quantity > 4294967295) {
            throw new InvalidArgumentException('Variant stock must be a non-negative whole number.');
        }
        if (mb_strlen($sku) > 80) throw new InvalidArgumentException('Variant SKU must be 80 characters or fewer.');
        $duplicate = $this->db->raw(
            'SELECT id FROM products WHERE parent_product_id = ? AND LOWER(variant_name) = LOWER(?) LIMIT 1',
            [(int) $parentId, $name]
        )->fetch();
        if ($duplicate) throw new InvalidArgumentException('A variant with that name already exists.');
        if ($sku !== '' && $this->db->raw('SELECT id FROM products WHERE sku = ? LIMIT 1', [$sku])->fetch()) {
            throw new InvalidArgumentException('That SKU is already in use.');
        }
        $this->db->table('products')->insert([
            'product_name' => $parent['product_name'],
            'description' => $parent['description'],
            'price' => round((float) $price, 2),
            'quantity' => $quantity,
            'category' => $parent['category'],
            'sku' => $sku ?: null,
            'barcode' => null,
            'reorder_level' => $parent['reorder_level'],
            'supplier_id' => $parent['supplier_id'],
            'image_url' => $parent['image_url'],
            'estimated_unit_cost' => $parent['estimated_unit_cost'],
            'parent_product_id' => (int) $parentId,
            'variant_name' => $name
        ]);
        return (int) $this->db->last_id();
    }

    public function deleteVariant($parentId, $variantId)
    {
        $variant = $this->db->raw(
            'SELECT id, quantity FROM products WHERE id = ? AND parent_product_id = ? LIMIT 1',
            [(int) $variantId, (int) $parentId]
        )->fetch();
        if (!$variant) throw new InvalidArgumentException('Variant not found.');
        if ((int) $variant['quantity'] !== 0) {
            throw new InvalidArgumentException('Adjust variant stock to zero before removing it.');
        }
        $this->db->table('products')->where('id', (int) $variantId)->delete();
    }

    public function updateVariant($parentId, $variantId, array $variant)
    {
        $existing = $this->db->raw(
            'SELECT id FROM products WHERE id = ? AND parent_product_id = ? LIMIT 1',
            [(int) $variantId, (int) $parentId]
        )->fetch();
        if (!$existing) throw new InvalidArgumentException('Variant not found.');
        $name = trim((string) ($variant['variant_name'] ?? ''));
        $price = $variant['price'] ?? null;
        $sku = trim((string) ($variant['sku'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException('Variant name is required and must be 120 characters or fewer.');
        }
        if (!is_numeric($price) || (float) $price <= 0 || (float) $price > 99999999.99) {
            throw new InvalidArgumentException('Variant price must be a positive amount within the supported range.');
        }
        if (mb_strlen($sku) > 80) throw new InvalidArgumentException('Variant SKU must be 80 characters or fewer.');
        $duplicateName = $this->db->raw(
            'SELECT id FROM products WHERE parent_product_id = ? AND LOWER(variant_name) = LOWER(?) AND id <> ? LIMIT 1',
            [(int) $parentId, $name, (int) $variantId]
        )->fetch();
        if ($duplicateName) throw new InvalidArgumentException('A variant with that name already exists.');
        if ($sku !== '' && $this->db->raw('SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1', [$sku, (int) $variantId])->fetch()) {
            throw new InvalidArgumentException('That SKU is already in use.');
        }
        $this->db->table('products')->where('id', (int) $variantId)->update([
            'variant_name' => $name,
            'price' => round((float) $price, 2),
            'sku' => $sku ?: null
        ]);
    }

    public function syncVariantDetails($parentId, array $parent)
    {
        $this->db->raw(
            'UPDATE products SET product_name = ?, description = ?, category = ?, reorder_level = ?,
                supplier_id = ?, image_url = ?, estimated_unit_cost = ?
             WHERE parent_product_id = ?',
            [
                $parent['product_name'],
                $parent['description'],
                $parent['category'],
                $parent['reorder_level'],
                $parent['supplier_id'],
                $parent['image_url'],
                $parent['estimated_unit_cost'],
                (int) $parentId
            ]
        );
    }
}
