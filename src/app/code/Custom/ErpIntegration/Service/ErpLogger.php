<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Service;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Output\OutputInterface;

class ErpLogger
{
    private Logger $cronLogger;

    public function __construct()
    {
        $this->cronLogger = new Logger('cron');
        $logPath = BP . '/var/log/magento.cron.log';
        $this->cronLogger->pushHandler(new StreamHandler($logPath, Logger::INFO));
    }

    public function info(string $msg, OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<info>' . $msg . '</info>');
        }
        $this->cronLogger->info($msg);
    }

    public function error(string $msg, OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<error>' . $msg . '</error>');
        }
        $this->cronLogger->error($msg);
    }

    public function comment(string $msg, OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<comment>' . $msg . '</comment>');
        }
        $this->cronLogger->info($msg);
    }
}
