<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HomeController — root URL redirect.
 * Fixes the 404 on / by redirecting to /sanctum.
 * Also serves a small landing page with feature links.
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        // Check if user is authenticated - if so, go straight to sanctum
        $user = $this->getUser();
        if ($user) {
            return new RedirectResponse('/sanctum');
        }

        // Show landing page for anonymous users with link to features
        return $this->render('home.html.twig');
    }

    #[Route('/home', name: 'home_alt', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('home.html.twig');
    }
}