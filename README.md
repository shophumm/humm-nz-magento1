# humm-magento-1.x [![Build status](https://ci.appveyor.com/api/projects/status/t71e6r0lvsfriwm0/branch/master?svg=true)](https://ci.appveyor.com/project/humm/humm-magento-1-x/branch/master)

## Installation

To deploy the plugin, clone this repo, and copy the following plugin files and folders into the corresponding folder under the Magento root directory.

```bash
/app/code/community/Humm/
/app/design/frontend/base/default/template/HummPayments/
/app/design/adminhtml/base/default/template/HummPayments/
/app/etc/modules/Humm_HummPayments.xml

/skin/frontend/base/default/images/Humm/
/skin/adminhtml/base/default/images/Humm/
```

Once copied - you should be able to see the Humm plugin loaded in magento (note this may require a cache flush/site reload)

Please find more details from 
http://docs.shophumm.com.au/platforms/magento_1/  (for Australia)

## Varnish cache exclusions

A rule must be added to varnish configuration for any magento installation running behind a varnish backend. (Or any other proxy cache) to invalidate any payment controller action.

Must exclude: `.*HummPayments.`* from all caching.


New Version Release 

copy follow files 

/app/code/community/Humm/
/app/design/frontend/base/default/template/humm/payments/
/app/design/frontend/base/default/layout/humm/
/app/design/adminhtml/base/default/template/HummPayments/
/app/design/adminhtml/default/default/template/humm/payments
/app/etc/modules/Humm_HummPayments.xml

/skin/frontend/base/default/images/Humm/
/skin/adminhtml/base/default/images/Humm/


in short, copy app and skin to the magento1 website same name folders 

New functions 


1. Rebuild API call 
2. Add product widget 
3. Add cart widget
4. Rebuild admin console, style is same to Woo and Magento2
5. Add API timeout configuration 
6. Refund  function 
7. Support NZ and AU stores 
8. Force Humm functions auto swift 
9. Log file rebuild 
10.dynamic widgets show position 
11.remove unnecessary hardcoded
12.redesign cancel order functions


