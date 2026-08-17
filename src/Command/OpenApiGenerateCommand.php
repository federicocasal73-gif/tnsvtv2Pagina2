<?php

declare(strict_types=1);

namespace App\Command;

use OpenApi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates the OpenAPI 3.1 spec from PHP annotations in src/.
 * Usage: bin/console openapi:generate
 *
 * Output: config/openapi.json (served by /api/docs/openapi.json).
 */
#[AsCommand(name: 'openapi:generate', description: 'Generate OpenAPI 3.1 spec from annotations')]
final class OpenApiGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output path',
            'config/openapi.json'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = dirname(__DIR__, 2);
        $srcPaths = [$projectDir . '/src'];

        $outputPath = $input->getOption('output');
        if (!str_starts_with($outputPath, '/')) {
            $outputPath = $projectDir . '/' . $outputPath;
        }

        $generator = new Generator();
        $openapi = $generator->generate($srcPaths);

        $json = $openapi->toJson();
        file_put_contents($outputPath, $json);

        $bytes = strlen($json);
        $io->success(sprintf('OpenAPI 3.1 spec generated at %s (%d bytes)', $outputPath, $bytes));

        return Command::SUCCESS;
    }
}