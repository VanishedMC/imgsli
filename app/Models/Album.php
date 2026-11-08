<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Album extends Model
{
    use HasFactory, HasUlids;

    /** Listed on the homepage and reachable by URL. */
    public const VISIBILITY_PUBLIC = 'public';

    /** Reachable by URL only; never listed. */
    public const VISIBILITY_UNLISTED = 'unlisted';

    /** Owner (or an admin) only. */
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_UNLISTED,
        self::VISIBILITY_PRIVATE,
    ];

    protected $fillable = ['user_id', 'title', 'visibility', 'images'];

    protected function casts(): array
    {
        return ['images' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Image entries with resolved URLs: [{url, description}].
     */
    public function imageUrls(): array
    {
        $disk = Storage::disk('public');

        return array_map(fn (array $image) => [
            'url' => $disk->url($image['key']),
            'description' => $image['description'] ?? '',
        ], $this->images);
    }

    public function isViewableBy(?User $user): bool
    {
        // Admins manage all albums, so they can open any of them. Public and
        // unlisted albums are reachable by anyone with the URL; private ones
        // only by their owner.
        return $user?->is_admin === true
            || $this->visibility !== self::VISIBILITY_PRIVATE
            || ($user !== null && $this->user_id === $user->id);
    }
}
