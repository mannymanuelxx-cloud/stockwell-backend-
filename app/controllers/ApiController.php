<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/BrowserSecurityPolicy.php';
require_once __DIR__ . '/../libraries/StockAdjustmentPolicy.php';

class ApiController extends Controller
{
    public function before_action()
    {
        $this->api = $this->call->library('api');
        $this->UsersModel->ensureAuthSchema();
        $this->ProductModel->ensureTable();
        $this->BusinessModel->ensureSchema();
    }

    public function index()
    {
        if (!$this->requireAuthentication(['inventory.manage', 'sales.manage', 'orders.manage', 'purchases.manage'])) return;
        $this->respond(200, ['data' => $this->ProductModel->all()]);
    }

    public function stockAdjustments()
    {
        if (!$this->requireAuthentication()) return;
        $productId = null;
        if (isset($_GET['product_id'])) {
            $productId = $this->validId($_GET['product_id']);
            if ($productId === null) {
                $this->respond(400, ['error' => 'A valid product ID is required.']);
                return;
            }
        }
        $this->respond(200, ['data' => $this->ProductModel->listStockAdjustments(100, $productId)]);
    }

    public function adjustStock($id = null)
    {
        if (!$this->requireAuthentication()) return;
        $productId = $this->validId($id);
        if ($productId === null) {
            $this->respond(400, ['error' => 'A valid product ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $adjustment = StockAdjustmentPolicy::validate(
            $payload['quantity_change'] ?? null,
            $payload['reason'] ?? null
        );
        if (isset($adjustment['error'])) {
            $this->respond(422, ['error' => $adjustment['error']]);
            return;
        }
        try {
            $record = $this->ProductModel->adjustStock(
                $productId,
                $adjustment['quantity_change'],
                $adjustment['reason'],
                (int) $_SESSION['user_id'],
                $_SESSION['username'] ?? 'Staff'
            );
            $this->respond(201, ['data' => $record]);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
        }
    }

    public function show($id = null)
    {
        if (!$this->requireAuthentication(['inventory.manage', 'sales.manage', 'orders.manage', 'purchases.manage'])) return;
        $product_id = $this->validId($id);
        if ($product_id === null) {
            $this->respond(400, ['error' => 'A valid product ID is required.']);
            return;
        }

        $product = $this->ProductModel->find($product_id);
        if (!$product) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }

        $this->respond(200, ['data' => $product]);
    }

    public function store()
    {
        if (!$this->requireAuthentication()) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }

        $product = $this->validatedProduct($payload);
        if (isset($product['error'])) {
            $this->respond(422, ['error' => $product['error']]);
            return;
        }

        $product_id = $this->ProductModel->insert($product);
        $created = $this->ProductModel->find($product_id);
        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Staff', 'created', 'product', $product_id, $created['product_name'] ?? null);
        $this->respond(201, ['data' => $created]);
    }

    public function uploadImage($id = null)
    {
        if (!$this->requireAuthentication()) return;
        $productId = $this->validId($id);
        if ($productId === null) {
            $this->respond(400, ['error' => 'A valid product ID is required.']);
            return;
        }
        $product = $this->ProductModel->find($productId);
        if (!$product) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            $this->respond(400, ['error' => 'Choose an image to upload.']);
            return;
        }
        $upload = $_FILES['image'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->respond(422, ['error' => 'Image upload failed. Choose a JPG, PNG, or WebP image smaller than 5 MB.']);
            return;
        }
        if (!isset($upload['tmp_name'], $upload['size'])
            || !is_uploaded_file($upload['tmp_name'])
            || (int) $upload['size'] < 1 || (int) $upload['size'] > 5 * 1024 * 1024) {
            $this->respond(422, ['error' => 'Image must be a valid upload between 1 byte and 5 MB.']);
            return;
        }
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file($upload['tmp_name']);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $dimensions = @getimagesize($upload['tmp_name']);
        if (!in_array($mime, $allowedTypes, true) || !$dimensions
            || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 6000 || $dimensions[1] > 6000
            || $dimensions[0] * $dimensions[1] > 20000000) {
            $this->respond(422, ['error' => 'Image must be a valid JPG, PNG, or WebP under 20 megapixels.']);
            return;
        }
        $imageBytes = file_get_contents($upload['tmp_name']);
        if ($imageBytes === false) {
            $this->respond(422, ['error' => 'The uploaded image could not be read.']);
            return;
        }
        $source = @imagecreatefromstring($imageBytes);
        if (!$source) {
            $this->respond(422, ['error' => 'The uploaded file could not be decoded as an image.']);
            return;
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, 1600 / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $normalized = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($normalized, 255, 255, 255);
        imagefill($normalized, 0, 0, $white);
        imagecopyresampled($normalized, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $directory = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            imagedestroy($normalized);
            $this->respond(500, ['error' => 'Product image storage could not be created.']);
            return;
        }
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $saved = imagejpeg($normalized, $destination, 88);
        imagedestroy($normalized);
        if (!$saved) {
            $this->respond(500, ['error' => 'Product image could not be saved.']);
            return;
        }
        $scriptPath = str_replace('\\', '/', (string) parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
        $backendPath = rtrim(dirname(dirname($scriptPath)), '/');
        if ($backendPath === '' || $backendPath === '.') $backendPath = '/backend';
        $imageUrl = $backendPath . '/uploads/products/' . $filename;
        try {
            $this->ProductModel->update($productId, ['image_url' => $imageUrl]);
        } catch (Throwable $error) {
            @unlink($destination);
            throw $error;
        }
        $updated = $this->ProductModel->find($productId);
        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Staff', 'uploaded image', 'product', $productId, $product['product_name'] ?? null);
        $this->respond(200, ['data' => $updated]);
    }

    public function update($id = null)
    {
        if (!$this->requireAuthentication()) return;
        $product_id = $this->validId($id);
        if ($product_id === null) {
            $this->respond(400, ['error' => 'A valid product ID is required.']);
            return;
        }

        $existingProduct = $this->ProductModel->find($product_id);
        if (!$existingProduct) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }

        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }

        $product = $this->validatedProduct($payload, $existingProduct['image_url'] ?? null);
        if (isset($product['error'])) {
            $this->respond(422, ['error' => $product['error']]);
            return;
        }
        if ((int) $product['quantity'] !== (int) $existingProduct['quantity']) {
            $this->respond(422, ['error' => 'Use the stock adjustment action to change quantity so the reason is recorded.']);
            return;
        }
        unset($product['quantity']);

        $this->ProductModel->update($product_id, $product);
        $updatedProduct = $this->ProductModel->find($product_id);
        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Staff', 'updated', 'product', $product_id, $updatedProduct['product_name'] ?? null);
        $this->respond(200, ['data' => $updatedProduct]);
    }

    public function destroy($id = null)
    {
        if (!$this->requireAuthentication()) return;
        $product_id = $this->validId($id);
        if ($product_id === null) {
            $this->respond(400, ['error' => 'A valid product ID is required.']);
            return;
        }

        $product = $this->ProductModel->find($product_id);
        if (!$product) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }

        if ($this->BusinessModel->productHasHistory($product_id)) {
            $this->respond(409, ['error' => 'This product is linked to sales, orders, or purchases and cannot be deleted.']);
            return;
        }

        $this->ProductModel->delete($product_id);
        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Staff', 'deleted', 'product', $product_id, $product['product_name'] ?? null);
        $this->respond(204);
    }

    private function readPayload()
    {
        $payload = $this->api->body();
        array_walk_recursive($payload, function (&$value) {
            if (is_string($value)) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });
        return is_array($payload) ? $payload : null;
    }

    private function validatedProduct(array $payload, $existingImageUrl = null)
    {
        $name = $payload['product_name'] ?? null;
        $description = $payload['description'] ?? '';
        $price = $payload['price'] ?? null;
        $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
        $reorderLevel = filter_var($payload['reorder_level'] ?? 5, FILTER_VALIDATE_INT);
        $category = $payload['category'] ?? '';
        $sku = $payload['sku'] ?? '';
        $barcode = $payload['barcode'] ?? '';
        $imageUrl = $payload['image_url'] ?? '';
        $supplierId = filter_var($payload['supplier_id'] ?? null, FILTER_VALIDATE_INT);
        $estimatedUnitCost = $payload['estimated_unit_cost'] ?? null;

        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 255) {
            return ['error' => 'Product name is required and must be 255 characters or fewer.'];
        }
        if (!is_string($description)) {
            return ['error' => 'Description must be text.'];
        }
        if (strlen($description) > 65535) {
            return ['error' => 'Description must be 65,535 bytes or fewer.'];
        }
        if (!is_numeric($price) || (float) $price <= 0 || (float) $price > 99999999.99) {
            return ['error' => 'Price must be a positive amount within the supported range.'];
        }
        if ($estimatedUnitCost !== null && $estimatedUnitCost !== ''
            && (!is_numeric($estimatedUnitCost) || (float) $estimatedUnitCost <= 0 || (float) $estimatedUnitCost > 99999999.99)) {
            return ['error' => 'Estimated unit cost must be blank or a positive amount within the supported range.'];
        }
        if ($quantity === false || $quantity < 0 || $quantity > 4294967295) {
            return ['error' => 'Quantity must be a non-negative whole number within the supported range.'];
        }
        if (!is_string($category) || mb_strlen(trim($category)) > 120 || !is_string($sku) || mb_strlen(trim($sku)) > 80 || !is_string($barcode) || mb_strlen(trim($barcode)) > 80) {
            return ['error' => 'Category, SKU, and barcode must use their supported lengths.'];
        }
        $imageUrl = is_string($imageUrl) ? trim($imageUrl) : '';
        $localUpload = preg_match('#^/(?:[A-Za-z0-9._-]+/)*backend/uploads/products/[a-f0-9]{32}\.jpg$#D', $imageUrl)
            && strpos($imageUrl, '..') === false;
        $unchangedImage = is_string($existingImageUrl) && $imageUrl === $existingImageUrl;
        if (!is_string($payload['image_url'] ?? '') || mb_strlen($imageUrl) > 1000
            || ($imageUrl !== '' && !$localUpload && !$unchangedImage && (!filter_var($imageUrl, FILTER_VALIDATE_URL)
                || !in_array(strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true)))) {
            return ['error' => 'Product image must be a valid HTTP or HTTPS URL or an uploaded Stockwell image (1,000 characters or fewer).'];
        }
        if ($reorderLevel === false || $reorderLevel < 0) return ['error' => 'Reorder level must be a non-negative whole number.'];
        if ($supplierId !== false && $supplierId !== null && !$this->BusinessModel->partyExists('suppliers', $supplierId)) {
            return ['error' => 'Choose a valid supplier company.'];
        }

        return [
            'product_name' => trim($name),
            'description' => trim($description),
            'price' => (float) $price,
            'quantity' => $quantity,
            'category' => trim($category),
            'sku' => trim($sku) ?: null,
            'barcode' => trim($barcode) ?: null,
            'reorder_level' => $reorderLevel,
            'supplier_id' => $supplierId ?: null,
            'image_url' => $imageUrl ?: null,
            'estimated_unit_cost' => $estimatedUnitCost === null || $estimatedUnitCost === '' ? null : round((float) $estimatedUnitCost, 2)
        ];
    }

    private function validId($id)
    {
        $product_id = filter_var($id, FILTER_VALIDATE_INT);
        return $product_id !== false && $product_id > 0 ? $product_id : null;
    }

    private function requireAuthentication($permission = 'inventory.manage')
    {
        BrowserSecurityPolicy::startSession();

        if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
            $this->respond(401, ['error' => 'Please sign in to access inventory.']);
            return false;
        }

        $role = $this->UsersModel->roleForSession(
            (int) $_SESSION['user_id'],
            $_SESSION['auth_password_hash'] ?? null
        );
        if ($role === null) {
            $_SESSION = [];
            $this->respond(401, ['error' => 'Your session is no longer valid. Please sign in again.']);
            return false;
        }
        $_SESSION['role'] = $role;
        $permissions = is_array($permission) ? $permission : [$permission];
        foreach ($permissions as $requiredPermission) {
            if ($this->UsersModel->hasPermission($role, $requiredPermission, (int) $_SESSION['user_id'])) {
                $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
                if ($error !== null) {
                    $this->respond(403, ['error' => $error]);
                    return false;
                }
                return true;
            }
        }

        $this->respond(403, ['error' => 'Your account does not have permission to access this inventory data.']);
        return false;
    }

    private function respond($status, array $payload = [])
    {
        $this->api->respond($payload, $status === 204 ? 200 : $status);
    }
}
