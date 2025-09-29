<?php
/**
 * Responsible for running PHPCS and PHPCBF.
 *
 * After creating an object of this class, you probably just want to
 * call runPHPCS() or runPHPCBF().
 *
 * @author    Greg Sherwood <gsherwood@squiz.net>
 * @copyright 2006-2023 Squiz Pty Ltd (ABN 77 084 670 600)
 * @copyright 2023 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/PHP_CodeSniffer/blob/HEAD/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer;

use Exception;
use InvalidArgumentException;
use PHP_CodeSniffer\Exceptions\DeepExitException;
use PHP_CodeSniffer\Exceptions\RuntimeException;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Interactive\ContextOption;
use PHP_CodeSniffer\Util\Cache;
use PHP_CodeSniffer\Util\Common;
use PHP_CodeSniffer\Util\ExitCode;
use PHP_CodeSniffer\Util\Standards;
use PHP_CodeSniffer\Util\Timing;
use PHP_CodeSniffer\Util\Tokens;
use PHP_CodeSniffer\Util\Writers\StatusWriter;

class Runner
{

    /**
     * The config data for the run.
     *
     * @var \PHP_CodeSniffer\Config
     */
    public $config = null;

    /**
     * The ruleset used for the run.
     *
     * @var \PHP_CodeSniffer\Ruleset
     */
    public $ruleset = null;

    /**
     * The reporter used for generating reports after the run.
     *
     * @var \PHP_CodeSniffer\Reporter
     */
    public $reporter = null;


    /**
     * Run the PHPCS script.
     *
     * @return int
     */
    public function runPHPCS()
    {
        $this->registerOutOfMemoryShutdownMessage('phpcs');

        try {
            Timing::startTiming();

            if (defined('PHP_CODESNIFFER_CBF') === false) {
                define('PHP_CODESNIFFER_CBF', false);
            }

            // Creating the Config object populates it with all required settings
            // based on the CLI arguments provided to the script and any config
            // values the user has set.
            $this->config = new Config();

            // Init the run and load the rulesets to set additional config vars.
            $this->init();

            // Print a list of sniffs in each of the supplied standards.
            // We fudge the config here so that each standard is explained in isolation.
            if ($this->config->explain === true) {
                $standards = $this->config->standards;
                foreach ($standards as $standard) {
                    $this->config->standards = [$standard];
                    $ruleset = new Ruleset($this->config);
                    $ruleset->explain();
                }

                return 0;
            }

            // Generate documentation for each of the supplied standards.
            if ($this->config->generator !== null) {
                $standards = $this->config->standards;
                foreach ($standards as $standard) {
                    $this->config->standards = [$standard];
                    $ruleset   = new Ruleset($this->config);
                    $class     = 'PHP_CodeSniffer\Generators\\' . $this->config->generator;
                    $generator = new $class($ruleset);
                    $generator->generate();
                }

                return 0;
            }

            // Other report formats don't really make sense in interactive mode
            // so we hard-code the full report here and when outputting.
            // We also ensure parallel processing is off because we need to do one file at a time.
            if ($this->config->interactive === true) {
                $this->config->reports      = ['full' => null];
                $this->config->parallel     = 1;
                $this->config->showProgress = false;
            }

            // Disable caching if we are processing STDIN as we can't be 100%
            // sure where the file came from or if it will change in the future.
            if ($this->config->stdin === true) {
                $this->config->cache = false;
            }

            $this->run();

            // Print all the reports for this run.
            $this->reporter->printReports();

            if ($this->config->quiet === false) {
                Timing::printRunTime();
            }
        } catch (DeepExitException $e) {
            $exitCode = $e->getCode();
            $message  = $e->getMessage();
            if ($message !== '') {
                if ($exitCode === 0) {
                    echo $e->getMessage();
                } else {
                    StatusWriter::write($e->getMessage(), 0, 0);
                }
            }

            return $exitCode;
        }

        return ExitCode::calculate($this->reporter);
    }


    /**
     * Run the PHPCBF script.
     *
     * @return int
     */
    public function runPHPCBF()
    {
        $this->registerOutOfMemoryShutdownMessage('phpcbf');

        if (defined('PHP_CODESNIFFER_CBF') === false) {
            define('PHP_CODESNIFFER_CBF', true);
        }

        try {
            Timing::startTiming();

            // Creating the Config object populates it with all required settings
            // based on the CLI arguments provided to the script and any config
            // values the user has set.
            $this->config = new Config();

            // When processing STDIN, we can't output anything to the screen
            // or it will end up mixed in with the file output.
            if ($this->config->stdin === true) {
                $this->config->verbosity = 0;
            }

            // Init the run and load the rulesets to set additional config vars.
            $this->init();

            // When processing STDIN, we only process one file at a time and
            // we don't process all the way through, so we can't use the parallel
            // running system.
            if ($this->config->stdin === true) {
                $this->config->parallel = 1;
            }

            // Override some of the command line settings that might break the fixes.
            $this->config->generator   = null;
            $this->config->explain     = false;
            $this->config->cache       = false;
            $this->config->showSources = false;
            // Keep recordErrors true in interactive mode so we can show violation details.
            if ($this->config->interactive === false) {
                $this->config->recordErrors = false;
            }

            $this->config->reportFile = null;

            // Interactive mode settings for PHPCBF.
            if ($this->config->interactive === true) {
                $this->config->parallel     = 1;
                $this->config->showProgress = false;
            }

            // Only use the "Cbf" report, but allow for the Performance report as well.
            $originalReports = array_change_key_case($this->config->reports, CASE_LOWER);
            $newReports      = ['cbf' => null];
            if (array_key_exists('performance', $originalReports) === true) {
                $newReports['performance'] = $originalReports['performance'];
            }

            $this->config->reports = $newReports;

            // If a standard tries to set command line arguments itself, some
            // may be blocked because PHPCBF is running, so stop the script
            // dying if any are found.
            $this->config->dieOnUnknownArg = false;

            $this->run();
            $this->reporter->printReports();

            if ($this->config->quiet === false) {
                StatusWriter::writeNewline();
                Timing::printRunTime();
            }
        } catch (DeepExitException $e) {
            $exitCode = $e->getCode();
            $message  = $e->getMessage();
            if ($message !== '') {
                if ($exitCode === 0) {
                    echo $e->getMessage();
                } else {
                    StatusWriter::write($e->getMessage(), 0, 0);
                }
            }

            return $exitCode;
        }

        return ExitCode::calculate($this->reporter);
    }


    /**
     * Init the rulesets and other high-level settings.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException If a referenced standard is not installed.
     */
    public function init()
    {
        if (defined('PHP_CODESNIFFER_CBF') === false) {
            define('PHP_CODESNIFFER_CBF', false);
        }

        // Disable the PCRE JIT as this caused issues with parallel running.
        ini_set('pcre.jit', false);

        // Check that the standards are valid.
        foreach ($this->config->standards as $standard) {
            if (Standards::isInstalledStandard($standard) === false) {
                // They didn't select a valid coding standard, so help them
                // out by letting them know which standards are installed.
                $error  = 'ERROR: the "' . $standard . '" coding standard is not installed.' . PHP_EOL . PHP_EOL;
                $error .= Standards::prepareInstalledStandardsForDisplay() . PHP_EOL;
                throw new DeepExitException($error, ExitCode::PROCESS_ERROR);
            }
        }

        // Saves passing the Config object into other objects that only need
        // the verbosity flag for debug output.
        if (defined('PHP_CODESNIFFER_VERBOSITY') === false) {
            define('PHP_CODESNIFFER_VERBOSITY', $this->config->verbosity);
        }

        // Create this class so it is autoloaded and sets up a bunch
        // of PHP_CodeSniffer-specific token type constants.
        new Tokens();

        // Allow autoloading of custom files inside installed standards.
        $installedStandards = Standards::getInstalledStandardDetails();
        foreach ($installedStandards as $details) {
            Autoload::addSearchPath($details['path'], $details['namespace']);
        }

        // The ruleset contains all the information about how the files
        // should be checked and/or fixed.
        try {
            $this->ruleset = new Ruleset($this->config);

            if ($this->ruleset->hasSniffDeprecations() === true) {
                $this->ruleset->showSniffDeprecations();
            }
        } catch (RuntimeException $e) {
            $error  = rtrim($e->getMessage(), "\r\n") . PHP_EOL . PHP_EOL;
            $error .= $this->config->printShortUsage(true);
            throw new DeepExitException($error, ExitCode::PROCESS_ERROR);
        }
    }


    /**
     * Performs the run.
     *
     * @return void
     *
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException
     */
    private function run()
    {
        // The class that manages all reporters for the run.
        $this->reporter = new Reporter($this->config);

        // Include bootstrap files.
        foreach ($this->config->bootstrap as $bootstrap) {
            include $bootstrap;
        }

        if ($this->config->stdin === true) {
            $fileContents = $this->config->stdinContent;
            if ($fileContents === null) {
                $handle = fopen('php://stdin', 'r');
                stream_set_blocking($handle, true);
                $fileContents = stream_get_contents($handle);
                fclose($handle);
            }

            $todo  = new FileList($this->config, $this->ruleset);
            $dummy = new DummyFile($fileContents, $this->ruleset, $this->config);
            $todo->addFile($dummy->path, $dummy);
        } else {
            if (empty($this->config->files) === true) {
                $error  = 'ERROR: You must supply at least one file or directory to process.' . PHP_EOL . PHP_EOL;
                $error .= $this->config->printShortUsage(true);
                throw new DeepExitException($error, ExitCode::PROCESS_ERROR);
            }

            if (PHP_CODESNIFFER_VERBOSITY > 0) {
                StatusWriter::write('Creating file list... ', 0, 0);
            }

            $todo = new FileList($this->config, $this->ruleset);

            if (PHP_CODESNIFFER_VERBOSITY > 0) {
                $numFiles = count($todo);
                StatusWriter::write("DONE ($numFiles files in queue)");
            }

            if ($this->config->cache === true) {
                if (PHP_CODESNIFFER_VERBOSITY > 0) {
                    StatusWriter::write('Loading cache... ', 0, 0);
                }

                Cache::load($this->ruleset, $this->config);

                if (PHP_CODESNIFFER_VERBOSITY > 0) {
                    $size = Cache::getSize();
                    StatusWriter::write("DONE ($size files in cache)");
                }
            }
        }

        $numFiles = count($todo);
        if ($numFiles === 0) {
            $error  = 'ERROR: No files were checked.' . PHP_EOL;
            $error .= 'All specified files were excluded or did not match filtering rules.' . PHP_EOL . PHP_EOL;
            throw new DeepExitException($error, ExitCode::PROCESS_ERROR);
        }

        // Turn all sniff errors into exceptions.
        set_error_handler([$this, 'handleErrors']);

        // If verbosity is too high, turn off parallelism so the
        // debug output is clean.
        if (PHP_CODESNIFFER_VERBOSITY > 1) {
            $this->config->parallel = 1;
        }

        // If the PCNTL extension isn't installed, we can't fork.
        if (function_exists('pcntl_fork') === false) {
            $this->config->parallel = 1;
        }

        $lastDir = '';

        if ($this->config->parallel === 1) {
            // Running normally.
            $numProcessed = 0;
            foreach ($todo as $path => $file) {
                if ($file->ignored === false) {
                    $currDir = dirname($path);
                    if ($lastDir !== $currDir) {
                        if (PHP_CODESNIFFER_VERBOSITY > 0) {
                            StatusWriter::write('Changing into directory ' . Common::stripBasepath($currDir, $this->config->basepath));
                        }

                        $lastDir = $currDir;
                    }

                    $this->processFile($file);
                } elseif (PHP_CODESNIFFER_VERBOSITY > 0) {
                    StatusWriter::write('Skipping ' . basename($file->path));
                }

                $numProcessed++;
                $this->printProgress($file, $numFiles, $numProcessed);
            }
        } else {
            // Batching and forking.
            $childProcs  = [];
            $numPerBatch = ceil($numFiles / $this->config->parallel);

            for ($batch = 0; $batch < $this->config->parallel; $batch++) {
                $startAt = ($batch * $numPerBatch);
                if ($startAt >= $numFiles) {
                    break;
                }

                $endAt = ($startAt + $numPerBatch);
                if ($endAt > $numFiles) {
                    $endAt = $numFiles;
                }

                $childOutFilename = tempnam(sys_get_temp_dir(), 'phpcs-child');
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Failed to create child process');
                } elseif ($pid !== 0) {
                    $childProcs[$pid] = $childOutFilename;
                } else {
                    // Move forward to the start of the batch.
                    $todo->rewind();
                    for ($i = 0; $i < $startAt; $i++) {
                        $todo->next();
                    }

                    // Reset the reporter to make sure only figures from this
                    // file batch are recorded.
                    $this->reporter->totalFiles           = 0;
                    $this->reporter->totalErrors          = 0;
                    $this->reporter->totalWarnings        = 0;
                    $this->reporter->totalFixableErrors   = 0;
                    $this->reporter->totalFixableWarnings = 0;
                    $this->reporter->totalFixedErrors     = 0;
                    $this->reporter->totalFixedWarnings   = 0;

                    // Process the files.
                    $pathsProcessed = [];
                    ob_start();
                    for ($i = $startAt; $i < $endAt; $i++) {
                        $path = $todo->key();
                        $file = $todo->current();

                        if ($file->ignored === true) {
                            $todo->next();
                            continue;
                        }

                        $currDir = dirname($path);
                        if ($lastDir !== $currDir) {
                            if (PHP_CODESNIFFER_VERBOSITY > 0) {
                                StatusWriter::write('Changing into directory ' . Common::stripBasepath($currDir, $this->config->basepath));
                            }

                            $lastDir = $currDir;
                        }

                        $this->processFile($file);

                        $pathsProcessed[] = $path;
                        $todo->next();
                    }

                    $debugOutput = ob_get_contents();
                    ob_end_clean();

                    // Write information about the run to the filesystem
                    // so it can be picked up by the main process.
                    $childOutput = [
                        'totalFiles'           => $this->reporter->totalFiles,
                        'totalErrors'          => $this->reporter->totalErrors,
                        'totalWarnings'        => $this->reporter->totalWarnings,
                        'totalFixableErrors'   => $this->reporter->totalFixableErrors,
                        'totalFixableWarnings' => $this->reporter->totalFixableWarnings,
                        'totalFixedErrors'     => $this->reporter->totalFixedErrors,
                        'totalFixedWarnings'   => $this->reporter->totalFixedWarnings,
                    ];

                    $output  = '<' . '?php' . "\n" . ' $childOutput = ';
                    $output .= var_export($childOutput, true);
                    $output .= ";\n\$debugOutput = ";
                    $output .= var_export($debugOutput, true);

                    if ($this->config->cache === true) {
                        $childCache = [];
                        foreach ($pathsProcessed as $path) {
                            $childCache[$path] = Cache::get($path);
                        }

                        $output .= ";\n\$childCache = ";
                        $output .= var_export($childCache, true);
                    }

                    $output .= ";\n?" . '>';
                    file_put_contents($childOutFilename, $output);
                    exit();
                }
            }

            $success = $this->processChildProcs($childProcs);
            if ($success === false) {
                throw new RuntimeException('One or more child processes failed to run');
            }
        }

        restore_error_handler();

        if (PHP_CODESNIFFER_VERBOSITY === 0
            && $this->config->interactive === false
            && $this->config->showProgress === true
        ) {
            StatusWriter::writeNewline(2);
        }

        if ($this->config->cache === true) {
            Cache::save();
        }
    }


    /**
     * Converts all PHP errors into exceptions.
     *
     * This method forces a sniff to stop processing if it is not
     * able to handle a specific piece of code, instead of continuing
     * and potentially getting into a loop.
     *
     * @param int    $code    The level of error raised.
     * @param string $message The error message.
     * @param string $file    The path of the file that raised the error.
     * @param int    $line    The line number the error was raised at.
     *
     * @return bool
     * @throws \PHP_CodeSniffer\Exceptions\RuntimeException
     */
    public function handleErrors(int $code, string $message, string $file, int $line)
    {
        if ((error_reporting() & $code) === 0) {
            // This type of error is being muted.
            return true;
        }

        throw new RuntimeException("$message in $file on line $line");
    }


    /**
     * Processes a single file, including checking and fixing.
     *
     * @param \PHP_CodeSniffer\Files\File $file The file to be processed.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    public function processFile(File $file)
    {
        if (PHP_CODESNIFFER_VERBOSITY > 0) {
            $startTime = microtime(true);
            $newlines  = 0;
            if (PHP_CODESNIFFER_VERBOSITY > 1) {
                $newlines = 1;
            }

            StatusWriter::write('Processing ' . basename($file->path) . ' ', 0, $newlines);
        }

        try {
            if ($this->config->interactive === true && PHP_CODESNIFFER_CBF === true) {
                // In PHPCBF interactive mode, we handle interaction inside processFile()
                // because we need to do it before fixing.
                $file->setInteractiveMode(true);
                $file->process();
                $this->handlePhpcbfInteractiveMode($file);
                $file->ruleset->populateTokenListeners();
                $file->reloadContent();
            }

            $file->process();

            if (PHP_CODESNIFFER_VERBOSITY > 0) {
                StatusWriter::write('DONE in ' . Timing::getHumanReadableDuration(Timing::getDurationSince($startTime)), 0, 0);

                if (PHP_CODESNIFFER_CBF === true) {
                    $errors   = $file->getFixableErrorCount();
                    $warnings = $file->getFixableWarningCount();
                    StatusWriter::write(" ($errors fixable errors, $warnings fixable warnings)");
                } else {
                    $errors   = $file->getErrorCount();
                    $warnings = $file->getWarningCount();
                    StatusWriter::write(" ($errors errors, $warnings warnings)");
                }
            }
        } catch (Exception $e) {
            $error = 'An error occurred during processing; checking has been aborted. The error message was: ' . $e->getMessage();

            // Determine which sniff caused the error.
            $sniffStack = null;
            $nextStack  = null;
            foreach ($e->getTrace() as $step) {
                if (isset($step['file']) === false) {
                    continue;
                }

                if (empty($sniffStack) === false) {
                    $nextStack = $step;
                    break;
                }

                if (substr($step['file'], -9) === 'Sniff.php') {
                    $sniffStack = $step;
                    continue;
                }
            }

            if (empty($sniffStack) === false) {
                $sniffCode = '';
                try {
                    if (empty($nextStack) === false
                        && isset($nextStack['class']) === true
                    ) {
                        $sniffCode = 'the ' . Common::getSniffCode($nextStack['class']) . ' sniff';
                    }
                } catch (InvalidArgumentException $e) {
                    // Sniff code could not be determined. This may be an abstract sniff class.
                }

                if ($sniffCode === '') {
                    $sniffCode = substr(strrchr(str_replace('\\', '/', $sniffStack['file']), '/'), 1);
                }

                $error .= sprintf(PHP_EOL . 'The error originated in %s on line %s.', $sniffCode, $sniffStack['line']);
            }

            $file->addErrorOnLine($error, 1, 'Internal.Exception');
        }

        $this->reporter->cacheFileReport($file);

        if ($this->config->interactive === true) {
            /*
                Running interactively in PHPCS.
                Print the error report for the current file and then wait for user input.
            */

            // Get current violations and then clear the list to make sure
            // we only print violations for a single file each time.
            $numErrors = null;
            while ($numErrors !== 0) {
                $numErrors = ($file->getErrorCount() + $file->getWarningCount());
                if ($numErrors === 0) {
                    continue;
                }

                if (PHP_CODESNIFFER_CBF === true) {
                    // For PHPCBF, we don't use the standard PHPCS interactive mode
                    // since it requires the "full" report which isn't available in PHPCBF
                    // Interactive mode is handled earlier in processFile().
                    break;
                }

                $this->reporter->printReport('full');

                echo '<ENTER> to recheck, [s] to skip or [q] to quit : ';
                $input = fgets(STDIN);
                $input = trim($input);

                switch ($input) {
                    case 's':
                        break(2);
                    case 'q':
                        // User request to "quit": exit code should be 0.
                        throw new DeepExitException('', ExitCode::OKAY);
                    default:
                        // Repopulate the sniffs because some of them save their state
                        // and only clear it when the file changes, but we are rechecking
                        // the same file.
                        $file->ruleset->populateTokenListeners();
                        $file->reloadContent();
                        $file->process();
                        $this->reporter->cacheFileReport($file);
                        break;
                }
            }
        }

        // Clean up the file to save (a lot of) memory.
        $file->cleanUp();
    }


    /**
     * Waits for child processes to complete and cleans up after them.
     *
     * The reporting information returned by each child process is merged
     * into the main reporter class.
     *
     * @param array<int, string> $childProcs An array of child processes to wait for.
     *
     * @return bool
     */
    private function processChildProcs(array $childProcs)
    {
        $numProcessed = 0;
        $totalBatches = count($childProcs);

        $success = true;

        while (count($childProcs) > 0) {
            $pid = pcntl_waitpid(0, $status);
            if ($pid <= 0 || isset($childProcs[$pid]) === false) {
                // No child or a child with an unmanaged PID was returned.
                continue;
            }

            $childProcessStatus = pcntl_wexitstatus($status);
            if ($childProcessStatus !== 0) {
                $success = false;
            }

            $out = $childProcs[$pid];
            unset($childProcs[$pid]);
            if (file_exists($out) === false) {
                continue;
            }

            include $out;
            unlink($out);

            $numProcessed++;

            if (isset($childOutput) === false) {
                // The child process died, so the run has failed.
                $file = new DummyFile('', $this->ruleset, $this->config);
                $file->setErrorCounts(1, 0, 0, 0, 0, 0);
                $this->printProgress($file, $totalBatches, $numProcessed);
                $success = false;
                continue;
            }

            $this->reporter->totalFiles           += $childOutput['totalFiles'];
            $this->reporter->totalErrors          += $childOutput['totalErrors'];
            $this->reporter->totalWarnings        += $childOutput['totalWarnings'];
            $this->reporter->totalFixableErrors   += $childOutput['totalFixableErrors'];
            $this->reporter->totalFixableWarnings += $childOutput['totalFixableWarnings'];
            $this->reporter->totalFixedErrors     += $childOutput['totalFixedErrors'];
            $this->reporter->totalFixedWarnings   += $childOutput['totalFixedWarnings'];

            if (isset($debugOutput) === true) {
                echo $debugOutput;
            }

            if (isset($childCache) === true) {
                foreach ($childCache as $path => $cache) {
                    Cache::set($path, $cache);
                }
            }

            // Fake a processed file so we can print progress output for the batch.
            $file = new DummyFile('', $this->ruleset, $this->config);
            $file->setErrorCounts(
                $childOutput['totalErrors'],
                $childOutput['totalWarnings'],
                $childOutput['totalFixableErrors'],
                $childOutput['totalFixableWarnings'],
                $childOutput['totalFixedErrors'],
                $childOutput['totalFixedWarnings']
            );
            $this->printProgress($file, $totalBatches, $numProcessed);
        }

        return $success;
    }


    /**
     * Print progress information for a single processed file.
     *
     * @param \PHP_CodeSniffer\Files\File $file         The file that was processed.
     * @param int                         $numFiles     The total number of files to process.
     * @param int                         $numProcessed The number of files that have been processed,
     *                                                  including this one.
     *
     * @return void
     */
    public function printProgress(File $file, int $numFiles, int $numProcessed)
    {
        if (PHP_CODESNIFFER_VERBOSITY > 0
            || $this->config->showProgress === false
        ) {
            return;
        }

        $showColors  = $this->config->colors;
        $colorOpen   = '';
        $progressDot = '.';
        $colorClose  = '';

        // Show progress information.
        if ($file->ignored === true) {
            $progressDot = 'S';
        } else {
            $errors   = $file->getErrorCount();
            $warnings = $file->getWarningCount();
            $fixable  = $file->getFixableCount();
            $fixed    = ($file->getFixedErrorCount() + $file->getFixedWarningCount());

            if (PHP_CODESNIFFER_CBF === true) {
                // Files with fixed errors or warnings are F (green).
                // Files with unfixable errors or warnings are E (red).
                // Files with no errors or warnings are . (black).
                if ($fixable > 0) {
                    $progressDot = 'E';

                    if ($showColors === true) {
                        $colorOpen  = "\033[31m";
                        $colorClose = "\033[0m";
                    }
                } elseif ($fixed > 0) {
                    $progressDot = 'F';

                    if ($showColors === true) {
                        $colorOpen  = "\033[32m";
                        $colorClose = "\033[0m";
                    }
                }
            } else {
                // Files with errors are E (red).
                // Files with fixable errors are E (green).
                // Files with warnings are W (yellow).
                // Files with fixable warnings are W (green).
                // Files with no errors or warnings are . (black).
                if ($errors > 0) {
                    $progressDot = 'E';

                    if ($showColors === true) {
                        if ($fixable > 0) {
                            $colorOpen = "\033[32m";
                        } else {
                            $colorOpen = "\033[31m";
                        }

                        $colorClose = "\033[0m";
                    }
                } elseif ($warnings > 0) {
                    $progressDot = 'W';

                    if ($showColors === true) {
                        if ($fixable > 0) {
                            $colorOpen = "\033[32m";
                        } else {
                            $colorOpen = "\033[33m";
                        }

                        $colorClose = "\033[0m";
                    }
                }
            }
        }

        StatusWriter::write($colorOpen . $progressDot . $colorClose, 0, 0);

        $numPerLine = 60;
        if ($numProcessed !== $numFiles && ($numProcessed % $numPerLine) !== 0) {
            return;
        }

        $percent = round(($numProcessed / $numFiles) * 100);
        $padding = (strlen($numFiles) - strlen($numProcessed));
        if ($numProcessed === $numFiles
            && $numFiles > $numPerLine
            && ($numProcessed % $numPerLine) !== 0
        ) {
            $padding += ($numPerLine - ($numFiles - (floor($numFiles / $numPerLine) * $numPerLine)));
        }

        StatusWriter::write(str_repeat(' ', $padding) . " $numProcessed / $numFiles ($percent%)");
    }


    /**
     * Registers a PHP shutdown function to provide a more informative out of memory error.
     *
     * @param string $command The command which was used to initiate the PHPCS run.
     *
     * @return void
     */
    private function registerOutOfMemoryShutdownMessage(string $command)
    {
        // Allocate all needed memory beforehand as much as possible.
        $errorMsg    = PHP_EOL . 'The PHP_CodeSniffer "%1$s" command ran out of memory.' . PHP_EOL;
        $errorMsg   .= 'Either raise the "memory_limit" of PHP in the php.ini file or raise the memory limit at runtime' . PHP_EOL;
        $errorMsg   .= 'using `%1$s -d memory_limit=512M` (replace 512M with the desired memory limit).' . PHP_EOL;
        $errorMsg    = sprintf($errorMsg, $command);
        $memoryError = 'Allowed memory size of';
        $errorArray  = [
            'type'    => 42,
            'message' => 'Some random dummy string to take up memory and take up some more memory and some more',
            'file'    => 'Another random string, which would be a filename this time. Should be relatively long to allow for deeply nested files',
            'line'    => 31427,
        ];

        register_shutdown_function(
            static function () use (
                $errorMsg,
                $memoryError,
                $errorArray
            ) {
                $errorArray = error_get_last();
                if (is_array($errorArray) === true && strpos($errorArray['message'], $memoryError) !== false) {
                    fwrite(STDERR, $errorMsg);
                }
            }
        );
    }


    /**
     * Handle PHPCBF interactive mode for a single file.
     *
     * @param \PHP_CodeSniffer\Files\File $file The file being processed.
     *
     * @return void
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    private function handlePhpcbfInteractiveMode(File $file)
    {
        while (true) {
            $errors   = $file->getErrors();
            $warnings = $file->getWarnings();

            // Only show header if there are violations to process.
            if (count($errors) === 0 && count($warnings) === 0) {
                return;
            }

            echo "\033[1m" . 'PHPCBF INTERACTIVE MODE - ' . basename($file->path) . "\033[0m" . PHP_EOL . PHP_EOL;

            $needsReload = false;
            foreach (compact('errors', 'warnings') as $type => $violations) {
                foreach ($violations as $line => $lineViolations) {
                    foreach ($lineViolations as $column => $messages) {
                        foreach ($messages as $message) {
                            $message['type'] = $type;
                            $ret = $this->handleSingleViolation($message, $line, $column, $file);
                            if ($ret === null || $ret === 'quit') {
                                return;
                            }

                            if ($ret === 'edit' || $ret === 'ignore_line' || $ret === 'ignore_file' || $ret === 'ignore_project') {
                                $needsReload = true;
                                break 4;
                            }
                        }
                    }
                }
            }

            if ($needsReload === false) {
                break;
            }
        }
    }


    /**
     * Generate a contextual diff display for a fix option using real diff data.
     *
     * @param array $diff The diff data generated by test-applying the fix.
     *
     * @return string The formatted diff output.
     */
    private function generateDiffDisplay(array $diff)
    {
        $output = '';

        foreach ($diff as $diffLine) {
            $lineNumber = str_pad((string) $diffLine['lineNum'], 3, ' ', STR_PAD_LEFT);
            $content    = rtrim($diffLine['content']);

            switch ($diffLine['type']) {
                case 'removed':
                    $output .= "      \033[31m{$lineNumber}- {$content}\033[0m" . PHP_EOL;
                    break;
                case 'added':
                    $output .= "      \033[32m{$lineNumber}+ {$content}\033[0m" . PHP_EOL;
                    break;
                case 'highlight':
                    $output .= "      \033[33m{$lineNumber}> {$content}\033[0m" . PHP_EOL;
                    break;
                case 'context':
                    $output .= "      \033[90m{$lineNumber}  {$content}\033[0m" . PHP_EOL;
                    break;
            }
        }

        return $output;
    }


    /**
     * Handle a single violation in PHPCBF interactive mode.
     *
     * @param array<string, string|int|bool> $message The violation message data.
     * @param int                            $line    The line number.
     * @param int                            $column  The column number.
     * @param \PHP_CodeSniffer\Files\File    $file    The file being processed.
     *
     * @return string The action taken.
     * @throws \PHP_CodeSniffer\Exceptions\DeepExitException
     */
    private function handleSingleViolation(array $message, int $line, int $column, File $file)
    {
        if (isset($message['type']) === true) {
            $type = $message['type'];
        } else {
            $type = 'UNKNOWN';
        }

        if (isset($message['message']) === true) {
            $messageText = $message['message'];
        } else {
            $messageText = 'Unknown violation';
        }

        if (isset($message['source']) === true) {
            $source = $message['source'];
        } else {
            $source = 'Unknown.Source';
        }

        if (isset($message['fixable']) === true) {
            $fixable = $message['fixable'];
        } else {
            $fixable = false;
        }

        if (isset($message['stackPtr']) === true) {
            $stackPtr = $message['stackPtr'];
        } else {
            $stackPtr = $this->findStackPtrAtPosition($file, $line, $column);
        }

        if ($stackPtr === null) {
            error_log("WARNING: Could not find stack pointer at $line:$column in file {$file->path}");
            return 'skip';
        }

        $input = false;

        $interactiveFixOptions = $file->getInteractiveFixOptions($line, $column, $source);
        $hasInteractiveFixes   = !empty($interactiveFixOptions) && isset($interactiveFixOptions[0]);

        if ($this->config->autoFirst === true) {
            if ($hasInteractiveFixes === true) {
                $input = '1';
            } else {
                $input = '';
            }
        }

        echo "\033[33m" . strtoupper($type) . "\033[0m at line $line, column $column:" . PHP_EOL;
        echo '  ' . $messageText . PHP_EOL;
        echo '  Sniff: ' . $source . PHP_EOL;

        if ($hasInteractiveFixes === true) {
            echo "  \033[36m(Interactive fixes available)\033[0m";
        } elseif ($fixable === true) {
            echo "  \033[32m(Auto-fixable)\033[0m";
        } else {
            echo "  \033[31m(Not auto-fixable)\033[0m";
        }

        echo PHP_EOL;
        echo PHP_EOL;

        // Show context for all violation types
        if ($hasInteractiveFixes === true) {
            echo "\033[1mInteractive Fix Options:\033[0m" . PHP_EOL;

            foreach ($interactiveFixOptions as $index => $fixOption) {
                $number = ($index + 1);
                echo "  [$number] {$fixOption->getDescription()}" . PHP_EOL;

                $diff = $fixOption->getDiff($stackPtr);
                if (empty($diff) === false) {
                    echo $this->generateDiffDisplay($diff);
                }
            }
        } else {
            $contextOption = new ContextOption($file, 'Context', $fixable);
            $contextOption->generateDiff($stackPtr);
            $diff = $contextOption->getDiff();
            if (empty($diff) === false) {
                echo $this->generateDiffDisplay($diff);
            }
        }

        echo PHP_EOL;

        if ($input === false) {
            echo 'Choose an action:' . PHP_EOL;
            if ($hasInteractiveFixes === true) {
                foreach ($interactiveFixOptions as $index => $fixOption) {
                    $number = ($index + 1);
                    echo "  [$number] Apply: {$fixOption->getDescription()}" . PHP_EOL;
                }
            } elseif ($fixable === true) {
                echo '  [f] Fix automatically' . PHP_EOL;
            }

            echo '  [i] Add a phpcs:ignore to this line (phpcs:ignore)' . PHP_EOL;
            echo '  [d] Disable this sniff for this file (phpcs:disable)' . PHP_EOL;
            echo '  [p] Project-wide: exclude this sniff in phpcs.xml' . PHP_EOL;
            echo '  [e] Edit the file manually' . PHP_EOL;
            echo '  [s] Skip this violation' . PHP_EOL;
            echo '  [q] Quit' . PHP_EOL;

            if ($hasInteractiveFixes === true) {
                echo "Action (default: 1 \"{$interactiveFixOptions[0]->getDescription()}\"): ";
            } elseif ($fixable === true) {
                echo 'Action (default: auto-fix): ';
            } else {
                echo 'Action (default: edit): ';
            }
        }

        while (true) {
            if ($this->config->autoFirst === false) {
                if ($hasInteractiveFixes === true) {
                    $input = '1';
                } else {
                    $input = '';
                }
            } else {
                $input = trim(fgets(STDIN));
            }

            // Handle empty input (Enter pressed) - default behavior
            if ($input === '') {
                if ($hasInteractiveFixes === true) {
                    // Default to first interactive fix option
                    echo 'Applying option 1...' . PHP_EOL;
                    $input = 1;
                } elseif ($fixable === true) {
                    echo 'Auto-fixing...' . PHP_EOL;
                    $input = 'f';
                } else {
                    $input = 'e';
                }
            }

            // Check if input is a number for interactive fix selection
            if (is_numeric($input) === true && isset($interactiveFixOptions[($input - 1)]) === true) {
                echo 'Fixing with option ', $input, '...' . PHP_EOL;
                $file->setSelectedInteractiveFixOption($line, $column, $source, ($input - 1));
                $fixed = $file->fixer->fixFile();
                if ($fixed === true) {
                    echo "\033[32mFixed!\033[0m" . PHP_EOL;
                } else {
                    echo "\033[31mFailed to fix.\033[0m" . PHP_EOL;
                }

                echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                return 'fix';
            }

            switch (strtolower($input)) {
                case 'f':
                    if ($fixable === true) {
                        echo 'Attempting to fix...' . PHP_EOL;
                        $fixed = $file->fixer->fixFile();
                        if ($fixed === true) {
                            echo "\033[32mFixed!\033[0m" . PHP_EOL;
                            echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                            return 'fix';
                        } else {
                            echo "\033[31mFailed to fix.\033[0m" . PHP_EOL;
                            echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                            return 'skip';
                        }
                    } else {
                        echo "\033[31mInvalid option 'f' - this violation is not auto-fixable.\033[0m" . PHP_EOL;
                        echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                        return 'skip';
                    }

                case 'i':
                    $success = $file->fixer->addPhpcsIgnoreToLine($line, $source);
                    if ($success === true) {
                        echo 'Added phpcs:ignore comment to line ' . $line . PHP_EOL;
                    } else {
                        echo 'Failed to add phpcs:ignore comment to line ' . $line . PHP_EOL;
                    }
                    echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                    return 'ignore_line';

                case 'd':
                    $this->ignoreSniffInFile($source, $file);
                    echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                    return 'ignore_file';

                case 'p':
                    $this->ignoreSniffInProject($source, $file);
                    echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                    return 'ignore_project';

                case 'e':
                    $this->editFile($file, $line);
                    echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                    return 'edit';

                case 's':
                    $file->skipInteractiveFix($line, $column, $source);
                    echo 'Skipping...' . PHP_EOL;
                    echo PHP_EOL . str_repeat('-', 80) . PHP_EOL;
                    return 'skip';

                case 'q':
                    return 'quit';

                default:
                    echo 'Invalid option. Try again: ' ;
            }
        }
    }


    /**
     * Add ignore comment for a sniff in the current file.
     *
     * @param string                      $sniffCode The sniff code to ignore.
     * @param \PHP_CodeSniffer\Files\File $file      The file being processed.
     *
     * @return void
     */
    private function ignoreSniffInFile(string $sniffCode, File $file)
    {
        $content = file_get_contents($file->path);
        $lines   = explode($file->eolChar, $content);

        // Add ignore comment at the top of the file after the opening PHP tag
        $ignoreComment = '// phpcs:disable ' . $sniffCode . ' -- Interactive ignore';
        if (isset($lines[0]) === true && strpos($lines[0], '<?php') === 0) {
            array_splice($lines, 1, 0, $ignoreComment);
        } else {
            array_unshift($lines, $ignoreComment);
        }

        $newContent = implode($file->eolChar, $lines);
        file_put_contents($file->path, $newContent);

        echo "\033[32mAdded ignore for $sniffCode to this file.\033[0m" . PHP_EOL;

        // Reload the file
        $file->reloadContent();
        $file->ruleset->populateTokenListeners();
        $file->process();
    }


    /**
     * Add ignore rule for a sniff in the project configuration.
     *
     * @param string                      $sniffCode The sniff code to ignore.
     * @param \PHP_CodeSniffer\Files\File $file      The file being processed.
     *
     * @return void
     */
    private function ignoreSniffInProject(string $sniffCode, File $file)
    {
        $configFiles = [
            'phpcs.xml',
            'phpcs.xml.dist',
            '.phpcs.xml',
            '.phpcs.xml.dist',
        ];
        $configFile  = null;

        // Find existing config file or create one
        foreach ($configFiles as $configFileName) {
            if (file_exists($configFileName) === true) {
                $configFile = $configFileName;
                break;
            }
        }

        if ($configFile === null) {
            echo 'No phpcs.xml file found in the current directory.' . PHP_EOL;
            return;
        }

        $this->addExcludeToPhpcsXml($configFile, $sniffCode);
        echo "\033[32mAdded exclude for $sniffCode to $configFile.\033[0m" . PHP_EOL;
    }


    /**
     * Launch editor for manual file editing.
     *
     * @param \PHP_CodeSniffer\Files\File $file The file being processed.
     * @param int                         $line The line number to jump to.
     *
     * @return void
     */
    private function editFile(File $file, int $line)
    {
        // Apply all fixes made so far and write to file so user edits the current state
        $fixedContent = $file->fixer->getContents();
        file_put_contents($file->path, $fixedContent);

        // Check VISUAL first (preferred), then EDITOR
        $editor = getenv('VISUAL');
        if ($editor === false) {
            $editor = getenv('EDITOR');
        }

        if ($editor === false) {
            echo "\033[31mNo editor configured. Please set the VISUAL or EDITOR environment variable.\033[0m" . PHP_EOL;
            echo 'Examples:' . PHP_EOL;
            echo '  export VISUAL=vim' . PHP_EOL;
            echo '  export VISUAL=code' . PHP_EOL;
            echo '  export EDITOR=nano' . PHP_EOL;
            return;
        }

        // Add line number support for common editors:
        $editorName = strtok(basename($editor), ' ');
        if (in_array($editorName, ['nano', 'vim', 'vi', 'nvim'], true) === true) {
            $command = escapeshellcmd($editor) . ' +' . $line . ' ' . escapeshellarg($file->path);
        } elseif ($editorName === 'emacs') {
            $command = escapeshellcmd($editor) . ' +' . $line . ' ' . escapeshellarg($file->path);
        } elseif ($editorName === 'code') {
            $command = escapeshellcmd($editor) . ' --goto ' . escapeshellarg($file->path . ':' . $line);
        } elseif ($editorName === 'subl') {
            // For Sublime Text, use file:line syntax
            $command = escapeshellcmd($editor) . ' ' . escapeshellarg($file->path . ':' . $line);
        } else {
            // Default: just open the file without line number
            $command = escapeshellcmd($editor) . ' ' . escapeshellarg($file->path);
        }

        echo "Opening file with: $command" . PHP_EOL;

        // Execute editor
        system($command, $returnCode);

        // Reload the file content
        $file->reloadContent();
        $file->ruleset->populateTokenListeners();
        $file->process();

        echo "\033[32mFile reloaded and reprocessed.\033[0m" . PHP_EOL;
    }


    /**
     * Add an exclude rule to phpcs.xml.
     *
     * @param string $configFile The config file to modify.
     * @param string $sniffCode  The sniff code to exclude.
     *
     * @return void
     */
    private function addExcludeToPhpcsXml(string $configFile, string $sniffCode)
    {
        $xml = simplexml_load_file($configFile);
        if ($xml === false) {
            echo "\033[31mFailed to load $configFile.\033[0m" . PHP_EOL;
            return;
        }

        // Check if exclude already exists
        foreach ($xml->rule as $rule) {
            if (isset($rule['ref']) === true && (string) $rule['ref'] === $sniffCode) {
                foreach ($rule->exclude as $exclude) {
                    if (isset($exclude['name']) === true && (string) $exclude['name'] === $sniffCode) {
                        echo "\033[33mSniff $sniffCode is already excluded.\033[0m" . PHP_EOL;
                        return;
                    }
                }
            }
        }

        // Add new exclude rule
        $rule = $xml->addChild('rule');
        $rule->addAttribute('ref', $sniffCode);
        $exclude = $rule->addChild('exclude');
        $exclude->addAttribute('name', $sniffCode);

        // Format and save
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $dom->save($configFile);
    }


    /**
     * Find the stack pointer at a specific line and column position.
     *
     * @param \PHP_CodeSniffer\Files\File $file   The file to search in.
     * @param int                         $line   The line number.
     * @param int                         $column The column number.
     *
     * @return int The stack pointer.
     */
    private function findStackPtrAtPosition(File $file, int $line, int $column)
    {
        $tokens = $file->getTokens();
        // Find the first token on the specified line at or near the column
        for ($i = 0; $i < $file->numTokens; $i++) {
            if ($tokens[$i]['line'] === $line && $tokens[$i]['column'] <= $column) {
                // Check if the next token is also on the same line and closer to the column
                if (isset($tokens[($i + 1)]) === true
                    && $tokens[($i + 1)]['line'] === $line
                    && $tokens[($i + 1)]['column'] <= $column
                ) {
                    continue;
                }

                return $i;
            }
        }

        return ($file->numTokens - 1);
    }
}
