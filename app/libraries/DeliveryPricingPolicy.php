<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class DeliveryPricingPolicy
{
    public static function quote($subtotal, $fee, $freeDeliveryEnabled, $freeDeliveryMinimum)
    {
        $subtotal = round((float) $subtotal, 2);
        $fee = round((float) $fee, 2);
        $minimum = round((float) $freeDeliveryMinimum, 2);
        if ($subtotal < 0 || $fee < 0 || $minimum < 0) {
            throw new InvalidArgumentException('Delivery amounts cannot be negative.');
        }

        $free = (bool) $freeDeliveryEnabled && $subtotal >= $minimum;
        $discount = $free ? $fee : 0.0;
        $total = round($subtotal + $fee - $discount, 2);
        if ($total > 9999999999.99) {
            throw new InvalidArgumentException('The total exceeds the supported transaction amount.');
        }
        return [
            'subtotal' => $subtotal,
            'delivery_fee' => $fee,
            'delivery_discount' => $discount,
            'total' => $total,
            'free_delivery_applied' => $free
        ];
    }
}
