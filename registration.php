<?php

use \Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'SalesIgniter_Common', __DIR__);

/*
 * The off-session gateway sub-modules (SubModules/*, .claude/rules/offsession.md): one Magento module per
 * gateway family, registered ONLY where that gateway's code is installed. releasecommon2 is on every Sales
 * Igniter store, and setup:upgrade enables every newly registered module, so an unconditional registration
 * would put e.g. SalesIgniter_CommonPayMollie (a class implementing a Mollie interface) into
 * setup:di:compile on stores without Mollie. The check asks Composer's class loaders where the gateway's
 * class file is (no class is loaded); app/code gateways are found through the root autoload too.
 */
(static function (): void {
    $installed = static function (string $class): bool {
        foreach (spl_autoload_functions() ?: [] as $loader) {
            if (is_array($loader) && $loader[0] instanceof \Composer\Autoload\ClassLoader && $loader[0]->findFile($class)) {
                return true;
            }
        }
        return false;
    };
    $gateways = [
        'SalesIgniter_CommonPayStripe' => ['Stripe', 'StripeIntegration\\Payments\\Model\\Config'],
        'SalesIgniter_CommonPayBraintree' => ['Braintree', 'PayPal\\Braintree\\Gateway\\Request\\TransactionSourceDataBuilder'],
        'SalesIgniter_CommonPayAdyen' => ['Adyen', 'Adyen\\Payment\\Gateway\\Validator\\CheckoutResponseValidator'],
        'SalesIgniter_CommonPayMollie' => ['Mollie', 'Mollie\\Payment\\Service\\Order\\TransactionPartInterface'],
        'SalesIgniter_CommonPayTokenBase' => ['TokenBase', 'ParadoxLabs\\TokenBase\\Api\\CardRepositoryInterface'],
    ];
    foreach ($gateways as $module => [$dir, $marker]) {
        if ($installed($marker)) {
            ComponentRegistrar::register(ComponentRegistrar::MODULE, $module, __DIR__ . '/SubModules/' . $dir);
        }
    }
})();
