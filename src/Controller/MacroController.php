<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MacroController extends AbstractController
{
    #[Route('/macro', name: 'macro_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('macro/dashboard.html.twig');
    }
}