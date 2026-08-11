<?php

namespace App\Controller\Api;

use App\Entity\EconomicReminder;
use App\Entity\User;
use App\Repository\EconomicReminderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/economic-reminders')]
class EconomicReminderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private EconomicReminderRepository $reminderRepository,
        private UserRepository $userRepository,
    ) {}

    #[Route('/schedule', name: 'api_economic_reminder_schedule', methods: ['POST'])]
    public function schedule(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $userCode = trim((string)($data['user_code'] ?? $request->query->get('user_code') ?? ''));
        $eventDate = trim((string)($data['event_date'] ?? ''));
        $eventTime = trim((string)($data['event_time'] ?? ''));
        $tz = trim((string)($data['timezone'] ?? 'America/Argentina/Buenos_Aires'));
        $title = trim((string)($data['event_title'] ?? ''));
        $titleOriginal = trim((string)($data['event_title_original'] ?? ''));
        $countryCode = trim((string)($data['event_country_code'] ?? ''));
        $currency = trim((string)($data['event_currency'] ?? ''));
        $importance = (int)($data['event_importance'] ?? 3);

        if ($userCode === '' || $eventDate === '' || $eventTime === '' || $title === '') {
            return $this->json(['error' => 'Faltan user_code, event_date, event_time o event_title'], 400);
        }

        $user = $this->userRepository->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $existing = $this->reminderRepository->findExisting($user, $eventDate, $eventTime);
        if ($existing) {
            return $this->json([
                'success' => true,
                'reminder_id' => $existing->getId(),
                'already_existed' => true,
            ]);
        }

        try {
            $eventDt = new \DateTimeImmutable($eventDate . ' ' . $eventTime, new \DateTimeZone($tz));
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Formato de fecha/hora invalido'], 400);
        }

        $remindAt = $eventDt->modify('-15 minutes');
        if ($remindAt < new \DateTimeImmutable()) {
            $remindAt = $eventDt;
        }

        $reminder = new EconomicReminder();
        $reminder->setUser($user);
        $reminder->setEventDate($eventDate);
        $reminder->setEventTime($eventTime);
        $reminder->setTimezone($tz);
        $reminder->setEventTitle($title);
        $reminder->setEventTitleOriginal($titleOriginal);
        $reminder->setEventCountryCode($countryCode);
        $reminder->setEventCurrency($currency);
        $reminder->setEventImportance($importance);
        $reminder->setRemindAt($remindAt);

        $this->em->persist($reminder);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'reminder_id' => $reminder->getId(),
            'remind_at' => $remindAt->format(\DateTimeInterface::ATOM),
            'event_at' => $eventDt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/list', name: 'api_economic_reminder_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $userCode = trim((string)$request->query->get('user_code') ?? '');
        if ($userCode === '') {
            return $this->json(['error' => 'user_code requerido'], 400);
        }
        $user = $this->userRepository->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $reminders = $this->reminderRepository->findByUser($user);
        $payload = array_map(function (EconomicReminder $r) {
            return [
                'id' => $r->getId(),
                'event_date' => $r->getEventDate(),
                'event_time' => $r->getEventTime(),
                'timezone' => $r->getTimezone(),
                'event_title' => $r->getEventTitle(),
                'event_title_original' => $r->getEventTitleOriginal(),
                'event_country_code' => $r->getEventCountryCode(),
                'event_currency' => $r->getEventCurrency(),
                'event_importance' => $r->getEventImportance(),
                'remind_at' => $r->getRemindAt()?->format(\DateTimeInterface::ATOM),
                'status' => $r->getStatus(),
                'fired_at' => $r->getFiredAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $reminders);

        return $this->json(['success' => true, 'reminders' => $payload]);
    }

    #[Route('/{id}/cancel', name: 'api_economic_reminder_cancel', methods: ['POST', 'DELETE'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $reminder = $this->reminderRepository->find($id);
        if (!$reminder) {
            return $this->json(['error' => 'Reminder no encontrado'], 404);
        }

        $userCode = trim((string)($request->query->get('user_code') ?? ''));
        if ($userCode !== '' && $reminder->getUser()?->getCode() !== $userCode) {
            return $this->json(['error' => 'No autorizado'], 403);
        }

        if ($reminder->getStatus() === EconomicReminder::STATUS_FIRED) {
            return $this->json(['error' => 'El reminder ya fue disparado, no se puede cancelar'], 400);
        }

        $reminder->cancel();
        $this->em->flush();

        return $this->json(['success' => true, 'reminder_id' => $id, 'status' => $reminder->getStatus()]);
    }
}
