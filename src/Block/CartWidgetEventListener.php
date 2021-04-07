<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Block;

use Sonata\BlockBundle\Event\BlockEvent;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Alma\SyliusPaymentPlugin\Resolver\AlmaPaymentMethodsResolver;
use Sylius\Bundle\PaymentBundle\Doctrine\ORM\PaymentMethodRepository;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as AlmaGatewayConfigInterface;
use Sylius\Component\Order\Context\CartContextInterface;

final class CartWidgetEventListener
{
    /** @var string */
    private $template;

    /** @var AlmaPaymentMethodsResolver */
    private $almaPaymentMethodsResolver;

    /** @var ChannelContextInterface */
    private $channel;

    /** @var PaymentMethodRepository */
    private $paymentMethodRepository;

    /** @var CurrencyContextInterface */
    private $currencyContextInterface;

    /** @var CartContextInterface */
    private $cartContextInterface;

    public function __construct(
        string $template,
        AlmaPaymentMethodsResolver $almaPaymentMethodsResolver,
        ChannelContextInterface $channelContext,
        PaymentMethodRepository $paymentMethodRepository,
        CurrencyContextInterface $currencyContextInterface,
        CartContextInterface $cartContextInterface
    ) {
        $this->template = $template;
        $this->almaPaymentMethodsResolver = $almaPaymentMethodsResolver;
        $this->channel = $channelContext;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->currencyContextInterface = $currencyContextInterface;
        $this->cartContextInterface = $cartContextInterface;
    }


    private function getWidgetData()
    {
        $channel = $this->channel->getChannel();
        $methods = $this->paymentMethodRepository->findEnabledForChannel($channel);
        $almaInstallments = [];
        $config['channel'] = $channel;
        $amount = $this->cartContextInterface->getCart()->getTotal();
        $currency = $this->currencyContextInterface->getCurrencyCode();
        foreach ($methods as $method) {
            $gatewayConfig = $method->getGatewayConfig();
            if (!$gatewayConfig || $this->almaPaymentMethodsResolver->getGatewayFactoryName($gatewayConfig) !== AlmaGatewayFactory::FACTORY_NAME) {
                continue;
            }
            if (!in_array($currency, AlmaGatewayConfigInterface::ALLOWED_CURRENCY_CODES, true)) {
                continue;
            }
            $config = $this->almaPaymentMethodsResolver->getAlmaGatewayConfig($method);
            $merchantId = $config->getMerchantId();
            $apiMode = $config->getApiMode();
            $almaInstallments[] = [
                'installmentsCount' => $config->getInstallmentsCount()
            ];
        }
        if (empty($almaInstallments)) {
            return false;
        }

        $almaMethods = [
            "merchantId"    => $merchantId,
            "apiMode"       => $apiMode,
            "plans"         => $almaInstallments,
            "refreshPrice"  => false,
            "amount"        => $amount,
        ];

        return json_encode($almaMethods);
    }

    public function onBlockEvent(BlockEvent $event): void
    {
        $almaWidget = $this->getWidgetData();
        $block = new AlmaWidgetBlock();
        $block->setId(uniqid('', true));
        $block->setSettings(array_replace($event->getSettings(), [
            'template' => $this->template
        ]));
        $block->setData($almaWidget);
        $block->setClass('cart');
        $block->setType('sonata.block.service.template');
        $event->addBlock($block);
    }
}
