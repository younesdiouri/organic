<?php

namespace App\Command;

use App\Service\DeliveryImport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:delivery:import', description: 'Prévisualise une livraison historique ; --apply importe sans doublon ni écrasement.')]
final class DeliveryImportCommand extends Command
{
    public function __construct(private DeliveryImport $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('manifest', InputArgument::REQUIRED)->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer la transaction.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $input->getArgument('manifest');

            if (!is_file($path)) {
                throw new \InvalidArgumentException('Manifeste absent.');
            }
            $json = file_get_contents($path, false, null, 0, 200001);

            if (false === $json || strlen($json) > 200000) {
                throw new \InvalidArgumentException('Manifeste illisible ou trop volumineux.');
            }
            $manifest = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

            if (!is_array($manifest)) {
                throw new \InvalidArgumentException('Manifeste invalide.');
            }
            $report = $this->importer->run($manifest, (bool) $input->getOption('apply'));
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>'.OutputFormatter::escape($error->getMessage()).'</error>');

            return Command::FAILURE;
        }
    }
}
