<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HomeController — public surface (landing + login).
 *
 * After the Home/Login split (Phase 5), the responsibilities are:
 *   /       → landing only (no login form). Auth users → redirect /sanctum.
 *   /home   → alt landing (kept for backwards compatibility).
 *   /login  → dedicated login form (no marketing).
 *
 * The Sanctum-app shell (sidebar, KPIs, etc.) lives under /sanctum/* — see
 * Admin\SanctumController and SanctumModuleController.
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if ($user) {
            return new RedirectResponse('/sanctum');
        }

        return $this->render('public/home.html.twig');
    }

    #[Route('/home', name: 'home_alt', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('public/home.html.twig');
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        $user = $this->getUser();
        if ($user) {
            return new RedirectResponse('/sanctum');
        }

        return $this->render('public/login.html.twig');
    }
}
