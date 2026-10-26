<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxMSI\Test\Unit\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\InventoryInStorePickupApi\Api\Data\PickupLocationInterface;
use Magento\InventoryInStorePickupApi\Model\GetPickupLocationInterface;
use Magento\InventoryInStorePickupQuote\Model\Address\GetAddressPickupLocationCode;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use OOCS\LynxMSI\Model\PickupLocations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the store picker under the in-store pickup rate gets: search endpoint, stocked SKUs, the chosen store
 */
class PickupLocationsTest extends TestCase
{
    /**
     * @var GetPickupLocationInterface&MockObject
     */
    private GetPickupLocationInterface $getPickupLocation;

    /**
     * @var GetAddressPickupLocationCode&MockObject
     */
    private GetAddressPickupLocationCode $addressCode;

    /**
     * @var PickupLocations
     */
    private PickupLocations $locations;

    /**
     * Store "default" of website "base"; the cart holds a simple and a configurable with one child
     *
     * @return void
     */
    protected function setUp(): void
    {
        $items = [
            $this->item('shirt', []),
            $this->item('hoodie', ['child']),
            $this->item('hoodie-m-blue', []),
        ];
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($items);
        $quote->method('getShippingAddress')->willReturn($this->createMock(Address::class));
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($quote);

        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(Store::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);
        $storeManager->method('getStore')->willReturn($store);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getBaseUrl')->willReturn('https://shop.test/');

        $this->getPickupLocation = $this->createMock(GetPickupLocationInterface::class);
        $this->addressCode = $this->createMock(GetAddressPickupLocationCode::class);

        $this->locations = new PickupLocations(
            $session,
            $storeManager,
            $url,
            $this->getPickupLocation,
            $this->addressCode
        );
    }

    /**
     * A quote item with this SKU and these children
     *
     * @param string $sku
     * @param array $children
     * @return Item
     */
    private function item(string $sku, array $children): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getChildren', 'getSku'])->getMock();
        $item->method('getSku')->willReturn($sku);
        $item->method('getChildren')->willReturn($children);

        return $item;
    }

    /**
     * Search endpoint of the store, the website, stocked SKUs only, page size and labels
     *
     * @return void
     */
    public function testConfig(): void
    {
        $config = $this->locations->getConfig();

        $this->assertSame(
            'https://shop.test/rest/default/V1/inventory/in-store-pickup/pickup-locations/',
            $config['search_url']
        );
        $this->assertSame('base', $config['website_code']);
        $this->assertSame(['shirt', 'hoodie-m-blue'], $config['skus']);
        $this->assertSame(10, $config['page_size']);
        $this->assertNull($config['selected']);
        $this->assertSame('Select Store', $config['labels']['selectStore']);
    }

    /**
     * The store already on the shipping address comes back as the selection
     *
     * @return void
     */
    public function testSelectedStore(): void
    {
        $this->addressCode->method('execute')->willReturn('austin');
        $location = $this->createMock(PickupLocationInterface::class);
        $location->method('getPickupLocationCode')->willReturn('austin');
        $location->method('getName')->willReturn('Austin Store');
        $location->method('getCity')->willReturn('Austin');
        $this->getPickupLocation->method('execute')->with('austin', 'website', 'base')->willReturn($location);

        $selected = $this->locations->getConfig()['selected'];

        $this->assertSame('austin', $selected['pickup_location_code']);
        $this->assertSame('Austin Store', $selected['name']);
        $this->assertSame('Austin', $selected['city']);
        $this->assertSame('', $selected['phone']);
    }

    /**
     * A store that no longer exists is no selection
     *
     * @return void
     */
    public function testSelectedStoreGone(): void
    {
        $this->addressCode->method('execute')->willReturn('closed');
        $this->getPickupLocation->method('execute')->willThrowException(new NoSuchEntityException());

        $this->assertNull($this->locations->getConfig()['selected']);
    }
}
