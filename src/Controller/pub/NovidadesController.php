<?php

namespace App\Controller\pub;

use App\Service\Novidades\NovidadesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Histórico de manutenções do portal, em linguagem simples, com o código de cada ajuste no git.
 */
class NovidadesController extends AbstractController
{
    public function __construct(private readonly NovidadesService $novidades)
    {
    }

    #[Route('/novidades', name: 'app_pub_news', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pub/novidades/index.html.twig', [
            'entries' => $this->novidades->entries(),
        ]);
    }
}
