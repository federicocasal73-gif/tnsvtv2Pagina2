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

    #[Route('/journal', name: 'legacy_journal', methods: ['GET'])]
    public function journal(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Trading Journal',
            'icon' => 'edit_note',
            'description' => 'Tu historial completo de trades con análisis emocional y métricas',
            'legacy_path' => '/journal',
            'features' => ['Journal entries', 'Emotional analysis', 'PnL tracking', 'Asset breakdown'],
        ]);
    }

    #[Route('/chat', name: 'legacy_chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Mensajes',
            'icon' => 'chat',
            'description' => 'Mensajería directa entre miembros del Sanctum',
            'legacy_path' => '/messages',
            'features' => ['Mensajes privados', 'Grupos', 'Adjuntos', 'Read receipts'],
        ]);
    }

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

    #[Route('/calendar', name: 'legacy_calendar', methods: ['GET'])]
    public function calendar(): Response
    {
        return $this->render('legacy/redirect.html.twig', [
            'title' => 'Calendario',
            'icon' => 'calendar_month',
            'description' => 'Calendario económico con eventos macro y recordatorios',
            'legacy_path' => '/calendar',
            'features' => ['Eventos macro', 'No-trade windows', 'Recordatorios', 'Histórico'],
        ]);
    }

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