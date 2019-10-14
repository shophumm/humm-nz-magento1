<?php

/**
 * Class Humm_HummPayments_Helper_Data
 *
 * Provides helper methods for retrieving data for the humm plugin
 */
class Humm_HummPayments_Helper_Data extends Mage_Core_Helper_Abstract {
    public static function init() {
    }

    /**
     * get if this is humm or Oxipay now
     * @return string
     */
    public static function getTitle() {
        $launch_time_string = Mage::getStoreConfig( 'payment/HummPayments/launch_time_string' );
        $is_after           = ( time() - strtotime( $launch_time_string ) >= 0 ) || Mage::getStoreConfig( 'payment/HummPayments/force_humm' );
        $title              = ( $is_after && Mage::getStoreConfig( 'payment/HummPayments/specificcountry' ) == 'AU' ) ? 'Humm' : 'Oxipay';

        return $title;
    }

    /**
     * get the URL of the configured humm gateway checkout
     * @return string
     */
    public static function getCheckoutURL() {
        $checkoutUrl = Mage::getStoreConfig( 'payment/HummPayments/gateway_url' );
        if ( isset( $checkoutUrl ) and strtolower( substr( $checkoutUrl, 0, 4 ) ) == 'http' ) {
            return $checkoutUrl;
        } else {
            $title          = self::getTitle();
            $country        = Mage::getStoreConfig( 'payment/HummPayments/specificcountry' );
            $country_domain = $country == 'NZ' ? '.co.nz' : '.com.au';  // .com.au is the default value
            $isSandbox      = Mage::getStoreConfig( 'payment/HummPayments/is_testing' ) ? true : false;
            $domainsTest    = array(
                'Humm'   => 'integration-cart.shophumm',
                'Oxipay' => 'securesandbox.oxipay'
            );
            $domains        = array(
                'Humm'   => 'cart.shophumm',
                'Oxipay' => 'secure.oxipay'
            );

            return 'https://' . ( $isSandbox ? $domainsTest[ $title ] : $domains[ $title ] ) . $country_domain . '/Checkout?platform=Default';
        }
    }

    /**
     * get the URL of the configured humm gateway refund
     * @return string
     */
    public static function getRefundURL() {
        $title          = self::getTitle();
        $country        = Mage::getStoreConfig( 'payment/HummPayments/specificcountry' );
        $country_domain = $country == 'NZ' ? '.co.nz' : '.com.au';  // .com.au is the default value
        $isSandbox      = Mage::getStoreConfig( 'payment/HummPayments/is_testing' ) ? true : false;
        $domainsTest    = array(
            'Humm'   => 'integration-buyerapi.shophumm',
            'Oxipay' => 'portalssandbox.oxipay'
        );
        $domains        = array(
            'Humm'   => 'buyerapi.shophumm',
            'Oxipay' => 'portals.oxipay'
        );

        return 'https://' . ( $isSandbox ? $domainsTest[ $title ] : $domains[ $title ] ) . $country_domain . '/api/ExternalRefund/v1/processrefund';
    }

    /**
     * @return string
     */
    public static function getCompleteUrl() {
        return Mage::getBaseUrl() . 'hummpayments/payment/complete';
    }

    /**
     * @return string
     */
    public static function getCancelledUrl( $orderId ) {
        return Mage::getBaseUrl() . "hummpayments/payment/cancel?orderId=$orderId";
    }
}

Humm_HummPayments_Helper_Data::init();