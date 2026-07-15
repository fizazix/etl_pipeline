<?php

namespace Tests\Concerns;

trait RequiresMySql
{
    protected function setUpRequiresMySql(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }
    }
}
