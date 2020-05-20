<?php

namespace InXpress\InXpressRating\Controller\Adminhtml\Register;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\Action;
use Magento\Backend\App\Action\Context;

/**
 * Class Index
 */

class Index extends Action
{
    protected $_urlInterface;
    protected $_scopeConfig;
    protected $_configWriter;
    protected $_request;

    /**
     * Index constructor.
     *
     * @param Context $context
     * @param \Magento\Framework\UrlInterface $urlInterface
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
     * @param \Magento\Framework\App\Request\Http $request
     */
    public function __construct(
        Context $context,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Framework\App\Request\Http $request
    ) {
        parent::__construct($context);
        $this->_urlInterface = $urlInterface;
        $this->_scopeConfig = $scopeConfig;
        $this->_configWriter = $configWriter;
        $this->_request = $request;
    }


    public function execute()
    {
        //$app_url = 'http://localhost:8080/';
        $app_url = 'https://portal.inxpressapps.com/';

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $store_id = $this->_scopeConfig->getValue("system/carriers/dhlexpress/store_id", $storeScope);

        $params = $this->_request->getParams();

        if ($store_id) {
            $resultRedirect->setUrl($app_url . 'store/' . $store_id);
        } elseif (array_key_exists('registered', $params) && $params['registered'] == "true") {
            $store_id = $params['store_id'];

            $this->_configWriter->save(
                'system/carriers/dhlexpress/store_id',
                $store_id,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            );

            $resultRedirect->setUrl($app_url . 'store/' . $store_id);
        } else {
            $site_url = $this->_urlInterface->getBaseUrl();
            $callback_url = $this->_urlInterface->getCurrentUrl();
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productMetadata = $objectManager->get('\Magento\Framework\App\ProductMetadataInterface');
            $plan = 'Magento 2' . ' (v' . $productMetadata->getVersion() . ')';

            $redirect = $app_url . 'register?platform=Magento%202&url=' . $site_url . '&plan=' . $plan . '&callback_url=' . $callback_url;

            $resultRedirect->setUrl($redirect);
        }

        return $resultRedirect;
    }
}
