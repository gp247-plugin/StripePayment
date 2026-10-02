<?php
/**
 * Provides everything needed for the Extension
 */

$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);
$extensionPath = $config['configGroup'].'/'.$config['configKey'];

$this->loadTranslationsFrom(__DIR__.'/Lang', $extensionPath);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

    if (file_exists(__DIR__.'/config.php')) {
        $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);
    }

    if (file_exists(__DIR__.'/function.php')) {
        require_once __DIR__.'/function.php';
    }

    // Stripe also collects / refunds the core's payment requests (pay links).
    if (function_exists('stripepayment_register_payment_gateway')) {
        stripepayment_register_payment_gateway();
    }

    if (class_exists(\Livewire\Livewire::class)) {
        \Livewire\Livewire::addNamespace('StripePayment', classNamespace: 'App\\GP247\\Plugins\\StripePayment\\Livewire');
    }

    // storeScope = "store" (gp247.json): each store may use its own Stripe account and the
    // root admin sets it through the ConfigForm store picker. WHO may edit is a separate
    // knob: this plugin deliberately does NOT add its admin segment to the store-scoped
    // segments registry, so store-admins and vendors are kept out of the payment settings
    // and cannot redirect where the money goes.
}
