<?php

declare(strict_types=1);

namespace Sentinel\Console\Commands;

use GuzzleHttp\Client;
use Sentinel\Drift\DriftReporter;
use Sentinel\Events\SchemaDriftDetected;
use Sentinel\Middleware\SchemaWatcher;
use Sentinel\Sentinel;
use Sentinel\Store\FileSchemaStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProfileCommand extends Command
{
    protected static string $defaultName = 'profile';

    protected function configure(): void
    {
        $this->setDescription('Profiles an endpoint to build a baseline JSON Schema.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Target URL to profile')
            ->addOption('method', null, InputOption::VALUE_OPTIONAL, 'HTTP Method', 'GET')
            ->addOption('header', 'H', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Custom headers (e.g., "Authorization: Bearer ...")')
            ->addOption('samples', null, InputOption::VALUE_OPTIONAL, 'Number of samples to collect before hardening', 20)
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output directory for the FileSchemaStore', './sentinel/schemas/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $url = $input->getOption('url');
        if (!is_string($url)) {
            $io->error('A target URL is required.');
            return Command::FAILURE;
        }

        $method = is_string($input->getOption('method')) ? $input->getOption('method') : 'GET';
        $samplesCount = (int) $input->getOption('samples');
        $outputDir = is_string($input->getOption('output')) ? $input->getOption('output') : './sentinel/schemas/';

        $optionsHeaders = $input->getOption('header');
        $headers = [];
        if (is_array($optionsHeaders)) {
            foreach ($optionsHeaders as $h) {
                if (is_string($h) && str_contains($h, ':')) {
                    [$key, $value] = explode(':', $h, 2);
                    $headers[trim($key)] = trim($value);
                }
            }
        }

        // Setup Sentinel for CLI Profiling
        $store = new FileSchemaStore($outputDir);
        $sentinel = Sentinel::create()
            ->withStore($store)
            ->withSampleThreshold($samplesCount)
            ->build();

        // Stub out dispatcher since CLI profile shouldn't strictly require PSR-14 event systems to function
        $reporter = new DriftReporter(new class implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function dispatch(object $event): object { return $event; }
        });

        // Initialize Guzzle with the SchemaWatcher middleware
        $stack = \GuzzleHttp\HandlerStack::create();
        $stack->push(\GuzzleHttp\Middleware::mapRequest(function ($request) { return $request; })); // Placeholder
        // A proper PSR-18 adapter for Guzzle is needed since Guzzle Client implements PSR-18 natively.
        // We can just wrap it manually, or rely on Guzzle's native implementation.
        
        // Guzzle 7 implements PSR-18 ClientInterface directly.
        $baseClient = new Client([
            // no special handler needed, we wrap the client itself
        ]);

        $watcher = new SchemaWatcher($baseClient, $sentinel, $reporter);

        $io->title("Profiling {$method} {$url}");
        $io->text("Collecting {$samplesCount} samples into {$outputDir}");
        $io->progressStart($samplesCount);

        for ($i = 0; $i < $samplesCount; $i++) {
            $request = new \GuzzleHttp\Psr7\Request($method, $url, $headers);
            
            try {
                $response = $watcher->sendRequest($request);
                
                if ($response->getStatusCode() >= 400) {
                    $io->progressFinish();
                    $io->error("API returned status " . $response->getStatusCode() . " on request " . ($i+1));
                    return Command::FAILURE;
                }
            } catch (\Throwable $e) {
                $io->progressFinish();
                $io->error("Failed request: " . $e->getMessage());
                return Command::FAILURE;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success('Profiling complete! Schema baseline hardened.');

        return Command::SUCCESS;
    }
}
