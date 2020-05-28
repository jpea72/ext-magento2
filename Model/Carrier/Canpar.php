<?php

namespace InXpress\InXpressRating\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Config;

class DHLExpress extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'canpar';

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
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

        if ($this->getConfigData('usekg')) {
            $weight *= 2.20462262;
        }

        $products = array();
        if ($request->getAllItems()) {
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
                        "weight" => floatval($item->getWeight()),
                        "quantity" => $item->getQty()
                    ));
                }
            }

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

    public function calcRate($account, $products, $destination)
    {
        $storeScope = \Magento\Store\Model\Magento\Store\Model\ScopeInterface::SCOPE_STORES;
        $store_id = $this->_scopeConfig->getValue("system/carriers/dhlexpress/store_id", $storeScope, \Magento\Store\Model\Store::DEFAULT_STORE_ID);

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

        $httpHeaders = new \Zend\Http\Headers();
        $httpHeaders->addHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ]);

        $request = new \Zend\Http\Request();
        $request->setHeaders($httpHeaders);
        $request->setUri($url);
        $request->setMethod(\Zend\Http\Request::METHOD_POST);

        $client = new \Zend\Http\Client();
        $options = [
            'adapter'   => 'Zend\Http\Client\Adapter\Curl',
            'curloptions' => [CURLOPT_FOLLOWLOCATION => true],
            'maxredirects' => 0,
            'timeout' => 30
        ];
        $client->setOptions($options);
        $client->setRawBody($payload);
        $client->setEncType('application/json');

        $response = $client->send($request);

        if ($response->isSuccess()) {
            $responseArray = json_decode($response, true);

            $this->_logger->critical("InXpress success requesting rates", ['response' => $responseArray]);

            if (isset($responseArray['totalCharge'])) {
                $response = array();
                $response['price'] = $responseArray['totalCharge'];
                $response['days'] = $responseArray['info']['baseCountryTransitDays'];
                return $response;
            } else {
                return false;
            }
        } else {
            $this->_logger->critical("InXpress error requesting rates", ['response' => $response->toString()]);
            return false;
        }
    }
}
