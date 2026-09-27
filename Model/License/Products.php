<?php
namespace SalesIgniter\Common\Model\License;

/**
 * The Sales Igniter Magento products as Easy Digital Downloads knows them on
 * rentalbookingsoftware.com, and the addresses licences are checked and renewed at.
 *
 * EDD Software Licensing checks a key against ONE download id and answers `invalid_item_id` for
 * any other (the store does not define EDD_BYPASS_ITEM_ID_CHECK), and a customer holds a key for
 * whichever of these they bought. So the key's product is found by asking in this order; the
 * base product first because it is what most stores hold.
 */
class Products
{
    const STORE_URL = 'https://rentalbookingsoftware.com/';

    /** EDD's checkout page; EDD SL builds its own renewal links on it (EDD_SL_License::get_renewal_url()). */
    const CHECKOUT_URL = 'https://rentalbookingsoftware.com/checkout/';

    const ALL = [
        32864 => 'Magento 2 Rental Booking System',
        32867 => 'Magento 2 Rental Booking System Pro',
        32870 => 'Magento 2 Rental Booking System Multi Source Inventory',
        32877 => 'Magento 2 Amasty RFQ Integration',
        32881 => 'Magento 2 Rental Booking Extension Multi-Vendor Marketplace',
    ];

    /**
     * The renewal link EDD itself would give this licence: its checkout with the key and the
     * download, which puts the renewal (at the renewal discount, if the store offers one) in the cart.
     */
    public static function renewalUrl(string $key, int $itemId): string
    {
        return self::CHECKOUT_URL . '?' . http_build_query(['edd_license_key' => $key, 'download_id' => $itemId]);
    }
}
