<?php

namespace Alma\SyliusPaymentPlugin\Block;

use Sonata\BlockBundle\Model\Block;

final class AlmaWidgetBlock extends Block
{
    protected $data;

    protected $margin;

    public function setData($data)
    {
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setClass($margin)
    {
        $this->margin = $margin;
    }

    public function getClass()
    {
        return $this->margin;
    }
}
