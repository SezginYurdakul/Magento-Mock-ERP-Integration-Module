<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Console\Command;

use Custom\ErpIntegration\Model\ProductImporter;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ErpIntegrationCommand extends Command
{
    public const FILE_ARGUMENT = 'file';
    private const DEFAULT_FILE = 'var/import/erp_products.json';
    private const DEFAULT_SOURCE = 'default'; // MSI source code (usually 'default' for single source)

    private ProductImporter $productImporter;
    private ProductRepositoryInterface $productRepository;
    private SourceItemsSaveInterface $sourceItemsSave;
    private SourceItemInterfaceFactory $sourceItemFactory;
    private State $appState;
    private ProductFactory $productFactory;
    private ScopeConfigInterface $scopeConfig;
    private Logger $cronLogger;

    public function __construct(
        ProductImporter $productImporter,
        ProductRepositoryInterface $productRepository,
        SourceItemsSaveInterface $sourceItemsSave,
        SourceItemInterfaceFactory $sourceItemFactory,
        State $appState,
        ProductFactory $productFactory,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->productImporter     = $productImporter;
        $this->productRepository   = $productRepository;
        $this->sourceItemsSave     = $sourceItemsSave;
        $this->sourceItemFactory   = $sourceItemFactory;
        $this->appState            = $appState;
        $this->productFactory      = $productFactory;
        $this->scopeConfig         = $scopeConfig;
        $this->cronLogger = new Logger('cron');
        $logPath = BP . '/var/log/magento.cron.log';
        $this->cronLogger->pushHandler(new StreamHandler($logPath, Logger::INFO));
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
        // The area code is set to ensure that the correct context is used when processing the command
        // This is particularly important for commands that interact with the Magento framework
        try {
            // Try to set if not already set
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Ignore "Area code is already set" exception
        }


        // If CLI argument is not provided, read from system config
        $filePath = $input->getArgument(self::FILE_ARGUMENT);
        if (!$filePath) {
            $filePath = $this->scopeConfig->getValue('erp_integration/general/json_path') ?: self::DEFAULT_FILE;
        }

        try {
            $products = $this->productImporter->readErpProducts($filePath);
        } catch (\Throwable $t) {
            $output->writeln('<error>Failed to read ERP file: ' . $t->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($products)) {
            $output->writeln('<error>No products found in file.</error>');
            return Command::FAILURE;
        }


        $updated = 0;
        $created = 0;
        $disabled = 0;
        $enabled = 0;
        $failReasons = [];
        foreach ($products as $productData) {
            $action = $productData['action'] ?? 'update';
            $sku   = $productData['sku']   ?? null;
            if (!$sku) {
                $msg = 'SKU missing in product data.';
                $output->writeln('<error>' . $msg . '</error>');
                $this->cronLogger->error($msg);
                $failReasons[] = $msg;
                continue;
            }
            try {
                if ($action === 'new') {
                    $failMsg = null;
                    $createdResult = $this->createProduct($productData, $output, $failMsg);
                    if ($createdResult) {
                        $created++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be created. Unknown reason.");
                    }
                } elseif ($action === 'disable') {
                    $failMsg = null;
                    $disabledResult = $this->disableProduct($sku, $output, $failMsg);
                    if ($disabledResult) {
                        $disabled++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be disabled. Unknown reason.");
                    }
                } elseif ($action === 'enable') {
                    $failMsg = null;
                    $enabledResult = $this->enableProduct($sku, $output, $failMsg);
                    if ($enabledResult) {
                        $enabled++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be enabled. Unknown reason.");
                    }
                } else {
                    $failMsg = null;
                    $updatedResult = $this->updateProduct($productData, $output, $failMsg);
                    if ($updatedResult) {
                        $updated++;
                    } else {
                        $failReasons[] = $failMsg ?? ("Product with SKU '$sku' could not be updated. Unknown reason.");
                    }
                }
            } catch (\Throwable $e) {
                $msg = sprintf('Failed to process SKU: %s - %s', $sku, $e->getMessage());
                $output->writeln('<error>' . $msg . '</error>');
                $this->cronLogger->error($msg);
                $failReasons[] = $msg;
            }
        }

        if ($updated === 0 && $created === 0 && $disabled === 0 && $enabled === 0) {
            $msg = 'No records were processed.';
            $output->writeln('<comment>' . $msg . '</comment>');
            $this->cronLogger->info($msg);
            if (!empty($failReasons)) {
                $output->writeln('<comment>Reasons:</comment>');
                $this->cronLogger->info('Reasons:');
                foreach ($failReasons as $failMsg) {
                    $output->writeln('<comment>' . $failMsg . '</comment>');
                    $this->cronLogger->info($failMsg);
                }
            } else {
                $msg = sprintf('Total updated: %d | created: %d | disabled: %d | enabled: %d', $updated, $created, $disabled, $enabled);
                $output->writeln('<info>' . $msg . '</info>');
                $this->cronLogger->info($msg);
            }

            return Command::SUCCESS;
        }
        return Command::SUCCESS;
    }

    /**
     * Update an existing product's price and stock
     */
    private function updateProduct(array $productData, OutputInterface $output, ?string &$failMsg = null): bool
    {
        $sku   = $productData['sku'];
        $price = $productData['price'] ?? null;
        $product = $this->productRepository->get($sku);
        $changed = false;
        if ($price !== null) {
            $price = (float)$price;
            if ($price < 0) {
                $msg = sprintf('Product with SKU "%s" could not be updated: price cannot be negative (%.2f)', $sku, $price);
                $failMsg = $msg;
                return false;
            }
            if ((float)$product->getPrice() !== $price) {
                $product->setPrice($price);
                $changed = true;
            }
        }
        // Multi-source support
        $sources = $productData['sources'];
        $sourceChanged = false;
    // Get existing source items
        $existingSourceItems = [];
        try {
            $searchCriteriaBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Framework\Api\SearchCriteriaBuilder::class);
            $searchCriteria = $searchCriteriaBuilder
                ->addFilter('sku', $sku)
                ->create();
            $sourceItemRepository = \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\InventoryApi\Api\SourceItemRepositoryInterface::class);
            $list = $sourceItemRepository->getList($searchCriteria);
            foreach ($list->getItems() as $item) {
                $existingSourceItems[$item->getSourceCode()] = [
                    'qty' => (float)$item->getQuantity(),
                    'status' => (int)$item->getStatus(),
                ];
            }
        } catch (\Throwable $e) {
            // ignore, treat as no existing source items
        }

        $sourceItems = [];
        foreach ($sources as $sourceData) {
            $sourceCode = $sourceData['source_code'] ?? self::DEFAULT_SOURCE;
            $qty = isset($sourceData['qty']) ? (float)$sourceData['qty'] : null;
            if ($qty === null) continue;
            if ($qty < 0) {
                $msg = sprintf('Product with SKU "%s" could not be updated: quantity for source "%s" cannot be negative (%.2f)', $sku, $sourceCode, $qty);
                $failMsg = $msg;
                return false;
            }
            $newStatus = $qty > 0 ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK;
            $existing = $existingSourceItems[$sourceCode] ?? null;
            if ($existing && $existing['qty'] === $qty && $existing['status'] === $newStatus) {
                // There are no changes to be made
                continue;
            }
            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSku($sku);
            $sourceItem->setSourceCode($sourceCode);
            $sourceItem->setQuantity($qty);
            $sourceItem->setStatus($newStatus);
            $sourceItems[] = $sourceItem;
            $sourceChanged = true;
        }
        if ($sourceItems) {
            $this->sourceItemsSave->execute($sourceItems);
        }
        if ($changed) {
            $this->productRepository->save($product);
        }
        if ($changed || $sourceChanged) {
            $msg = sprintf(
                'Updated SKU: %s%s%s',
                $sku,
                $changed ? ' | Price: ' . (string)$price : '',
                $sourceChanged ? ' | Sources: ' . json_encode($sources) : ''
            );
            $output->writeln('<info>' . $msg . '</info>');
            $this->cronLogger->info($msg);
            return true;
        }
        $failMsg = sprintf('Product with SKU "%s" could not be updated: no changes detected.', $sku);
        return false;
    }

    /**
     * Disable (set status to disabled) a product
     */
    private function disableProduct(string $sku, OutputInterface $output, ?string &$failMsg = null): bool
    {
        try {
            $product = $this->productRepository->get($sku, false, 0);
            if ($product->getStatus() == 2) {
                $failMsg = sprintf('Product with SKU "%s" could not be disabled: already disabled.', $sku);
                return false;
            }
            $product->setStatus(2); // 2 = disabled
            $this->productRepository->save($product);
            $msg = sprintf('Disabled SKU: %s', $sku);
            $output->writeln('<info>' . $msg . '</info>');
            $this->cronLogger->info($msg);
            return true;
        } catch (\Throwable $e) {
            $failMsg = sprintf('Product with SKU "%s" could not be disabled: %s', $sku, $e->getMessage());
            $output->writeln('<error>' . $failMsg . '</error>');
            $this->cronLogger->error($failMsg);
            return false;
        }
    }

    /**
     * Enable (set status to enabled) a product
     */
    private function enableProduct(string $sku, OutputInterface $output, ?string &$failMsg = null): bool
    {
        try {
            $product = $this->productRepository->get($sku, false, 0);
            if ($product->getStatus() == 1) {
                $failMsg = sprintf('Product with SKU "%s" could not be enabled: already enabled.', $sku);
                return false;
            }
            $product->setStatus(1); // 1 = enabled
            $this->productRepository->save($product);
            $msg = sprintf('Enabled SKU: %s', $sku);
            $output->writeln('<info>' . $msg . '</info>');
            $this->cronLogger->info($msg);
            return true;
        } catch (\Throwable $e) {
            $failMsg = sprintf('Product with SKU "%s" could not be enabled: %s', $sku, $e->getMessage());
            $output->writeln('<error>' . $failMsg . '</error>');
            $this->cronLogger->error($failMsg);
            return false;
        }
    }

    /**
     * Create a new product
     */
    private function createProduct(array $productData, OutputInterface $output, ?string &$failMsg = null): bool
    {
        $sku = $productData['sku'];
        $name = $productData['name'] ?? 'New Product';
        $price = $productData['price'] ?? 0;
        $attributeSetId = $productData['attribute_set_id'] ?? 4;
        $status = $productData['status'] ?? 1;
        $visibility = $productData['visibility'] ?? 4;
        $typeId = $productData['type_id'] ?? 'simple';

        // Check if product already exists
        try {
            $this->productRepository->get($sku);
            // Product exists, do not create or show message
            $failMsg = sprintf('Product with SKU "%s" could not be created: already exists.', $sku);
            return false;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Product does not exist, continue to create
        }

        // Negative price check
        if ((float)$price < 0) {
            $failMsg = sprintf('Product with SKU "%s" could not be created: price cannot be negative (%.2f)', $sku, $price);
            return false;
        }

        // Multi-source support
        $sources = $productData['sources'];
        foreach ($sources as $sourceData) {
            $sourceCode = $sourceData['source_code'] ?? self::DEFAULT_SOURCE;
            $qty = isset($sourceData['qty']) ? (float)$sourceData['qty'] : null;
            if ($qty === null) continue;
            if ($qty < 0) {
                $failMsg = sprintf('Product with SKU "%s" could not be created: quantity for source "%s" cannot be negative (%.2f)', $sku, $sourceCode, $qty);
                return false;
            }
        }

        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setName($name);
        $product->setPrice((float)$price);
        $product->setTypeId($typeId);
        $product->setAttributeSetId((int)$attributeSetId);
        $product->setStatus((int)$status);
        $product->setVisibility((int)$visibility);
        $this->productRepository->save($product);

        $sourceItems = [];
        foreach ($sources as $sourceData) {
            $sourceCode = $sourceData['source_code'] ?? self::DEFAULT_SOURCE;
            $qty = isset($sourceData['qty']) ? (float)$sourceData['qty'] : null;
            if ($qty === null) continue;
            /** @var SourceItemInterface $sourceItem */
            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSku($sku);
            $sourceItem->setSourceCode($sourceCode);
            $sourceItem->setQuantity($qty);
            $sourceItem->setStatus(
                $qty > 0
                    ? SourceItemInterface::STATUS_IN_STOCK
                    : SourceItemInterface::STATUS_OUT_OF_STOCK
            );
            $sourceItems[] = $sourceItem;
        }
        if ($sourceItems) {
            $this->sourceItemsSave->execute($sourceItems);
        }
        $msg = sprintf('Created SKU: %s | Name: %s | Price: %s | Sources: %s', $sku, $name, $price, json_encode($sources));
        $output->writeln('<info>' . $msg . '</info>');
        $this->cronLogger->info($msg);
        return true;
    }
}
