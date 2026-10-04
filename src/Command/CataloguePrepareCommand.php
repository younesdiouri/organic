<?php
namespace App\Command;

use App\Entity\Supplier;
use App\Service\CatalogueSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:catalogue:prepare',description:'Prépare un manifeste privé depuis les deux classeurs, sans écriture en base.')]
final class CataloguePrepareCommand extends Command
{
    public function __construct(private CatalogueSource $source,private EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('sales',InputArgument::REQUIRED)->addArgument('recipes',InputArgument::REQUIRED)->addOption('output',null,InputOption::VALUE_REQUIRED,'Fichier JSON privé','var/import-analysis/catalogue.json');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $registry=array_map(static fn(Supplier $s)=>['name'=>$s->name,'aliases'=>$s->aliases],$this->em->getRepository(Supplier::class)->findAll());
            $manifest=$this->source->prepare($input->getArgument('sales'),$input->getArgument('recipes'),$registry);
            $path=$input->getOption('output');
            if (!is_dir(dirname($path)) || file_put_contents($path,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new \RuntimeException('Écriture du manifeste impossible.');
            chmod($path,0600);
            $output->writeln(sprintf('Manifeste privé préparé : %d articles, %d fournisseurs. %s',count($manifest['products']),count($manifest['suppliers']),$path));
            return Command::SUCCESS;
        } catch (\Throwable $e) { $output->writeln('<error>'.$e->getMessage().'</error>');return Command::FAILURE; }
    }
}
