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
        Schema::create('canvas', function (Blueprint $table) {
            $table->id('id_canvas');
            $table->unsignedBigInteger('id_project');
            $table->string('name');
            $table->string('created_by')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_time')->nullable();
            $table->boolean('is_deleted')->default(false);

            $table->foreign('id_project')->references('id_project')->on('projects');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canvas');
    }
};
