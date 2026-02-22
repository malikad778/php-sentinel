<?php

declare(strict_types=1);

namespace Sentinel\Symfony;

use Sentinel\Symfony\DependencyInjection\SentinelExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SentinelBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SentinelExtension();
    }
}
