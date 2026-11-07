<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StoreMailer
{
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
        $relayUrl = trim((string) getenv('GMAIL_RELAY_URL'));
        $relaySecret = trim((string) getenv('GMAIL_RELAY_SECRET'));
        $relayParts = $relayUrl !== '' ? parse_url($relayUrl) : false;
        if ($relayParts === false
            || strtolower((string) ($relayParts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($relayParts['host'] ?? '')) !== 'script.google.com'
            || isset($relayParts['user'])
            || isset($relayParts['pass'])
            || (isset($relayParts['port']) && (int) $relayParts['port'] !== 443)
            || !preg_match('/^[a-f0-9]{64}$/i', $relaySecret)) {
            throw new RuntimeException('GMAIL_RELAY_URL and GMAIL_RELAY_SECRET must be configured with a secure Google Apps Script deployment.');
        }

        $recipient = trim((string) $recipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid recipient email address is required.');
        }

        if (preg_match('/[\r\n]/', (string) $subject)) {
            throw new InvalidArgumentException('Email subject must be a single line.');
        }

        $this->sendViaGmailRelay($relayUrl, $relaySecret, [
            'secret' => $relaySecret,
            'to' => $recipient,
            'subject' => (string) $subject,
            'text' => (string) $text,
            'html' => (string) $html
        ]);
    }

    protected function sendViaGmailRelay(string $relayUrl, string $relaySecret, array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->requestGmailRelay($relayUrl, 'POST', $body);
        $statusCode = $response['status'];
        if ($statusCode >= 300 && $statusCode < 400 && !empty($response['location'])) {
            $redirect = parse_url($response['location']);
            if ($redirect === false
                || strtolower((string) ($redirect['scheme'] ?? '')) !== 'https'
                || strtolower((string) ($redirect['host'] ?? '')) !== 'script.googleusercontent.com'
                || isset($redirect['user'])
                || isset($redirect['pass'])
                || (isset($redirect['port']) && (int) $redirect['port'] !== 443)) {
                throw new RuntimeException('Google Apps Script returned an unexpected HTTPS redirect.');
            }
            $response = $this->requestGmailRelay($response['location'], 'GET');
            $statusCode = $response['status'];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Google Apps Script email relay returned HTTP ' . $statusCode . '.');
        }

        $result = json_decode($response['body'], true);
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Google Apps Script email relay rejected the message.');
        }
    }

    protected function requestGmailRelay(string $url, string $method, ?string $body = null): array
    {
        $headers = ['Accept: application/json'];
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1
        ];
        if ($method === 'POST') {
            $options['header'] .= "\r\nContent-Type: application/json";
            $options['content'] = $body;
        }

        $context = stream_context_create([
            'http' => $options,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        $statusCode = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
        }

        if (!is_string($responseBody)) {
            throw new RuntimeException('Could not reach the Google Apps Script email relay over HTTPS.');
        }

        return [
            'status' => $statusCode,
            'location' => $location,
            'body' => $responseBody
        ];
    }
}
