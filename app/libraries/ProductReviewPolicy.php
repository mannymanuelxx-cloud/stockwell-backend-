<?php

class ProductReviewPolicy
{
    public static function eligibleOrderStatuses()
    {
        return ['delivered', 'completed'];
    }

    public static function isEligibleOrderStatus($status)
    {
        return in_array($status, self::eligibleOrderStatuses(), true);
    }

    public static function normalize($rating, $title, $review)
    {
        $rating = filter_var($rating, FILTER_VALIDATE_INT);
        if ($rating === false || $rating < 1 || $rating > 5 || !is_string($title) || !is_string($review)) {
            throw new InvalidArgumentException('Choose a rating from 1 to 5 and enter a review title and text.');
        }
        $title = trim($title);
        $review = trim($review);
        if ($title === '' || mb_strlen($title) > 160 || $review === '' || mb_strlen($review) > 2000) {
            throw new InvalidArgumentException('Review title is required (up to 160 characters) and review text must be 1 to 2,000 characters.');
        }
        return ['rating' => $rating, 'title' => $title, 'review' => $review];
    }
}
