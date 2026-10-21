<?php

namespace App\Support;

/**
 * Cache-busting for the dashboard's JavaScript modules. The page loads main.js with a version in its address, but the
 * modules it imports are requested by plain address, so a CDN or browser can keep serving old copies after an update.
 * An import map points each module at an address carrying its file's modification time instead.
 */
class Assets
{
    /** @return array<string,string> module address => versioned address */
    public static function importMap(): array
    {
        $root = public_path('assets');
        $map = [];
        foreach ([...(glob($root.'/js/*.js') ?: []), ...(glob($root.'/js/*/*.js') ?: []), ...(glob($root.'/vendor/*.js') ?: [])] as $file) {
            $url = asset('assets'.substr($file, strlen($root)));
            $map[$url] = $url.'?v='.@filemtime($file);
        }

        return $map;
    }
}
