
## Installation

To deploy the plugin, clone this repo, and copy the following plugin files and folders into the corresponding folder under the Magento root directory.


New Version Release 

Copy follow files 

/app/code/community/Humm/
/app/design/frontend/base/default/template/humm/payments/
/app/design/frontend/base/default/layout/humm/
/app/design/adminhtml/base/default/template/HummPayments/
/app/design/adminhtml/default/default/template/humm/payments
/app/etc/modules/Humm_HummPayments.xml

/skin/frontend/base/default/images/Humm/
/skin/adminhtml/base/default/images/Humm/


To conclude,copy app and skin to the same name folders of magento1 website

New functions 

1. Rebuild API call 
2. Add product widget 
3. Add cart widget
4. Rebuild admin console, style is same to Woo and Magento2
5. Add API timeout configuration 
6. Refund  function 
7. Support NZ and AU stores 
8. Force Humm function auto swift from Oxipay to Humm
9. Log file rebuild 
10.Dynamic widgets show position (TBC)
11.Remove unnecessary hardcoded
12.Redesign cancel order function (cancel order rolls back stock of inventory)
13.Add Humm orders menu in the admin panel -> sales-> humm orders, show order status changing history 
14.Add HummCron.php crontab modules to clean redundancy pending orders 
15.Clean span of days configure in the admin, enable/disable configure in the admin
16.Crontab time set in the config.xml default is  */30 * * * *




