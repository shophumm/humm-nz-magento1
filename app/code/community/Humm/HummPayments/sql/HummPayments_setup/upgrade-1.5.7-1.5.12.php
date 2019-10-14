<?php

$installer = $this;

$installer->startSetup();

$carry_over_list = [
    'active',
    'merchant_number',
    'api_key',
    'gateway_url',
    'is_testing',
    'automatic_invoice',
    'email_customer',
    'min_order_total',
    'max_order_total',
    'specificcountry',
    'sort_order',
];

//change existing oxipay settings to humm settings
foreach ($carry_over_list as $item){
    $installer->run( "UPDATE `{$this->getTable('core_config_data')}` set `path`= 'payment/HummPayments/{$item}' where `path`='payment/oxipayments/{$item}'" );
}

//another carry over setting
$installer->run( "UPDATE `{$this->getTable('core_config_data')}` set `path`= 'payment/HummPayments/humm_approved_order_status' where `path`='payment/oxipayments/oxipay_approved_order_status'" );

$installer->endSetup();