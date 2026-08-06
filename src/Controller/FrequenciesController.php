<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrequenciesController extends AbstractController
{
    #[Route('/frequencies', name: 'frequencies_hub', methods: ['GET'])]
    public function hub(): Response
    {
        return $this->render('frequencies/hub.html.twig');
    }
}