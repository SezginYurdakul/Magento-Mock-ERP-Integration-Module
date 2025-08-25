<?php

declare(strict_types=1);

namespace Custom\ErpIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Custom\ErpIntegration\Service\ErpIntegrationLogger;

class OrderCancelAfterObserver implements ObserverInterface
{
    private ErpIntegrationLogger $erpLogger;

    public function __construct(ErpIntegrationLogger $erpLogger)
    {
        $this->erpLogger = $erpLogger;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            $this->erpLogger->error("Order object is missing, could not update ERP JSON on cancel.");
            return;
        }
        $incrementId = method_exists($order, 'getIncrementId') ? $order->getIncrementId() : null;
        if (!$incrementId) {
            $this->erpLogger->error("Order increment_id is missing, cannot update ERP JSON on cancel.");
            return;
        }
        $filePath = BP . '/var/export/erp_orders.json';
        if (!file_exists($filePath)) {
            $this->erpLogger->comment("ERP JSON file does not exist, nothing to update for order #$incrementId.");
            return;
        }
        $json = file_get_contents($filePath);
        $ordersData = json_decode($json, true) ?: [];
        $found = false;
        foreach ($ordersData as &$orderData) {
            if (($orderData['increment_id'] ?? null) == $incrementId) {
                $orderData['status'] = 'canceled';
                $found = true;
                break;
            }
        }
        unset($orderData);
        if ($found) {
            try {
                file_put_contents($filePath, json_encode($ordersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->erpLogger->info("Order #$incrementId marked as canceled in ERP JSON file.");
            } catch (\Throwable $e) {
                $this->erpLogger->error("Failed to update ERP JSON for canceled order #$incrementId: " . $e->getMessage());
            }
        } else {
            $this->erpLogger->comment("Order #$incrementId not found in ERP JSON file, cannot mark as canceled.");
        }
    }
}
