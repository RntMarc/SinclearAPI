<?php

namespace Sinclear\Api\Application;

final readonly class Settings
{
    public function __construct(
        public array $app,
        public array $db,
        public array $jwt,
        public array $discord,
        public array $smtp,
        public array $cors,
        public array $rate_limit,
        public array $pagination,
        public array $fcm = [],
        public array $downloads = [],
    ) {}
}
