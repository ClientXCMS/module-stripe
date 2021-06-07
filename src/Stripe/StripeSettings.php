<?php
namespace App\Stripe;

use App\Admin\Settings\SettingsInterface;
use ClientX\Renderer\RendererInterface;
use ClientX\Validator;

class StripeSettings implements SettingsInterface {

    public function name(): string
    {
        return "stripe";
    }

    public function title(): string
    {
        return "Stripe";
    }

    public function icon(): string
    {
        return "fab fa-stripe-s";
    }

    public function validate(array $params): Validator
    {
        return (new Validator($params))
            ->notEmpty('stripe_secret', 'stripe_key', 'stripe_endsecret');
    }

    public function render(RendererInterface $renderer)
    {
        return $renderer->render('@stripe_admin/settings');
    }


}