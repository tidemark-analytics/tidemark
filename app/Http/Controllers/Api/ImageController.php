<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Profile photos and organization logos. Small raster images only (PNG, JPEG, WebP: no SVG, so nothing can carry script), checked by their
 * content rather than their name, and served to signed-in people with a long cache. The page resizes to a square before uploading.
 */
class ImageController extends Controller
{
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function uploadAvatar(Request $r)
    {
        [$data, $mime] = $this->read($r);
        $r->user()->forceFill(['avatar' => $data, 'avatar_mime' => $mime, 'avatar_updated_at' => now()])->save();

        return ['avatar_url' => $r->user()->fresh()->avatarUrl()];
    }

    public function deleteAvatar(Request $r)
    {
        $r->user()->forceFill(['avatar' => null, 'avatar_mime' => null, 'avatar_updated_at' => null])->save();

        return ['avatar_url' => null];
    }

    public function uploadLogo(Request $r, string $organization)
    {
        $org = $this->owned($r, $organization);
        [$data, $mime] = $this->read($r);
        $org->forceFill(['logo' => $data, 'logo_mime' => $mime, 'logo_updated_at' => now()])->save();

        return ['logo_url' => $org->fresh()->logoUrl()];
    }

    public function deleteLogo(Request $r, string $organization)
    {
        $this->owned($r, $organization)->forceFill(['logo' => null, 'logo_mime' => null, 'logo_updated_at' => null])->save();

        return ['logo_url' => null];
    }

    public function showUser(string $user)
    {
        $u = User::findOrFail($user);
        abort_unless($u->avatar_mime, 404);

        return $this->image($u->avatar, $u->avatar_mime);
    }

    public function showOrganization(string $organization)
    {
        $o = Organization::findOrFail($organization);
        abort_if($o->personal || ! $o->logo_mime, 404);

        return $this->image($o->logo, $o->logo_mime);
    }

    private function image(string $base64, string $mime)
    {
        return response(base64_decode($base64), 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{0:string,1:string} base64 data and its real type */
    private function read(Request $r): array
    {
        $r->validate(['image' => 'required|file|max:512|mimes:png,jpg,jpeg,webp'], ['image.max' => 'The image can be up to 512 KB.', 'image.mimes' => 'Use a PNG, JPEG or WebP image.', 'image.uploaded' => 'The image could not be uploaded (is it over 512 KB?).']);
        /** @var UploadedFile $file */
        $file = $r->file('image');
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], self::TYPES, true)) {
            abort(422, 'Use a PNG, JPEG or WebP image.');
        }
        if ($info[0] > 1024 || $info[1] > 1024 || $info[0] < 32 || $info[1] < 32) {
            abort(422, 'The image should be between 32 and 1024 pixels on each side.');
        }

        return [base64_encode((string) file_get_contents($file->getRealPath())), $info['mime']];
    }

    private function owned(Request $r, string $id): Organization
    {
        $org = Organization::where('owner_id', $r->user()->id)->findOrFail($id);
        abort_if($org->personal, 422, 'Your Personal organization shows your profile photo. Change it in your profile.');

        return $org;
    }
}
