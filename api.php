<?php
declare(strict_types=1);

ini_set('session.gc_maxlifetime', (string)(30 * 24 * 3600));
session_set_cookie_params(['lifetime' => 30 * 24 * 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DB_HOST = 'localhost';
const DB_NAME = 'u3444889_default';
const DB_USER = 'u3444889_default';
const DB_PASS = 'WToerYyz3760lHUT';
const GENERAL_SUBJECT = 'Генеральная прокуратура';
const DEFAULT_ADMIN_LOGIN = 'admin';
const MAINTENANCE_DANGER_ACTION_PASSWORD = 'e7c3b9a1f0d2e8c4b6a5d7e9f1c3b8a2';
const TESTER_SEED_FULL_NAME = 'Тест помощник прокурора';
const TESTER_SEED_AUDIT_MARKER = 'Служебный флаг тестера выдан: Тест помощник прокурора';
const PILOT_SUBJECTS = ['Рублёвка', 'Арбат', 'Патрики', 'Тверской', 'Кутузовский'];

const STATE_KEYS = [
    'users',
    'registrationRequests',
    'reports',
    'bonuses',
    'activityEvents',
    'factions',
    'performanceReviews',
    'activitySettings',
    'positions',
    'classRanks',
    'medals',
    'criteria',
    'bonusSettings',
    'auditLog',
];

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now_iso(): string
{
    return gmdate('c');
}

function normalize_iso_datetime_string($value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return '';
    }

    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        return '';
    }

    return gmdate('c', $timestamp);
}

function normalize_login(string $login): string
{
    $trimmed = trim($login);
    return function_exists('mb_strtolower') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
}

function split_full_name(string $fullName): array
{
    $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
    $parts = array_values(array_filter($parts, static fn($part) => $part !== ''));
    if (count($parts) === 0) {
        return ['surname' => '', 'name' => ''];
    }

    return [
        'surname' => $parts[0],
        'name' => implode(' ', array_slice($parts, 1)) ?: $parts[0],
    ];
}

function role_labels(): array
{
    return [
        'STAFF' => 'Сотрудник',
        'BOSS' => 'Руководитель субъекта',
        'FEDERAL' => 'Федеральный сотрудник',
        'ADMIN' => 'Администратор',
    ];
}

function allowed_state_keys(): array
{
    return STATE_KEYS;
}

function default_bonus_settings(): array
{
    return [
        'baseAmount' => 50000,
        'maxMultiplier' => 3.0,
        'approvalRequired' => true,
        'payPeriod' => 'biweekly',
        'reportDeadlineDay' => 25,
        'reportDeadlineTime' => '23:59',
        'maintenanceEnabled' => false,
        'maintenanceGif' => 'download.gif',
        'maintenanceScheduledAt' => '',
        'maintenanceAnnouncement' => '',
        'maintenanceActivatedAt' => '',
    ];
}

function default_factions(): array
{
    return [
        ['id' => 'faction_government', 'name' => 'Правительство', 'active' => true],
        ['id' => 'faction_mia_cao', 'name' => 'УВД по ЦАО', 'active' => true],
        ['id' => 'faction_gibdd', 'name' => 'Управление ГИБДД', 'active' => true],
        ['id' => 'faction_rosgvard', 'name' => 'УФСВНГ', 'active' => true],
        ['id' => 'faction_court', 'name' => 'ВС РФ', 'active' => true],
        ['id' => 'faction_fsin', 'name' => 'ФСИН', 'active' => true],
        ['id' => 'faction_hospital3', 'name' => 'ЦГБ №3', 'active' => true],
        ['id' => 'faction_hospital7', 'name' => 'ЦГБ №7', 'active' => true],
        ['id' => 'faction_media', 'name' => 'ВГТРК "Москва-Live"', 'active' => true],
        ['id' => 'faction_fsb', 'name' => 'ФСБ', 'active' => true],
        ['id' => 'faction_codd', 'name' => 'ГКУ "ЦОДД"', 'active' => true],
    ];
}

function normalize_factions($factions, ?array $fallback = null): array
{
    $source = is_array($factions) ? $factions : (is_array($fallback) ? $fallback : default_factions());
    $result = [];
    foreach ($source as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string)($item['name'] ?? $item['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $result[] = [
            'id' => trim((string)($item['id'] ?? sprintf('faction_%d', $index + 1))) ?: sprintf('faction_%d', $index + 1),
            'name' => $name,
            'active' => array_key_exists('active', $item) ? (bool)$item['active'] : true,
        ];
    }
    return $result;
}

function default_activity_settings(): array
{
    return [
        'pilotSubjects' => PILOT_SUBJECTS,
        'eventTypes' => [
            ['id' => 'detention', 'label' => 'Задержание госслужащего', 'kind' => 'person'],
            ['id' => 'decision', 'label' => 'Вынесение решения в отношении госслужащего', 'kind' => 'person'],
            ['id' => 'fine', 'label' => 'Назначение штрафа госслужащему', 'kind' => 'person'],
            ['id' => 'warning', 'label' => 'Назначение предупреждения госслужащему', 'kind' => 'person'],
            ['id' => 'disciplinary', 'label' => 'Назначение дисциплинарного взыскания', 'kind' => 'person'],
            ['id' => 'official_visit', 'label' => 'Официальный визит / лекция / мероприятие', 'kind' => 'visit'],
        ],
        'requireEvidenceLinks' => true,
    ];
}

function normalize_activity_settings($settings, ?array $fallback = null): array
{
    $defaults = is_array($fallback) ? array_merge(default_activity_settings(), $fallback) : default_activity_settings();
    $source = is_array($settings) ? $settings : [];
    $sourcePilotSubjects = is_array($source['pilotSubjects'] ?? null) ? $source['pilotSubjects'] : [];
    $pilotSubjects = array_values(array_filter(array_unique(array_merge(
        array_map(static fn($value) => trim((string)$value), $defaults['pilotSubjects']),
        array_map(static fn($value) => trim((string)$value), $sourcePilotSubjects)
    )), static fn($value) => $value !== ''));

    $rawEventTypes = is_array($source['eventTypes'] ?? null) && count($source['eventTypes']) > 0
        ? $source['eventTypes']
        : $defaults['eventTypes'];
    $eventTypes = [];
    foreach ($rawEventTypes as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string)($item['id'] ?? sprintf('event_type_%d', $index + 1)));
        $label = trim((string)($item['label'] ?? ''));
        $kind = trim((string)($item['kind'] ?? 'person'));
        if ($id === '' || $label === '') {
            continue;
        }
        $eventTypes[] = [
            'id' => $id,
            'label' => $label,
            'kind' => $kind === 'visit' ? 'visit' : 'person',
        ];
    }

    return [
        'pilotSubjects' => count($pilotSubjects) > 0 ? $pilotSubjects : $defaults['pilotSubjects'],
        'eventTypes' => count($eventTypes) > 0 ? $eventTypes : $defaults['eventTypes'],
        'requireEvidenceLinks' => array_key_exists('requireEvidenceLinks', $source)
            ? (bool)$source['requireEvidenceLinks']
            : (bool)$defaults['requireEvidenceLinks'],
    ];
}

function normalize_bonus_settings(array $settings, ?array $fallback = null): array
{
    $defaults = array_merge(default_bonus_settings(), is_array($fallback) ? $fallback : []);

    $deadlineDay = (int)($settings['reportDeadlineDay'] ?? $defaults['reportDeadlineDay']);
    if ($deadlineDay < 1 || $deadlineDay > 31) {
        $deadlineDay = (int)$defaults['reportDeadlineDay'];
    }

    $deadlineTime = trim((string)($settings['reportDeadlineTime'] ?? $defaults['reportDeadlineTime']));
    if (!preg_match('/^\d{2}:\d{2}$/', $deadlineTime)) {
        $deadlineTime = (string)$defaults['reportDeadlineTime'];
    }

    $maintenanceEnabled = (bool)($settings['maintenanceEnabled'] ?? $defaults['maintenanceEnabled']);
    $maintenanceGif = trim((string)($settings['maintenanceGif'] ?? $defaults['maintenanceGif']));
    $maintenanceScheduledAt = normalize_iso_datetime_string($settings['maintenanceScheduledAt'] ?? '');
    $maintenanceActivatedAt = normalize_iso_datetime_string($settings['maintenanceActivatedAt'] ?? '');

    if ($maintenanceEnabled) {
        $maintenanceScheduledAt = '';
        if ($maintenanceActivatedAt === '') {
            $maintenanceActivatedAt = now_iso();
        }
    } else {
        $maintenanceActivatedAt = '';
    }

    return [
        'baseAmount' => (float)($settings['baseAmount'] ?? $defaults['baseAmount']),
        'maxMultiplier' => (float)($settings['maxMultiplier'] ?? $defaults['maxMultiplier']),
        'approvalRequired' => array_key_exists('approvalRequired', $settings) ? (bool)$settings['approvalRequired'] : (bool)$defaults['approvalRequired'],
        'payPeriod' => trim((string)($settings['payPeriod'] ?? $defaults['payPeriod'])) ?: (string)$defaults['payPeriod'],
        'reportDeadlineDay' => $deadlineDay,
        'reportDeadlineTime' => $deadlineTime,
        'maintenanceEnabled' => $maintenanceEnabled,
        'maintenanceGif' => $maintenanceGif !== '' ? $maintenanceGif : (string)$defaults['maintenanceGif'],
        'maintenanceScheduledAt' => $maintenanceScheduledAt,
        'maintenanceAnnouncement' => trim((string)($settings['maintenanceAnnouncement'] ?? '')),
        'maintenanceActivatedAt' => $maintenanceActivatedAt,
    ];
}

function create_default_state(): array
{
    return [
        'users' => [[
            'id' => 'admin1',
            'login' => DEFAULT_ADMIN_LOGIN,
            'password' => DEFAULT_ADMIN_LOGIN,
            'name' => 'Система',
            'surname' => 'Администратор',
            'subject' => GENERAL_SUBJECT,
            'role' => 'FEDERAL',
            'isSystemAdmin' => true,
            'blocked' => false,
            'createdAt' => now_iso(),
            'positionId' => '',
            'classRankId' => '',
            'medalIds' => [],
        ]],
        'registrationRequests' => [],
        'reports' => [],
        'bonuses' => [],
        'activityEvents' => [],
        'factions' => default_factions(),
        'performanceReviews' => [],
        'activitySettings' => default_activity_settings(),
        'positions' => [
            'STAFF' => [
                ['id' => 's1', 'title' => 'Помощник прокурора'],
                ['id' => 's2', 'title' => 'Младший советник юстиции'],
                ['id' => 's3', 'title' => 'Советник юстиции'],
            ],
            'BOSS' => [
                ['id' => 'b1', 'title' => 'Прокурор субъекта'],
                ['id' => 'b2', 'title' => 'Заместитель прокурора субъекта'],
                ['id' => 'b3', 'title' => 'Старший прокурор субъекта'],
            ],
            'FEDERAL' => [
                ['id' => 'f1', 'title' => 'Генеральный прокурор'],
                ['id' => 'f2', 'title' => 'Заместитель Генерального прокурора'],
                ['id' => 'f3', 'title' => 'Старший советник Генеральной прокуратуры'],
            ],
        ],
        'classRanks' => [
            ['id' => 'cr1', 'title' => 'Юрист 3 класса'],
            ['id' => 'cr2', 'title' => 'Юрист 2 класса'],
            ['id' => 'cr3', 'title' => 'Юрист 1 класса'],
            ['id' => 'cr4', 'title' => 'Младший советник юстиции'],
            ['id' => 'cr5', 'title' => 'Советник юстиции'],
            ['id' => 'cr6', 'title' => 'Старший советник юстиции'],
        ],
        'medals' => [
            ['id' => 'med1', 'title' => 'За безупречную службу'],
            ['id' => 'med2', 'title' => 'За укрепление законности'],
            ['id' => 'med3', 'title' => 'Почётный работник прокуратуры'],
            ['id' => 'med4', 'title' => 'За взаимодействие и служебную дисциплину'],
        ],
        'criteria' => [
            ['id' => 'c1', 'name' => 'Проверки ЮЛ и ИП', 'weight' => 15, 'description' => 'Проведение надзорных проверок юридических лиц'],
            ['id' => 'c2', 'name' => 'Рассмотрение обращений граждан', 'weight' => 20, 'description' => 'Работа с жалобами и заявлениями'],
            ['id' => 'c3', 'name' => 'Участие в судебных заседаниях', 'weight' => 25, 'description' => 'Поддержание обвинения в суде'],
            ['id' => 'c4', 'name' => 'Надзор за ОРД', 'weight' => 20, 'description' => 'Надзор за оперативно-розыскной деятельностью'],
            ['id' => 'c5', 'name' => 'Работа с документацией', 'weight' => 10, 'description' => 'Подготовка процессуальных документов'],
            ['id' => 'c6', 'name' => 'Дисциплина и посещаемость', 'weight' => 10, 'description' => 'Соблюдение служебного распорядка'],
        ],
        'bonusSettings' => default_bonus_settings(),
        'auditLog' => [],
    ];
}

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_state (
            state_key VARCHAR(64) NOT NULL PRIMARY KEY,
            state_value LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
}

function save_state_key(PDO $pdo, string $key, $value): void
{
    if (!in_array($key, allowed_state_keys(), true)) {
        throw new RuntimeException('Недопустимый ключ состояния');
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать данные');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_state (state_key, state_value)
         VALUES (:state_key, :state_value)
         ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':state_key' => $key,
        ':state_value' => $json,
    ]);
}

function seed_state(PDO $pdo): bool
{
    ensure_schema($pdo);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM app_state')->fetchColumn();
    $freshlySeeded = $count === 0;
    $defaults = create_default_state();

    foreach ($defaults as $key => $value) {
        if ($freshlySeeded) {
            if ($key === 'bonusSettings' && is_array($value)) {
                $value = normalize_bonus_settings($value, is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : null);
            }

            save_state_key($pdo, $key, $value);
            $state[$key] = $value;
            continue;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM app_state WHERE state_key = :state_key LIMIT 1');
        $stmt->execute([':state_key' => $key]);
        if (!$stmt->fetchColumn()) {
            save_state_key($pdo, $key, $value);
        }
    }

    return $freshlySeeded;
}

function load_state(PDO $pdo): array
{
    $defaults = create_default_state();
    $rows = $pdo->query('SELECT state_key, state_value FROM app_state')->fetchAll();
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[$row['state_key']] = $row['state_value'];
    }

    $state = [];
    foreach ($defaults as $key => $fallback) {
        if (!array_key_exists($key, $indexed)) {
            $state[$key] = $fallback;
            save_state_key($pdo, $key, $fallback);
            continue;
        }

        $decoded = json_decode((string)$indexed[$key], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $fallback;
            save_state_key($pdo, $key, $fallback);
        }

        if ($key === 'bonusSettings') {
            $normalized = normalize_bonus_settings(is_array($decoded) ? $decoded : [], is_array($fallback) ? $fallback : null);
            if ($normalized !== $decoded) {
                save_state_key($pdo, $key, $normalized);
            }
            $decoded = $normalized;
        } elseif ($key === 'factions') {
            $normalized = normalize_factions($decoded, is_array($fallback) ? $fallback : null);
            if ($normalized !== $decoded) {
                save_state_key($pdo, $key, $normalized);
            }
            $decoded = $normalized;
        } elseif ($key === 'activitySettings') {
            $normalized = normalize_activity_settings($decoded, is_array($fallback) ? $fallback : null);
            if ($normalized !== $decoded) {
                save_state_key($pdo, $key, $normalized);
            }
            $decoded = $normalized;
        }

        $state[$key] = $decoded;
    }

    return $state;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, ['ok' => false, 'error' => 'Ожидался JSON-объект']);
    }

    return $decoded;
}

function find_position_meta(array $positions, string $positionId): ?array
{
    foreach ($positions as $role => $items) {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $positionId) {
                return [
                    'role' => $role,
                    'title' => $item['title'] ?? '',
                ];
            }
        }
    }

    return null;
}

function is_login_busy(array $users, array $requests, string $login, ?string $excludeRequestId = null): bool
{
    $normalized = normalize_login($login);
    if ($normalized === '') {
        return false;
    }

    foreach ($users as $user) {
        if (normalize_login((string)($user['login'] ?? '')) === $normalized) {
            return true;
        }
    }

    foreach ($requests as $request) {
        if (($request['id'] ?? null) === $excludeRequestId) {
            continue;
        }

        if (($request['status'] ?? null) !== 'pending') {
            continue;
        }

        if (normalize_login((string)($request['login'] ?? '')) === $normalized) {
            return true;
        }
    }

    return false;
}

function sanitize_user_for_client(array $user): array
{
    $copy = $user;
    unset($copy['password']);
    return $copy;
}

function sanitize_state_for_client(array $state): array
{
    $copy = $state;
    $copy['users'] = array_map('sanitize_user_for_client', $state['users'] ?? []);
    $copy['bonusSettings'] = normalize_bonus_settings(is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : []);
    $copy['factions'] = normalize_factions($state['factions'] ?? []);
    $copy['activitySettings'] = normalize_activity_settings($state['activitySettings'] ?? []);
    return $copy;
}

function sanitize_public_state_for_client(array $state): array
{
    return [
        'users' => [],
        'registrationRequests' => [],
        'reports' => [],
        'bonuses' => [],
        'activityEvents' => [],
        'factions' => [],
        'performanceReviews' => [],
        'activitySettings' => [],
        'positions' => $state['positions'] ?? [],
        'classRanks' => [],
        'medals' => [],
        'criteria' => [],
        'bonusSettings' => normalize_bonus_settings(is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : []),
        'auditLog' => [],
    ];
}

function current_user(array $state): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    foreach ($state['users'] ?? [] as $user) {
        if (($user['id'] ?? null) === $userId && !($user['blocked'] ?? false)) {
            return $user;
        }
    }

    unset($_SESSION['user_id']);
    return null;
}

function find_registration_request_by_id(array $requests, string $requestId): ?array
{
    foreach ($requests as $request) {
        if (($request['id'] ?? null) === $requestId) {
            return $request;
        }
    }

    return null;
}

function find_user_by_id(array $users, string $userId): ?array
{
    foreach ($users as $user) {
        if (($user['id'] ?? null) === $userId) {
            return $user;
        }
    }

    return null;
}

function find_user_by_login(array $users, string $login): ?array
{
    $normalizedLogin = normalize_login($login);
    if ($normalizedLogin === '') {
        return null;
    }

    foreach ($users as $user) {
        if (
            normalize_login((string)($user['login'] ?? '')) === $normalizedLogin
            && !($user['blocked'] ?? false)
        ) {
            return $user;
        }
    }

    return null;
}

function build_pending_registration_user(array $request): array
{
    return [
        'id' => 'pending_registration_' . (string)($request['id'] ?? ''),
        'login' => (string)($request['login'] ?? ''),
        'name' => (string)($request['name'] ?? ''),
        'surname' => (string)($request['surname'] ?? ''),
        'role' => (string)($request['requestedRole'] ?? 'STAFF'),
        'subject' => (string)($request['requestedSubject'] ?? ''),
        'positionId' => (string)($request['requestedPositionId'] ?? ''),
        'blocked' => false,
        'isSystemAdmin' => false,
        'isTester' => false,
        'classRankId' => '',
        'medalIds' => [],
        'createdAt' => (string)($request['createdAt'] ?? now_iso()),
        'isPendingApproval' => true,
        'pendingRequestId' => (string)($request['id'] ?? ''),
        'pendingRequestCreatedAt' => (string)($request['createdAt'] ?? now_iso()),
    ];
}

function current_user_or_pending(array $state): ?array
{
    $user = current_user($state);
    if ($user) {
        return $user;
    }

    $pendingRequestId = trim((string)($_SESSION['pending_registration_request_id'] ?? ''));
    if ($pendingRequestId === '') {
        return null;
    }

    $request = find_registration_request_by_id($state['registrationRequests'] ?? [], $pendingRequestId);
    if (!$request) {
        unset($_SESSION['pending_registration_request_id']);
        return null;
    }

    $status = (string)($request['status'] ?? 'pending');
    if ($status === 'pending') {
        return build_pending_registration_user($request);
    }

    unset($_SESSION['pending_registration_request_id']);
    if ($status !== 'approved') {
        return null;
    }

    $approvedUser = find_user_by_login($state['users'] ?? [], (string)($request['login'] ?? ''));
    if (!$approvedUser) {
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $approvedUser['id'];
    return $approvedUser;
}

function has_system_admin_access(array $user): bool
{
    return (bool)($user['isSystemAdmin'] ?? false) || (($user['role'] ?? null) === 'ADMIN');
}

function require_auth(array $state): array
{
    $user = current_user($state);
    if (!$user) {
        respond(401, ['ok' => false, 'error' => 'Требуется вход в систему']);
    }

    return $user;
}

function can_access_user_record(array $requester, array $targetUser): bool
{
    $role = $requester['role'] ?? null;
    $targetIsSystemAdmin = has_system_admin_access($targetUser);
    if ($targetIsSystemAdmin && !has_system_admin_access($requester)) {
        return ($requester['id'] ?? null) === ($targetUser['id'] ?? null);
    }

    if (has_system_admin_access($requester) || $role === 'FEDERAL') {
        return true;
    }

    if ($role === 'BOSS') {
        return ($requester['subject'] ?? null) === ($targetUser['subject'] ?? null);
    }

    return ($requester['id'] ?? null) === ($targetUser['id'] ?? null);
}

function can_mutate_key(array $user, string $key): bool
{
    $role = $user['role'] ?? null;
    if (has_system_admin_access($user)) {
        return true;
    }

    $map = [
        'STAFF' => ['reports', 'auditLog', 'activityEvents'],
        'BOSS' => ['reports', 'bonuses', 'users', 'registrationRequests', 'auditLog', 'activityEvents', 'performanceReviews'],
        'FEDERAL' => ['reports', 'bonuses', 'users', 'registrationRequests', 'auditLog', 'activityEvents', 'performanceReviews'],
    ];

    return in_array($key, $map[$role] ?? [], true);
}

function get_subject_prosecutor_position_ids(array $state): array
{
    $bossPositions = is_array($state['positions']['BOSS'] ?? null) ? $state['positions']['BOSS'] : [];
    $ids = [];
    foreach ($bossPositions as $position) {
        $positionId = trim((string)($position['id'] ?? ''));
        $title = trim((string)($position['title'] ?? ''));
        if ($positionId === '') {
            continue;
        }
        if ($positionId === 'b1' || $title === 'Прокурор субъекта') {
            $ids[] = $positionId;
        }
    }
    if (!in_array('b1', $ids, true)) {
        $ids[] = 'b1';
    }
    return array_values(array_unique(array_filter($ids)));
}

function is_subject_prosecutor_user(array $user, array $state = []): bool
{
    return (($user['role'] ?? null) === 'BOSS')
        && in_array((string)($user['positionId'] ?? ''), get_subject_prosecutor_position_ids($state), true);
}

function find_bonus_index_by_id(array $bonuses, string $bonusId): int
{
    foreach ($bonuses as $index => $bonus) {
        if (($bonus['id'] ?? null) === $bonusId) {
            return $index;
        }
    }

    return -1;
}

function bonus_requires_subject_payout(array $bonus, array $users, array $state = []): bool
{
    if (($bonus['status'] ?? null) !== 'approved') {
        return false;
    }

    if (($bonus['payoutTracking'] ?? false) === true) {
        return true;
    }

    $subject = trim((string)($bonus['subject'] ?? ''));
    if ($subject === '' || $subject === GENERAL_SUBJECT) {
        return false;
    }

    if (($bonus['source'] ?? null) === 'federal_request') {
        return true;
    }

    $targetUserId = trim((string)($bonus['userId'] ?? ''));
    if ($targetUserId === '') {
        return false;
    }

    $targetUser = find_user_by_id($users, $targetUserId);
    if (!$targetUser) {
        return false;
    }

    if (($targetUser['subject'] ?? null) === GENERAL_SUBJECT) {
        return false;
    }

    if (($targetUser['role'] ?? null) === 'STAFF') {
        return true;
    }

    return (($targetUser['role'] ?? null) === 'BOSS') && !is_subject_prosecutor_user($targetUser, $state);
}

function can_report_bonus_payout(array $actor, array $bonus, array $users, array $state = []): bool
{
    if (!bonus_requires_subject_payout($bonus, $users, $state)) {
        return false;
    }

    $payoutStatus = (string)($bonus['payoutStatus'] ?? '');
    if ($payoutStatus !== 'pending_subject_payout' && $payoutStatus !== 'reported') {
        return false;
    }

    if (has_system_admin_access($actor)) {
        return true;
    }

    if (!is_subject_prosecutor_user($actor, $state)) {
        return false;
    }

    return trim((string)($actor['subject'] ?? '')) !== '' && trim((string)($actor['subject'] ?? '')) === trim((string)($bonus['subject'] ?? ''));
}

function ensure_named_upload_dir(string $subDirectory, string $errorMessage): array
{
    $safeDirectory = trim(str_replace(['..', '\\'], ['', '/'], $subDirectory), '/');
    $relativeDir = 'uploads/' . $safeDirectory;
    $absoluteDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeDirectory);

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException($errorMessage);
    }

    return ['absolute' => $absoluteDir, 'relative' => $relativeDir];
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'checks' . DIRECTORY_SEPARATOR . 'CheckModule.php';

function ensure_bonus_payout_upload_dir(): array
{
    return ensure_named_upload_dir('bonus-payouts', 'Не удалось создать директорию для отчётов о выплате премий');
}

function ensure_evidence_upload_dir(): array
{
    return ensure_named_upload_dir('evidence-proofs', 'Не удалось создать директорию для файлов-доказательств');
}

function resolve_supported_image_extension(array $file): ?string
{
    $allowedMimeMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detectedMime = finfo_file($finfo, (string)($file['tmp_name'] ?? ''));
            if (is_string($detectedMime)) {
                $mimeType = $detectedMime;
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType !== '' && array_key_exists($mimeType, $allowedMimeMap)) {
        return $allowedMimeMap[$mimeType];
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
    if (in_array($extension, $allowedExtensions, true)) {
        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    return null;
}

function merge_users_preserving_passwords(array $existingUsers, array $incomingUsers): array
{
    $passwordById = [];
    foreach ($existingUsers as $user) {
        if (isset($user['id'])) {
            $passwordById[$user['id']] = $user['password'] ?? '';
        }
    }

    foreach ($incomingUsers as $index => $user) {
        if (!is_array($user)) {
            continue;
        }

        $id = $user['id'] ?? null;
        $hasPassword = array_key_exists('password', $user) && trim((string)$user['password']) !== '';
        if (!$hasPassword && $id && array_key_exists($id, $passwordById)) {
            $incomingUsers[$index]['password'] = $passwordById[$id];
        }
    }

    return $incomingUsers;
}

function append_audit(PDO $pdo, array &$state, string $action, ?string $userId): void
{
    $state['auditLog'][] = [
        'action' => $action,
        'date' => now_iso(),
        'userId' => $userId,
    ];
    save_state_key($pdo, 'auditLog', $state['auditLog']);
}

function ensure_tester_seed(PDO $pdo, array &$state): void
{
    $auditLog = is_array($state['auditLog'] ?? null) ? $state['auditLog'] : [];
    foreach ($auditLog as $entry) {
        if (($entry['action'] ?? null) === TESTER_SEED_AUDIT_MARKER) {
            return;
        }
    }

    $users = is_array($state['users'] ?? null) ? $state['users'] : [];
    $matchedIndex = -1;
    $alreadyTester = false;

    foreach ($users as $index => $user) {
        $login = trim((string)($user['login'] ?? ''));
        $fullName = trim(preg_replace('/\s+/u', ' ', trim((string)($user['surname'] ?? '') . ' ' . (string)($user['name'] ?? ''))));
        if ($login === TESTER_SEED_FULL_NAME || $fullName === TESTER_SEED_FULL_NAME) {
            $matchedIndex = $index;
            $alreadyTester = (bool)($user['isTester'] ?? false);
            break;
        }
    }

    if ($matchedIndex < 0) {
        return;
    }

    if (!$alreadyTester) {
        $users[$matchedIndex]['isTester'] = true;
        $state['users'] = $users;
        save_state_key($pdo, 'users', $state['users']);
    }

    append_audit($pdo, $state, TESTER_SEED_AUDIT_MARKER, $users[$matchedIndex]['id'] ?? null);
}

function apply_scheduled_maintenance(PDO $pdo, array &$state): void
{
    $currentSettings = normalize_bonus_settings(is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : []);
    if ($currentSettings !== ($state['bonusSettings'] ?? null)) {
        $state['bonusSettings'] = $currentSettings;
        save_state_key($pdo, 'bonusSettings', $state['bonusSettings']);
    } else {
        $state['bonusSettings'] = $currentSettings;
    }

    if (($state['bonusSettings']['maintenanceEnabled'] ?? false) === true) {
        return;
    }

    $scheduledAt = $state['bonusSettings']['maintenanceScheduledAt'] ?? '';
    if ($scheduledAt === '') {
        return;
    }

    $scheduledTimestamp = strtotime($scheduledAt);
    if ($scheduledTimestamp === false || $scheduledTimestamp > time()) {
        return;
    }

    $state['bonusSettings']['maintenanceEnabled'] = true;
    $state['bonusSettings']['maintenanceActivatedAt'] = now_iso();
    $state['bonusSettings']['maintenanceScheduledAt'] = '';
    save_state_key($pdo, 'bonusSettings', $state['bonusSettings']);

    $auditSuffix = trim((string)($state['bonusSettings']['maintenanceAnnouncement'] ?? ''));
    append_audit(
        $pdo,
        $state,
        $auditSuffix !== ''
            ? sprintf('Автозапуск техработ по расписанию: %s', $auditSuffix)
            : 'Автозапуск техработ по расписанию',
        null
    );
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function http_fetch_text(string $url, int $timeout = 10): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FemidaTelegramBot/1.0; +https://prosecutors-office-rmrp.ru/)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ru,en;q=0.9'],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'Удалённый источник недоступен');
        }

        return (string)$body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "User-Agent: Mozilla/5.0 (compatible; FemidaTelegramBot/1.0; +https://prosecutors-office-rmrp.ru/)\r\nAccept-Language: ru,en;q=0.9\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Не удалось получить данные Telegram');
    }

    return (string)$body;
}

function collapse_whitespace(string $value): string
{
    $collapsed = preg_replace('/\s+/u', ' ', trim($value));
    return is_string($collapsed) ? $collapsed : trim($value);
}

function truncate_utf8(string $text, int $length): string
{
    $clean = collapse_whitespace(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($clean, 0, $length, '…', 'UTF-8');
    }

    if (strlen($clean) <= $length) {
        return $clean;
    }

    return substr($clean, 0, max(0, $length - 3)) . '...';
}

function build_telegram_post_payload(string $channel, int $id, string $text = '', ?string $date = null, ?string $imageUrl = null): array
{
    $safeText = collapse_whitespace($text);
    $title = truncate_utf8($safeText !== '' ? $safeText : sprintf('Публикация канала %s', $channel), 90);
    $excerpt = truncate_utf8($safeText !== '' ? $safeText : sprintf('Откройте пост %d в Telegram.', $id), 240);

    return [
        'id' => $id,
        'url' => sprintf('https://t.me/%s/%d', $channel, $id),
        'title' => $title,
        'excerpt' => $excerpt,
        'text' => $safeText,
        'date' => $date,
        'imageUrl' => $imageUrl,
    ];
}

function get_telegram_channel_posts(string $channel, int $limit = 3): array
{
    if (!preg_match('/^[A-Za-z0-9_]{3,}$/', $channel)) {
        throw new RuntimeException('Некорректный канал Telegram');
    }

    $safeLimit = max(1, min(6, $limit));
    $html = http_fetch_text(sprintf('https://t.me/s/%s', rawurlencode($channel)));
    $posts = [];

    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[@data-post]');
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $dataPost = (string)$node->getAttribute('data-post');
                if (strpos($dataPost, $channel . '/') !== 0) {
                    continue;
                }

                $id = (int)substr($dataPost, strlen($channel) + 1);
                if ($id <= 0 || isset($posts[$id])) {
                    continue;
                }

                $textNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ')]", $node)->item(0);
                $timeNode = $xpath->query('.//time[@datetime]', $node)->item(0);
                $photoNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_photo_wrap ')]", $node)->item(0);

                $text = $textNode ? trim((string)$textNode->textContent) : '';
                $date = $timeNode instanceof DOMElement ? trim((string)$timeNode->getAttribute('datetime')) : null;
                $imageUrl = null;
                if ($photoNode instanceof DOMElement) {
                    $style = (string)$photoNode->getAttribute('style');
                    if (preg_match("/url\\('([^']+)'\\)/", $style, $styleMatch)) {
                        $imageUrl = html_entity_decode($styleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }

                $posts[$id] = build_telegram_post_payload($channel, $id, $text, $date, $imageUrl);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if (count($posts) === 0) {
        preg_match_all(sprintf('/data-post="%s\/(\d+)"/', preg_quote($channel, '/')), $html, $matches);
        $ids = array_values(array_unique($matches[1] ?? []));
        if (count($ids) === 0) {
            preg_match_all(sprintf('#https://t\.me/%s/(\d+)#', preg_quote($channel, '#')), $html, $fallbackMatches);
            $ids = array_values(array_unique($fallbackMatches[1] ?? []));
        }

        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            if ($id <= 0 || isset($posts[$id])) {
                continue;
            }
            $posts[$id] = build_telegram_post_payload($channel, $id);
        }
    }

    $items = array_values($posts);
    usort($items, static fn(array $left, array $right) => ($right['id'] ?? 0) <=> ($left['id'] ?? 0));
    return array_slice($items, 0, $safeLimit);
}

try {
    $pdo = pdo();
    $freshlySeeded = seed_state($pdo);
    $state = load_state($pdo);
    ensure_tester_seed($pdo, $state);
    apply_scheduled_maintenance($pdo, $state);
    checks_ensure_schema($pdo);
    checks_seed_settings($pdo);
    $action = $_GET['action'] ?? 'bootstrap';

    if ($action === 'health') {
        respond(200, [
            'ok' => true,
            'storage' => 'mysql',
            'database' => DB_NAME,
        ]);
    }

    if ($action === 'telegram-feed') {
        $channel = trim((string)($_GET['channel'] ?? 'rmrpzakon'));
        $limit = (int)($_GET['limit'] ?? 3);
        $posts = get_telegram_channel_posts($channel, $limit);
        respond(200, [
            'ok' => true,
            'channel' => $channel,
            'channelUrl' => sprintf('https://t.me/%s', $channel),
            'posts' => $posts,
        ]);
    }

    if (cases_handle_action($pdo, (string)$action, $state)) {
        exit;
    }

    if (checks_handle_action($pdo, (string)$action, $state)) {
        exit;
    }

    if ($action === 'bootstrap') {
        $user = current_user_or_pending($state);
        $checksMeta = checks_build_bootstrap_meta($pdo, $state, $user);
        $casesMeta = cases_build_bootstrap_meta($pdo, $state, $user);
        respond(200, [
            'ok' => true,
            'authenticated' => (bool)$user,
            'currentUser' => $user ? sanitize_user_for_client($user) : null,
            'state' => $user && !($user['isPendingApproval'] ?? false)
                ? sanitize_state_for_client($state)
                : sanitize_public_state_for_client($state),
            'checksMeta' => $checksMeta,
            'casesMeta' => $casesMeta,
            'meta' => [
                'freshlySeeded' => $freshlySeeded,
                'storage' => 'mysql',
            ],
        ]);
    }

    if ($action === 'login') {
        $body = read_json_body();
        $login = (string)($body['login'] ?? '');
        $password = (string)($body['password'] ?? '');
        $normalizedLogin = normalize_login($login);

        if ($normalizedLogin === '' || $password === '') {
            respond(422, ['ok' => false, 'error' => 'Укажите логин и пароль']);
        }

        $matchedUser = null;
        foreach ($state['users'] as $user) {
            if (normalize_login((string)($user['login'] ?? '')) === $normalizedLogin && (string)($user['password'] ?? '') === $password) {
                $matchedUser = $user;
                break;
            }
        }

        if (!$matchedUser) {
            respond(401, ['ok' => false, 'error' => 'Неверный логин или пароль']);
        }

        if ($matchedUser['blocked'] ?? false) {
            respond(403, ['ok' => false, 'error' => 'Аккаунт заблокирован. Обратитесь к администрации.']);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $matchedUser['id'];
        unset($_SESSION['pending_registration_request_id']);
        append_audit($pdo, $state, sprintf('Вход: %s %s (%s)', $matchedUser['surname'] ?? '', $matchedUser['name'] ?? '', $matchedUser['role'] ?? ''), $matchedUser['id'] ?? null);

        $checksMeta = checks_build_bootstrap_meta($pdo, $state, $matchedUser);
        $casesMeta = cases_build_bootstrap_meta($pdo, $state, $matchedUser);

        respond(200, [
            'ok' => true,
            'currentUser' => sanitize_user_for_client($matchedUser),
            'state' => sanitize_state_for_client($state),
            'checksMeta' => $checksMeta,
            'casesMeta' => $casesMeta,
            'meta' => [
                'freshlySeeded' => $freshlySeeded,
                'storage' => 'mysql',
            ],
        ]);
    }

    if ($action === 'logout') {
        $user = current_user($state);
        if ($user) {
            append_audit($pdo, $state, sprintf('Выход: %s %s', $user['surname'] ?? '', $user['name'] ?? ''), $user['id'] ?? null);
        }
        destroy_session();
        respond(200, ['ok' => true]);
    }

    if ($action === 'verify-maintenance-action-password') {
        $user = require_auth($state);
        if (!has_system_admin_access($user)) {
            respond(403, ['ok' => false, 'error' => 'Недостаточно прав для выполнения служебного действия']);
        }

        $body = read_json_body();
        $password = trim((string)($body['password'] ?? ''));
        if ($password === '' || !hash_equals(MAINTENANCE_DANGER_ACTION_PASSWORD, $password)) {
            respond(403, ['ok' => false, 'error' => 'Неверный служебный пароль']);
        }

        respond(200, ['ok' => true]);
    }

    if ($action === 'user-password') {
        $user = require_auth($state);
        $body = read_json_body();
        $targetUserId = trim((string)($body['userId'] ?? ''));

        if ($targetUserId === '') {
            respond(422, ['ok' => false, 'error' => 'Не указан пользователь']);
        }

        $targetUser = find_user_by_id($state['users'], $targetUserId);
        if (!$targetUser) {
            respond(404, ['ok' => false, 'error' => 'Пользователь не найден']);
        }

        if (!can_access_user_record($user, $targetUser)) {
            respond(403, ['ok' => false, 'error' => 'Недостаточно прав для просмотра пароля']);
        }

        append_audit(
            $pdo,
            $state,
            sprintf('Просмотрен пароль пользователя: %s %s', $targetUser['surname'] ?? '', $targetUser['name'] ?? ''),
            $user['id'] ?? null
        );

        respond(200, [
            'ok' => true,
            'userId' => $targetUser['id'],
            'password' => (string)($targetUser['password'] ?? ''),
        ]);
    }

    if ($action === 'submit-registration') {
        $body = read_json_body();
        $fullName = trim((string)($body['login'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $subject = trim((string)($body['subject'] ?? ''));
        $positionId = trim((string)($body['positionId'] ?? ''));
        $comment = trim((string)($body['comment'] ?? ''));

        if ($fullName === '' || $password === '' || $subject === '' || $positionId === '') {
            respond(422, ['ok' => false, 'error' => 'Заполните все обязательные поля']);
        }

        $passwordLength = function_exists('mb_strlen') ? mb_strlen(trim($password), 'UTF-8') : strlen(trim($password));
        if ($passwordLength < 4) {
            respond(422, ['ok' => false, 'error' => 'Пароль должен содержать минимум 4 символа']);
        }

        $nameParts = preg_split('/\s+/u', $fullName) ?: [];
        $nameParts = array_values(array_filter($nameParts, static fn($part) => $part !== ''));
        if (count($nameParts) < 2) {
            respond(422, ['ok' => false, 'error' => 'Укажите Фамилию Имя Отчество одной строкой']);
        }

        if (is_login_busy($state['users'], $state['registrationRequests'], $fullName)) {
            respond(409, ['ok' => false, 'error' => 'Логин уже используется в системе или в заявках']);
        }

        $positionMeta = find_position_meta($state['positions'], $positionId);
        if (!$positionMeta) {
            respond(422, ['ok' => false, 'error' => 'Выберите должность из списка']);
        }

        $parsedName = split_full_name($fullName);
        $request = [
            'id' => bin2hex(random_bytes(8)),
            'login' => $fullName,
            'password' => $password,
            'name' => $parsedName['name'],
            'surname' => $parsedName['surname'],
            'requestedRole' => $positionMeta['role'],
            'requestedSubject' => $subject,
            'requestedPositionId' => $positionId,
            'status' => 'pending',
            'comment' => $comment,
            'createdAt' => now_iso(),
            'reviewedAt' => null,
            'reviewedBy' => null,
            'rejectionReason' => '',
        ];

        $state['registrationRequests'][] = $request;
        save_state_key($pdo, 'registrationRequests', $state['registrationRequests']);

        $roleLabel = role_labels()[$request['requestedRole']] ?? $request['requestedRole'];
        append_audit($pdo, $state, sprintf('Подана заявка на регистрацию: %s %s (%s)', $request['surname'], $request['name'], $roleLabel), null);

        session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['pending_registration_request_id'] = $request['id'];

        $pendingUser = build_pending_registration_user($request);
        $checksMeta = checks_build_bootstrap_meta($pdo, $state, $pendingUser);
        $casesMeta = cases_build_bootstrap_meta($pdo, $state, $pendingUser);

        respond(200, [
            'ok' => true,
            'requestId' => $request['id'],
            'currentUser' => sanitize_user_for_client($pendingUser),
            'state' => sanitize_public_state_for_client($state),
            'checksMeta' => $checksMeta,
            'casesMeta' => $casesMeta,
            'meta' => [
                'freshlySeeded' => $freshlySeeded,
                'storage' => 'mysql',
            ],
        ]);
    }

    if ($action === 'change-password') {
        $user = require_auth($state);
        $body = read_json_body();
        $currentPassword = (string)($body['currentPassword'] ?? '');
        $newPassword = (string)($body['newPassword'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            respond(422, ['ok' => false, 'error' => 'Укажите текущий и новый пароль']);
        }

        if (($user['password'] ?? '') !== $currentPassword) {
            respond(403, ['ok' => false, 'error' => 'Текущий пароль указан неверно']);
        }

        $newPasswordLength = function_exists('mb_strlen') ? mb_strlen(trim($newPassword), 'UTF-8') : strlen(trim($newPassword));
        if ($newPasswordLength < 4) {
            respond(422, ['ok' => false, 'error' => 'Новый пароль должен содержать минимум 4 символа']);
        }

        if ($currentPassword === $newPassword) {
            respond(422, ['ok' => false, 'error' => 'Новый пароль должен отличаться от текущего']);
        }

        foreach ($state['users'] as $index => $storedUser) {
            if (($storedUser['id'] ?? null) !== ($user['id'] ?? null)) {
                continue;
            }
            $state['users'][$index]['password'] = $newPassword;
            $user = $state['users'][$index];
            break;
        }

        save_state_key($pdo, 'users', $state['users']);
        append_audit(
            $pdo,
            $state,
            sprintf('Пользователь изменил пароль: %s %s', $user['surname'] ?? '', $user['name'] ?? ''),
            $user['id'] ?? null
        );

        respond(200, [
            'ok' => true,
            'currentUser' => sanitize_user_for_client($user),
        ]);
    }

    if ($action === 'upload-evidence-proof') {
        require_auth($state);

        if (!isset($_FILES['proof']) || !is_array($_FILES['proof'])) {
            respond(422, ['ok' => false, 'error' => 'Приложите скриншот-доказательство']);
        }

        $file = $_FILES['proof'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
                UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
                UPLOAD_ERR_NO_FILE => 'Приложите скриншот-доказательство',
                default => 'Не удалось загрузить файл',
            };
            respond(422, ['ok' => false, 'error' => $message]);
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            respond(422, ['ok' => false, 'error' => 'Файл пустой']);
        }
        if ($fileSize > 10 * 1024 * 1024) {
            respond(422, ['ok' => false, 'error' => 'Максимальный размер файла — 10 МБ']);
        }

        $extension = resolve_supported_image_extension($file);
        if ($extension === null) {
            respond(422, ['ok' => false, 'error' => 'Поддерживаются только PNG, JPG и WEBP']);
        }

        if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            respond(422, ['ok' => false, 'error' => 'Некорректный источник загружаемого файла']);
        }

        $directoryInfo = ensure_evidence_upload_dir();
        $filename = sprintf('evidence_%s.%s', gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)), $extension);
        $targetPath = $directoryInfo['absolute'] . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $directoryInfo['relative'] . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            respond(500, ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере']);
        }

        respond(200, [
            'ok' => true,
            'url' => $relativePath,
            'name' => (string)($file['name'] ?? ''),
        ]);
    }

    if ($action === 'upload-case-file') {
        require_auth($state);

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            respond(422, ['ok' => false, 'error' => 'Приложите файл']);
        }

        $file = $_FILES['file'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
                UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
                UPLOAD_ERR_NO_FILE => 'Приложите файл',
                default => 'Не удалось загрузить файл',
            };
            respond(422, ['ok' => false, 'error' => $message]);
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            respond(422, ['ok' => false, 'error' => 'Файл пустой']);
        }
        if ($fileSize > 10 * 1024 * 1024) {
            respond(422, ['ok' => false, 'error' => 'Максимальный размер файла — 10 МБ']);
        }

        $extension = resolve_supported_image_extension($file);
        if ($extension === null) {
            respond(422, ['ok' => false, 'error' => 'Поддерживаются только PNG, JPG и WEBP']);
        }

        if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            respond(422, ['ok' => false, 'error' => 'Некорректный источник загружаемого файла']);
        }

        $directoryInfo = ensure_named_upload_dir('case-files', 'Не удалось создать директорию для файлов обращений');
        $filename = sprintf('case_%s.%s', gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)), $extension);
        $targetPath = $directoryInfo['absolute'] . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $directoryInfo['relative'] . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            respond(500, ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере']);
        }

        respond(200, [
            'ok' => true,
            'url' => $relativePath,
            'name' => (string)($file['name'] ?? ''),
        ]);
    }

    if ($action === 'upload-bonus-payout-proof') {
        $user = require_auth($state);
        $bonusId = trim((string)($_POST['bonusId'] ?? ''));
        $comment = trim((string)($_POST['comment'] ?? ''));

        if ($bonusId === '') {
            respond(422, ['ok' => false, 'error' => 'Не указана премия для отчёта о выплате']);
        }

        $bonusIndex = find_bonus_index_by_id($state['bonuses'] ?? [], $bonusId);
        if ($bonusIndex < 0) {
            respond(404, ['ok' => false, 'error' => 'Премия не найдена']);
        }

        $bonus = $state['bonuses'][$bonusIndex];
        if (!can_report_bonus_payout($user, $bonus, $state['users'] ?? [], $state)) {
            respond(403, ['ok' => false, 'error' => 'Недостаточно прав для отчёта о выплате этой премии']);
        }

        if (!isset($_FILES['proof']) || !is_array($_FILES['proof'])) {
            respond(422, ['ok' => false, 'error' => 'Приложите скриншот перевода']);
        }

        $file = $_FILES['proof'];
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
                UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
                UPLOAD_ERR_NO_FILE => 'Приложите скриншот перевода',
                default => 'Не удалось загрузить файл',
            };
            respond(422, ['ok' => false, 'error' => $message]);
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            respond(422, ['ok' => false, 'error' => 'Файл пустой']);
        }
        if ($fileSize > 10 * 1024 * 1024) {
            respond(422, ['ok' => false, 'error' => 'Максимальный размер файла — 10 МБ']);
        }

        $extension = resolve_supported_image_extension($file);
        if ($extension === null) {
            respond(422, ['ok' => false, 'error' => 'Поддерживаются только PNG, JPG и WEBP']);
        }

        if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            respond(422, ['ok' => false, 'error' => 'Некорректный источник загружаемого файла']);
        }

        $directoryInfo = ensure_bonus_payout_upload_dir();
        $safeBonusId = preg_replace('/[^A-Za-z0-9_-]/', '', $bonusId) ?: bin2hex(random_bytes(4));
        $filename = sprintf('bonus_%s_%s.%s', $safeBonusId, gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3)), $extension);
        $targetPath = $directoryInfo['absolute'] . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $directoryInfo['relative'] . '/' . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
            respond(500, ['ok' => false, 'error' => 'Не удалось сохранить файл на сервере']);
        }

        $previousProofUrl = trim((string)($bonus['payoutProofUrl'] ?? ''));
        $state['bonuses'][$bonusIndex]['payoutTracking'] = true;
        $state['bonuses'][$bonusIndex]['payoutStatus'] = 'reported';
        $state['bonuses'][$bonusIndex]['payoutReportedAt'] = now_iso();
        $state['bonuses'][$bonusIndex]['payoutReportedBy'] = $user['id'] ?? null;
        $state['bonuses'][$bonusIndex]['payoutProofUrl'] = $relativePath;
        $state['bonuses'][$bonusIndex]['payoutProofName'] = (string)($file['name'] ?? '');
        $state['bonuses'][$bonusIndex]['payoutComment'] = $comment;

        save_state_key($pdo, 'bonuses', $state['bonuses']);

        if ($previousProofUrl !== '' && str_starts_with($previousProofUrl, $directoryInfo['relative'] . '/')) {
            $previousPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $previousProofUrl);
            if ($previousPath !== $targetPath && is_file($previousPath)) {
                @unlink($previousPath);
            }
        }

        $targetUser = find_user_by_id($state['users'] ?? [], (string)($bonus['userId'] ?? ''));
        append_audit(
            $pdo,
            $state,
            sprintf(
                'Загружен отчёт о выплате премии: %s%s%s • %s',
                $targetUser['surname'] ?? 'Сотрудник',
                isset($targetUser['name']) && $targetUser['name'] !== '' ? ' ' : '',
                $targetUser['name'] ?? '',
                $bonus['subject'] ?? 'Без субъекта'
            ),
            $user['id'] ?? null
        );

        respond(200, [
            'ok' => true,
            'bonus' => $state['bonuses'][$bonusIndex],
        ]);
    }

    if ($action === 'save') {
        $user = require_auth($state);
        $body = read_json_body();
        $key = (string)($body['key'] ?? '');
        $value = $body['value'] ?? null;

        if (!in_array($key, allowed_state_keys(), true)) {
            respond(422, ['ok' => false, 'error' => 'Недопустимый ключ состояния']);
        }

        if (!can_mutate_key($user, $key)) {
            respond(403, ['ok' => false, 'error' => 'Недостаточно прав для изменения этих данных']);
        }

        if ($key === 'users' && is_array($value)) {
            $value = merge_users_preserving_passwords($state['users'], $value);
        }

        if ($key === 'bonusSettings' && is_array($value)) {
            $value = normalize_bonus_settings($value, is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : null);
        }

        if ($key === 'factions') {
            $value = normalize_factions($value, is_array($state['factions'] ?? null) ? $state['factions'] : null);
        }

        if ($key === 'activitySettings') {
            $value = normalize_activity_settings($value, is_array($state['activitySettings'] ?? null) ? $state['activitySettings'] : null);
        }

        save_state_key($pdo, $key, $value);
        $state[$key] = $value;
        if ($key === 'bonusSettings') {
            apply_scheduled_maintenance($pdo, $state);
        }
        respond(200, [
            'ok' => true,
            'savedKey' => $key,
            'savedAt' => now_iso(),
            'value' => $state[$key] ?? $value,
        ]);
    }

    if ($action === 'bulk-save') {
        $user = require_auth($state);
        if (!has_system_admin_access($user)) {
            respond(403, ['ok' => false, 'error' => 'Импорт доступен только администратору']);
        }

        $body = read_json_body();
        $incomingState = $body['state'] ?? null;
        if (!is_array($incomingState)) {
            respond(422, ['ok' => false, 'error' => 'Ожидался объект состояния']);
        }

        foreach (allowed_state_keys() as $key) {
            if (!array_key_exists($key, $incomingState)) {
                continue;
            }

            $value = $incomingState[$key];
            if ($key === 'users' && is_array($value)) {
                $value = merge_users_preserving_passwords($state['users'], $value);
            }

            if ($key === 'bonusSettings' && is_array($value)) {
                $value = normalize_bonus_settings($value, is_array($state['bonusSettings'] ?? null) ? $state['bonusSettings'] : null);
            }

            if ($key === 'factions') {
                $value = normalize_factions($value, is_array($state['factions'] ?? null) ? $state['factions'] : null);
            }

            if ($key === 'activitySettings') {
                $value = normalize_activity_settings($value, is_array($state['activitySettings'] ?? null) ? $state['activitySettings'] : null);
            }

            save_state_key($pdo, $key, $value);
            $state[$key] = $value;
        }

        $state = load_state($pdo);
        apply_scheduled_maintenance($pdo, $state);
        append_audit($pdo, $state, 'Локальные данные импортированы в MySQL', $user['id'] ?? null);

        respond(200, [
            'ok' => true,
            'state' => sanitize_state_for_client($state),
        ]);
    }

    respond(404, ['ok' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    respond(500, [
        'ok' => false,
        'error' => 'Внутренняя ошибка сервера',
        'details' => $e->getMessage(),
    ]);
}

