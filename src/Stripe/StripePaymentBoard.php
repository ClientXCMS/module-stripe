<?php

namespace App\Stripe;

class StripePaymentBoard extends \App\Shop\Payment\AbstractPaymentBoard
{
    protected string $entity = StripePaymentType::class;
    protected string $type = 'stripe';
}
