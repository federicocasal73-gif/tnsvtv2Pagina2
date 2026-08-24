<?php

namespace App\Controller\Api;

use App\Entity\MacroQuestionnaire;
use App\Entity\User;
use App\Repository\MacroQuestionnaireRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TNSVT Sprint C — Macro Questionnaires API.
 *
 * Endpoints para gestionar cuestionarios del usuario (perfil de riesgo,
 * conocimiento del mercado). Cada usuario puede tener UN cuestionario
 * por tipo.
 */
#[Route('/api/macro')]
class MacroQuestionnaireController extends AbstractController
{
    /** Preguntas del cuestionario de perfil de riesgo */
    public const RISK_PROFILE_QUESTIONS = [
        [
            'id' => 'horizon',
            'label' => '¿Cuál es tu horizonte temporal preferido?',
            'options' => [
                'intraday' => ['label' => 'Intradía (1 día)', 'score' => 80],
                'short'    => ['label' => 'Corto plazo (1-2 semanas)', 'score' => 60],
                'medium'   => ['label' => 'Mediano plazo (1-3 meses)', 'score' => 40],
                'long'     => ['label' => 'Largo plazo (>3 meses)', 'score' => 20],
            ],
        ],
        [
            'id' => 'risk_per_trade',
            'label' => '¿Qué porcentaje de tu capital estás dispuesto a arriesgar por operación?',
            'options' => [
                'lt_1'   => ['label' => 'Menos del 1%', 'score' => 10],
                '1_2'    => ['label' => '1-2%', 'score' => 30],
                '2_5'    => ['label' => '2-5%', 'score' => 60],
                'gt_5'    => ['label' => 'Más de 5%', 'score' => 90],
            ],
        ],
        [
            'id' => 'reaction_loss',
            'label' => '¿Cómo reaccionas ante una pérdida del 10% en una posición?',
            'options' => [
                'close'  => ['label' => 'Cierro inmediatamente', 'score' => 20],
                'reduce' => ['label' => 'Reduzco la posición', 'score' => 30],
                'wait'   => ['label' => 'Espero la recuperación', 'score' => 60],
                'add'    => ['label' => 'Aumento posición (promedio abajo)', 'score' => 90],
            ],
        ],
        [
            'id' => 'concurrent_trades',
            'label' => '¿Cuántas operaciones activas sueles mantener simultáneamente?',
            'options' => [
                '1_2'   => ['label' => '1-2', 'score' => 20],
                '3_5'   => ['label' => '3-5', 'score' => 40],
                '6_10'  => ['label' => '6-10', 'score' => 60],
                'gt_10' => ['label' => 'Más de 10', 'score' => 80],
            ],
        ],
        [
            'id' => 'main_indicator',
            'label' => '¿Qué tipo de análisis usas principalmente?',
            'options' => [
                'price_action' => ['label' => 'Price Action', 'score' => 60],
                'technical'    => ['label' => 'Indicadores técnicos', 'score' => 50],
                'fundamental'  => ['label' => 'Análisis fundamental/macro', 'score' => 30],
                'combined'     => ['label' => 'Combinación de varios', 'score' => 40],
            ],
        ],
    ];

    /** Preguntas del cuestionario de conocimiento del mercado */
    public const MARKET_KNOWLEDGE_QUESTIONS = [
        [
            'id' => 'sl_vs_tp',
            'label' => '¿Conoces la diferencia entre Stop Loss y Take Profit?',
            'options' => [
                'yes_full'  => ['label' => 'Sí, en detalle', 'score' => 100],
                'yes_basic' => ['label' => 'Sí, básicamente', 'score' => 60],
                'unsure'    => ['label' => 'No estoy seguro', 'score' => 20],
                'no'        => ['label' => 'No', 'score' => 0],
            ],
        ],
        [
            'id' => 'pip',
            'label' => '¿Sabes qué es un pip en forex?',
            'options' => [
                'yes'      => ['label' => 'Sí, y sé calcularlo', 'score' => 100],
                'idea'     => ['label' => 'Tengo una idea', 'score' => 50],
                'no'       => ['label' => 'No', 'score' => 0],
            ],
        ],
        [
            'id' => 'tech_vs_fund',
            'label' => '¿Conoces la diferencia entre análisis técnico y fundamental?',
            'options' => [
                'yes'    => ['label' => 'Sí, claramente', 'score' => 100],
                'partial'=> ['label' => 'Parcialmente', 'score' => 50],
                'no'     => ['label' => 'No', 'score' => 0],
            ],
        ],
        [
            'id' => 'derivatives',
            'label' => '¿Has operado con derivados (futuros, opciones)?',
            'options' => [
                'yes_live' => ['label' => 'Sí, con dinero real', 'score' => 100],
                'yes_paper'=> ['label' => 'Sí, en demo/paper', 'score' => 60],
                'know'     => ['label' => 'Conozco pero no operé', 'score' => 30],
                'no'       => ['label' => 'No conozco', 'score' => 0],
            ],
        ],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private MacroQuestionnaireRepository $questionnaireRepository,
        private UserRepository $userRepository,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;

        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) {
                $code = trim((string) $data['code']);
            }
        }
        if (!$code) return null;

        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    #[Route('/questionnaire/{type}', name: 'api_macro_questionnaire_get', methods: ['GET'], requirements: ['type' => 'risk_profile|market_knowledge'])]
    public function getQuestionnaire(string $type, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $questions = $type === MacroQuestionnaire::TYPE_RISK_PROFILE
            ? self::RISK_PROFILE_QUESTIONS
            : self::MARKET_KNOWLEDGE_QUESTIONS;

        $existing = $this->questionnaireRepository->findByUserAndType($user, $type);

        return new JsonResponse([
            'success' => true,
            'type' => $type,
            'questions' => $questions,
            'completed' => $existing !== null,
            'answers' => $existing?->getAnswers(),
            'score' => $existing?->getScore(),
            'tier' => $existing?->getTier(),
        ]);
    }

    #[Route('/questionnaire/{type}', name: 'api_macro_questionnaire_submit', methods: ['POST'], requirements: ['type' => 'risk_profile|market_knowledge'])]
    public function submitQuestionnaire(string $type, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
            return new JsonResponse(['error' => 'answers required'], 400);
        }

        $questions = $type === MacroQuestionnaire::TYPE_RISK_PROFILE
            ? self::RISK_PROFILE_QUESTIONS
            : self::MARKET_KNOWLEDGE_QUESTIONS;

        // Validar y calcular score
        $score = 0;
        $validAnswers = [];
        foreach ($questions as $q) {
            $qid = $q['id'];
            $answer = $data['answers'][$qid] ?? null;
            if ($answer === null || !isset($q['options'][$answer])) {
                return new JsonResponse(['error' => "Falta respuesta para: $qid"], 400);
            }
            $score += $q['options'][$answer]['score'];
            $validAnswers[$qid] = $answer;
        }
        // Normalizar a 0-100 (cada pregunta puede aportar hasta 100, pero el máximo típico es menor)
        $score = min(100, intval($score / count($questions)));

        $existing = $this->questionnaireRepository->findByUserAndType($user, $type);
        if (!$existing) {
            $existing = new MacroQuestionnaire();
            $existing->setUser($user);
            $existing->setQuestionnaireType($type);
            $this->em->persist($existing);
        }
        $existing->setAnswers($validAnswers);
        $existing->setScore($score);
        $existing->setCompletedAt(new \DateTimeImmutable());
        $existing->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'score' => $existing->getScore(),
            'tier' => $existing->getTier(),
            'answers' => $existing->getAnswers(),
        ]);
    }

    #[Route('/questionnaires', name: 'api_macro_questionnaires_list', methods: ['GET'])]
    public function listQuestionnaires(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $items = $this->questionnaireRepository->findByUser($user);
        $result = [];
        foreach ($items as $q) {
            $result[] = [
                'type' => $q->getQuestionnaireType(),
                'score' => $q->getScore(),
                'tier' => $q->getTier(),
                'completed_at' => $q->getCompletedAt()?->format('c'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'questionnaires' => $result,
        ]);
    }
}
