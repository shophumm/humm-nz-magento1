<?php
require_once dirname( __FILE__ ) . '/../Helper/Crypto.php';
require_once dirname( __FILE__ ) . '/../Helper/Data.php';

class Humm_HummPayments_PaymentController extends Mage_Core_Controller_Front_Action {
    const LOG_FILE = 'humm.log';
    const HUMM_AU_CURRENCY_CODE = 'AUD';
    const HUMM_AU_COUNTRY_CODE = 'AU';
    const HUMM_NZ_CURRENCY_CODE = 'NZD';
    const HUMM_NZ_COUNTRY_CODE = 'NZ';

    /**
     * GET: /HummPayments/payment/start
     *
     * Begin processing payment via humm
     */
    public function startAction() {
        if ( $this->validateQuote() ) {
            try {
                $order   = $this->getLastRealOrder();
                $payload = $this->getPayload( $order );

                if ( in_array( $order->getState(), array(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    Mage_Sales_Model_Order::STATE_COMPLETE,
                    Mage_Sales_Model_Order::STATE_CLOSED,
                    Mage_Sales_Model_Order::STATE_CANCELED,
                    Mage_Sales_Model_Order::STATE_HOLDED,
                    Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW
                ) ) ) {
                    $this->_redirect( 'checkout/cart' );
                    return;
                }

                $order->setState( Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, 'Humm authorisation underway.' );
                $order->setStatus( Humm_HummPayments_Helper_OrderStatus::STATUS_PENDING_PAYMENT );
                $order->save();

                $this->postToCheckout( Humm_HummPayments_Helper_Data::getCheckoutUrl(), $payload );
            } catch ( Exception $ex ) {
                Mage::logException( $ex );
                Mage::log( 'An exception was encountered in HummPayments/paymentcontroller: ' . $ex->getMessage(), Zend_Log::ERR, self::LOG_FILE );
                Mage::log( $ex->getTraceAsString(), Zend_Log::ERR, self::LOG_FILE );
                $this->getCheckoutSession()->addError( $this->__( 'Unable to start humm Checkout.' ) );
            }
        } else {
            $this->restoreCart( $this->getLastRealOrder() );
            $this->_redirect( 'checkout/cart' );

            // cancel order (restore stock) and delete order
            $order = $this->getLastRealOrder();
            $this->cancelOrder( $order );
            Mage::getResourceSingleton( 'sales/order' )->delete( $order );
        }
    }

    /**
     * GET: /HummPayments/payment/cancel
     * Cancel an order given an order id
     */
    public function cancelAction() {
        $orderId = $this->getRequest()->get( 'orderId' );
        $order   = $this->getOrderById( $orderId );

        if ( $order && $order->getId() ) {
            $cancel_signature_query = [
                "orderId"   => $orderId,
                "amount"    => $order->getTotalDue(),
                "email"     => $order->getData( 'customer_email' ),
                "firstname" => $order->getCustomerFirstname(),
                "lastname"  => $order->getCustomerLastname()
            ];
            $cancel_signature       = Humm_HummPayments_Helper_Crypto::generateSignature( $cancel_signature_query, $this->getApiKey() );
            $signatureValid         = ( $this->getRequest()->get( 'signature' ) == $cancel_signature );
            if ( ! $signatureValid ) {
                Mage::log( 'Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE );
                $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );

                return;
            }
            Mage::log(
                'Requested order cancellation by customer. OrderId: ' . $order->getIncrementId(),
                Zend_Log::DEBUG,
                self::LOG_FILE
            );
            $this->cancelOrder( $order );
            $this->restoreCart( $order );
            $order->save();
        }
        $this->_redirect( 'checkout/cart' );
    }

    /**
     * GET: HummPayments/payment/complete
     *
     * callback - humm calls this once the payment process has been completed.
     */
    public function completeAction() {
        $isValid       = Humm_HummPayments_Helper_Crypto::isValidSignature( $this->getRequest()->getParams(), $this->getApiKey() );
        $result        = $this->getRequest()->get( "x_result" );
        $orderId       = $this->getRequest()->get( "x_reference" );
        $transactionId = $this->getRequest()->get( "x_gateway_reference" );

        if ( ! $isValid ) {
            Mage::log( 'Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE );
            $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );

            return;
        }

        if ( ! $orderId ) {
            Mage::log( "Humm returned a null order id. This may indicate an issue with the humm payment gateway.", Zend_Log::ERR, self::LOG_FILE );
            $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );

            return;
        }

        $order = $this->getOrderById( $orderId );
        $isFromAsyncCallback = ( strtoupper( $this->getRequest()->getMethod() == "POST" ) ) ? true : false;

        if ( ! $order ) {
            Mage::log( "Humm returned an id for an order that could not be retrieved: $orderId", Zend_Log::ERR, self::LOG_FILE );
            $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );

            return;
        }

        // ensure that we have a Mage_Sales_Model_Order
        if ( get_class( $order ) !== 'Mage_Sales_Model_Order' ) {
            Mage::log( "The instance of order returned is an unexpected type.", Zend_Log::ERR, self::LOG_FILE );
        }

        $resource = Mage::getSingleton( 'core/resource' );
        $write    = $resource->getConnection( 'core_write' );
        $table    = $resource->getTableName( 'sales/order' );

        try {
            $write->beginTransaction();

            $select = $write->select()
                            ->forUpdate()
                            ->from( array( 't' => $table ),
                                array( 'state' ) )
                            ->where( 'increment_id = ?', $orderId );
            $state = $write->fetchOne( $select );

            $select_status = $write->select()
                            ->forUpdate()
                            ->from( array( 't' => $table ),
                                array( 'status' ) )
                            ->where( 'increment_id = ?', $orderId );
            $status = $write->fetchOne( $select_status );

            if ( $state === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT ) {
                $whereQuery = array( 'increment_id = ?' => $orderId );

                if ( $result == "completed" ) {
                    $dataQuery = array( 'state' => Mage_Sales_Model_Order::STATE_PROCESSING );
                } else {
                    $dataQuery = array( 'state' => Mage_Sales_Model_Order::STATE_CANCELED );
                }

                $write->update( $table, $dataQuery, $whereQuery );
            } elseif ( $status === Humm_HummPayments_Helper_OrderStatus::STATUS_CANCELED && $result == "completed" ){
                $whereQuery = array( 'increment_id = ?' => $orderId );
                $dataQuery = array( 'state' => Mage_Sales_Model_Order::STATE_PROCESSING );
                $write->update( $table, $dataQuery, $whereQuery );
            } else {
                $write->commit();

                $this->sendResponse( $isFromAsyncCallback, $result, $orderId );

                return;
            }

            $write->commit();
        } catch ( Exception $e ) {
            $write->rollback();
            Mage::log( "Transaction failed. Order status not updated", Zend_Log::ERR, self::LOG_FILE );
            $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );

            return;
        }

        if ( $result == "completed" ) {
            if( $status = Humm_HummPayments_Helper_OrderStatus::STATUS_CANCELED ){
                $order->setState(Mage_Sales_Model_Order::STATE_NEW, true, 'Order uncancelled by humm.', false );
                $order->setBaseDiscountCanceled(0);
                $order->setBaseShippingCanceled(0);
                $order->setBaseSubtotalCanceled(0);
                $order->setBaseTaxCanceled(0);
                $order->setBaseTotalCanceled(0);
                $order->setDiscountCanceled(0);
                $order->setShippingCanceled(0);
                $order->setSubtotalCanceled(0);
                $order->setTaxCanceled(0);
                $order->setTotalCanceled(0);

                $stockItems = [];
                $productIds = [];

                foreach ($order->getAllItems() as $item) {
                    /** @var $item Mage_Sales_Model_Order_Item */
                    $item->setQtyCanceled(0);
                    $item->setTaxCanceled(0);
                    $item->setHiddenTaxCanceled(0);
                    $item->save();

                    $stockItems[$item->getProductId()] = ['qty'=>$item->getQtyOrdered()];
                    $productIds[$item->getProductId()] = $item->getProductId();
                    $children   = $item->getChildrenItems();
                    if ($children) {
                        foreach ($children as $childItem) {
                            $productIds[$childItem->getProductId()] = $childItem->getProductId();
                        }
                    }
                }

                $stockModel = Mage::getSingleton('cataloginventory/stock');
                $itemsForReindex = $stockModel->registerProductsSale($stockItems);

                if (count($productIds)) {
                    Mage::getResourceSingleton('cataloginventory/indexer_stock')->reindexProducts($productIds);
                }

                $stockProductIds = array();
                foreach ($itemsForReindex as $item) {
                    $item->save();
                    $stockProductIds[] = $item->getProductId();
                }
                if (count($stockProductIds)) {
                    Mage::getResourceSingleton('catalog/product_indexer_price')->reindexProductIds($stockProductIds);
                }
                $order->save();
            }

            $orderState    = Mage_Sales_Model_Order::STATE_PROCESSING;
            $orderStatus   = Mage::getStoreConfig( 'payment/HummPayments/humm_approved_order_status' );
            $emailCustomer = Mage::getStoreConfig( 'payment/HummPayments/email_customer' );
            if ( ! $this->statusExists( $orderStatus ) ) {
                $orderStatus = $order->getConfig()->getStateDefaultStatus( $orderState );
            }

            $order->setState( $orderState, $orderStatus ? $orderStatus : true, $this->__( "Humm authorisation success. Transaction #$transactionId" ), $emailCustomer );
            $payment = $order->getPayment();
            $payment->setTransactionId( $transactionId );

            $payment->addTransaction( Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE );

            $payment->save();
            $order->save();

            if ( $emailCustomer ) {
                $order->sendNewOrderEmail();
            }

            $invoiceAutomatically = Mage::getStoreConfig( 'payment/HummPayments/automatic_invoice' );
            if ( $invoiceAutomatically ) {
                $this->invoiceOrder( $order );
            }
        } else {
            $order->addStatusHistoryComment( $this->__( "Order #" . ( $order->getId() ) . " was declined by humm. Transaction #$transactionId." ) );
            $order
                ->cancel()
                ->setStatus( Humm_HummPayments_Helper_OrderStatus::STATUS_DECLINED )
                ->addStatusHistoryComment( $this->__( "Order #" . ( $order->getId() ) . " was canceled by customer." ) );

            $order->save();
            // $this->restoreCart($order, true);
            $this->restoreCart( $order );
        }
        Mage::getSingleton( 'checkout/session' )->unsQuoteId();
        $this->sendResponse( $isFromAsyncCallback, $result, $orderId );

        return;
    }

    private function statusExists( $orderStatus ) {
        try {
            $orderStatusModel = Mage::getModel( 'sales/order_status' );
            if ( $orderStatusModel ) {
                $statusesResCol = $orderStatusModel->getResourceCollection();
                if ( $statusesResCol ) {
                    $statuses = $statusesResCol->getData();
                    foreach ( $statuses as $status ) {
                        if ( $orderStatus === $status["status"] ) {
                            return true;
                        }
                    }
                }
            }
        } catch ( Exception $e ) {
            Mage::log( "Exception searching statuses: " . ( $e->getMessage() ), Zend_Log::ERR, self::LOG_FILE );
        }

        return false;
    }

    private function sendResponse( $isFromAsyncCallback, $result, $orderId ) {
        if ( $isFromAsyncCallback ) {
            // if from POST request (from asynccallback)
            $jsonData = json_encode( [ "result" => $result, "order_id" => $orderId ] );
            $this->getResponse()->setHeader( 'Content-type', 'application/json' );
            $this->getResponse()->setBody( $jsonData );
        } else {
            // if from GET request (from browser redirect)
            if ( $result == "completed" ) {
                $this->_redirect( 'checkout/onepage/success', array( '_secure' => false ) );
            } else {
                $this->_redirect( 'checkout/onepage/failure', array( '_secure' => false ) );
            }
        }

        return;
    }

    private function invoiceOrder( Mage_Sales_Model_Order $order ) {

        if ( ! $order->canInvoice() ) {
            Mage::throwException( Mage::helper( 'core' )->__( 'Cannot create an invoice.' ) );
        }

        $invoice = Mage::getModel( 'sales/service_order', $order )->prepareInvoice();

        if ( ! $invoice->getTotalQty() ) {
            Mage::throwException( Mage::helper( 'core' )->__( 'Cannot create an invoice without products.' ) );
        }

        $invoice->setRequestedCaptureCase( Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE );
        $invoice->setTransactionId( $order->getPayment()->getTransactionId() );
        $invoice->register();
        $transactionSave = Mage::getModel( 'core/resource_transaction' )
                               ->addObject( $invoice )
                               ->addObject( $invoice->getOrder() );

        $transactionSave->save();
    }

    /**
     * Constructs a request payload to send to humm
     *
     * @param $order
     *
     * @return array
     * @throws Mage_Core_Model_Store_Exception
     */
    private function getPayload( $order ) {
        if ( $order == null ) {
            Mage::log( 'Unable to get order from last lodged order id. Possibly related to a failed database call.', Zend_Log::ALERT, self::LOG_FILE );
            $this->_redirect( 'checkout/onepage/error', array( '_secure' => false ) );
        }

        $shippingAddress = $order->getShippingAddress();
        $billingAddress  = $order->getBillingAddress();

        $billingAddressParts = preg_split( '/\r\n|\r|\n/', $billingAddress->getData( 'street' ) );
        $billingAddress0     = $billingAddressParts[0];
        $billingAddress1     = ( count( $billingAddressParts ) > 1 ) ? $billingAddressParts[1] : '';

        if ( ! empty( $shippingAddress ) ) {
            $shippingAddressParts     = preg_split( '/\r\n|\r|\n/', $shippingAddress->getData( 'street' ) );
            $shippingAddress0         = $shippingAddressParts[0];
            $shippingAddress1         = ( count( $shippingAddressParts ) > 1 ) ? $shippingAddressParts[1] : '';
            $shippingAddress_city     = $shippingAddress->getData( 'city' );
            $shippingAddress_region   = $shippingAddress->getData( 'region' );
            $shippingAddress_postcode = $shippingAddress->getData( 'postcode' );
        } else {
            $shippingAddress0         = "";
            $shippingAddress1         = "";
            $shippingAddress_city     = "";
            $shippingAddress_region   = "";
            $shippingAddress_postcode = "";
        }

        $orderId                = (int) $order->getRealOrderId();
        $cancel_signature_query = [
            "orderId"   => $orderId,
            "amount"    => $order->getTotalDue(),
            "email"     => $order->getData( 'customer_email' ),
            "firstname" => $order->getCustomerFirstname(),
            "lastname"  => $order->getCustomerLastname()
        ];
        $cancel_signature       = Humm_HummPayments_Helper_Crypto::generateSignature( $cancel_signature_query, $this->getApiKey() );
        $data                   = array(
            'x_currency'                   => str_replace( PHP_EOL, ' ', $order->getOrderCurrencyCode() ),
            'x_url_callback'               => str_replace( PHP_EOL, ' ', Humm_HummPayments_Helper_Data::getCompleteUrl() ),
            'x_url_complete'               => str_replace( PHP_EOL, ' ', Humm_HummPayments_Helper_Data::getCompleteUrl() ),
            'x_url_cancel'                 => str_replace( PHP_EOL, ' ', Humm_HummPayments_Helper_Data::getCancelledUrl( $orderId ) . "&signature=" . $cancel_signature ),
            'x_shop_name'                  => str_replace( PHP_EOL, ' ', Mage::app()->getStore()->getCode() ),
            'x_account_id'                 => str_replace( PHP_EOL, ' ', Mage::getStoreConfig( 'payment/HummPayments/merchant_number' ) ),
            'x_reference'                  => str_replace( PHP_EOL, ' ', $orderId ),
            'x_invoice'                    => str_replace( PHP_EOL, ' ', $orderId ),
            'x_amount'                     => str_replace( PHP_EOL, ' ', $order->getTotalDue() ),
            'x_customer_first_name'        => str_replace( PHP_EOL, ' ', $order->getCustomerFirstname() ),
            'x_customer_last_name'         => str_replace( PHP_EOL, ' ', $order->getCustomerLastname() ),
            'x_customer_email'             => str_replace( PHP_EOL, ' ', $order->getData( 'customer_email' ) ),
            'x_customer_phone'             => str_replace( PHP_EOL, ' ', $billingAddress->getData( 'telephone' ) ),
            'x_customer_billing_address1'  => $billingAddress0,
            'x_customer_billing_address2'  => $billingAddress1,
            'x_customer_billing_city'      => str_replace( PHP_EOL, ' ', $billingAddress->getData( 'city' ) ),
            'x_customer_billing_state'     => str_replace( PHP_EOL, ' ', $billingAddress->getData( 'region' ) ),
            'x_customer_billing_zip'       => str_replace( PHP_EOL, ' ', $billingAddress->getData( 'postcode' ) ),
            'x_customer_shipping_address1' => $shippingAddress0,
            'x_customer_shipping_address2' => $shippingAddress1,
            'x_customer_shipping_city'     => str_replace( PHP_EOL, ' ', $shippingAddress_city ),
            'x_customer_shipping_state'    => str_replace( PHP_EOL, ' ', $shippingAddress_region ),
            'x_customer_shipping_zip'      => str_replace( PHP_EOL, ' ', $shippingAddress_postcode ),
            'x_test'                       => 'false',
            'version_info'                 => 'Humm_' . (string) Mage::getConfig()->getNode()->modules->Humm_HummPayments->version . '_on_magento' . substr( Mage::getVersion(), 0, 4 )
        );

        if (!Mage::getStoreConfigFlag('payment/HummPayments/hide_versions')) {
            $data['version_info'] = 'Humm_' . (string) Mage::getConfig()->getNode()->modules->Humm_HummPayments->version . '_on_magento' . substr(Mage::getVersion(), 0, 4);
        }

        $apiKey                 = $this->getApiKey();
        $signature              = Humm_HummPayments_Helper_Crypto::generateSignature( $data, $apiKey );
        $data['x_signature']    = $signature;

        return $data;
    }

    /**
     * checks the quote for validity
     * @throws Mage_Api_Exception
     */
    private function validateQuote() {
        $specificCurrency = null;
        $order            = $this->getLastRealOrder();
        $total            = $order->getTotalDue();
        $title            = Humm_HummPayments_Helper_Data::getTitle();

        if ( $this->getSpecificCountry() == self::HUMM_AU_COUNTRY_CODE ) {
            if ( $title == 'Oxipay' && $total > 2100 ) {
                Mage::getSingleton( 'checkout/session' )->addError( "Oxipay doesn't support purchases over $2100." );

                return false;
            }
            $specificCurrency = self::HUMM_AU_CURRENCY_CODE;
        } elseif ( $this->getSpecificCountry() == self::HUMM_NZ_COUNTRY_CODE ) {
            if ( $title == 'Oxipay' && $total > 1500 ) {
                Mage::getSingleton( 'checkout/session' )->addError( "Oxipay doesn't support purchases over $1500." );

                return false;
            }
            $specificCurrency = self::HUMM_NZ_CURRENCY_CODE;
        }

        if ( $title == 'Oxipay' && $total < 20 ) {
            Mage::getSingleton( 'checkout/session' )->addError( "Oxipay doesn't support purchases less than $20." );

            return false;
        }

        if ( $order->getBillingAddress()->getCountry() != $this->getSpecificCountry() || $order->getOrderCurrencyCode() != $specificCurrency ) {
            Mage::getSingleton( 'checkout/session' )->addError( "Orders from this country are not supported by humm. Please select a different payment option." );

            return false;
        }

        if ( ! $order->isVirtual && $order->getShippingAddress()->getCountry() != $this->getSpecificCountry() ) {
            Mage::getSingleton( 'checkout/session' )->addError( "Orders shipped to this country are not supported by humm. Please select a different payment option." );

            return false;
        }

        return true;
    }

    /**
     * Get current checkout session
     * @return Mage_Core_Model_Abstract
     */
    private function getCheckoutSession() {
        return Mage::getSingleton( 'checkout/session' );
    }

    /**
     * Injects a self posting form to the page in order to kickoff humm checkout process
     *
     * @param $checkoutUrl
     * @param $payload
     */
    private function postToCheckout( $checkoutUrl, $payload ) {
        echo
        "<html>
            <body>
            <form id='form' action='$checkoutUrl' method='post'>";
        foreach ( $payload as $key => $value ) {
            echo "<input type='hidden' id='$key' name='$key' value='" . htmlspecialchars( $value, ENT_QUOTES ) . "'/>";
        }
        echo
        '</form>
            </body>';
        echo
        '<script>
                var form = document.getElementById("form");
                form.submit();
            </script>
        </html>';
    }

    /**
     * returns an Order object based on magento's internal order id
     *
     * @param $orderId
     *
     * @return Mage_Sales_Model_Order
     */
    private function getOrderById( $orderId ) {
        return Mage::getModel( 'sales/order' )->loadByIncrementId( $orderId );
    }

    /**
     * retrieve the merchants humm api key
     * @return mixed
     */
    private function getApiKey() {
        return Mage::getStoreConfig( 'payment/HummPayments/api_key' );
    }

    /**
     * Get specific country
     *
     * @return string
     */
    public function getSpecificCountry() {
        return Mage::getStoreConfig( 'payment/HummPayments/specificcountry' );
    }

    /**
     * retrieve the last order created by this session
     * @return null
     */
    private function getLastRealOrder() {
        $orderId = Mage::getSingleton( 'checkout/session' )->getLastRealOrderId();

        $order =
            ( $orderId )
                ? $this->getOrderById( $orderId )
                : null;

        return $order;
    }

    /**
     * Method is called when an order is cancelled by a customer. As a humm reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return $this
     * @throws Exception
     */
    private function cancelOrder( Mage_Sales_Model_Order $order ) {
        if ( ! $order->isCanceled() ) {
            $order
                ->cancel()
                ->setStatus( Humm_HummPayments_Helper_OrderStatus::STATUS_CANCELED )
                ->addStatusHistoryComment( $this->__( "Order #" . ( $order->getId() ) . " was canceled by customer." ) );
        }

        return $this;
    }

    /**
     * Loads the cart with items from the order
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return $this
     */
    private function restoreCart( Mage_Sales_Model_Order $order, $refillStock = false ) {
        // return all products to shopping cart
        $quoteId = $order->getQuoteId();
        $quote   = Mage::getModel( 'sales/quote' )->load( $quoteId );

        if ( $quote->getId() ) {
            $quote->setIsActive( 1 );
            if ( $refillStock ) {
                $items = $this->_getProductsQty( $quote->getAllItems() );
                if ( $items != null ) {
                    Mage::getSingleton( 'cataloginventory/stock' )->revertProductsSale( $items );
                }
            }

            $quote->setReservedOrderId( null );
            $quote->save();
            $this->getCheckoutSession()->replaceQuote( $quote );
        }

        return $this;
    }

    /**
     * Prepare array with information about used product qty and product stock item
     * result is:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     *
     * @param array $relatedItems
     *
     * @return array
     */
    protected function _getProductsQty( $relatedItems ) {
        $items = array();
        foreach ( $relatedItems as $item ) {
            $productId = $item->getProductId();
            if ( ! $productId ) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ( $children ) {
                foreach ( $children as $childItem ) {
                    $this->_addItemToQtyArray( $childItem, $items );
                }
            } else {
                $this->_addItemToQtyArray( $item, $items );
            }
        }

        return $items;
    }


    /**
     * Adds stock item qty to $items (creates new entry or increments existing one)
     * $items is array with following structure:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     *
     * @param Mage_Sales_Model_Quote_Item $quoteItem
     * @param array &$items
     */
    protected function _addItemToQtyArray( $quoteItem, &$items ) {
        $productId = $quoteItem->getProductId();
        if ( ! $productId ) {
            return;
        }
        if ( isset( $items[ $productId ] ) ) {
            $items[ $productId ]['qty'] += $quoteItem->getTotalQty();
        } else {
            $stockItem = null;
            if ( $quoteItem->getProduct() ) {
                $stockItem = $quoteItem->getProduct()->getStockItem();
            }
            $items[ $productId ] = array(
                'item' => $stockItem,
                'qty'  => $quoteItem->getTotalQty()
            );
        }
    }
}
