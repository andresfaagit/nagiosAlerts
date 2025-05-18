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
        Schema::create('store_website', function (Blueprint $table) {
            $table->smallIncrements('website_id');
            $table->string('code', 32)->nullable()->unique()->comment('Code');
            $table->string('name', 64)->nullable()->comment('Website Name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_website');
    }
};
