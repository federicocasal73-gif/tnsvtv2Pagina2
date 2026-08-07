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

    #[Route('/tasks', name: 'sanctum_tasks', methods: ['GET'])]
    public function tasks(): Response
    {
        return $this->render('sanctum/tasks.html.twig');
    }

    #[Route('/settings', name: 'sanctum_settings', methods: ['GET'])]
    public function settings(): Response
    {
        return $this->render('sanctum/settings.html.twig');
    }

    #[Route('/monitoring', name: 'sanctum_monitoring', methods: ['GET'])]
    public function monitoring(): Response
    {
        return $this->render('sanctum/monitoring.html.twig');
    }
}