<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Block;

use Sonata\BlockBundle\Event\BlockEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Sylius\Component\Currency\Context\CurrencyContextInterface;
use Alma\SyliusPaymentPlugin\Resolver\AlmaPaymentMethodsResolver;
use Sylius\Bundle\PaymentBundle\Doctrine\ORM\PaymentMethodRepository;
use Sylius\Bundle\ProductBundle\Doctrine\ORM\ProductVariantRepository;
use Sylius\Component\Core\Calculator\ProductVariantPriceCalculatorInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as AlmaGatewayConfigInterface;

final class WidgetEventListener
{
    /** @var string */
    private $template;

    /** @var AlmaPaymentMethodsResolver */
    private $almaPaymentMethodsResolver;

    /** @var RequestStack */
    private $request;

    /** @var ChannelContextInterface */
    private $channel;

    /** @var ProductVariantRepository */
    private $productVariantRepository;

    /** @var PaymentMethodRepository */
    private $paymentMethodRepository;

    /** @var ProductVariantPriceCalculatorInterface */
    private $productVariantPriceCalculatorInterface;

    /** @var CurrencyContextInterface */
    private $currencyContextInterface;


    public function __construct(
        string $template,
        AlmaPaymentMethodsResolver $almaPaymentMethodsResolver,
        RequestStack $request,
        ChannelContextInterface $channelContext,
        ProductVariantRepository $productVariantRepository,
        PaymentMethodRepository $paymentMethodRepository,
        ProductVariantPriceCalculatorInterface $productVariantPriceCalculatorInterface,
        CurrencyContextInterface $currencyContextInterface
    ) {
        $this->template = $template;
        $this->almaPaymentMethodsResolver = $almaPaymentMethodsResolver;
        $this->request = $request;
        $this->channel = $channelContext;
        $this->productVariantRepository = $productVariantRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->productVariantPriceCalculatorInterface = $productVariantPriceCalculatorInterface;
        $this->currencyContextInterface = $currencyContextInterface;
    }


    private function getWidgetData()
    {
        $slug = $this->request->getCurrentRequest()->attributes->get('slug');
        $variant =  $this->productVariantRepository->findOneBy(['code' => $slug]);
        $channel = $this->channel->getChannel();
        $methods = $this->paymentMethodRepository->findEnabledForChannel($channel);
        $almaInstallments = [];
        $config['channel'] = $channel;

        if ($variant) {
            $amount = $this->productVariantPriceCalculatorInterface->calculate($variant, $config);
        } else {
            $amount = 0;
        }

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
            "refreshPrice"  => true,
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
        $block->setClass('product');
        $block->setType('sonata.block.service.template');
        $event->addBlock($block);
    }
}
