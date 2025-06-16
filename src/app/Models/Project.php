<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $table = 'projects';
    protected $primaryKey = 'id_project';
    public $timestamps = false;
    protected $fillable = [
        'id_user',
        'name',
        'description',
        'created_by',
        'created_time',
        'modified_by',
        'modified_time',
        'is_deleted'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function datasources()
    {
        return $this->hasMany(Datasource::class, 'id_project');
    }

    public function canvas()
    {
        return $this->hasMany(Canvas::class, 'id_project');
    }

    public function projectAccess()
    {
        return $this->hasMany(ProjectAccess::class, 'id_project');
    }
}
