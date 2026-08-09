<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SanctumModuleController — migrated legacy modules rendered with v2 design system.
 *
 * Each module here is fully functional in v2 (uses v2 entities/repositories).
 * The templates extend shell.html.twig and use the apiFetch helper.
 *
 * Remaining legacy modules (still in LegacyModuleController):
 *   - /trading
 */
class SanctumModuleController extends AbstractController
{
    #[Route('/journal', name: 'sanctum_journal', methods: ['GET'])]
    public function journal(): Response
    {
        return $this->render('sanctum/journal.html.twig');
    }

    #[Route('/calendar', name: 'sanctum_calendar', methods: ['GET'])]
    public function calendar(): Response
    {
        return $this->render('sanctum/calendar.html.twig');
    }
}
