<?php

/*
 * Static defaults for the Stripe settings.
 *
 * This file is replaced on every 1-click update, so it holds defaults only. The runtime
 * source of truth is admin_config (per store, secrets encrypted at rest), read through
 * stripepayment_config(); these values are returned only when a key has no database row.
 * No env() here on purpose: the settings are managed in admin, never in .env.
 *
 * The success / cancel / webhook URLs are not settings: they are computed from the plugin
 * routes (function.php) so they always match the store's current domain.
 */
return [
    'test_mode' => 1,
    'secret_key_test' => '',
    'secret_key_live' => '',
    'webhook_secret_test' => '',
    'webhook_secret_live' => '',
    'order_status_success' => 2,  // Processing
    'order_status_refunded' => 7, // Refunded
];
