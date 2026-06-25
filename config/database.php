<?php

declare(strict_types=1);

return [
    'url' => $_ENV['DATABASE_URL'] ?? 'mysql://db:db@db:3306/db',
];
