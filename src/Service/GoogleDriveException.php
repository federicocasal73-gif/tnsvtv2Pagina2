<?php

declare(strict_types=1);

namespace App\Service;

class GoogleDriveException extends \RuntimeException
{
    public const INVALID_API_KEY = 'invalid_api_key';
    public const FOLDER_NOT_FOUND = 'folder_not_found';
    public const FILE_NOT_FOUND = 'file_not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const UPSTREAM_ERROR = 'upstream_error';

    public function __construct(
        string $message,
        private readonly string $strategy,
        private readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStrategy(): string { return $this->strategy; }
    public function getStatusCode(): int { return $this->statusCode; }
}