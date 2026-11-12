<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Shared profile photo upload, replacement and removal.
 *
 * Profile forms post the same fields, so the rules, messages and file handling
 * live here instead of being copied between controllers.
 */
trait HandlesProfilePhotos
{
    protected const PHOTO_DIRECTORY = 'profile-photos';

    protected const MAX_PHOTO_KILOBYTES = 2048;

    /**
     * Validation rules for the photo fields of a profile form.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function photoRules(): array
    {
        return [
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_PHOTO_KILOBYTES],
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function photoMessages(): array
    {
        return [
            'profile_photo.image' => 'The profile photo must be a valid image.',
            'profile_photo.mimes' => 'The profile photo must be a JPG, JPEG, PNG or WEBP image.',
            'profile_photo.max' => 'The profile photo may not be larger than 2 MB.',
        ];
    }

    /**
     * Store a new photo or clear the current one, then delete the file that was
     * replaced so the photo directory does not collect orphaned uploads.
     *
     * Uploading always wins over the remove checkbox: picking a new image and
     * ticking "remove" in the same submission keeps the new image.
     */
    protected function syncProfilePhoto(Request $request, User $user): void
    {
        $previousPhoto = $user->profile_photo_path;

        if ($request->boolean('remove_photo') && ! $request->hasFile('profile_photo')) {
            $user->profile_photo_path = null;
        }

        if ($request->hasFile('profile_photo')) {
            $user->profile_photo_path = $request->file('profile_photo')->store(self::PHOTO_DIRECTORY, 'public');
        }

        $user->save();

        if ($user->profile_photo_path !== $previousPhoto) {
            $this->deleteStoredPhoto($previousPhoto, (int) $user->getKey());
        }
    }

    /**
     * Only unreferenced uploads in the profile directory are removed.
     */
    private function deleteStoredPhoto(?string $path, int $userId): void
    {
        if (! $path || ! str_starts_with($path, self::PHOTO_DIRECTORY.'/')) {
            return;
        }

        if (User::where('profile_photo_path', $path)->whereKeyNot($userId)->exists()) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}