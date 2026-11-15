<?php
define('PREVENT_DIRECT_ACCESS', true);

class Model
{
    public $db;
}

class ShopCatalogStatement
{
    public function fetchAll()
    {
        return [];
    }
}

class ShopCatalogDatabase
{
    public $query;

    public function raw($query)
    {
        $this->query = $query;
        return new ShopCatalogStatement();
    }
}

require_once __DIR__ . '/../app/models/ProductModel.php';

$model = new ProductModel();
$model->db = new ShopCatalogDatabase();
$catalog = $model->shopCatalog();
$select = strstr($model->db->query, ' FROM products p', true);

$failures = 0;
$checks = 0;
$assert = function ($condition, $message) use (&$failures) {
    global $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
        return;
    }
    echo "PASS: {$message}\n";
};

foreach (['reorder_level', 'supplier_id', 'units_sold'] as $internalField) {
    $assert(
        !preg_match('/\b' . preg_quote($internalField, '/') . '\b/i', $select),
        "shop catalog does not select {$internalField}"
    );
}

foreach (['p.id', 'p.product_name', 'p.price', 'p.quantity', 'p.image_url', 'average_rating', 'review_count'] as $storefrontField) {
    $assert(
        strpos($select, $storefrontField) !== false,
        "shop catalog retains storefront field {$storefrontField}"
    );
}

$assert($catalog === [], 'shop catalog returns the query result');

if ($failures > 0) exit(1);
echo "{$checks} tests passed.\n";
