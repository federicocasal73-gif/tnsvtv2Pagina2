<?php

namespace App\Controller\Api;

use App\Entity\CalendarEvent;
use App\Entity\ClassBooking;
use App\Entity\MentorAvailability;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\ClassBookingRepository;
use App\Repository\MentorAvailabilityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * F8 — Academic: eventos, disponibilidad de mentores, reservas 1:1.
 */
#[Route('/api/academic')]
class AcademicController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CalendarEventRepository $eventRepo,
        private MentorAvailabilityRepository $availRepo,
        private ClassBookingRepository $bookingRepo,
        private UserRepository $userRepo,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) $code = trim((string) $data['code']);
        }
        if (!$code) {
            $code = trim($request->query->get('code', ''));
        }
        if (!$code) return null;
        return $this->userRepo->findOneBy(['code' => $code, 'active' => true]);
    }

    private function isMentorOrAdmin(User $u): bool
    {
        foreach ($u->getRoles() as $r) {
            if (str_contains($r, 'MENTOR') || str_contains($r, 'ADMIN')) return true;
        }
        return false;
    }

    private function notify(User $to, string $type, string $content, ?string $link = null): void
    {
        $n = new Notification();
        $n->setUser($to);
        $n->setType($type);
        $n->setContent($content);
        $n->setLink($link);
        $this->em->persist($n);
    }

    // ══════ Calendar events ══════

    #[Route('/events', name: 'api_academic_events_list', methods: ['GET'])]
    public function listEvents(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $start = $request->query->get('start') ? new \DateTimeImmutable($request->query->get('start')) : null;
        $end   = $request->query->get('end')   ? new \DateTimeImmutable($request->query->get('end'))   : null;
        $type  = $request->query->get('type');
        $mentorCode = $request->query->get('mentor_code');

        $events = $this->eventRepo->findInRange($start, $end, $type, $mentorCode);
        return $this->json([
            'success' => true,
            'events' => array_map(fn(CalendarEvent $e) => $e->toArray(), $events),
        ]);
    }

    #[Route('/events', name: 'api_academic_events_create', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isMentorOrAdmin($user)) {
            return $this->json(['error' => 'Solo mentores o admin pueden crear eventos'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['title']) || empty($data['starts_at'])) {
            return $this->json(['error' => 'title y starts_at son obligatorios'], 400);
        }

        $ev = new CalendarEvent();
        $ev->setOwner($user);
        $ev->setTitle(trim((string) $data['title']));
        $ev->setDescription($data['description'] ?? null);
        $ev->setType(isset($data['type']) ? (string) $data['type'] : CalendarEvent::TYPE_CLASS);
        $ev->setStartsAt(new \DateTimeImmutable($data['starts_at']));
        if (!empty($data['ends_at'])) {
            $ev->setEndsAt(new \DateTimeImmutable($data['ends_at']));
        }
        if (!empty($data['mentor_code'])) {
            $mentor = $this->userRepo->findByCode((string) $data['mentor_code']);
            if ($mentor) $ev->setMentor($mentor);
        }
        $ev->setLocation($data['location'] ?? null);
        $ev->setMeetingUrl($data['meeting_url'] ?? null);
        $ev->setStatus(CalendarEvent::STATUS_SCHEDULED);
        $ev->setColor($data['color'] ?? null);
        $ev->setMaxAttendees((int) ($data['max_attendees'] ?? 0));

        $this->em->persist($ev);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $ev->getId()], 201);
    }

    // ══════ Mentor availability ══════

    #[Route('/availability', name: 'api_academic_avail_list', methods: ['GET'])]
    public function listAvail(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $mentorCode = $request->query->get('mentor_code');
        if (!$mentorCode) {
            $mentorCode = $user->getCode();
        }
        $day = $request->query->get('day');
        $items = $this->availRepo->findByMentor($mentorCode, $day !== null ? (int) $day : null);
        return $this->json([
            'success' => true,
            'mentor_code' => $mentorCode,
            'items' => array_map(fn(MentorAvailability $a) => $a->toArray(), $items),
        ]);
    }

    #[Route('/availability', name: 'api_academic_avail_create', methods: ['POST'])]
    public function createAvail(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) return $this->json(['error' => 'JSON inválido'], 400);

        $mentorCode = $data['mentor_code'] ?? $user->getCode();
        $mentor = $this->userRepo->findByCode($mentorCode);
        if (!$mentor) return $this->json(['error' => 'Mentor no encontrado'], 404);

        // El mentor que define su disponibilidad debe ser admin o el mismo mentor
        if ($mentor->getCode() !== $user->getCode() && !$this->isMentorOrAdmin($user)) {
            return $this->json(['error' => 'No autorizado'], 403);
        }

        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [$data];
        $created = 0;
        foreach ($items as $it) {
            $av = new MentorAvailability();
            $av->setMentor($mentor);
            $av->setDayOfWeek((int) ($it['day_of_week'] ?? 1));
            if (empty($it['start_time']) || empty($it['end_time'])) continue;
            $av->setStartTime(new \DateTimeImmutable($it['start_time']));
            $av->setEndTime(new \DateTimeImmutable($it['end_time']));
            $av->setStatus(MentorAvailability::STATUS_OPEN);
            $this->em->persist($av);
            $created++;
        }
        $this->em->flush();
        return $this->json(['success' => true, 'created' => $created], 201);
    }

    // ══════ Class bookings (1:1) ══════

    #[Route('/bookings', name: 'api_academic_bookings_list', methods: ['GET'])]
    public function listBookings(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $filters = [
            'status' => $request->query->get('status'),
            'upcoming_only' => $request->query->get('upcoming') === '1',
        ];
        // Por defecto: bookings propios (como alumno o como mentor)
        $scope = $request->query->get('scope', 'me');
        if ($scope === 'all' && $this->isMentorOrAdmin($user)) {
            // ok admin/mentor puede ver todos
        } else {
            $studentCond = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(User::class, 'u')
                ->where('u.code = :c AND u.id = b.student')
                ->getDQL();
            $mentorCond = $this->getEntityManager()->createQueryBuilder()
                ->select('1')->from(User::class, 'u2')
                ->where('u2.code = :c AND u2.id = b.mentor')
                ->getDQL();

            $bookings = $this->bookingRepo->createQueryBuilder('b')
                ->where('(' . $studentCond . ') OR (' . $mentorCond . ')')
                ->setParameter('c', $user->getCode())
                ->orderBy('b.startAt', 'ASC')
                ->getQuery()
                ->getResult();
            if (!empty($filters['status'])) {
                $bookings = array_filter($bookings, fn(ClassBooking $b) => $b->getStatus() === $filters['status']);
            }
            if (!empty($filters['upcoming_only'])) {
                $now = new \DateTimeImmutable();
                $bookings = array_filter($bookings, fn(ClassBooking $b) =>
                    $b->getStartAt() !== null && $b->getStartAt() >= $now);
            }
            return $this->json([
                'success' => true,
                'bookings' => array_map(fn(ClassBooking $b) => $b->toArray(), $bookings),
            ]);
        }

        $bookings = $this->bookingRepo->findFiltered($filters);
        return $this->json([
            'success' => true,
            'bookings' => array_map(fn(ClassBooking $b) => $b->toArray(), $bookings),
        ]);
    }

    #[Route('/bookings', name: 'api_academic_bookings_create', methods: ['POST'])]
    public function createBooking(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['mentor_code']) || empty($data['start_at']) || empty($data['topic'])) {
            return $this->json(['error' => 'mentor_code, start_at y topic son obligatorios'], 400);
        }

        $mentor = $this->userRepo->findByCode((string) $data['mentor_code']);
        if (!$mentor) return $this->json(['error' => 'Mentor no encontrado'], 404);

        $booking = new ClassBooking();
        $booking->setStudent($user);
        $booking->setMentor($mentor);
        $booking->setStartAt(new \DateTimeImmutable($data['start_at']));
        $booking->setDurationMinutes((int) ($data['duration_minutes'] ?? 30));
        $booking->setTopic(trim((string) $data['topic']));
        $booking->setNotes($data['notes'] ?? null);
        $booking->setStatus(ClassBooking::STATUS_PENDING);

        if (!empty($data['event_id'])) {
            $ev = $this->eventRepo->find((int) $data['event_id']);
            if ($ev) $booking->setEvent($ev);
        }

        $this->em->persist($booking);
        $this->em->flush();

        // Notificar al mentor
        $this->notify(
            $mentor,
            'class_booking_request',
            sprintf(
                '%s solicita una clase 1:1 sobre "%s"',
                $user->getName() ?? $user->getCode(),
                $booking->getTopic()
            ),
            '/sanctum/admin/bookings'
        );
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $booking->getId()], 201);
    }

    #[Route('/bookings/{id}/accept', name: 'api_academic_bookings_accept', methods: ['POST'])]
    public function acceptBooking(int $id, Request $request): JsonResponse
    {
        return $this->mutateBooking($id, $request, ClassBooking::STATUS_ACCEPTED, null,
            'class_booking_accepted', 'aceptó tu solicitud de clase');
    }

    #[Route('/bookings/{id}/decline', name: 'api_academic_bookings_decline', methods: ['POST'])]
    public function declineBooking(int $id, Request $request): JsonResponse
    {
        return $this->mutateBooking($id, $request, ClassBooking::STATUS_DECLINED, null,
            'class_booking_declined', 'no pudo aceptar tu solicitud');
    }

    #[Route('/bookings/{id}/propose', name: 'api_academic_bookings_propose', methods: ['POST'])]
    public function proposeBooking(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $booking = $this->bookingRepo->find($id);
        if (!$booking || $booking->getMentor()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'No autorizado'], 403);
        }
        $data = json_decode($request->getContent(), true);
        $proposed = is_array($data['proposed_times'] ?? null) ? $data['proposed_times'] : [];
        $booking->setProposedTimes($proposed);
        $booking->setStatus(ClassBooking::STATUS_PROPOSED);
        $booking->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    #[Route('/bookings/{id}/cancel', name: 'api_academic_bookings_cancel', methods: ['POST'])]
    public function cancelBooking(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $booking = $this->bookingRepo->find($id);
        if (!$booking) return $this->json(['error' => 'No encontrado'], 404);
        if ($booking->getStudent()?->getId() !== $user->getId()
            && $booking->getMentor()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'No autorizado'], 403);
        }
        $booking->setStatus(ClassBooking::STATUS_CANCELED);
        $booking->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    private function mutateBooking(int $id, Request $request, string $newStatus, ?array $proposedTimes,
                                   string $notifyType, string $notifyMsgPrefix): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $booking = $this->bookingRepo->find($id);
        if (!$booking || $booking->getMentor()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'No autorizado'], 403);
        }
        if ($proposedTimes !== null) $booking->setProposedTimes($proposedTimes);
        $booking->setStatus($newStatus);
        $booking->setUpdatedAt(new \DateTimeImmutable());

        $this->notify(
            $booking->getStudent(),
            $notifyType,
            sprintf(
                '%s %s · %s',
                $user->getName() ?? $user->getCode(),
                $notifyMsgPrefix,
                $booking->getTopic()
            ),
            '/sanctum/admin/bookings'
        );
        $this->em->flush();
        return $this->json(['success' => true]);
    }
}
