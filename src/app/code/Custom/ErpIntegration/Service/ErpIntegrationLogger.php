<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Service;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Output\OutputInterface;

class ErpIntegrationLogger
{
    private Logger $erpLogger;

    public function __construct()
    {
        $this->erpLogger = new Logger('erp_integration');
        $logPath = BP . '/var/log/magento.erp_integration.log';
        $this->erpLogger->pushHandler(new StreamHandler($logPath, Logger::INFO));
    }

    public function info(string $msg, ?OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<info>' . $msg . '</info>');
        }
        $this->erpLogger->info($msg);
    }

    public function error(string $msg, ?OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<error>' . $msg . '</error>');
        }
        $this->erpLogger->error($msg);
    }

    public function comment(string $msg, ?OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<comment>' . $msg . '</comment>');
        }
        $this->erpLogger->info($msg);
    }
}
