<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add instance-scoping to `dynaflow_step_assignees`.
     *
     * Pre-this migration, an assignee row was strictly step-scoped: a static
     * pick a workflow designer authored at definition time, valid for every
     * instance that ever visits the step. That's fine for hand-picked
     * assignees (`user_id=42, role_id=7`) but breaks the moment an assignee
     * is DYNAMICALLY resolved from the running instance (e.g. "the
     * submitter's manager" — different manager per submitter). Without this
     * column, an instance-A row would be indistinguishable from an
     * instance-B row and either would satisfy the other's authorization
     * check — cross-instance leak.
     *
     * With the new column:
     * - `NULL` = classic static/step-scoped assignee (valid for any instance).
     * - non-NULL = instance-scoped, only that specific instance sees this row.
     *
     * The pre-existing unique index `step_assignable_unique`
     * (dynaflow_step_id, assignable_type, assignable_id) would prevent two
     * different instances from both resolving the same manager — dropped and
     * re-added with `dynaflow_instance_id` included so each (instance, step,
     * user) tuple is unique but the same user can be an assignee across
     * distinct instances.
     */
    public function up(): void
    {
        // Split into three ALTERs. In ONE ALTER, MySQL refuses to drop the
        // pre-existing `step_assignable_unique` because the `dynaflow_step_id`
        // FK depends on it as its covering index — the new unique doesn't
        // exist yet at the drop instant. Adding the new unique + a leading
        // step-id index in a prior ALTER gives the FK an alternative cover
        // BEFORE the old unique goes away.
        Schema::table('dynaflow_step_assignees', function (Blueprint $table) {
            $table->unsignedBigInteger('dynaflow_instance_id')
                ->nullable()
                ->after('dynaflow_step_id');

            $table->foreign('dynaflow_instance_id')
                ->references('id')->on('dynaflow_instances')
                ->cascadeOnDelete();
        });

        Schema::table('dynaflow_step_assignees', function (Blueprint $table) {
            $table->unique(
                ['dynaflow_step_id', 'dynaflow_instance_id', 'assignable_type', 'assignable_id'],
                'step_instance_assignable_unique'
            );

            $table->index(['dynaflow_step_id', 'dynaflow_instance_id'], 'step_instance_idx');
        });

        Schema::table('dynaflow_step_assignees', function (Blueprint $table) {
            $table->dropUnique('step_assignable_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dynaflow_step_assignees', function (Blueprint $table) {
            $table->unique(
                ['dynaflow_step_id', 'assignable_type', 'assignable_id'],
                'step_assignable_unique'
            );
        });

        Schema::table('dynaflow_step_assignees', function (Blueprint $table) {
            $table->dropUnique('step_instance_assignable_unique');
            $table->dropIndex('step_instance_idx');
            $table->dropForeign(['dynaflow_instance_id']);
            $table->dropColumn('dynaflow_instance_id');
        });
    }
};
