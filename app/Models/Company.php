<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;

class Company extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'name',
        'short_name',
        'slogan',
        'head_office_address',
        'email',
        'phone_one',
        'phone_two',
        'owner_id',
        'primary_color',
        'secondary_color',
    ];
    protected $appends = ['logo_url'];

    public function owner()
    {
        return $this->belongsTo(Owner::class);
    }

    public function stores()
    {
        return $this->hasMany(Store::class);
    }


    protected $hidden = [
        'deleted_at',
        'created_at',
        'updated_at'
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->singleFile()
            ->useDisk('companies');
    }

    public function getLogoUrlAttribute()
    {
        $media = $this->getFirstMedia('logo');
        return $media ? $media->getUrl() : null;
    }
}
