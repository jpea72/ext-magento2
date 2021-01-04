<?php

namespace InXpress\InXpressRating\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Config;
use Magento\Framework\HTTP\ZendClient;
use Magento\Directory\Model\RegionFactory;

class UPSInxpress extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'upsinxpress';

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
        \Magento\Directory\Model\RegionFactory $regionFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_clientFactory = $clientFactory;
        $this->_regionFactory = $regionFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return ['upsinxpress' => $this->getConfigData('name')];
    }

    /**
     * @param $regionId
     * @return String
     */
    public function getRegionCode( $regionId ){
        $region = $this->_regionFactory->create()->load($regionId);
        $regionArray = $region->getData();
	    return $regionArray['code'];
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

        $gateway = $this->getConfigData('gateway');
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
                "city" => $request->getDestCity(),
                "province" => $request->getDestRegionCode(),
                "phone" => "",
                "country" => $request->getDestCountryId(),
                "postal_code" => $request->getDestPostcode()
            );


            $prices = $this->calcRate($account, $gateway, $products, $destination);

            if ($prices) {
                foreach($prices as $price) {
                    $this->_logger->critical("InXpress price", ['price' => $prices]);
                    if ($price) {
                        $shippingPrice = $price['price'];
                    } else {
                        return false;
                    }

                    if ($shippingPrice != 0) {
                        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
                        $method = $this->_rateMethodFactory->create();

                        $method->setCarrier('upsinxpress');
                        $method->setCarrierTitle($this->getConfigData('title'));

                        $method->setMethod(strtolower($price['service_code']));
                        $method->setMethodTitle($price['service']);

                        $method->setPrice($shippingPrice);
                        $method->setCost($shippingPrice);

                        $result->append($method);
                    }
                }
            }
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
        $weight_in_uom = floatval($item->getWeight());
        $weight_unit = $this->getWeightUnit();

        switch ( $weight_unit ) {
            case 'lbs':
                $weight = $weight_in_uom * 453.5920;
                break;
            case 'kgs':
                $weight = $weight_in_uom * 1000;
                break;
            default:
                $weight = $weight_in_uom;
        }

        return $weight;
    }

    public function getWeightUnit()
    {
        return $this->_scopeConfig->getValue('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    public function addHandling($price)
    {
        $handling_type = $this->getConfigData('handling_type');
        $handling_fee = $this->getConfigData('handling_fee');

        if ( $handling_type === "F" && isset( $handling_fee )) {
            $price += $handling_fee;
        }

        if ( $handling_type === "P" && isset( $handling_fee )) {
            $multiplier = $handling_fee / 100 + 1.00;
            $price *= $multiplier;
        }

        return $price;
    }

    public function calcRate($account, $gateway, $products, $destination)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $store_id = $this->_scopeConfig->getValue("system/carriers/dhlexpress/store_id", $storeScope, \Magento\Store\Model\Store::DEFAULT_STORE_ID);

        if (empty($store_id)) {
            $this->_logger->critical("InXpress store id not found, please register on the portal", ['store_id' => $store_id]);
            return false;
        }

        $regionCode = $this->getRegionCode( $this->_scopeConfig->getValue(
            Config::XML_PATH_ORIGIN_REGION_ID,
            $storeScope,
            \Magento\Store\Model\Store::DEFAULT_STORE_ID )
	     );

        $origin = array(
            "name" => "",
            "address1" => "",
            "address2" => "",
            "city" => $this->_scopeConfig->getValue(
                Config::XML_PATH_ORIGIN_CITY,
                $storeScope,
                \Magento\Store\Model\Store::DEFAULT_STORE_ID
            ),
	        "province" => $regionCode,
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
            "gateway" => $gateway,
            "services" => array(array(
                "carrier" => "UPS",
                "service" => "DHL Express"
            )),
            "origin" => $origin,
            "destination" => $destination,
            "items" => $products
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
                $responses = array();
                foreach($responseArray["rates"] as $rate) {
                    $response = array();

                    $before_handling_price = $rate["total_price"] / 100;
                    $response['price'] = $this->addHandling($before_handling_price);
                    $response['days'] = $rate["display_sub_text"];
                    $response['service'] = $rate["display_text"];
                    array_push($responses, $response);
                }
                return $responses;
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
