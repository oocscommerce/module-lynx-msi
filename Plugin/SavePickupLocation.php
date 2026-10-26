<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxMSI\Plugin;

use Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\InventoryInStorePickupApi\Model\GetPickupLocationInterface;
use Magento\InventoryInStorePickupQuote\Model\Address\GetAddressPickupLocationCode;
use Magento\InventoryInStorePickupQuote\Model\GetWebsiteCodeByStoreId;
use Magento\InventoryInStorePickupQuote\Model\ToQuoteAddress;
use Magento\InventoryInStorePickupShippingApi\Model\Carrier\InStorePickup;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use OOCS\LynxCheckout\Provider\Checkout\Step\ShippingMethod;

/**
 * Puts the picked store on the quote before the shipping method step saves the carrier
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class SavePickupLocation
{
    /**
     * Checkout session, get pickup location, get website code by store id, get address pickup location code
     *
     * @param CheckoutSession $checkoutSession
     * @param GetPickupLocationInterface $getPickupLocation
     * @param GetWebsiteCodeByStoreId $getWebsiteCodeByStoreId
     * @param GetAddressPickupLocationCode $getAddressPickupLocationCode
     * @param ToQuoteAddress $toQuoteAddress
     * @param AddressExtensionFactory $addressExtensionFactory
     * @param ShippingInformationInterfaceFactory $shippingInformationFactory
     * @param ShippingInformationManagementInterface $shippingInformationManagement
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly GetPickupLocationInterface $getPickupLocation,
        private readonly GetWebsiteCodeByStoreId $getWebsiteCodeByStoreId,
        private readonly GetAddressPickupLocationCode $getAddressPickupLocationCode,
        private readonly ToQuoteAddress $toQuoteAddress,
        private readonly AddressExtensionFactory $addressExtensionFactory,
        private readonly ShippingInformationInterfaceFactory $shippingInformationFactory,
        private readonly ShippingInformationManagementInterface $shippingInformationManagement,
    ) {
    }

    /**
     * Before Save
     *
     * @param ShippingMethod $subject
     * @param array $draft
     * @return array{0: array<string, mixed>}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(ShippingMethod $subject, array $draft): array
    {
        $code = (string) ($draft['pickupLocationCode'] ?? '');
        if (($draft['shippingMethod'] ?? '') !== InStorePickup::DELIVERY_METHOD || $code === '') {
            return [$draft];
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId() || $quote->isVirtual()) {
            return [$draft];
        }

        $address = $quote->getShippingAddress();
        if ($address->getShippingMethod() === InStorePickup::DELIVERY_METHOD
            && $this->getAddressPickupLocationCode->execute($address) === $code
        ) {
            return [$draft];
        }

        // Unknown code → NoSuchEntityException, which the process controller hands back to the step as its error
        $pickupLocation = $this->getPickupLocation->execute(
            $code,
            SalesChannelInterface::TYPE_WEBSITE,
            $this->getWebsiteCodeByStoreId->execute((int) $quote->getStoreId())
        );

        // The store as the addressee, the way core's checkout and its GraphQL both write it (firstname the store's
        $shippingAddress = $this->toQuoteAddress->convert($pickupLocation, $address, [
            'firstname' => (string) $pickupLocation->getName(),
            'lastname'  => 'Store',
            'telephone' => (string) $pickupLocation->getPhone(),
        ]);
        $extension = $shippingAddress->getExtensionAttributes() ?? $this->addressExtensionFactory->create();
        $extension->setPickupLocationCode($code);
        $shippingAddress->setExtensionAttributes($extension);

        // carrier_method: the carrier is the first segment, the method the rest.
        [$carrierCode, $methodCode] = explode('_', InStorePickup::DELIVERY_METHOD, 2);
        $information = $this->shippingInformationFactory->create();
        $information->setShippingAddress($shippingAddress);
        $information->setShippingCarrierCode($carrierCode);
        $information->setShippingMethodCode($methodCode);
        $this->shippingInformationManagement->saveAddressInformation((int) $quote->getId(), $information);

        return [$draft];
    }
}
