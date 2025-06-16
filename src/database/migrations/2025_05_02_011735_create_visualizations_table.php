<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visualizations', function (Blueprint $table) {
            $table->id('id_visualization');
            $table->unsignedBigInteger('id_canvas');
            $table->unsignedBigInteger('id_datasource');
            $table->string('name');
            $table->string('visualization_type');
            $table->text('query');
            $table->json('config');
            $table->double('width');
            $table->double('height');
            $table->double('position_x');
            $table->double('position_y');
            $table->string('created_by')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_time')->nullable();
            $table->boolean('is_deleted')->default(false);

            $table->foreign('id_canvas')->references('id_canvas')->on('canvas');
            $table->foreign('id_datasource')->references('id_datasource')->on('datasources');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visualizations');
    }
};
