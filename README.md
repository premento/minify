# Minify

[![Build status](https://img.shields.io/github/actions/workflow/status/matthiasmullie/minify/test.yml?branch=master&style=flat-square)](https://github.com/matthiasmullie/minify/actions/workflows/test.yml)
[![Code coverage](http://img.shields.io/codecov/c/gh/matthiasmullie/minify?style=flat-square)](https://codecov.io/gh/matthiasmullie/minify)
[![Latest version](http://img.shields.io/packagist/v/matthiasmullie/minify?style=flat-square)](https://packagist.org/packages/matthiasmullie/minify)
[![Downloads total](http://img.shields.io/packagist/dt/matthiasmullie/minify?style=flat-square)](https://packagist.org/packages/matthiasmullie/minify)
[![License](http://img.shields.io/packagist/l/matthiasmullie/minify?style=flat-square)](https://github.com/matthiasmullie/minify/blob/master/LICENSE)


Removes whitespace, strips comments, combines files (incl. `@import` statements and small assets in CSS files), and optimizes/shortens a few common programming patterns, such as:

**JavaScript**
* `object['property']` -> `object.property`
* `true`, `false` -> `!0`, `!1`
* `while(true)` -> `for(;;)`

**CSS**
* `@import url("http://path")` -> `@import "http://path"`
* `#ff0000`, `#ff00ff` -> `red`, `#f0f`
* `-0px`, `50.00px` -> `0`, `50px`
* `bold` -> `700`
* `p {}` -> removed

**HTML**
* whitespace collapsed, and removed entirely where it can't be rendered
* comments removed (conditional comments & `@license`/`@preserve` kept)
* inline `<style>` & `<script>` minified with the CSS & JS minifiers
* `<script type="text/javascript">` -> `<script>`

And it comes with a huge test suite.


## Usage

### CSS

```php
use MatthiasMullie\Minify;

$sourcePath = '/path/to/source/css/file.css';
$minifier = new Minify\CSS($sourcePath);

// we can even add another file, they'll then be
// joined in 1 output file
$sourcePath2 = '/path/to/second/source/css/file.css';
$minifier->add($sourcePath2);

// or we can just add plain CSS
$css = 'body { color: #000000; }';
$minifier->add($css);

// save minified file to disk
$minifiedPath = '/path/to/minified/css/file.css';
$minifier->minify($minifiedPath);

// or just output the content
echo $minifier->minify();
```

### JS

```php
// just look at the CSS example; it's exactly the same, but with the JS class & JS files :)
```

### HTML

```php
use MatthiasMullie\Minify;

$minifier = new Minify\HTML($sourcePath);

// save minified file to disk
$minifier->minify($targetPath);

// or just output the content
echo $minifier->minify();
```

Whitespace in HTML is not like whitespace in CSS or JS: the space between two
inline elements is rendered, so removing it changes the page. This minifier is
therefore conservative by default - it collapses runs of whitespace into a
single space, and only removes it completely next to block-level elements.
`<pre>` and `<textarea>` content is left exactly as it was.

**One caveat worth knowing:** which elements count as block-level is decided
from each element's *default* display, because that is all the markup tells us.
CSS can change it, and then the minifier's assumption no longer holds:

* `li { display: inline }` (common in navigations) - whitespace between list
  items becomes significant. `li` is therefore **not** treated as block-level by
  default; it only costs one byte per list item. Use
  [setBlockElements()](#setblockelementselements-html-only) to change the list
  either way.
* `white-space: pre` (or `pre-wrap`/`break-spaces`) on anything other than
  `<pre>`/`<textarea>` - *all* whitespace inside it is significant. If the rule
  targets a whole element (`code`, a custom element, ...), add it via
  [setVerbatimElements()](#setverbatimelementselements-html-only). If it targets
  a class (`div.code { white-space: pre }`), there is no way to express that
  here - whitespace inside those elements will be collapsed, so don't minify
  markup that relies on it.

If neither applies to your markup, the default settings leave the rendered page
identical. See
[setAggressiveWhitespace()](#setaggressivewhitespaceaggressive-html-only) if you
want to trade that for a few more bytes.


## Methods

Available methods, for the CSS, JS & HTML minifiers, are:

### __construct(/* overload paths */)

The object constructor accepts 0, 1 or multiple paths of files, or even complete CSS/JS content, that should be minified.
All CSS/JS passed along, will be combined into 1 minified file.

```php
use MatthiasMullie\Minify;
$minifier = new Minify\JS($path1, $path2);
```

### add($path, /* overload paths */)

This is roughly equivalent to the constructor.

```php
$minifier->add($path3);
$minifier->add($js);
```

### minify($path)

This will minify the files' content, save the result to $path and return the resulting content.
If the $path parameter is omitted, the result will not be written anywhere.

*CAUTION: If you have CSS with relative paths (to imports, images, ...), you should always specify a target path! Then those relative paths will be adjusted in accordance with the new path.*

```php
$minifier->minify('/target/path.js');
```

### gzip($path, $level)

Minifies and optionally saves to a file, just like `minify()`, but it also `gzencode()`s the minified content.

```php
$minifier->gzip('/target/path.js');
```

### setMaxImportSize($size) *(CSS only)*

The CSS minifier will automatically embed referenced files (like images, fonts, ...) into the minified CSS, so they don't have to be fetched over multiple connections.

However, for really large files, it's likely better to load them separately (as it would increase the CSS load time if they were included.)

This method allows the max size of files to import into the minified CSS to be set (in kB). The default size is 5.

```php
$minifier->setMaxImportSize(10);
```

### setImportExtensions($extensions) *(CSS only)*

The CSS minifier will automatically embed referenced files (like images, fonts, ...) into minified CSS, so they don't have to be fetched over multiple connections.

This methods allows the type of files to be specified, along with their data:mime type.

The default embedded file types are gif, png, jpg, jpeg, svg, apng, avif, webp, woff and woff2.

```php
$extensions = array(
    'gif' => 'data:image/gif',
    'png' => 'data:image/png',
);

$minifier->setImportExtensions($extensions);
```

### setAggressiveWhitespace($aggressive) *(HTML only)*

By default, the HTML minifier only removes whitespace a browser wouldn't have
rendered anyway. This method makes it remove *all* whitespace between tags,
which is smaller, but also removes whitespace that **is** rendered:

```html
<!-- source -->
<p>Some <strong>bold</strong> <em>and italic</em> text.</p>

<!-- default: renders as "Some bold and italic text." -->
<p>Some <strong>bold</strong> <em>and italic</em> text.</p>

<!-- aggressive: renders as "Some boldand italic text." -->
<p>Some <strong>bold</strong><em>and italic</em> text.</p>
```

Only turn this on if you know your markup doesn't rely on that spacing.

```php
$minifier->setAggressiveWhitespace();
```

### setMinifyInlineAssets($minify) *(HTML only)*

By default, the content of `<style>` and `<script>` elements is minified with
this library's CSS and JS minifiers. Pass `false` to leave it untouched.

Content that isn't JavaScript (`<script type="application/ld+json">`, inline
templates, ...) is always left alone. If minifying an inline asset fails, its
content is kept as-is rather than failing the whole document.

```php
$minifier->setMinifyInlineAssets(false);
```

### setBlockElements($elements) *(HTML only)*

The elements next to which whitespace is removed entirely rather than collapsed
to a single space. The default list is derived from each element's default CSS
display; override it when your stylesheet differs.

```php
// our nav uses `li { display: inline }`, but our `span`s are all display:block
$minifier->setBlockElements(array('div', 'p', 'ul', 'span'));
```

### setVerbatimElements($elements) *(HTML only)*

The elements whose content is passed through untouched. Defaults to `pre` and
`textarea`; add anything your CSS gives `white-space: pre`, since whitespace
inside those is significant and the markup alone doesn't say so.

```php
$minifier->setVerbatimElements(array('pre', 'textarea', 'code-sample'));
```


## Installation

Simply add a dependency on `matthiasmullie/minify` to your composer.json file if you use [Composer](https://getcomposer.org/) to manage the dependencies of your project:

```sh
composer require matthiasmullie/minify
```

Although it's recommended to use Composer, you can actually [include these files](https://github.com/matthiasmullie/minify/issues/83) anyway you want.


## License

Minify is [MIT](http://opensource.org/licenses/MIT) licensed.
