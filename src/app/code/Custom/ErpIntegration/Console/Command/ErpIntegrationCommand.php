<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Console\Command;

use Custom\ErpIntegration\Model\ProductImporter;
use Custom\ErpIntegration\Service\ProductActionService;
use Custom\ErpIntegration\Service\ErpLogger;
use Custom\ErpIntegration\Service\ProductInputValidator;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ErpIntegrationCommand extends Command
{
    public const FILE_ARGUMENT = 'file';
    private const DEFAULT_FILE = 'var/import/erp_products.json';
    private const DEFAULT_SOURCE = 'default'; // MSI source code (usually 'default' for single source)
    private ProductImporter $productImporter;
    private State $appState;
    private ScopeConfigInterface $scopeConfig;
    private ProductActionService $productActionService;
    private ErpLogger $erpLogger;
    private ProductInputValidator $inputValidator;

    public function __construct(
        ProductImporter $productImporter,
        State $appState,
        ScopeConfigInterface $scopeConfig,
        ProductActionService $productActionService,
        ErpLogger $erpLogger,
        ProductInputValidator $inputValidator
    ) {
        $this->productImporter = $productImporter;
        $this->appState = $appState;
        $this->scopeConfig = $scopeConfig;
        $this->productActionService = $productActionService;
        $this->erpLogger = $erpLogger;
        $this->inputValidator = $inputValidator;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('erp:integration:run')
            ->setDescription('Processes ERP product integration actions (update, new, enable, disable) from JSON file')
            ->addArgument(
                self::FILE_ARGUMENT,
                InputArgument::OPTIONAL,
                'Path to the JSON file (default: var/import/erp_products.json)'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Ignore if already set
        }

        $filePath = $input->getArgument(self::FILE_ARGUMENT);
        if (!$filePath) {
            $filePath = $this->scopeConfig->getValue('erp_integration/general/json_path') ?: self::DEFAULT_FILE;
        }

        try {
            $products = $this->productImporter->readErpProducts($filePath);
        } catch (\Throwable $t) {
            $this->erpLogger->error('Failed to read ERP file: ' . $t->getMessage(), $output);
            return Command::FAILURE;
        }

        if (empty($products)) {
            $this->erpLogger->error('No products found in file.', $output);
            return Command::FAILURE;
        }

        // Validate input, but always process enable/disable for all products
        $validationErrors = [];
        $allValid = $this->inputValidator->validateAll($products, $validationErrors);

        $updated = 0;
        $created = 0;
        $disabled = 0;
        $enabled = 0;
        $failReasons = [];
        $processedSkus = [];

        // Collect validation errors for update/new, but always try enable/disable
        foreach ($products as $idx => $productData) {
            $action = $productData['action'] ?? 'update';
            $sku   = $productData['sku']   ?? null;
            $errs = $validationErrors[$idx] ?? [];
            if (!$sku) {
                $msg = 'Product with SKU is missing: SKU is missing.';
                $failReasons[] = $msg;
                continue;
            }
            if (in_array($action, ['enable', 'disable'], true)) {
                // Always try enable/disable, even if validation failed
                $failMsg = null;
                if ($action === 'enable') {
                    $enabledResult = $this->productActionService->enableProduct($sku, $output, $failMsg);
                    if ($enabledResult) {
                        $enabled++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be enabled. Unknown reason.");
                    }
                } else {
                    $disabledResult = $this->productActionService->disableProduct($sku, $output, $failMsg);
                    if ($disabledResult) {
                        $disabled++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be disabled. Unknown reason.");
                    }
                }
            } else {
                // For update/new, only process if valid
                if (!empty($errs)) {
                    foreach ($errs as $err) {
                        $failReasons[] = $err;
                    }
                    continue;
                }
                try {
                    $failMsg = null;
                    if ($action === 'new') {
                        $createdResult = $this->productActionService->createProduct($productData, $output, $failMsg);
                        if ($createdResult) {
                            $created++;
                        } else {
                            $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be created. Unknown reason.");
                        }
                    } else {
                        $updatedResult = $this->productActionService->updateProduct($productData, $output, $failMsg);
                        if ($updatedResult) {
                            $updated++;
                        } else {
                            $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be updated. Unknown reason.");
                        }
                    }
                } catch (\Throwable $e) {
                    $msg = sprintf('Failed to process SKU: %s - %s', $sku, $e->getMessage());
                    $failReasons[] = $msg;
                }
            }
        }

        if ($updated === 0 && $created === 0 && $disabled === 0 && $enabled === 0) {
            $msg = 'No records were processed.';
            $this->erpLogger->comment($msg, $output);
            if (!empty($failReasons)) {
                $this->erpLogger->comment('Reasons:', $output);
                foreach ($failReasons as $failMsg) {
                    $this->erpLogger->comment($failMsg, $output);
                }
            }
        } else {
            $msg = sprintf('Total updated: %d | created: %d | disabled: %d | enabled: %d', $updated, $created, $disabled, $enabled);
            $this->erpLogger->info($msg, $output);
        }

        return Command::SUCCESS;
    }
}