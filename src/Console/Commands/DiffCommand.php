<?php

declare(strict_types=1);

namespace Sentinel\Console\Commands;

use Sentinel\Drift\DriftDetector;
use Sentinel\Schema\StoredSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DiffCommand extends Command
{
    protected static string $defaultName = 'diff';

    protected function configure(): void
    {
        $this->setDescription('Compare two fully realized schema output files.')
            ->addArgument('baseline', InputArgument::REQUIRED, 'Path to old schema')
            ->addArgument('current', InputArgument::REQUIRED, 'Path to new inferred schema');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $basePath = is_string($input->getArgument('baseline')) ? $input->getArgument('baseline') : '';
        $currPath = is_string($input->getArgument('current')) ? $input->getArgument('current') : '';

        if (!file_exists($basePath) || !file_exists($currPath)) {
            $io->error("One or both schema files do not exist.");
            return Command::FAILURE;
        }

        $baseData = json_decode((string) file_get_contents($basePath), true);
        $currData = json_decode((string) file_get_contents($currPath), true);

        if (!is_array($baseData) || !is_array($currData)) {
            $io->error("Invalid JSON format in schema files.");
            return Command::FAILURE;
        }

        $baseSchema = StoredSchema::fromArray($baseData);
        // Note: drift diffing usually expects pure json structure for the current inference,
        // but if we are comparing two pre-hardened schema outputs produced by sentinel:
        $freshInference = isset($currData['jsonSchema']) ? $currData['jsonSchema'] : $currData;

        $detector = new DriftDetector();
        // Just mocking the endpoint for CLI diff since both structures are provided
        $drift = $detector->detect('CLI_DIFF', $baseSchema, $freshInference);

        if ($drift === null) {
            $io->success("No drift detected. Schemas match.");
            return Command::SUCCESS;
        }

        $io->writeln("<info>Drift Detected:</info> severity " . $drift->severity->value);
        
        $hasBreaking = false;
        foreach ($drift->changes as $change) {
            $symbol = '?';
            $sev = $change->getSeverity()->value;
            if ($sev === 'BREAKING') {
                $symbol = '<fg=red>✗</>';
                $hasBreaking = true;
            } elseif ($sev === 'ADDITIVE') {
                $symbol = '<fg=green>✓</>';
            } elseif ($sev === 'ADVISORY') {
                $symbol = '<fg=yellow>⚠</>';
            }

            $io->writeln(sprintf("  %s  %s  %s \t(%s)", $symbol, $sev, $change->getPath(), $change->getDescription()));
        }

        return $hasBreaking ? Command::FAILURE : Command::SUCCESS;
    }
}
