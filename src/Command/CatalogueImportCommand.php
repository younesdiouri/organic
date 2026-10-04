<?php
namespace App\Command;

use App\Service\CatalogueImport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:catalogue:import',description:'Simule un import ; --apply importe atomiquement sans écraser les modifications.')]
final class CatalogueImportCommand extends Command
{
    public function __construct(private CatalogueImport $importer) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('manifest',InputArgument::REQUIRED)->addOption('apply',null,InputOption::VALUE_NONE,'Appliquer la transaction.'); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $path=$input->getArgument('manifest');
            if (!is_file($path) || filesize($path)>20_000_000) throw new \InvalidArgumentException('Manifeste absent ou trop volumineux.');
            $manifest=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) throw new \InvalidArgumentException('Manifeste invalide.');
            $report=$this->importer->run($manifest,(bool)$input->getOption('apply'));
            $output->writeln(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return $report['conflicts']===[] ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) { $output->writeln('<error>'.$e->getMessage().'</error>');return Command::FAILURE; }
    }
}
