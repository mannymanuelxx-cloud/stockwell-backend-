<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class PaymentPolicy
{
    private const METHODS = ['cash', 'card', 'gcash', 'bank_transfer', 'other'];

    public static function normalize($status, $method, $total)
    {
        if (!in_array($status, ['unpaid', 'paid'], true)) {
            throw new InvalidArgumentException('Payment must be recorded as paid in full or unpaid.');
        }
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Choose a valid payment method.');
        }
        if (!is_numeric($total) || (float) $total <= 0) {
            throw new InvalidArgumentException('The transaction total must be greater than zero.');
        }

        return [
            'payment_status' => $status,
            'payment_method' => $method,
            'amount_paid' => $status === 'paid' ? round((float) $total, 2) : 0
        ];
    }

    public static function assertOrderPaymentTiming($paymentStatus, $orderStatus, $allowPreDeliveryGcash = false)
    {
        if ($paymentStatus === 'paid'
            && !in_array($orderStatus, ['delivered', 'completed'], true)
            && !($allowPreDeliveryGcash && $orderStatus !== 'cancelled')) {
            throw new InvalidArgumentException('An order can only be marked paid after it has been delivered.');
        }
    }

    public static function cashOnDeliveryPayment($paymentStatus, $paymentMethod, $orderStatus, $total)
    {
        if ($paymentMethod !== 'cash' || $orderStatus !== 'delivered' || $paymentStatus === 'refunded') {
            return null;
        }
        if (!is_numeric($total) || (float) $total <= 0) {
            throw new InvalidArgumentException('The transaction total must be greater than zero.');
        }

        return [
            'payment_status' => 'paid',
            'amount_paid' => round((float) $total, 2)
        ];
    }
}
