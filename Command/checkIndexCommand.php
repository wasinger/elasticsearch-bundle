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

    const RETURN_OK = 0;
    const RETURN_WARNING = 1;
    const RETURN_ERROR = 2;
    const RETURN_ERROR_VERSIONALIAS = 3;
    const RETURN_ERROR_NO_SUCH_INDEX = 4;

    public function __construct(IndexRegistry $ir, ?string $name = null)
    {
        parent::__construct($name);
        $this->ir = $ir;
    }

    protected function configure()
    {
        $this
            ->addArgument('index', InputArgument::OPTIONAL, 'Index name')
            ->addOption('create', 'c', InputOption::VALUE_NONE, '(re-)create index if it does not exist, or if mapping or settings do not match')
            ->addOption('reindex', 'r', InputOption::VALUE_NONE, 'reindex after creating new index version')
            ->addOption('alias', 'a', InputOption::VALUE_NONE, 'set missing aliases for index')
            ->addOption('switchalias', null, InputOption::VALUE_REQUIRED, 'Switch index alias to given index version', null)
            ->setDescription('Check if the index exists, and settings and mappings are correctly set')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('index');
        $return = self::RETURN_WARNING;
        if ($name) {
            $io->title('Checking index: ' . $name);
            $index = $this->ir->getIndex($name);
            if ($index) {
                $index->setLogger(new ConsoleLogger($output));
                if ($input->getOption('create')) {
                    $reindex = $input->getOption('reindex');
                    if (($res = $index->prepare(  true, $reindex)) != false) {
                        $io->success('Done. Current index: ' . $res);
                        $return = self::RETURN_OK;
                    } else {
                        $io->error('An error occured.');
                        $return = self::RETURN_ERROR;
                    }
                } elseif ($input->getOption('alias')) {
                    $r = $index->setAliases();
                    $io->text(\json_encode($r));
                } elseif ($new_real_index = $input->getOption('switchalias')) {
                    try {
                        $index->switchIndexVersion($new_real_index);
                        $current_real_index = $index->getRealIndexName();
                        if ($current_real_index == $new_real_index) {
                            $io->success(sprintf('Current version for index %s is now %s', $name, $current_real_index));
                            $return = self::RETURN_OK;
                        } else {
                            throw new \Exception(sprintf('Could not switch index alias, current version index is still %s', $current_real_index));
                        }
                    } catch (\Exception $e) {
                        $io->error(sprintf('Could not switch index alias %s to version %s. Error Message: %s', $name, $new_real_index, $e->getMessage()));
                        $return = self::RETURN_ERROR_VERSIONALIAS;
                    }
                } else {
                    $res = $index->checkSettingsAndMappings();
                    if ($res === null) {
                        $io->warning('Index does not exist.');
                        $return = self::RETURN_WARNING;
                    } else {
                        $real_index = $index->getRealIndexName();
                        if ($real_index !== $name) {
                            $io->note('"' . $name . '" is an alias for: ' . $real_index);
                        }
                        if (is_array($res) && count($res) == 0) {
                            // check aliases
                            $aliasdiff = $index->checkAliases();
                            if (!empty($aliasdiff)) {
                                $io->warning('Aliases missing: ' . join(', ', $aliasdiff));
                                $return = self::RETURN_WARNING;
                            } else {
                                $io->success('Index exists and all settings are correct');
                                $return = self::RETURN_OK;
                            }
                        } else {
                            if (!empty($res['mappings']['-'])) {
                                $io->warning('Missing mappings:');
                                if ($io->isVerbose()) {
                                    $io->text(\json_encode($res['mappings']['-'], \JSON_PRETTY_PRINT));
                                }
                                $return = self::RETURN_WARNING;
                            }
                            if (!empty($res['mappings']['+'])) {
                                $io->warning('Index has unexpected additional mappings (may be auto-created)');
                                if ($io->isVerbose()) {
                                    $io->text(\json_encode($res['mappings']['+'], \JSON_PRETTY_PRINT));
                                }
                                $return = self::RETURN_WARNING;
                            }
                            if (!empty($res['settings']['-'])) {
                                $io->warning('Missing settings:');
                                if ($io->isVerbose()) {
                                    $io->text(\json_encode($res['settings']['-'], \JSON_PRETTY_PRINT));
                                }
                                $return = self::RETURN_WARNING;
                            }
                            if (!empty($res['settings']['+'])) {
                                $io->warning('Index has unexpected additional settings');
                                if ($io->isVerbose()) {
                                    $io->text(\json_encode($res['settings']['+'], \JSON_PRETTY_PRINT));
                                }
                                $return = self::RETURN_WARNING;
                            }
                        }
                    }
                }
            } else {
                $io->error('No index configured with this name: ' . $name . '. Available indices: ' . join(', ', $this->ir->list()));
                $return = self::RETURN_ERROR_NO_SUCH_INDEX;
            }
        } else {
            $io->note('No index given. Available indices: ' . join(', ', $this->ir->list()));
            $return = self::RETURN_OK;
        }
        return $return;
    }
}
