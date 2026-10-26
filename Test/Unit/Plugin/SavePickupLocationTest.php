<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxMSI\Test\Unit\Plugin;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryInStorePickupApi\Api\Data\PickupLocationInterface;
use Magento\InventoryInStorePickupApi\Model\GetPickupLocationInterface;
use Magento\InventoryInStorePickupQuote\Model\Address\GetAddressPickupLocationCode;
use Magento\InventoryInStorePickupQuote\Model\GetWebsiteCodeByStoreId;
use Magento\InventoryInStorePickupQuote\Model\ToQuoteAddress;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magento\Quote\Api\Data\AddressExtensionInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use OOCS\LynxCheckout\Provider\Checkout\Step\ShippingMethod;
use OOCS\LynxMSI\Plugin\SavePickupLocation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Choosing in-store pickup writes the store as the shipping address, with its pickup location code
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SavePickupLocationTest extends TestCase
{
    private const DRAFT = ['shippingMethod' => 'instore_pickup', 'pickupLocationCode' => 'austin'];

    /**
     * @var Quote&MockObject
     */
    private Quote $quote;

    /**
     * @var Address&MockObject
     */
    private Address $address;

    /**
     * @var GetPickupLocationInterface&MockObject
     */
    private GetPickupLocationInterface $getPickupLocation;

    /**
     * @var GetAddressPickupLocationCode&MockObject
     */
    private GetAddressPickupLocationCode $addressCode;

    /**
     * @var ToQuoteAddress&MockObject
     */
    private ToQuoteAddress $toQuoteAddress;

    /**
     * @var ShippingInformationInterface&MockObject
     */
    private ShippingInformationInterface $information;

    /**
     * @var ShippingInformationManagementInterface&MockObject
     */
    private ShippingInformationManagementInterface $management;

    /**
     * @var SavePickupLocation
     */
    private SavePickupLocation $plugin;

    /**
     * Quote 10 on store 1 of website "base", shipping by flat rate so far
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->address = $this->createMock(Address::class);
        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getId')->willReturn(10);
        $this->quote->method('getStoreId')->willReturn(1);
        $this->quote->method('getShippingAddress')->willReturn($this->address);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($this->quote);

        $this->getPickupLocation = $this->createMock(GetPickupLocationInterface::class);
        $websiteCode = $this->createMock(GetWebsiteCodeByStoreId::class);
        $websiteCode->method('execute')->with(1)->willReturn('base');
        $this->addressCode = $this->createMock(GetAddressPickupLocationCode::class);
        $this->toQuoteAddress = $this->createMock(ToQuoteAddress::class);
        $extensionFactory = $this->createMock(AddressExtensionFactory::class);
        $extensionFactory->method('create')->willReturn($this->createMock(AddressExtensionInterface::class));
        $this->information = $this->createMock(ShippingInformationInterface::class);
        $informationFactory = $this->createMock(ShippingInformationInterfaceFactory::class);
        $informationFactory->method('create')->willReturn($this->information);
        $this->management = $this->createMock(ShippingInformationManagementInterface::class);

        $this->plugin = new SavePickupLocation(
            $session,
            $this->getPickupLocation,
            $websiteCode,
            $this->addressCode,
            $this->toQuoteAddress,
            $extensionFactory,
            $informationFactory,
            $this->management
        );
    }

    /**
     * Run the plugin on a draft
     *
     * @param array $draft
     * @return array
     */
    private function before(array $draft): array
    {
        return $this->plugin->beforeSave($this->createMock(ShippingMethod::class), $draft);
    }

    /**
     * The store becomes the addressee, carries its code and is saved as instore_pickup
     *
     * @return void
     */
    public function testSavesStoreAddress(): void
    {
        $location = $this->createMock(PickupLocationInterface::class);
        $location->method('getName')->willReturn('Austin Store');
        $location->method('getPhone')->willReturn('555');
        $this->getPickupLocation->method('execute')->with('austin', 'website', 'base')->willReturn($location);
        $extension = $this->createMock(AddressExtensionInterface::class);
        $extension->expects($this->once())->method('setPickupLocationCode')->with('austin');
        $storeAddress = $this->createMock(AddressInterface::class);
        $storeAddress->method('getExtensionAttributes')->willReturn($extension);
        $storeAddress->expects($this->once())->method('setExtensionAttributes')->with($extension);
        $this->toQuoteAddress->expects($this->once())->method('convert')
            ->with($location, $this->address, [
                'firstname' => 'Austin Store',
                'lastname'  => 'Store',
                'telephone' => '555',
            ])
            ->willReturn($storeAddress);
        $this->information->expects($this->once())->method('setShippingCarrierCode')->with('instore');
        $this->information->expects($this->once())->method('setShippingMethodCode')->with('pickup');
        $this->management->expects($this->once())->method('saveAddressInformation')->with(10, $this->information);

        $this->assertSame([self::DRAFT], $this->before(self::DRAFT));
    }

    /**
     * Another method, no store code, an empty or virtual quote, or the same store again save nothing
     *
     * @return void
     */
    public function testNothingToSave(): void
    {
        $this->management->expects($this->never())->method('saveAddressInformation');
        $this->getPickupLocation->expects($this->never())->method('execute');

        $this->before(['shippingMethod' => 'flatrate_flatrate', 'pickupLocationCode' => 'austin']);
        $this->before(['shippingMethod' => 'instore_pickup', 'pickupLocationCode' => '']);
        $this->before(['shippingMethod' => 'instore_pickup']);

        $this->address->method('getShippingMethod')->willReturn('instore_pickup');
        $this->addressCode->method('execute')->willReturn('austin');
        $this->before(self::DRAFT);

        $this->quote->method('isVirtual')->willReturn(true);
        $this->before(['shippingMethod' => 'instore_pickup', 'pickupLocationCode' => 'other']);
    }

    /**
     * An unknown store code fails, for the step to show as its error
     *
     * @return void
     */
    public function testUnknownStore(): void
    {
        $this->getPickupLocation->method('execute')->willThrowException(new NoSuchEntityException());
        $this->management->expects($this->never())->method('saveAddressInformation');

        $this->expectException(NoSuchEntityException::class);
        $this->before(self::DRAFT);
    }
}
