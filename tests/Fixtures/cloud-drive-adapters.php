<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

/*
 * Stand-ins for the Google Drive and Dropbox adapters, which are not
 * dependencies of the module: they keep what they were given. Loaded only
 * in a process of its own, where no test expects the adapters to be missing.
 */

namespace Google {
    final class Client
    {
        /** @var array<string,string> */
        public array $calls = [];

        public function setClientId(string $id): void
        {
            $this->calls['clientId'] = $id;
        }

        public function setClientSecret(string $secret): void
        {
            $this->calls['clientSecret'] = $secret;
        }

        public function setAccessType(string $type): void
        {
            $this->calls['accessType'] = $type;
        }

        public function refreshToken(string $token): void
        {
            $this->calls['refreshToken'] = $token;
        }
    }
}

namespace Google\Service {
    use Google\Client;

    final class Drive
    {
        public function __construct(public Client $client) {}
    }
}

namespace Masbug\Flysystem {
    use Google\Service\Drive;
    use League\Flysystem\Local\LocalFilesystemAdapter;

    final class GoogleDriveAdapter extends LocalFilesystemAdapter
    {
        /** @param array<string,mixed> $options */
        public function __construct(public Drive $service, public string $root, public array $options = [])
        {
            parent::__construct(sys_get_temp_dir());
        }
    }
}

namespace Spatie\Dropbox {
    final class Client
    {
        public function __construct(public string $token) {}
    }
}

namespace Spatie\FlysystemDropbox {
    use League\Flysystem\Local\LocalFilesystemAdapter;
    use Spatie\Dropbox\Client;

    final class DropboxAdapter extends LocalFilesystemAdapter
    {
        public function __construct(public Client $client, public string $prefix = '')
        {
            parent::__construct(sys_get_temp_dir());
        }
    }
}
