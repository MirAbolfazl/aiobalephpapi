<?php

namespace App\Services;

use Aiobale\Client\FileInput;
use Aiobale\Types\FileDetails;
use App\Models\BaleAccount;

class BaleFileUploadService
{
    public function __construct(private BaleClientFactory $clients)
    {
    }

    public function upload(BaleAccount $account, string $path, string $mimeType): FileDetails
    {
        return $this->clients->forAccount($account)
            ->uploadFile(FileInput::fromPath($path, $mimeType))
            ->await();
    }
}
