<?php

namespace Premento\Minify\Tests\JS;

use Premento\Minify\JS;

class NoSaveJS extends JS
{
    protected function save($content, $path)
    {
        // do nothing
    }
}
