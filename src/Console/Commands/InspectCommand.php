<?php

declare(strict_types=1);

namespace Sentinel\Console\Commands;

use Sentinel\Store\FileSchemaStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InspectCommand extends Command
{
    protected static string $defaultName = 'inspect';

    protected function configure(): void
    {
        $this->setDescription('Show the full hardened schema details for an endpoint key.')
            ->addArgument('endpointKey', InputArgument::REQUIRED, 'The endpoint key to inspect')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Schema store directory', './sentinel/schemas/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = is_string($input->getArgument('endpointKey')) ? $input->getArgument('endpointKey') : '';
        $dir = is_string($input->getOption('output')) ? $input->getOption('output') : '';

        $store = new FileSchemaStore($dir);
        $schema = $store->get($key);

        if ($schema === null) {
            $io->error("No hardened schema found for endpoint key: {$key}");
            // Let's check if it's currently sampling
            if (count($store->getSamples($key)->all()) > 0) {
                $io->info("Endpoint is currently in sampling phase. (" . count($store->getSamples($key)->all()) . " samples collected)");
            }
            return Command::FAILURE;
        }

        $io->title("Schema Details: {$key}");
        $io->writeln("Version: <info>{$schema->version}</info>");
        $io->writeln("Samples Built From: <info>{$schema->sampleCount}</info>");
        $io->writeln("Hardened At: <info>{$schema->hardenedAt->format('Y-m-d H:i:s T')}</info>");
        $io->newLine();

        $io->section("JSON Schema representation:");
        $io->writeln((string) json_encode($schema->jsonSchema, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
