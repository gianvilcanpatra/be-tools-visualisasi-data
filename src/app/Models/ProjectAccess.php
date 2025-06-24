<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectAccess extends Model
{
    use HasFactory;

    protected $table = 'projects_access';
    protected $primaryKey = 'id_project_access';
    public $timestamps = false;
    protected $fillable = [
        'id_project',
        'id_user',
        'access',
        'created_by',
        'created_time',
        'modified_by',
        'modified_time',
        'is_deleted'
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'id_project');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
