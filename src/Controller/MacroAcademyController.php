<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MacroAcademyController extends AbstractController
{
    #[Route('/macro/academy', name: 'macro_academy', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('macro/academy.html.twig');
    }
}
