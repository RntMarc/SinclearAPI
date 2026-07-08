<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\ForumMemberRepository;
use Sinclear\Api\Repository\ForumRepository;
use Sinclear\Api\Repository\TravelEventRepository;
use Sinclear\Api\Repository\TravelTripRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\OtpService;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Services\NotificationService;

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
        private ForumRepository $forumRepo,
        private ForumMemberRepository $forumMemberRepo,
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
                <td>{$name}</td>
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
                <td>{$eName}</td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$eDesc}">{$eDesc}</td>
                <td>{$eTripName}</td>
                <td>{$eStart}</td>
                <td>{$eEnd}</td>
                <td class="flex" style="gap:0.4rem;">
                    <button class="btn btn-sm btn-primary" onclick="editEvent('{$eId}', `{$eName}`, `{$eDesc}`, '{$eTripId}', '{$e['start']}', '{$e['end']}', '{$e['hastickets']}', `{$e['ticket']}`, `{$e['ticketUrl']}`, `{$e['url']}`, `{$e['image']}`, `{$e['organizer']}`, `{$e['address']}")">Bearbeiten</button>
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
