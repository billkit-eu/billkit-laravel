<?php

declare(strict_types=1);

use BillKit\BillKitClient;

if (! function_exists('billkit')) {
    /**
     * Resolve the shared, container-managed BillKit API client.
     */
    function billkit(): BillKitClient
    {
        return app(BillKitClient::class);
    }
}
