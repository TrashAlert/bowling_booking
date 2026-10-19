<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // When it happened, to the second.
            $table->timestampTz('occurred_at');

            // Who did it: a staff member ("user"), a customer, or the app by
            // itself ("system"). The name is kept as it was then. There is no
            // foreign key, so removing a user never has to change a line.
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name');

            // What happened, e.g. "lane.closed_for_repair", and what it was
            // about, e.g. lane 3.
            $table->string('action', 60);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // In words, and what changed from what, as JSON text. Text, not
            // json, so it is kept exactly as written and its fingerprint holds.
            $table->text('summary');
            $table->text('changes')->nullable();

            $table->string('ip_address', 45)->nullable();

            // The fingerprint of the line before and of this one. Each covers
            // the one before it, so a line changed afterwards breaks the chain.
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index('occurred_at');
            $table->index('action');
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        // The rules below are PostgreSQL features.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A line can never be changed, and can only be removed once it is more
        // than 18 months old. Emptying the whole table is refused too.
        // "Or replace": migrate:fresh drops tables but not functions.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_logs_refuse_change() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.occurred_at < now() - interval '18 months' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'The activity log cannot be changed: % refused.', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('
            CREATE TRIGGER activity_logs_append_only
            BEFORE UPDATE OR DELETE ON activity_logs
            FOR EACH ROW EXECUTE FUNCTION activity_logs_refuse_change()
        ');

        DB::statement('
            CREATE TRIGGER activity_logs_no_truncate
            BEFORE TRUNCATE ON activity_logs
            FOR EACH STATEMENT EXECUTE FUNCTION activity_logs_refuse_change()
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS activity_logs_refuse_change()');
        }
    }
};
