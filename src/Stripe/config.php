<?php

use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use App\Stripe\StripePaymentType;
use App\Stripe\StripePaymentBoard;

use App\Stripe\StripeSettings;
use function DI\add;
use function DI\autowire;
use function DI\get;

return [
    'auth.entity'       => StripeUser::class,
    'payments.type'     => add(get(StripePaymentType::class)),
    'csrf.except'       => add(['stripe.webhook']),
    'payment.boards'    => add(get(StripePaymentBoard::class)),
    'admin.settings'    => add(get(StripeSettings::class)),
    Stripe::class       => autowire()
        ->constructorParameter('endpointkey', $_ENV['STRIPE_ENDPOINT'] ?? null)
        ->constructorParameter('privateKey', $_ENV['STRIPE_SECRET'] ?? null)
        ->constructorParameter('types', \ClientX\setting('stripe_payment_types', '["card"]'))
        ->constructorParameter('publicKey', $_ENV['STRIPE_PUBLIC'] ?? null)
];
