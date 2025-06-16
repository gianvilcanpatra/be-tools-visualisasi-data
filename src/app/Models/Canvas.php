<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Canvas extends Model
{
    use HasFactory;

    protected $table = 'public.canvas';
    protected $primaryKey = 'id_canvas';
    public $timestamps = false;
    protected $fillable = [
        'id_project',
        'name',
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

    public function visualizations()
    {
        return $this->hasMany(Visualization::class, 'id_canvas');
    }
}
