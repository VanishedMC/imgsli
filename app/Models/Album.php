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

    protected $fillable = ['user_id', 'title', 'is_public', 'images'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'images' => 'array',
        ];
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
        return $this->is_public || $user !== null && $this->user_id === $user->id;
    }
}
