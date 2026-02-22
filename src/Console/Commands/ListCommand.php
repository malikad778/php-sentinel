<?php

declare(strict_types=1);

namespace Sentinel\Console\Commands;

use Sentinel\Store\FileSchemaStore;
use Sentinel\Sentinel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListCommand extends Command
{
    protected static string $defaultName = 'list-schemas'; // Avoid conflict with built-in "list"

    public function __construct(private readonly ?Sentinel $sentinel = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Lists all tracked endpoint keys with their current state.')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Schema store directory', './sentinel/schemas/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = is_string($input->getOption('output')) ? $input->getOption('output') : '';

        $store = $this->sentinel ? $this->sentinel->getStore() : new FileSchemaStore($dir);
        $keys = $store->all();

        if (count($keys) === 0) {
            $io->info("No tracked endpoints found in {$dir}.");
            return Command::SUCCESS;
        }

        $io->title("Tracked API Endpoints");

        $rows = [];
        foreach ($keys as $key) {
            $schema = $store->get($key);
            $samples = count($store->getSamples($key)->all());

            if ($schema !== null) {
                $state = '<info>Hardened</info>';
                $time = $schema->hardenedAt->format('Y-m-d H:i:s');
                $version = substr($schema->version, 0, 16) . '...';
            } else {
                $state = '<fg=yellow>Sampling</>';
                $time = '-';
                $version = '-';
            }

            $rows[] = [$key, $state, $samples, $version, $time];
        }

        $io->table(['Endpoint Key', 'State', 'Samples', 'Version', 'Last Update'], $rows);

        return Command::SUCCESS;
    }
}
