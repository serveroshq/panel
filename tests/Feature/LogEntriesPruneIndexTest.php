<?php

use Illuminate\Support\Facades\Schema;

test('log lines have an index on machine and time, so pruning one workspace reads only its own old lines', function () {
    expect(Schema::hasIndex('log_entries', 'log_entries_machine_id_logged_at_index'))->toBeTrue();
});
