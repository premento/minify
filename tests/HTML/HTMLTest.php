<?php

namespace MatthiasMullie\Minify\Tests\HTML;

use MatthiasMullie\Minify\Tests\CompatTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * HTML minifier test case.
 */
class HTMLTest extends CompatTestCase
{
    protected function getMinifier()
    {
        // use custom class where `save` has been turned into a no-op;
        // there's no point in writing the result out here
        return new NoSaveHTML();
    }

    /**
     * Test HTML minifier rules, provided by dataProvider.
     *
     * @dataProvider dataProvider
     */
    #[DataProvider('dataProvider')]
    public function testMinify($input, $expected)
    {
        $minifier = $this->getMinifier();
        $minifier->add($input);

        $this->assertEquals($expected, $minifier->minify());
    }

    /**
     * Test the opt-in aggressive whitespace mode, provided by
     * dataProviderAggressive.
     *
     * @dataProvider dataProviderAggressive
     */
    #[DataProvider('dataProviderAggressive')]
    public function testMinifyAggressive($input, $expected)
    {
        $minifier = $this->getMinifier();
        $minifier->setAggressiveWhitespace();
        $minifier->add($input);

        $this->assertEquals($expected, $minifier->minify());
    }

    /**
     * With inline asset minification switched off, the content of <style> and
     * <script> is left exactly as it was.
     */
    public function testMinifyInlineAssetsDisabled()
    {
        $minifier = $this->getMinifier();
        $minifier->setMinifyInlineAssets(false);
        $minifier->add("<style>\n  body {\n    color: red;\n  }\n</style>");

        $this->assertEquals("<style>body {\n    color: red;\n  }</style>", $minifier->minify());
    }

    /**
     * An inline asset the CSS/JS minifier chokes on must not take the whole page
     * with it: its content is passed through untouched & the rest of the
     * document is still minified.
     *
     * Also covers the document itself being large: locating the end of a big
     * <style> or <script> must not run into pcre.backtrack_limit, which a lazy
     * quantifier would.
     */
    public function testUnminifiableInlineAssetIsKept()
    {
        // long whitespace run that exceeds pcre.backtrack_limit inside the CSS
        // minifier, exactly as CSSTest::testErrorHandling relies on
        $limit = (int) (ini_get('pcre.backtrack_limit') ?: 1000000);
        $css = '.a{content:"2"}' . str_repeat(' ', $limit) . '.b{content:"1"}';

        $minifier = $this->getMinifier();
        $minifier->add("<div>\n  <p>before</p>\n</div><style>" . $css . '</style><p>after</p>');

        $result = $minifier->minify();

        // the surrounding markup is minified as usual ...
        $this->assertStringStartsWith('<div><p>before</p></div><style>', $result);
        $this->assertStringEndsWith('</style><p>after</p>', $result);
        // ... and the stylesheet survived rather than being mangled or dropped
        $this->assertStringContainsString('.a{content:"2"}', $result);
        $this->assertStringContainsString('.b{content:"1"}', $result);
    }

    /**
     * Minifying twice must give the same result twice.
     */
    public function testRepeatedMinify()
    {
        $minifier = $this->getMinifier();
        $minifier->add("<div>\n  <p>a</p>\n</div>");

        $first = $minifier->minify();
        $second = $minifier->minify();

        $this->assertEquals('<div><p>a</p></div>', $first);
        $this->assertEquals($first, $second);
    }

    /**
     * The block-element list goes by each element's default display, which CSS
     * can override. `li` is therefore not in it by default (`li{display:inline}`
     * is common in navigations), but can be added.
     */
    public function testBlockElementsAreConfigurable()
    {
        $list = "<ul>\n  <li>one</li>\n  <li>two</li>\n</ul>";

        // by default the single space between list items survives, which costs
        // one byte per item but keeps `li{display:inline}` rendering the same
        $minifier = $this->getMinifier();
        $minifier->add($list);
        $this->assertEquals('<ul><li>one</li> <li>two</li></ul>', $minifier->minify());

        $minifier = $this->getMinifier();
        $minifier->setBlockElements(array('ul', 'li'));
        $minifier->add($list);
        $this->assertEquals('<ul><li>one</li><li>two</li></ul>', $minifier->minify());
    }

    /**
     * Whitespace is significant in anything styled `white-space: pre`, which
     * can't be seen from the markup, so the verbatim list is configurable too.
     */
    public function testVerbatimElementsAreConfigurable()
    {
        $html = '<div>  <my-code>  a   b  </my-code>  </div>';

        $minifier = $this->getMinifier();
        $minifier->add($html);
        $this->assertEquals('<div><my-code> a b </my-code></div>', $minifier->minify());

        $minifier = $this->getMinifier();
        $minifier->setVerbatimElements(array('pre', 'textarea', 'my-code'));
        $minifier->add($html);
        $this->assertEquals('<div><my-code>  a   b  </my-code></div>', $minifier->minify());
    }

    /**
     * The placeholders used internally must never survive into the output, no
     * matter how many things get extracted.
     */
    public function testNoPlaceholdersLeakIntoOutput()
    {
        $html = '<!DOCTYPE html><html><head>'
            . '<style media="screen and (max-width: 600px)">a{color:red}</style>'
            . '<script type="application/ld+json">{"a": "b   c"}</script>'
            . '</head><body><!--! keep --><pre>  x  </pre>'
            . '<p class="a   b" title="two words">t</p></body></html>';

        $minifier = $this->getMinifier();
        $minifier->add($html);
        $result = $minifier->minify();

        $this->assertStringNotContainsString("\x02", $result);
        $this->assertStringNotContainsString("\x03", $result);
    }

    /**
     * @return array [input, expected result]
     */
    public static function dataProvider()
    {
        $tests = array();

        // whitespace between block-level elements can't be rendered
        $tests[] = array(
            "<div>\n    <p>hello</p>\n    <p>world</p>\n</div>",
            '<div><p>hello</p><p>world</p></div>',
        );

        // ... but whitespace between inline elements is rendered, so it stays
        $tests[] = array(
            '<p><span>a</span> <span>b</span></p>',
            '<p><span>a</span> <span>b</span></p>',
        );
        // a run of it is still only worth a single space
        $tests[] = array(
            "<p><span>a</span>   \n  <span>b</span></p>",
            '<p><span>a</span> <span>b</span></p>',
        );
        // `br` is inline: the space in front of it is rendered
        $tests[] = array(
            '<p>a <br> b</p>',
            '<p>a <br> b</p>',
        );

        // runs of whitespace in text collapse to one space
        $tests[] = array(
            "<p>one   two\n\nthree</p>",
            '<p>one two three</p>',
        );

        // <pre> & <textarea> render whitespace as authored
        $tests[] = array(
            "<div>\n<pre>  line 1\n    line 2  </pre>\n</div>",
            "<div><pre>  line 1\n    line 2  </pre></div>",
        );
        $tests[] = array(
            "<form>\n<textarea>  keep\n  this  </textarea>\n</form>",
            "<form><textarea>  keep\n  this  </textarea></form>",
        );

        // comments go, unless they're functional or explicitly marked to stay
        $tests[] = array(
            '<p>a</p><!-- a comment --><p>b</p>',
            '<p>a</p><p>b</p>',
        );
        $tests[] = array(
            '<!--[if lt IE 9]><script src="s.js"></script><![endif]--><p>a</p>',
            '<!--[if lt IE 9]><script src="s.js"></script><![endif]--><p>a</p>',
        );
        $tests[] = array(
            '<!--! keep me --><p>a</p>',
            '<!--! keep me --><p>a</p>',
        );
        $tests[] = array(
            '<!-- @license MIT --><p>a</p>',
            '<!-- @license MIT --><p>a</p>',
        );

        // inline assets go through the CSS & JS minifiers
        $tests[] = array(
            "<style>\n  body {\n    color: #ff0000;\n  }\n</style>",
            '<style>body{color:red}</style>',
        );
        $tests[] = array(
            "<script>\n  var a = true;\n  // gone\n  function f() { return a; }\n</script>",
            '<script>var a=!0;function f(){return a}</script>',
        );

        // ... but only when the content actually is JavaScript
        $tests[] = array(
            '<script type="application/ld+json">{"@context": "https://schema.org"}</script>',
            '<script type="application/ld+json">{"@context": "https://schema.org"}</script>',
        );
        $tests[] = array(
            '<script type="text/x-template"><div>  {{ x }}  </div></script>',
            '<script type="text/x-template"><div>  {{ x }}  </div></script>',
        );

        // attributes that only restate a default
        $tests[] = array(
            '<script type="text/javascript">var a=1</script>',
            '<script>var a=1</script>',
        );
        $tests[] = array(
            '<style type="text/css">a{color:red}</style>',
            '<style>a{color:red}</style>',
        );
        $tests[] = array(
            '<form method="GET" action="/x"><input name="q"></form>',
            '<form action="/x"><input name="q"></form>',
        );
        // ... but not input[type=text], which is commonly used as a CSS selector
        $tests[] = array(
            '<input type="text" name="q">',
            '<input type="text" name="q">',
        );

        // attribute values are never touched
        $tests[] = array(
            '<div class="a   b" title="two words">x</div>',
            '<div class="a   b" title="two words">x</div>',
        );
        // ... including one holding a `>`, which is legal
        $tests[] = array(
            '<a title="a>b" href="/x">link</a>',
            '<a title="a>b" href="/x">link</a>',
        );
        $tests[] = array(
            '<style media="screen and (max-width: 600px)">a{color:red}</style>',
            '<style media="screen and (max-width: 600px)">a{color:red}</style>',
        );

        // element & attribute names keep their case: it matters in SVG
        $tests[] = array(
            '<svg viewBox="0 0 10 10"><linearGradient id="g"/></svg>',
            '<svg viewBox="0 0 10 10"><linearGradient id="g"/></svg>',
        );

        // whitespace between attributes collapses
        $tests[] = array(
            "<div   id=\"a\"\n     class=\"b\">x</div>",
            '<div id="a" class="b">x</div>',
        );

        // doctype keeps its shape, bar the whitespace between its parts
        $tests[] = array(
            "<!DOCTYPE   html>\n<html>\n<head><title>  T  </title></head>\n</html>",
            '<!DOCTYPE html><html><head><title>T</title></head></html>',
        );

        // boolean attributes & self-closing tags survive
        $tests[] = array(
            '<input disabled name="x">',
            '<input disabled name="x">',
        );
        $tests[] = array(
            '<img src="a.png" />',
            '<img src="a.png"/>',
        );

        // CDATA is character data
        $tests[] = array(
            '<p><![CDATA[  raw   data  ]]></p>',
            '<p><![CDATA[  raw   data  ]]></p>',
        );

        // leading & trailing whitespace of the document as a whole
        $tests[] = array(
            "\n  <p>a</p>\n  ",
            '<p>a</p>',
        );

        /*
         * An unclosed <script>/<style>/<pre>/<textarea> runs to the end of the
         * input as far as a browser is concerned. Its content must still be
         * treated as that element's content, not collapsed as if it were markup
         * - which would silently rewrite the whitespace inside a string.
         */
        $tests[] = array(
            '<script>var s = "a    b";',
            '<script>var s="a    b"',
        );
        $tests[] = array(
            '<style>a::after{content:"x    y"}',
            '<style>a::after{content:"x    y"}',
        );
        $tests[] = array(
            '<textarea>a    b',
            '<textarea>a    b',
        );
        $tests[] = array(
            '<div><pre>  keep  ',
            '<div><pre>  keep  ',
        );

        // a closing tag keeps its name & case, and only loses whitespace
        $tests[] = array(
            '<script>var a = 1;</script >',
            '<script>var a=1</script>',
        );
        $tests[] = array(
            '<SCRIPT>var a=1</SCRIPT>',
            '<SCRIPT>var a=1</SCRIPT>',
        );
        $tests[] = array(
            '<STYLE>a{color:red}</STYLE>',
            '<STYLE>a{color:red}</STYLE>',
        );

        // table cells & options are still trimmed: they're in the block list
        $tests[] = array(
            "<table>\n<tr>\n<td>a</td>\n<td>b</td>\n</tr>\n</table>",
            '<table><tr><td>a</td><td>b</td></tr></table>',
        );

        return $tests;
    }

    /**
     * @return array [input, expected result]
     */
    public static function dataProviderAggressive()
    {
        $tests = array();

        // this is what makes the mode opt-in: the space between the two spans
        // is rendered, and this removes it
        $tests[] = array(
            '<p><span>a</span> <span>b</span></p>',
            '<p><span>a</span><span>b</span></p>',
        );
        $tests[] = array(
            "<div>\n  <p>a</p>\n  <p>b</p>\n</div>",
            '<div><p>a</p><p>b</p></div>',
        );
        // text is still only collapsed, never dropped
        $tests[] = array(
            '<p>one   two</p>',
            '<p>one two</p>',
        );
        // and <pre> is still off limits
        $tests[] = array(
            '<div> <pre>  a  b  </pre> </div>',
            '<div><pre>  a  b  </pre></div>',
        );

        return $tests;
    }
}
