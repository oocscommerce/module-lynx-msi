<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxMSI\Model\Product;

use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use OOCS\LynxCatalog\Model\Product\SalableStatus as CatalogSalableStatus;

/**
 * Salability of many SKUs in one MSI stock index query, against the stock of the current website.
 *
 * The index does not re-check that a child is enabled, so such a configurable still reads as salable.
 */
class SalableStatus extends CatalogSalableStatus
{
    /**
     * MSI salability service and the stock of the current website
     *
     * @param AreProductsSalableInterface $areProductsSalable
     * @param GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite
     */
    public function __construct(
        private readonly AreProductsSalableInterface $areProductsSalable,
        private readonly GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite,
    ) {
    }

    /**
     * The salable flag of every SKU asked for, keyed by SKU.
     *
     * @param string[] $skus
     * @return array<string, bool>
     */
    public function forSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));
        if ($skus === []) {
            return [];
        }

        $status = [];
        foreach ($this->areProductsSalable->execute($skus, $this->getStockIdForCurrentWebsite->execute()) as $result) {
            $status[$result->getSku()] = $result->isSalable();
        }

        return $status;
    }
}
