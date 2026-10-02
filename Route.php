<?php
use Illuminate\Support\Facades\Route;

$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {

    // Shopper-facing return URLs: normal storefront middleware (session, locale, domain).
    Route::group(
        [
            'middleware' => GP247_FRONT_MIDDLEWARE,
            'prefix'    => 'plugin/stripe-payment',
        ],
        function () {
            // Throttled: each hit reads the session back from the Stripe API.
            Route::get('success', [\App\GP247\Plugins\StripePayment\Controllers\FrontController::class, 'success'])
                ->middleware('throttle:30,1')
                ->name('stripe-payment.success');
            Route::get('cancel', [\App\GP247\Plugins\StripePayment\Controllers\FrontController::class, 'cancel'])
                ->name('stripe-payment.cancel');
            // Back from paying a core payment request (pay link): read from Stripe, recorded
            // idempotently, then back to the link's page.
            Route::get('payment-request/return', [\App\GP247\Plugins\StripePayment\Controllers\FrontController::class, 'paymentRequestReturn'])
                ->middleware('throttle:30,1')
                ->name('stripe-payment.payment_request.return');
        }
    );

    // Webhook: deliberately OUTSIDE the storefront group. It is a server-to-server POST
    // (no session, no CSRF token), and the storefront middleware can answer it with a
    // redirect (front.redirect) or a maintenance page (check.active exits with 200) —
    // Stripe would count either as delivered and the payment would be lost. The store
    // is already resolved from the domain at boot, which is all the webhook needs.
    // Throttled generously (Stripe retries a 429): signature checks are cheap, but an
    // unsigned flood should not reach the database at all.
    Route::post('plugin/stripe-payment/webhook', [\App\GP247\Plugins\StripePayment\Controllers\FrontController::class, 'webhook'])
        ->middleware('throttle:600,1')
        ->name('stripe-payment.webhook');

    Route::group(
        [
            'prefix' => GP247_ADMIN_PREFIX.'/stripe-payment',
            'middleware' => GP247_ADMIN_MIDDLEWARE,
        ],
        function () {
            Route::get('/', \App\GP247\Plugins\StripePayment\Livewire\AdminLivewire::class)
                ->name('admin_stripe-payment.index');
        }
    );
}
