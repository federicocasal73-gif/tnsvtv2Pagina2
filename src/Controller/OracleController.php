<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OracleController extends AbstractController
{
    #[Route('/oracle', name: 'oracle_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('oracle/dashboard.html.twig');
    }
}