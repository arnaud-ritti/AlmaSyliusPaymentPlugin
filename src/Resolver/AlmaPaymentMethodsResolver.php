<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Resolver;


use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as AlmaGatewayConfigInterface;
use InvalidArgumentException;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\GatewayConfigInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

final class AlmaPaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    /**
     * @var PaymentMethodRepositoryInterface
     */
    private $methodsRepository;

    /**
     * @var AlmaBridgeInterface
     */
    private $almaBridge;

    public function __construct(
        PaymentMethodRepositoryInterface $paymentMethodRepository,
        AlmaBridgeInterface $almaBridge
    ) {
        $this->methodsRepository = $paymentMethodRepository;
        $this->almaBridge = $almaBridge;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedMethods(BasePaymentInterface $subject): array
    {
        if (!$subject instanceof PaymentInterface || !$this->supports($subject)) {
            throw new InvalidArgumentException('Payment subject not supported');
        }

        // Since `supports` returned true, we know for sure getOrder & getChannel aren't returning null
        /** @var PaymentMethodInterface[] $methods */
        $methods = $this->methodsRepository->findEnabledForChannel($subject->getOrder()->getChannel());

        /** @var PaymentMethodInterface[] $supportedMethods */
        $supportedMethods = [];
        $almaMethods = new ArrayObject();

        foreach ($methods as $method) {
            $gatewayConfig = $method->getGatewayConfig();

            // Don't mess with non-Alma payment methods
            if (!$gatewayConfig || $this->getGatewayFactoryName($gatewayConfig) !== AlmaGatewayFactory::FACTORY_NAME) {
                $supportedMethods[] = $method;

                continue;
            }

            // If we're not dealing with an allowed currency, skip all Alma methods
            if (!in_array($subject->getCurrencyCode(), AlmaGatewayConfigInterface::ALLOWED_CURRENCY_CODES, true)) {
                continue;
            }

            // Keep track of where payment methods are supposed to be
            $supportedMethods[] = $method->getId();

            $config = $this->getAlmaGatewayConfig($method);

            // Group Alma methods by merchant ID & API mode to batch eligibility calls
            $groupKey = $config->getMerchantId() . ':' . $config->getApiMode();
            $almaMethods->defaults([$groupKey => []]);

            /** @var PaymentMethodInterface[] $group */
            $group = $almaMethods[$groupKey];
            $group[] = $method;
            $almaMethods[$groupKey] = $group;
        }

        /** @var PaymentMethodInterface[] $methodsToAdd */
        $methodsToAdd = [];

        /** @var array<string, PaymentMethodInterface[]> $almaMethods */
        foreach ($almaMethods as $group => $enabledMethods) {
            // Break out enabled methods for a merchantId:apiMode group into their respective installments count
            // Doing so, we avoid requesting eligibility several times for a single installments count, in case the
            // merchant has created several gateways for the same installments count
            $installmentsCounts = array_reduce(
                $enabledMethods,
                function (array $memo, PaymentMethodInterface $method): array {
                    $config = $this->getAlmaGatewayConfig($method);

                    $installmentsCount = $config->getInstallmentsCount();
                    if (!isset($memo[$installmentsCount])) {
                        $memo[$installmentsCount] = [];
                    }

                    $memo[$installmentsCount][] = $method;

                    return $memo;
                },
                []
            );

            // Configure AlmaBridge with any of the enabled methods config, then request eligibilities for all
            // configured installments counts at once
            $this->almaBridge->initialize($this->getGatewayConfigData($enabledMethods[0]));
            $eligibilities = $this->almaBridge->getEligibilities($subject, array_keys($installmentsCounts));

            // For each eligible installments count, add all concerned Alma methods into the supportedMethods array
            foreach ($eligibilities as $eligibility) {
                if (!$eligibility->isEligible()) {
                    continue;
                }

                /** @var int $installmentsCount */
                $installmentsCount = $eligibility->installmentsCount;
                $methodsToAdd = array_merge(
                    $methodsToAdd,
                    array_reduce(
                        $installmentsCounts[$installmentsCount],
                        function (array $memo, PaymentMethodInterface $m): array {
                            // PHP automatically coerces int-looking indices to int, and array_merge doesn't preserve
                            // int keys, so we need an actual string key to make things work.
                            $memo["method:" . $m->getId()] = $m;

                            return $memo;
                        },
                        []
                    )
                );
            }
        }

        // Reinsert alma payment methods into the supportedMethods array, in their proper place
        $supportedMethods = array_map(function ($m) use ($methodsToAdd) {
            if ($m instanceof PaymentMethodInterface) {
                return $m;
            }

            $methodKey = "method:" . $m;
            if (array_key_exists($methodKey, $methodsToAdd)) {
                return $methodsToAdd[$methodKey];
            }

            return null;
        }, $supportedMethods);

        return array_filter($supportedMethods);
    }

    public function supports(BasePaymentInterface $subject): bool
    {
        return $subject instanceof PaymentInterface
            && $subject->getOrder() !== null
            && $subject->getOrder()->getChannel() !== null;
    }

    public function getGatewayFactoryName(GatewayConfigInterface $gatewayConfig): ?string
    {
        $config = $gatewayConfig->getConfig();

        // GatewayConfigInterface::getFactoryName is deprecated and the recommended method is to set the factory name on
        // the `payum.factory_name` directly into the gateway's config
        if (array_key_exists('payum.factory_name', $config)) {
            return strval($config['payum.factory_name']);
        }

        // Fallback, just in case!
        if (is_callable([$gatewayConfig, 'getFactoryName'])) {
            return $gatewayConfig->getFactoryName();
        }

        return null;
    }

    private function getGatewayConfigData(PaymentMethodInterface $method): ArrayObject
    {
        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $method->getGatewayConfig();
        return ArrayObject::ensureArrayObject($gatewayConfig->getConfig());
    }

    public function getAlmaGatewayConfig(PaymentMethodInterface $method): AlmaGatewayConfigInterface
    {
        return new GatewayConfig($this->getGatewayConfigData($method));
    }
}
