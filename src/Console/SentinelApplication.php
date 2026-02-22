<?php

declare(strict_types=1);

namespace Sentinel\Console;

use Sentinel\Console\Commands\DiffCommand;
use Sentinel\Console\Commands\InspectCommand;
use Sentinel\Console\Commands\ListCommand;
use Sentinel\Console\Commands\ProfileCommand;
use Symfony\Component\Console\Application;

class SentinelApplication extends Application
{
    public function __construct()
    {
        parent::__construct('php-sentinel', '1.0.0');

        $this->add(new ProfileCommand());
        $this->add(new DiffCommand());
        $this->add(new InspectCommand());
        $this->add(new ListCommand());
    }
}
