<?php

namespace Premento\Minify\Tests\CSS;

use Premento\Minify\CSS;

class NoSaveCSS extends CSS
{
    protected function save($content, $path)
    {
        // do nothing
    }
}
