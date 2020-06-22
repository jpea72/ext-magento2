<?php

namespace InXpress\InXpressRating\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Config;
use Magento\Framework\HTTP\ZendClient;

class Canpar extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'canpar';

    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory $clientFactory
     */
    protected $clientFactory;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Framework\HTTP\ZendClientFactory $clientFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Framework\HTTP\ZendClientFactory $clientFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_clientFactory = $clientFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return ['canpar' => $this->getConfigData('name')];
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $shippingPrice = 0;

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        $account = $this->getConfigData('account');

        if (!$account) {
            return false;
        }

        if ($request->getAllItems()) {
            $products = $this->buildProducts($request);

            $destination = array(
                "name" => "",
                "address1" => "",
                "address2" => "",
                "city" => "",
                "province" => "",
                "phone" => "",
                "country" => $request->getDestCountryId(),
                "postal_code" => $request->getDestPostcode()
            );


            $price = $this->calcRate($account, $products, $destination);

            $this->_logger->critical("InXpress price", ['price' => $price]);
            if ($price) {
                $shippingPrice = $price['price'];
            } else {
                return false;
            }
        }

        if ($shippingPrice != 0) {
            /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
            $method = $this->_rateMethodFactory->create();

            $method->setCarrier('canpar');
            $method->setCarrierTitle($this->getConfigData('title'));

            $method->setMethod('canpar');
            $method->setMethodTitle($this->getConfigData('name'));

            $method->setPrice($shippingPrice);
            $method->setCost($shippingPrice);

            $result->append($method);
        }

        return $result;
    }

    public function buildProducts($request)
    {
        $products = array();
        foreach ($request->getAllItems() as $item) {
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                continue;
            }

            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if (!$child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                        array_push($products, $child->toArray());
                    }
                }
            } else {
                array_push($products, array(
                    "id" => $item->getProductId(),
                    "sku" => $item->getSku(),
                    "name" => $item->getName(),
                    "weight" => $this->itemWeight($item),
                    "quantity" => $item->getQty()
                ));
            }
        }
        return $products;
    }

    public function itemWeight($item)
    {
        $weight = floatval($item->getWeight());

        if ($this->getConfigData('usekg')) {
            $weight *= 2.20462262;
        }

        return $weight;
    }

    public function calcRate($account, $products, $destination)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $store_id = $this->_scopeConfig->getValue("system/carriers/dhlexpress/store_id", $storeScope, \Magento\Store\Model\Store::DEFAULT_STORE_ID);

        if (empty($store_id)) {
            $this->_logger->critical("InXpress store id not found, please register on the portal", ['store_id' => $store_id]);
            return false;
        }

        $origin = array(
            "name" => "",
            "address1" => "",
            "address2" => "",
            "city" => "",
            "province" => "",
            "phone" => "",
            "country" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_COUNTRY_ID,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            ),
            "postal_code" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_POSTCODE,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            )
        );

        $url = "https://api.inxpressapps.com/carrier/v1/stores/" . $store_id . "/rates";

        $payload = json_encode(array(
            "account" => $account,
            "services" => array(
                array(
                    'carrier' => 'Canpar',
                    'service' => 'Canpar Ground',
                ),
                array(
                    'carrier' => 'Canpar',
                    'service' => 'Canpar Select',
                ),
                array(
                    'carrier' => 'Canpar',
                    'service' => 'Canpar Express',
                )
            ),
            "origin" => $origin,
            "destination" => $destination,
            "products" => $products
        ));

        $this->_logger->critical("InXpress requesting rates", ['url' => $url, 'request' => $payload]);

        try {
            /** @var \Magento\Framework\HTTP\ZendClient $client */
            $client = $this->_clientFactory->create();
            $client->setUri($url);
            $client->setMethod(ZendClient::POST);
            $client->setHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]);
            $client->setConfig(array('maxredirects' => 0, 'timeout' => 30));
            $client->setRawData($payload);
            $client->setEncType('application/json');
            $response = $client->request(\Magento\Framework\HTTP\ZendClient::POST)->getBody();

            $responseArray = json_decode($response, true);
            $this->_logger->critical("InXpress response array", $responseArray);

            if (isset($responseArray["rates"][0]["total_price"])) {
                $response1 = array();
                $response1['price'] = $responseArray["rates"][0]["total_price"] / 100;
                $response1['days'] = $responseArray["rates"][0]["display_sub_text"];
                return $response1;
            } else {
                $this->_logger->critical("InXpress error requesting rates", ['response' => $response]);
                return false;
            }
        } catch (\Exception $e) {
            $this->_logger->critical("InXpress error requesting rates", ['response' => $e->getMessage()]);
            return false;
        }
    }
}
