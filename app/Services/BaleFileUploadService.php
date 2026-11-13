<?php

namespace App\Services;

use Aiobale\Client\FileInput;
use Aiobale\Types\FileDetails;
use Aiobale\Types\Peer;
use App\Models\BaleAccount;

class BaleFileUploadService
{
    public function __construct(private BaleClientFactory $clients)
    {
    }

    public function upload(
        BaleAccount $account,
        string $path,
        string $mimeType,
        ?Peer $peer = null,
    ): FileDetails
    {
        return $this->clients->forAccount($account)
            ->uploadFile(FileInput::fromPath($path, $mimeType), peer: $peer)
            ->await();
    }
}
