<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/ProductReviewPolicy.php';
require_once __DIR__ . '/../libraries/BrowserSecurityPolicy.php';

class ShopController extends Controller
{
    public function before_action()
    {
        $this->api = $this->call->library('api');
        $this->ProductModel->ensureTable();
        $this->BusinessModel->ensureSchema();
    }

    public function products()
    {
        $this->respond(200, ['data' => $this->ProductModel->shopCatalog()]);
    }

    public function productReviews($id = null)
    {
        $productId = filter_var($id, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1 || !$this->ProductModel->find($productId)) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }
        $customerId = null;
        BrowserSecurityPolicy::startSession();
        if (!empty($_SESSION['user_id'])) {
            $role = $this->UsersModel->roleForSession(
                (int) $_SESSION['user_id'],
                $_SESSION['auth_password_hash'] ?? null
            );
            if ($role !== null && $this->UsersModel->hasPermission($role, 'orders.view_own', (int) $_SESSION['user_id'])) {
                $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
            }
        }
        $this->respond(200, ['data' => $this->BusinessModel->productReviews($productId, $customerId)]);
    }

    public function createProductReview($id = null)
    {
        if (!$this->requireCustomer()) return;
        $productId = filter_var($id, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1 || !$this->ProductModel->find($productId)) {
            $this->respond(404, ['error' => 'Product not found.']);
            return;
        }
        $payload = $this->api->body();
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        try {
            $reviewData = ProductReviewPolicy::normalize(
                $payload['rating'] ?? null,
                $payload['title'] ?? null,
                $payload['review'] ?? null
            );
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
            return;
        }
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        try {
            $saved = $this->BusinessModel->saveProductReview(
                $customerId,
                $productId,
                $reviewData['rating'],
                $reviewData['title'],
                $reviewData['review']
            );
            $this->respond(200, ['data' => $saved]);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
        }
    }

    public function deliveryZones()
    {
        $this->respond(200, ['data' => $this->BusinessModel->deliveryZones(true)]);
    }

    public function orders()
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->customerOrders($customerId)]);
    }

    public function notifications()
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->customerOrderNotifications($customerId)]);
    }

    public function markNotificationsRead()
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $this->BusinessModel->markCustomerOrderNotificationsRead($customerId);
        $this->respond(200, ['success' => true]);
    }

    public function order($id = null)
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        $order = $customerId ? $this->BusinessModel->customerOrderDetails($customerId, $id) : null;
        if (!$order) {
            $this->respond(404, ['error' => 'Order not found.']);
            return;
        }
        $this->respond(200, ['data' => $order]);
    }

    public function cancelOrder($id = null)
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        try {
            $this->BusinessModel->cancelCustomerOrder($customerId, $id);
            $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Customer', 'cancelled', 'order', $id);
            $response = ['success' => true];
            $warning = $this->sendOrderNotification((int) $id, 'order cancelled');
            if ($warning) $response['notification_warning'] = $warning;
            $this->respond(200, $response);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'Order not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
        }
    }

    public function profile()
    {
        if (!$this->requireCustomer()) return;
        $profile = $this->BusinessModel->customerProfile((int) $_SESSION['user_id']);
        if (!$profile) {
            $this->respond(404, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $profile['addresses'] = $this->BusinessModel->customerAddresses((int) $profile['customer_id']);
        $this->respond(200, ['data' => $profile]);
    }

    public function updateProfile()
    {
        if (!$this->requireCustomer()) return;
        $payload = $this->api->body();
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        foreach (['firstname', 'lastname', 'email', 'phone', 'address'] as $field) {
            if (isset($payload[$field]) && !is_string($payload[$field])) {
                $this->respond(422, ['error' => 'Profile fields must be text values.']);
                return;
            }
        }
        $profile = [
            'firstname' => trim((string) ($payload['firstname'] ?? '')),
            'lastname' => trim((string) ($payload['lastname'] ?? '')),
            'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
            'phone' => trim((string) ($payload['phone'] ?? '')),
            'address' => trim((string) ($payload['address'] ?? ''))
        ];
        if ($profile['firstname'] === '' || mb_strlen($profile['firstname']) > 100
            || $profile['lastname'] === '' || mb_strlen($profile['lastname']) > 100
            || mb_strlen($profile['firstname'] . ' ' . $profile['lastname']) > 160) {
            $this->respond(422, ['error' => 'Enter your first and last name (100 characters or fewer each and 160 combined).']);
            return;
        }
        if (strlen($profile['email']) > 150 || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['error' => 'Enter a valid email address (150 characters or fewer).']);
            return;
        }
        if ($profile['address'] === '' || mb_strlen($profile['address']) < 15 || mb_strlen($profile['phone']) > 40 || mb_strlen($profile['address']) > 500) {
            $this->respond(422, ['error' => 'A default delivery address is required with 15 to 500 characters; phone numbers may be up to 40 characters.']);
            return;
        }
        try {
            $userId = (int) $_SESSION['user_id'];
            $this->BusinessModel->updateCustomerProfile($userId, $profile);
            $_SESSION['firstname'] = $profile['firstname'];
            $_SESSION['lastname'] = $profile['lastname'];
            $_SESSION['address'] = $profile['address'];
            $this->respond(200, ['data' => $this->BusinessModel->customerProfile($userId)]);
        } catch (InvalidArgumentException $error) {
            $this->respond(409, ['error' => $error->getMessage()]);
        }
    }

    public function addAddress()
    {
        if (!$this->requireCustomer()) return;
        $payload = $this->api->body();
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        foreach (['label', 'recipient_name', 'phone', 'address'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                $this->respond(422, ['error' => 'Address fields must be text values.']);
                return;
            }
        }
        $address = [
            'label' => trim((string) ($payload['label'] ?? '')),
            'recipient_name' => trim((string) ($payload['recipient_name'] ?? '')),
            'phone' => trim((string) ($payload['phone'] ?? '')),
            'address' => trim((string) ($payload['address'] ?? '')),
            'is_default' => !empty($payload['is_default'])
        ];
        if ($address['label'] === '' || mb_strlen($address['label']) > 80
            || $address['recipient_name'] === '' || mb_strlen($address['recipient_name']) > 160
            || $address['phone'] === '' || mb_strlen($address['phone']) > 40
            || $address['address'] === '' || mb_strlen($address['address']) < 15 || mb_strlen($address['address']) > 500) {
            $this->respond(422, ['error' => 'Enter a label, recipient name, phone number, and an address from 15 to 500 characters.']);
            return;
        }
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $id = $this->BusinessModel->addCustomerAddress($customerId, $address);
        $this->respond(201, ['data' => ['id' => $id, 'addresses' => $this->BusinessModel->customerAddresses($customerId)]]);
    }

    public function setDefaultAddress($id = null)
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId || !$this->BusinessModel->setDefaultCustomerAddress($customerId, $id)) {
            $this->respond(404, ['error' => 'Saved address not found.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->customerAddresses($customerId)]);
    }

    public function deleteAddress($id = null)
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId || !$this->BusinessModel->deleteCustomerAddress($customerId, $id)) {
            $this->respond(404, ['error' => 'Saved address not found.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->customerAddresses($customerId)]);
    }

    public function returns()
    {
        if (!$this->requireCustomer()) return;
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->customerReturns($customerId)]);
    }

    public function createReturn()
    {
        if (!$this->requireCustomer()) return;
        $payload = $this->api->body();
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $orderId = filter_var($payload['order_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = $payload['reason'] ?? null;
        $details = $payload['details'] ?? '';
        $items = $payload['items'] ?? null;
        if (!$orderId || !is_string($reason) || !is_string($details) || !is_array($items)) {
            $this->respond(422, ['error' => 'Provide an order, reason, optional details, and return items.']);
            return;
        }
        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }
        try {
            $returnId = $this->BusinessModel->createCustomerReturn($customerId, $orderId, $reason, $details, $items);
            $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Customer', 'requested', 'return', $returnId);
            $response = ['data' => ['id' => $returnId]];
            $warning = $this->sendReturnNotification($returnId, 'return request received');
            if ($warning) $response['notification_warning'] = $warning;
            $this->respond(201, $response);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'Order not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
        }
    }

    public function checkout()
    {
        if (!$this->requireCustomer()) return;
        $payload = $this->api->body();
        if (!is_array($payload)) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        array_walk_recursive($payload, function (&$value) {
            if (is_string($value)) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $this->respond(422, ['error' => 'Add at least one product to your cart.']);
            return;
        }

        $customerId = $this->BusinessModel->customerIdForUser((int) $_SESSION['user_id']);
        if (!$customerId) {
            $this->respond(403, ['error' => 'Your customer profile could not be found.']);
            return;
        }

        try {
            $orderId = $this->BusinessModel->createShopOrder(
                $customerId,
                $payload['items'],
                $payload['address'] ?? null,
                $payload['phone'] ?? null,
                $payload['delivery_notes'] ?? '',
                $payload['recipient_name'] ?? null,
                $payload['delivery_zone_id'] ?? null
            );
            $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Customer', 'placed', 'order', $orderId);
            $response = ['data' => ['id' => $orderId]];
            $order = $this->BusinessModel->orderNotificationInfo($orderId);
            if ($order && filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                try {
                    require_once ROOT_DIR . 'app/libraries/StoreMailer.php';
                    (new StoreMailer())->sendOrderUpdate($order, 'order placed');
                } catch (Throwable $mailError) {
                    error_log('Order confirmation email could not be sent for order #' . $orderId . ': ' . $mailError->getMessage());
                    $response['notification_warning'] = 'Your order was placed, but the confirmation email could not be sent. Check SMTP configuration.';
                }
            }
            $this->respond(201, $response);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
        }
    }

    private function requireCustomer()
    {
        BrowserSecurityPolicy::startSession();
        if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
            $this->respond(401, ['error' => 'Please sign in to continue.']);
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
        if ($this->UsersModel->hasPermission($role, 'orders.view_own', (int) $_SESSION['user_id'])) {
            $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
            if ($error !== null) {
                $this->respond(403, ['error' => $error]);
                return false;
            }
            return true;
        }
        $this->respond(403, ['error' => 'A customer account is required.']);
        return false;
    }

    private function sendOrderNotification($orderId, $event)
    {
        $order = $this->BusinessModel->orderNotificationInfo($orderId);
        if (!$order || !filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) return null;
        try {
            require_once ROOT_DIR . 'app/libraries/StoreMailer.php';
            (new StoreMailer())->sendOrderUpdate($order, $event);
            return null;
        } catch (Throwable $error) {
            error_log('Order notification could not be sent for order #' . $orderId . ': ' . $error->getMessage());
            return 'Your order was updated, but the confirmation email could not be sent. Check SMTP configuration.';
        }
    }

    private function sendReturnNotification($returnId, $event)
    {
        $request = $this->BusinessModel->returnNotificationInfo($returnId);
        if (!$request || !filter_var($request['email'] ?? '', FILTER_VALIDATE_EMAIL)) return null;
        try {
            require_once ROOT_DIR . 'app/libraries/StoreMailer.php';
            (new StoreMailer())->sendReturnUpdate($request, $event);
            return null;
        } catch (Throwable $error) {
            error_log('Return notification could not be sent for request #' . $returnId . ': ' . $error->getMessage());
            return 'Your return request was saved, but the confirmation email could not be sent. Check SMTP configuration.';
        }
    }

    private function respond($status, array $payload)
    {
        $this->api->respond($payload, $status);
    }
}
