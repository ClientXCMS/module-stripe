<?php
namespace App\Stripe;

use App\Stripe\Actions\StripeAdminAction;
use App\Stripe\Actions\StripeApiAction;
use ClientX\Module;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use Psr\Container\ContainerInterface;

class StripeModule extends Module
{

    const DEFINITIONS = __DIR__ . '/config.php';
    const MIGRATIONS = __DIR__ . '/db/migrations';

    public function __construct(Router $router, RendererInterface $renderer, ContainerInterface $container)
    {
        $renderer->addPath("stripe_admin", __DIR__ . '/Views');
        $router->post('/stripe/api', StripeApiAction::class, 'stripe.webhook');
        if ($container->has('admin.prefix')) {
            $prefix = $container->get('admin.prefix');
            $router->get($prefix . "/stripe", StripeAdminAction::class, 'stripe.admin');
            $router->get($prefix . "/stripe", StripeAdminAction::class, 'stripe.admin.index');
        }
    }
}