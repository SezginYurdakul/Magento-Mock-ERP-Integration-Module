<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Custom\ErpIntegration\Service\ErpIntegrationLogger;
use Custom\ErpIntegration\Service\ProductInputValidator;

class ProductActionService
{
    private ProductRepositoryInterface $productRepository;
    private SourceItemsSaveInterface $sourceItemsSave;
    private SourceItemInterfaceFactory $sourceItemFactory;
    private ProductFactory $productFactory;
    private ErpIntegrationLogger $erpLogger;
    private ProductInputValidator $inputValidator;
    private const DEFAULT_SOURCE = 'default';

    public function __construct(
        ProductRepositoryInterface $productRepository,
        SourceItemsSaveInterface $sourceItemsSave,
        SourceItemInterfaceFactory $sourceItemFactory,
        ProductFactory $productFactory,
        ErpIntegrationLogger $erpLogger,
        ProductInputValidator $inputValidator
    ) {
        $this->productRepository = $productRepository;
        $this->sourceItemsSave = $sourceItemsSave;
        $this->sourceItemFactory = $sourceItemFactory;
        $this->productFactory = $productFactory;
        $this->erpLogger = $erpLogger;
        $this->inputValidator = $inputValidator;
    }

    public function createProduct(array $productData, OutputInterface $output, ?string &$failMsg = null): bool
    {
        $errors = [];
        if (!$this->inputValidator->validate($productData, $errors)) {
            $failMsg = implode("; ", $errors);
            return false;
        }
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
            $failMsg = sprintf('Product with SKU "%s" could not be created: already exists.', $sku);
            return false;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Product does not exist, continue to create
        }

        $sources = $productData['sources'] ?? [];
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
            $qty = isset($sourceData['quantity']) ? (float)$sourceData['quantity'] : null;
            if ($qty === null) continue;
            /** @var SourceItemInterface $sourceItem */
            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSku($sku);
            $sourceItem->setSourceCode($sourceCode);
            $sourceItem->setQuantity($qty);
            $sourceItem->setStatus($qty > 0
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
        $this->erpLogger->info($msg, $output);
        return true;
    }

    public function updateProduct(array $productData, OutputInterface $output, ?string &$failMsg = null): bool
    {
        $errors = [];
        if (!$this->inputValidator->validate($productData, $errors)) {
            $failMsg = implode("; ", $errors);
            return false;
        }
        $sku   = $productData['sku'];
        $price = $productData['price'] ?? null;
        $product = $this->productRepository->get($sku);
        $changed = false;
        if ($price !== null) {
            $price = (float)$price;
            if ((float)$product->getPrice() !== $price) {
                $product->setPrice($price);
                $changed = true;
            }
        }
        // Multi-source support
        $sources = $productData['sources'] ?? [];
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
            $qty = isset($sourceData['quantity']) ? (float)$sourceData['quantity'] : null;
            if ($qty === null) continue;
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
            $this->erpLogger->info($msg, $output);
            return true;
        }
        $failMsg = sprintf('Product with SKU "%s" could not be updated: no changes detected.', $sku);
        return false;
    }

    public function disableProduct(string $sku, OutputInterface $output, ?string &$failMsg = null): bool
    {
        try {
            $product = $this->productRepository->get($sku, false, 0);
            if ($product->getStatus() == 2) {
                $failMsg = sprintf('Product with SKU "%s" could not be disabled: already disabled.', $sku);
                return false;
            }
            $product->setStatus(2); // 2 = disabled
            $this->productRepository->save($product);
            $msg = sprintf('Product with SKU "%s" has been disabled.', $sku);
            $output->writeln('<info>' . $msg . '</info>');
            $this->erpLogger->info($msg, $output);
            return true;
        } catch (\Throwable $e) {
            $failMsg = sprintf('Product with SKU "%s" could not be disabled: %s', $sku, $e->getMessage());
            $output->writeln('<error>' . $failMsg . '</error>');
            return false;
        }
    }

    public function enableProduct(string $sku, OutputInterface $output, ?string &$failMsg = null): bool
    {
        try {
            $product = $this->productRepository->get($sku, false, 0);
            if ($product->getStatus() == 1) {
                $failMsg = sprintf('Product with SKU "%s" could not be enabled: already enabled.', $sku);
                return false;
            }
            $product->setStatus(1); // 1 = enabled
            $this->productRepository->save($product);
            $msg = sprintf('Product with SKU "%s" has been enabled.', $sku);
            $output->writeln('<info>' . $msg . '</info>');
            $this->erpLogger->info($msg, $output);
            return true;
        } catch (\Throwable $e) {
            $failMsg = sprintf('Product with SKU "%s" could not be enabled: %s', $sku, $e->getMessage());
            $output->writeln('<error>' . $failMsg . '</error>');
            return false;
        }
    }
}
