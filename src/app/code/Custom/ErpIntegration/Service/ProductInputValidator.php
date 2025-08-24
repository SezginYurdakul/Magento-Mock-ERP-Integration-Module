<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Service;

class ProductInputValidator
{
    /**
     * Validate required product fields and structure
     *
     * @param array $productData
     * @param string[] $errors
     * @return bool
     */
    public function validate(array $productData, array &$errors = []): bool
    {
        $valid = true;
        $sku = isset($productData['sku']) ? $productData['sku'] : '';
        $action = isset($productData['action']) ? strtolower($productData['action']) : '';

        if (empty($sku)) {
            $errors[] = 'Product with SKU is missing: SKU is missing.';
            $valid = false;
        }
        if (empty($action)) {
            $errors[] = sprintf('Product with SKU "%s" could not be processed: action is missing.', $sku);
            $valid = false;
        }

        // For enable/disable, only sku and action are required
        if (in_array($action, ['enable', 'disable'], true)) {
            return $valid;
        }

        if (!isset($productData['price'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be %s: price is missing.', $sku, $action ?: 'processed');
            $valid = false;
        } elseif ($productData['price'] < 0) {
            $errors[] = sprintf('Product with SKU "%s" could not be %s: price cannot be negative (%.2f)', $sku, $action, $productData['price']);
            $valid = false;
        }
        if (!isset($productData['sources']) || !is_array($productData['sources'])) {
            $errors[] = sprintf('Product with SKU "%s" could not be %s: sources array is missing or invalid.', $sku, $action ?: 'processed');
            $valid = false;
        } else {
            foreach ($productData['sources'] as $idx => $source) {
                $sourceCode = isset($source['source_code']) ? $source['source_code'] : 'unknown';
                if (!isset($source['quantity'])) {
                    $errors[] = sprintf('Product with SKU "%s" could not be %s: quantity for source "%s" is missing', $sku, $action, $sourceCode);
                    $valid = false;
                } elseif ($source['quantity'] < 0) {
                    $errors[] = sprintf('Product with SKU "%s" could not be %s: quantity for source "%s" cannot be negative (%.2f)', $sku, $action, $sourceCode, $source['quantity']);
                    $valid = false;
                }
            }
        }
        return $valid;
    }

    /**
     * Validate the entire product list
     *
     * @param array $products
     * @param array $allErrors
     * @return bool
     */
    public function validateAll(array $products, array &$allErrors = []): bool
    {
        $allValid = true;
        foreach ($products as $idx => $productData) {
            $errors = [];
            if (!$this->validate($productData, $errors)) {
                $allValid = false;
                $allErrors[$idx] = $errors;
            }
        }
        return $allValid;
    }
}
