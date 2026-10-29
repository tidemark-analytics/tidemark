<?php

namespace App\Support;

use InvalidArgumentException;

/** The small part of CBOR (RFC 8949) that WebAuthn attestation objects and COSE keys use: integers, byte and text strings, arrays, maps, booleans. */
class Cbor
{
    /** Decodes one item from the start of $data (or from $offset, which is moved past the item). */
    public static function decode(string $data, int &$offset = 0): mixed
    {
        $initial = ord(self::take($data, $offset, 1));
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new InvalidArgumentException('Unsupported CBOR simple value.'),
            };
        }

        $length = match (true) {
            $info < 24 => $info,
            $info === 24 => ord(self::take($data, $offset, 1)),
            $info === 25 => unpack('n', self::take($data, $offset, 2))[1],
            $info === 26 => unpack('N', self::take($data, $offset, 4))[1],
            $info === 27 => unpack('J', self::take($data, $offset, 8))[1],
            default => throw new InvalidArgumentException('Indefinite CBOR lengths are not supported.'),
        };

        switch ($major) {
            case 0:
                return $length;
            case 1:
                return -1 - $length;
            case 2:
            case 3:
                return self::take($data, $offset, $length);
            case 4:
                $out = [];
                for ($i = 0; $i < $length; $i++) {
                    $out[] = self::decode($data, $offset);
                }

                return $out;
            case 5:
                $out = [];
                for ($i = 0; $i < $length; $i++) {
                    $key = self::decode($data, $offset);
                    $out[$key] = self::decode($data, $offset);
                }

                return $out;
            default:
                throw new InvalidArgumentException('Unsupported CBOR type.');
        }
    }

    private static function take(string $data, int &$offset, int $n): string
    {
        if ($n < 0 || $offset + $n > strlen($data)) {
            throw new InvalidArgumentException('Truncated CBOR data.');
        }
        $chunk = substr($data, $offset, $n);
        $offset += $n;

        return $chunk;
    }
}
