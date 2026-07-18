<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\EventRelationRepository;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\TravelAccommodationRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelRelationRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Services\NotificationService;
use Sinclear\Api\Services\SubscriptionService;

final readonly class AdminController
{
    private const array VALID_CODES = [
        'admin.system_update',
        'admin.new_feature',
        'admin.maintenance',
        'admin.welcome',
        'admin.test',
        'admin.custom',
    ];

    private const array VALID_DEEP_LINKS = [
        'home', 'travel', 'events', 'profile', 'settings',
        'friends', 'discover', 'news', 'chat', 'feedback',
    ];

    public function __construct(
        private OtpService $otpService,
        private OtpTokenRepository $otpTokenRepo,
        private UserRepository $userRepo,
        private NotificationService $notificationService,
        private TravelTripRepository $tripRepo,
        private TravelEventRepository $eventRepo,
        private TravelRelationRepository $travelRelationRepo,
        private TravelAccommodationRepository $accommodationRepo,
        private EventRelationRepository $eventRelationRepo,
        private ForumRepository $forumRepo,
        private ForumMemberRepository $forumMemberRepo,
        private SubscriptionRepository $subscriptionRepo,
        private SubscriptionService $subscriptionService,
    ) {}

    public function loginPage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['admin_id'], $_SESSION['admin_email'])) {
            $response->getBody()->write('');
            return $response->withStatus(302)->withHeader('Location', '/api/v2/admin/');
        }

        $html = file_get_contents(__DIR__ . '/../../templates/admin/login.php') ?: '';
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function loginOtpRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ResponseFactory::json(['error' => 'invalid_email'], 400, $response);
        }

        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if (!($user['isAdmin'] ?? false)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        if (!$this->otpService->canRequestCode($email)) {
            return ResponseFactory::json(['error' => 'too_many_requests'], 429, $response);
        }

        $code = $this->otpService->generateCode();
        $this->otpService->sendOtpEmail($email, $code);
        $this->otpService->storeCode($email, $code);

        return ResponseFactory::json(['message' => 'otp_sent'], 200, $response);
    }

    public function loginOtpVerify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $code = trim($body['code'] ?? '');

        if (empty($code) || !preg_match('/^\d{6}$/', $code)) {
            return ResponseFactory::json(['error' => 'invalid_code'], 400, $response);
        }

        $otpToken = $this->otpTokenRepo->findValid($email, $code);
        if ($otpToken === null) {
            return ResponseFactory::json(['error' => 'invalid_or_expired_code'], 400, $response);
        }

        $user = $this->userRepo->findByEmail($otpToken['email']);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if (!($user['isAdmin'] ?? false)) {
            return ResponseFactory::json(['error' => 'forbidden'], 403, $response);
        }

        $this->otpTokenRepo->markUsed($otpToken['id']);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_email'] = $user['email'];

        return ResponseFactory::json(['message' => 'login_success'], 200, $response);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();

        return $response->withStatus(302)->withHeader('Location', '/api/v2/admin/login');
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $userCount = $this->userRepo->countAll();
        $tripCount = $this->tripRepo->countAll();

        $contentHtml = $this->renderTemplate('dashboard.php', [
            'userCount' => $userCount,
            'tripCount' => $tripCount,
        ]);
        $html = $this->renderLayout('Dashboard', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $allUsers = $this->userRepo->findAll();

        $rows = '';
        foreach ($allUsers as $u) {
            $createdAt = date('d.m.Y H:i', strtotime($u['createdAt']));
            $adminBadge = ($u['isAdmin'] ?? false)
                ? '<span class="badge badge-admin">Admin</span>'
                : '';
            $rows .= <<<ROW
            <tr>
                <td>{$u['id']}</td>
                <td>{$u['email']}</td>
                <td>{$u['displayName']}</td>
                <td>{$adminBadge}</td>
                <td>{$createdAt}</td>
                <td><button class="btn btn-sm" disabled title="Coming soon">Bearbeiten</button></td>
            </tr>
ROW;
        }

        $contentHtml = $this->renderTemplate('users.php', ['rows' => $rows]);
        $html = $this->renderLayout('Nutzerverwaltung', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function adminUsersJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $users = $this->userRepo->findAll();

        $result = array_map(fn(array $u) => [
            'id' => $u['id'],
            'email' => $u['email'],
            'displayName' => $u['displayName'],
            'isAdmin' => (bool) ($u['isAdmin'] ?? false),
        ], $users);

        return ResponseFactory::json(['data' => $result], 200, $response);
    }

    public function travel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $allTrips = $this->tripRepo->findAll();
        $allEvents = $this->eventRepo->findAll();

        $tripById = [];
        foreach ($allTrips as $t) {
            $tripById[$t['id']] = $t['name'];
        }

        $tripRows = '';
        foreach ($allTrips as $t) {
            $id = htmlspecialchars($t['id']);
            $name = htmlspecialchars($t['name']);
            $desc = htmlspecialchars($t['description'] ?? '');
            $start = date('d.m.Y', strtotime($t['start']));
            $end = date('d.m.Y', strtotime($t['end']));
            $hastickets = $t['hastickets'] === '1' ? 'Ja' : 'Nein';
            $tripRows .= <<<ROW
            <tr>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$id}">{$id}</td>
                <td><a href="/api/v2/admin/travel/trips/{$id}" style="color:#5865F2;text-decoration:none;">{$name}</a></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$desc}">{$desc}</td>
                <td>{$start} – {$end}</td>
                <td>{$hastickets}</td>
                <td class="flex" style="gap:0.4rem;">
                    <button class="btn btn-sm btn-primary" onclick="editTrip('{$id}', `{$name}`, `{$desc}`, '{$t['start']}', '{$t['end']}', '{$t['hastickets']}', `{$t['ticket']}`, `{$t['ticketUrl']}`)">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteTrip('{$id}', '{$name}')">Löschen</button>
                </td>
            </tr>
ROW;
        }

        $eventRows = '';
        foreach ($allEvents as $e) {
            $eId = htmlspecialchars($e['ID']);
            $eName = htmlspecialchars($e['name']);
            $eDesc = htmlspecialchars($e['description'] ?? '');
            $eTripId = $e['trip'] ?? '';
            $eTripName = $eTripId !== '' && isset($tripById[$eTripId])
                ? htmlspecialchars($tripById[$eTripId])
                : '–';
            $eStart = date('d.m.Y H:i', strtotime($e['start']));
            $eEnd = date('d.m.Y H:i', strtotime($e['end']));
            $eventRows .= <<<ROW
            <tr>
                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$eId}">{$eId}</td>
                <td><a href="/api/v2/admin/travel/events/{$eId}" style="color:#5865F2;text-decoration:none;">{$eName}</a></td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$eDesc}">{$eDesc}</td>
                <td>{$eTripName}</td>
                <td>{$eStart}</td>
                <td>{$eEnd}</td>
                <td class="flex" style="gap:0.4rem;">
                    <button class="btn btn-sm btn-primary" onclick="editEvent('{$eId}', `{$eName}`, `{$eDesc}`, '{$eTripId}', '{$e['start']}', '{$e['end']}', '{$e['hastickets']}', `{$e['ticket']}`, `{$e['ticketUrl']}`, `{$e['url']}`, `{$e['image']}`, `{$e['organizer']}`, `{$e['address']}`, `{$e['latitude']}`, `{$e['longitude']}`, `{$e['OSMID']}`)">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteEvent('{$eId}', '{$eName}')">Löschen</button>
                </td>
            </tr>
ROW;
        }

        $tripOptions = '<option value="">– Keine Reise (Standalone) –</option>';
        foreach ($allTrips as $t) {
            $tid = htmlspecialchars($t['id']);
            $tname = htmlspecialchars($t['name']);
            $tripOptions .= "<option value=\"{$tid}\">{$tname}</option>";
        }

        $contentHtml = $this->renderTemplate('travel.php', [
            'tripRows' => $tripRows,
            'eventRows' => $eventRows,
            'tripOptions' => $tripOptions,
        ]);
        $html = $this->renderLayout('Reisen & Events', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createTrip(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }

        $start = trim((string) ($body['start'] ?? ''));
        $end = trim((string) ($body['end'] ?? ''));
        if ($start === '' || $end === '') {
            return ResponseFactory::json(['error' => 'start_and_end_required'], 400, $response);
        }

        $id = $this->tripRepo->create([
            'name' => $name,
            'description' => isset($body['description']) && is_string($body['description'])
                ? trim($body['description']) : null,
            'start' => $start,
            'end' => $end,
            'hastickets' => !empty($body['hastickets']) ? '1' : '0',
            'ticket' => isset($body['ticket']) && is_string($body['ticket'])
                ? trim($body['ticket']) : null,
            'ticketUrl' => isset($body['ticketUrl']) && is_string($body['ticketUrl'])
                ? trim($body['ticketUrl']) : null,
        ]);

        $trip = $this->tripRepo->findById($id);
        return ResponseFactory::json(['data' => $trip], 201, $response);
    }

    public function updateTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = is_string($body['description'])
                ? trim($body['description']) : null;
        }
        if (isset($body['start'])) {
            $data['start'] = trim((string) $body['start']);
        }
        if (isset($body['end'])) {
            $data['end'] = trim((string) $body['end']);
        }
        if (isset($body['hastickets'])) {
            $data['hastickets'] = !empty($body['hastickets']) ? '1' : '0';
        }
        if (isset($body['ticket'])) {
            $data['ticket'] = is_string($body['ticket'])
                ? trim($body['ticket']) : null;
        }
        if (isset($body['ticketUrl'])) {
            $data['ticketUrl'] = is_string($body['ticketUrl'])
                ? trim($body['ticketUrl']) : null;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->tripRepo->update($id, $data);
        $updated = $this->tripRepo->findById($id);
        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function deleteTrip(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }

        $this->tripRepo->delete($id);
        return ResponseFactory::noContent($response);
    }

    public function createEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }

        $start = trim((string) ($body['start'] ?? ''));
        $end = trim((string) ($body['end'] ?? ''));
        if ($start === '' || $end === '') {
            return ResponseFactory::json(['error' => 'start_and_end_required'], 400, $response);
        }

        $tripId = isset($body['trip']) && is_string($body['trip']) && $body['trip'] !== ''
            ? trim($body['trip']) : null;

        $id = $this->eventRepo->create([
            'trip' => $tripId,
            'name' => $name,
            'description' => isset($body['description']) && is_string($body['description'])
                ? trim($body['description']) : null,
            'start' => $start,
            'end' => $end,
            'hastickets' => !empty($body['hastickets']) ? '1' : '0',
            'ticket' => isset($body['ticket']) && is_string($body['ticket'])
                ? trim($body['ticket']) : null,
            'ticketUrl' => isset($body['ticketUrl']) && is_string($body['ticketUrl'])
                ? trim($body['ticketUrl']) : null,
            'url' => isset($body['url']) && is_string($body['url'])
                ? trim($body['url']) : null,
            'image' => isset($body['image']) && is_string($body['image'])
                ? trim($body['image']) : null,
            'organizer' => isset($body['organizer']) && is_string($body['organizer'])
                ? trim($body['organizer']) : null,
            'address' => isset($body['address']) && is_string($body['address'])
                ? trim($body['address']) : null,
            'latitude' => isset($body['latitude']) && $body['latitude'] !== ''
                ? (float) $body['latitude'] : null,
            'longitude' => isset($body['longitude']) && $body['longitude'] !== ''
                ? (float) $body['longitude'] : null,
            'OSMID' => isset($body['OSMID']) && $body['OSMID'] !== ''
                ? (int) $body['OSMID'] : null,
        ]);

        $event = $this->eventRepo->findById($id);
        return ResponseFactory::json(['data' => $event], 201, $response);
    }

    public function updateEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = is_string($body['description'])
                ? trim($body['description']) : null;
        }
        $stringFields = ['start', 'end', 'ticket', 'ticketUrl', 'url', 'image', 'organizer', 'address'];
        foreach ($stringFields as $field) {
            if (isset($body[$field])) {
                $data[$field] = is_string($body[$field])
                    ? trim($body[$field]) : null;
            }
        }
        if (isset($body['trip'])) {
            $data['trip'] = is_string($body['trip']) && $body['trip'] !== ''
                ? trim($body['trip']) : null;
        }
        if (isset($body['hastickets'])) {
            $data['hastickets'] = !empty($body['hastickets']) ? '1' : '0';
        }
        if (isset($body['latitude'])) {
            $data['latitude'] = $body['latitude'] !== ''
                ? (float) $body['latitude'] : null;
        }
        if (isset($body['longitude'])) {
            $data['longitude'] = $body['longitude'] !== ''
                ? (float) $body['longitude'] : null;
        }
        if (isset($body['OSMID'])) {
            $data['OSMID'] = $body['OSMID'] !== ''
                ? (int) $body['OSMID'] : null;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->eventRepo->update($id, $data);
        $updated = $this->eventRepo->findById($id);
        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function deleteEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $this->eventRepo->delete($id);
        return ResponseFactory::noContent($response);
    }

    public function tripDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $trip = $this->tripRepo->findById($id);
        if ($trip === null) {
            $response->getBody()->write('<h1>Reise nicht gefunden</h1><a href="/api/v2/admin/travel">Zurück</a>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        // Participants
        $participants = $this->travelRelationRepo->findParticipantRelationsByTrip($id);
        $participantCount = count($participants);

        // All accommodations for the select dropdown + pre-select current
        $allAccommodations = $this->accommodationRepo->findAll();
        $accommodationOptionsForSelect = '';
        foreach ($allAccommodations as $a) {
            $aId = htmlspecialchars($a['ID']);
            $aName = htmlspecialchars($a['name']);
            $accommodationOptionsForSelect .= "<option value=\"{$aId}\">{$aName}</option>";
        }

        $participantRows = '';
        foreach ($participants as $p) {
            $pUserId = htmlspecialchars($p['userid']);
            $pName = htmlspecialchars($p['displayName']);
            $pEmail = htmlspecialchars($p['email']);
            $currentAccId = $p['accommodation'] ?? '';
            $options = '<option value="">– Keine –</option>';
            foreach ($allAccommodations as $a) {
                $aId = htmlspecialchars($a['ID']);
                $aName = htmlspecialchars($a['name']);
                $selected = $aId === $currentAccId ? ' selected' : '';
                $options .= "<option value=\"{$aId}\"{$selected}>{$aName}</option>";
            }
            $participantRows .= <<<ROW
            <tr>
                <td>{$pName}</td>
                <td>{$pEmail}</td>
                <td>
                    <select onchange="changeAccommodation('{$pUserId}', this)" style="background:#1a1a2e;color:#fff;border:1px solid #0f3460;border-radius:6px;padding:0.3rem;max-width:180px;">
                        {$options}
                    </select>
                </td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="removeParticipant('{$pUserId}', '{$pName}')">Entfernen</button>
                </td>
            </tr>
ROW;
        }

        // User options for add-participant dropdown
        $allUsers = $this->userRepo->findAll();
        $userOptions = '';
        foreach ($allUsers as $u) {
            $uid = htmlspecialchars($u['id']);
            $uname = htmlspecialchars($u['displayName']);
            $uemail = htmlspecialchars($u['email']);
            $userOptions .= "<option value=\"{$uid}\">{$uname} ({$uemail})</option>";
        }

        // Accommodations table
        $tripAccommodations = $this->accommodationRepo->findByTrip($id);
        $accommodationRows = '';
        foreach ($tripAccommodations as $a) {
            $aId = htmlspecialchars($a['ID']);
            $aName = htmlspecialchars($a['name']);
            $aAddress = htmlspecialchars($a['address'] ?? '');
            $aPhone = htmlspecialchars($a['phone'] ?? '');
            $aMail = htmlspecialchars($a['mail'] ?? '');
            $aContact = $aPhone . ($aMail ? ' / ' . $aMail : '');
            $aIshotel = $a['ishotel'] ? 'Hotel' : 'Privat';
            $accommodationRows .= <<<ROW
            <tr>
                <td>{$aName}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$aAddress}">{$aAddress}</td>
                <td>{$aIshotel}</td>
                <td>{$aContact}</td>
                <td class="flex" style="gap:0.4rem;">
                    <button class="btn btn-sm btn-primary" onclick="editAccommodation('{$aId}', `{$aName}`, `{$a['description']}`, `{$aAddress}`, '{$aPhone}', '{$aMail}', '{$a['latitude']}', '{$a['longitude']}', '{$a['OSMID']}', '{$a['ishotel']}')">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteAccommodation('{$aId}', '{$aName}')">Löschen</button>
                </td>
            </tr>
ROW;
        }

        // Events for this trip (linked events with unlink button)
        $allTrips = $this->tripRepo->findAll();
        $tripById = [];
        foreach ($allTrips as $t) {
            $tripById[$t['id']] = $t['name'];
        }

        $tripEvents = $this->eventRepo->findByTrip($id);
        $tripEventCount = count($tripEvents);
        $tripEventRows = '';
        if ($tripEventCount > 0) {
            $tripEventRows .= '<table><thead><tr><th>Name</th><th>Start</th><th>Ende</th><th>Aktionen</th></tr></thead><tbody>';
            foreach ($tripEvents as $e) {
                $eId = htmlspecialchars($e['ID']);
                $eName = htmlspecialchars($e['name']);
                $eStart = date('d.m.Y H:i', strtotime($e['start']));
                $eEnd = date('d.m.Y H:i', strtotime($e['end']));
                $tripEventRows .= <<<ROW
                <tr>
                    <td><a href="/api/v2/admin/travel/events/{$eId}" style="color:#5865F2;text-decoration:none;">{$eName}</a></td>
                    <td>{$eStart}</td>
                    <td>{$eEnd}</td>
                    <td>
                        <button class="btn btn-sm btn-danger" onclick="unlinkEvent('{$eId}', '{$eName}')">Trennen</button>
                    </td>
                </tr>
ROW;
            }
            $tripEventRows .= '</tbody></table>';
        } else {
            $tripEventRows = '<p style="color:#666;">Keine Events für diese Reise.</p>';
        }

        // All events not linked to this trip (for the link dropdown)
        $allEvents = $this->eventRepo->findAll();
        $availableEventOptions = '';
        foreach ($allEvents as $e) {
            if (($e['trip'] ?? null) === $id) {
                continue;
            }
            $eId = htmlspecialchars($e['ID']);
            $eName = htmlspecialchars($e['name']);
            $eTripLabel = '';
            if (!empty($e['trip'])) {
                $eTripLabel = ' (Reise: ' . htmlspecialchars($tripById[$e['trip']] ?? $e['trip']) . ')';
            }
            $availableEventOptions .= "<option value=\"{$eId}\">{$eName}{$eTripLabel}</option>";
        }

        $tripName = htmlspecialchars($trip['name']);
        $tripDesc = htmlspecialchars($trip['description'] ?? '');
        $tripStart = date('d.m.Y H:i', strtotime($trip['start']));
        $tripEnd = date('d.m.Y H:i', strtotime($trip['end']));

        $contentHtml = $this->renderTemplate('trip_detail.php', [
            'tripId' => $id,
            'tripName' => $tripName,
            'tripDescription' => $tripDesc,
            'tripStart' => $tripStart,
            'tripEnd' => $tripEnd,
            'participantRows' => $participantRows,
            'participantCount' => $participantCount,
            'userOptions' => $userOptions,
            'accommodationOptions' => $accommodationOptionsForSelect,
            'accommodationRows' => $accommodationRows,
            'tripEventRows' => $tripEventRows,
            'tripEventCount' => $tripEventCount,
            'availableEventOptions' => $availableEventOptions,
        ]);
        $html = $this->renderLayout("Reise: {$tripName}", $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function addTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $tripId = $args['id'];
        $body = $request->getParsedBody();

        $userId = trim((string) ($body['userId'] ?? ''));
        if ($userId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        $trip = $this->tripRepo->findById($tripId);
        if ($trip === null) {
            return ResponseFactory::json(['error' => 'trip_not_found'], 404, $response);
        }

        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        if ($this->travelRelationRepo->isParticipant($userId, $tripId)) {
            return ResponseFactory::json(['error' => 'already_participant'], 409, $response);
        }

        $accommodation = isset($body['accommodation']) && is_string($body['accommodation']) && $body['accommodation'] !== ''
            ? trim($body['accommodation']) : null;

        $relationId = $this->travelRelationRepo->addParticipant($userId, $tripId, $accommodation);

        return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
    }

    public function removeTripParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $tripId = $args['id'];
        $userId = $args['userId'];

        if (!$this->travelRelationRepo->isParticipant($userId, $tripId)) {
            return ResponseFactory::json(['error' => 'not_a_participant'], 404, $response);
        }

        $this->travelRelationRepo->removeByUserAndTrip($userId, $tripId);
        return ResponseFactory::noContent($response);
    }

    public function updateParticipantAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $tripId = $args['id'];
        $userId = $args['userId'];
        $body = $request->getParsedBody();

        if (!$this->travelRelationRepo->isParticipant($userId, $tripId)) {
            return ResponseFactory::json(['error' => 'not_a_participant'], 404, $response);
        }

        $accommodation = isset($body['accommodation']) && is_string($body['accommodation']) && $body['accommodation'] !== ''
            ? trim($body['accommodation']) : null;

        $this->travelRelationRepo->updateAccommodation($userId, $tripId, $accommodation);
        return ResponseFactory::json(['message' => 'accommodation_updated'], 200, $response);
    }

    public function createTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }

        $id = $this->accommodationRepo->create([
            'name' => $name,
            'description' => isset($body['description']) && is_string($body['description'])
                ? trim($body['description']) : null,
            'address' => isset($body['address']) && is_string($body['address'])
                ? trim($body['address']) : null,
            'OSMID' => isset($body['OSMID']) && $body['OSMID'] !== ''
                ? (int) $body['OSMID'] : null,
            'latitude' => isset($body['latitude']) && $body['latitude'] !== ''
                ? (float) $body['latitude'] : null,
            'longitude' => isset($body['longitude']) && $body['longitude'] !== ''
                ? (float) $body['longitude'] : null,
            'phone' => isset($body['phone']) && is_string($body['phone'])
                ? trim($body['phone']) : null,
            'mail' => isset($body['mail']) && is_string($body['mail'])
                ? trim($body['mail']) : null,
            'ishotel' => !empty($body['ishotel']) ? 1 : 0,
        ]);

        $accommodation = $this->accommodationRepo->findById($id);
        return ResponseFactory::json(['data' => $accommodation], 201, $response);
    }

    public function updateTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['accId'];
        $body = $request->getParsedBody();

        $accommodation = $this->accommodationRepo->findById($id);
        if ($accommodation === null) {
            return ResponseFactory::json(['error' => 'accommodation_not_found'], 404, $response);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        $stringFields = ['description', 'address', 'phone', 'mail'];
        foreach ($stringFields as $field) {
            if (isset($body[$field])) {
                $data[$field] = is_string($body[$field])
                    ? trim($body[$field]) : null;
            }
        }
        if (isset($body['OSMID'])) {
            $data['OSMID'] = $body['OSMID'] !== '' ? (int) $body['OSMID'] : null;
        }
        if (isset($body['latitude'])) {
            $data['latitude'] = $body['latitude'] !== '' ? (float) $body['latitude'] : null;
        }
        if (isset($body['longitude'])) {
            $data['longitude'] = $body['longitude'] !== '' ? (float) $body['longitude'] : null;
        }
        if (isset($body['ishotel'])) {
            $data['ishotel'] = !empty($body['ishotel']) ? 1 : 0;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->accommodationRepo->update($id, $data);
        $updated = $this->accommodationRepo->findById($id);
        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function deleteTripAccommodation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['accId'];

        $accommodation = $this->accommodationRepo->findById($id);
        if ($accommodation === null) {
            return ResponseFactory::json(['error' => 'accommodation_not_found'], 404, $response);
        }

        $this->accommodationRepo->delete($id);
        return ResponseFactory::noContent($response);
    }

    public function eventDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $id = $args['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            $response->getBody()->write('<h1>Event nicht gefunden</h1><a href="/api/v2/admin/travel">Zurück</a>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $participants = $this->eventRelationRepo->findByEvent($id);
        $participantCount = count($participants);

        $participantRows = '';
        foreach ($participants as $p) {
            $pUserId = htmlspecialchars($p['userId']);
            $pName = htmlspecialchars($p['displayName']);
            $pEmail = htmlspecialchars($p['email']);
            $pCreatedAt = date('d.m.Y H:i', strtotime($p['createdAt']));
            $participantRows .= <<<ROW
            <tr>
                <td>{$pName}</td>
                <td>{$pEmail}</td>
                <td>{$pCreatedAt}</td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="removeParticipant('{$pUserId}', '{$pName}')">Entfernen</button>
                </td>
            </tr>
ROW;
        }

        // User options for add-participant dropdown
        $allUsers = $this->userRepo->findAll();
        $userOptions = '';
        foreach ($allUsers as $u) {
            $uid = htmlspecialchars($u['id']);
            $uname = htmlspecialchars($u['displayName']);
            $uemail = htmlspecialchars($u['email']);
            $userOptions .= "<option value=\"{$uid}\">{$uname} ({$uemail})</option>";
        }

        $eventName = htmlspecialchars($event['name']);
        $eventDesc = htmlspecialchars($event['description'] ?? '');
        $eventStart = date('d.m.Y H:i', strtotime($event['start']));
        $eventEnd = date('d.m.Y H:i', strtotime($event['end']));
        $eventTrip = $event['trip'] ?? null;
        if ($eventTrip !== null) {
            $trip = $this->tripRepo->findById($eventTrip);
            $eventTrip = $trip ? '<a href="/api/v2/admin/travel/trips/' . htmlspecialchars($trip['id']) . '" style="color:#5865F2;text-decoration:none;">' . htmlspecialchars($trip['name']) . '</a>' : '–';
        } else {
            $eventTrip = 'Standalone-Event';
        }
        $eventOrganizer = htmlspecialchars($event['organizer'] ?? '–');
        $eventAddress = htmlspecialchars($event['address'] ?? '–');

        $extras = '';
        if (!empty($event['url'])) {
            $extras .= '<tr><td style="border:none;padding:0.3rem 0;color:#888;">URL</td><td style="border:none;padding:0.3rem 0;"><a href="' . htmlspecialchars($event['url']) . '" target="_blank" style="color:#5865F2;">' . htmlspecialchars($event['url']) . '</a></td></tr>';
        }
        if (!empty($event['latitude']) && !empty($event['longitude'])) {
            $extras .= '<tr><td style="border:none;padding:0.3rem 0;color:#888;">Koordinaten</td><td style="border:none;padding:0.3rem 0;">' . htmlspecialchars($event['latitude']) . ', ' . htmlspecialchars($event['longitude']) . '</td></tr>';
        }
        if (!empty($event['hastickets']) && $event['hastickets'] === '1') {
            $extras .= '<tr><td style="border:none;padding:0.3rem 0;color:#888;">Tickets</td><td style="border:none;padding:0.3rem 0;">Ja' .
                (!empty($event['ticket']) ? ' – ' . htmlspecialchars($event['ticket']) : '') .
                (!empty($event['ticketUrl']) ? ' – <a href="' . htmlspecialchars($event['ticketUrl']) . '" target="_blank" style="color:#5865F2;">Link</a>' : '') .
                '</td></tr>';
        }

        // JSON data for the edit button
        $editData = json_encode([
            'id' => $event['ID'],
            'name' => $event['name'],
            'description' => $event['description'] ?? '',
            'trip' => $event['trip'] ?? '',
            'start' => $event['start'],
            'end' => $event['end'],
            'hastickets' => $event['hastickets'] ?? '0',
            'ticket' => $event['ticket'] ?? '',
            'ticketUrl' => $event['ticketUrl'] ?? '',
            'url' => $event['url'] ?? '',
            'image' => $event['image'] ?? '',
            'organizer' => $event['organizer'] ?? '',
            'address' => $event['address'] ?? '',
            'latitude' => $event['latitude'] ?? '',
            'longitude' => $event['longitude'] ?? '',
            'OSMID' => $event['OSMID'] ?? '',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_SINGLE | JSON_HEX_QUOT);

        // Trip options for the edit form
        $allTrips = $this->tripRepo->findAll();
        $tripOptions = '<option value="">– Keine Reise (Standalone) –</option>';
        foreach ($allTrips as $t) {
            $tid = htmlspecialchars($t['id']);
            $tname = htmlspecialchars($t['name']);
            $tripOptions .= "<option value=\"{$tid}\">{$tname}</option>";
        }

        $contentHtml = $this->renderTemplate('event_detail.php', [
            'eventId' => $id,
            'eventName' => $eventName,
            'eventDescription' => $eventDesc,
            'eventStart' => $eventStart,
            'eventEnd' => $eventEnd,
            'eventTrip' => $eventTrip,
            'eventOrganizer' => $eventOrganizer,
            'eventAddress' => $eventAddress,
            'eventDetailsExtras' => $extras,
            'participantRows' => $participantRows,
            'participantCount' => $participantCount,
            'userOptions' => $userOptions,
            'tripOptions' => $tripOptions,
            'eventEditData' => $editData,
        ]);
        $html = $this->renderLayout("Event: {$eventName}", $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function addEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $eventId = $args['id'];
        $body = $request->getParsedBody();

        $userId = trim((string) ($body['userId'] ?? ''));
        if ($userId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return ResponseFactory::json(['error' => 'event_not_found'], 404, $response);
        }

        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $relationId = $this->eventRelationRepo->addParticipant($eventId, $userId);

        return ResponseFactory::json(['data' => ['id' => $relationId]], 201, $response);
    }

    public function removeEventParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $eventId = $args['id'];
        $userId = $args['userId'];

        $this->eventRelationRepo->removeByEventAndUser($eventId, $userId);
        return ResponseFactory::noContent($response);
    }

    public function forums(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $result = $this->forumRepo->list(1, 99999);
        $allForums = $result['data'];
        $forumIds = array_column($allForums, 'id');
        $memberCounts = $this->forumMemberRepo->countByForums($forumIds);

        $rows = '';
        foreach ($allForums as $f) {
            $createdAt = date('d.m.Y H:i', strtotime($f['createdAt']));
            $memberCount = $memberCounts[$f['id']] ?? 0;
            $id = htmlspecialchars($f['id']);
            $name = htmlspecialchars($f['name']);
            $description = htmlspecialchars($f['description'] ?? '');
            $rows .= <<<ROW
            <tr>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$id}">{$id}</td>
                <td>{$name}</td>
                <td>{$description}</td>
                <td>{$memberCount}</td>
                <td>{$createdAt}</td>
                <td class="flex" style="gap:0.4rem;">
                    <button class="btn btn-sm btn-primary" onclick="editForum('{$id}', '{$name}', `{$description}`)">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteForum('{$id}', '{$name}')">Löschen</button>
                </td>
            </tr>
ROW;
        }

        $contentHtml = $this->renderTemplate('forums.php', ['rows' => $rows]);
        $html = $this->renderLayout('Foren', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function adminForumsJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $result = $this->forumRepo->list(1, 99999);
        $forumIds = array_column($result['data'], 'id');
        $memberCounts = $this->forumMemberRepo->countByForums($forumIds);

        $data = array_map(fn(array $f) => [
            'id' => $f['id'],
            'name' => $f['name'],
            'description' => $f['description'],
            'memberCount' => $memberCounts[$f['id']] ?? 0,
            'createdAt' => $f['createdAt'],
        ], $result['data']);

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function createForum(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }

        $description = isset($body['description']) && is_string($body['description'])
            ? trim($body['description']) : null;

        $id = $this->forumRepo->create([
            'name' => $name,
            'description' => $description,
        ]);

        $forum = $this->forumRepo->findById($id);
        return ResponseFactory::json(['data' => $forum], 201, $response);
    }

    public function updateForum(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];
        $body = $request->getParsedBody();

        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            return ResponseFactory::json(['error' => 'forum_not_found'], 404, $response);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        if (isset($body['description'])) {
            $data['description'] = is_string($body['description'])
                ? trim($body['description']) : null;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        $this->forumRepo->update($id, $data);
        $updated = $this->forumRepo->findById($id);
        return ResponseFactory::json(['data' => $updated], 200, $response);
    }

    public function deleteForum(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $forum = $this->forumRepo->findById($id);
        if ($forum === null) {
            return ResponseFactory::json(['error' => 'forum_not_found'], 404, $response);
        }

        $this->forumRepo->delete($id);
        return ResponseFactory::noContent($response);
    }

    public function notifications(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);

        $deepLinks = implode(', ', array_map(
            fn(string $d) => "'{$d}'",
            self::VALID_DEEP_LINKS,
        ));

        $contentHtml = $this->renderTemplate('notifications.php', [
            'deepLinks' => $deepLinks,
        ]);
        $html = $this->renderLayout('Benachrichtigungen', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function sendNotification(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $userId = trim((string) ($body['userId'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $deepLink = trim((string) ($body['deepLink'] ?? 'home'));

        if ($userId === '') {
            return ResponseFactory::json(['error' => 'userId_required'], 400, $response);
        }

        if (!in_array($code, self::VALID_CODES, true)) {
            return ResponseFactory::json(['error' => 'invalid_code'], 400, $response);
        }

        if (!in_array($deepLink, self::VALID_DEEP_LINKS, true)) {
            return ResponseFactory::json(['error' => 'invalid_deepLink'], 400, $response);
        }

        $targetUser = $this->userRepo->findById($userId);
        if ($targetUser === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $payload = ['deepLink' => $deepLink];

        if ($code === 'admin.custom') {
            $title = trim((string) ($body['title'] ?? ''));
            $bodyText = trim((string) ($body['body'] ?? ''));
            if ($title === '' || $bodyText === '') {
                return ResponseFactory::json(['error' => 'title_and_body_required'], 400, $response);
            }
            $payload['title'] = $title;
            $payload['body'] = $bodyText;
        }

        $notificationId = $this->notificationService->createNotification($userId, $code, $payload);

        return ResponseFactory::json([
            'data' => [
                'id' => $notificationId,
                'userId' => $userId,
                'code' => $code,
            ],
        ], 201, $response);
    }

    public function subscriptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $subscriptions = $this->subscriptionService->listAll();

        $rows = '';
        foreach ($subscriptions as $sub) {
            $start = date('d.m.Y', strtotime($sub['billingPeriodStart']));
            $end = date('d.m.Y', strtotime($sub['billingPeriodEnd']));
            $price = number_format($sub['basePrice'], 2, ',', '.') . ' €';
            $participantBadge = '<span class="badge badge-admin">' . $sub['participantCount'] . '</span>';
            $rows .= <<<ROW
            <tr>
                <td>{$sub['id']}</td>
                <td>{$sub['name']}</td>
                <td>{$start}</td>
                <td>{$end}</td>
                <td>{$price}</td>
                <td>{$participantBadge}</td>
                <td>
                    <a href="/api/v2/admin/subscriptions/{$sub['id']}" class="btn btn-sm">Details</a>
                    <button class="btn btn-sm" onclick="editSubscription('{$sub['id']}', '{$sub['name']}', '{$sub['billingPeriodStart']}', '{$sub['billingPeriodEnd']}', {$sub['basePrice']})">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteSubscription('{$sub['id']}')">Löschen</button>
                </td>
            </tr>
ROW;
        }

        $contentHtml = $this->renderTemplate('subscriptions.php', ['rows' => $rows]);
        $html = $this->renderLayout('Abonnements', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function adminSubscriptionsJson(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $subscriptions = $this->subscriptionService->listAll();

        return ResponseFactory::json(['data' => $subscriptions], 200, $response);
    }

    public function createSubscription(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        $billingPeriodStart = trim((string) ($body['billingPeriodStart'] ?? ''));
        $billingPeriodEnd = trim((string) ($body['billingPeriodEnd'] ?? ''));
        $basePrice = isset($body['basePrice']) ? (float) $body['basePrice'] : null;

        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }
        if ($billingPeriodStart === '' || $billingPeriodEnd === '') {
            return ResponseFactory::json(['error' => 'billing_period_required'], 400, $response);
        }
        if ($basePrice === null || $basePrice < 0) {
            return ResponseFactory::json(['error' => 'invalid_base_price'], 400, $response);
        }

        try {
            $subscription = $this->subscriptionService->create([
                'name' => $name,
                'billingPeriodStart' => $billingPeriodStart,
                'billingPeriodEnd' => $billingPeriodEnd,
                'basePrice' => $basePrice,
            ]);
            return ResponseFactory::json(['data' => $subscription], 201, $response);
        } catch (\RuntimeException $e) {
            return ResponseFactory::json(['error' => 'creation_failed'], 500, $response);
        }
    }

    public function updateSubscription(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $data = [];

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }

        if (isset($body['billingPeriodStart'])) {
            $data['billingPeriodStart'] = trim((string) $body['billingPeriodStart']);
        }

        if (isset($body['billingPeriodEnd'])) {
            $data['billingPeriodEnd'] = trim((string) $body['billingPeriodEnd']);
        }

        if (isset($body['basePrice'])) {
            $basePrice = (float) $body['basePrice'];
            if ($basePrice < 0) {
                return ResponseFactory::json(['error' => 'invalid_base_price'], 400, $response);
            }
            $data['basePrice'] = $basePrice;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        try {
            $subscription = $this->subscriptionService->update($args['id'], $data);
            return ResponseFactory::json(['data' => $subscription], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'update_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function deleteSubscription(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        try {
            $this->subscriptionService->delete($args['id']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'delete_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function addSubscriptionParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $userName = !empty($body['userName']) ? trim((string) $body['userName']) : null;
        $userId = !empty($body['userId']) ? trim((string) $body['userId']) : null;
        $hasPaid = isset($body['hasPaid']) ? (bool) $body['hasPaid'] : false;

        if ($userName === null && $userId === null) {
            return ResponseFactory::json(['error' => 'userName_or_userId_required'], 400, $response);
        }

        try {
            $participant = $this->subscriptionService->addParticipant($args['id'], [
                'userId' => $userId,
                'isUser' => $userId !== null ? 1 : 0,
                'userName' => $userName,
                'hasPaid' => $hasPaid ? 1 : 0,
            ]);
            return ResponseFactory::json(['data' => $participant], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'add_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function removeSubscriptionParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        try {
            $this->subscriptionService->removeParticipant($args['participantId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Participant not found' ? 404 : 500;
            $error = $e->getMessage() === 'Participant not found' ? 'participant_not_found' : 'remove_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function updateSubscriptionParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $body = $request->getParsedBody();

        $hasPaid = isset($body['hasPaid']) ? (bool) $body['hasPaid'] : null;

        if ($hasPaid === null) {
            return ResponseFactory::json(['error' => 'hasPaid_required'], 400, $response);
        }

        try {
            $participant = $this->subscriptionService->updateParticipant($args['participantId'], [
                'hasPaid' => $hasPaid ? 1 : 0,
            ]);
            return ResponseFactory::json(['data' => $participant], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Participant not found' ? 404 : 500;
            $error = $e->getMessage() === 'Participant not found' ? 'participant_not_found' : 'update_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function subscriptionDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $subscription = $this->subscriptionRepo->findById($args['id']);

        if ($subscription === null) {
            $response->getBody()->write('Abo nicht gefunden');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $participants = $this->subscriptionRepo->findParticipants($args['id']);

        $participantRows = '';
        foreach ($participants as $p) {
            $name = $p['userName'] ?? $p['userId'] ?? 'Unbekannt';
            if (!empty($p['userDisplayName'])) {
                $name = htmlspecialchars($p['userDisplayName']);
            }
            $paidBadge = $p['hasPaid']
                ? '<span class="badge badge-success">Bezahlt</span>'
                : '<span class="badge badge-danger">Offen</span>';
            $userBadge = $p['isUser'] ? '<span class="badge badge-admin">Nutzer</span>' : '';
            $hasPaidBool = $p['hasPaid'] ? 'true' : 'false';
            $participantRows .= <<<ROW
            <tr>
                <td>{$name} {$userBadge}</td>
                <td>{$paidBadge}</td>
                <td>
                    <button class="btn btn-sm" onclick="togglePaidStatus('{$p['id']}', {$hasPaidBool})">Status ändern</button>
                    <button class="btn btn-sm btn-danger" onclick="removeParticipant('{$args['id']}', '{$p['id']}')">Entfernen</button>
                </td>
            </tr>
ROW;
        }

        $start = date('d.m.Y', strtotime($subscription['billingPeriodStart']));
        $end = date('d.m.Y', strtotime($subscription['billingPeriodEnd']));

        $contentHtml = $this->renderTemplate('subscription_detail.php', [
            'subscriptionId' => $args['id'],
            'subscriptionName' => htmlspecialchars($subscription['name']),
            'billingPeriodStart' => $start,
            'billingPeriodEnd' => $end,
            'basePrice' => number_format($subscription['basePrice'], 2, ',', '.'),
            'participantRows' => $participantRows,
        ]);
        $html = $this->renderLayout('Abo-Details', $contentHtml, $user->email);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function renderLayout(string $title, string $content, string $userEmail): string
    {
        $layout = file_get_contents(__DIR__ . '/../../templates/admin/layout.php') ?: '';
        return strtr($layout, [
            '{{title}}' => htmlspecialchars($title),
            '{{content}}' => $content,
            '{{userEmail}}' => htmlspecialchars($userEmail),
        ]);
    }

    private function renderTemplate(string $template, array $vars): string
    {
        $path = __DIR__ . '/../../templates/admin/' . $template;
        $html = file_get_contents($path) ?: '';
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }
        return strtr($html, $replacements);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
