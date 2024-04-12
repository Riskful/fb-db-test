<?php
declare(strict_types=1);

namespace FpDbTest;

interface Specifier
{
    public function prepare(string $query): string;
}