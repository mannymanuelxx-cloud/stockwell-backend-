<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class OrderReturnPolicy
{
    private const TRANSITIONS = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['received', 'rejected'],
        'received' => ['refunded'],
        'rejected' => [],
        'refunded' => []
    ];

    public static function isEligibleOrderStatus($status)
    {
        return in_array($status, ['delivered', 'completed'], true);
    }

    public static function remainingQuantity($orderedQuantity, $alreadyRequestedQuantity)
    {
        return max(0, (int) $orderedQuantity - (int) $alreadyRequestedQuantity);
    }

    public static function isWithinReturnWindow($deliveredAt, $now = null, $days = 14)
    {
        $deliveredTimestamp = strtotime((string) $deliveredAt);
        $currentTimestamp = $now === null ? time() : (int) $now;
        return $deliveredTimestamp !== false && $deliveredTimestamp >= $currentTimestamp - ((int) $days * 86400);
    }

    public static function canTransition($currentStatus, $newStatus)
    {
        return in_array($newStatus, self::TRANSITIONS[$currentStatus] ?? [], true);
    }

    public static function refundAmount($requestedAmount, $amountPaid)
    {
        return round(max(0, min((float) $requestedAmount, (float) $amountPaid)), 2);
    }

    public static function paymentStatusAfterRefund($amountPaid, $refundAmount, $currentStatus)
    {
        if ($refundAmount <= 0) return $currentStatus;
        return $amountPaid - $refundAmount <= 0.005 ? 'refunded' : 'partial';
    }
}
