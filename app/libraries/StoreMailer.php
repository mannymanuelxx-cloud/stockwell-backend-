<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StoreMailer
{
    private const RESEND_API_URL = 'https://api.resend.com/emails';

    public function sendOrderUpdate(array $order, $event)
    {
        $name = trim((string) ($order['delivery_name'] ?: $order['name']));
        $orderId = (int) $order['id'];
        $subject = 'Stockwell order #' . $orderId . ' update';
        $status = $this->orderStatusLabel((string) ($order['status'] ?? ''));
        $tracking = trim(implode(' · ', array_filter([
            $order['courier'] ?? '',
            $order['tracking_number'] ?? '',
            !empty($order['estimated_delivery_at']) ? 'Estimated delivery: ' . $order['estimated_delivery_at'] : ''
        ])));
        $trackingUrl = trim((string) ($order['tracking_url'] ?? ''));
        $trackingUrlParts = $trackingUrl !== '' ? parse_url($trackingUrl) : [];
        $hasTrackingUrl = filter_var($trackingUrl, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) ($trackingUrlParts['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($trackingUrlParts['user'])
            && !isset($trackingUrlParts['pass']);
        $text = "Hi {$name},\n\nYour Stockwell order #{$orderId} has an update: {$event}.\nCurrent status: {$status}.\n"
            . (!empty($order['payment_status']) ? "Payment: " . $this->paymentStatusLabel((string) $order['payment_status']) . ".\n" : '')
            . (isset($order['total'], $order['amount_paid']) ? "Amount received: PHP " . number_format((float) $order['amount_paid'], 2) . ". Balance due: PHP " . number_format(max(0, (float) $order['total'] - (float) $order['amount_paid']), 2) . ".\n" : '')
            . ($tracking !== '' ? "Delivery: {$tracking}\n" : '')
            . ($hasTrackingUrl ? "Track your delivery: {$trackingUrl}\n" : '')
            . "Thank you for shopping with Stockwell.";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>Your Stockwell order <strong>#' . $orderId . '</strong> has an update: '
            . htmlspecialchars($event, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.</p>'
            . '<p>Current status: <strong>' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
            . (!empty($order['payment_status']) ? '<p>Payment: ' . htmlspecialchars($this->paymentStatusLabel((string) $order['payment_status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '')
            . (isset($order['total'], $order['amount_paid']) ? '<p>Amount received: PHP ' . number_format((float) $order['amount_paid'], 2) . ' · Balance due: PHP ' . number_format(max(0, (float) $order['total'] - (float) $order['amount_paid']), 2) . '</p>' : '')
            . ($tracking !== '' ? '<p>Delivery: ' . htmlspecialchars($tracking, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '')
            . ($hasTrackingUrl ? '<p><a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Track your delivery</a></p>' : '')
            . '<p>Thank you for shopping with Stockwell.</p>';
        $this->send($order['email'], $subject, $text, $html);
    }

    public function sendReturnUpdate(array $request, $event)
    {
        $name = trim((string) ($request['name'] ?? 'customer')) ?: 'customer';
        $returnId = (int) $request['id'];
        $orderId = (int) $request['order_id'];
        $status = $this->returnStatusLabel((string) ($request['status'] ?? ''));
        $refund = number_format((float) ($request['refund_amount'] ?? 0), 2);
        $subject = 'Stockwell return #' . $returnId . ' update';
        $refundLine = "Requested refund amount: PHP {$refund}.\n";
        $text = "Hi {$name},\n\nYour return request #{$returnId} for order #{$orderId} has an update: {$event}.\n"
            . "Current status: {$status}.\n{$refundLine}"
            . ($status === 'Refund marked issued' ? "The store has marked this refund as issued outside Stockwell.\n" : '')
            . "Sign in to Stockwell to review your return status.";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>Your return request <strong>#' . $returnId . '</strong> for order <strong>#' . $orderId
            . '</strong> has an update: ' . htmlspecialchars($event, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.</p>'
            . '<p>Current status: <strong>' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>'
            . '<p>Requested refund amount: PHP ' . $refund . '</p>'
            . ($status === 'Refund marked issued' ? '<p>The store has marked this refund as issued outside Stockwell.</p>' : '')
            . '<p>Sign in to Stockwell to review your return status.</p>';
        $this->send($request['email'], $subject, $text, $html);
    }

    private function orderStatusLabel($status)
    {
        return [
            'pending' => 'Awaiting store confirmation',
            'processing' => 'Being prepared',
            'ready_to_ship' => 'Ready to ship',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled'
        ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function paymentStatusLabel($status)
    {
        return [
            'unpaid' => 'Unpaid',
            'partial' => 'Previously recorded partial payment',
            'paid' => 'Paid',
            'refunded' => 'Refund recorded'
        ][$status] ?? ucfirst($status);
    }

    private function returnStatusLabel($status)
    {
        return [
            'requested' => 'Submitted for review',
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'received' => 'Returned item received; refund review in progress',
            'refunded' => 'Refund marked issued'
        ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function send($recipient, $subject, $text, $html = '')
    {
        $apiKey = trim((string) getenv('RESEND_API_KEY'));
        $fromEmail = trim((string) getenv('RESEND_FROM_EMAIL'));
        if ($apiKey === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('RESEND_API_KEY and a valid RESEND_FROM_EMAIL must be configured before email can be sent.');
        }

        if (preg_match('/[\r\n]/', $apiKey)) {
            throw new RuntimeException('RESEND_API_KEY contains invalid characters.');
        }

        $recipient = trim((string) $recipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid recipient email address is required.');
        }

        $fromName = trim((string) (getenv('RESEND_FROM_NAME') ?: 'Stockwell'));
        if ($fromName === '' || preg_match('/[\r\n]/', $fromName)) {
            throw new RuntimeException('RESEND_FROM_NAME must be a non-empty, single-line value.');
        }

        $payload = [
            'from' => $fromName . ' <' . $fromEmail . '>',
            'to' => [$recipient],
            'subject' => (string) $subject,
            'text' => (string) $text
        ];
        if ($html !== '') {
            $payload['html'] = (string) $html;
        }

        $this->sendViaResend($apiKey, $payload);
    }

    protected function sendViaResend(string $apiKey, array $payload): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $response = @file_get_contents(self::RESEND_API_URL, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Email provider request failed over HTTPS. Check outbound network access and provider settings.');
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorName = '';
            $errorBody = json_decode($response, true);
            if (is_array($errorBody) && isset($errorBody['name']) && is_string($errorBody['name'])
                && preg_match('/^[A-Za-z0-9_-]{1,60}$/', $errorBody['name'])) {
                $errorName = ' (' . $errorBody['name'] . ')';
            }
            throw new RuntimeException('Email provider returned HTTP ' . $statusCode . $errorName . '.');
        }

        $successBody = json_decode($response, true);
        if (!is_array($successBody) || !isset($successBody['id']) || !is_string($successBody['id']) || $successBody['id'] === '') {
            throw new RuntimeException('Email provider returned an invalid success response.');
        }
    }
}
