<?php
/**
 * Interactive fix option for simple token replacements.
 *
 * @author    Alex Kirk <akirk@noreply.users.github.com>
 * @copyright 2025 PHPCSStandards and contributors
 * @license   https://github.com/PHPCSStandards/PHP_CodeSniffer/blob/master/licence.txt BSD Licence
 */

namespace PHP_CodeSniffer\Interactive;

use PHP_CodeSniffer\Files\File;

/**
 * Fix option that replaces a token with specific text.
 */
class ReplaceOption extends InteractiveOption
{


    /**
     * The replacement text for the token.
     *
     * @var string
     */
    private $replaceWith;


    /**
     * Constructor.
     *
     * @param \\PHP_CodeSniffer\\Files\\File $file        The file being processed.
     * @param string                      $description Human-readable description of the fix.
     * @param string                      $replaceWith The replacement text for the token.
     */
    public function __construct(File $file, string $description, string $replaceWith)
    {
        parent::__construct($file, $description);
        $this->replaceWith = $replaceWith;
    }


    /**
     * Apply this fix to the specified token in the file.
     *
     * @param \PHP_CodeSniffer\Files\File $file     The file being fixed.
     * @param int                         $stackPtr The token position to fix.
     *
     * @return void
     */
    public function applyFix(File $file, int $stackPtr)
    {
        $file->fixer->replaceToken($stackPtr, $this->replaceWith);
    }


}