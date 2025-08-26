<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Service;

class ProductInputValidator
{
    /**
     * Main validate method: routes to action-specific validation
     */
    public function validate(array $productData, array &$errors = []): bool
    {
        $sku = isset($productData['sku']) ? $productData['sku'] : '';
        $action = isset($productData['action']) ? strtolower($productData['action']) : '';
        if (empty($sku)) {
            $errors[] = 'Product with SKU is missing: SKU is missing.';
            return false;
        }
        if (empty($action)) {
            $errors[] = sprintf('Product with SKU "%s" could not be processed: action is missing.', $sku);
            return false;
        }
        switch ($action) {
            case 'new':
                return $this->validateNew($productData, $errors);
            case 'update':
                return $this->validateUpdate($productData, $errors);
            case 'enable':
                return $this->validateEnable($productData, $errors);
            case 'disable':
                return $this->validateDisable($productData, $errors);
            default:
                $errors[] = sprintf('Product with SKU "%s" has unknown action: %s', $sku, $action);
                return false;
        }
    }

    /**
     * Validate new product creation
     */
    public function validateNew(array $productData, array &$errors = []): bool
    {
        $valid = true;
        $sku = $productData['sku'] ?? '';
        if (!isset($productData['name']) || $productData['name'] === '') {
            $errors[] = sprintf('Product with SKU "%s" could not be created: name is missing.', $sku);
            $valid = false;
        }
        if (!isset($productData['price'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: price is missing.', $sku);
            $valid = false;
        } elseif ($productData['price'] < 0) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: price cannot be negative (%.2f)', $sku, $productData['price']);
            $valid = false;
        }
        if (!isset($productData['attribute_set_id'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: attribute_set_id is missing.', $sku);
            $valid = false;
        }
        if (!isset($productData['type_id'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: type_id is missing.', $sku);
            $valid = false;
        }
        if (!isset($productData['status'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: status is missing.', $sku);
            $valid = false;
        }
        if (!isset($productData['visibility'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: visibility is missing.', $sku);
            $valid = false;
        }
        if (!isset($productData['sources']) || !is_array($productData['sources'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be created: sources array is missing or invalid.', $sku);
            $valid = false;
        } else {
            foreach ($productData['sources'] as $idx => $source) {
                $sourceCode = $source['source_code'] ?? 'unknown';
                if (!isset($source['quantity'])) {
                    $errors[] = sprintf('Product with SKU "%s" could not be created: quantity for source "%s" is missing', $sku, $sourceCode);
                    $valid = false;
                } elseif ($source['quantity'] < 0) {
                    $errors[] = sprintf('Product with SKU "%s" could not be created: quantity for source "%s" cannot be negative (%.2f)', $sku, $sourceCode, $source['quantity']);
                    $valid = false;
                }
            }
        }
        return $valid;
    }

    /**
     * Validate product update
     */
    public function validateUpdate(array $productData, array &$errors = []): bool
    {
        $valid = true;
        $sku = $productData['sku'] ?? '';
        if (isset($productData['price']) && $productData['price'] < 0) {
            $errors[] = sprintf('Product with SKU "%s" could not be updated: price cannot be negative (%.2f)', $sku, $productData['price']);
            $valid = false;
        }
        if (!isset($productData['sources']) || !is_array($productData['sources'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be updated: sources array is missing or invalid.', $sku);
            $valid = false;
        } else {
            foreach ($productData['sources'] as $idx => $source) {
                $sourceCode = $source['source_code'] ?? 'unknown';
                if (!isset($source['quantity'])) {
                    $errors[] = sprintf('Product with SKU "%s" could not be updated: quantity for source "%s" is missing', $sku, $sourceCode);
                    $valid = false;
                } elseif ($source['quantity'] < 0) {
                    $errors[] = sprintf('Product with SKU "%s" could not be updated: quantity for source "%s" cannot be negative (%.2f)', $sku, $sourceCode, $source['quantity']);
                    $valid = false;
                }
            }
        }
        return $valid;
    }

    /**
     * Validate product enable
     */
    public function validateEnable(array $productData, array &$errors = []): bool
    {
        $sku = $productData['sku'] ?? '';
        if (empty($sku)) {
            $errors[] = 'Product with SKU is missing: SKU is missing.';
            return false;
        }
        return true;
    }

    /**
     * Validate product disable
     */
    public function validateDisable(array $productData, array &$errors = []): bool
    {
        $sku = $productData['sku'] ?? '';
        if (empty($sku)) {
            $errors[] = 'Product with SKU is missing: SKU is missing.';
            return false;
        }
        return true;
    }
}
