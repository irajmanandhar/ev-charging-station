<?php

return [
    /*
    | Payment gateway: "null" (online payments off, default), "khalti" or "esewa".
    | Both gateways point at their SANDBOX by default; switch the URLs and keys only
    | after the provider has approved your merchant account.
    */
    'driver' => env('PAYMENTS_DRIVER', 'null'),

    // Where the gateway sends the driver back to after paying.
    'return_url' => env('PAYMENTS_RETURN_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/bookings'),

    'khalti' => [
        'base_url' => env('KHALTI_BASE_URL', 'https://dev.khalti.com/api/v2'),
        'secret_key' => env('KHALTI_SECRET_KEY'),
    ],

    'esewa' => [
        'form_url' => env('ESEWA_FORM_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2/form'),
        'status_url' => env('ESEWA_STATUS_URL', 'https://rc.esewa.com.np/api/epay/transaction/status/'),
        'product_code' => env('ESEWA_PRODUCT_CODE', 'EPAYTEST'),
        'secret_key' => env('ESEWA_SECRET_KEY'),
    ],
];
