<?php

namespace Wa72\ElasticsearchBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wa72\ElasticsearchBundle\Services\IndexRegistry;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'elasticsearch:deleteindex', description: 'delete an index')]
class deleteIndexCommand extends Command
{

    public function __construct(private IndexRegistry $ir)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Index name')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('index');
        $io->info('Elasticsearch hosts: ' . join(', ', $this->ir->getHosts()));
        $return = Command::INVALID;
        if ($name) {
            $io->title('About to DELETE index: ' . $name);
            $index = $this->ir->getIndex($name);
            if ($index) {
                $io->info('Index contains ' . $index->count() . ' documents.');
                $index->setLogger(new ConsoleLogger($output));
                $ok = $io->confirm('Are you sure you want to delete index ' . $name . '?', false);
                if ($ok) {
                    $res = $index->deleteIndex();
                    if ($res) {
                        $io->success('Index successfully deleted.');
                        $return = Command::SUCCESS;
                    } else {
                        $io->error('An error occured.');
                        return Command::FAILURE;
                    }
                }
            } else {
                $io->error('No index configured with this name: ' . $name . '. ' . $this->getAvailableIndicesNote());
                $return = Command::FAILURE;
            }
        } else {
            $io->note('No index given. ' . $this->getAvailableIndicesNote());
            $return = Command::INVALID;
        }
        return $return;
    }

    private function getAvailableIndicesNote(): string
    {
        $available_indices = $this->ir->list();
        if (empty($available_indices)) {
            return 'No indices configured.';
        } else {
            return 'Available indices: ' . join(', ', $available_indices);
        }
    }
}
