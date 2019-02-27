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

class checkIndexCommand extends Command
{
    protected static $defaultName = 'elasticsearch:checkindex';
    private $ir;

    public function __construct(IndexRegistry $ir, ?string $name = null)
    {
        parent::__construct($name);
        $this->ir = $ir;
    }

    protected function configure()
    {
        $this
            ->addArgument('index', InputArgument::REQUIRED, 'Index name')
            ->addOption('create', 'c', InputOption::VALUE_NONE, 'create index if it does not exist')
            ->addOption('alias', 'a', InputOption::VALUE_NONE, 'set missing aliases for index')
            ->setDescription('Check if the index exists, and settings and mappings are correctly set')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $index = $input->getArgument('index');
        $io = new SymfonyStyle($input, $output);
        $io->title('Checking index: ' . $index);
        $indexer = $this->ir->getIndex($index);
        if ($indexer) {
            $indexer->setLogger(new ConsoleLogger($output));
            if ($input->getOption('create')) {
                if (($res = $indexer->prepare()) != false) {
                    $io->success('Done. Current index: ' . $res);
                } else {
                    $io->error('An error occured.');
                }
            } elseif ($input->getOption('alias')) {
                $r = $indexer->setAliases();
                $io->text(\json_encode($r));
            } else {
                $res = $indexer->checkSettingsAndMappings();
                if ($res === null) {
                    $io->warning('Index does not exist.');
                } else if (is_array($res) && count($res) == 0) {
                    // check aliases
                    $aliasdiff = $indexer->checkAliases();
                    if (!empty($aliasdiff)) {
                        $io->note('Aliases missing: ' . join(', ', $aliasdiff));
                    } else {
                        $io->success('Index exists and all settings are correct');
                    }
                } else {
                    if (!empty($res['mappings']['-'])) {
                        $io->warning('Missing mappings:');
                        if ($io->isVerbose()) $io->text(\json_encode($res['mappings']['-'], \JSON_PRETTY_PRINT));
                    }
                    if (!empty($res['mappings']['+'])) {
                        $io->note('Index has unexpected additional mappings (may be auto-created)');
                        if ($io->isVerbose()) $io->text(\json_encode($res['mappings']['+'], \JSON_PRETTY_PRINT));
                    }
                    if (!empty($res['settings']['-'])) {
                        $io->warning('Missing settings:');
                        if ($io->isVerbose()) $io->text(\json_encode($res['settings']['-'], \JSON_PRETTY_PRINT));
                    }
                    if (!empty($res['settings']['+'])) {
                        $io->note('Index has unexpected additional settings');
                        if ($io->isVerbose()) $io->text(\json_encode($res['settings']['+'], \JSON_PRETTY_PRINT));
                    }
                }
            }
        } else {
            $io->error('No index configured with this name: ' . $index);
        }
    }
}
