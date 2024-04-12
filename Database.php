<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $replacer = new DefaultReplacer($args, $query, $this->mysqli);

        foreach (self::specifiers() as $specifier => $type) {
            $replacer->addMatcher($specifier, $type);
        }

        return $replacer->getResult();
    }

    public function skip()
    {
        return null;
    }

    private static function specifiers(): array
    {
        return [
            '?d' => 'integer',
            '?f' => 'float',
            '?a' => 'array',
            '?#' => 'ids',
            '?\B' => 'any',
        ];
    }
}
