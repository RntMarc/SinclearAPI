<?php

declare(strict_types=1);

namespace Sinclear\Api\Dav;

use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\CardDAV\Plugin as CardDavPlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalCollection;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Services\CalendarFeedService;
use Sinclear\Api\Services\DavTokenService;
use Sinclear\Api\Services\UserService;

final readonly class DavServerFactory
{
    /**
     * Basis-Pfad des DAV-Endpunkts. Die API liegt unter /api/ auf dem
     * Webserver (die Root-Domain wird von der Web-App belegt), daher
     * ist der volle Pfad /api/dav/ - nicht /dav/.
     */
    public const string DAV_BASE_URI = '/api/dav/';

    public function __construct(
        private DavTokenService $tokenService,
        private UserRepository $userRepo,
        private UserService $userService,
        private CalendarFeedService $feedService,
        private IcsFactory $icsFactory,
        private VcardFactory $vcardFactory,
        private DavDummyFactory $dummyFactory,
        private ?LoggerInterface $logger = null,
    ) {}

    public function createServer(): Server
    {
        $authBackend = new DavAuthBackend($this->tokenService, $this->logger);
        $authBackend->setRealm('Sinclear Beyond');
        $principalBackend = new DavPrincipalBackend($this->userRepo, $authBackend);
        $caldavBackend = new CalDavBackend($this->feedService, $this->icsFactory, $this->dummyFactory);
        $carddavBackend = new CardDavBackend($this->userRepo, $this->userService, $this->vcardFactory, $this->dummyFactory);

        $server = new Server([
            new PrincipalCollection($principalBackend),
            new ReadOnlyCalendarRoot($principalBackend, $caldavBackend),
            new ReadOnlyAddressBookRoot($principalBackend, $carddavBackend),
        ]);

        $server->setBaseUri(self::DAV_BASE_URI);

        $server->addPlugin(new AuthPlugin($authBackend));

        $server->addPlugin(new AclPlugin());
        $server->addPlugin(new CalDavPlugin());
        $server->addPlugin(new CardDavPlugin());

        return $server;
    }
}
