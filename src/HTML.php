<?php

/**
 * HTML Minifier.
 *
 * Please report bugs on https://github.com/matthiasmullie/minify/issues
 *
 * @author Matthias Mullie <minify@mullie.eu>
 * @copyright Copyright (c) 2012, Matthias Mullie. All rights reserved
 * @license MIT License
 */

namespace MatthiasMullie\Minify;

/**
 * HTML minifier.
 *
 * Unlike CSS & JS, whitespace in HTML is often significant: the space between
 * two inline elements is rendered, so removing it changes the page. This class
 * is therefore conservative by default - it collapses runs of whitespace into a
 * single space, and only removes whitespace completely where a browser couldn't
 * have rendered it anyway (next to block-level elements.)
 * setAggressiveWhitespace() opts in to removing all whitespace between tags,
 * which is smaller but *will* change how inline content is spaced.
 *
 * Please report bugs on https://github.com/matthiasmullie/minify/issues
 *
 * @author Matthias Mullie <minify@mullie.eu>
 * @copyright Copyright (c) 2012, Matthias Mullie. All rights reserved
 * @license MIT License
 */
class HTML extends Minify
{
    /**
     * An element's attribute list: a run of `name`, `name=value`, `name="value"`
     * or `name='value'`, separated by whitespace.
     *
     * Quoted values are spelled out so that a `>` inside one - `<a title="a>b">`
     * is perfectly legal - doesn't get mistaken for the end of the tag.
     *
     * @internal
     *
     * @var string
     */
    const REGEX_ATTRIBUTES = '(?:\s+[^\s=\/>]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]*))?)*';

    /**
     * Elements whose content is rendered exactly as authored, whitespace and
     * all, so neither they nor their content may be touched.
     *
     * @var string[]
     */
    protected $verbatimElements = array(
        'pre',
        'textarea',
    );

    /**
     * Elements next to which whitespace cannot be rendered, so it is safe to
     * remove it there rather than merely collapse it.
     *
     * These are the block-level elements, plus the ones that aren't rendered at
     * all (`script`, `style`, `link`, `meta`, `base`, `title`).
     *
     * Note `br` is deliberately absent: it is inline, and a space in front of it
     * *is* rendered.
     *
     * @var string[]
     */
    protected $blockElements = array(
        'address', 'article', 'aside', 'base', 'blockquote', 'body', 'caption',
        'colgroup', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'legend', 'li', 'link',
        'main', 'menu', 'meta', 'nav', 'ol', 'optgroup', 'option', 'p',
        'script', 'section', 'style', 'summary', 'table', 'tbody', 'td',
        'tfoot', 'th', 'thead', 'title', 'tr', 'ul',
    );

    /**
     * `type` values marking a <script> whose content is JavaScript & can be run
     * through the JS minifier. An absent type also means JavaScript.
     *
     * Anything else - JSON-LD, an inline template, ... - is left untouched.
     *
     * @var string[]
     */
    protected $javascriptTypes = array(
        'application/ecmascript',
        'application/javascript',
        'application/x-ecmascript',
        'application/x-javascript',
        'module',
        'text/ecmascript',
        'text/javascript',
        'text/jscript',
        'text/livescript',
        'text/x-ecmascript',
        'text/x-javascript',
    );

    /**
     * Attributes that only restate a default the browser already assumes, as
     * [element => [attribute => default value]].
     *
     * Deliberately conservative: `input type="text"` is also a default, but
     * dropping it would break the very common `input[type=text]` CSS selector,
     * so it isn't in here.
     *
     * @var array[]
     */
    protected $redundantAttributes = array(
        'form' => array('method' => 'get'),
        'link' => array('type' => 'text/css'),
        'script' => array('type' => 'text/javascript', 'language' => 'javascript'),
        'style' => array('type' => 'text/css'),
    );

    /**
     * @var bool whether to strip all whitespace between tags
     */
    protected $aggressiveWhitespace = false;

    /**
     * @var bool whether to minify the content of <style> & <script> elements
     */
    protected $minifyInlineAssets = true;

    /**
     * Remove *all* whitespace between tags, rather than only where it couldn't
     * be rendered.
     *
     * This produces smaller output, but it also removes whitespace the browser
     * would have rendered: `<span>a</span> <span>b</span>` becomes
     * `<span>a</span><span>b</span>`, i.e. "ab" instead of "a b". Only turn this
     * on if you know the markup doesn't depend on such spacing.
     *
     * @param bool $aggressive
     */
    public function setAggressiveWhitespace($aggressive = true)
    {
        $this->aggressiveWhitespace = (bool) $aggressive;
    }

    /**
     * Whether to also minify the content of <style> & <script> elements, with
     * this library's CSS & JS minifiers. On by default.
     *
     * @param bool $minify
     */
    public function setMinifyInlineAssets($minify = true)
    {
        $this->minifyInlineAssets = (bool) $minify;
    }

    /**
     * Minify the data.
     *
     * @param string[optional] $path Path to write the data to
     *
     * @return string The minified data
     */
    public function execute($path = null)
    {
        $content = '';

        foreach ($this->data as $html) {
            $this->reset();

            /*
             * Take everything whose content has to survive as-is out of the way,
             * and normalize the tags, before touching any whitespace. What is
             * left afterwards is text content and already-normalized tags, which
             * is safe to collapse whitespace in.
             *
             * Order matters where two patterns can match at the same offset: the
             * first one registered wins, so the specific elements have to come
             * before the catch-all tag pattern.
             */
            $this->extractComments();
            $this->extractDoctype();
            $this->extractCdata();
            $this->extractVerbatimElements();
            $this->extractStyles();
            $this->extractScripts();
            $this->extractTags();

            $html = $this->replace($html);
            $html = $this->stripWhitespace($html);

            $content .= $this->restoreExtractedData($html);
        }

        return $content;
    }

    /**
     * A sub-pattern matching everything up to - but not including - the first
     * occurrence of a terminator, without ever backtracking.
     *
     * The obvious spelling for this is a lazy `.*?`, but that consumes one step
     * of pcre.backtrack_limit per character, so a large enough <script>, <style>
     * or comment would blow the limit & make the whole document fail to minify.
     * Inlining a big stylesheet or bundle is a normal thing to do on a page
     * that's being minified for load speed, so this needs to scale.
     *
     * Consumes runs of "not the terminator's first character" possessively, and
     * only pays for a lookahead at each occurrence of that character.
     *
     * @param string $firstChar First character of the terminator
     * @param string $rest Pattern matching the remainder of the terminator
     *
     * @return string
     */
    protected static function upTo($firstChar, $rest)
    {
        $char = preg_quote($firstChar, '/');

        return '(?>[^' . $char . ']*+(?:' . $char . '(?!' . $rest . ')[^' . $char . ']*+)*+)';
    }

    /**
     * Store a value out of harm's way & return the placeholder that replaces it.
     *
     * The placeholder is built from control characters, which cannot legally
     * appear in HTML content, so it can't be confused for anything in the
     * document. It holds no whitespace and no regex metacharacters either, so it
     * passes through the whitespace handling below untouched.
     *
     * Note restoreExtractedData() replaces placeholders in a single pass, so an
     * extracted value must never itself contain a placeholder - hence e.g.
     * <style> keeping its opening tag out of the extracted content.
     *
     * @internal
     *
     * @param string $value
     *
     * @return string
     */
    public function extract($value)
    {
        $placeholder = "\x02" . count($this->extracted) . "\x03";
        $this->extracted[$placeholder] = $value;

        return $placeholder;
    }

    /**
     * Comments are dropped, except for IE conditional comments (which are
     * functional) and comments explicitly marked to be kept: those opening with
     * `<!--!`, or carrying a `@license`/`@preserve` tag - matching how the CSS &
     * JS minifiers treat comments.
     */
    protected function extractComments()
    {
        $minifier = $this;
        $this->registerPattern(
            '/<!--(' . self::upTo('-', '->') . ')-->/s',
            function ($match) use ($minifier) {
                $keep = strncmp($match[1], '!', 1) === 0
                    // downlevel-hidden & downlevel-revealed conditional comments
                    || stripos($match[1], '[if') !== false
                    || stripos($match[1], '[endif') !== false
                    || preg_match('/@(?:license|preserve)/i', $match[1]);

                return $keep ? $minifier->extract($match[0]) : '';
            }
        );
    }

    /**
     * A doctype can hold quoted identifiers containing whitespace, so keep it as
     * it is, bar collapsing the whitespace separating its parts.
     */
    protected function extractDoctype()
    {
        $minifier = $this;
        $this->registerPattern(
            '/<!DOCTYPE\s[^>]*>/i',
            function ($match) use ($minifier) {
                return $minifier->extract(preg_replace('/\s+/', ' ', $match[0]));
            }
        );
    }

    /**
     * CDATA sections (in XHTML & SVG) are character data and must not be touched.
     */
    protected function extractCdata()
    {
        $minifier = $this;
        $this->registerPattern(
            '/<!\[CDATA\[' . self::upTo(']', '\]>') . '\]\]>/s',
            function ($match) use ($minifier) {
                return $minifier->extract($match[0]);
            }
        );
    }

    /**
     * <pre> & <textarea> render whitespace as authored, so the whole element is
     * kept exactly as it was.
     */
    protected function extractVerbatimElements()
    {
        $minifier = $this;
        foreach ($this->verbatimElements as $element) {
            $this->registerPattern(
                '/<' . $element . '\b' . self::REGEX_ATTRIBUTES . '\s*>'
                . self::upTo('<', '\/' . $element . '\s*>')
                . '<\/' . $element . '\s*>/is',
                function ($match) use ($minifier) {
                    return $minifier->extract($match[0]);
                }
            );
        }
    }

    /**
     * <style> content is CSS rather than HTML: hand it to the CSS minifier.
     */
    protected function extractStyles()
    {
        $minifier = $this;
        $this->registerPattern(
            '/(<style\b' . self::REGEX_ATTRIBUTES . '\s*>)(' . self::upTo('<', '\/style\s*>') . ')<\/style\s*>/is',
            function ($match) use ($minifier) {
                return $minifier->rebuildElement($match[1], $minifier->minifyInline($match[2], 'css'), 'style');
            }
        );
    }

    /**
     * <script> content is only JavaScript for certain `type`s; anything else
     * (JSON-LD, an inline template, ...) is kept verbatim.
     */
    protected function extractScripts()
    {
        $minifier = $this;
        $this->registerPattern(
            '/(<script\b' . self::REGEX_ATTRIBUTES . '\s*>)(' . self::upTo('<', '\/script\s*>') . ')<\/script\s*>/is',
            function ($match) use ($minifier) {
                $type = strtolower(trim($minifier->attributeValue($match[1], 'type')));
                $isJavascript = $type === '' || in_array($type, $minifier->getJavascriptTypes(), true);
                $content = $isJavascript ? $minifier->minifyInline($match[2], 'js') : $match[2];

                return $minifier->rebuildElement($match[1], $content, 'script');
            }
        );
    }

    /**
     * Reassemble a <style>/<script> element from its normalized opening tag and
     * its (already minified) content.
     *
     * The opening tag stays visible in the document rather than being extracted
     * along with the content: it may hold attribute values that were themselves
     * extracted, and a placeholder inside an extracted value would never be
     * restored.
     *
     * @internal
     *
     * @param string $openingTag
     * @param string $content
     * @param string $element
     *
     * @return string
     */
    public function rebuildElement($openingTag, $content, $element)
    {
        return $this->normalizeTag($openingTag)
            . ($content === '' ? '' : $this->extract($content))
            . '</' . $element . '>';
    }

    /**
     * Every remaining tag.
     */
    protected function extractTags()
    {
        $minifier = $this;
        $this->registerPattern(
            '/<[a-zA-Z][^\s\/>]*' . self::REGEX_ATTRIBUTES . '\s*\/?>|<\/[a-zA-Z][^\s>]*\s*>/s',
            function ($match) use ($minifier) {
                return $minifier->normalizeTag($match[0]);
            }
        );
    }

    /**
     * Rewrite a single tag: whitespace between attributes collapsed, attributes
     * that only restate a default dropped, and any attribute value that could
     * interfere with the whitespace handling hidden behind a placeholder.
     *
     * The tag itself is deliberately *not* turned into a placeholder: the
     * whitespace handling needs to see element names, to know which of them are
     * block-level.
     *
     * Element & attribute names keep their original case, because it matters in
     * SVG (`linearGradient`, `viewBox`, ...) even though it doesn't in HTML.
     *
     * @internal
     *
     * @param string $tag
     *
     * @return string
     */
    public function normalizeTag($tag)
    {
        $minifier = $this;

        if (!preg_match('/^<(\/?)([a-zA-Z][^\s\/>]*)(.*?)(\/?)>$/s', $tag, $parts)) {
            return $tag;
        }

        list(, $closing, $name, $attributes, $selfClosing) = $parts;
        $element = strtolower($name);

        // hide attribute values before any whitespace is touched, so that
        // class="a   b" or alt="two words" survives intact
        $attributes = preg_replace_callback(
            '/([^\s=\/>]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]*)/s',
            function ($match) use ($minifier, $element) {
                if ($minifier->isRedundantAttribute($element, $match[1], $match[2])) {
                    return '';
                }

                /*
                 * Only values that would otherwise be affected need hiding:
                 * whitespace would be collapsed, and a `<` or `>` would be
                 * mistaken for a tag boundary further on. Leaving the rest
                 * in place keeps the output readable.
                 */
                if (preg_match('/[\s<>]/', $match[2])) {
                    return $match[1] . '=' . $minifier->extract($match[2]);
                }

                return $match[1] . '=' . $match[2];
            },
            $attributes
        );

        $attributes = trim(preg_replace('/\s+/', ' ', $attributes));

        return '<' . $closing . $name
            . ($attributes === '' ? '' : ' ' . $attributes)
            . ($selfClosing === '' ? '' : '/') . '>';
    }

    /**
     * Whether an attribute only restates a default the browser assumes anyway.
     *
     * @internal
     *
     * @param string $element Lowercased element name
     * @param string $attribute
     * @param string $value Attribute value, possibly still quoted
     *
     * @return bool
     */
    public function isRedundantAttribute($element, $attribute, $value)
    {
        $attribute = strtolower($attribute);
        if (!isset($this->redundantAttributes[$element][$attribute])) {
            return false;
        }

        return strtolower(trim($value, '"\'')) === $this->redundantAttributes[$element][$attribute];
    }

    /**
     * Read an attribute's value out of an opening tag, unquoted.
     *
     * @internal
     *
     * @param string $tag
     * @param string $attribute
     *
     * @return string Empty string when the attribute isn't there
     */
    public function attributeValue($tag, $attribute)
    {
        $pattern = '/\s' . preg_quote($attribute, '/') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]*)/i';
        if (!preg_match($pattern, $tag, $match)) {
            return '';
        }

        return trim($match[1], '"\'');
    }

    /**
     * Run the content of an inline <style> or <script> through the matching
     * minifier.
     *
     * Left alone when inline asset minification is switched off, or when
     * minifying it fails: one unminifiable asset shouldn't take down the page
     * it's on.
     *
     * @internal
     *
     * @param string $content
     * @param string $type Either 'css' or 'js'
     *
     * @return string
     */
    public function minifyInline($content, $type)
    {
        if (!$this->minifyInlineAssets || trim($content) === '') {
            return trim($content);
        }

        try {
            $minifier = $type === 'css' ? new CSS() : new JS();
            $minifier->add($content);

            return $minifier->minify();
        } catch (\Exception $e) {
            return trim($content);
        }
    }

    /**
     * @internal
     *
     * @return string[]
     */
    public function getJavascriptTypes()
    {
        return $this->javascriptTypes;
    }

    /**
     * Collapse whitespace.
     *
     * A run of whitespace becomes a single space, because that is all a browser
     * would have rendered of it anyway. Whitespace next to a block-level element
     * is dropped entirely, since it can't be rendered there at all.
     *
     * @param string $content
     *
     * @return string
     */
    protected function stripWhitespace($content)
    {
        // a run of whitespace renders as a single space
        $content = preg_replace('/\s+/', ' ', $content);

        /*
         * Whitespace directly next to a block-level element isn't rendered.
         * Every tag has been normalized by now, and any attribute value holding
         * a `>` was hidden, so `[^>]*>` cannot overrun the end of a tag here.
         */
        $tag = '<\/?(?:' . implode('|', $this->blockElements) . ')\b[^>]*>';
        $content = preg_replace('/\s+(' . $tag . ')/i', '\\1', $content);
        $content = preg_replace('/(' . $tag . ')\s+/i', '\\1', $content);

        if ($this->aggressiveWhitespace) {
            // ... and, if we're told to, between any two tags at all
            $content = preg_replace('/>\s+</', '><', $content);
        }

        return trim($content);
    }
}
