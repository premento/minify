<?php

namespace MatthiasMullie\Minify\Tests\HTML;

use MatthiasMullie\Minify\HTML;

class NoSaveHTML extends HTML
{
    protected function save($content, $path)
    {
        // do nothing
    }
}
