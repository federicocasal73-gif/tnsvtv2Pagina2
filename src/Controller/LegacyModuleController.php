<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LegacyModuleController — bridges v2 admin to legacy tnsvt.com modules.
 *
 * These routes exist in v2 so the admin sanctum can have nav links
 * to all features, even if the actual feature modules are still
 * running on the legacy codebase (tnsvt.com).
 *
 * The v2 project focuses on: Dashboard, Tasks, Users, Audit, Settings,
 * Monitoring, Oracle, Macro, Frequencies.
 * Everything else (Chat, Journal, Feed, Calendar, Diario) is still in legacy.
 */
class LegacyModuleController extends AbstractController
{
    /**
     * Each legacy module route renders a polished placeholder page
     * that explains the status and links back to the legacy site.
     * Once a module is fully migrated to v2, this controller is replaced
     * with the real module controller.
     */

    // /journal migrated to SanctumModuleController::journal()


    // /chat migrated to SanctumModuleController::chat()


    #[Route('/feed', name: 'legacy_feed', methods: ['GET'])]
    public function feed(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Salón del Cónclave',
            'icon' => 'forum',
            'description' => 'Feed comunitario con señales, resultados y proyecciones',
            'legacy_path' => '/feed',
            'features' => ['Posts', 'Señales', 'Resultados', 'Comentarios'],
        ]);
    }

    // /calendar migrated to SanctumModuleController::calendar()


    #[Route('/diario', name: 'legacy_diario', methods: ['GET'])]
    public function diario(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Diario Personal',
            'icon' => 'menu_book',
            'description' => 'Tu diario privado con reflexiones y bitácora',
            'legacy_path' => '/diary',
            'features' => ['Entradas privadas', 'Reflexiones', 'Bitácora', 'Privacidad'],
        ]);
    }

    #[Route('/trading', name: 'legacy_trading', methods: ['GET'])]
    public function trading(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Trading',
            'icon' => 'candlestick_chart',
            'description' => 'Plataforma de trading con cuentas, órdenes y posiciones',
            'legacy_path' => '/trading',
            'features' => ['Cuentas', 'Órdenes', 'Posiciones', 'P&L tracking'],
        ]);
    }
}