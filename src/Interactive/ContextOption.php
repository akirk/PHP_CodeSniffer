<?php
/**
 * Interactive option for displaying context around a violation.
 *
 * @author    Alex Kirk <akirk@noreply.users.github.com>
 * @copyright 2025 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Interactive;

use PHP_CodeSniffer\Files\File;

/**
 * Option that shows context around a violation without applying any fixes.
 */
class ContextOption extends InteractiveOption
{

    /**
     * Whether this violation is auto-fixable.
     *
     * @var boolean
     */
    private $fixable;


    /**
     * Constructor.
     *
     * @param \PHP_CodeSniffer\Files\File $file        The file being processed.
     * @param string                      $description Human-readable description.
     * @param bool                        $fixable     Whether the violation is auto-fixable.
     */
    public function __construct(File $file, string $description, bool $fixable)
    {
        parent::__construct($file, $description);
        $this->fixable = $fixable;
    }


    /**
     * Generate diff/context for display.
     *
     * @param int $stackPtr The token position.
     *
     * @return void
     */
    public function generateDiff(int $stackPtr)
    {
        $tempFile = $this->file->createTempClone();
        $tempFile->fixer->enabled = true;
        $tempFile->fixer->fixFile();
        $modifiedContent = $tempFile->fixer->getContents();

        $this->diff = $this->file->generateDiff($modifiedContent, $stackPtr);

        // Cleanup.
        $tempFile->fixer = null;
        unset($tempFile);
    }


    /**
     * This option doesn't actually apply any fixes.
     *
     * @param \PHP_CodeSniffer\Files\File $file     The file being fixed.
     * @param int                         $stackPtr The token position to fix.
     *
     * @return void
     */
    public function applyFix(File $file, int $stackPtr)
    {
        // Context option doesn't apply fixes.
    }


    /**
     * Generate context lines around a specific line number.
     *
     * @param int $line The line number to show context around.
     *
     * @return array The context diff array.
     */
    private function generateContextAroundLine(int $line)
    {
        $content = $this->file->getTokensAsString(0, $this->file->numTokens);
        $lines   = explode("\n", $content);

        $contextLines = 2;
        // Show 2 lines before and after.
        $startLine = max(1, ($line - $contextLines));
        $endLine   = min(count($lines), ($line + $contextLines));

        $diff = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineContent = rtrim(($lines[($i - 1)] ?? ''));

            if ($i === $line) {
                // Mark the violation line as "removed" to highlight it in red.
                $diff[] = [
                    'type'    => 'removed',
                    'lineNum' => $i,
                    'content' => $lineContent,
                ];
            } else {
                // Context lines.
                $diff[] = [
                    'type'    => 'context',
                    'lineNum' => $i,
                    'content' => $lineContent,
                ];
            }
        }

        return $diff;
    }
}
