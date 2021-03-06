<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package     Fooman_Jirafe
 * @copyright   Copyright (c) 2010 Jirafe Inc (http://www.jirafe.com)
 * @copyright   Copyright (c) 2010 Fooman Limited (http://www.fooman.co.nz)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Fooman_Jirafe_Model_Observer
{

    protected function _initPiwikTracker ($storeId)
    {
        $siteId = Mage::helper('foomanjirafe')->getStoreConfig('site_id', $storeId);
        $appToken = Mage::helper('foomanjirafe')->getStoreConfig('app_token');
        $siteId = Mage::helper('foomanjirafe')->getStoreConfig('site_id', $storeId);

        $jirafePiwikUrl = 'http://' . Mage::getModel('foomanjirafe/jirafe')->getPiwikBaseUrl();
        $piwikTracker = new Fooman_Jirafe_Model_JirafeTracker($siteId, $jirafePiwikUrl);
        $piwikTracker->setTokenAuth($appToken);
        $piwikTracker->setVisitorId($piwikTracker->getVisitorId());
        $piwikTracker->disableCookieSupport();
        $piwikTracker->setAsyncFlag(true);

        return $piwikTracker;
    }

    /**
     * save Piwik visitorId and attributionInfo to order db table
     * for later use
     *
     * @param $observer
     */
    public function salesConvertQuoteToOrder ($observer)
    {
        Mage::helper('foomanjirafe')->debug('salesConvertQuoteToOrder');
        $order = $observer->getOrder();
        /* @var $order Mage_Sales_Model_Order */
        $piwikTracker = $this->_initPiwikTracker($order->getStoreId());
        if (Mage::getDesign()->getArea() == 'frontend') {
            Mage::helper('foomanjirafe')->debug('salesConvertQuoteToOrder Frontend');
            $order->setJirafePlacedFromFrontend(true);
        }
        $order->setJirafeVisitorId($piwikTracker->getVisitorId());
        $order->setJirafeAttributionData($piwikTracker->getAttributionInfo());
        $order->setJirafeIsNew(true);     
    }

    /**
     * salesOrderSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see salesOrderSaveCommitAfter
     * @param type $observer 
     */
    public function salesOrderSaveAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->salesOrderSaveCommitAfter($observer);
        }
    }    
    
    /**
     * Track piwik goals for orders
     * TODO: this could be made configurable based on payment method used
     *
     * @param $observer
     */
    public function salesOrderSaveCommitAfter ($observer)
    {
        Mage::helper('foomanjirafe')->debug('salesOrderSaveCommitAfter');
        $order = $observer->getOrder();    

        //track only orders that are just being converted from a quote
        if($order->getJirafeIsNew()) {
            $piwikTracker = $this->_initPiwikTracker($order->getStoreId());
            $piwikTracker->setCustomVariable(1, 'U', Fooman_Jirafe_Block_Js::VISITOR_CUSTOMER);
            $piwikTracker->setCustomVariable(5, 'orderId', $order->getIncrementId());
            $piwikTracker->setIp($order->getRemoteIp());

            // this observer can be potentially be triggered via a backend action
            // it is safer to set the visitor id from the order (if available)
            if ($order->getJirafeVisitorId()) {
                $piwikTracker->setVisitorId($order->getJirafeVisitorId());
            }

            if ($order->getJirafeAttributionData()) {
                $piwikTracker->setAttributionInfo($order->getJirafeAttributionData());
            }

            try {
                Mage::helper('foomanjirafe')->debug($order->getIncrementId().': '.$order->getJirafeVisitorId().' '.$order->getBaseGrandTotal());
                $checkoutGoalId = Mage::helper('foomanjirafe')->getStoreConfig('checkout_goal_id', $order->getStoreId());

                $this->_addEcommerceItems($piwikTracker, Mage::getModel('sales/quote')->load($order->getQuoteId()));
                $piwikTracker->doTrackEcommerceOrder(
                        $order->getIncrementId(),
                        $order->getBaseGrandTotal(),
                        $order->getBaseSubtotal(),
                        $order->getBaseTaxAmount(),
                        $order->getBaseShippingAmount(),
                        $order->getBaseDiscountAmount()
                );
                $order->unsJirafeIsNew();

            } catch (Exception $e) {
                Mage::logException($e);            
            }
        }  
    }

    /**
     * Check fields in the user object to see if we should run sync
     * use POST data to identify update to existing users
     * only call sync if relevant data has changed
     *
     * @param $observer
     */
    public function adminUserSaveBefore($observer)
    {
        Mage::helper('foomanjirafe')->debug('adminUserSaveBefore');
        $user = $observer->getEvent()->getObject();
        if (Mage::registry('foomanjirafe_sync') || Mage::registry('foomanjirafe_upgrade')) {
            //to prevent a password change unset it here for pre 1.4.0.0
            if (version_compare(Mage::getVersion(), '1.4.0.0') < 0) {
                $user->unsPassword();
            }
            return;
        }

        $jirafeUserId = $user->getJirafeUserId();
        $jirafeToken = $user->getJirafeUserToken();

        $jirafeSendEmail = Mage::app()->getRequest()->getPost('jirafe_send_email');
        $jirafeDashboardActive = Mage::app()->getRequest()->getPost('jirafe_dashboard_active');        
        $jirafeEmailReportType = Mage::app()->getRequest()->getPost('jirafe_email_report_type');
        $jirafeEmailSuppress = Mage::app()->getRequest()->getPost('jirafe_email_suppress');
     
        // Check to see if some user fields have changed
        if (!$user->getId() ||
            $user->dataHasChangedFor('firstname') ||
            $user->dataHasChangedFor('username') ||
            $user->dataHasChangedFor('email') ||
            empty($jirafeUserId) ||
            empty($jirafeToken)) {
            if (!Mage::registry('foomanjirafe_sync')) {
                Mage::register('foomanjirafe_sync', true);
            }
        }
        
        if ($jirafeSendEmail != $user->getJirafeSendEmail()) {
            $user->setJirafeSendEmail($jirafeSendEmail);
            $user->setDataChanges(true);
        }
        if ($jirafeDashboardActive != $user->getJirafeDashboardActive() && $jirafeDashboardActive != null) {
            $user->setJirafeDashboardActive($jirafeDashboardActive);
            $user->setDataChanges(true);
        }        
        if ($jirafeEmailReportType != $user->getJirafeEmailReportType()) {
            $user->setJirafeEmailReportType($jirafeEmailReportType);
            $user->setDataChanges(true);
        }
        if ($jirafeEmailSuppress != $user->getJirafeEmailSuppress()) {
            $user->setJirafeEmailSuppress($jirafeEmailSuppress);
            $user->setDataChanges(true);
        }
    }

    
    /**
     * adminUserSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see adminUserSaveCommitAfter
     * @param type $observer 
     */
    public function adminUserSaveAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->adminUserSaveCommitAfter($observer);
        }
    }    
    
    /**
     * Check to see if we need to sync.  If so, do it.
     *
     * @param $observer
     */
    public function adminUserSaveCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('adminUserSaveAfter');
        if (Mage::registry('foomanjirafe_sync')) {
            Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
        }
    }

    /**
     * adminUserSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see adminUserDeleteCommitAfter
     * @param type $observer 
     */
    public function adminUserDeleteAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->adminUserDeleteCommitAfter($observer);
        }
    }   
    
    /**
     * We need to sync every time after we delete a user
     *
     * @param $observer
     */
    public function adminUserDeleteCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('adminUserDeleteAfter');
        Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
    }

    /**
     * Check fields in the store object to see if we should run sync
     * only call sync if relevant data has changed
     *
     * @param $observer
     */
    public function storeSaveBefore($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeSaveBefore');
        $store = $observer->getEvent()->getStore();
        // If the object is new, or has any data changes, sync
        if (!$store->getId() 
            || $store->hasDataChanges()                         //works for Magento 1.4.1+
            || $store->dataHasChangedFor('is_active')
            || $store->dataHasChangedFor('name')
            || $store->dataHasChangedFor('code')
            || $store->dataHasChangedFor('group_id')
            ) {
            if (!Mage::registry('foomanjirafe_sync')) {
                Mage::register('foomanjirafe_sync', true);
            }
        }
    }

    /**
     * storeSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see storeSaveCommitAfter
     * @param type $observer 
     */
    public function storeSaveAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->storeSaveCommitAfter($observer);
        }
    }     
    
    /**
     * Check to see if we need to sync.  If so, do it.
     *
     * @param $observer
     */
    public function storeSaveCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeSaveAfter');
        if (Mage::registry('foomanjirafe_sync')) {
            Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
        }
    }

    
    /**
     * storeDeleteCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see storeDeleteCommitAfter
     * @param type $observer 
     */
    public function storeDeleteAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->storeDeleteCommitAfter($observer);
        }
    }    
    
    /**
     * We need to sync every time after we delete a store
     *
     * @param $observer
     */
    public function storeDeleteCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeDeleteCommitAfter');
        Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
    }

    /**
     * websiteDeleteCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see websiteDeleteCommitAfter
     * @param type $observer 
     */
    public function websiteDeleteAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->websiteDeleteCommitAfter($observer);
        }
    }      
    
    /**
     * We need to sync every time after we delete a website
     *
     * @param $observer
     */
    public function websiteDeleteCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('websiteDeleteCommitAfter');
        Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
    }

    /**
     * Check fields in the website object to see if we should run sync
     * only call sync if relevant data has changed
     *
     * @param $observer
     */
    public function websiteSaveBefore($observer)
    {
        Mage::helper('foomanjirafe')->debug('websiteSaveBefore');
        $website = $observer->getEvent()->getWebsite();
        // If the object is new, or has any data changes, sync
        if (!$website->getId() 
            || $website->hasDataChanges()                         //works for Magento 1.4.1+
            || $website->dataHasChangedFor('name')
            || $website->dataHasChangedFor('code')
            || $website->dataHasChangedFor('default_group_id')        
        ){
            if (!Mage::registry('foomanjirafe_sync')) {
                Mage::register('foomanjirafe_sync', true);
            }
        }
    }

    /**
     * websiteSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see websiteSaveCommitAfter
     * @param type $observer 
     */
    public function websiteSaveAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->websiteSaveCommitAfter($observer);
        }
    }      
    
    /**
     * Check to see if we need to sync.  If so, do it.
     *
     * @param $observer
     */
    public function websiteSaveCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('websiteSaveCommitAfter');
        if (Mage::registry('foomanjirafe_sync')) {
            Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
        }
    }

    /**
     * Check fields in the store group object to see if we should run sync
     * only call sync if relevant data has changed
     *
     * @param $observer
     */
    public function storeGroupSaveBefore($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeGroupSaveBefore');
        $storeGroup = $observer->getEvent()->getStoreGroup();
        // If the object is new, or has any data changes, sync
        if (!$storeGroup->getId() 
            || $storeGroup->hasDataChanges()                         //works for Magento 1.4.1+
            || $storeGroup->dataHasChangedFor('name')
            || $storeGroup->dataHasChangedFor('code')
            || $storeGroup->dataHasChangedFor('default_group_id')        
        ){
            if (!Mage::registry('foomanjirafe_sync')) {
                Mage::register('foomanjirafe_sync', true);
            }
        }
    }

    /**
     * storeGroupSaveCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see storeGroupSaveCommitAfter
     * @param type $observer 
     */
    public function storeGroupSaveAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->storeGroupSaveCommitAfter($observer);
        }
    }      
    
    /**
     * Check to see if we need to sync.  If so, do it.
     *
     * @param $observer
     */
    public function storeGroupSaveCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeGroupSaveAfter');
        if (Mage::registry('foomanjirafe_sync')) {
            Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
        }
    }

    /**
     * storeGroupDeleteCommitAfter is not available on Magento 1.3
     * provide the closest alternative
     * 
     * @see storeGroupDeleteCommitAfter
     * @param type $observer 
     */
    public function storeGroupDeleteAfter ($observer)
    {
        if (version_compare(Mage::getVersion(), '1.4.0.0', '<')) {
            $this->storeGroupDeleteCommitAfter($observer);
        }
    }    
    
    /**
     * We need to sync every time after we delete a store group
     *
     * @param $observer
     */
    public function storeGroupDeleteCommitAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('storeGroupDeleteAfter');
        Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
    }

    /**
     * sync a jirafe store after settings have been saved
     * checks local settings hash for settings before sync
     *
     * @param $observer
     */
    public function configSaveAfter($observer)
    {
        Mage::helper('foomanjirafe')->debug('syncAfterSettingsSave');
        $configData = $observer->getEvent()->getObject();
        if($configData instanceof Mage_Core_Model_Config_Data) {
            $path = $configData->getPath();
            $keys = array('web/unsecure/base_url', 'general/locale/timezone', 'currency/options/base');
            if (in_array($path, $keys)) {
                Mage::getModel('foomanjirafe/jirafe')->syncUsersStores();
            }
        }
    }

    /**
     * we can't add external javascript via normal Magento means
     * adding child elements to the head block or dashboard are also not automatically rendered
     * add foomanjirafe_dashboard_head via this observer
     * add foomanjirafe_dashboard_toggle via this observer
     * add foomanjirafe_adminhtml_permissions_user_edit_tab_jirafe via this observer
     *
     * @param $observer
     */
    public function coreBlockAbstractToHtmlBefore($observer)
    {
        $block = $observer->getEvent()->getBlock();
        $params = array('_relative'=>true);
        if ($area = $block->getArea()) {
            $params['_area'] = $area;
        }
        if ($block instanceof Mage_Adminhtml_Block_Permissions_User_Edit_Tabs) {
            $block->addTab('jirafe_section', array(
                'label'     => Mage::helper('foomanjirafe')->__('Jirafe Analytics'),
                'title'     => Mage::helper('foomanjirafe')->__('Jirafe Analytics'),
                'content'   => $block->getLayout()->createBlock('foomanjirafe/adminhtml_permissions_user_edit_tab_jirafe')->toHtml(),
                'after'     => 'roles_section'
            ));
        }
        if (($block instanceof Mage_Adminhtml_Block_Page_Head || $block instanceof Fooman_Speedster_Block_Adminhtml_Page_Head)
            && strpos($block->getRequest()->getControllerName(), 'dashboard')!==false
        ) {
            $block->setOrigTemplate(Mage::getBaseDir('design').DS.Mage::getDesign()->getTemplateFilename($block->getTemplate(), $params));
            $block->setTemplate('fooman/jirafe/dashboard-head.phtml');
            $block->setFoomanBlock($block->getLayout()->createBlock('foomanjirafe/adminhtml_dashboard_js'));
        }
        if ($block instanceof Mage_Adminhtml_Block_Dashboard) {
            $block->setOrigTemplate(Mage::getBaseDir('design').DS.Mage::getDesign()->getTemplateFilename($block->getTemplate(), $params));
            $block->setTemplate('fooman/jirafe/dashboard-toggle.phtml');
            $block->setFoomanBlock($block->getLayout()->createBlock('foomanjirafe/adminhtml_dashboard_toggle'));
        }
    }
    
    
    protected function _getCategory($product)
    {
        $id = current($product->getCategoryIds());
        $category = Mage::getModel('catalog/category')->load($id);
        $aCategories = array();
        foreach ($category->getPathIds() as $k => $id) {
            // Skip null and root
            if ($k > 1) {
                $category = Mage::getModel('catalog/category')->load($id);
                $aCategories[] = $category->getName();
            }
        }
        return join('/', $aCategories);
    }
    
    protected function _addEcommerceItems($piwikTracker, $quote)
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            if($item->getName()){
                //we only want to track the main configurable item
                //but not the subitem
                if($item->getParentItem()) {
                    if ($item->getParentItem()->getProductType() == 'configurable') {
                        continue;
                    }
                }

                $itemPrice = $item->getBasePrice();
                // This is inconsistent behaviour from Magento
                // base_price should be item price in base currency
                // TODO: add test so we don't get caught out when this is fixed in a future release
                if(!$itemPrice || $itemPrice < 0.00001) {
                    $itemPrice = $item->getPrice();
                }
                $piwikTracker->addEcommerceItem(
                    $item->getProduct()->getData('sku'),
                    $item->getName(),
                    $this->_getCategory($item->getProduct()),
                    $itemPrice,
                    $item->getQty()
                );
            }
        }        
    }

    protected function ecommerceCartUpdate($quote)
    {
        $piwikTracker = $this->_initPiwikTracker($quote->getStoreId());
        $piwikTracker->setIp($quote->getRemoteIp());
        $piwikTracker->setCustomVariable(1, 'U', Fooman_Jirafe_Block_Js::VISITOR_READY2BUY);

        $this->_addEcommerceItems($piwikTracker, $quote);
        $piwikTracker->doTrackEcommerceCartUpdate($quote->getBaseGrandTotal());
    }
    
    public function checkoutCartProductAddAfter($observer)
    {
        Mage::getSingleton('customer/session')->setJirafePageLevel(Fooman_Jirafe_Block_Js::VISITOR_READY2BUY);
        if(!Mage::registry('foomanjirafe_update_ecommerce')) {
            Mage::register('foomanjirafe_update_ecommerce', true);
        }
    }

    public function checkoutCartUpdateItemsAfter($observer)
    {
        if(!Mage::registry('foomanjirafe_update_ecommerce')) {
            Mage::register('foomanjirafe_update_ecommerce', true);
        }        
    }

    public function checkoutCartProductUpdateAfter($observer)
    {
        if(!Mage::registry('foomanjirafe_update_ecommerce')) {
            Mage::register('foomanjirafe_update_ecommerce', true);
        }        
    }

    public function salesQuoteRemoveItem($observer)
    {
        if(!Mage::registry('foomanjirafe_update_ecommerce')) {
            Mage::register('foomanjirafe_update_ecommerce', true);
        }        
    }
    
    public function salesQuoteCollectTotalsAfter ($observer)
    {
        if(Mage::registry('foomanjirafe_update_ecommerce')) {
            $this->ecommerceCartUpdate($observer->getEvent()->getQuote());
            Mage::unregister('foomanjirafe_update_ecommerce');
        }        
    }
}
