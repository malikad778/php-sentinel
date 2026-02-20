<?php

declare(strict_types=1);

namespace Sentinel\Symfony\DependencyInjection;

use Sentinel\Sentinel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

class SentinelExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = new Definition(Sentinel::class);
        $definition->setFactory([Sentinel::class, 'create']);
        
        $definition->addMethodCall('withSampleThreshold', [$config['sample_threshold']]);
        $definition->addMethodCall('withReharden', [$config['reharden']]);
        
        // Severity mapping
        $severityClass = \Sentinel\Drift\Severity::class;
        $definition->addMethodCall('withDriftSeverity', [
            is_callable([$severityClass, 'from']) ? $severityClass::from($config['drift_severity']) : constant($severityClass . '::' . $config['drift_severity']) // Fallback for some PHP versions handling enums in DI compilation
        ]);

        $container->setDefinition(Sentinel::class, $definition);
        $container->setAlias('sentinel', Sentinel::class);
        
        // Note: Full driver injection (Redis/PDO) would happen in a compiler pass
        // to retrieve the active driver services dynamically. We can stub it here
        // or let the builder fall back to FileSchemaStore as default.
    }
}
