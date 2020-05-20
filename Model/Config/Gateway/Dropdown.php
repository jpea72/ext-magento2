<?php

namespace InXpress\InXpressRating\Model\Config\Gateway;

class Dropdown implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'US',
                'label' => 'US'
            ],
            [
                'value' => 'CA',
                'label' => 'CA',
            ]
        ];
    }
}
