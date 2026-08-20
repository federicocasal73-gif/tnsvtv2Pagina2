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


    // /feed migrated to SanctumModuleController::feed()


    // /calendar migrated to SanctumModuleController::calendar()


    // /diario migrated to SanctumModuleController::diary()


    #[Route('/trading', name: 'legacy_trading', methods: ['GET'])]
    public function trading(): RedirectResponse
    {
        return $this->redirectToRoute('sanctum_journal_new');
    }
}