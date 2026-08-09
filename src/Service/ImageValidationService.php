<?php

declare(strict_types=1);

namespace App\Service;

class ImageValidationService
{
    public function __construct(
        private int $maxSizeMb = 5,
    ) {}

    public function validateBase64(string $data): array
    {
        if ($data === '') {
            return ['valid' => true];
        }

        if (str_starts_with($data, 'data:')) {
            $parts = explode(',', $data, 2);
            if (count($parts) !== 2) {
                return ['valid' => false, 'error' => 'invalid_base64'];
            }
            $data = $parts[1];
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ['valid' => false, 'error' => 'invalid_base64'];
        }

        $maxBytes = $this->maxSizeMb * 1024 * 1024;
        if (strlen($decoded) > $maxBytes) {
            return ['valid' => false, 'error' => 'image_too_large'];
        }

        return ['valid' => true];
    }
}
