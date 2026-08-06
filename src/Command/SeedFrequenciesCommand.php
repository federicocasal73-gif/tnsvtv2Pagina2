<?php

namespace App\Command;

use App\Entity\FrequencyPreset;
use App\Entity\UserFrequency;
use App\Repository\FrequencyPresetRepository;
use App\Repository\UserFrequencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the default Solfeggio frequencies + 432Hz (Phase 3 — Audio Hub).
 *
 * Run: php bin/console app:seed-frequencies
 */
#[AsCommand(
    name: 'app:seed-frequencies',
    description: 'Seed the default Solfeggio frequencies + 432Hz in frequency_presets table',
)]
class SeedFrequenciesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private FrequencyPresetRepository $presetRepo,
    ) {
        parent::__construct();
    }

    private const PRESETS = [
        // Earth/Universal tuning
        ['name' => 'Universal 432 Hz', 'frequency' => 432, 'category' => 'tuning', 'description' => 'Frecuencia natural de la Tierra. Algunos la llaman "Verdi tuning" - supuestamente más armónica con la naturaleza que el estándar 440Hz.', 'benefits' => ['meditation', 'grounding', 'calm']],
        // Solfeggio frequencies (9 original)
        ['name' => 'UT - 396 Hz (Liberación)', 'frequency' => 396, 'category' => 'solfeggio', 'description' => 'Frecuencia UT - libera miedo y culpa. Conecta con la energía de la Tierra.', 'benefits' => ['liberation', 'grounding', 'fear_release']],
        ['name' => 'RE - 417 Hz (Cambio)', 'frequency' => 417, 'category' => 'solfeggio', 'description' => 'Frecuencia RE - facilita el cambio y despeja situaciones traumáticas.', 'benefits' => ['change', 'transformation', 'healing']],
        ['name' => 'MI - 528 Hz (Milagros / Reparación DNA)', 'frequency' => 528, 'category' => 'solfeggio', 'description' => 'Frecuencia MI - la frecuencia del amor y la reparación del ADN. Frecuencia de los milagros.', 'benefits' => ['dna_repair', 'love', 'miracles']],
        ['name' => 'FA - 639 Hz (Conexión)', 'frequency' => 639, 'category' => 'solfeggio', 'description' => 'Frecuencia FA - facilita la conexión, las relaciones y la comunicación.', 'benefits' => ['relationships', 'communication', 'harmony']],
        ['name' => 'SOL - 741 Hz (Despertar Intuición)', 'frequency' => 741, 'category' => 'solfeggio', 'description' => 'Frecuencia SOL - activa la intuición y la capacidad de resolver problemas.', 'benefits' => ['intuition', 'awakening', 'expression']],
        ['name' => 'LA - 852 Hz (Orden Espiritual)', 'frequency' => 852, 'category' => 'solfeggio', 'description' => 'Frecuencia LA - despierta la intuición y vuelve al orden espiritual.', 'benefits' => ['awakening', 'spiritual_order', 'intuition']],
        ['name' => 'TI - 963 Hz (Conciencia Divina)', 'frequency' => 963, 'category' => 'solfeggio', 'description' => 'Frecuencia TI - la frecuencia de los Dioses. Conecta con la conciencia superior.', 'benefits' => ['divine_consciousness', 'unity', 'awakening']],
        // Additional healing frequencies
        ['name' => '174 Hz - Anestesia Natural', 'frequency' => 174, 'category' => 'healing', 'description' => 'La frecuencia más baja de Solfeggio. Reduce dolor físico y emocional.', 'benefits' => ['pain_relief', 'grounding', 'calm']],
        ['name' => '285 Hz - Regeneración', 'frequency' => 285, 'category' => 'healing', 'description' => 'Regeneración de tejidos y huesos. Influencia sobre la estructura celular.', 'benefits' => ['regeneration', 'healing', 'tissue_repair']],
        // Chakra frequencies
        ['name' => 'Muladhara (Root) - 256 Hz', 'frequency' => 256, 'category' => 'chakra', 'description' => 'Chakra Raíz - conexión con la tierra, seguridad y supervivencia.', 'benefits' => ['grounding', 'stability', 'security']],
        ['name' => 'Sahasrara (Crown) - 480 Hz', 'frequency' => 480, 'category' => 'chakra', 'description' => 'Chakra Corona - conexión espiritual y conciencia universal.', 'benefits' => ['spiritual_connection', 'consciousness', 'enlightenment']],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = 0;
        $skipped = 0;
        $now = new \DateTimeImmutable();

        foreach (self::PRESETS as $data) {
            $existing = $this->presetRepo->findOneBy(['frequency' => $data['frequency']]);
            if ($existing) {
                $skipped++;
                continue;
            }
            $preset = new FrequencyPreset();
            $preset->setName($data['name']);
            $preset->setFrequency($data['frequency']);
            $preset->setCategory($data['category']);
            $preset->setDescription($data['description']);
            $preset->setActive(true);
            $preset->setBenefits($data['benefits']);
            $this->em->persist($preset);
            $created++;
        }
        $this->em->flush();

        $io->success(sprintf('Frequencies seeded: %d created, %d skipped (already existed)', $created, $skipped));
        $io->table(['#', 'Name', 'Hz', 'Category'], array_map(fn($p) => [
            $p->getFrequency() . 'Hz',
            $p->getName(),
            $p->getFrequency(),
            $p->getCategory(),
        ], $this->presetRepo->findAllActive()));

        return Command::SUCCESS;
    }
}