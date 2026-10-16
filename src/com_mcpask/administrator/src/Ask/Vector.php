<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  com_mcpask
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mcpask\Administrator\Ask;

\defined('_JEXEC') or die;

/**
 * Embeddings as they are stored: unit length, 32-bit floats, base64. Text columns hold them the
 * same way on every database, and unit length makes the cosine similarity a plain dot product.
 *
 * @since  0.1.0
 */
final class Vector
{
    /**
     * @param  float[]  $values
     */
    public static function pack(array $values): string
    {
        $length = sqrt(array_sum(array_map(static fn ($value) => $value * $value, $values)));

        if ($length > 0.0) {
            $values = array_map(static fn ($value) => $value / $length, $values);
        }

        return base64_encode(pack('g*', ...$values));
    }

    /**
     * @return  array<int, float>  Keys start at 1, as unpack() returns them
     */
    public static function unpack(string $packed): array
    {
        return unpack('g*', (string) base64_decode($packed, true)) ?: [];
    }

    /** Columns b0..b7 of a passage hold one bit for each of the first 512 dimensions. */
    public const CODE_WORDS = 8;

    /**
     * The signs of the first dimensions, as eight unsigned 64-bit numbers. Counting the bits in
     * which two such codes differ is a rough but very fast measure of how far two texts are
     * apart, and the database can do it.
     *
     * @param   array<int, float>  $values  As unpack() returns them
     *
     * @return  string[]  Decimal numbers, for an unsigned BIGINT
     */
    public static function code(array $values): array
    {
        $values = array_values($values);
        $words  = [];

        for ($word = 0; $word < self::CODE_WORDS; $word++) {
            $bits = 0;

            for ($bit = 0; $bit < 64; $bit++) {
                if (($values[$word * 64 + $bit] ?? 0.0) > 0.0) {
                    $bits |= 1 << $bit;
                }
            }

            // PHP's integers are signed; the column is not
            $words[] = \sprintf('%u', $bits);
        }

        return $words;
    }

    /**
     * Cosine similarity of two unpacked vectors.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }
}
