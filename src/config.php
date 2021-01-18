<?php

use App\Stripe\Api\Entity\StripeUser;
use App\Stripe\Api\Stripe;
use App\Stripe\StripePaymentType;
use App\Stripe\StripeSettings;

use function ClientX\setting;
use function DI\add;
use function DI\autowire;
use function DI\get;

return [
    'auth.entity'           => StripeUser::class,
    'payments.type' => add(get(StripePaymentType::class)),
    'stripe.key'    => setting("stripe_key"),
    'stripe.secret' => setting("stripe_secret"),
    'stripe.endsecret' => setting('stripe_endsecret'),
    'admin.settings'=> add(get(StripeSettings::class)),
    'csrf.except'   => add('stripe.webhook'),
    Stripe::class   => autowire()
        ->constructorParameter('endpointkey', get('stripe.endsecret'))
        ->constructorParameter('privateKey', get('stripe.secret'))
        ->constructorParameter('publicKey', get('stripe.key'))
];