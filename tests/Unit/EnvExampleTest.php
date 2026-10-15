<?php

use Dotenv\Dotenv;

it('parses .env.example so a copy of it works as .env', function () {
    $values = Dotenv::parse(file_get_contents(dirname(__DIR__, 2).'/.env.example'));

    expect($values['STUDENT_DB_LARAGON_PATHS'])->toBe('C:\laragon;D:\laragon');
});
