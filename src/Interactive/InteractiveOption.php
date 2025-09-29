<?php
/**
 * Abstract base class for interactive options.
 *
 * @author    Alex Kirk <akirk@noreply.users.github.com>
 * @copyright 2025 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Interactive;

use PHP_CodeSniffer\Files\File;

/**
 * Abstract base class for interactive options that can apply themselves.
 */
abstract class InteractiveOption
{

    /**
     * Human-readable description of what this fix does.
     *
     * @var string
     */
    protected $description;

    /**
     * The diff preview for this fix.
     *
     * @var array|null
     */
    protected $diff;

    /**
     * Reference to the file being processed.
     *
     * @var \PHP_CodeSniffer\Files\File|null
     */
    protected $file;


    /**
     * Constructor.
     *
     * @param \PHP_CodeSniffer\Files\File $file        The file being processed.
     * @param string                      $description Human-readable description of the fix.
     */
    public function __construct(File $file, string $description)
    {
        $this->file        = $file;
        $this->description = $description;
    }


    /**
     * Get the description of this fix option.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }


    /**
     * Get the diff preview for this fix.
     *
     * @return array|null
     */
    public function getDiff()
    {
        return $this->diff;
    }


    /**
     * Get the diff preview for this fix.
     *
     * @param int $stackPtr The token position to fix.
     *
     * @return void
     */
    public function generateDiff(int $stackPtr)
    {
        $tempFile = $this->file->createTempClone();
        $this->applyFix($tempFile, $stackPtr);
        $modifiedContent = $tempFile->fixer->getContents();

        $this->diff = $this->file->generateDiff($modifiedContent, $stackPtr);

        $tempFile->fixer = null;
        unset($tempFile);
    }


    /**
     * Apply this fix to the specified token in the file.
     *
     * @param \PHP_CodeSniffer\Files\File $file     The file being fixed.
     * @param int                         $stackPtr The token position to fix.
     *
     * @return void
     */
    abstract public function applyFix(File $file, int $stackPtr);
}
