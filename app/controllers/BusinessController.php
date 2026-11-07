<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/../libraries/BrowserSecurityPolicy.php';

class BusinessController extends Controller
{
    public function before_action()
    {
        $this->api = $this->call->library('api');
        $this->UsersModel->ensureAuthSchema();
        $this->BusinessModel->ensureSchema();
    }

    public function customers() { $this->listContacts('customers'); }
    public function suppliers() { $this->listContacts('suppliers'); }
    public function createCustomer() { $this->saveContact('customers'); }
    public function createSupplier() { $this->saveContact('suppliers'); }
    public function updateCustomer($id = null) { $this->saveContact('customers', $id); }
    public function updateSupplier($id = null) { $this->saveContact('suppliers', $id); }
    public function deleteCustomer($id = null) { $this->removeContact('customers', $id); }
    public function deleteSupplier($id = null) { $this->removeContact('suppliers', $id); }
    public function sales() { $this->listDocuments('sales'); }
    public function orders() { $this->listDocuments('orders'); }
    public function purchases() { $this->listDocuments('purchases'); }
    public function createSale() { $this->createDocument('sales'); }
    public function createOrder() { $this->createDocument('orders'); }
    public function createPurchase() { $this->createDocument('purchases'); }
    public function sale($id = null) { $this->showDocument('sales', $id); }
    public function order($id = null) { $this->showDocument('orders', $id); }
    public function purchase($id = null) { $this->showDocument('purchases', $id); }
    public function updateSale($id = null) { $this->saveDocument('sales', $id); }
    public function updateOrder($id = null) { $this->saveDocument('orders', $id); }
    public function updatePurchase($id = null) { $this->saveDocument('purchases', $id); }
    public function completeOrder($id = null) { $this->updateStatus('orders', $id); }
    public function receivePurchase($id = null) { $this->updateStatus('purchases', $id); }
    public function deleteSale($id = null) { $this->deleteDocument('sales', $id); }
    public function deleteOrder($id = null) { $this->deleteDocument('orders', $id); }
    public function deletePurchase($id = null) { $this->deleteDocument('purchases', $id); }

    public function activity()
    {
        if (!$this->requireAuthentication('activity.view')) return;
        $this->respond(200, ['data' => $this->BusinessModel->listActivity()]);
    }

    public function adminNotifications()
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        $this->respond(200, ['data' => $this->BusinessModel->adminNotificationData()]);
    }

    public function returns()
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        $this->respond(200, ['data' => $this->BusinessModel->listReturnRequests()]);
    }

    public function deliveryZones()
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        $this->respond(200, ['data' => $this->BusinessModel->deliveryZones()]);
    }

    public function createDeliveryZone()
    {
        $this->saveDeliveryZone();
    }

    public function updateDeliveryZone($id = null)
    {
        $this->saveDeliveryZone($id);
    }

    public function deleteDeliveryZone($id = null)
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid delivery area ID is required.']);
            return;
        }
        try {
            if (!$this->BusinessModel->deleteDeliveryZone((int) $id)) {
                $this->respond(404, ['error' => 'Delivery area not found.']);
                return;
            }
            $this->logActivity('deleted', 'delivery area', (int) $id);
            $this->respond(200, ['data' => $this->BusinessModel->deliveryZones()]);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
        }
    }

    public function updateReturn($id = null)
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid return request ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        $status = $payload['status'] ?? null;
        if (!is_string($status)) {
            $this->respond(422, ['error' => 'Choose a return request status.']);
            return;
        }
        try {
            $return = $this->BusinessModel->updateReturnStatus((int) $id, $status);
            $this->logActivity('changed return status', 'return', (int) $id, $status);
            $response = ['data' => $this->BusinessModel->returnNotificationInfo((int) $id) ?: $return];
            $warning = $this->sendReturnNotification((int) $id, 'return request status updated');
            if ($warning) $response['notification_warning'] = $warning;
            $this->respond(200, $response);
        } catch (InvalidArgumentException $error) {
            $code = $error->getMessage() === 'Return request not found.' ? 404 : 422;
            $this->respond($code, ['error' => $error->getMessage()]);
        }
    }

    public function updateOrderTracking($id = null)
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid order ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $tracking = [];
        foreach (['courier' => 120, 'tracking_number' => 160, 'tracking_url' => 1000] as $field => $limit) {
            $value = $payload[$field] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > $limit) {
                $this->respond(422, ['error' => 'Courier, tracking number, and tracking link must be text within their supported lengths.']);
                return;
            }
            $tracking[$field] = trim($value);
        }
        $trackingUrlParts = $tracking['tracking_url'] !== '' ? parse_url($tracking['tracking_url']) : [];
        if ($tracking['tracking_url'] !== ''
            && (!filter_var($tracking['tracking_url'], FILTER_VALIDATE_URL)
                || !in_array(strtolower((string) ($trackingUrlParts['scheme'] ?? '')), ['http', 'https'], true)
                || empty($trackingUrlParts['host'])
                || isset($trackingUrlParts['user'])
                || isset($trackingUrlParts['pass']))) {
            $this->respond(422, ['error' => 'Tracking link must be a valid HTTP or HTTPS URL.']);
            return;
        }
        $date = $payload['estimated_delivery_at'] ?? '';
        if (!is_string($date) || ($date !== '' && !$this->validDate($date))) {
            $this->respond(422, ['error' => 'Enter a valid estimated delivery date.']);
            return;
        }
        $tracking['estimated_delivery_at'] = $date;
        try {
            $this->BusinessModel->updateOrderTracking((int) $id, $tracking);
            $this->logActivity('updated delivery tracking', 'order', (int) $id, $tracking['courier'] . ' ' . $tracking['tracking_number']);
            $warning = $this->sendOrderNotification((int) $id, 'tracking updated');
            $response = ['data' => $this->BusinessModel->document('customer_orders', 'customer_order_items', 'order_id', (int) $id)];
            if ($warning) $response['notification_warning'] = $warning;
            $this->respond(200, $response);
        } catch (InvalidArgumentException $error) {
            $code = $error->getMessage() === 'Record not found.' ? 404 : 422;
            $this->respond($code, ['error' => $error->getMessage()]);
        }
    }

    public function backup()
    {
        if (!$this->requireAuthentication('backup.manage')) return;
        $this->ProductModel->ensureTable();
        $this->BusinessModel->logActivity((int) $_SESSION['user_id'], $_SESSION['username'] ?? 'Admin', 'downloaded backup', 'backup');
        $filename = 'stockwell-backup-' . date('Ymd-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo $this->BusinessModel->sqlBackup();
        exit;
    }

    public function reports()
    {
        if (!$this->requireAuthentication('reports.view')) return;
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-d');
        if (!$this->validDate($start) || !$this->validDate($end) || $start > $end) {
            $this->respond(422, ['error' => 'Choose a valid date range with the start date on or before the end date.']);
            return;
        }
        $this->respond(200, ['data' => $this->BusinessModel->reportData($start, $end)]);
    }

    private function listContacts($table)
    {
        if (!$this->requireAuthentication($table . '.manage')) return;
        $this->respond(200, ['data' => $this->BusinessModel->listContacts($table)]);
    }

    private function saveContact($table, $id = null)
    {
        if (!$this->requireAuthentication($table . '.manage')) return;
        if ($id !== null && !$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }
        if ($id !== null && !$this->BusinessModel->contact($table, (int) $id)) {
            $this->respond(404, ['error' => 'Record not found.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $name = $payload['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 160) {
            $this->respond(422, ['error' => 'Name is required and must be 160 characters or fewer.']);
            return;
        }
        $data = ['name' => trim($name)];
        $fields = $table === 'customers'
            ? ['email' => 190, 'phone' => 40, 'address' => 500, 'notes' => 65535]
            : ['email' => 190, 'address' => 500, 'notes' => 65535];
        foreach ($fields as $field => $max) {
            $value = $payload[$field] ?? '';
            if (!is_string($value) || strlen($value) > $max) {
                $this->respond(422, ['error' => ucfirst($field) . ' is too long or invalid.']);
                return;
            }
            $data[$field] = trim($value) === '' ? null : trim($value);
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->respond(422, ['error' => 'Enter a valid email address.']);
            return;
        }
        $recordId = $this->BusinessModel->saveContact($table, $data, $id ? (int) $id : null);
        $this->logActivity($id ? 'updated' : 'created', rtrim($table, 's'), $recordId, $data['name']);
        $this->respond($id ? 200 : 201, ['data' => $this->BusinessModel->contact($table, $recordId)]);
    }

    private function removeContact($table, $id)
    {
        if (!$this->requireAuthentication($table . '.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }
        if (!$this->BusinessModel->contact($table, (int) $id)) {
            $this->respond(404, ['error' => 'Record not found.']);
            return;
        }
        try {
            $this->BusinessModel->deleteContact($table, (int) $id);
        } catch (InvalidArgumentException $error) {
            $this->respond(409, ['error' => $error->getMessage()]);
            return;
        }
        $this->logActivity('deleted', rtrim($table, 's'), (int) $id);
        $this->respond(204);
    }

    private function listDocuments($kind)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        $config = $this->documentConfig($kind);
        $response = [
            'data' => $this->BusinessModel->listDocuments($config['table'], $config['items_table'], $config['foreign_key']),
            'products' => $this->BusinessModel->productOptions(),
            'parties' => $kind === 'purchases'
                ? $this->BusinessModel->listContacts('suppliers')
                : $this->BusinessModel->listContacts('customers')
        ];
        if ($kind === 'sales') {
            $response['online_sales'] = $this->BusinessModel->completedOnlineSales();
        }
        $this->respond(200, $response);
    }

    public function updateOrderPayment($id = null)
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid order ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $payment = $this->orderPaymentPayload($payload);
        if ($payment === false) return;
        try {
            $this->BusinessModel->updateOrderPayment((int) $id, $payment);
            $this->logActivity('updated payment', 'order', (int) $id, $payment['payment_status'] . ' via ' . $payment['payment_method']);
            $response = ['data' => $this->BusinessModel->document('customer_orders', 'customer_order_items', 'order_id', (int) $id)];
            $warning = $this->sendOrderNotification((int) $id, 'payment updated');
            if ($warning) $response['notification_warning'] = $warning;
            $this->respond(200, $response);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'Record not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
        }
    }

    private function showDocument($kind, $id)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }
        $config = $this->documentConfig($kind);
        $record = $this->BusinessModel->document($config['table'], $config['items_table'], $config['foreign_key'], (int) $id);
        if (!$record) {
            $this->respond(404, ['error' => 'Record not found.']);
            return;
        }
        $this->respond(200, ['data' => $record]);
    }

    private function createDocument($kind)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $config = $this->documentConfig($kind);
        $partyId = filter_var($payload[$config['party_key']] ?? null, FILTER_VALIDATE_INT);
        if (!$partyId) {
            $this->respond(422, ['error' => 'Choose a valid ' . $config['party_label'] . '.']);
            return;
        }
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $this->respond(422, ['error' => 'Add at least one valid product item.']);
            return;
        }
        $payment = $this->salePaymentPayload($kind, $payload);
        if ($payment === false) return;
        try {
            $id = $this->BusinessModel->createDocument($kind, $partyId, $payload['items'], $payment);
            $record = $this->BusinessModel->document($config['table'], $config['items_table'], $config['foreign_key'], $id);
            $this->logActivity('created', rtrim($kind, 's'), $id, 'Total ' . number_format((float) $record['total'], 2));
            $response = ['data' => $record];
            if ($kind === 'orders') {
                $warning = $this->sendOrderNotification((int) $id, 'order created');
                if ($warning) $response['notification_warning'] = $warning;
            }
            $this->respond(201, $response);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['error' => $error->getMessage()]);
        }
    }

    private function saveDocument($kind, $id)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        $config = $this->documentConfig($kind);
        $partyId = filter_var($payload[$config['party_key']] ?? null, FILTER_VALIDATE_INT);
        if (!$partyId) {
            $this->respond(422, ['error' => 'Choose a valid ' . $config['party_label'] . '.']);
            return;
        }
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $this->respond(422, ['error' => 'Add at least one valid product item.']);
            return;
        }
        $payment = $this->salePaymentPayload($kind, $payload);
        if ($payment === false) return;
        try {
            $this->BusinessModel->updateDocument($kind, (int) $id, $partyId, $payload['items'], $payment);
            $record = $this->BusinessModel->document(
                $config['table'],
                $config['items_table'],
                $config['foreign_key'],
                (int) $id
            );
            $this->logActivity('updated', rtrim($kind, 's'), (int) $id);
            $this->respond(200, ['data' => $record]);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'Record not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
        }
    }

    private function updateStatus($kind, $id)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }

        $payload = $this->readPayload();
        if ($payload === null || !isset($payload['status']) || !is_string($payload['status'])) {
            $this->respond(400, ['error' => 'A valid status is required.']);
            return;
        }
        try {
            $this->BusinessModel->updateDocumentStatus($kind, (int) $id, $payload['status']);
            $config = $this->documentConfig($kind);
            $record = $this->BusinessModel->document($config['table'], $config['items_table'], $config['foreign_key'], (int) $id);
            $this->logActivity('changed status', rtrim($kind, 's'), (int) $id, $payload['status']);
            $response = ['data' => $record];
            if ($kind === 'orders') {
                $warning = $this->sendOrderNotification((int) $id, 'status updated');
                if ($warning) $response['notification_warning'] = $warning;
            }
            $this->respond(200, $response);
        } catch (InvalidArgumentException $error) {
            $status = $error->getMessage() === 'Record not found.' ? 404 : 422;
            $this->respond($status, ['error' => $error->getMessage()]);
        }
    }

    private function saveDeliveryZone($id = null)
    {
        if (!$this->requireAuthentication('orders.manage')) return;
        if ($id !== null && !$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid delivery area ID is required.']);
            return;
        }
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->respond(400, ['error' => 'Request body must be valid JSON.']);
            return;
        }
        if (!isset($payload['area_name']) || !is_string($payload['area_name'])
            || !isset($payload['fee']) || !is_numeric($payload['fee'])
            || (isset($payload['free_delivery_minimum']) && !is_numeric($payload['free_delivery_minimum']))) {
            $this->respond(422, ['error' => 'Enter a delivery area, fee, and valid free-delivery minimum.']);
            return;
        }
        try {
            $zoneId = $this->BusinessModel->saveDeliveryZone($payload, $id === null ? null : (int) $id);
            $this->logActivity($id === null ? 'created' : 'updated', 'delivery area', $zoneId, trim($payload['area_name']));
            $this->respond($id === null ? 201 : 200, ['data' => $this->BusinessModel->deliveryZones()]);
        } catch (InvalidArgumentException $error) {
            $this->respond($error->getMessage() === 'Delivery area not found.' ? 404 : 422, ['error' => $error->getMessage()]);
        }
    }

    private function deleteDocument($kind, $id)
    {
        if (!$this->requireAuthentication($kind . '.manage')) return;
        if (!$this->validId($id)) {
            $this->respond(400, ['error' => 'A valid record ID is required.']);
            return;
        }
        try {
            if (!$this->BusinessModel->deleteDocument($kind, (int) $id)) {
                $this->respond(404, ['error' => 'Record not found.']);
                return;
            }
            $this->logActivity('deleted', rtrim($kind, 's'), (int) $id);
            $this->respond(204);
        } catch (InvalidArgumentException $error) {
            $this->respond(409, ['error' => $error->getMessage()]);
        }
    }

    private function documentConfig($kind)
    {
        $map = [
            'sales' => ['table' => 'sales', 'items_table' => 'sale_items', 'foreign_key' => 'sale_id', 'party_key' => 'customer_id', 'party_label' => 'customer'],
            'orders' => ['table' => 'customer_orders', 'items_table' => 'customer_order_items', 'foreign_key' => 'order_id', 'party_key' => 'customer_id', 'party_label' => 'customer'],
            'purchases' => ['table' => 'purchases', 'items_table' => 'purchase_items', 'foreign_key' => 'purchase_id', 'party_key' => 'supplier_id', 'party_label' => 'supplier']
        ];
        return $map[$kind];
    }

    private function salePaymentPayload($kind, array $payload)
    {
        if ($kind === 'orders') return $this->orderPaymentPayload($payload);
        if ($kind !== 'sales') return [];
        $status = $payload['payment_status'] ?? 'paid';
        $method = $payload['payment_method'] ?? 'cash';
        $amountPaid = $payload['amount_paid'] ?? null;
        if (!is_string($status) || !is_string($method)
            || ($amountPaid !== null && $amountPaid !== '' && !is_numeric($amountPaid))) {
            $this->respond(422, ['error' => 'Enter valid payment details for this sale.']);
            return false;
        }
        return [
            'payment_status' => $status,
            'payment_method' => $method,
            'amount_paid' => $amountPaid === '' ? null : $amountPaid
        ];
    }

    private function orderPaymentPayload(array $payload)
    {
        $status = $payload['payment_status'] ?? 'unpaid';
        $method = $payload['payment_method'] ?? 'cash';
        $amountPaid = $payload['amount_paid'] ?? null;
        if (!is_string($status) || !is_string($method)
            || ($amountPaid !== null && $amountPaid !== '' && !is_numeric($amountPaid))) {
            $this->respond(422, ['error' => 'Enter valid payment details for this order.']);
            return false;
        }
        return [
            'payment_status' => $status,
            'payment_method' => $method,
            'amount_paid' => $amountPaid === '' ? null : $amountPaid
        ];
    }

    private function logActivity($action, $entityType, $entityId, $details = null)
    {
        $this->BusinessModel->logActivity(
            (int) ($_SESSION['user_id'] ?? 0),
            $_SESSION['username'] ?? 'Admin',
            $action,
            $entityType,
            $entityId,
            $details
        );
    }

    private function sendOrderNotification($orderId, $event)
    {
        $order = $this->BusinessModel->orderNotificationInfo($orderId);
        if (!$order) return 'The order was saved, but its customer email could not be found.';
        if (!filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return 'The order was saved, but the customer has no valid email address on file.';
        }
        try {
            require_once ROOT_DIR . 'app/libraries/StoreMailer.php';
            (new StoreMailer())->sendOrderUpdate($order, $event);
            return null;
        } catch (Throwable $error) {
            error_log('Order notification could not be sent for order #' . $orderId . ': ' . $error->getMessage());
            return 'The record was saved, but the customer email could not be sent. Check email provider settings.';
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
            return 'The return status was saved, but the customer email could not be sent. Check email provider settings.';
        }
    }

    private function requireAuthentication($permission)
    {
        BrowserSecurityPolicy::startSession();
        if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) {
            $this->respond(401, ['error' => 'Please sign in to access this module.']);
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
        if ($this->UsersModel->hasPermission($role, $permission, (int) $_SESSION['user_id'])) {
            $error = BrowserSecurityPolicy::validateStateChangingRequest(true);
            if ($error !== null) {
                $this->respond(403, ['error' => $error]);
                return false;
            }
            return true;
        }
        $message = $permission === 'users.manage'
            ? 'Administrator access is required to manage users.'
            : 'Your account does not have permission to access this module.';
        $this->respond(403, ['error' => $message]);
        return false;
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

    private function validId($id)
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);
        return $value !== false && $value > 0;
    }

    private function validDate($value)
    {
        if (!is_string($value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    private function respond($status, array $payload = [])
    {
        $this->api->respond($payload, $status === 204 ? 200 : $status);
    }
}
