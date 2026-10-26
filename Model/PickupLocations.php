<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxMSI\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\InventoryInStorePickupApi\Api\Data\PickupLocationInterface;
use Magento\InventoryInStorePickupApi\Model\GetPickupLocationInterface;
use Magento\InventoryInStorePickupQuote\Model\Address\GetAddressPickupLocationCode;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;
use OOCS\LynxCheckout\Api\ShippingMethodRendererConfigInterface;

/**
 * What the store picker needs to search: not the stores themselves
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class PickupLocations implements ShippingMethodRendererConfigInterface
{
    /** Stores per page of the list, and per Show More. */
    private const PAGE_SIZE = 10;

    /**
     * Checkout session, store manager, url builder, get pickup location, get address pickup location code
     *
     * @param CheckoutSession $checkoutSession
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param GetPickupLocationInterface $getPickupLocation
     * @param GetAddressPickupLocationCode $getAddressPickupLocationCode
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly GetPickupLocationInterface $getPickupLocation,
        private readonly GetAddressPickupLocationCode $getAddressPickupLocationCode,
    ) {
    }

    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $websiteCode = (string) $this->storeManager->getWebsite()->getCode();

        $skus = [];
        foreach ($quote->getAllItems() as $item) {
            // The parents of a configurable or bundle are not stocked; their children are.
            if (empty($item->getChildren())) {
                $skus[] = (string) $item->getSku();
            }
        }

        return [
            // The same shape Helper\CartApiUrl builds for the cart's own endpoints.
            'search_url'   => $this->urlBuilder->getBaseUrl()
                . 'rest/' . $this->storeManager->getStore()->getCode()
                . '/V1/inventory/in-store-pickup/pickup-locations/',
            'website_code' => $websiteCode,
            'skus'         => $skus,
            'page_size'    => self::PAGE_SIZE,
            'selected'     => $this->getSelected($websiteCode),
            // Every string the renderer shows, translated here
            'labels'       => [
                'pickUpAt'    => (string) __('Pick up at'),
                'selectStore' => (string) __('Select Store'),
                'selectOther' => (string) __('Select Other'),
                'search'      => (string) __('Search by store name, city or postcode'),
                'loading'     => (string) __('Loading...'),
                'noResults'   => (string) __('We were unable to find nearby locations for provided search query.'),
                'showMore'    => (string) __('Show more'),
                'required'    => (string) __('Please select a store to pick up your order.'),
            ],
        ];
    }

    /**
     * The store on the quote's shipping address, as the REST endpoint lists it minus the description (list only)
     *
     * @param string $websiteCode
     * @return array<string, mixed>|null
     */
    private function getSelected(string $websiteCode): ?array
    {
        $address = $this->checkoutSession->getQuote()->getShippingAddress();
        $code = (string) $this->getAddressPickupLocationCode->execute($address);
        if ($code === '') {
            return null;
        }

        try {
            $location = $this->getPickupLocation->execute($code, SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->format($location);
    }

    /**
     * Format
     *
     * @param PickupLocationInterface $location
     * @return array<string, mixed>
     */
    private function format(PickupLocationInterface $location): array
    {
        return [
            'pickup_location_code' => $location->getPickupLocationCode(),
            'name'                 => (string) $location->getName(),
            'street'               => (string) $location->getStreet(),
            'city'                 => (string) $location->getCity(),
            'region'               => (string) $location->getRegion(),
            'postcode'             => (string) $location->getPostcode(),
            'country_id'           => (string) $location->getCountryId(),
            'phone'                => (string) $location->getPhone(),
        ];
    }
}
