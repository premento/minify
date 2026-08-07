<?php

/**
 * Abstract minifier class.
 *
 * Please report bugs on https://github.com/matthiasmullie/minify/issues
 *
 * @author Matthias Mullie <minify@mullie.eu>
 * @copyright Copyright (c) 2012, Matthias Mullie. All rights reserved
 * @license MIT License
 */

namespace Premento\Minify;

use Premento\Minify\Exceptions\IOException;
use Premento\Minify\Exceptions\PatternMatchException;
use Psr\Cache\CacheItemInterface;

/**
 * Abstract minifier class.
 *
 * Please report bugs on https://github.com/matthiasmullie/minify/issues
 *
 * @author Matthias Mullie <minify@mullie.eu>
 * @copyright Copyright (c) 2012, Matthias Mullie. All rights reserved
 * @license MIT License
 */
abstract class Minify
{
    /**
     * The data to be minified.
     *
     * @var string[]
     */
    protected $data = array();

    /**
     * Array of patterns to match.
     *
     * @var string[]
     */
    protected $patterns = array();

    /**
     * This array will hold content of strings and regular expressions that have
     * been extracted from the JS source code, so we can reliably match "code",
     * without having to worry about potential "code-like" characters inside.
     *
     * @internal
     *
     * @var string[]
     */
    public $extracted = array();

    /**
     * Init the minify class - optionally, code may be passed along already.
     */
    public function __construct(/* $data = null, ... */)
    {
        // it's possible to add the source through the constructor as well ;)
        if (func_num_args()) {
            call_user_func_array(array($this, 'add'), func_get_args());
        }
    }

    /**
     * Add a file or straight-up code to be minified.
     *
     * @param string|string[] $data
     *
     * @return static
     */
    public function add($data /* $data = null, ... */)
    {
        // parameter $data is not read directly - we're using func_get_args()
        // instead, to support overloading - but it still needs to be declared,
        // because it makes no sense to have this function without argument :)
        $args = func_get_args();

        // this method can be overloaded
        foreach ($args as $data) {
            if (is_array($data)) {
                // array_values: string keys would be interpreted as named
                // arguments; skip empty arrays, which would call add() without
                // the argument it requires
                if ($data) {
                    call_user_func_array(array($this, 'add'), array_values($data));
                }
                continue;
            }

            // redefine var
            $data = (string) $data;

            /*
             * Only a source that was actually read from disk gets to keep its
             * path as key (we need it to resolve relative paths later on.) Don't
             * infer that from comparing input & output of load(): a file whose
             * content happens to equal its own path would lose its path.
             */
            $isFile = $this->canImportFile($data);

            // load data
            $value = $this->load($data);
            $key = $isFile ? $data : count($this->data);

            // replace CR linefeeds etc.
            // @see https://github.com/matthiasmullie/minify/pull/139
            $value = str_replace(array("\r\n", "\r"), "\n", $value);

            // store data
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Add a file to be minified.
     *
     * @param string|string[] $data
     *
     * @return static
     *
     * @throws IOException
     */
    public function addFile($data /* $data = null, ... */)
    {
        // see add(): $data is not read directly, but still has to be declared
        $args = func_get_args();

        // this method can be overloaded
        foreach ($args as $path) {
            if (is_array($path)) {
                // see add(): string keys would become named arguments, and an
                // empty array would call addFile() without its argument
                if ($path) {
                    call_user_func_array(array($this, 'addFile'), array_values($path));
                }
                continue;
            }

            // redefine var
            $path = (string) $path;

            // check if we can read the file
            if (!$this->canImportFile($path)) {
                throw new IOException('The file "' . $path . '" could not be opened for reading. Check if PHP has enough permissions.');
            }

            $this->add($path);
        }

        return $this;
    }

    /**
     * Minify the data & (optionally) saves it to a file.
     *
     * @param string[optional] $path Path to write the data to
     *
     * @return string The minified data
     */
    public function minify($path = null)
    {
        $content = $this->execute($path);

        // save to path
        if ($path !== null) {
            $this->save($content, $path);
        }

        return $content;
    }

    /**
     * Minify & gzip the data & (optionally) saves it to a file.
     *
     * @param string[optional] $path  Path to write the data to
     * @param int[optional]    $level Compression level, from 0 to 9
     *
     * @return string The minified & gzipped data
     */
    public function gzip($path = null, $level = 9)
    {
        $content = $this->execute($path);
        $content = gzencode($content, $level, FORCE_GZIP);

        // save to path
        if ($path !== null) {
            $this->save($content, $path);
        }

        return $content;
    }

    /**
     * Minify the data & write it to a CacheItemInterface object.
     *
     * @param CacheItemInterface $item Cache item to write the data to
     *
     * @return CacheItemInterface Cache item with the minifier data
     */
    public function cache(CacheItemInterface $item)
    {
        $content = $this->execute();
        $item->set($content);

        return $item;
    }

    /**
     * Minify the data.
     *
     * @param string[optional] $path Path to write the data to
     *
     * @return string The minified data
     */
    abstract public function execute($path = null);

    /**
     * Load data.
     *
     * @param string $data Either a path to a file or the content itself
     *
     * @return string
     */
    protected function load($data)
    {
        // check if the data is a file
        if ($this->canImportFile($data)) {
            $data = file_get_contents($data);

            // strip BOM, if any
            if (substr($data, 0, 3) == "\xef\xbb\xbf") {
                $data = substr($data, 3);
            }
        }

        return $data;
    }

    /**
     * Save to file.
     *
     * @param string $content The minified data
     * @param string $path The path to save the minified data to
     *
     * @throws IOException
     */
    protected function save($content, $path)
    {
        $handler = $this->openFileForWriting($path);

        // no finally: this library still supports PHP < 5.5
        try {
            $this->writeToFile($handler, $content, $path);
        } catch (IOException $e) {
            @fclose($handler);

            throw $e;
        }

        @fclose($handler);
    }

    /**
     * Discard all registered patterns & extracted data.
     *
     * Patterns are registered right before the content they apply to is
     * processed, and they're never useful afterwards. Clearing them here keeps
     * processing one source from leaking state into the next one, and keeps a
     * second execute() call on the same instance from accumulating duplicates
     * of every pattern (which would make replace() do the same work twice).
     */
    protected function reset()
    {
        $this->patterns = array();
        $this->extracted = array();
    }

    /**
     * Register a pattern to execute against the source content.
     *
     * If $replacement is a string, it must be plain text. Placeholders like $1 or \2 don't work.
     * If you need that functionality, use a callback instead.
     *
     * @param string $pattern PCRE pattern
     * @param string|callable $replacement Replacement value for matched pattern
     */
    protected function registerPattern($pattern, $replacement = '')
    {
        // study the pattern, we'll execute it more than once
        $pattern .= 'S';

        $this->patterns[] = array($pattern, $replacement);
    }

    /**
     * Both JS and CSS use the same form of multi-line comment, so putting the common code here.
     */
    protected function stripMultilineComments()
    {
        $minifier = $this;
        // Pattern for matching comments that we want to preserve
        $keepPattern = '/^
            # comment content
            (?:
                # either starts with an !
                !
            |
                # or, after some number of characters (this is the comment
                # body, which cannot contain the comment terminator anyway)
                .*?

                # there is either a @license or @preserve tag
                @(?:license|preserve)
            )
            /ixs';
        $callback = function ($match) use ($minifier, $keepPattern) {
            if (preg_match($keepPattern, $match[1])) {
                // Preserve the comment
                $count = count($minifier->extracted);
                $placeholder = '/*' . $count . '*/';
                $minifier->extracted[$placeholder] = $match[0];
            } else {
                // Discard the comment but keep any single line feed
                $placeholder = strncmp($match[0], "\n", 1) === 0 || substr($match[0], -1) === "\n"
                    ? "\n"
                    : '';
            }

            return $placeholder;
        };

        $this->registerPattern('/\n?\/\*(.*?)\*\/\n?/s', $callback);
    }

    /**
     * We can't "just" run some regular expressions against JavaScript: it's a
     * complex language. E.g. having an occurrence of // xyz would be a comment,
     * unless it's used within a string. Of you could have something that looks
     * like a 'string', but inside a comment.
     * The only way to accurately replace these pieces is to traverse the JS one
     * character at a time and try to find whatever starts first.
     *
     * @param string $content The content to replace patterns in
     *
     * @return string The (manipulated) content
     *
     * @throws PatternMatchException
     */
    protected function replace($content)
    {
        $contentLength = strlen($content);
        $output = '';
        $processedOffset = 0;
        $positions = array_fill(0, count($this->patterns), -1);
        $matches = array();

        while ($processedOffset < $contentLength) {
            // find first match for all patterns
            foreach ($this->patterns as $i => $pattern) {
                list($pattern, $replacement) = $pattern;

                // we can safely ignore patterns for positions we've unset earlier,
                // because we know these won't show up anymore
                if (array_key_exists($i, $positions) == false) {
                    continue;
                }

                // no need to re-run matches that are still in the part of the
                // content that hasn't been processed
                if ($positions[$i] >= $processedOffset) {
                    continue;
                }

                $match = null;
                $matchResult = preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $processedOffset);
                if ($matchResult) {
                    $matches[$i] = $match;

                    // we'll store the match position as well; that way, we
                    // don't have to redo all preg_matches after changing only
                    // the first (we'll still know where those others are)
                    $positions[$i] = $match[0][1];
                } else {
                    if ($matchResult === false) {
                        throw PatternMatchException::fromLastError(
                            "Failed to match pattern '$pattern' at $processedOffset"
                        );
                    }
                    // if the pattern couldn't be matched, there's no point in
                    // executing it again in later runs on this same content;
                    // ignore this one until we reach end of content
                    unset($matches[$i], $positions[$i]);
                }
            }

            // no more matches to find: everything's been processed, break out
            if (!$matches) {
                // output the remaining content
                $output .= substr($content, $processedOffset);
                break;
            }

            // see which of the patterns actually found the first thing (we'll
            // only want to execute that one, since we're unsure if what the
            // other found was not inside what the first found)
            $matchOffset = min($positions);
            $firstPattern = array_search($matchOffset, $positions);
            $match = $matches[$firstPattern];

            // execute the pattern that matches earliest in the content string
            list(, $replacement) = $this->patterns[$firstPattern];

            // add the part of the input between $processedOffset and the first match;
            // that content wasn't matched by anything
            $output .= substr($content, $processedOffset, $matchOffset - $processedOffset);
            // add the replacement for the match
            $output .= $this->executeReplacement($replacement, $match);
            // advance $processedOffset past the match
            $processedOffset = $matchOffset + strlen($match[0][0]);
        }

        return $output;
    }

    /**
     * If $replacement is a callback, execute it, passing in the match data.
     * If it's a string, just pass it through.
     *
     * @param string|callable $replacement Replacement value
     * @param array $match Match data, in PREG_OFFSET_CAPTURE form
     *
     * @return string
     */
    protected function executeReplacement($replacement, $match)
    {
        if (!is_callable($replacement)) {
            return $replacement;
        }
        // convert $match from the PREG_OFFSET_CAPTURE form to the form the callback expects
        foreach ($match as &$matchItem) {
            $matchItem = $matchItem[0];
        }

        return $replacement($match);
    }

    /**
     * Strings are a pattern we need to match, in order to ignore potential
     * code-like content inside them, but we just want all of the string
     * content to remain untouched.
     *
     * This method will replace all string content with simple STRING#
     * placeholder text, so we've rid all strings from characters that may be
     * misinterpreted. Original string content will be saved in $this->extracted
     * and after doing all other minifying, we can restore the original content
     * via restoreStrings().
     *
     * @param string[optional] $chars List of quote characters, one per string delimiter
     * @param string[optional] $placeholderPrefix
     */
    protected function extractStrings($chars = '\'"', $placeholderPrefix = '')
    {
        // PHP only supports $this inside anonymous functions since 5.4
        $minifier = $this;
        $callback = function ($match) use ($minifier, $placeholderPrefix) {
            /*
             * Thanks to the branch reset group in the pattern below, group 1 is
             * the string content regardless of which quote character matched.
             * The quote itself is simply the first character of the match.
             */
            $quote = $match[0][0];
            $content = $match[1];

            if ($content === '') {
                /*
                 * Empty strings need no placeholder; they can't be confused for
                 * anything else anyway.
                 * But we still needed to match them, for the extraction routine
                 * to skip over this particular string.
                 */
                return $match[0];
            }

            $count = count($minifier->extracted);
            $placeholder = $quote . $placeholderPrefix . $count . $quote;
            $minifier->extracted[$placeholder] = $quote . $content . $quote;

            return $placeholder;
        };

        /*
         * One alternative per quote character, instead of a character class plus
         * a \1 backreference for the closing quote.
         *
         * Spelling the delimiter out per alternative means the content can be
         * matched with a negated character class rather than a lazy `.`:
         * everything that is neither the quote nor a backslash is consumed in one
         * possessive step, and an escaped character (`\"`, or `\\` so a backslash
         * can never end up escaping the closing quote) is consumed as a unit.
         *
         * Nothing in there can backtrack, which is what the previous pattern
         * needed its {0,65535} bound for - to stay inside pcre.backtrack_limit.
         * That bound also imposed a 64KB ceiling: a longer string simply didn't
         * match at its opening quote, so the next match started at its *closing*
         * quote instead, pairing up the wrong quotes & leaving the real string
         * content to be treated as code (whitespace collapsed, `//` taken for the
         * start of a comment). This has neither problem, and no length limit.
         *
         * The alternatives are wrapped in a branch reset group - (?|, PCRE 7.2+,
         * as already required by the \K used elsewhere - so that all of them
         * share group number 1, and this stays a single pattern. Registering one
         * pattern per quote character would work too, but replace() would then
         * run a separate match for each of them on every pass.
         */
        $branches = array();
        $length = strlen($chars);
        for ($i = 0; $i < $length; ++$i) {
            $quote = preg_quote($chars[$i], '/');
            $branches[] = $quote
                . '((?>[^' . $quote . '\\\\]*+(?:\\\\.[^' . $quote . '\\\\]*+)*+))'
                . $quote;
        }

        $this->registerPattern('/(?|' . implode('|', $branches) . ')/s', $callback);
    }

    /**
     * This method will restore all extracted data (strings, regexes) that were
     * replaced with placeholder text in extract*(). The original content was
     * saved in $this->extracted.
     *
     * @param string $content
     *
     * @return string
     */
    protected function restoreExtractedData($content)
    {
        if (!$this->extracted) {
            // nothing was extracted, nothing to restore
            return $content;
        }

        $content = strtr($content, $this->extracted);

        $this->extracted = array();

        return $content;
    }

    /**
     * Check if the path is a regular file and can be read.
     *
     * @param string $path
     *
     * @return bool
     */
    protected function canImportFile($path)
    {
        $parsed = parse_url($path);
        if (
            // file is elsewhere
            isset($parsed['host'])
            // file responds to queries (may change, or need to bypass cache)
            || isset($parsed['query'])
        ) {
            return false;
        }

        try {
            return strlen($path) < PHP_MAXPATHLEN && @is_file($path) && is_readable($path);
        }
        // catch openbasedir exceptions which are not caught by @ on is_file()
        catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Attempts to open file specified by $path for writing.
     *
     * @param string $path The path to the file
     *
     * @return resource Specifier for the target file
     *
     * @throws IOException
     */
    protected function openFileForWriting($path)
    {
        if ($path === '' || ($handler = @fopen($path, 'w')) === false) {
            throw new IOException('The file "' . $path . '" could not be opened for writing. Check if PHP has enough permissions.');
        }

        return $handler;
    }

    /**
     * Attempts to write $content to the file specified by $handler. $path is used for printing exceptions.
     *
     * @param resource $handler The resource to write to
     * @param string $content The content to write
     * @param string $path The path to the file (for exception printing only)
     *
     * @throws IOException
     */
    protected function writeToFile($handler, $content, $path = '')
    {
        if (!is_resource($handler)) {
            throw new IOException('The file "' . $path . '" could not be written to. Check your disk space and file permissions.');
        }

        /*
         * fwrite() is allowed to write less than it was given, so a short write
         * is not in itself an error: keep going until everything has been
         * written. A write of 0 bytes does mean we're not making progress
         * anymore, though, so bail out instead of looping forever.
         */
        $length = strlen($content);
        for ($written = 0; $written < $length; $written += $result) {
            $result = @fwrite($handler, substr($content, $written));
            if ($result === false || $result === 0) {
                throw new IOException('The file "' . $path . '" could not be written to. Check your disk space and file permissions.');
            }
        }
    }

    protected static function str_replace_first($search, $replace, $subject)
    {
        $pos = strpos($subject, $search);
        if ($pos !== false) {
            return substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }
}
