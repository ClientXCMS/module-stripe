<?php
namespace App\Stripe\Actions;

use App\Stripe\Database\StripeTable;
use ClientX\Actions\Action;
use ClientX\Renderer\RendererInterface;

class StripeAdminAction extends Action {

    /**
     * @var StripeTable
     */
    private $table;

    public function __construct(RendererInterface $renderer, StripeTable $table)
    {
        $this->renderer = $renderer;
        $this->table = $table;
    }

    public function __invoke()
    {
        return $this->render('@stripe_admin/index', ['items' => $this->table->findAll()]);
    }
}