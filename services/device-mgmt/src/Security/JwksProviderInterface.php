<?php

declare(strict_types=1);

namespace App\Security;

use Jose\Component\Core\JWKSet;

interface JwksProviderInterface
{
    public function getKeySet(): JWKSet;
}
