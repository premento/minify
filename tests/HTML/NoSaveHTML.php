<?php

namespace Premento\Minify\Tests\HTML;

use Premento\Minify\HTML;

class NoSaveHTML extends HTML
{
    protected function save($content, $path)
    {
        // do nothing
    }
}
