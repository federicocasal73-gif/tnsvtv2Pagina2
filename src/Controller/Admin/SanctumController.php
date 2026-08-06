<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sanctum')]
class SanctumController extends AbstractController
{
    #[Route('', name: 'sanctum_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('sanctum/dashboard.html.twig');
    }

    #[Route('/users', name: 'sanctum_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('sanctum/users.html.twig');
    }

    #[Route('/audit', name: 'sanctum_audit', methods: ['GET'])]
    public function audit(): Response
    {
        return $this->render('sanctum/audit.html.twig');
    }
}