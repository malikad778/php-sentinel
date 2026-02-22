<?php

declare(strict_types=1);

namespace Sentinel\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sentinel');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('store')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('driver')
                            ->values(['file', 'redis', 'pdo', 'array'])
                            ->defaultValue('file')
                        ->end()
                        ->scalarNode('path')->defaultValue('%kernel.project_dir%/var/sentinel')->end()
                    ->end()
                ->end()
                ->integerNode('sample_threshold')->defaultValue(20)->end()
                ->enumNode('drift_severity')
                    ->values(['BREAKING', 'ADDITIVE', 'ADVISORY'])
                    ->defaultValue('BREAKING')
                ->end()
                ->booleanNode('reharden')->defaultTrue()->end()
            ->end();

        return $treeBuilder;
    }
}
