<?php

use App\Models\Specialty;
use App\Models\User;
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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Specialty::class)->constrained()->restrictOnDelete();
            $table->text('bio')->nullable();
            $table->unsignedTinyInteger('years_experience')->default(0);
            $table->decimal('consultation_fee', 10, 2)->default(0);
            $table->string('clinic_name');
            $table->string('clinic_address');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->string('timezone')->default('UTC'); 
            $table->enum('status', ['pending', 'approved', 'suspended'])->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
