<?php
namespace App\Command;
use App\Service\InvoiceDrafts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'app:invoice-drafts:cleanup', description: 'Remove private invoice drafts older than 24 hours.')]
final class CleanupInvoiceDraftsCommand extends Command
{
    public function __construct(private InvoiceDrafts $drafts) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->drafts->cleanup().' brouillon(s) expiré(s) supprimé(s).');
        return Command::SUCCESS;
    }
}
