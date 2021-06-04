<?php

use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use App\Stripe\StripePaymentType;
use App\Stripe\StripeSettings;

use function DI\add;
use function DI\autowire;
use function DI\get;

return [
    'auth.entity'   => StripeUser::class,
    'payments.type' => add(get(StripePaymentType::class)),
    //'admin.settings'=> add(get(StripeSettings::class)),
    'csrf.except'   => add(['stripe.webhook']),
    Stripe::class   => autowire()
        ->constructorParameter('endpointkey', $_ENV['STRIPE_ENDPOINT'] ?? null)
        ->constructorParameter('privateKey', $_ENV['STRIPE_SECRET'] ?? null)
        ->constructorParameter('publicKey', $_ENV['STRIPE_PUBLIC'] ?? null)
];
