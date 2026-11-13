<?php

final class ManualGcashCheckout
{
    public function isConfigured()
    {
        return $this->publicDetails() !== null;
    }

    public function publicDetails()
    {
        $accountName = trim((string) getenv('GCASH_ACCOUNT_NAME'));
        $accountNumber = trim((string) getenv('GCASH_ACCOUNT_NUMBER'));
        $qrImageUrl = trim((string) getenv('GCASH_QR_IMAGE_URL'));
        if ($accountName === '' || strlen($accountName) > 120
            || $accountNumber === '' || strlen($accountNumber) > 40
            || preg_match('/[\x00-\x1F\x7F]/', $accountName . $accountNumber)
            || !$this->isTrustedImageUrl($qrImageUrl)) {
            return null;
        }
        return [
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'qr_image_url' => $qrImageUrl
        ];
    }

    private function isTrustedImageUrl($url)
    {
        if ($url === '' || strlen($url) > 1000) return false;
        $parts = parse_url($url);
        $path = strtolower((string) ($parts['path'] ?? ''));
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && preg_match('/\.(?:png|jpe?g|webp)$/', $path);
    }
}
