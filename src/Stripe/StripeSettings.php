<?php

namespace App\Stripe;

use ClientX\Renderer\RendererInterface;
use ClientX\Validator;

class StripeSettings implements \App\Admin\Settings\SettingsInterface
{
    const TYPES = ["acss_debit","affirm","afterpay_clearpay","alipay","au_becs_debit","bacs_debit","bancontact","blik","boleto","card","customer_balance","eps","fpx","giropay","grabpay","ideal","klarna","konbini","link","oxxo","p24","paynow","pix","promptpay","sepa_debit","sofort","us_bank_account","wechat_pay"];
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

    public function render(RendererInterface $renderer)
    {
        $types = collect(self::TYPES)->mapWithKeys(function ($key) {
            return [$key => $key];
        })->toArray();
        return $renderer->render("@stripe_admin/settings", ['types' => $types]);
    }

    public function validate(array $params): Validator
    {
        return (new Validator($params));
    }
}
