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
        Schema::create('projects_access', function (Blueprint $table) {
            $table->id('id_project_access');
            $table->unsignedBigInteger('id_project');
            $table->unsignedBigInteger('id_user');
            $table->string('access', 4);
            $table->string('created_by')->nullable();
            $table->timestamp('created_time')->nullable();
            $table->string('modified_by')->nullable();
            $table->timestamp('modified_time')->nullable();
            $table->boolean('is_deleted')->default(false);

            $table->foreign('id_project')->references('id_project')->on('projects');
            $table->foreign('id_user')->references('id_user')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_access');
    }
};
