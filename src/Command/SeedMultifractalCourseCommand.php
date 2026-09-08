<?php

namespace App\Command;

use App\Entity\CampusCourse;
use App\Entity\CampusLesson;
use App\Entity\CampusModule;
use App\Repository\CampusCourseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the "Multifractal / 2 Steps" course as a 6-lesson module inside Campus.
 *
 * Restores the iconic V1 educational module as a lesson series so it lives
 * inside the existing Courses → Modules → Lessons hierarchy (no new top-level
 * route needed).
 *
 * Lessons mirror the V1 tabs:
 *   1. Teoría
 *   2. BOS (Break of Structure)
 *   3. LG (Liquidity Grab)
 *   4. Entrada
 *   5. Timeframes
 *   6. Checklist (validación con resultado dinámico)
 *
 * Run: php bin/console app:seed-multifractal-course
 */
#[AsCommand(
    name: 'app:seed-multifractal-course',
    description: 'Seed the Multifractal / 2 Steps course (6 lessons) inside Campus',
)]
class SeedMultifractalCourseCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CampusCourseRepository $courseRepo,
    ) {
        parent::__construct();
    }

    private const COURSE_TITLE = 'Multifractal / 2 Steps';
    private const MODULE_TITLE = 'El Método';

    private const LESSONS = [
        [
            'title' => '1. Teoría — El Mercado como Fractal',
            'description' => <<<'HTML'
<p>El principio fundamental: <strong class="text-[var(--gold-elev)]">los mercados se mueven en fractales</strong>. Lo que ocurre en un timeframe mayor se replica en uno menor. Un Break of Structure en H4 tiene la misma firma que uno en M15, solo cambia el ruido.</p>
<p>Esta teoría es la base del método 2 Steps: identificar el movimiento dominante en un TF alto y luego esperar la confirmación en un TF bajo antes de ejecutar.</p>
<ul class="list-disc pl-5 space-y-1 mt-3 text-sm">
    <li>Auto-similitud: misma estructura en todos los TF.</li>
    <li>Jerarquía: H4/H1 manda, M15/M5 ejecuta.</li>
    <li>Probabilidad, no certeza: el fractal reduce el ruido, no lo elimina.</li>
</ul>
HTML,
        ],
        [
            'title' => '2. BOS — Break of Structure',
            'description' => <<<'HTML'
<p>El <strong class="text-[var(--gold-elev)]">Break of Structure</strong> es la ruptura del último máximo/mínimo relevante. Marca el inicio de un nuevo impulse direccional.</p>
<p>En un mercado alcista: el precio rompe el último higher high → BOS alcista → buscamos compras en retroceso.</p>
<p>En un mercado bajista: el precio rompe el último lower low → BOS bajista → buscamos ventas en retroceso.</p>
<div class="mt-3 p-3 rounded bg-[var(--glass-bg-elev)] border border-[var(--glass-border-elev)] text-sm">
    <strong>Regla TNSVT:</strong> Un BOS sin volumen es una trampa. Espera la vela de cierre, no la mecha.
</div>
HTML,
        ],
        [
            'title' => '3. LG — Liquidity Grab',
            'description' => <<<'HTML'
<p>El <strong class="text-[var(--gold-elev)]">Liquidity Grab</strong> es cuando el precio toma los stops obvios (máximos/mínimos relativos) antes de revertir. Es la trampa que el mercado tiende a las manos minoristas.</p>
<p>Señales:</p>
<ul class="list-disc pl-5 space-y-1 mt-2 text-sm">
    <li>El precio supera brevemente el máximo anterior y cierra de vuelta abajo.</li>
    <li>Aparece en zonas de stop-loss visible (igual high/low múltiples veces).</li>
    <li>Suele coincidir con el final de un impulse y el inicio de un retracement.</li>
</ul>
<p class="mt-3">El LG + BOS juntos son el setup de máxima probabilidad del método 2 Steps.</p>
HTML,
        ],
        [
            'title' => '4. Entrada — El 2 Steps',
            'description' => <<<'HTML'
<p>El <strong class="text-[var(--gold-elev)]">2 Steps</strong> son dos confirmaciones consecutivas en distintos timeframes:</p>
<ol class="list-decimal pl-5 space-y-2 mt-3 text-sm">
    <li><strong>Step 1 — TF alto (H1/H4):</strong> BOS o cambio de estructura claro + retroceso a zona de interés (OTE 62-79% del impulse anterior).</li>
    <li><strong>Step 2 — TF bajo (M5/M15):</strong> Break of Structure menor en la dirección del Step 1 → entrada al cierre de la vela.</li>
</ol>
<div class="mt-3 p-3 rounded bg-[var(--glass-bg-elev)] border border-[var(--glass-border-elev)] text-sm">
    <strong>Stop Loss:</strong> detrás del LG o del OTE, no en el BOS. <strong>TP:</strong> próximo high/low de liquidez o RR mínimo 1:2.
</div>
HTML,
        ],
        [
            'title' => '5. Timeframes — La Pirámide',
            'description' => <<<'HTML'
<p>Estructura de timeframes recomendada:</p>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3 text-sm">
    <div class="p-3 rounded bg-[var(--violet-elev)]/10 border border-[var(--violet-elev)]/30">
        <strong class="block text-[var(--gold-elev)] mb-1">Contexto</strong>
        <span class="text-xs">H4 / D1<br>Define la tendencia macro y las zonas de liquidez.</span>
    </div>
    <div class="p-3 rounded bg-[var(--gold-elev)]/10 border border-[var(--gold-elev)]/30">
        <strong class="block text-[var(--gold-elev)] mb-1">Estructura</strong>
        <span class="text-xs">H1 / M30<br>Identifica BOS y OTE.</span>
    </div>
    <div class="p-3 rounded bg-[var(--success-elev)]/10 border border-[var(--success-elev)]/30">
        <strong class="block text-[var(--gold-elev)] mb-1">Ejecución</strong>
        <span class="text-xs">M15 / M5<br>Step 2 — entrada.</span>
    </div>
</div>
<p class="mt-3 text-sm">Si los tres TF están alineados, el setup tiene probabilidad máxima. Si el TF alto contradice, no operes.</p>
HTML,
        ],
        [
            'title' => '6. Checklist — Validación en Tiempo Real',
            'description' => <<<'HTML'
<p>Antes de ejecutar, marca las 4 condiciones. Si se cumplen todas, el setup está validado:</p>
<div id="multifractal-checklist" class="space-y-2 mt-4 text-sm" data-controller="multifractal-checklist">
    <label class="flex items-center gap-3 p-3 rounded border border-[var(--glass-border-elev)] cursor-pointer hover:border-[var(--gold-elev)] transition">
        <input type="checkbox" class="mf-check w-4 h-4 accent-[var(--gold-elev)]" data-action="change->multifractal-checklist#recalc">
        <span>1. BOS claro en TF alto (H1/H4) y dirección definida.</span>
    </label>
    <label class="flex items-center gap-3 p-3 rounded border border-[var(--glass-border-elev)] cursor-pointer hover:border-[var(--gold-elev)] transition">
        <input type="checkbox" class="mf-check w-4 h-4 accent-[var(--gold-elev)]" data-action="change->multifractal-checklist#recalc">
        <span>2. Precio en zona OTE (62-79%) del impulse anterior.</span>
    </label>
    <label class="flex items-center gap-3 p-3 rounded border border-[var(--glass-border-elev)] cursor-pointer hover:border-[var(--gold-elev)] transition">
        <input type="checkbox" class="mf-check w-4 h-4 accent-[var(--gold-elev)]" data-action="change->multifractal-checklist#recalc">
        <span>3. LG visible (toma de stops) en TF bajo.</span>
    </label>
    <label class="flex items-center gap-3 p-3 rounded border border-[var(--glass-border-elev)] cursor-pointer hover:border-[var(--gold-elev)] transition">
        <input type="checkbox" class="mf-check w-4 h-4 accent-[var(--gold-elev)]" data-action="change->multifractal-checklist#recalc">
        <span>4. Step 2 confirmado (BOS menor en M5/M15).</span>
    </label>
</div>
<div id="mf-result" class="mt-4 p-4 rounded bg-[var(--glass-bg-elev)] border border-[var(--glass-border-elev)] text-center">
    <p class="text-sm text-[var(--outline-elev)]">Marca las 4 condiciones para validar el setup</p>
</div>
HTML,
        ],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $course = $this->courseRepo->findOneBy(['title' => self::COURSE_TITLE]);
        if ($course) {
            $io->warning('El curso "' . self::COURSE_TITLE . '" ya existe (id ' . $course->getId() . '). No se hace nada.');
            return Command::SUCCESS;
        }

        $course = new CampusCourse();
        $course->setTitle(self::COURSE_TITLE);
        $course->setDescription('Método Multifractal / 2 Steps — la estrategia icónica de TNSVT para ejecutar con precisión. 6 lecciones que cubren Teoría, BOS, Liquidity Grab, Entrada, Timeframes y Checklist de validación.');
        $course->setEmoji('⛧');
        $course->setIsActive(true);
        $course->setOrden(999);
        $this->em->persist($course);

        $module = new CampusModule();
        $module->setCourse($course);
        $module->setTitle(self::MODULE_TITLE);
        $module->setDescription('Las 6 etapas del método 2 Steps, basadas en la lectura multifractal del mercado.');
        $module->setOrden(1);
        $this->em->persist($module);

        foreach (self::LESSONS as $index => $lessonData) {
            $lesson = new CampusLesson();
            $lesson->setModule($module);
            $lesson->setTitle($lessonData['title']);
            $lesson->setDescription($lessonData['description']);
            $lesson->setOrden($index + 1);
            $this->em->persist($lesson);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Curso "%s" creado con 6 lecciones (id %d). Visible en Campus.',
            self::COURSE_TITLE,
            $course->getId()
        ));

        return Command::SUCCESS;
    }
}
