<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visualization extends Model
{
    use HasFactory;

    protected $table = 'visualizations';
    protected $primaryKey = 'id_visualization';
    public $timestamps = false;
    protected $fillable = [
        'id_canvas',
        'id_datasource',
        'name',
        'visualization_type',
        'query',
        'config',
        'builder_payload', // Tambahkan ini
        'width',
        'height',
        'position_x',
        'position_y',
        'created_by',
        'created_time',
        'modified_by',
        'modified_time',
        'is_deleted'
    ];

    protected $casts = [
        'config' => 'array',
        'builder_payload' => 'array', // Tambahkan ini juga
    ];

    public function datasource()
    {
        return $this->belongsTo(Datasource::class, 'id_datasource');
    }

    public function kanvas()
    {
        return $this->belongsTo(Canvas::class, 'id_canvas');
    }
}