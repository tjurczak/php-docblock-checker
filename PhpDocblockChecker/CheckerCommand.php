<?php
/**
 * PHP Docblock Checker
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/php-docblock-checker/blob/master/LICENSE.md
 * @link         http://www.phptesting.org/
 */

namespace PhpDocblockChecker;

use DirectoryIterator;
use PHP_Token_Stream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to check a directory of PHP files for Docblocks.
 * @author Dan Cryer <dan@block8.co.uk>
 */
class CheckerCommand extends Command
{
    /**
     * @var string
     */
    protected $basePath = './';

    /**
     * @var bool
     */
    protected $verbose = true;

    /**
     * @var array
     */
    protected $report = array();

    /**
     * @var array
     */
    protected $exclude = array();

    /**
     * @var bool
     */
    protected $skipClasses = false;

    /**
     * @var bool
     */
    protected $skipMethods = false;

    /**
     * @var array
     */
    protected $filesPath = array();

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Configure the console command, add options, etc.
     */
    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('Check PHP files within a directory for appropriate use of Docblocks.')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED, 'Files and directories to exclude.', null)
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory to scan.')
            ->addOption('skip-classes', null, InputOption::VALUE_NONE, 'Don\'t check classes for docblocks.')
            ->addOption('skip-methods', null, InputOption::VALUE_NONE, 'Don\'t check methods for docblocks.')
            ->addOption('skip-anonymous-functions', null, InputOption::VALUE_NONE, 'Don\'t check anonymous functions for docblocks.')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output JSON instead of a log.')
            ->addArgument('files', InputArgument::IS_ARRAY, 'Files to scan.');
    }

    /**
     * Execute the actual docblock checker.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Process options:
        $exclude = $input->getOption('exclude');
        $json = $input->getOption('json');
        $this->basePath = $input->getOption('directory');
        $this->filesPath = $input->getArgument('files');
        $this->verbose = !$json;
        $this->output = $output;
        $this->skipClasses = $input->getOption('skip-classes');
        $this->skipMethods = $input->getOption('skip-methods');
        $this->skipAnonymousFunctions = $input->getOption('skip-anonymous-functions');

        // Set up excludes:
        if (!is_null($exclude)) {
            $this->exclude = array_map('trim', explode(',', $exclude));
        }

        // Check base path ends with a slash:
        if (!empty($this->basePath) && substr($this->basePath, -1) != '/') {
            $this->basePath .= '/';
        }

        // Process:
        if (!empty($this->basePath)) {
            $this->processDirectory();
        }

        if (!empty($this->filesPath)) {
            $this->processFiles();
        }

        // Output JSON if requested:
        if ($json) {
            print json_encode($this->report);
        }

        return count($this->report) ? 1 : 0;
    }

    /**
     * Iterate through files from argument
     */
    protected function processFiles()
    {
        foreach($this->filesPath as $filePath) {
            $this->processFile($filePath);
        }
    }

    /**
     * Iterate through a directory and check all of the PHP files within it.
     * @param string $path
     */
    protected function processDirectory($path = '')
    {
        $dir = new DirectoryIterator($this->basePath . $path);

        foreach ($dir as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $path . $item->getFilename();

            if (in_array($itemPath, $this->exclude)) {
                continue;
            }

            if ($item->isFile() && $item->getExtension() == 'php') {
                $this->processFile($itemPath);
            }

            if ($item->isDir()) {
                $this->processDirectory($itemPath . '/');
            }
        }
    }

    /**
     * Check a specific PHP file for errors.
     * @param $file
     */
    protected function processFile($file)
    {
        $stream = new PHP_Token_Stream($this->basePath . $file);

        foreach ($stream->getClasses() as $name => $class) {
            $errors = false;

            if (!$this->skipClasses && is_null($class['docblock'])) {
                $errors = true;

                $this->report[] = array(
                    'type' => 'class',
                    'file' => $file,
                    'class' => $name,
                    'line' => $class['startLine'],
                );

                if ($this->verbose) {
                    $message = $class['file'] . ': ' . $class['startLine'] . ' - Class ' . $name . ' is missing a docblock.';
                    $this->output->writeln('<error>' . $message . '</error>');
                }
            }

            if (!$this->skipMethods) {
                foreach ($class['methods'] as $methodName => $method) {
                    if ($methodName == 'anonymous function') {
                        continue;
                    }

                    if (is_null($method['docblock'])) {
                        if ($this->skipAnonymousFunctions && $methodName == 'anonymous function') {
                            continue;
                        }

                        $errors = true;

                        $this->report[] = array(
                            'type' => 'method',
                            'file' => $file,
                            'class' => $name,
                            'method' => $methodName,
                            'line' => $method['startLine'],
                        );

                        if ($this->verbose) {
                            $message = $class['file'] . ': ' . $method['startLine'] . ' - Method ' . $name . '::' . $methodName . ' is missing a docblock.';
                            $this->output->writeln('<error>' . $message . '</error>');
                        }
                    }
                }
            }

            if (!$errors && $this->verbose) {
                $this->output->writeln($name . ' <info>OK</info>');
            }
        }


    }
}