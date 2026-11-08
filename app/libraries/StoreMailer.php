<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

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
        $apiKey = trim((string) getenv('BREVO_API_KEY'));
        if ($apiKey !== '') {
            $from = trim((string) getenv('BREVO_FROM_EMAIL'));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid, verified BREVO_FROM_EMAIL must be configured before email can be sent.');
            }
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid recipient email address is required.');
            }

            $payload = [
                'sender' => [
                    'email' => $from,
                    'name' => trim((string) (getenv('BREVO_FROM_NAME') ?: 'Stockwell'))
                ],
                'to' => [['email' => $recipient]],
                'subject' => $subject,
                'textContent' => $text
            ];
            if ($html !== '') {
                $payload['htmlContent'] = $html;
            }

            $replyTo = trim((string) getenv('BREVO_REPLY_TO'));
            if ($replyTo !== '') {
                if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('BREVO_REPLY_TO must be a valid email address when configured.');
                }
                $payload['replyTo'] = [
                    'email' => $replyTo,
                    'name' => trim((string) (getenv('BREVO_REPLY_TO_NAME') ?: 'Stockwell'))
                ];
            }

            $this->sendBrevoMessage($payload, $apiKey);
            return;
        }

        $host = trim((string) getenv('SMTP_HOST'));
        $from = trim((string) getenv('SMTP_FROM'));
        if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP_HOST and a valid SMTP_FROM must be configured before email can be sent.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = trim((string) getenv('SMTP_USERNAME')) !== '';
        if ($mail->SMTPAuth) {
            if (trim((string) getenv('SMTP_PASSWORD')) === '') {
                throw new RuntimeException('SMTP_PASSWORD must be configured when SMTP_USERNAME is set.');
            }
            $mail->Username = (string) getenv('SMTP_USERNAME');
            $mail->Password = (string) getenv('SMTP_PASSWORD');
        }
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption !== 'none') {
            throw new RuntimeException('SMTP_ENCRYPTION must be tls, ssl, or none.');
        }
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($from, trim((string) (getenv('SMTP_FROM_NAME') ?: 'Stockwell')));
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->Body = $html !== '' ? $html : $text;
        $mail->AltBody = $text;
        $mail->isHTML($html !== '');
        try {
            $mail->send();
        } catch (MailException $error) {
            throw new RuntimeException('SMTP delivery failed: ' . $mail->ErrorInfo, 0, $error);
        }
    }

    protected function sendBrevoMessage(array $payload, $apiKey)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Could not encode the Brevo email request.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'api-key: ' . $apiKey
                ]),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if (!is_string($response) || $status < 200 || $status >= 300) {
            $message = '';
            $responsePayload = is_string($response) ? json_decode($response, true) : null;
            if (is_array($responsePayload) && is_string($responsePayload['message'] ?? null)) {
                $message = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $responsePayload['message']);
                $message = substr(trim($message), 0, 250);
            }
            throw new RuntimeException(
                'Brevo transactional email request failed with HTTP ' . $status
                . ($message !== '' ? ': ' . $message : '.')
            );
        }
    }
}
