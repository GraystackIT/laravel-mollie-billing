<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more pieces of the shared world:
 *
 *  - `workbench_mailbox`: every notification either process sends while a test has the
 *    mailbox switched on (`WorkbenchSetting` `mailbox` = 1) — what "the admin got a mail"
 *    means in a suite whose mail goes to the log, and where the sender may be the Dusk
 *    server, out of reach of `Notification::fake()`.
 *  - `fake_mollie_chargebacks`: the fake Mollie's chargebacks, which a test creates on a
 *    paid payment (`FakeMollie::chargeback()`) and may reverse later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbench_mailbox', function (Blueprint $table): void {
            $table->id();
            $table->string('notification');
            $table->string('channel');
            $table->string('recipient')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('fake_mollie_chargebacks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('payment_id')->index();
            $table->string('amount_value');
            $table->string('amount_currency', 3);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_mollie_chargebacks');
        Schema::dropIfExists('workbench_mailbox');
    }
};
