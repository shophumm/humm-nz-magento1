<?php
require_once dirname(__FILE__) . '/../Helper/Crypto.php';
require_once dirname(__FILE__) . '/../Helper/Data.php';
require_once dirname(__FILE__) . '/../Helper/DataHumm.php';

/**
 * Class Humm_Payments_PaymentController
 */
class Humm_Payments_PaymentController extends Mage_Core_Controller_Front_Action
{
    const LOG_FILE = 'humm.log';
    const HUMM_AU_CURRENCY_CODE = 'AUD';
    const HUMM_AU_COUNTRY_CODE = 'AU';
    const HUMM_NZ_CURRENCY_CODE = 'NZD';
    const HUMM_NZ_COUNTRY_CODE = 'NZ';

    /**
     * Begin processing payment via humm
     */
    public function startAction()
    {
        if ($this->validateQuote()) {
            try {
                $order = $this->getLastRealOrder();
                $payload = $this->getPayload($order);
                if (in_array($order->getState(), array(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    Mage_Sales_Model_Order::STATE_COMPLETE,
                    Mage_Sales_Model_Order::STATE_CLOSED,
                    Mage_Sales_Model_Order::STATE_CANCELED,
                    Mage_Sales_Model_Order::STATE_HOLDED,
                    Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW
                ))) {
                    $this->_redirect('checkout/cart');
                    return;
                }
                $order->setState(Mage_Sales_Model_Order::STATE_NEW, true, 'Humm_authorisation');
                $orderStatus = $order->getConfig()->getStateDefaultStatus(Mage_Sales_Model_Order::STATE_NEW);
                $order->setStatus($orderStatus);
                $order->save();
                $this->postToCheckoutTemplate(Humm_Payments_Helper_DataHumm::getCheckoutUrl(), $payload);
            } catch (Exception $ex) {
                Mage::log('An exception was encountered in humm_payments/paymentcontroller: ' . $ex->getMessage(), Zend_Log::ERR, self::LOG_FILE);
                Mage::log($ex->getTraceAsString(), Zend_Log::ERR, self::LOG_FILE);
                $this->getCheckoutSession()->addError($this->__('Unable to start humm Checkout.'));
            }
        } else {
            $this->restoreCart($this->getLastRealOrder());
            $this->_redirect('checkout/cart');
            $order = $this->getLastRealOrder();
            $this->cancelOrder($order);
            Mage::getResourceSingleton('sales/order')->delete($order);
        }
    }

    /**
     * @return bool
     */
    protected function validateQuote()
    {
        $order = $this->getLastRealOrder();
        $total = $order->getTotalDue();
        $title = Humm_Payments_Helper_DataHumm::getTitle();
        $orderId = $order->getIncrementId();
        $specificCurrency = Mage::getStoreConfig('payment/humm_payments/country_currency/allowed_currencies');
        $minAmount = Mage::getStoreConfig('payment/humm_payments/min_order_total');

        if ($total <= Mage::getStoreConfig('payment/humm_payments/min_order_total')) {
            Mage::getSingleton('checkout/session')->addError("Payment does not support purchases less than$" . $minAmount);
            Mage::log('Payment less than' . $orderId . '|' . $minAmount, self::LOG_FILE, 7);
            return false;
        }

        if ($order->getBillingAddress()->getCountry() != $this->getSpecificCountry() || $order->getOrderCurrencyCode() != $specificCurrency) {
            Mage::getSingleton('checkout/session')->addError("Orders from this country are not supported by humm. Please select a different payment option.");
            return false;
        }

        if (!$order->isVirtual && $order->getShippingAddress()->getCountry() != $this->getSpecificCountry()) {
            Mage::getSingleton('checkout/session')->addError("Orders shipped to this country are not supported by humm. Please select a different payment option.");
            return false;
        }

        return true;
    }

    /**
     * retrieve the last order created by this session
     * @return null
     */
    protected function getLastRealOrder()
    {
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order =
            ($orderId)
                ? $this->getOrderById($orderId)
                : null;

        return $order;
    }

    /**
     * returns an Order object based on magento's internal order id
     *
     * @param $orderId
     *
     * @return Mage_Sales_Model_Order
     */
    protected function getOrderById($orderId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($orderId);
    }

    /**
     * Get specific country
     *
     * @return string
     */
    public function getSpecificCountry()
    {
        return Mage::getStoreConfig('payment/humm_payments/country_currency/specific_countries');
    }

    /**
     * Constructs a request payload to send to humm
     *
     * @param $order
     *
     * @return array
     * @throws Mage_Core_Model_Store_Exception
     */
    private function getPayload($order)
    {

        try {
            if ($order == null) {
                Mage::log('Unable to get order from last lodged order id. Possibly related to a failed database call.', Zend_Log::ALERT, self::LOG_FILE);
                $this->_redirect('checkout/onepage/error', array('_secure' => false));
            }
            $shippingAddress = $order->getShippingAddress();
            $billingAddress = $order->getBillingAddress();
            $billingAddressParts = preg_split('/\r\n|\r|\n/', $billingAddress->getData('street'));
            $billingAddress0 = $billingAddressParts[0];
            $billingAddress1 = (count($billingAddressParts) > 1) ? $billingAddressParts[1] : '';
            if (!empty($shippingAddress)) {
                $shippingAddressParts = preg_split('/\r\n|\r|\n/', $shippingAddress->getData('street'));
                $shippingAddress0 = $shippingAddressParts[0];
                $shippingAddress1 = (count($shippingAddressParts) > 1) ? $shippingAddressParts[1] : '';
                $shippingAddress_city = $shippingAddress->getData('city');
                $shippingAddress_region = $shippingAddress->getData('region');
                $shippingAddress_postcode = $shippingAddress->getData('postcode');
            } else {
                $shippingAddress0 = "";
                $shippingAddress1 = "";
                $shippingAddress_city = "";
                $shippingAddress_region = "";
                $shippingAddress_postcode = "";
            }
            $orderId = (int)$order->getRealOrderId();
            $cancel_signature_query = [
                "orderId" => $orderId,
                "amount" => $order->getTotalDue(),
                "email" => $order->getData('customer_email'),
                "firstname" => $order->getCustomerFirstname(),
                "lastname" => $order->getCustomerLastname()
            ];

            if (intval(Mage::getStoreConfig('payment/humm_payments/api/timeout'))) {
                $timeOut = intval(Mage::getStoreConfig('payment/humm_payments/api/timeout'));
            } else {
                $timeOut = 120;
            }
            $cancel_signature = Humm_Payments_Helper_Crypto::generateSignature($cancel_signature_query, $this->getApiKey());
            $data = array(
                'x_currency' => str_replace(PHP_EOL, ' ', $order->getOrderCurrencyCode()),
                'x_url_callback' => str_replace(PHP_EOL, ' ', Humm_Payments_Helper_DataHumm::getCompleteUrl()),
                'x_url_complete' => str_replace(PHP_EOL, ' ', Humm_Payments_Helper_DataHumm::getCompleteUrl()),
                'x_url_cancel' => str_replace(PHP_EOL, ' ', Humm_Payments_Helper_DataHumm::getCancelledUrl($orderId) . "&signature=" . $cancel_signature),
                'x_shop_name' => str_replace(PHP_EOL, ' ', Mage::app()->getStore()->getCode()),
                'x_account_id' => str_replace(PHP_EOL, ' ', Mage::getStoreConfig('payment/humm_payments/public_key')),
                'x_reference' => str_replace(PHP_EOL, ' ', $orderId),
                'x_invoice' => str_replace(PHP_EOL, ' ', $orderId),
                'x_amount' => str_replace(PHP_EOL, ' ', $order->getTotalDue()),
                'x_customer_first_name' => str_replace(PHP_EOL, ' ', $order->getCustomerFirstname()),
                'x_customer_last_name' => str_replace(PHP_EOL, ' ', $order->getCustomerLastname()),
                'x_customer_email' => str_replace(PHP_EOL, ' ', $order->getData('customer_email')),
                'x_customer_phone' => str_replace(PHP_EOL, ' ', $billingAddress->getData('telephone')),
                'x_customer_billing_address1' => $billingAddress0,
                'x_customer_billing_address2' => $billingAddress1,
                'x_customer_billing_city' => str_replace(PHP_EOL, ' ', $billingAddress->getData('city')),
                'x_customer_billing_state' => str_replace(PHP_EOL, ' ', $billingAddress->getData('region')),
                'x_customer_billing_zip' => str_replace(PHP_EOL, ' ', $billingAddress->getData('postcode')),
                'x_customer_shipping_address1' => $shippingAddress0,
                'x_customer_shipping_address2' => $shippingAddress1,
                'x_customer_shipping_city' => str_replace(PHP_EOL, ' ', $shippingAddress_city),
                'x_customer_shipping_state' => str_replace(PHP_EOL, ' ', $shippingAddress_region),
                'x_customer_shipping_zip' => str_replace(PHP_EOL, ' ', $shippingAddress_postcode),
                'x_test' => 'false',
                'x_transaction_timeout' => $timeOut
            );
            if (!Mage::getStoreConfigFlag('payment/humm_payments/hide_versions')) {
                $data['version_info'] = 'Humm_' . (string)Mage::getConfig()->getNode()->modules->Humm_Payments->version . '_on_magento' . substr(Mage::getVersion(), 0, 4);
            }
            $apiKey = Mage::helper('core')->decrypt($this->getApiKey());
            $signature = Humm_Payments_Helper_Crypto::generateSignature($data, $apiKey);
            $data['x_signature'] = $signature;
            Mage::log("send-data.." . $data['x_reference'], 7, self::LOG_FILE);
            return $data;
        } catch (Mage_Core_Model_Store_Exception $e) {
            Mage::log(sprintf("Payload error%s OrderId %s", $e->getMessage(), $order->getRealOrderId()), 7, self::LOG_FILE);
        }
    }

    /**
     * retrieve the merchants humm api key
     * @return mixed
     */
    protected function getApiKey()
    {
        return Mage::getStoreConfig('payment/humm_payments/private_key');
    }

    /**
     * @param $checkoutUrl
     * @param $payload
     */
    protected function postToCheckoutTemplate($checkoutUrl, $payload)
    {

        try {
            $formItem = '';
            $beforeForm = sprintf("%s", "<html> <body> <form id='form' action='$checkoutUrl' method='post'>");
            foreach ($payload as $key => $value) {
                $formItem = sprintf("%s %s", $formItem, sprintf("<input type='hidden' id='%s' name='%s' value='%s'/>", $key, $key, htmlspecialchars($value, ENT_QUOTES)));
            }
            $afterForm = sprintf("%s", '</form> </body> <script> var form = document.getElementById("form");form.submit();</script></html>');
            $postForm  = sprintf("%s %s %s", $beforeForm, $formItem, $afterForm);
            Mage::log(sprintf("PostFormTemplate: %s", $postForm), 7, self::LOG_FILE);
            echo $postForm;
        } catch (Exception $e) {
            Mage::log(sprintf("PostFormErrors=%s", $e->getMessage()), 4, self::LOG_FILE);
        }

    }

    /**
     * Get current checkout session
     * @return Mage_Core_Model_Abstract
     */
    protected function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param bool $refillStock
     * @return $this
     * @throws Exception
     */
    protected function restoreCart(Mage_Sales_Model_Order $order, $refillStock = false)
    {

        $quoteId = $order->getQuoteId();
        $quote = Mage::getModel('sales/quote')->load($quoteId);

        if ($quote->getId()) {
            $quote->setIsActive(1);
            if ($refillStock) {
                $items = $this->_getProductsQty($quote->getAllItems());
                if ($items != null) {
                    Mage::getSingleton('cataloginventory/stock')->revertProductsSale($items);
                }
            }

            $quote->setReservedOrderId(null);
            $quote->save();
            $this->getCheckoutSession()->replaceQuote($quote);
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
    protected function _getProductsQty($relatedItems)
    {
        $items = array();
        foreach ($relatedItems as $item) {
            $productId = $item->getProductId();
            if (!$productId) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ($children) {
                foreach ($children as $childItem) {
                    $this->_addItemToQtyArray($childItem, $items);
                }
            } else {
                $this->_addItemToQtyArray($item, $items);
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
    protected function _addItemToQtyArray($quoteItem, &$items)
    {
        $productId = $quoteItem->getProductId();
        if (!$productId) {
            return;
        }
        if (isset($items[$productId])) {
            $items[$productId]['qty'] += $quoteItem->getTotalQty();
        } else {
            $stockItem = null;
            if ($quoteItem->getProduct()) {
                $stockItem = $quoteItem->getProduct()->getStockItem();
            }
            $items[$productId] = array(
                'item' => $stockItem,
                'qty' => $quoteItem->getTotalQty()
            );
        }
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @throws Exception
     * @throws Mage_Core_Exception
     */
    protected function cancelOrder(Mage_Sales_Model_Order $order)
    {
        if ($order->getId() && $order->getState() != Mage_Sales_Model_Order::STATE_CANCELED) {
            $order->registerCancellation("Order #" . ($order->getId()) . " was canceled by Humm Payment or customer.")->save();
        }
    }

    /**
     * Cancel an order given an order id
     */
    public function cancelAction()
    {
        $orderId = $this->getRequest()->get('orderId');
        $order = $this->getOrderById($orderId);

        if ($order && $order->getId()) {
            $cancel_signature_query = [
                "orderId" => $orderId,
                "amount" => $order->getTotalDue(),
                "email" => $order->getData('customer_email'),
                "firstname" => $order->getCustomerFirstname(),
                "lastname" => $order->getCustomerLastname()
            ];
            $cancel_signature = Humm_Payments_Helper_Crypto::generateSignature($cancel_signature_query, $this->getApiKey());
            $signatureValid = ($this->getRequest()->get('signature') == $cancel_signature);
            if (!$signatureValid) {
                Mage::log('Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE);
                $this->_redirect('checkout/onepage/error', array('_secure' => false));

                return;
            }
            Mage::log(
                'Requested order cancellation by customer from Humm_payment. OrderId: ' . $order->getIncrementId(),
                Zend_Log::DEBUG,
                self::LOG_FILE
            );
            $this->cancelOrder($order);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     *
     * callback - humm calls this once the payment process has been completed.
     */
    public function completeAction()
    {
        $isValid = Humm_Payments_Helper_Crypto::isValidSignature($this->getRequest()->getParams(), Mage::helper('core')->decrypt($this->getApiKey()));
        $result = $this->getRequest()->get("x_result");
        $orderId = $this->getRequest()->get("x_reference");
        $transactionId = $this->getRequest()->get("x_gateway_reference");
        Mage::log(sprintf("[Response---:%s] [method = %s]", json_encode($this->getRequest()->getParams()), $this->getRequest()->getMethod()), 7, self::LOG_FILE);


        if (!$isValid) {
            Mage::log('Possible site forgery detected: invalid response signature.', Zend_Log::ALERT, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure' => false));
            return;
        }

        if (!$orderId) {
            Mage::log("Humm returned a null order id. This may indicate an issue with the humm payment gateway.", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure' => false));
            return;
        }

        $order = $this->getOrderById($orderId);
        $isFromAsyncCallback = (strtoupper($this->getRequest()->getMethod() == "POST")) ? true : false;

        if (!$order) {
            Mage::log("Humm returned an id for an order that could not be retrieved: $orderId", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure' => false));
            return;
        }
        if (get_class($order) !== 'Mage_Sales_Model_Order') {
            Mage::log("The instance of order returned is an unexpected type.", Zend_Log::ERR, self::LOG_FILE);
        }

        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sales/order');

        try {
            $write->beginTransaction();
            $select = $write->select()
                ->forUpdate()
                ->from(array('t' => $table),
                    array('state', 'status'))
                ->where('increment_id = ?', $orderId);
            $state_and_status = $write->fetchRow($select);
            $state = $state_and_status['state'];
            $status = $state_and_status['status'];

            if ($state === Mage_Sales_Model_Order::STATE_NEW) {
                $whereQuery = array('increment_id = ?' => $orderId);
                if ($result == "completed") {
                    $dataQuery = array('state' => Mage_Sales_Model_Order::STATE_PROCESSING);
                } else {
                    $dataQuery = array('state' => Mage_Sales_Model_Order::STATE_CANCELED);
                }
                $write->update($table, $dataQuery, $whereQuery);
            } elseif ($status === Humm_Payments_Helper_OrderStatus::STATUS_CANCELED && $result == "completed") {
                $whereQuery = array('increment_id = ?' => $orderId);
                $dataQuery = array('state' => Mage_Sales_Model_Order::STATE_PROCESSING);
                $write->update($table, $dataQuery, $whereQuery);
            } else {
                $write->commit();
                $this->sendResponse($isFromAsyncCallback, $result, $state, $orderId);
                return;
            }
            $write->commit();
        } catch (Exception $e) {
            $write->rollback();
            Mage::log("Transaction failed. Order status not updated", Zend_Log::ERR, self::LOG_FILE);
            $this->_redirect('checkout/onepage/error', array('_secure' => false));
            return;
        }

        if ($result == "completed") {
            if ($status === Humm_Payments_Helper_OrderStatus::STATUS_CANCELED) {
                $order->setState(Mage_Sales_Model_Order::STATE_NEW, true, 'Order uncancelled by humm.', false);
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
                    $stockItems[$item->getProductId()] = ['qty' => $item->getQtyOrdered()];
                    $productIds[$item->getProductId()] = $item->getProductId();
                    $children = $item->getChildrenItems();
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

            $orderState = Mage_Sales_Model_Order::STATE_PROCESSING;
            $orderStatus = Mage::getStoreConfig('payment/humm_payments/humm_approved_order_status');
            $emailCustomer = Mage::getStoreConfig('payment/humm_payments/email_customer');
            if (!$this->statusExists($orderStatus)) {
                $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
            }

            $order->setState($orderState, $orderStatus ? $orderStatus : true, $this->__("Humm authorisation success. Transaction #$transactionId"), $emailCustomer);
            $payment = $order->getPayment();
            $payment->setTransactionId($transactionId);

            $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);

            $payment->save();
            $order->save();

            if ($emailCustomer) {
                $order->sendNewOrderEmail();
            }

            $invoiceAutomatically = Mage::getStoreConfig('payment/humm_payments/automatic_invoice');
            if ($invoiceAutomatically) {
                $this->invoiceOrder($order);
            }
        } else {
            $order->addStatusHistoryComment($this->__("Order #" . ($order->getId()) . " was declined by humm. Transaction #$transactionId."));
            $order
                ->cancel()
                ->setStatus(Humm_Payments_Helper_OrderStatus::STATUS_DECLINED)
                ->addStatusHistoryComment($this->__("Order #" . ($order->getId()) . " was canceled by customer."));

            $order->save();
            // $this->restoreCart($order, true);
            $this->restoreCart($order);
        }
        Mage::getSingleton('checkout/session')->unsQuoteId();
        $this->sendResponse($isFromAsyncCallback, $result, $order->getState(), $orderId);
        return;
    }

    private function sendResponse($isFromAsyncCallback, $result, $state, $orderId)
    {
        if ($isFromAsyncCallback) {
            // if from POST request (from asynccallback)
            $jsonData = json_encode(["result" => $state, "order_id" => $orderId]);
            $this->getResponse()->setHeader('Content-type', 'application/json');
            $this->getResponse()->setBody($jsonData);
        } else {
            // if from GET request (from browser redirect)
            if ($result == "completed") {
                $this->_redirect('checkout/onepage/success', array('_secure' => false));
            } else {
                $this->_redirect('checkout/onepage/failure', array('_secure' => false));
            }
        }

        return;
    }

    private function statusExists($orderStatus)
    {
        try {
            $orderStatusModel = Mage::getModel('sales/order_status');
            if ($orderStatusModel) {
                $statusesResCol = $orderStatusModel->getResourceCollection();
                if ($statusesResCol) {
                    $statuses = $statusesResCol->getData();
                    foreach ($statuses as $status) {
                        if ($orderStatus === $status["status"]) {
                            return true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Mage::log("Exception searching statuses: " . ($e->getMessage()), Zend_Log::ERR, self::LOG_FILE);
        }

        return false;
    }

    private function invoiceOrder(Mage_Sales_Model_Order $order)
    {

        if (!$order->canInvoice()) {
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
        }

        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

        if (!$invoice->getTotalQty()) {
            Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
        }

        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
        $invoice->setTransactionId($order->getPayment()->getTransactionId());
        $invoice->register();
        $transactionSave = Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder());

        $transactionSave->save();
    }
}
