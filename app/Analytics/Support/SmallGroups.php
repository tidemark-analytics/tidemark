<?php

namespace App\Analytics\Support;

/**
 * Small groups on public dashboards. With few visitors, a single visit from a rare country or to an obscure page can point at one person,
 * so a site owner can set a minimum: rows under it are left out of the lists that public and shared views see (the owner and members of the
 * site always see everything). Report rows are ordered by visitors, so the small ones are the tail.
 */
class SmallGroups
{
    /**
     * @param  array<string, mixed>  $payload  a report response
     * @return array<string, mixed>
     */
    public static function trim(array $payload, int $min, string $key = 'results'): array
    {
        if ($min < 2 || ! isset($payload[$key]) || ! is_array($payload[$key])) {
            return $payload;
        }
        $kept = array_values(array_filter($payload[$key], fn ($row) => (int) ($row['visitors'] ?? 0) >= $min));
        $hidden = count($payload[$key]) - count($kept);
        $payload[$key] = $kept;
        $payload['hidden_below'] = $min;
        if ($hidden) {
            $payload['hidden'] = $hidden;
            $payload['has_more'] = false; // everything after this point is smaller still
        }

        return $payload;
    }
}
