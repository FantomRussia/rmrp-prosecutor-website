<?php

const CHECKS_MODULE_DEFAULT_SETTINGS = [
    'enabled_subjects' => ['Рублёвка', 'Арбат', 'Патрики', 'Тверской', 'Кутузовский'],
    'allow_federal_participants' => true,
    'allow_gp_notes' => true,
];

const CHECKS_STATUS_LABELS = [
    'planned' => 'Запланирована',
    'active' => 'Активна',
    'completed' => 'Завершена',
    'pending_approval' => 'На утверждении',
    'approved' => 'Утверждена',
    'cancelled' => 'Отменена',
];

const CHECKS_FINAL_RATINGS = [
    'unsatisfactory' => 'Неудовлетворительно',
    'satisfactory' => 'Удовлетворительно',
    'good' => 'Хорошо',
    'excellent' => 'Отлично',
];

const CHECKS_ALLOWED_ATTACHMENT_EXTENSIONS = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

const CALENDAR_EVENT_CATEGORIES = [
    'meeting'  => 'Совещание',
    'deadline' => 'Дедлайн',
    'personal' => 'Личное',
    'official' => 'Служебное',
    'news'     => 'Новости',
    'duty'     => 'Дежурство',
    'other'    => 'Другое',
];

function checks_default_settings(): array
{
    return CHECKS_MODULE_DEFAULT_SETTINGS;
}

function checks_now_storage(): string
{
    return (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
}

function checks_default_meta(array $settings): array
{
    return [
        'settings' => $settings,
        'availableForCurrentUser' => false,
        'permissions' => [
            'canCreate' => false,
            'canViewApproved' => false,
            'canAddGpNotes' => false,
            'canAccessModule' => false,
        ],
        'counters' => [
            'owned' => 0,
            'assigned' => 0,
            'approved' => 0,
            'pendingApproval' => 0,
            'unreadNotifications' => 0,
        ],
        'notifications' => [],
        'calendarEvents' => [],
    ];
}

function checks_normalize_settings($settings): array
{
    $source = is_array($settings) ? $settings : [];
    $defaults = checks_default_settings();
    $subjects = array_values(array_filter(array_map(
        static fn($item) => trim((string)$item),
        is_array($source['enabled_subjects'] ?? null) ? $source['enabled_subjects'] : $defaults['enabled_subjects']
    ), static fn($item) => $item !== ''));

    return [
        'enabled_subjects' => count($subjects) > 0 ? array_values(array_unique($subjects)) : $defaults['enabled_subjects'],
        'allow_federal_participants' => array_key_exists('allow_federal_participants', $source)
            ? (bool)$source['allow_federal_participants']
            : (bool)$defaults['allow_federal_participants'],
        'allow_gp_notes' => array_key_exists('allow_gp_notes', $source)
            ? (bool)$source['allow_gp_notes']
            : (bool)$defaults['allow_gp_notes'],
    ];
}

function checks_is_enabled_subject(?string $subject, array $settings): bool
{
    $value = trim((string)$subject);
    if ($value === '') {
        return false;
    }
    return in_array($value, $settings['enabled_subjects'] ?? [], true);
}

function checks_uuid(): string
{
    return bin2hex(random_bytes(16));
}

function checks_json_encode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать данные проверки');
    }
    return $json;
}

function checks_json_decode_array($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function checks_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function checks_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function checks_execute(PDO $pdo, string $sql, array $params = []): void
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function checks_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $row = checks_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS cnt
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name',
        [
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]
    );

    return (int)($row['cnt'] ?? 0) > 0;
}

function checks_full_name(?array $user): string
{
    if (!$user) {
        return 'Неизвестный пользователь';
    }

    $parts = array_filter([
        trim((string)($user['surname'] ?? '')),
        trim((string)($user['name'] ?? '')),
    ], static fn($item) => $item !== '');

    return count($parts) > 0 ? implode(' ', $parts) : trim((string)($user['login'] ?? 'Пользователь'));
}

function checks_get_faction_meta(array $state, string $factionId): ?array
{
    foreach ($state['factions'] ?? [] as $item) {
        if (($item['id'] ?? null) === $factionId) {
            return $item;
        }
    }
    return null;
}

function checks_get_faction_name(array $state, string $factionId): string
{
    return (string)(checks_get_faction_meta($state, $factionId)['name'] ?? 'Фракция');
}

function checks_status_label(string $status): string
{
    return CHECKS_STATUS_LABELS[$status] ?? $status;
}

function checks_final_rating_label(?string $rating): string
{
    return CHECKS_FINAL_RATINGS[$rating ?? ''] ?? '—';
}

function checks_normalize_datetime_output($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : gmdate('c', $timestamp);
}

function checks_datetime_to_storage(?string $value, bool $required = false): ?string
{
    $normalized = normalize_iso_datetime_string((string)$value);
    if ($normalized === '') {
        if ($required) {
            respond(422, ['ok' => false, 'error' => 'Некорректная дата и время']);
        }
        return null;
    }
    return gmdate('Y-m-d H:i:s', strtotime($normalized));
}

function checks_date_to_storage(?string $value): ?string
{
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }
    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        respond(422, ['ok' => false, 'error' => 'Некорректная дата']);
    }
    return gmdate('Y-m-d', $timestamp);
}

function checks_compute_reporting_period(?string $startsAt): array
{
    $timestamp = strtotime((string)$startsAt);
    if ($timestamp === false) {
        return ['from' => null, 'to' => null];
    }

    return [
        'from' => gmdate('Y-m-d', $timestamp - 30 * 24 * 60 * 60),
        'to' => gmdate('Y-m-d', $timestamp),
    ];
}

function checks_event_date_to_storage($value): ?string
{
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }
    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function checks_score_choice_to_value(string $choice): float
{
    return match ($choice) {
        'correct' => 1.0,
        'partial' => 0.5,
        default => 0.0,
    };
}

function checks_grade_from_score(float $score): array
{
    if ($score <= 2.0) {
        return [
            'code' => '2',
            'label' => '2',
            'recommendedConsequence' => 'Увольнение либо дисциплинарное взыскание',
            'excellentResult' => false,
        ];
    }
    if ($score < 4.0) {
        return [
            'code' => '3',
            'label' => '3',
            'recommendedConsequence' => 'Предупреждение',
            'excellentResult' => false,
        ];
    }
    if ($score < 5.0) {
        return [
            'code' => '4',
            'label' => '4',
            'recommendedConsequence' => 'Замечаний не имеется',
            'excellentResult' => false,
        ];
    }
    if ($score < 7.0) {
        return [
            'code' => '5',
            'label' => '5',
            'recommendedConsequence' => 'Высокий уровень знаний, без взысканий',
            'excellentResult' => false,
        ];
    }
    return [
        'code' => '5_plus',
        'label' => '5+',
        'recommendedConsequence' => 'Исключительный результат',
        'excellentResult' => true,
    ];
}

function checks_user_snapshot(?array $user): array
{
    if (!$user) {
        return [
            'id' => null,
            'fullName' => 'Неизвестный пользователь',
            'role' => '',
            'positionId' => '',
            'subject' => '',
            'isSystemAdmin' => false,
        ];
    }

    return [
        'id' => $user['id'] ?? null,
        'fullName' => checks_full_name($user),
        'role' => (string)($user['role'] ?? ''),
        'positionId' => (string)($user['positionId'] ?? ''),
        'subject' => (string)($user['subject'] ?? ''),
        'isSystemAdmin' => has_system_admin_access($user),
    ];
}

function checks_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS checks_module_settings (
            settings_key VARCHAR(64) NOT NULL PRIMARY KEY,
            settings_value LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `checks` (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            subject VARCHAR(128) NOT NULL,
            faction_id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            type_code VARCHAR(64) DEFAULT NULL,
            type_label VARCHAR(191) DEFAULT NULL,
            basis_text TEXT NOT NULL,
            description TEXT DEFAULT NULL,
            notes_text TEXT DEFAULT NULL,
            period_from DATE DEFAULT NULL,
            period_to DATE DEFAULT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME DEFAULT NULL,
            collection_closed_at DATETIME DEFAULT NULL,
            created_by VARCHAR(64) NOT NULL,
            lead_user_id VARCHAR(64) NOT NULL,
            final_rating VARCHAR(32) DEFAULT NULL,
            final_conclusion TEXT DEFAULT NULL,
            resolution_text TEXT DEFAULT NULL,
            approved_snapshot_id VARCHAR(32) DEFAULT NULL,
            approved_by VARCHAR(64) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            cancel_reason TEXT DEFAULT NULL,
            lock_version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_checks_subject_status (subject, status, starts_at),
            INDEX idx_checks_faction_period (faction_id, starts_at),
            INDEX idx_checks_creator (created_by, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_participants (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            participant_role VARCHAR(32) NOT NULL,
            source VARCHAR(32) NOT NULL,
            assigned_by VARCHAR(64) NOT NULL,
            assigned_at DATETIME NOT NULL,
            removed_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_check_participant (check_id, user_id),
            INDEX idx_check_participants_user (user_id, removed_at),
            CONSTRAINT fk_check_participants_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_reports (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            author_user_id VARCHAR(64) NOT NULL,
            section_code VARCHAR(64) NOT NULL,
            section_label VARCHAR(191) NOT NULL,
            circumstances_text MEDIUMTEXT DEFAULT NULL,
            violations_text MEDIUMTEXT DEFAULT NULL,
            staff_actions_text MEDIUMTEXT DEFAULT NULL,
            quantitative_metrics_json LONGTEXT DEFAULT NULL,
            comment_text MEDIUMTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_check_reports_check (check_id, author_user_id, deleted_at),
            CONSTRAINT fk_check_reports_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_report_files (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            report_id VARCHAR(32) NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(128) NOT NULL,
            size_bytes BIGINT NOT NULL,
            uploaded_by VARCHAR(64) NOT NULL,
            uploaded_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_check_report_files_report (report_id, deleted_at),
            CONSTRAINT fk_check_report_files_report FOREIGN KEY (report_id) REFERENCES check_reports (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS faction_person_profiles (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            subject VARCHAR(128) NOT NULL,
            faction_id VARCHAR(64) NOT NULL,
            external_employee_id VARCHAR(64) NOT NULL,
            full_name VARCHAR(191) NOT NULL,
            position_title VARCHAR(191) NOT NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            source VARCHAR(32) NOT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            UNIQUE KEY uniq_faction_person (subject, faction_id, external_employee_id),
            INDEX idx_faction_person_name (subject, faction_id, full_name)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_interviews (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            faction_person_id VARCHAR(32) NOT NULL,
            entered_by VARCHAR(64) NOT NULL,
            total_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            average_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            final_grade_code VARCHAR(16) NOT NULL DEFAULT "",
            final_grade_label VARCHAR(64) NOT NULL DEFAULT "",
            recommended_consequence VARCHAR(191) NOT NULL DEFAULT "",
            override_consequence VARCHAR(191) DEFAULT NULL,
            reviewer_comment MEDIUMTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_check_person_interview (check_id, faction_person_id),
            INDEX idx_check_interviews_check (check_id, deleted_at),
            CONSTRAINT fk_check_interviews_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE,
            CONSTRAINT fk_check_interviews_person FOREIGN KEY (faction_person_id) REFERENCES faction_person_profiles (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_interview_answers (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            interview_id VARCHAR(32) NOT NULL,
            topic_code VARCHAR(64) DEFAULT NULL,
            topic_label VARCHAR(191) DEFAULT NULL,
            question_text TEXT NOT NULL,
            answer_text MEDIUMTEXT DEFAULT NULL,
            score_choice VARCHAR(16) NOT NULL,
            score_value DECIMAL(4,2) NOT NULL DEFAULT 0,
            reviewer_comment MEDIUMTEXT DEFAULT NULL,
            entered_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_check_interview_answers_interview (interview_id, deleted_at),
            CONSTRAINT fk_check_interview_answers_interview FOREIGN KEY (interview_id) REFERENCES check_interviews (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_summary_snapshots (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            snapshot_kind VARCHAR(16) NOT NULL,
            summary_json LONGTEXT NOT NULL,
            generated_by_system_at DATETIME NOT NULL,
            generated_for_status VARCHAR(32) NOT NULL,
            created_by VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_check_snapshot_kind (check_id, snapshot_kind),
            INDEX idx_check_summary_snapshots_check (check_id),
            CONSTRAINT fk_check_summary_snapshots_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_approvals (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            approved_snapshot_id VARCHAR(32) NOT NULL,
            final_rating VARCHAR(32) NOT NULL,
            final_conclusion MEDIUMTEXT NOT NULL,
            resolution_text MEDIUMTEXT DEFAULT NULL,
            approved_by VARCHAR(64) NOT NULL,
            approved_at DATETIME NOT NULL,
            UNIQUE KEY uniq_check_approval (check_id),
            CONSTRAINT fk_check_approvals_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    if (!checks_column_exists($pdo, 'check_approvals', 'created_at')) {
        $pdo->exec(
            'ALTER TABLE check_approvals
             ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER approved_at'
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS check_gp_notes (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            check_id VARCHAR(32) NOT NULL,
            note_text MEDIUMTEXT NOT NULL,
            created_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            visibility VARCHAR(32) NOT NULL DEFAULT "internal_gp",
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_check_gp_notes_check (check_id, deleted_at),
            CONSTRAINT fk_check_gp_notes_check FOREIGN KEY (check_id) REFERENCES `checks` (id) ON DELETE CASCADE
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            recipient_user_id VARCHAR(64) NOT NULL,
            type VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            body TEXT NOT NULL,
            priority VARCHAR(16) NOT NULL DEFAULT "info",
            action_page_id VARCHAR(64) NOT NULL,
            action_payload_json LONGTEXT DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_notifications_recipient (recipient_user_id, read_at, created_at),
            INDEX idx_notifications_entity (entity_type, entity_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS calendar_events (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            recipient_user_id VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            description TEXT DEFAULT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME DEFAULT NULL,
            status_label VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_calendar_entity_recipient (recipient_user_id, entity_type, entity_id),
            INDEX idx_calendar_events_recipient (recipient_user_id, starts_at, deleted_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            action_code VARCHAR(64) NOT NULL,
            actor_user_id VARCHAR(64) DEFAULT NULL,
            actor_role VARCHAR(32) DEFAULT NULL,
            actor_subject VARCHAR(128) DEFAULT NULL,
            before_json LONGTEXT DEFAULT NULL,
            after_json LONGTEXT DEFAULT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_audit_logs_entity (entity_type, entity_id, created_at),
            INDEX idx_audit_logs_actor (actor_user_id, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    // Migration: add manual event columns to calendar_events
    if (!checks_column_exists($pdo, 'calendar_events', 'creator_user_id')) {
        $pdo->exec('ALTER TABLE calendar_events ADD COLUMN creator_user_id VARCHAR(64) DEFAULT NULL');
    }
    if (!checks_column_exists($pdo, 'calendar_events', 'visibility')) {
        $pdo->exec("ALTER TABLE calendar_events ADD COLUMN visibility VARCHAR(32) DEFAULT 'private'");
    }
    if (!checks_column_exists($pdo, 'calendar_events', 'color')) {
        $pdo->exec('ALTER TABLE calendar_events ADD COLUMN color VARCHAR(32) DEFAULT NULL');
    }
    if (!checks_column_exists($pdo, 'calendar_events', 'target_user_id')) {
        $pdo->exec('ALTER TABLE calendar_events ADD COLUMN target_user_id VARCHAR(64) DEFAULT NULL');
    }

    // Предварительная проверка: крайний срок 24 часа для жалоб
    if (checks_column_exists($pdo, 'cases', 'id') && !checks_column_exists($pdo, 'cases', 'preliminary_deadline')) {
        $pdo->exec('ALTER TABLE cases ADD COLUMN preliminary_deadline DATETIME DEFAULT NULL');
    }
    if (checks_column_exists($pdo, 'cases', 'id') && !checks_column_exists($pdo, 'cases', 'assigned_staff_name')) {
        $pdo->exec('ALTER TABLE cases ADD COLUMN assigned_staff_name VARCHAR(255) DEFAULT NULL');
    }
    if (checks_column_exists($pdo, 'cases', 'id') && !checks_column_exists($pdo, 'cases', 'incident_date')) {
        $pdo->exec('ALTER TABLE cases ADD COLUMN incident_date DATE DEFAULT NULL');
    }
    if (checks_column_exists($pdo, 'cases', 'id') && !checks_column_exists($pdo, 'cases', 'decision_deadline')) {
        $pdo->exec('ALTER TABLE cases ADD COLUMN decision_deadline DATETIME DEFAULT NULL');
    }
}

function checks_seed_settings(PDO $pdo): void
{
    $row = checks_fetch_one($pdo, 'SELECT settings_value FROM checks_module_settings WHERE settings_key = :key LIMIT 1', [
        ':key' => 'checks_module',
    ]);
    if (!$row) {
        checks_execute($pdo, 'INSERT INTO checks_module_settings (settings_key, settings_value) VALUES (:key, :value)', [
            ':key' => 'checks_module',
            ':value' => checks_json_encode(checks_default_settings()),
        ]);
        return;
    }

    // Migration: reset all test cases (one-time)
    $resetDone = checks_fetch_one($pdo, "SELECT settings_value FROM checks_module_settings WHERE settings_key = 'cases_reset_v1' LIMIT 1");
    if (!$resetDone) {
        checks_execute($pdo, "DELETE FROM case_comments WHERE 1=1");
        checks_execute($pdo, "DELETE FROM case_links WHERE 1=1");
        checks_execute($pdo, "DELETE FROM case_status_history WHERE 1=1");
        checks_execute($pdo, "DELETE FROM cases WHERE 1=1");
        checks_execute($pdo, "INSERT INTO checks_module_settings (settings_key, settings_value) VALUES ('cases_reset_v1', '1')");
    }

    // Migration: sync enabled_subjects with defaults
    $allSubjects = CHECKS_MODULE_DEFAULT_SETTINGS['enabled_subjects'];
    $current = checks_json_decode_array($row['settings_value'] ?? '');
    $currentSubjects = is_array($current['enabled_subjects'] ?? null) ? $current['enabled_subjects'] : [];
    if ($currentSubjects !== $allSubjects) {
        $current['enabled_subjects'] = $allSubjects;
        checks_execute($pdo, 'UPDATE checks_module_settings SET settings_value = :value WHERE settings_key = :key', [
            ':value' => checks_json_encode($current),
            ':key' => 'checks_module',
        ]);
    }
}

function checks_get_settings(PDO $pdo): array
{
    checks_ensure_schema($pdo);
    checks_seed_settings($pdo);
    $row = checks_fetch_one($pdo, 'SELECT settings_value FROM checks_module_settings WHERE settings_key = :key LIMIT 1', [
        ':key' => 'checks_module',
    ]);
    return checks_normalize_settings(checks_json_decode_array($row['settings_value'] ?? ''));
}

function checks_find_user(array $state, string $userId): ?array
{
    return find_user_by_id($state['users'] ?? [], $userId);
}

function checks_get_subject_prosecutor_position_ids(array $state): array
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

function checks_is_subject_prosecutor(array $user, array $settings, array $state = []): bool
{
    return (($user['role'] ?? null) === 'BOSS')
        && in_array((string)($user['positionId'] ?? ''), checks_get_subject_prosecutor_position_ids($state), true)
        && checks_is_enabled_subject((string)($user['subject'] ?? ''), $settings);
}

function checks_is_federal_check_coordinator(array $user, array $settings): bool
{
    return (($user['role'] ?? null) === 'FEDERAL') && (bool)($settings['allow_federal_participants'] ?? false);
}

function checks_user_can_manage_subject_checks_from_gp(array $user, array $check, array $settings): bool
{
    return checks_is_federal_check_coordinator($user, $settings)
        && checks_is_enabled_subject((string)($check['subject'] ?? ''), $settings)
        && (string)($check['createdBy'] ?? '') === (string)($user['id'] ?? '');
}

function checks_user_can_create(array $user, array $settings, array $state = []): bool
{
    return has_system_admin_access($user)
        || checks_is_subject_prosecutor($user, $settings, $state)
        || checks_is_federal_check_coordinator($user, $settings);
}

function checks_user_assigned_check_ids(PDO $pdo, string $userId): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT check_id FROM check_participants WHERE user_id = :user_id AND removed_at IS NULL',
        [':user_id' => $userId]
    );
    $result = [];
    foreach ($rows as $row) {
        $result[(string)$row['check_id']] = true;
    }
    return $result;
}

function checks_load_active_participant_rows(PDO $pdo, string $checkId): array
{
    return checks_fetch_all(
        $pdo,
        'SELECT * FROM check_participants WHERE check_id = :check_id AND removed_at IS NULL ORDER BY participant_role DESC, assigned_at ASC',
        [':check_id' => $checkId]
    );
}

function checks_load_active_participant_ids(PDO $pdo, string $checkId): array
{
    $rows = checks_load_active_participant_rows($pdo, $checkId);
    $ids = [];
    foreach ($rows as $row) {
        $ids[(string)$row['user_id']] = true;
    }
    return $ids;
}

function checks_user_can_view_check(array $user, array $check, array $participantIds, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    if (($user['role'] ?? null) === 'FEDERAL') {
        return checks_is_enabled_subject((string)($check['subject'] ?? ''), $settings)
            || (($check['status'] ?? '') === 'approved')
            || !empty($participantIds[$user['id'] ?? '']);
    }

    if (checks_is_subject_prosecutor($user, $settings, $state) && ($user['subject'] ?? '') === ($check['subject'] ?? '')) {
        return true;
    }

    return !empty($participantIds[$user['id'] ?? '']);
}

function checks_user_can_edit_check_metadata(array $user, array $check, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    if (checks_user_can_manage_subject_checks_from_gp($user, $check, $settings)) {
        return ($check['status'] ?? '') === 'planned';
    }

    return checks_is_subject_prosecutor($user, $settings, $state)
        && ($user['subject'] ?? '') === ($check['subject'] ?? '')
        && ($check['status'] ?? '') === 'planned';
}

function checks_user_can_activate_check(array $user, array $check, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    return checks_is_subject_prosecutor($user, $settings, $state)
        && ($user['subject'] ?? '') === ($check['subject'] ?? '')
        && ($check['status'] ?? '') === 'planned';
}

function checks_user_can_complete_check(array $user, array $check, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    return checks_is_subject_prosecutor($user, $settings, $state)
        && ($user['subject'] ?? '') === ($check['subject'] ?? '')
        && ($check['status'] ?? '') === 'active';
}

function checks_user_can_submit_for_approval(array $user, array $check, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    return checks_is_subject_prosecutor($user, $settings, $state)
        && ($user['subject'] ?? '') === ($check['subject'] ?? '')
        && in_array(($check['status'] ?? ''), ['completed', 'pending_approval'], true);
}

function checks_user_can_approve(array $user, array $check, array $settings, array $state = []): bool
{
    return checks_user_can_submit_for_approval($user, $check, $settings, $state);
}

function checks_user_can_delete(array $user, array $check, array $settings, array $state = []): bool
{
    return has_system_admin_access($user);
}

function checks_user_can_add_gp_note(array $user, array $check, array $settings): bool
{
    if (!$settings['allow_gp_notes']) {
        return false;
    }

    if (($check['status'] ?? '') !== 'approved') {
        return false;
    }

    return has_system_admin_access($user) || (($user['role'] ?? null) === 'FEDERAL');
}

function checks_user_can_manage_materials(array $user, array $check, array $participantIds): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }

    if (($check['status'] ?? '') !== 'active') {
        return false;
    }

    return !empty($participantIds[$user['id'] ?? '']);
}

function checks_user_can_view_management_sections(array $user, array $check, array $settings, array $state = []): bool
{
    if (has_system_admin_access($user)) {
        return true;
    }
    if (($user['role'] ?? null) === 'FEDERAL') {
        return true;
    }
    return checks_is_subject_prosecutor($user, $settings, $state)
        && ($user['subject'] ?? '') === ($check['subject'] ?? '');
}

function checks_normalize_check_row(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'subject' => (string)$row['subject'],
        'factionId' => (string)$row['faction_id'],
        'status' => (string)$row['status'],
        'statusLabel' => checks_status_label((string)$row['status']),
        'typeCode' => (string)($row['type_code'] ?? ''),
        'typeLabel' => (string)($row['type_label'] ?? ''),
        'basisText' => (string)($row['basis_text'] ?? ''),
        'basisLink' => (string)($row['basis_link'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'notesText' => (string)($row['notes_text'] ?? ''),
        'periodFrom' => $row['period_from'] ?: null,
        'periodTo' => $row['period_to'] ?: null,
        'startsAt' => checks_normalize_datetime_output($row['starts_at'] ?? null),
        'endsAt' => checks_normalize_datetime_output($row['ends_at'] ?? null),
        'collectionClosedAt' => checks_normalize_datetime_output($row['collection_closed_at'] ?? null),
        'createdBy' => (string)($row['created_by'] ?? ''),
        'leadUserId' => (string)($row['lead_user_id'] ?? ''),
        'finalRating' => (string)($row['final_rating'] ?? ''),
        'finalRatingLabel' => checks_final_rating_label((string)($row['final_rating'] ?? '')),
        'finalConclusion' => (string)($row['final_conclusion'] ?? ''),
        'resolutionText' => (string)($row['resolution_text'] ?? ''),
        'approvedSnapshotId' => (string)($row['approved_snapshot_id'] ?? ''),
        'approvedBy' => (string)($row['approved_by'] ?? ''),
        'approvedAt' => checks_normalize_datetime_output($row['approved_at'] ?? null),
        'cancelReason' => (string)($row['cancel_reason'] ?? ''),
        'lockVersion' => (int)($row['lock_version'] ?? 1),
        'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        'updatedAt' => checks_normalize_datetime_output($row['updated_at'] ?? null),
    ];
}

function checks_generate_type_code(string $subject = ''): string
{
    $datePart = gmdate('YmdHis');
    $subjectPart = preg_replace('/[^A-Z0-9]/', '', strtoupper(substr((string)$subject, 0, 6)));
    if ($subjectPart === '') {
        $subjectPart = 'CHECK';
    }
    try {
        $randomPart = strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable $e) {
        $randomPart = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    }
    return $subjectPart . '-' . $datePart . '-' . $randomPart;
}

function checks_fetch_raw_check(PDO $pdo, string $checkId): ?array
{
    return checks_fetch_one($pdo, 'SELECT * FROM `checks` WHERE id = :id AND deleted_at IS NULL LIMIT 1', [':id' => $checkId]);
}

function checks_fetch_check(PDO $pdo, string $checkId): ?array
{
    $row = checks_fetch_raw_check($pdo, $checkId);
    return $row ? checks_normalize_check_row($row) : null;
}

function checks_normalize_participant_row(array $row, array $state): array
{
    $user = checks_find_user($state, (string)$row['user_id']);
    return [
        'id' => (string)$row['id'],
        'userId' => (string)$row['user_id'],
        'participantRole' => (string)$row['participant_role'],
        'source' => (string)$row['source'],
        'assignedBy' => (string)$row['assigned_by'],
        'assignedAt' => checks_normalize_datetime_output($row['assigned_at'] ?? null),
        'user' => checks_user_snapshot($user),
    ];
}

function checks_normalize_report_file_row(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'originalName' => (string)$row['original_name'],
        'mimeType' => (string)$row['mime_type'],
        'sizeBytes' => (int)($row['size_bytes'] ?? 0),
        'uploadedBy' => (string)$row['uploaded_by'],
        'uploadedAt' => checks_normalize_datetime_output($row['uploaded_at'] ?? null),
        'downloadUrl' => 'api.php?action=checks.files.download&id=' . rawurlencode((string)$row['id']),
    ];
}

function checks_extract_report_meta(array $metrics): array
{
    $mode = trim((string)($metrics['__reportMode'] ?? '')) === 'employee' ? 'employee' : 'general';
    return [
        'reportMode' => $mode,
        'employeeRef' => $mode === 'employee'
            ? [
                'fullName' => trim((string)($metrics['__employeeFullName'] ?? '')),
                'externalEmployeeId' => trim((string)($metrics['__employeeId'] ?? '')),
                'rankTitle' => trim((string)($metrics['__employeeRank'] ?? '')),
            ]
            : null,
    ];
}

function checks_strip_report_meta(array $metrics): array
{
    $clean = [];
    foreach ($metrics as $key => $value) {
        if (str_starts_with((string)$key, '__')) {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean;
}

function checks_load_report_files(PDO $pdo, array $reportIds): array
{
    if (count($reportIds) === 0) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($reportIds as $index => $reportId) {
        $placeholder = ':report_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $reportId;
    }

    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM check_report_files WHERE report_id IN (' . implode(', ', $placeholders) . ') AND deleted_at IS NULL ORDER BY uploaded_at ASC',
        $params
    );

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string)$row['report_id']][] = checks_normalize_report_file_row($row);
    }
    return $grouped;
}

function checks_normalize_report_row(array $row, array $files, array $state): array
{
    $author = checks_find_user($state, (string)$row['author_user_id']);
    $rawMetrics = checks_json_decode_array($row['quantitative_metrics_json'] ?? '');
    $reportMeta = checks_extract_report_meta($rawMetrics);
    return [
        'id' => (string)$row['id'],
        'authorUserId' => (string)$row['author_user_id'],
        'author' => checks_user_snapshot($author),
        'sectionCode' => (string)$row['section_code'],
        'sectionLabel' => (string)$row['section_label'],
        'circumstancesText' => (string)($row['circumstances_text'] ?? ''),
        'violationsText' => (string)($row['violations_text'] ?? ''),
        'staffActionsText' => (string)($row['staff_actions_text'] ?? ''),
        'quantitativeMetrics' => checks_strip_report_meta($rawMetrics),
        'reportMode' => $reportMeta['reportMode'],
        'employeeRef' => $reportMeta['employeeRef'],
        'commentText' => (string)($row['comment_text'] ?? ''),
        'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        'updatedAt' => checks_normalize_datetime_output($row['updated_at'] ?? null),
        'files' => $files,
    ];
}

function checks_load_reports(PDO $pdo, string $checkId, array $state): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM check_reports WHERE check_id = :check_id AND deleted_at IS NULL ORDER BY created_at DESC',
        [':check_id' => $checkId]
    );
    $reportIds = array_map(static fn($row) => (string)$row['id'], $rows);
    $filesByReport = checks_load_report_files($pdo, $reportIds);
    return array_map(
        static fn($row) => checks_normalize_report_row($row, $filesByReport[(string)$row['id']] ?? [], $state),
        $rows
    );
}

function checks_normalize_answer_row(array $row): array
{
    return [
        'id' => (string)$row['id'],
        'topicCode' => (string)($row['topic_code'] ?? ''),
        'topicLabel' => (string)($row['topic_label'] ?? ''),
        'questionText' => (string)$row['question_text'],
        'answerText' => (string)($row['answer_text'] ?? ''),
        'scoreChoice' => (string)$row['score_choice'],
        'scoreValue' => (float)($row['score_value'] ?? 0),
        'reviewerComment' => (string)($row['reviewer_comment'] ?? ''),
        'enteredBy' => (string)($row['entered_by'] ?? ''),
        'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        'updatedAt' => checks_normalize_datetime_output($row['updated_at'] ?? null),
    ];
}

function checks_load_interview_answers(PDO $pdo, array $interviewIds): array
{
    if (count($interviewIds) === 0) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($interviewIds as $index => $interviewId) {
        $placeholder = ':interview_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $interviewId;
    }

    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM check_interview_answers WHERE interview_id IN (' . implode(', ', $placeholders) . ') AND deleted_at IS NULL ORDER BY created_at ASC',
        $params
    );

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string)$row['interview_id']][] = checks_normalize_answer_row($row);
    }
    return $grouped;
}

function checks_normalize_interview_row(array $row, array $answers, array $state): array
{
    $enteredBy = checks_find_user($state, (string)$row['entered_by']);
    return [
        'id' => (string)$row['id'],
        'employee' => [
            'profileId' => (string)$row['faction_person_id'],
            'subject' => (string)$row['profile_subject'],
            'factionId' => (string)$row['faction_id'],
            'externalEmployeeId' => (string)$row['external_employee_id'],
            'fullName' => (string)$row['full_name'],
            'positionTitle' => (string)$row['position_title'],
        ],
        'enteredBy' => (string)$row['entered_by'],
        'enteredByUser' => checks_user_snapshot($enteredBy),
        'totalScore' => (float)($row['total_score'] ?? 0),
        'averageScore' => (float)($row['average_score'] ?? 0),
        'finalGradeCode' => (string)($row['final_grade_code'] ?? ''),
        'finalGradeLabel' => (string)($row['final_grade_label'] ?? ''),
        'recommendedConsequence' => (string)($row['recommended_consequence'] ?? ''),
        'overrideConsequence' => (string)($row['override_consequence'] ?? ''),
        'effectiveConsequence' => trim((string)($row['override_consequence'] ?? '')) !== ''
            ? (string)($row['override_consequence'] ?? '')
            : (string)($row['recommended_consequence'] ?? ''),
        'reviewerComment' => (string)($row['reviewer_comment'] ?? ''),
        'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        'updatedAt' => checks_normalize_datetime_output($row['updated_at'] ?? null),
        'answers' => $answers,
    ];
}

function checks_load_interviews(PDO $pdo, string $checkId, array $state): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT ci.*, fpp.subject AS profile_subject, fpp.faction_id, fpp.external_employee_id, fpp.full_name, fpp.position_title
         FROM check_interviews ci
         INNER JOIN faction_person_profiles fpp ON fpp.id = ci.faction_person_id
         WHERE ci.check_id = :check_id AND ci.deleted_at IS NULL
         ORDER BY fpp.full_name ASC',
        [':check_id' => $checkId]
    );

    $interviewIds = array_map(static fn($row) => (string)$row['id'], $rows);
    $answersByInterview = checks_load_interview_answers($pdo, $interviewIds);

    return array_map(
        static fn($row) => checks_normalize_interview_row($row, $answersByInterview[(string)$row['id']] ?? [], $state),
        $rows
    );
}

function checks_load_gp_notes(PDO $pdo, string $checkId, array $state): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM check_gp_notes WHERE check_id = :check_id AND deleted_at IS NULL ORDER BY created_at DESC',
        [':check_id' => $checkId]
    );

    return array_map(static function ($row) use ($state) {
        $author = checks_find_user($state, (string)$row['created_by']);
        return [
            'id' => (string)$row['id'],
            'noteText' => (string)$row['note_text'],
            'createdBy' => (string)$row['created_by'],
            'author' => checks_user_snapshot($author),
            'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
            'visibility' => (string)($row['visibility'] ?? 'internal_gp'),
        ];
    }, $rows);
}

function checks_load_audit(PDO $pdo, string $checkId): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM audit_logs WHERE entity_type = :entity_type AND entity_id = :entity_id ORDER BY created_at DESC',
        [
            ':entity_type' => 'check',
            ':entity_id' => $checkId,
        ]
    );

    return array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'actionCode' => (string)$row['action_code'],
            'actorUserId' => (string)($row['actor_user_id'] ?? ''),
            'actorRole' => (string)($row['actor_role'] ?? ''),
            'actorSubject' => (string)($row['actor_subject'] ?? ''),
            'before' => checks_json_decode_array($row['before_json'] ?? ''),
            'after' => checks_json_decode_array($row['after_json'] ?? ''),
            'meta' => checks_json_decode_array($row['meta_json'] ?? ''),
            'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        ];
    }, $rows);
}

function checks_load_summary_snapshot(PDO $pdo, string $checkId, string $kind): ?array
{
    $row = checks_fetch_one(
        $pdo,
        'SELECT * FROM check_summary_snapshots WHERE check_id = :check_id AND snapshot_kind = :kind LIMIT 1',
        [
            ':check_id' => $checkId,
            ':kind' => $kind,
        ]
    );

    if (!$row) {
        return null;
    }

    return [
        'id' => (string)$row['id'],
        'snapshotKind' => (string)$row['snapshot_kind'],
        'generatedAt' => checks_normalize_datetime_output($row['generated_by_system_at'] ?? null),
        'generatedForStatus' => (string)$row['generated_for_status'],
        'createdBy' => (string)($row['created_by'] ?? ''),
        'createdAt' => checks_normalize_datetime_output($row['created_at'] ?? null),
        'summary' => checks_json_decode_array($row['summary_json'] ?? ''),
    ];
}

function checks_log_audit(PDO $pdo, string $entityType, string $entityId, string $actionCode, ?array $actorUser, $before = null, $after = null, array $meta = []): void
{
    checks_execute(
        $pdo,
        'INSERT INTO audit_logs (entity_type, entity_id, action_code, actor_user_id, actor_role, actor_subject, before_json, after_json, meta_json, created_at)
         VALUES (:entity_type, :entity_id, :action_code, :actor_user_id, :actor_role, :actor_subject, :before_json, :after_json, :meta_json, :created_at)',
        [
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':action_code' => $actionCode,
            ':actor_user_id' => $actorUser['id'] ?? null,
            ':actor_role' => $actorUser['role'] ?? null,
            ':actor_subject' => $actorUser['subject'] ?? null,
            ':before_json' => $before === null ? null : checks_json_encode($before),
            ':after_json' => $after === null ? null : checks_json_encode($after),
            ':meta_json' => count($meta) > 0 ? checks_json_encode($meta) : null,
            ':created_at' => checks_now_storage(),
        ]
    );
}

function checks_get_activity_event_date(array $event): ?string
{
    foreach (['eventDate', 'date', 'createdAt'] as $field) {
        if (!empty($event[$field])) {
            return checks_event_date_to_storage($event[$field]);
        }
    }
    return null;
}

function checks_get_precheck_events(array $state, array $check): array
{
    $startsAt = strtotime((string)($check['startsAt'] ?? ''));
    if ($startsAt === false) {
        return [];
    }
    $windowFrom = gmdate('Y-m-d H:i:s', $startsAt - 30 * 24 * 60 * 60);
    $windowTo = gmdate('Y-m-d H:i:s', $startsAt);
    $subject = (string)($check['subject'] ?? '');
    $factionId = (string)($check['factionId'] ?? '');

    return array_values(array_filter($state['activityEvents'] ?? [], static function ($event) use ($subject, $factionId, $windowFrom, $windowTo) {
        if (!is_array($event)) {
            return false;
        }
        if (trim((string)($event['authorSubject'] ?? '')) !== $subject) {
            return false;
        }
        if (trim((string)($event['factionId'] ?? '')) !== $factionId) {
            return false;
        }
        $eventDate = checks_get_activity_event_date($event);
        if ($eventDate === null) {
            return false;
        }
        return $eventDate >= $windowFrom && $eventDate <= $windowTo;
    }));
}

function checks_build_precheck_summary(array $state, array $check): array
{
    $events = checks_get_precheck_events($state, $check);
    $reasons = [];
    $metrics = [
        'detentions' => 0,
        'fines' => 0,
        'decisions' => 0,
        'warnings' => 0,
        'disciplinary' => 0,
        'officialVisits' => 0,
        'uniquePersons' => 0,
        'eventsTotal' => count($events),
    ];
    $uniquePersons = [];

    foreach ($events as $event) {
        $type = (string)($event['type'] ?? '');
        if ($type === 'detention') $metrics['detentions'] += 1;
        if ($type === 'fine') $metrics['fines'] += 1;
        if ($type === 'decision') $metrics['decisions'] += 1;
        if ($type === 'warning') $metrics['warnings'] += 1;
        if ($type === 'disciplinary') $metrics['disciplinary'] += 1;
        if ($type === 'official_visit') $metrics['officialVisits'] += 1;

        $targetId = trim((string)($event['personRecord']['targetId'] ?? ''));
        $targetName = trim((string)($event['personRecord']['targetFullName'] ?? ''));
        if ($targetId !== '' || $targetName !== '') {
            $uniquePersons[$targetId . '::' . $targetName] = true;
        }

        $reason = trim((string)($event['personRecord']['reason'] ?? ''));
        if ($reason !== '') {
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
    }

    arsort($reasons);
    $startsAt = strtotime((string)($check['startsAt'] ?? ''));

    return [
        'windowFrom' => $startsAt === false ? null : gmdate('c', $startsAt - 30 * 24 * 60 * 60),
        'windowTo' => $startsAt === false ? null : gmdate('c', $startsAt),
        'metrics' => array_merge($metrics, [
            'uniquePersons' => count($uniquePersons),
            'topReasons' => array_map(
                static fn($reason, $count) => ['reason' => $reason, 'count' => $count],
                array_keys(array_slice($reasons, 0, 5, true)),
                array_values(array_slice($reasons, 0, 5, true))
            ),
        ]),
    ];
}

function checks_count_report_metrics(array $reports): array
{
    $bySection = [];
    $violationsCount = 0;
    $attachmentsCount = 0;
    $quantitativeTotals = [];
    $highlights = [];

    foreach ($reports as $report) {
        $section = trim((string)($report['sectionLabel'] ?? 'Материалы'));
        $bySection[$section] = ($bySection[$section] ?? 0) + 1;
        if (trim((string)($report['violationsText'] ?? '')) !== '') {
            $violationsCount += 1;
        }
        $attachmentsCount += count($report['files'] ?? []);
        foreach (($report['quantitativeMetrics'] ?? []) as $metricKey => $metricValue) {
            if (is_numeric($metricValue)) {
                $quantitativeTotals[$metricKey] = ($quantitativeTotals[$metricKey] ?? 0) + (float)$metricValue;
            }
        }

        foreach (['circumstancesText', 'violationsText', 'staffActionsText', 'commentText'] as $field) {
            $value = trim((string)($report[$field] ?? ''));
            if ($value !== '') {
                $highlights[] = $value;
            }
        }
    }

    return [
        'reportsCount' => count($reports),
        'violationsCount' => $violationsCount,
        'attachmentsCount' => $attachmentsCount,
        'reportsBySection' => $bySection,
        'quantitativeTotals' => $quantitativeTotals,
        'highlights' => array_values(array_slice(array_unique($highlights), 0, 8)),
    ];
}

function checks_build_interview_roster(array $interviews): array
{
    return array_map(static function ($interview) {
        return [
            'employee' => $interview['employee'],
            'answers' => $interview['answers'],
            'totalScore' => $interview['totalScore'],
            'averageScore' => $interview['averageScore'],
            'finalGradeCode' => $interview['finalGradeCode'],
            'finalGradeLabel' => $interview['finalGradeLabel'],
            'recommendedConsequence' => $interview['recommendedConsequence'],
            'effectiveConsequence' => $interview['effectiveConsequence'],
            'reviewerComment' => $interview['reviewerComment'],
            'enteredBy' => $interview['enteredByUser'],
        ];
    }, $interviews);
}

function checks_build_current_summary(array $reports, array $interviews, array $participants): array
{
    $reportMetrics = checks_count_report_metrics($reports);
    $gradeDistribution = [];
    $totalScoreSum = 0.0;
    $answersCount = 0;

    foreach ($interviews as $interview) {
        $grade = (string)($interview['finalGradeLabel'] ?? '');
        if ($grade !== '') {
            $gradeDistribution[$grade] = ($gradeDistribution[$grade] ?? 0) + 1;
        }
        $totalScoreSum += (float)($interview['totalScore'] ?? 0);
        $answersCount += count($interview['answers'] ?? []);
    }

    $participantSummary = [];
    foreach ($participants as $participant) {
        $participantId = (string)($participant['userId'] ?? '');
        $reportCount = count(array_filter($reports, static fn($report) => ($report['authorUserId'] ?? '') === $participantId));
        $interviewCount = count(array_filter($interviews, static fn($interview) => ($interview['enteredBy'] ?? '') === $participantId));
        $participantSummary[] = [
            'user' => $participant['user'],
            'reportCount' => $reportCount,
            'interviewCount' => $interviewCount,
        ];
    }

    return [
        'reportsSummary' => [
            'reportsCount' => $reportMetrics['reportsCount'],
            'violationsCount' => $reportMetrics['violationsCount'],
            'attachmentsCount' => $reportMetrics['attachmentsCount'],
            'reportsBySection' => $reportMetrics['reportsBySection'],
            'quantitativeTotals' => $reportMetrics['quantitativeTotals'],
        ],
        'interviewsSummary' => [
            'employeesCount' => count($interviews),
            'answersCount' => $answersCount,
            'averageTotalScore' => count($interviews) > 0 ? round($totalScoreSum / count($interviews), 2) : 0,
            'gradeDistribution' => $gradeDistribution,
        ],
        'participantSummary' => $participantSummary,
        'generalFindings' => $reportMetrics['highlights'],
    ];
}

function checks_build_summary_payload(array $check, array $participants, array $reports, array $interviews, array $state, ?array $approval = null, array $gpNotes = []): array
{
    return [
        'check' => [
            'id' => $check['id'],
            'subject' => $check['subject'],
            'faction' => [
                'id' => $check['factionId'],
                'name' => checks_get_faction_name($state, $check['factionId']),
            ],
            'status' => $check['status'],
            'statusLabel' => $check['statusLabel'],
            'basisText' => $check['basisText'],
            'description' => $check['description'],
            'notesText' => $check['notesText'],
            'startsAt' => $check['startsAt'],
            'endsAt' => $check['endsAt'],
            'participants' => $participants,
        ],
        'preCheckSummary' => checks_build_precheck_summary($state, $check),
        'currentCheckSummary' => checks_build_current_summary($reports, $interviews, $participants),
        'interviewRoster' => checks_build_interview_roster($interviews),
        'approval' => $approval,
        'gpNotes' => $gpNotes,
    ];
}

function checks_upsert_summary_snapshot(PDO $pdo, string $checkId, string $kind, array $summary, string $status, ?string $createdBy): array
{
    $snapshotId = checks_uuid();
    $generatedAt = checks_now_storage();
    checks_execute(
        $pdo,
        'INSERT INTO check_summary_snapshots (id, check_id, snapshot_kind, summary_json, generated_by_system_at, generated_for_status, created_by, created_at)
         VALUES (:id, :check_id, :snapshot_kind, :summary_json, :generated_by_system_at, :generated_for_status, :created_by, :created_at)
         ON DUPLICATE KEY UPDATE
            id = VALUES(id),
            summary_json = VALUES(summary_json),
            generated_by_system_at = VALUES(generated_by_system_at),
            generated_for_status = VALUES(generated_for_status),
            created_by = VALUES(created_by),
            created_at = VALUES(created_at)',
        [
            ':id' => $snapshotId,
            ':check_id' => $checkId,
            ':snapshot_kind' => $kind,
            ':summary_json' => checks_json_encode($summary),
            ':generated_by_system_at' => $generatedAt,
            ':generated_for_status' => $status,
            ':created_by' => $createdBy,
            ':created_at' => $generatedAt,
        ]
    );

    return (array)checks_load_summary_snapshot($pdo, $checkId, $kind);
}

function checks_refresh_draft_summary(PDO $pdo, array $state, string $checkId, ?string $createdBy = null): array
{
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $participants = array_map(
        static fn($row) => checks_normalize_participant_row($row, $state),
        checks_load_active_participant_rows($pdo, $checkId)
    );
    $reports = checks_load_reports($pdo, $checkId, $state);
    $interviews = checks_load_interviews($pdo, $checkId, $state);
    $gpNotes = checks_load_gp_notes($pdo, $checkId, $state);
    $summary = checks_build_summary_payload($check, $participants, $reports, $interviews, $state, null, $gpNotes);

    return checks_upsert_summary_snapshot($pdo, $checkId, 'draft', $summary, $check['status'], $createdBy);
}

function checks_create_notification(PDO $pdo, string $recipientUserId, string $type, string $entityType, string $entityId, string $title, string $body, string $priority = 'info', string $actionPageId = 'checks', array $actionPayload = []): void
{
    checks_execute(
        $pdo,
        'INSERT INTO notifications (id, recipient_user_id, type, entity_type, entity_id, title, body, priority, action_page_id, action_payload_json, created_at)
         VALUES (:id, :recipient_user_id, :type, :entity_type, :entity_id, :title, :body, :priority, :action_page_id, :action_payload_json, :created_at)',
        [
            ':id' => checks_uuid(),
            ':recipient_user_id' => $recipientUserId,
            ':type' => $type,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':title' => $title,
            ':body' => $body,
            ':priority' => $priority,
            ':action_page_id' => $actionPageId,
            ':action_payload_json' => count($actionPayload) > 0 ? checks_json_encode($actionPayload) : null,
            ':created_at' => checks_now_storage(),
        ]
    );
}

function checks_mark_notifications_deleted(PDO $pdo, string $entityType, string $entityId): void
{
    checks_execute(
        $pdo,
        'UPDATE notifications
         SET deleted_at = :deleted_at
         WHERE entity_type = :entity_type AND entity_id = :entity_id AND deleted_at IS NULL',
        [
            ':deleted_at' => checks_now_storage(),
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
        ]
    );
}

function checks_notification_priority_for_status(string $status): string
{
    return in_array($status, ['pending_approval', 'approved'], true) ? 'warning' : 'info';
}

function checks_build_check_title(array $state, array $check): string
{
    return 'Проверка фракции: ' . checks_get_faction_name($state, (string)$check['factionId']);
}

function checks_build_check_description(array $state, array $check): string
{
    $parts = [
        'Субъект: ' . ($check['subject'] ?: '—'),
        'Основание: ' . ($check['basisText'] ?: '—'),
    ];
    if (!empty($check['startsAt'])) {
        $parts[] = 'Начало: ' . (new DateTimeImmutable($check['startsAt']))->format('d.m.Y H:i');
    }
    if (!empty($check['endsAt'])) {
        $parts[] = 'Окончание: ' . (new DateTimeImmutable($check['endsAt']))->format('d.m.Y H:i');
    }
    return implode(' • ', $parts);
}

function checks_upsert_calendar_event(PDO $pdo, string $recipientUserId, array $check, string $title, string $description): void
{
    checks_execute(
        $pdo,
        'INSERT INTO calendar_events (id, recipient_user_id, entity_type, entity_id, title, description, starts_at, ends_at, status_label, created_at, updated_at, deleted_at)
         VALUES (:id, :recipient_user_id, :entity_type, :entity_id, :title, :description, :starts_at, :ends_at, :status_label, :created_at, :updated_at, NULL)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            starts_at = VALUES(starts_at),
            ends_at = VALUES(ends_at),
            status_label = VALUES(status_label),
            updated_at = VALUES(updated_at),
            deleted_at = NULL',
        [
            ':id' => checks_uuid(),
            ':recipient_user_id' => $recipientUserId,
            ':entity_type' => 'check',
            ':entity_id' => $check['id'],
            ':title' => $title,
            ':description' => $description,
            ':starts_at' => checks_datetime_to_storage($check['startsAt'], true),
            ':ends_at' => checks_datetime_to_storage($check['endsAt']),
            ':status_label' => $check['statusLabel'],
            ':created_at' => checks_now_storage(),
            ':updated_at' => checks_now_storage(),
        ]
    );
}

function checks_mark_calendar_deleted(PDO $pdo, string $checkId, string $recipientUserId): void
{
    checks_execute(
        $pdo,
        'UPDATE calendar_events SET deleted_at = :deleted_at, updated_at = :updated_at
         WHERE entity_type = :entity_type AND entity_id = :entity_id AND recipient_user_id = :recipient_user_id AND deleted_at IS NULL',
        [
            ':deleted_at' => checks_now_storage(),
            ':updated_at' => checks_now_storage(),
            ':entity_type' => 'check',
            ':entity_id' => $checkId,
            ':recipient_user_id' => $recipientUserId,
        ]
    );
}

function checks_list_rows_for_user(PDO $pdo, array $state, array $user, array $settings): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM `checks` WHERE deleted_at IS NULL ORDER BY created_at DESC'
    );

    $assignedIds = checks_user_assigned_check_ids($pdo, (string)($user['id'] ?? ''));
    $result = [];

    foreach ($rows as $row) {
        $check = checks_normalize_check_row($row);
        $canView = false;

        if (has_system_admin_access($user)) {
            $canView = true;
        } elseif (($user['role'] ?? null) === 'FEDERAL') {
            $canView = checks_is_enabled_subject((string)($check['subject'] ?? ''), $settings)
                || ($check['status'] === 'approved')
                || !empty($assignedIds[$check['id']]);
        } elseif (checks_is_subject_prosecutor($user, $settings, $state) && ($user['subject'] ?? '') === $check['subject']) {
            $canView = true;
        } else {
            $canView = !empty($assignedIds[$check['id']]);
        }

        if ($canView) {
            $result[] = $check;
        }
    }

    return $result;
}

function checks_build_bootstrap_notifications(PDO $pdo, array $user): array
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT * FROM notifications WHERE recipient_user_id = :recipient_user_id AND deleted_at IS NULL AND read_at IS NULL ORDER BY created_at DESC LIMIT 12',
        [':recipient_user_id' => $user['id']]
    );

    $activeCheckIds = [];
    foreach ($rows as $row) {
        if (($row['entity_type'] ?? '') !== 'check') {
            continue;
        }
        if (($row['type'] ?? '') === 'check_started') {
            $activeCheckIds[(string)($row['entity_id'] ?? '')] = true;
        }
    }

    $rows = array_values(array_filter($rows, static function ($row) use ($activeCheckIds) {
        if (($row['entity_type'] ?? '') !== 'check') {
            return true;
        }
        if (($row['type'] ?? '') !== 'check_assigned') {
            return true;
        }
        $entityId = (string)($row['entity_id'] ?? '');
        return !isset($activeCheckIds[$entityId]);
    }));

    return array_map(static function ($row) {
        return [
            'id' => (string)$row['id'],
            'type' => 'check_notification',
            'priority' => (string)($row['priority'] ?? 'info'),
            'title' => (string)$row['title'],
            'text' => (string)$row['body'],
            'date' => checks_normalize_datetime_output($row['created_at'] ?? null),
            'actionId' => (string)($row['action_page_id'] ?? 'checks'),
            'actionLabel' => 'Открыть раздел',
            'scope' => 'Проверки',
            'actionPayload' => checks_json_decode_array($row['action_payload_json'] ?? ''),
        ];
    }, $rows);
}

function checks_build_bootstrap_calendar(PDO $pdo, array $user, array $state = []): array
{
    $isGlobal = has_system_admin_access($user) || ($user['role'] ?? '') === 'FEDERAL' || ($user['subject'] ?? '') === GENERAL_SUBJECT;
    $fromDate = gmdate('Y-m-d H:i:s', time() - 7 * 24 * 60 * 60);

    if ($isGlobal) {
        $rows = checks_fetch_all(
            $pdo,
            'SELECT * FROM calendar_events
             WHERE deleted_at IS NULL AND starts_at >= :from_date
             ORDER BY starts_at ASC
             LIMIT 60',
            [':from_date' => $fromDate]
        );
    } else {
        $rows = checks_fetch_all(
            $pdo,
            'SELECT * FROM calendar_events
             WHERE recipient_user_id = :recipient_user_id AND deleted_at IS NULL
               AND starts_at >= :from_date
             ORDER BY starts_at ASC
             LIMIT 20',
            [':recipient_user_id' => $user['id'], ':from_date' => $fromDate]
        );
    }

    // Deduplicate by entity_type+entity_id (global query may return many per-user rows)
    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
        $key = $row['entity_type'] . ':' . $row['entity_id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $row;
        }
    }

    // Add case deadlines + checks from table (so planned checks appear too)
    $caseEvents = cases_build_calendar_events($pdo, $user, $isGlobal, $fromDate);
    $checkTableEvents = checks_build_calendar_from_checks_table($pdo, $user, $isGlobal, $fromDate, $state);

    $result = [];
    $seenEntities = [];
    // First add calendar_events rows
    foreach ($unique as $row) {
        $result[] = calendar_row_to_array($row);
        $seenEntities[$row['entity_type'] . ':' . $row['entity_id']] = true;
    }
    // Add checks from table (skip if already present from calendar_events)
    foreach ($checkTableEvents as $ce) {
        $key = $ce['entityType'] . ':' . $ce['entityId'];
        if (!isset($seenEntities[$key])) {
            $result[] = $ce;
            $seenEntities[$key] = true;
        }
    }
    foreach ($caseEvents as $ce) {
        $result[] = $ce;
    }

    usort($result, function ($a, $b) {
        return strcmp($a['startsAt'] ?? '', $b['startsAt'] ?? '');
    });

    return array_slice($result, 0, 60);
}

function checks_build_calendar_from_checks_table(PDO $pdo, array $user, bool $isGlobal, string $fromDate, array $state): array
{
    $terminalStatuses = ['cancelled'];
    $placeholders = implode(',', array_map(function ($i) { return ':cs' . $i; }, range(0, count($terminalStatuses) - 1)));
    $params = [':from_date' => $fromDate];
    foreach ($terminalStatuses as $i => $s) {
        $params[':cs' . $i] = $s;
    }

    if ($isGlobal) {
        $sql = "SELECT id, subject, faction_id, status, starts_at, ends_at
                FROM `checks`
                WHERE deleted_at IS NULL AND status NOT IN ($placeholders) AND starts_at >= :from_date
                ORDER BY starts_at ASC LIMIT 60";
    } else {
        $userId = $user['id'] ?? '';
        $sql = "SELECT c.id, c.subject, c.faction_id, c.status, c.starts_at, c.ends_at
                FROM `checks` c
                LEFT JOIN check_participants cp ON cp.check_id = c.id AND cp.user_id = :uid AND cp.removed_at IS NULL
                WHERE c.deleted_at IS NULL AND c.status NOT IN ($placeholders) AND c.starts_at >= :from_date
                  AND (c.created_by = :uid2 OR cp.user_id IS NOT NULL)
                ORDER BY c.starts_at ASC LIMIT 30";
        $params[':uid'] = $userId;
        $params[':uid2'] = $userId;
    }

    $rows = checks_fetch_all($pdo, $sql, $params);
    $statusLabels = CHECKS_STATUS_LABELS;

    $events = [];
    foreach ($rows as $r) {
        $factionName = '';
        foreach ($state['factions'] ?? [] as $f) {
            if (($f['id'] ?? '') === ($r['faction_id'] ?? '')) { $factionName = $f['name'] ?? ''; break; }
        }
        $title = 'Проверка' . ($factionName ? ': ' . $factionName : '');
        $statusLabel = $statusLabels[$r['status']] ?? $r['status'];
        $events[] = [
            'id' => 'chk-' . $r['id'],
            'entityType' => 'check',
            'entityId' => (string)$r['id'],
            'title' => $title,
            'description' => $r['subject'] . ' • ' . $statusLabel,
            'startsAt' => $r['starts_at'],
            'endsAt' => $r['ends_at'] ?? $r['starts_at'],
            'statusLabel' => $statusLabel,
        ];
    }
    return $events;
}

function cases_build_calendar_events(PDO $pdo, array $user, bool $isGlobal, string $fromDate): array
{
    $terminalStatuses = ['completed', 'archive', 'check_terminated', 'criminal_case_refused', 'prosecution_refused'];
    $placeholders = implode(',', array_map(function ($i) { return ':ts' . $i; }, range(0, count($terminalStatuses) - 1)));
    $params = [':from_date' => $fromDate];
    foreach ($terminalStatuses as $i => $s) {
        $params[':ts' . $i] = $s;
    }

    if ($isGlobal) {
        $sql = "SELECT id, reg_number, subject, case_type, status, applicant_name, deadline, assigned_staff_id, supervisor_id, created_by
                FROM cases
                WHERE deleted_at IS NULL AND deadline IS NOT NULL AND status NOT IN ($placeholders) AND deadline >= :from_date
                ORDER BY deadline ASC LIMIT 60";
    } else {
        $userSubject = $user['subject'] ?? '';
        $userId = $user['id'] ?? '';
        $sql = "SELECT id, reg_number, subject, case_type, status, applicant_name, deadline, assigned_staff_id, supervisor_id, created_by
                FROM cases
                WHERE deleted_at IS NULL AND deadline IS NOT NULL AND status NOT IN ($placeholders) AND deadline >= :from_date
                  AND (subject = :subject OR assigned_staff_id = :uid OR supervisor_id = :uid2 OR created_by = :uid3)
                ORDER BY deadline ASC LIMIT 20";
        $params[':subject'] = $userSubject;
        $params[':uid'] = $userId;
        $params[':uid2'] = $userId;
        $params[':uid3'] = $userId;
    }

    $rows = checks_fetch_all($pdo, $sql, $params);

    $events = [];
    foreach ($rows as $r) {
        $caseType = ($r['case_type'] === 'appeal') ? 'Обращение' : 'Жалоба';
        $statusLabel = CASES_STATUSES[$r['status']] ?? $r['status'];
        $events[] = [
            'id' => 'case-' . $r['id'],
            'entityType' => 'case',
            'entityId' => (string)$r['id'],
            'title' => '📨 ' . $r['reg_number'] . ' — ' . $caseType,
            'description' => ($r['applicant_name'] ? 'Заявитель: ' . $r['applicant_name'] . ' • ' : '') . 'Срок: ' . (new DateTimeImmutable($r['deadline']))->format('d.m.Y') . ' • ' . $r['subject'],
            'startsAt' => $r['deadline'] . ' 00:00:00',
            'endsAt' => $r['deadline'] . ' 23:59:59',
            'statusLabel' => $statusLabel,
        ];
    }
    return $events;
}

function checks_build_bootstrap_meta(PDO $pdo, array $state, ?array $user): array
{
    $settings = checks_get_settings($pdo);
    $meta = checks_default_meta($settings);
    if (!$user) {
        return $meta;
    }

    checks_reconcile_statuses($pdo, $state);
    $rows = checks_list_rows_for_user($pdo, $state, $user, $settings);
    $assignedIds = checks_user_assigned_check_ids($pdo, (string)($user['id'] ?? ''));
    $owned = 0;
    $assigned = 0;
    $approved = 0;
    $pendingApproval = 0;

    foreach ($rows as $check) {
        if (($check['subject'] ?? '') === ($user['subject'] ?? '') && checks_is_subject_prosecutor($user, $settings, $state)) {
            $owned += 1;
        }
        if (($user['role'] ?? null) === 'FEDERAL' && ($check['createdBy'] ?? '') === ($user['id'] ?? '')) {
            $owned += 1;
        }
        if (!empty($assignedIds[$check['id']])) {
            $assigned += 1;
        }
        if (($check['status'] ?? '') === 'approved') {
            $approved += 1;
        }
        if (($check['status'] ?? '') === 'pending_approval') {
            $pendingApproval += 1;
        }
    }

    $notifications = checks_build_bootstrap_notifications($pdo, $user);
    $calendarEvents = checks_build_bootstrap_calendar($pdo, $user, $state);

    $meta['availableForCurrentUser'] =
        checks_user_can_create($user, $settings, $state)
        || $assigned > 0
        || $approved > 0
        || (($user['role'] ?? null) === 'FEDERAL')
        || has_system_admin_access($user);
    $meta['permissions'] = [
        'canCreate' => checks_user_can_create($user, $settings, $state),
        'canViewApproved' => has_system_admin_access($user) || (($user['role'] ?? null) === 'FEDERAL') || checks_is_subject_prosecutor($user, $settings, $state),
        'canAddGpNotes' => (has_system_admin_access($user) || (($user['role'] ?? null) === 'FEDERAL')) && (bool)$settings['allow_gp_notes'],
        'canAccessModule' => $meta['availableForCurrentUser'],
    ];
    $meta['counters'] = [
        'owned' => $owned,
        'assigned' => $assigned,
        'approved' => $approved,
        'pendingApproval' => $pendingApproval,
        'unreadNotifications' => count($notifications),
    ];
    $meta['notifications'] = $notifications;
    $meta['calendarEvents'] = $calendarEvents;

    return $meta;
}

function checks_reconcile_statuses(PDO $pdo, array $state): void
{
    $rows = checks_fetch_all(
        $pdo,
        'SELECT id FROM `checks` WHERE deleted_at IS NULL AND status = :status AND starts_at <= :now',
        [
            ':status' => 'planned',
            ':now' => checks_now_storage(),
        ]
    );

    foreach ($rows as $row) {
        $checkId = (string)$row['id'];
        checks_execute(
            $pdo,
            'UPDATE `checks` SET status = :status, updated_at = :updated_at, lock_version = lock_version + 1 WHERE id = :id',
            [
                ':status' => 'active',
                ':updated_at' => checks_now_storage(),
                ':id' => $checkId,
            ]
        );

        $check = checks_fetch_check($pdo, $checkId);
        if (!$check) {
            continue;
        }

        $participants = array_map(
            static fn($participantRow) => checks_find_user($state, (string)$participantRow['user_id']),
            checks_load_active_participant_rows($pdo, $checkId)
        );
        $participants = array_values(array_filter($participants));

        foreach ($participants as $participantUser) {
            checks_upsert_calendar_event(
                $pdo,
                (string)$participantUser['id'],
                $check,
                checks_build_check_title($state, $check),
                checks_build_check_description($state, $check)
            );
            checks_create_notification(
                $pdo,
                (string)$participantUser['id'],
                'check_started',
                'check',
                $checkId,
                'Проверка переведена в активный статус',
                checks_build_check_title($state, $check) . ' доступна для внесения материалов.',
                'warning',
                'checks',
                ['checkId' => $checkId]
            );
        }

        checks_log_audit(
            $pdo,
            'check',
            $checkId,
            'status_auto_activated',
            null,
            ['status' => 'planned'],
            ['status' => 'active'],
            ['source' => 'system_reconcile']
        );
    }
}

function checks_validate_subject(string $subject, array $settings): void
{
    if (!checks_is_enabled_subject($subject, $settings)) {
        respond(403, ['ok' => false, 'error' => 'Модуль проверки фракции доступен только для Арбата и Патриков']);
    }
}

function checks_validate_faction(array $state, string $factionId): array
{
    if ($factionId === '') {
        respond(422, ['ok' => false, 'error' => 'Выберите проверяемую фракцию']);
    }

    $faction = checks_get_faction_meta($state, $factionId);
    if (!$faction) {
        respond(422, ['ok' => false, 'error' => 'Фракция не найдена в справочнике']);
    }

    return $faction;
}

function checks_validate_participant_ids(array $state, array $settings, string $subject, array $participantUserIds, string $creatorUserId): array
{
    $normalized = array_values(array_unique(array_filter(array_map(static fn($item) => trim((string)$item), $participantUserIds))));
    if (!in_array($creatorUserId, $normalized, true)) {
        $normalized[] = $creatorUserId;
    }

    if (count($normalized) === 0) {
        respond(422, ['ok' => false, 'error' => 'Назначьте минимум одного участника проверки']);
    }

    $participants = [];
    foreach ($normalized as $participantUserId) {
        $participantUser = checks_find_user($state, $participantUserId);
        if (!$participantUser || ($participantUser['blocked'] ?? false)) {
            respond(422, ['ok' => false, 'error' => 'В составе участников есть недоступные или заблокированные пользователи']);
        }

        $participantSubject = trim((string)($participantUser['subject'] ?? ''));
        $participantRole = trim((string)($participantUser['role'] ?? ''));
        $isFederal = $participantSubject === GENERAL_SUBJECT || $participantRole === 'FEDERAL' || has_system_admin_access($participantUser);

        if ($participantSubject !== $subject && !$isFederal) {
            respond(422, ['ok' => false, 'error' => 'Назначать можно только сотрудников своего субъекта или Генеральной прокуратуры']);
        }

        if ($participantSubject !== $subject && $isFederal && !$settings['allow_federal_participants']) {
            respond(422, ['ok' => false, 'error' => 'Федеральные участники временно запрещены настройками модуля']);
        }

        $participants[] = $participantUser;
    }

    return $participants;
}

function checks_sync_participants(PDO $pdo, array $state, array $check, array $actorUser, array $participantUsers): array
{
    $existingRows = checks_load_active_participant_rows($pdo, $check['id']);
    $existingByUserId = [];
    foreach ($existingRows as $row) {
        $existingByUserId[(string)$row['user_id']] = $row;
    }

    $targetByUserId = [];
    foreach ($participantUsers as $participantUser) {
        $targetByUserId[(string)$participantUser['id']] = $participantUser;
    }

    $removedUserIds = array_diff(array_keys($existingByUserId), array_keys($targetByUserId));
    $addedUsers = array_diff(array_keys($targetByUserId), array_keys($existingByUserId));

    foreach ($removedUserIds as $removedUserId) {
        checks_execute(
            $pdo,
            'UPDATE check_participants SET removed_at = :removed_at WHERE check_id = :check_id AND user_id = :user_id AND removed_at IS NULL',
            [
                ':removed_at' => checks_now_storage(),
                ':check_id' => $check['id'],
                ':user_id' => $removedUserId,
            ]
        );
        checks_mark_calendar_deleted($pdo, $check['id'], $removedUserId);
    }

    foreach ($participantUsers as $participantUser) {
        $participantUserId = (string)$participantUser['id'];
        $participantRole = $participantUserId === ($check['leadUserId'] ?? '') ? 'lead' : 'member';
        $source = (($participantUser['subject'] ?? '') === GENERAL_SUBJECT || ($participantUser['role'] ?? '') === 'FEDERAL') ? 'federal' : 'subject';

        if (isset($existingByUserId[$participantUserId])) {
            continue;
        }

        checks_execute(
            $pdo,
            'INSERT INTO check_participants (id, check_id, user_id, participant_role, source, assigned_by, assigned_at)
             VALUES (:id, :check_id, :user_id, :participant_role, :source, :assigned_by, :assigned_at)',
            [
                ':id' => checks_uuid(),
                ':check_id' => $check['id'],
                ':user_id' => $participantUserId,
                ':participant_role' => $participantRole,
                ':source' => $source,
                ':assigned_by' => $actorUser['id'] ?? '',
                ':assigned_at' => checks_now_storage(),
            ]
        );
    }

    foreach ($participantUsers as $participantUser) {
        checks_upsert_calendar_event(
            $pdo,
            (string)$participantUser['id'],
            $check,
            checks_build_check_title($state, $check),
            checks_build_check_description($state, $check)
        );
        if (in_array((string)$participantUser['id'], $addedUsers, true)) {
            checks_create_notification(
                $pdo,
                (string)$participantUser['id'],
                'check_assigned',
                'check',
                $check['id'],
                'Вы назначены участником проверки',
                checks_build_check_title($state, $check) . ' • доступ к материалам откроется с даты начала.',
                'info',
                'checks',
                ['checkId' => $check['id']]
            );
        }
    }

    return array_map(
        static fn($row) => checks_normalize_participant_row($row, $state),
        checks_load_active_participant_rows($pdo, $check['id'])
    );
}

function checks_collect_files_for_download(PDO $pdo, string $fileId): ?array
{
    return checks_fetch_one(
        $pdo,
        'SELECT crf.*, cr.check_id
         FROM check_report_files crf
         INNER JOIN check_reports cr ON cr.id = crf.report_id
         WHERE crf.id = :id AND crf.deleted_at IS NULL AND cr.deleted_at IS NULL
         LIMIT 1',
        [':id' => $fileId]
    );
}

function checks_resolve_attachment_extension(array $file): ?string
{
    $mimeType = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, (string)($file['tmp_name'] ?? ''));
            if (is_string($detected)) {
                $mimeType = $detected;
            }
            finfo_close($finfo);
        }
    }

    if ($mimeType !== '' && array_key_exists($mimeType, CHECKS_ALLOWED_ATTACHMENT_EXTENSIONS)) {
        return CHECKS_ALLOWED_ATTACHMENT_EXTENSIONS[$mimeType];
    }

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    foreach (CHECKS_ALLOWED_ATTACHMENT_EXTENSIONS as $allowedExtension) {
        if ($extension === $allowedExtension) {
            return $extension;
        }
    }

    return null;
}

function checks_upload_dir(string $checkId): array
{
    return ensure_named_upload_dir('checks/' . preg_replace('/[^A-Za-z0-9_-]/', '', $checkId), 'Не удалось создать директорию для файлов проверки');
}

function checks_report_by_id(PDO $pdo, string $checkId, string $reportId): ?array
{
    return checks_fetch_one(
        $pdo,
        'SELECT * FROM check_reports WHERE id = :report_id AND check_id = :check_id AND deleted_at IS NULL LIMIT 1',
        [
            ':report_id' => $reportId,
            ':check_id' => $checkId,
        ]
    );
}

function checks_interview_by_id(PDO $pdo, string $checkId, string $interviewId): ?array
{
    return checks_fetch_one(
        $pdo,
        'SELECT * FROM check_interviews WHERE id = :interview_id AND check_id = :check_id AND deleted_at IS NULL LIMIT 1',
        [
            ':interview_id' => $interviewId,
            ':check_id' => $checkId,
        ]
    );
}

function checks_build_permissions_payload(array $user, array $check, array $participantIds, array $settings, array $state): array
{
    return [
        'canView' => checks_user_can_view_check($user, $check, $participantIds, $settings, $state),
        'canEditMetadata' => checks_user_can_edit_check_metadata($user, $check, $settings, $state),
        'canActivate' => checks_user_can_activate_check($user, $check, $settings, $state),
        'canComplete' => checks_user_can_complete_check($user, $check, $settings, $state),
        'canSubmitApproval' => checks_user_can_submit_for_approval($user, $check, $settings, $state),
        'canApprove' => checks_user_can_approve($user, $check, $settings, $state),
        'canDelete' => checks_user_can_delete($user, $check, $settings, $state),
        'canManageMaterials' => checks_user_can_manage_materials($user, $check, $participantIds),
        'canAddGpNotes' => checks_user_can_add_gp_note($user, $check, $settings),
    ];
}

function checks_load_detail(PDO $pdo, array $state, array $user, array $settings, string $checkId): array
{
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $participantIds = checks_load_active_participant_ids($pdo, $checkId);
    if (!checks_user_can_view_check($user, $check, $participantIds, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для просмотра этой проверки']);
    }

    $participants = array_map(
        static fn($row) => checks_normalize_participant_row($row, $state),
        checks_load_active_participant_rows($pdo, $checkId)
    );
    $reports = checks_load_reports($pdo, $checkId, $state);
    $interviews = checks_load_interviews($pdo, $checkId, $state);
    $draftSummary = checks_load_summary_snapshot($pdo, $checkId, 'draft');
    if (!$draftSummary) {
        $draftSummary = checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    }
    $approvedSummary = checks_load_summary_snapshot($pdo, $checkId, 'approved');
    $gpNotes = checks_load_gp_notes($pdo, $checkId, $state);
    $audit = checks_load_audit($pdo, $checkId);
    if (!checks_user_can_view_management_sections($user, $check, $settings, $state)) {
        $draftSummary = null;
        $approvedSummary = null;
        $gpNotes = [];
        $audit = [];
    }
    if (!has_system_admin_access($user)) {
        $audit = [];
    }

    return [
        'check' => array_merge($check, [
            'factionName' => checks_get_faction_name($state, $check['factionId']),
            'createdByUser' => checks_user_snapshot(checks_find_user($state, $check['createdBy'])),
            'leadUser' => checks_user_snapshot(checks_find_user($state, $check['leadUserId'])),
        ]),
        'permissions' => checks_build_permissions_payload($user, $check, $participantIds, $settings, $state),
        'participants' => $participants,
        'reports' => $reports,
        'interviews' => $interviews,
        'draftSummary' => $draftSummary,
        'approvedSummary' => $approvedSummary,
        'gpNotes' => $gpNotes,
        'audit' => $audit,
    ];
}

function checks_scope_filter(array $checks, array $user, string $scope, PDO $pdo): array
{
    if ($scope === '') {
        return $checks;
    }

    $assignedIds = checks_user_assigned_check_ids($pdo, (string)($user['id'] ?? ''));
    return array_values(array_filter($checks, static function ($check) use ($user, $scope, $assignedIds) {
        return match ($scope) {
            'owned' => (($user['role'] ?? null) === 'FEDERAL')
                ? (($check['createdBy'] ?? '') === ($user['id'] ?? ''))
                : (($check['subject'] ?? '') === ($user['subject'] ?? '')),
            'assigned' => !empty($assignedIds[$check['id']]),
            'approved' => (($check['status'] ?? '') === 'approved'),
            default => true,
        };
    }));
}

function checks_filter_list(array $checks): array
{
    $status = trim((string)($_GET['status'] ?? ''));
    $subject = trim((string)($_GET['subject'] ?? ''));
    $factionId = trim((string)($_GET['factionId'] ?? ''));
    $month = trim((string)($_GET['month'] ?? ''));

    return array_values(array_filter($checks, static function ($check) use ($status, $subject, $factionId, $month) {
        if ($status !== '' && ($check['status'] ?? '') !== $status) {
            return false;
        }
        if ($subject !== '' && ($check['subject'] ?? '') !== $subject) {
            return false;
        }
        if ($factionId !== '' && ($check['factionId'] ?? '') !== $factionId) {
            return false;
        }
        if ($month !== '' && !str_starts_with((string)($check['startsAt'] ?? ''), $month)) {
            return false;
        }
        return true;
    }));
}

function checks_build_list_item(PDO $pdo, array $state, array $check): array
{
    $participantCount = (int)(checks_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM check_participants WHERE check_id = :check_id AND removed_at IS NULL', [':check_id' => $check['id']])['total'] ?? 0);
    $reportCount = (int)(checks_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM check_reports WHERE check_id = :check_id AND deleted_at IS NULL', [':check_id' => $check['id']])['total'] ?? 0);
    $interviewCount = (int)(checks_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM check_interviews WHERE check_id = :check_id AND deleted_at IS NULL', [':check_id' => $check['id']])['total'] ?? 0);

    return array_merge($check, [
        'factionName' => checks_get_faction_name($state, $check['factionId']),
        'participantCount' => $participantCount,
        'reportCount' => $reportCount,
        'interviewCount' => $interviewCount,
    ]);
}

function checks_upsert_person_profile(PDO $pdo, array $check, array $factionPerson): array
{
    $externalEmployeeId = trim((string)($factionPerson['externalEmployeeId'] ?? ''));
    $fullName = trim((string)($factionPerson['fullName'] ?? ''));
    $positionTitle = trim((string)($factionPerson['positionTitle'] ?? ''));

    if ($externalEmployeeId === '' || $fullName === '' || $positionTitle === '') {
        respond(422, ['ok' => false, 'error' => 'Для опроса укажите ФИО, ID и должность сотрудника фракции']);
    }

    $existing = checks_fetch_one(
        $pdo,
        'SELECT * FROM faction_person_profiles
         WHERE subject = :subject AND faction_id = :faction_id AND external_employee_id = :external_employee_id
         LIMIT 1',
        [
            ':subject' => $check['subject'],
            ':faction_id' => $check['factionId'],
            ':external_employee_id' => $externalEmployeeId,
        ]
    );

    if ($existing) {
        checks_execute(
            $pdo,
            'UPDATE faction_person_profiles
             SET full_name = :full_name, position_title = :position_title, last_seen_at = :last_seen_at
             WHERE id = :id',
            [
                ':full_name' => $fullName,
                ':position_title' => $positionTitle,
                ':last_seen_at' => checks_now_storage(),
                ':id' => $existing['id'],
            ]
        );

        return checks_fetch_one($pdo, 'SELECT * FROM faction_person_profiles WHERE id = :id LIMIT 1', [':id' => $existing['id']]) ?: $existing;
    }

    $id = checks_uuid();
    checks_execute(
        $pdo,
        'INSERT INTO faction_person_profiles (id, subject, faction_id, external_employee_id, full_name, position_title, first_seen_at, last_seen_at, source, meta_json)
         VALUES (:id, :subject, :faction_id, :external_employee_id, :full_name, :position_title, :first_seen_at, :last_seen_at, :source, :meta_json)',
        [
            ':id' => $id,
            ':subject' => $check['subject'],
            ':faction_id' => $check['factionId'],
            ':external_employee_id' => $externalEmployeeId,
            ':full_name' => $fullName,
            ':position_title' => $positionTitle,
            ':first_seen_at' => checks_now_storage(),
            ':last_seen_at' => checks_now_storage(),
            ':source' => 'manual',
            ':meta_json' => checks_json_encode([]),
        ]
    );

    return checks_fetch_one($pdo, 'SELECT * FROM faction_person_profiles WHERE id = :id LIMIT 1', [':id' => $id]) ?: [];
}

function checks_replace_interview_answers(PDO $pdo, string $interviewId, array $answers, array $actorUser): array
{
    checks_execute(
        $pdo,
        'UPDATE check_interview_answers SET deleted_at = :deleted_at, updated_at = :updated_at
         WHERE interview_id = :interview_id AND deleted_at IS NULL',
        [
            ':deleted_at' => checks_now_storage(),
            ':updated_at' => checks_now_storage(),
            ':interview_id' => $interviewId,
        ]
    );

    $normalizedAnswers = [];
    foreach ($answers as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $questionText = trim((string)($answer['questionText'] ?? ''));
        if ($questionText === '') {
            continue;
        }
        $scoreChoice = trim((string)($answer['scoreChoice'] ?? 'incorrect'));
        if (!in_array($scoreChoice, ['incorrect', 'partial', 'correct'], true)) {
            $scoreChoice = 'incorrect';
        }
        $scoreValue = checks_score_choice_to_value($scoreChoice);

        checks_execute(
            $pdo,
            'INSERT INTO check_interview_answers (id, interview_id, topic_code, topic_label, question_text, answer_text, score_choice, score_value, reviewer_comment, entered_by, created_at, updated_at, deleted_at)
             VALUES (:id, :interview_id, :topic_code, :topic_label, :question_text, :answer_text, :score_choice, :score_value, :reviewer_comment, :entered_by, :created_at, :updated_at, NULL)',
            [
                ':id' => checks_uuid(),
                ':interview_id' => $interviewId,
                ':topic_code' => trim((string)($answer['topicCode'] ?? '')) ?: null,
                ':topic_label' => trim((string)($answer['topicLabel'] ?? '')) ?: null,
                ':question_text' => $questionText,
                ':answer_text' => trim((string)($answer['answerText'] ?? '')),
                ':score_choice' => $scoreChoice,
                ':score_value' => $scoreValue,
                ':reviewer_comment' => trim((string)($answer['reviewerComment'] ?? '')) ?: null,
                ':entered_by' => $actorUser['id'] ?? '',
                ':created_at' => checks_now_storage(),
                ':updated_at' => checks_now_storage(),
            ]
        );

        $normalizedAnswers[] = [
            'questionText' => $questionText,
            'answerText' => trim((string)($answer['answerText'] ?? '')),
            'scoreChoice' => $scoreChoice,
            'scoreValue' => $scoreValue,
            'topicCode' => trim((string)($answer['topicCode'] ?? '')),
            'topicLabel' => trim((string)($answer['topicLabel'] ?? '')),
            'reviewerComment' => trim((string)($answer['reviewerComment'] ?? '')),
        ];
    }

    if (count($normalizedAnswers) === 0) {
        respond(422, ['ok' => false, 'error' => 'Добавьте минимум один вопрос в опросе сотрудника']);
    }

    return $normalizedAnswers;
}

function checks_recalculate_interview(PDO $pdo, string $interviewId, ?string $overrideConsequence, ?string $reviewerComment): void
{
    $answers = checks_fetch_all(
        $pdo,
        'SELECT score_value FROM check_interview_answers WHERE interview_id = :interview_id AND deleted_at IS NULL',
        [':interview_id' => $interviewId]
    );

    $totalScore = 0.0;
    foreach ($answers as $answer) {
        $totalScore += (float)($answer['score_value'] ?? 0);
    }
    $averageScore = count($answers) > 0 ? $totalScore / count($answers) : 0.0;
    $grade = checks_grade_from_score($totalScore);

    checks_execute(
        $pdo,
        'UPDATE check_interviews
         SET total_score = :total_score,
             average_score = :average_score,
             final_grade_code = :final_grade_code,
             final_grade_label = :final_grade_label,
             recommended_consequence = :recommended_consequence,
             override_consequence = :override_consequence,
             reviewer_comment = :reviewer_comment,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':total_score' => $totalScore,
            ':average_score' => $averageScore,
            ':final_grade_code' => $grade['code'],
            ':final_grade_label' => $grade['label'],
            ':recommended_consequence' => $grade['recommendedConsequence'],
            ':override_consequence' => trim((string)$overrideConsequence) !== '' ? trim((string)$overrideConsequence) : null,
            ':reviewer_comment' => trim((string)$reviewerComment) !== '' ? trim((string)$reviewerComment) : null,
            ':updated_at' => checks_now_storage(),
            ':id' => $interviewId,
        ]
    );
}

function checks_handle_list(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    checks_reconcile_statuses($pdo, $state);

    $checks = checks_list_rows_for_user($pdo, $state, $user, $settings);
    $scope = trim((string)($_GET['scope'] ?? ''));
    $checks = checks_scope_filter($checks, $user, $scope, $pdo);
    $checks = checks_filter_list($checks);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int)($_GET['perPage'] ?? 20)));
    $total = count($checks);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($checks, $offset, $perPage);
    $items = array_map(static fn($check) => checks_build_list_item($pdo, $state, $check), $slice);

    respond(200, [
        'ok' => true,
        'items' => $items,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'permissions' => [
            'canCreate' => checks_user_can_create($user, $settings, $state),
        ],
        'settings' => $settings,
    ]);
}

function checks_handle_get(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    if ($checkId === '') {
        respond(422, ['ok' => false, 'error' => 'Не указан идентификатор проверки']);
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_create(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    if (!checks_user_can_create($user, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Создание проверки доступно только прокурору субъекта тестового субъекта или сотруднику Генеральной прокуратуры']);
    }

    $body = read_json_body();
    $subject = (has_system_admin_access($user) || checks_is_federal_check_coordinator($user, $settings))
        ? trim((string)($body['subject'] ?? $user['subject'] ?? ''))
        : trim((string)($user['subject'] ?? ''));
    checks_validate_subject($subject, $settings);

    $factionId = trim((string)($body['factionId'] ?? ''));
    checks_validate_faction($state, $factionId);

    $basisText = trim((string)($body['basisText'] ?? ''));
    if ($basisText === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите основание проведения проверки']);
    }
    $basisLink = trim((string)($body['basisLink'] ?? ''));

    $startsAt = checks_datetime_to_storage((string)($body['startsAt'] ?? ''), true);
    $endsAt = checks_datetime_to_storage((string)($body['endsAt'] ?? ''));
    if ($endsAt !== null && strtotime($endsAt) < strtotime((string)$startsAt)) {
        respond(422, ['ok' => false, 'error' => 'Дата окончания не может быть раньше даты начала']);
    }

    $participantUsers = checks_validate_participant_ids(
        $state,
        $settings,
        $subject,
        is_array($body['participantUserIds'] ?? null) ? $body['participantUserIds'] : [],
        (string)($user['id'] ?? '')
    );

    $checkId = checks_uuid();
    $now = checks_now_storage();
    $period = checks_compute_reporting_period($startsAt);
    $generatedTypeCode = checks_generate_type_code($subject);

    $pdo->beginTransaction();
    try {
        checks_execute(
            $pdo,
            'INSERT INTO `checks` (id, subject, faction_id, status, type_code, type_label, basis_text, basis_link, description, notes_text, period_from, period_to, starts_at, ends_at, collection_closed_at, created_by, lead_user_id, created_at, updated_at)
             VALUES (:id, :subject, :faction_id, :status, :type_code, :type_label, :basis_text, :basis_link, :description, :notes_text, :period_from, :period_to, :starts_at, :ends_at, NULL, :created_by, :lead_user_id, :created_at, :updated_at)',
            [
                ':id' => $checkId,
                ':subject' => $subject,
                ':faction_id' => $factionId,
                ':status' => 'planned',
                ':type_code' => $generatedTypeCode,
                ':type_label' => trim((string)($body['typeLabel'] ?? '')) ?: null,
                ':basis_text' => $basisText,
                ':basis_link' => $basisLink ?: null,
                ':description' => trim((string)($body['description'] ?? '')) ?: null,
                ':notes_text' => trim((string)($body['notes'] ?? '')) ?: null,
                ':period_from' => $period['from'],
                ':period_to' => $period['to'],
                ':starts_at' => $startsAt,
                ':ends_at' => $endsAt,
                ':created_by' => (string)$user['id'],
                ':lead_user_id' => (string)$user['id'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        $check = checks_fetch_check($pdo, $checkId);
        if (!$check) {
            throw new RuntimeException('Не удалось создать проверку');
        }

        $participants = checks_sync_participants($pdo, $state, $check, $user, $participantUsers);
        checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
        checks_log_audit(
            $pdo,
            'check',
            $checkId,
            'created',
            $user,
            null,
            $check,
            ['participants' => array_map(static fn($participant) => $participant['userId'], $participants)]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_update(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Редактирование карточки проверки доступно только до её начала']);
    }

    $body = read_json_body();
    $factionId = trim((string)($body['factionId'] ?? $check['factionId']));
    checks_validate_faction($state, $factionId);
    $basisText = trim((string)($body['basisText'] ?? $check['basisText']));
    if ($basisText === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите основание проведения проверки']);
    }
    $basisLink = trim((string)($body['basisLink'] ?? $check['basisLink'] ?? ''));

    $startsAt = checks_datetime_to_storage((string)($body['startsAt'] ?? $check['startsAt']), true);
    $endsAt = checks_datetime_to_storage((string)($body['endsAt'] ?? $check['endsAt']));
    if ($endsAt !== null && strtotime($endsAt) < strtotime((string)$startsAt)) {
        respond(422, ['ok' => false, 'error' => 'Дата окончания не может быть раньше даты начала']);
    }

    $before = $check;
    $period = checks_compute_reporting_period($startsAt);
    checks_execute(
        $pdo,
        'UPDATE `checks`
         SET faction_id = :faction_id,
             basis_text = :basis_text,
             basis_link = :basis_link,
             description = :description,
             notes_text = :notes_text,
             period_from = :period_from,
             period_to = :period_to,
             starts_at = :starts_at,
             ends_at = :ends_at,
             type_code = :type_code,
             type_label = :type_label,
             updated_at = :updated_at,
             lock_version = lock_version + 1
         WHERE id = :id',
        [
            ':faction_id' => $factionId,
            ':basis_text' => $basisText,
            ':basis_link' => $basisLink ?: null,
            ':description' => trim((string)($body['description'] ?? $check['description'])) ?: null,
            ':notes_text' => trim((string)($body['notes'] ?? $check['notesText'])) ?: null,
            ':period_from' => $period['from'],
            ':period_to' => $period['to'],
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':type_code' => $check['typeCode'] !== '' ? $check['typeCode'] : checks_generate_type_code((string)($check['subject'] ?? '')),
            ':type_label' => trim((string)($body['typeLabel'] ?? $check['typeLabel'])) ?: null,
            ':updated_at' => checks_now_storage(),
            ':id' => $checkId,
        ]
    );

    $updated = checks_fetch_check($pdo, $checkId);
    if ($updated) {
        $participantRows = checks_load_active_participant_rows($pdo, $checkId);
        foreach ($participantRows as $participantRow) {
            checks_upsert_calendar_event(
                $pdo,
                (string)$participantRow['user_id'],
                $updated,
                checks_build_check_title($state, $updated),
                checks_build_check_description($state, $updated)
            );
        }
    }

    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check', $checkId, 'updated', $user, $before, $updated);

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_transition_status(PDO $pdo, array &$state, array $actorUser, string $checkId, string $nextStatus, string $auditAction, array $meta = []): array
{
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $before = $check;
    checks_execute(
        $pdo,
        'UPDATE `checks`
         SET status = :status,
             updated_at = :updated_at,
             lock_version = lock_version + 1,
             collection_closed_at = :collection_closed_at
         WHERE id = :id',
        [
            ':status' => $nextStatus,
            ':updated_at' => checks_now_storage(),
            ':collection_closed_at' => $nextStatus === 'completed' ? checks_now_storage() : ($check['collectionClosedAt'] ?? null),
            ':id' => $checkId,
        ]
    );

    $updated = checks_fetch_check($pdo, $checkId);
    if (!$updated) {
        respond(500, ['ok' => false, 'error' => 'Не удалось обновить статус проверки']);
    }

    $participantRows = checks_load_active_participant_rows($pdo, $checkId);
    foreach ($participantRows as $participantRow) {
        checks_upsert_calendar_event(
            $pdo,
            (string)$participantRow['user_id'],
            $updated,
            checks_build_check_title($state, $updated),
            checks_build_check_description($state, $updated)
        );
    }

    checks_refresh_draft_summary($pdo, $state, $checkId, $actorUser['id'] ?? null);
    checks_log_audit($pdo, 'check', $checkId, $auditAction, $actorUser, $before, $updated, $meta);
    return $updated;
}

function checks_handle_sync_participants(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Изменение состава участников доступно только до начала проверки']);
    }

    $body = read_json_body();
    $participantUsers = checks_validate_participant_ids(
        $state,
        $settings,
        (string)$check['subject'],
        is_array($body['participantUserIds'] ?? null) ? $body['participantUserIds'] : [],
        (string)($user['id'] ?? '')
    );

    $beforeRows = checks_load_active_participant_rows($pdo, $checkId);
    $beforeUserIds = array_map(static fn($row) => (string)$row['user_id'], $beforeRows);
    $participants = checks_sync_participants($pdo, $state, $check, $user, $participantUsers);
    $afterUserIds = array_map(static fn($participant) => (string)$participant['userId'], $participants);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit(
        $pdo,
        'check',
        $checkId,
        'participants_synced',
        $user,
        ['participants' => $beforeUserIds],
        ['participants' => $afterUserIds]
    );

    respond(200, [
        'ok' => true,
        'participants' => $participants,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_prepare_report_payload(array $body, ?array $existingReportRow = null): array
{
    $existingMetrics = $existingReportRow ? checks_json_decode_array($existingReportRow['quantitative_metrics_json'] ?? '') : [];
    $rawMetrics = is_array($body['quantitativeMetrics'] ?? null) ? $body['quantitativeMetrics'] : $existingMetrics;
    $reportMode = trim((string)($rawMetrics['__reportMode'] ?? '')) === 'employee' ? 'employee' : 'general';
    $commentText = trim((string)($body['commentText'] ?? $body['circumstancesText'] ?? ($existingReportRow['comment_text'] ?? $existingReportRow['circumstances_text'] ?? '')));
    if ($commentText === '') {
        respond(422, ['ok' => false, 'error' => 'Введите комментарий участника проверки']);
    }

    if ($reportMode === 'employee') {
        $employeeFullName = trim((string)($rawMetrics['__employeeFullName'] ?? ''));
        $employeeId = trim((string)($rawMetrics['__employeeId'] ?? ''));
        $employeeRank = trim((string)($rawMetrics['__employeeRank'] ?? ''));
        if ($employeeFullName === '' || $employeeId === '' || $employeeRank === '') {
            respond(422, ['ok' => false, 'error' => 'Для комментария по сотруднику укажите ФИО, ID и звание']);
        }
    } else {
        unset($rawMetrics['__employeeFullName'], $rawMetrics['__employeeId'], $rawMetrics['__employeeRank']);
    }

    $rawMetrics['__reportMode'] = $reportMode;

    return [
        'sectionCode' => $reportMode === 'employee' ? 'employee_comment' : 'general_comment',
        'sectionLabel' => $reportMode === 'employee' ? 'Комментарий по сотруднику' : 'Общий комментарий',
        'circumstancesText' => $commentText,
        'commentText' => $commentText,
        'rawMetrics' => $rawMetrics,
    ];
}

function checks_handle_report_create_v2(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $participantIds = checks_load_active_participant_ids($pdo, $checkId);
    if (!checks_user_can_manage_materials($user, $check, $participantIds)) {
        respond(403, ['ok' => false, 'error' => 'У вас нет прав на внесение материалов по этой проверке']);
    }
    if (($check['status'] ?? '') !== 'active') {
        respond(422, ['ok' => false, 'error' => 'Материалы можно вносить только в активной проверке']);
    }

    $body = read_json_body();
    $prepared = checks_prepare_report_payload($body);
    $reportId = checks_uuid();
    $now = checks_now_storage();
    checks_execute(
        $pdo,
        'INSERT INTO check_reports (
            id, check_id, author_user_id, section_code, section_label, circumstances_text, violations_text,
            staff_actions_text, quantitative_metrics_json, comment_text, created_at, updated_at, deleted_at
         ) VALUES (
            :id, :check_id, :author_user_id, :section_code, :section_label, :circumstances_text, :violations_text,
            :staff_actions_text, :quantitative_metrics_json, :comment_text, :created_at, :updated_at, NULL
         )',
        [
            ':id' => $reportId,
            ':check_id' => $checkId,
            ':author_user_id' => $user['id'] ?? '',
            ':section_code' => $prepared['sectionCode'],
            ':section_label' => $prepared['sectionLabel'],
            ':circumstances_text' => $prepared['circumstancesText'],
            ':violations_text' => null,
            ':staff_actions_text' => null,
            ':quantitative_metrics_json' => checks_json_encode($prepared['rawMetrics']),
            ':comment_text' => $prepared['commentText'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $report = checks_report_by_id($pdo, $checkId, $reportId);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check_report', $reportId, 'created', $user, null, $report, ['checkId' => $checkId]);

    respond(200, [
        'ok' => true,
        'report' => $report ? checks_normalize_report_row($report, [], $state) : null,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_report_update_v2(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $reportId = trim((string)($_GET['reportId'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    $report = checks_report_by_id($pdo, $checkId, $reportId);
    if (!$check || !$report) {
        respond(404, ['ok' => false, 'error' => 'Отчёт проверки не найден']);
    }
    if (($check['status'] ?? '') === 'approved') {
        respond(422, ['ok' => false, 'error' => 'Утверждённую проверку редактировать нельзя']);
    }
    if (($report['author_user_id'] ?? '') !== ($user['id'] ?? '') && !checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Можно редактировать только свои отчёты или материалы под правами прокурора субъекта']);
    }

    $body = read_json_body();
    $prepared = checks_prepare_report_payload($body, $report);
    $before = checks_normalize_report_row($report, [], $state);
    checks_execute(
        $pdo,
        'UPDATE check_reports
         SET section_code = :section_code,
             section_label = :section_label,
             circumstances_text = :circumstances_text,
             violations_text = :violations_text,
             staff_actions_text = :staff_actions_text,
             quantitative_metrics_json = :quantitative_metrics_json,
             comment_text = :comment_text,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':section_code' => $prepared['sectionCode'],
            ':section_label' => $prepared['sectionLabel'],
            ':circumstances_text' => $prepared['circumstancesText'],
            ':violations_text' => null,
            ':staff_actions_text' => null,
            ':quantitative_metrics_json' => checks_json_encode($prepared['rawMetrics']),
            ':comment_text' => $prepared['commentText'],
            ':updated_at' => checks_now_storage(),
            ':id' => $reportId,
        ]
    );

    $updated = checks_report_by_id($pdo, $checkId, $reportId);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check_report', $reportId, 'updated', $user, $before, $updated ? checks_normalize_report_row($updated, [], $state) : null, ['checkId' => $checkId]);

    respond(200, [
        'ok' => true,
        'report' => $updated ? checks_normalize_report_row($updated, [], $state) : null,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_report_create(PDO $pdo, array &$state): void
{
    checks_handle_report_create_v2($pdo, $state);
    return;
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $participantIds = checks_load_active_participant_ids($pdo, $checkId);
    if (!checks_user_can_manage_materials($user, $check, $participantIds)) {
        respond(403, ['ok' => false, 'error' => 'У вас нет прав на внесение материалов по этой проверке']);
    }
    if (($check['status'] ?? '') !== 'active') {
        respond(422, ['ok' => false, 'error' => 'Материалы можно вносить только в активной проверке']);
    }

    $body = read_json_body();
    $sectionLabel = trim((string)($body['sectionLabel'] ?? ''));
    $circumstancesText = trim((string)($body['circumstancesText'] ?? ''));
    if ($sectionLabel === '' || $circumstancesText === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите раздел и описание выявленных обстоятельств']);
    }

    $reportId = checks_uuid();
    $now = checks_now_storage();
    checks_execute(
        $pdo,
        'INSERT INTO check_reports (
            id, check_id, author_user_id, section_code, section_label, circumstances_text, violations_text,
            staff_actions_text, quantitative_metrics_json, comment_text, created_at, updated_at, deleted_at
         ) VALUES (
            :id, :check_id, :author_user_id, :section_code, :section_label, :circumstances_text, :violations_text,
            :staff_actions_text, :quantitative_metrics_json, :comment_text, :created_at, :updated_at, NULL
         )',
        [
            ':id' => $reportId,
            ':check_id' => $checkId,
            ':author_user_id' => $user['id'] ?? '',
            ':section_code' => trim((string)($body['sectionCode'] ?? '')) ?: null,
            ':section_label' => $sectionLabel,
            ':circumstances_text' => $circumstancesText,
            ':violations_text' => trim((string)($body['violationsText'] ?? '')) ?: null,
            ':staff_actions_text' => trim((string)($body['staffActionsText'] ?? '')) ?: null,
            ':quantitative_metrics_json' => checks_json_encode(is_array($body['quantitativeMetrics'] ?? null) ? $body['quantitativeMetrics'] : []),
            ':comment_text' => trim((string)($body['commentText'] ?? '')) ?: null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $report = checks_report_by_id($pdo, $checkId, $reportId);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check_report', $reportId, 'created', $user, null, $report, ['checkId' => $checkId]);

    respond(200, [
        'ok' => true,
        'report' => $report ? checks_normalize_report_row($report, $state) : null,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_report_update(PDO $pdo, array &$state): void
{
    checks_handle_report_update_v2($pdo, $state);
    return;
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $reportId = trim((string)($_GET['reportId'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    $report = checks_report_by_id($pdo, $checkId, $reportId);
    if (!$check || !$report) {
        respond(404, ['ok' => false, 'error' => 'Отчёт проверки не найден']);
    }
    if (($check['status'] ?? '') === 'approved') {
        respond(422, ['ok' => false, 'error' => 'Утверждённую проверку редактировать нельзя']);
    }
    if (($report['author_user_id'] ?? '') !== ($user['id'] ?? '') && !checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Можно редактировать только свои отчёты или материалы под правами прокурора субъекта']);
    }

    $body = read_json_body();
    $sectionLabel = trim((string)($body['sectionLabel'] ?? $report['section_label'] ?? ''));
    $circumstancesText = trim((string)($body['circumstancesText'] ?? $report['circumstances_text'] ?? ''));
    if ($sectionLabel === '' || $circumstancesText === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите раздел и описание выявленных обстоятельств']);
    }

    $before = checks_normalize_report_row($report, $state);
    checks_execute(
        $pdo,
        'UPDATE check_reports
         SET section_code = :section_code,
             section_label = :section_label,
             circumstances_text = :circumstances_text,
             violations_text = :violations_text,
             staff_actions_text = :staff_actions_text,
             quantitative_metrics_json = :quantitative_metrics_json,
             comment_text = :comment_text,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':section_code' => trim((string)($body['sectionCode'] ?? $report['section_code'] ?? '')) ?: null,
            ':section_label' => $sectionLabel,
            ':circumstances_text' => $circumstancesText,
            ':violations_text' => trim((string)($body['violationsText'] ?? $report['violations_text'] ?? '')) ?: null,
            ':staff_actions_text' => trim((string)($body['staffActionsText'] ?? $report['staff_actions_text'] ?? '')) ?: null,
            ':quantitative_metrics_json' => checks_json_encode(is_array($body['quantitativeMetrics'] ?? null) ? $body['quantitativeMetrics'] : checks_json_decode_array($report['quantitative_metrics_json'] ?? '')),
            ':comment_text' => trim((string)($body['commentText'] ?? $report['comment_text'] ?? '')) ?: null,
            ':updated_at' => checks_now_storage(),
            ':id' => $reportId,
        ]
    );

    $updated = checks_report_by_id($pdo, $checkId, $reportId);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check_report', $reportId, 'updated', $user, $before, $updated ? checks_normalize_report_row($updated, $state) : null, ['checkId' => $checkId]);

    respond(200, [
        'ok' => true,
        'report' => $updated ? checks_normalize_report_row($updated, $state) : null,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_report_upload(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_POST['checkId'] ?? $_GET['id'] ?? ''));
    $reportId = trim((string)($_POST['reportId'] ?? $_GET['reportId'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    $report = checks_report_by_id($pdo, $checkId, $reportId);
    if (!$check || !$report) {
        respond(404, ['ok' => false, 'error' => 'Отчёт проверки не найден']);
    }
    if (($check['status'] ?? '') === 'approved') {
        respond(422, ['ok' => false, 'error' => 'После утверждения отчёта вложения не редактируются']);
    }
    if (($report['author_user_id'] ?? '') !== ($user['id'] ?? '') && !checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Можно загружать вложения только в свои отчёты или под правами прокурора субъекта']);
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        respond(422, ['ok' => false, 'error' => 'Прикрепите файл отчёта']);
    }

    $file = $_FILES['file'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        respond(422, ['ok' => false, 'error' => 'Не удалось загрузить файл отчёта']);
    }
    $sizeBytes = (int)($file['size'] ?? 0);
    if ($sizeBytes <= 0 || $sizeBytes > 20 * 1024 * 1024) {
        respond(422, ['ok' => false, 'error' => 'Файл должен быть не пустым и не больше 20 МБ']);
    }
    if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        respond(422, ['ok' => false, 'error' => 'Некорректный источник файла']);
    }

    $extension = checks_resolve_attachment_extension($file);
    if ($extension === null) {
        respond(422, ['ok' => false, 'error' => 'Поддерживаются PNG, JPG, WEBP и PDF']);
    }

    $directoryInfo = checks_upload_dir($checkId);
    $filename = sprintf('check_%s_%s.%s', preg_replace('/[^A-Za-z0-9_-]/', '', $reportId), gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)), $extension);
    $absolutePath = $directoryInfo['absolute'] . DIRECTORY_SEPARATOR . $filename;
    $relativePath = $directoryInfo['relative'] . '/' . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
        respond(500, ['ok' => false, 'error' => 'Не удалось сохранить файл отчёта']);
    }

    $fileId = checks_uuid();
    checks_execute(
        $pdo,
        'INSERT INTO check_report_files (id, report_id, storage_path, original_name, mime_type, size_bytes, uploaded_by, uploaded_at, deleted_at)
         VALUES (:id, :report_id, :storage_path, :original_name, :mime_type, :size_bytes, :uploaded_by, :uploaded_at, NULL)',
        [
            ':id' => $fileId,
            ':report_id' => $reportId,
            ':storage_path' => $relativePath,
            ':original_name' => (string)($file['name'] ?? ''),
            ':mime_type' => (string)($file['type'] ?? ''),
            ':size_bytes' => $sizeBytes,
            ':uploaded_by' => $user['id'] ?? '',
            ':uploaded_at' => checks_now_storage(),
        ]
    );

    $detail = checks_load_detail($pdo, $state, $user, $settings, $checkId);
    $uploaded = null;
    foreach (($detail['reports'] ?? []) as $detailReport) {
        if (($detailReport['id'] ?? '') !== $reportId) {
            continue;
        }
        foreach (($detailReport['files'] ?? []) as $item) {
            if (($item['id'] ?? '') === $fileId) {
                $uploaded = $item;
                break 2;
            }
        }
    }

    checks_log_audit($pdo, 'check_report_file', $fileId, 'uploaded', $user, null, $uploaded, ['checkId' => $checkId, 'reportId' => $reportId]);
    respond(200, [
        'ok' => true,
        'file' => $uploaded,
        'detail' => $detail,
    ]);
}

function checks_handle_interview_upsert(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    $participantIds = checks_load_active_participant_ids($pdo, $checkId);
    if (!checks_user_can_manage_materials($user, $check, $participantIds)) {
        respond(403, ['ok' => false, 'error' => 'Нет прав для внесения результатов опроса']);
    }
    if (($check['status'] ?? '') === 'approved') {
        respond(422, ['ok' => false, 'error' => 'Утверждённую проверку редактировать нельзя']);
    }

    $body = read_json_body();
    $interviewId = trim((string)($body['interviewId'] ?? ''));
    $factionPerson = is_array($body['factionPerson'] ?? null) ? $body['factionPerson'] : [];
    $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
    $reviewerComment = trim((string)($body['reviewerComment'] ?? ''));
    $overrideConsequence = trim((string)($body['overrideConsequence'] ?? ''));
    $personProfile = checks_upsert_person_profile($pdo, $check, $factionPerson);

    $existing = $interviewId !== '' ? checks_interview_by_id($pdo, $checkId, $interviewId) : null;
    if ($existing && (($existing['entered_by'] ?? '') !== ($user['id'] ?? '') && !checks_user_can_edit_check_metadata($user, $check, $settings, $state))) {
        respond(403, ['ok' => false, 'error' => 'Можно редактировать только свои результаты опроса или материалы под правами прокурора субъекта']);
    }

    $now = checks_now_storage();
    if ($existing) {
        $before = checks_normalize_interview_row($existing, $state);
        checks_execute(
            $pdo,
            'UPDATE check_interviews
             SET faction_person_id = :faction_person_id,
                 reviewer_comment = :reviewer_comment,
                 override_consequence = :override_consequence,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':faction_person_id' => $personProfile['id'],
                ':reviewer_comment' => $reviewerComment !== '' ? $reviewerComment : null,
                ':override_consequence' => $overrideConsequence !== '' ? $overrideConsequence : null,
                ':updated_at' => $now,
                ':id' => $existing['id'],
            ]
        );
        $interviewId = (string)$existing['id'];
        if (count($answers) > 0) {
            checks_replace_interview_answers($pdo, $interviewId, $answers, $user);
        }
        checks_recalculate_interview($pdo, $interviewId, $overrideConsequence, $reviewerComment);
        $updated = checks_interview_by_id($pdo, $checkId, $interviewId);
        checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
        checks_log_audit($pdo, 'check_interview', $interviewId, 'updated', $user, $before, $updated ? checks_normalize_interview_row($updated, $state) : null, ['checkId' => $checkId]);
    } else {
        $interviewId = checks_uuid();
        checks_execute(
            $pdo,
            'INSERT INTO check_interviews (
                id, check_id, faction_person_id, entered_by, total_score, average_score, final_grade_code, final_grade_label,
                recommended_consequence, override_consequence, reviewer_comment, created_at, updated_at, deleted_at
             ) VALUES (
                :id, :check_id, :faction_person_id, :entered_by, 0, 0, :final_grade_code, :final_grade_label,
                :recommended_consequence, :override_consequence, :reviewer_comment, :created_at, :updated_at, NULL
             )',
            [
                ':id' => $interviewId,
                ':check_id' => $checkId,
                ':faction_person_id' => $personProfile['id'],
                ':entered_by' => $user['id'] ?? '',
                ':final_grade_code' => '2',
                ':final_grade_label' => '2',
                ':recommended_consequence' => 'Увольнение либо дисциплинарное взыскание',
                ':override_consequence' => $overrideConsequence !== '' ? $overrideConsequence : null,
                ':reviewer_comment' => $reviewerComment !== '' ? $reviewerComment : null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
        $normalizedAnswers = checks_replace_interview_answers($pdo, $interviewId, $answers, $user);
        checks_recalculate_interview($pdo, $interviewId, $overrideConsequence, $reviewerComment);
        $created = checks_interview_by_id($pdo, $checkId, $interviewId);
        checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
        checks_log_audit($pdo, 'check_interview', $interviewId, 'created', $user, null, [
            'interviewId' => $interviewId,
            'factionPersonId' => $personProfile['id'] ?? null,
            'answersCount' => count($normalizedAnswers),
        ], ['checkId' => $checkId]);
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_interview_answers_upsert(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $interviewId = trim((string)($_GET['interviewId'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    $interview = checks_interview_by_id($pdo, $checkId, $interviewId);
    if (!$check || !$interview) {
        respond(404, ['ok' => false, 'error' => 'Опрос не найден']);
    }
    if (($check['status'] ?? '') === 'approved') {
        respond(422, ['ok' => false, 'error' => 'Утверждённую проверку редактировать нельзя']);
    }
    if (($interview['entered_by'] ?? '') !== ($user['id'] ?? '') && !checks_user_can_edit_check_metadata($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Можно редактировать только свои записи опроса или материалы под правами прокурора субъекта']);
    }

    $body = read_json_body();
    $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
    $overrideConsequence = trim((string)($body['overrideConsequence'] ?? ($interview['override_consequence'] ?? '')));
    $reviewerComment = trim((string)($body['reviewerComment'] ?? ($interview['reviewer_comment'] ?? '')));
    $before = checks_normalize_interview_row($interview, $state);
    checks_replace_interview_answers($pdo, $interviewId, $answers, $user);
    checks_recalculate_interview($pdo, $interviewId, $overrideConsequence, $reviewerComment);
    $updated = checks_interview_by_id($pdo, $checkId, $interviewId);
    checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
    checks_log_audit($pdo, 'check_interview', $interviewId, 'answers_updated', $user, $before, $updated ? checks_normalize_interview_row($updated, $state) : null, ['checkId' => $checkId]);

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_summary(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    checks_reconcile_statuses($pdo, $state);
    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_submit_approval(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_submit_for_approval($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Отправить проверку на утверждение может только прокурор субъекта']);
    }
    if (($check['status'] ?? '') !== 'completed') {
        respond(422, ['ok' => false, 'error' => 'На утверждение можно отправить только завершённую проверку']);
    }

    $updated = checks_transition_status($pdo, $state, $user, $checkId, 'pending_approval', 'submitted_for_approval');
    $participants = checks_load_active_participant_rows($pdo, $checkId);
    foreach ($participants as $participantRow) {
        checks_create_notification(
            $pdo,
            (string)$participantRow['user_id'],
            'check_pending_approval',
            'check',
            $checkId,
            'Проверка переведена на этап утверждения',
            checks_build_check_title($state, $updated) . ' ожидает итогового решения прокурора субъекта.',
            'warning',
            'checks',
            ['checkId' => $checkId]
        );
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_activate(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_activate_check($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для запуска проверки']);
    }
    if (($check['status'] ?? '') !== 'planned') {
        respond(422, ['ok' => false, 'error' => 'Запустить можно только запланированную проверку']);
    }

    $updated = checks_transition_status($pdo, $state, $user, $checkId, 'active', 'activated_manual');
    $participants = checks_load_active_participant_rows($pdo, $checkId);
    foreach ($participants as $participantRow) {
        checks_create_notification(
            $pdo,
            (string)$participantRow['user_id'],
            'check_started',
            'check',
            $checkId,
            'Проверка переведена в активный статус',
            checks_build_check_title($state, $updated) . ' доступна для внесения материалов.',
            'warning',
            'checks',
            ['checkId' => $checkId]
        );
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_complete(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_complete_check($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для завершения сбора материалов']);
    }
    if (($check['status'] ?? '') !== 'active') {
        respond(422, ['ok' => false, 'error' => 'Завершить можно только активную проверку']);
    }

    checks_transition_status($pdo, $state, $user, $checkId, 'completed', 'completed_materials');
    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_reopen(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_edit_check_metadata($user, $check, $settings, $state) && !checks_user_can_approve($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для возврата проверки в работу']);
    }
    if (!in_array((string)($check['status'] ?? ''), ['completed', 'pending_approval'], true)) {
        respond(422, ['ok' => false, 'error' => 'Вернуть в работу можно только завершённую или ожидающую утверждения проверку']);
    }

    checks_transition_status($pdo, $state, $user, $checkId, 'active', 'reopened');
    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_approve(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_approve($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Утвердить проверку может только прокурор субъекта']);
    }
    if (!in_array((string)($check['status'] ?? ''), ['completed', 'pending_approval'], true)) {
        respond(422, ['ok' => false, 'error' => 'Утверждение доступно только для завершённой проверки']);
    }

    $body = read_json_body();
    $finalRating = trim((string)($body['finalRating'] ?? ''));
    $finalConclusion = trim((string)($body['finalConclusion'] ?? ''));
    $resolutionText = trim((string)($body['resolutionText'] ?? ''));
    if (!array_key_exists($finalRating, CHECKS_FINAL_RATINGS)) {
        respond(422, ['ok' => false, 'error' => 'Выберите итоговую оценку проверки']);
    }
    if ($finalConclusion === '') {
        respond(422, ['ok' => false, 'error' => 'Заполните итоговое заключение прокурора субъекта']);
    }

    $pdo->beginTransaction();
    try {
        $current = checks_fetch_check($pdo, $checkId);
        if (!$current) {
            respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
        }

        $draft = checks_refresh_draft_summary($pdo, $state, $checkId, $user['id'] ?? null);
        $approvedSummaryPayload = $draft['summary'] ?? [];
        $approvedSummaryPayload['approval'] = [
            'finalRating' => $finalRating,
            'finalRatingLabel' => checks_final_rating_label($finalRating),
            'finalConclusion' => $finalConclusion,
            'resolutionText' => $resolutionText,
            'approvedBy' => checks_user_snapshot($user),
            'approvedAt' => checks_normalize_datetime_output(checks_now_storage()),
        ];

        $approvedSnapshot = checks_upsert_summary_snapshot($pdo, $checkId, 'approved', $approvedSummaryPayload, 'approved', $user['id'] ?? null);
        checks_execute(
            $pdo,
            'UPDATE `checks`
             SET status = :status,
                 final_rating = :final_rating,
                 final_conclusion = :final_conclusion,
                 resolution_text = :resolution_text,
                 approved_snapshot_id = :approved_snapshot_id,
                 approved_by = :approved_by,
                 approved_at = :approved_at,
                 updated_at = :updated_at,
                 lock_version = lock_version + 1
             WHERE id = :id',
            [
                ':status' => 'approved',
                ':final_rating' => $finalRating,
                ':final_conclusion' => $finalConclusion,
                ':resolution_text' => $resolutionText !== '' ? $resolutionText : null,
                ':approved_snapshot_id' => $approvedSnapshot['id'] ?? null,
                ':approved_by' => $user['id'] ?? '',
                ':approved_at' => checks_now_storage(),
                ':updated_at' => checks_now_storage(),
                ':id' => $checkId,
            ]
        );

        checks_execute(
            $pdo,
            'INSERT INTO check_approvals (id, check_id, approved_snapshot_id, final_rating, final_conclusion, resolution_text, approved_by, approved_at, created_at)
             VALUES (:id, :check_id, :approved_snapshot_id, :final_rating, :final_conclusion, :resolution_text, :approved_by, :approved_at, :created_at)',
            [
                ':id' => checks_uuid(),
                ':check_id' => $checkId,
                ':approved_snapshot_id' => $approvedSnapshot['id'] ?? null,
                ':final_rating' => $finalRating,
                ':final_conclusion' => $finalConclusion,
                ':resolution_text' => $resolutionText !== '' ? $resolutionText : null,
                ':approved_by' => $user['id'] ?? '',
                ':approved_at' => checks_now_storage(),
                ':created_at' => checks_now_storage(),
            ]
        );

        $updated = checks_fetch_check($pdo, $checkId);
        if (!$updated) {
            throw new RuntimeException('Не удалось зафиксировать утверждение проверки');
        }

        $participants = checks_load_active_participant_rows($pdo, $checkId);
        foreach ($participants as $participantRow) {
            checks_upsert_calendar_event(
                $pdo,
                (string)$participantRow['user_id'],
                $updated,
                checks_build_check_title($state, $updated),
                checks_build_check_description($state, $updated)
            );
        }

        foreach (($state['users'] ?? []) as $possibleUser) {
            if (($possibleUser['role'] ?? null) !== 'FEDERAL' && !has_system_admin_access($possibleUser)) {
                continue;
            }
            checks_create_notification(
                $pdo,
                (string)$possibleUser['id'],
                'check_approved',
                'check',
                $checkId,
                'Утверждён итоговый отчёт проверки',
                checks_build_check_title($state, $updated) . ' передана для просмотра в Генеральную прокуратуру.',
                'warning',
                'checks',
                ['checkId' => $checkId]
            );
        }

        checks_log_audit(
            $pdo,
            'check',
            $checkId,
            'approved',
            $user,
            ['status' => $check['status'], 'finalRating' => $check['finalRating'], 'finalConclusion' => $check['finalConclusion']],
            ['status' => 'approved', 'finalRating' => $finalRating, 'finalConclusion' => $finalConclusion]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_delete(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    if (!has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для удаления проверки']);
    }

    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_delete($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для удаления проверки']);
    }

    $body = read_json_body();
    $password = trim((string)($body['password'] ?? ''));
    if ($password === '' || !defined('MAINTENANCE_DANGER_ACTION_PASSWORD') || !hash_equals(MAINTENANCE_DANGER_ACTION_PASSWORD, $password)) {
        respond(403, ['ok' => false, 'error' => 'Неверный служебный пароль']);
    }

    $rawCheck = checks_fetch_raw_check($pdo, $checkId);
    if (!$rawCheck) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }

    $deletedAt = checks_now_storage();
    $participantRows = checks_load_active_participant_rows($pdo, $checkId);

    $pdo->beginTransaction();
    try {
        checks_execute(
            $pdo,
            'UPDATE `checks`
             SET deleted_at = :deleted_at,
                 updated_at = :updated_at,
                 lock_version = lock_version + 1
             WHERE id = :id AND deleted_at IS NULL',
            [
                ':deleted_at' => $deletedAt,
                ':updated_at' => $deletedAt,
                ':id' => $checkId,
            ]
        );

        checks_execute(
            $pdo,
            'UPDATE calendar_events
             SET deleted_at = :deleted_at,
                 updated_at = :updated_at
             WHERE entity_type = :entity_type AND entity_id = :entity_id AND deleted_at IS NULL',
            [
                ':deleted_at' => $deletedAt,
                ':updated_at' => $deletedAt,
                ':entity_type' => 'check',
                ':entity_id' => $checkId,
            ]
        );

        checks_mark_notifications_deleted($pdo, 'check', $checkId);

        checks_log_audit(
            $pdo,
            'check',
            $checkId,
            'deleted',
            $user,
            checks_normalize_check_row($rawCheck),
            ['deletedAt' => checks_normalize_datetime_output($deletedAt)],
            [
                'participantUserIds' => array_values(array_map(
                    static fn($row) => (string)($row['user_id'] ?? ''),
                    $participantRows
                )),
            ]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    respond(200, [
        'ok' => true,
        'deletedCheckId' => $checkId,
    ]);
}

function checks_handle_final_report(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (!checks_user_can_view_management_sections($user, $check, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для просмотра итогового отчёта']);
    }
    $detail = checks_load_detail($pdo, $state, $user, $settings, $checkId);
    $report = $detail['approvedSummary'] ?: $detail['draftSummary'];
    respond(200, [
        'ok' => true,
        'report' => $report,
        'detail' => $detail,
    ]);
}

function checks_handle_gp_note_create(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $checkId = trim((string)($_GET['id'] ?? ''));
    $check = checks_fetch_check($pdo, $checkId);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка не найдена']);
    }
    if (($check['status'] ?? '') !== 'approved') {
        respond(422, ['ok' => false, 'error' => 'Служебные пометки ГП доступны только после утверждения отчёта']);
    }
    if (!checks_user_can_add_gp_note($user, $check, $settings)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для добавления пометки']);
    }

    $body = read_json_body();
    $noteText = trim((string)($body['noteText'] ?? ''));
    if ($noteText === '') {
        respond(422, ['ok' => false, 'error' => 'Введите текст служебной пометки']);
    }

    $noteId = checks_uuid();
    checks_execute(
        $pdo,
        'INSERT INTO check_gp_notes (id, check_id, note_text, created_by, created_at, visibility, deleted_at)
         VALUES (:id, :check_id, :note_text, :created_by, :created_at, :visibility, NULL)',
        [
            ':id' => $noteId,
            ':check_id' => $checkId,
            ':note_text' => $noteText,
            ':created_by' => $user['id'] ?? '',
            ':created_at' => checks_now_storage(),
            ':visibility' => 'internal_gp',
        ]
    );

    $note = checks_fetch_one($pdo, 'SELECT * FROM check_gp_notes WHERE id = :id LIMIT 1', [':id' => $noteId]);
    checks_log_audit($pdo, 'check_gp_note', $noteId, 'created', $user, null, $note, ['checkId' => $checkId]);
    respond(200, [
        'ok' => true,
        'detail' => checks_load_detail($pdo, $state, $user, $settings, $checkId),
    ]);
}

function checks_handle_notifications_list(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    respond(200, [
        'ok' => true,
        'items' => checks_build_bootstrap_notifications($pdo, $user),
    ]);
}

function checks_handle_notifications_broadcast(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    if (!has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim((string)($body['title'] ?? ''));
    $text = trim((string)($body['body'] ?? ''));
    $priority = in_array($body['priority'] ?? '', ['info', 'warning', 'critical'], true) ? $body['priority'] : 'info';
    $recipientFilter = $body['recipientFilter'] ?? 'all';
    $recipientValue = trim((string)($body['recipientValue'] ?? ''));

    if ($title === '' || $text === '') {
        respond(422, ['ok' => false, 'error' => 'Заголовок и текст обязательны']);
    }

    $recipients = [];
    $allUsers = $state['users'] ?? [];
    if ($recipientFilter === 'all') {
        $recipients = $allUsers;
    } elseif ($recipientFilter === 'role' && $recipientValue !== '') {
        foreach ($allUsers as $u) {
            if (($u['role'] ?? '') === $recipientValue) {
                $recipients[] = $u;
            }
        }
    } elseif ($recipientFilter === 'user' && $recipientValue !== '') {
        foreach ($allUsers as $u) {
            if (($u['id'] ?? '') === $recipientValue) {
                $recipients[] = $u;
                break;
            }
        }
    }

    $count = 0;
    foreach ($recipients as $r) {
        $rid = (string)($r['id'] ?? '');
        if ($rid === '' || $rid === ($user['id'] ?? '')) {
            continue;
        }
        checks_create_notification($pdo, $rid, 'broadcast', 'system', 'broadcast', $title, $text, $priority, 'notifications');
        $count++;
    }

    respond(200, ['ok' => true, 'sent' => $count]);
}

function checks_handle_notifications_clear_read(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    if (!has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('UPDATE notifications SET deleted_at = :deleted_at WHERE read_at IS NOT NULL AND deleted_at IS NULL');
    $stmt->execute([':deleted_at' => $now]);
    $cleared = $stmt->rowCount();
    respond(200, ['ok' => true, 'cleared' => $cleared]);
}

function checks_handle_notifications_stats(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    if (!has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }
    $row = checks_fetch_one($pdo,
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS `read`
         FROM notifications WHERE deleted_at IS NULL',
        []
    );
    respond(200, [
        'ok' => true,
        'stats' => [
            'total' => (int)($row['total'] ?? 0),
            'unread' => (int)($row['unread'] ?? 0),
            'read' => (int)($row['read'] ?? 0),
        ],
    ]);
}

function checks_handle_notification_dismiss(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $notificationId = trim((string)($body['notificationId'] ?? ''));
    if ($notificationId === '') {
        respond(422, ['ok' => false, 'error' => 'Не указан ID уведомления']);
    }
    $stmt = $pdo->prepare('UPDATE notifications SET read_at = :read_at WHERE id = :id AND recipient_user_id = :uid AND read_at IS NULL');
    $stmt->execute([':read_at' => gmdate('Y-m-d H:i:s'), ':id' => $notificationId, ':uid' => $user['id']]);
    respond(200, ['ok' => true]);
}

function calendar_row_to_array(array $row, ?string $currentUserId = null): array
{
    $item = [
        'id' => (string)$row['id'],
        'entityType' => (string)$row['entity_type'],
        'entityId' => (string)$row['entity_id'],
        'title' => (string)$row['title'],
        'description' => (string)($row['description'] ?? ''),
        'startsAt' => checks_normalize_datetime_output($row['starts_at'] ?? null),
        'endsAt' => checks_normalize_datetime_output($row['ends_at'] ?? null),
        'statusLabel' => (string)$row['status_label'],
    ];
    if (($row['entity_type'] ?? '') === 'manual') {
        $item['creatorUserId'] = (string)($row['creator_user_id'] ?? '');
        $item['visibility'] = (string)($row['visibility'] ?? 'private');
        $item['color'] = (string)($row['color'] ?? 'other');
        $item['targetUserId'] = (string)($row['target_user_id'] ?? '');
        if ($currentUserId !== null) {
            $item['isOwner'] = $item['creatorUserId'] === $currentUserId;
        }
    }
    return $item;
}

function checks_build_full_calendar(PDO $pdo, array $user, string $month, array $state = []): array
{
    $isGlobal = has_system_admin_access($user) || ($user['role'] ?? '') === 'FEDERAL' || ($user['subject'] ?? '') === GENERAL_SUBJECT;
    $fromDate = $month . '-01 00:00:00';
    $toDate = (new DateTimeImmutable($fromDate))->modify('+1 month +7 days')->format('Y-m-d H:i:s');

    if ($isGlobal) {
        $rows = checks_fetch_all($pdo,
            'SELECT * FROM calendar_events WHERE deleted_at IS NULL AND starts_at >= :from_date AND starts_at <= :to_date ORDER BY starts_at ASC LIMIT 200',
            [':from_date' => $fromDate, ':to_date' => $toDate]
        );
    } else {
        $rows = checks_fetch_all($pdo,
            'SELECT * FROM calendar_events WHERE recipient_user_id = :uid AND deleted_at IS NULL AND starts_at >= :from_date AND starts_at <= :to_date ORDER BY starts_at ASC LIMIT 200',
            [':uid' => $user['id'], ':from_date' => $fromDate, ':to_date' => $toDate]
        );
    }

    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
        $key = $row['entity_type'] . ':' . $row['entity_id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $row;
        }
    }

    $caseEvents = cases_build_calendar_events($pdo, $user, $isGlobal, $fromDate);
    $checkTableEvents = checks_build_calendar_from_checks_table($pdo, $user, $isGlobal, $fromDate, $state);

    $result = [];
    $seenEntities = [];
    foreach ($unique as $row) {
        $result[] = calendar_row_to_array($row, $user['id']);
        $seenEntities[$row['entity_type'] . ':' . $row['entity_id']] = true;
    }
    foreach ($checkTableEvents as $ce) {
        $key = $ce['entityType'] . ':' . $ce['entityId'];
        if (!isset($seenEntities[$key])) {
            $result[] = $ce;
            $seenEntities[$key] = true;
        }
    }
    foreach ($caseEvents as $ce) {
        $result[] = $ce;
    }

    usort($result, function ($a, $b) { return strcmp($a['startsAt'] ?? '', $b['startsAt'] ?? ''); });
    return $result;
}

function checks_handle_calendar_list(PDO $pdo, array $state): void
{
    $user = require_auth($state);
    $month = trim((string)($_GET['month'] ?? ''));

    // Debug mode
    if (isset($_GET['debug'])) {
        $isGlobal = has_system_admin_access($user) || ($user['role'] ?? '') === 'FEDERAL' || ($user['subject'] ?? '') === GENERAL_SUBJECT;
        $fromDate = ($month !== '' ? $month . '-01 00:00:00' : gmdate('Y-m-d H:i:s', time() - 7 * 24 * 60 * 60));
        $checksRows = checks_fetch_all($pdo,
            "SELECT id, subject, faction_id, status, starts_at, ends_at FROM `checks` WHERE deleted_at IS NULL AND status != 'cancelled' AND starts_at >= :from_date ORDER BY starts_at ASC LIMIT 10",
            [':from_date' => $fromDate]
        );
        $calRows = checks_fetch_all($pdo,
            "SELECT id, entity_type, entity_id, title, starts_at, recipient_user_id FROM calendar_events WHERE deleted_at IS NULL AND starts_at >= :from_date ORDER BY starts_at ASC LIMIT 10",
            [':from_date' => $fromDate]
        );
        respond(200, ['ok' => true, 'debug' => true, 'isGlobal' => $isGlobal, 'fromDate' => $fromDate, 'userId' => $user['id'], 'userSubject' => $user['subject'] ?? '', 'checksRows' => $checksRows, 'calendarRows' => $calRows]);
    }

    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        try {
            $items = checks_build_full_calendar($pdo, $user, $month, $state);
            respond(200, ['ok' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            respond(500, ['ok' => false, 'error' => 'Calendar error: ' . $e->getMessage(), 'trace' => $e->getFile() . ':' . $e->getLine()]);
        }
    }
    respond(200, ['ok' => true, 'items' => checks_build_bootstrap_calendar($pdo, $user, $state)]);
}

function checks_handle_calendar_create(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();

    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $startsAt = trim((string)($body['startsAt'] ?? ''));
    $endsAt = trim((string)($body['endsAt'] ?? ''));
    $color = trim((string)($body['color'] ?? 'other'));
    $visibility = trim((string)($body['visibility'] ?? 'private'));

    if ($title === '') respond(422, ['ok' => false, 'error' => 'Укажите название события']);
    if ($startsAt === '') respond(422, ['ok' => false, 'error' => 'Укажите дату начала']);
    if (!isset(CALENDAR_EVENT_CATEGORIES[$color])) $color = 'other';
    if (!in_array($visibility, ['private', 'subject', 'all'], true)) $visibility = 'private';
    if ($visibility !== 'private' && !has_system_admin_access($user) && !in_array($user['role'] ?? '', ['BOSS', 'SENIOR_STAFF', 'FEDERAL'], true)) {
        $visibility = 'private';
    }

    $targetUserId = trim((string)($body['targetUserId'] ?? ''));

    $groupId = checks_uuid();
    $startsAtStorage = checks_datetime_to_storage($startsAt, true);
    $endsAtStorage = $endsAt !== '' ? checks_datetime_to_storage($endsAt) : null;
    $statusLabel = CALENDAR_EVENT_CATEGORIES[$color];

    $recipients = [];
    if ($visibility === 'private') {
        $recipients[] = $user['id'];
    } elseif ($visibility === 'subject') {
        foreach ($state['users'] ?? [] as $u) {
            if (($u['blocked'] ?? false)) continue;
            if (($u['subject'] ?? '') === ($user['subject'] ?? '')) $recipients[] = $u['id'];
        }
    } else {
        foreach ($state['users'] ?? [] as $u) {
            if (($u['blocked'] ?? false)) continue;
            $recipients[] = $u['id'];
        }
    }
    // Если назначено конкретному сотруднику — добавить его в получатели
    if ($targetUserId !== '' && !in_array($targetUserId, $recipients, true)) {
        $recipients[] = $targetUserId;
    }
    if (empty($recipients)) $recipients[] = $user['id'];

    $now = checks_now_storage();
    foreach ($recipients as $recipientId) {
        checks_execute($pdo,
            'INSERT INTO calendar_events (id, recipient_user_id, entity_type, entity_id, title, description, starts_at, ends_at, status_label, creator_user_id, visibility, color, target_user_id, created_at, updated_at)
             VALUES (:id, :rid, :etype, :eid, :title, :desc, :sa, :ea, :sl, :cuid, :vis, :color, :tuid, :ca, :ua)',
            [
                ':id' => checks_uuid(), ':rid' => $recipientId, ':etype' => 'manual', ':eid' => $groupId,
                ':title' => $title, ':desc' => $description, ':sa' => $startsAtStorage, ':ea' => $endsAtStorage,
                ':sl' => $statusLabel, ':cuid' => $user['id'], ':vis' => $visibility, ':color' => $color,
                ':tuid' => $targetUserId !== '' ? $targetUserId : null,
                ':ca' => $now, ':ua' => $now,
            ]
        );
    }

    respond(200, ['ok' => true, 'groupId' => $groupId]);
}

function checks_handle_calendar_update(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();

    $entityId = trim((string)($body['entityId'] ?? ''));
    if ($entityId === '') respond(422, ['ok' => false, 'error' => 'Не указан идентификатор события']);

    $existing = checks_fetch_one($pdo,
        "SELECT * FROM calendar_events WHERE entity_type = 'manual' AND entity_id = :eid AND deleted_at IS NULL LIMIT 1",
        [':eid' => $entityId]
    );
    if (!$existing) respond(404, ['ok' => false, 'error' => 'Событие не найдено']);
    if (($existing['creator_user_id'] ?? '') !== $user['id'] && !has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Нет прав на редактирование']);
    }

    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $startsAt = trim((string)($body['startsAt'] ?? ''));
    $endsAt = trim((string)($body['endsAt'] ?? ''));
    $color = trim((string)($body['color'] ?? 'other'));
    $visibility = trim((string)($body['visibility'] ?? $existing['visibility'] ?? 'private'));

    if ($title === '') respond(422, ['ok' => false, 'error' => 'Укажите название события']);
    if ($startsAt === '') respond(422, ['ok' => false, 'error' => 'Укажите дату начала']);
    if (!isset(CALENDAR_EVENT_CATEGORIES[$color])) $color = 'other';
    if (!in_array($visibility, ['private', 'subject', 'all'], true)) $visibility = 'private';

    $startsAtStorage = checks_datetime_to_storage($startsAt, true);
    $endsAtStorage = $endsAt !== '' ? checks_datetime_to_storage($endsAt) : null;
    $statusLabel = CALENDAR_EVENT_CATEGORIES[$color];
    $now = checks_now_storage();
    $creatorId = $existing['creator_user_id'];

    // Soft-delete all existing rows
    checks_execute($pdo,
        "UPDATE calendar_events SET deleted_at = :da, updated_at = :ua WHERE entity_type = 'manual' AND entity_id = :eid AND deleted_at IS NULL",
        [':da' => $now, ':ua' => $now, ':eid' => $entityId]
    );

    // Re-create with potentially new visibility
    $recipients = [];
    if ($visibility === 'private') {
        $recipients[] = $creatorId;
    } elseif ($visibility === 'subject') {
        $creatorUser = checks_find_user($state, $creatorId);
        $creatorSubject = $creatorUser['subject'] ?? '';
        foreach ($state['users'] ?? [] as $u) {
            if (($u['blocked'] ?? false)) continue;
            if (($u['subject'] ?? '') === $creatorSubject) $recipients[] = $u['id'];
        }
    } else {
        foreach ($state['users'] ?? [] as $u) {
            if (($u['blocked'] ?? false)) continue;
            $recipients[] = $u['id'];
        }
    }
    if (empty($recipients)) $recipients[] = $creatorId;

    foreach ($recipients as $recipientId) {
        checks_execute($pdo,
            'INSERT INTO calendar_events (id, recipient_user_id, entity_type, entity_id, title, description, starts_at, ends_at, status_label, creator_user_id, visibility, color, created_at, updated_at)
             VALUES (:id, :rid, :etype, :eid, :title, :desc, :sa, :ea, :sl, :cuid, :vis, :color, :ca, :ua)',
            [
                ':id' => checks_uuid(), ':rid' => $recipientId, ':etype' => 'manual', ':eid' => $entityId,
                ':title' => $title, ':desc' => $description, ':sa' => $startsAtStorage, ':ea' => $endsAtStorage,
                ':sl' => $statusLabel, ':cuid' => $creatorId, ':vis' => $visibility, ':color' => $color,
                ':ca' => $now, ':ua' => $now,
            ]
        );
    }

    respond(200, ['ok' => true]);
}

function checks_handle_calendar_delete(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();

    $entityId = trim((string)($body['entityId'] ?? ''));
    if ($entityId === '') respond(422, ['ok' => false, 'error' => 'Не указан идентификатор события']);

    $existing = checks_fetch_one($pdo,
        "SELECT * FROM calendar_events WHERE entity_type = 'manual' AND entity_id = :eid AND deleted_at IS NULL LIMIT 1",
        [':eid' => $entityId]
    );
    if (!$existing) respond(404, ['ok' => false, 'error' => 'Событие не найдено']);
    if (($existing['creator_user_id'] ?? '') !== $user['id'] && !has_system_admin_access($user)) {
        respond(403, ['ok' => false, 'error' => 'Нет прав на удаление']);
    }

    $now = checks_now_storage();
    checks_execute($pdo,
        "UPDATE calendar_events SET deleted_at = :da, updated_at = :ua WHERE entity_type = 'manual' AND entity_id = :eid AND deleted_at IS NULL",
        [':da' => $now, ':ua' => $now, ':eid' => $entityId]
    );

    respond(200, ['ok' => true]);
}

// ── Discord bot endpoints (no auth required, use shared secret) ──

function checks_discord_verify_secret(): void
{
    $secret = getenv('DISCORD_WEBHOOK_SECRET') ?: '';
    if ($secret === '') return; // no secret configured = skip (dev)
    $header = trim((string)($_SERVER['HTTP_X_DISCORD_SECRET'] ?? ''));
    if (!hash_equals($secret, $header)) {
        respond(403, ['success' => false, 'error' => 'Forbidden']);
    }
}

function checks_handle_discord_status(PDO $pdo, array $state): void
{
    checks_discord_verify_secret();

    $usersTotal = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $usersOnline = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_active > DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
    $checksTotal = (int)$pdo->query("SELECT COUNT(*) FROM checks")->fetchColumn();
    $checksActive = (int)$pdo->query("SELECT COUNT(*) FROM checks WHERE status = 'active'")->fetchColumn();
    $checksPending = (int)$pdo->query("SELECT COUNT(*) FROM checks WHERE status = 'pending_approval'")->fetchColumn();
    $checksApproved = (int)$pdo->query("SELECT COUNT(*) FROM checks WHERE status = 'approved'")->fetchColumn();

    respond(200, [
        'success' => true,
        'data' => compact('usersTotal', 'usersOnline', 'checksTotal', 'checksActive', 'checksPending', 'checksApproved'),
    ]);
}

function checks_handle_discord_list(PDO $pdo, array $state): void
{
    checks_discord_verify_secret();

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $status = trim((string)($input['status'] ?? ''));
    $limit = min(max((int)($input['limit'] ?? 10), 1), 25);

    $sql = "SELECT id, name, subject_name AS subject, status, date_start FROM checks";
    $params = [];
    if ($status !== '') {
        $sql .= " WHERE status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY updated_at DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, ['success' => true, 'data' => ['checks' => $checks]]);
}

function checks_handle_file_download(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $settings = checks_get_settings($pdo);
    $fileId = trim((string)($_GET['fileId'] ?? ''));
    if ($fileId === '') {
        respond(422, ['ok' => false, 'error' => 'Не указан файл']);
    }

    $fileRow = checks_collect_files_for_download($pdo, $fileId);
    if (!$fileRow) {
        respond(404, ['ok' => false, 'error' => 'Файл не найден']);
    }
    $check = checks_fetch_check($pdo, (string)$fileRow['check_id']);
    if (!$check) {
        respond(404, ['ok' => false, 'error' => 'Проверка для файла не найдена']);
    }
    $participantIds = checks_load_active_participant_ids($pdo, (string)$check['id']);
    if (!checks_user_can_view_check($user, $check, $participantIds, $settings, $state)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для скачивания файла']);
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$fileRow['storage_path']);
    $absolutePath = realpath($absolutePath) ?: $absolutePath;
    if (!is_file($absolutePath)) {
        respond(404, ['ok' => false, 'error' => 'Файл отсутствует на сервере']);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . ((string)($fileRow['mime_type'] ?? '') ?: 'application/octet-stream'), true);
    header('Content-Length: ' . filesize($absolutePath), true);
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)($fileRow['original_name'] ?? basename($absolutePath))) . '"', true);
    readfile($absolutePath);
    exit;
}

function checks_handle_action(PDO $pdo, string $action, array &$state): bool
{
    if (!str_starts_with($action, 'checks.')) {
        return false;
    }

    checks_ensure_schema($pdo);
    checks_seed_settings($pdo);

    switch ($action) {
        case 'checks.list':
            checks_handle_list($pdo, $state);
            return true;
        case 'checks.get':
            checks_handle_get($pdo, $state);
            return true;
        case 'checks.create':
            checks_handle_create($pdo, $state);
            return true;
        case 'checks.update':
            checks_handle_update($pdo, $state);
            return true;
        case 'checks.activate':
            checks_handle_activate($pdo, $state);
            return true;
        case 'checks.complete':
            checks_handle_complete($pdo, $state);
            return true;
        case 'checks.reopen':
            checks_handle_reopen($pdo, $state);
            return true;
        case 'checks.participants.sync':
            checks_handle_sync_participants($pdo, $state);
            return true;
        case 'checks.reports.create':
            checks_handle_report_create($pdo, $state);
            return true;
        case 'checks.reports.update':
            checks_handle_report_update($pdo, $state);
            return true;
        case 'checks.reports.upload':
            checks_handle_report_upload($pdo, $state);
            return true;
        case 'checks.interviews.upsert':
            checks_handle_interview_upsert($pdo, $state);
            return true;
        case 'checks.interviews.answers.bulk-upsert':
            checks_handle_interview_answers_upsert($pdo, $state);
            return true;
        case 'checks.summary':
            checks_handle_summary($pdo, $state);
            return true;
        case 'checks.submit-approval':
            checks_handle_submit_approval($pdo, $state);
            return true;
        case 'checks.approve':
            checks_handle_approve($pdo, $state);
            return true;
        case 'checks.delete':
            checks_handle_delete($pdo, $state);
            return true;
        case 'checks.final-report':
            checks_handle_final_report($pdo, $state);
            return true;
        case 'checks.gp-notes.create':
            checks_handle_gp_note_create($pdo, $state);
            return true;
        case 'checks.notifications.list':
            checks_handle_notifications_list($pdo, $state);
            return true;
        case 'checks.notifications.dismiss':
            checks_handle_notification_dismiss($pdo, $state);
            return true;
        case 'checks.notifications.broadcast':
            checks_handle_notifications_broadcast($pdo, $state);
            return true;
        case 'checks.notifications.clear-read':
            checks_handle_notifications_clear_read($pdo, $state);
            return true;
        case 'checks.notifications.stats':
            checks_handle_notifications_stats($pdo, $state);
            return true;
        case 'checks.calendar.list':
            checks_handle_calendar_list($pdo, $state);
            return true;
        case 'checks.calendar.create':
            checks_handle_calendar_create($pdo, $state);
            return true;
        case 'checks.calendar.update':
            checks_handle_calendar_update($pdo, $state);
            return true;
        case 'checks.calendar.delete':
            checks_handle_calendar_delete($pdo, $state);
            return true;
        case 'checks.files.download':
            checks_handle_file_download($pdo, $state);
            return true;
        case 'checks.discord.status':
            checks_handle_discord_status($pdo, $state);
            return true;
        case 'checks.discord.list':
            checks_handle_discord_list($pdo, $state);
            return true;
        default:
            respond(404, ['ok' => false, 'error' => 'Неизвестное действие модуля проверок']);
            return true;
    }
}

// ═══════════════════════════════════════════════════════════════════
//  МОДУЛЬ «ОБРАЩЕНИЯ И ЖАЛОБЫ» (Cases)
// ═══════════════════════════════════════════════════════════════════

define('CASES_STATUSES', [
    'registered'                => 'Зарегистрировано',
    'assigned_staff'            => 'Назначен исполнитель',
    'assigned_supervisor'       => 'Назначен прокурор',
    'preliminary_check'         => 'На предварительной проверке',
    'check_terminated'          => 'Проверка прекращена',
    'transferred_investigation' => 'Передано в следствие',
    'criminal_case_opened'      => 'ВУД',
    'criminal_case_refused'     => 'Отказ в ВУД',
    'prosecution_review'        => 'На утверждении в прокуратуре',
    'prosecution_approved'      => 'Утверждено',
    'prosecution_refused'       => 'В утверждении отказано',
    'sent_to_court'             => 'Передано в суд',
    'verdict_issued'            => 'Приговор вынесен',
    'verdict_guilty'            => 'Приговор в пользу обвинения',
    'verdict_partial'           => 'Приговор частично в пользу обвинения',
    'verdict_acquitted'         => 'Приговор в пользу подсудимого',
    'completed'                 => 'Завершено',
    'archive'                   => 'Архив',
]);

define('CASES_STATUS_TRANSITIONS', [
    'registered'                => ['preliminary_check'],
    'assigned_staff'            => ['assigned_supervisor', 'preliminary_check'],
    'assigned_supervisor'       => ['preliminary_check'],
    'preliminary_check'         => ['check_terminated', 'transferred_investigation'],
    'check_terminated'          => ['archive'],
    'transferred_investigation' => ['criminal_case_opened', 'criminal_case_refused'],
    'criminal_case_opened'      => ['prosecution_review'],
    'criminal_case_refused'     => ['archive'],
    'prosecution_review'        => ['prosecution_approved', 'prosecution_refused'],
    'prosecution_approved'      => ['sent_to_court'],
    'prosecution_refused'       => ['prosecution_review', 'archive'],
    'sent_to_court'             => ['verdict_guilty', 'verdict_partial', 'verdict_acquitted'],
    'verdict_guilty'            => ['completed'],
    'verdict_partial'           => ['completed'],
    'verdict_acquitted'         => ['completed'],
    'completed'                 => ['archive'],
    'archive'                   => [],
]);

define('CASES_TERMINAL_STATUSES', ['completed', 'archive', 'check_terminated', 'criminal_case_refused', 'prosecution_refused']);

define('CASES_SEVERITY', [
    'minor'          => ['label' => 'Небольшой тяжести', 'days' => 6],
    'medium'         => ['label' => 'Средней тяжести',   'days' => 12],
    'serious'        => ['label' => 'Тяжкая',            'days' => 18],
    'especially_serious' => ['label' => 'Особо тяжкая',  'days' => 24],
]);

define('CASES_EXTENSION_DAYS', 7);

function cases_calc_deadline_by_severity(string $severity, ?string $fromDate = null): ?string
{
    $meta = CASES_SEVERITY[$severity] ?? null;
    if (!$meta) return null;
    $from = $fromDate ? new DateTime($fromDate) : new DateTime();
    $from->modify('+' . $meta['days'] . ' days');
    return $from->format('Y-m-d');
}

define('CASES_TRANSITION_REQUIRED_FIELDS', [
    'assigned_staff'        => [],
    'assigned_supervisor'   => [],
    'check_terminated'      => ['stage_result'],
    'criminal_case_opened'  => ['stage_result'],
    'criminal_case_refused' => ['stage_result'],
    'prosecution_approved'  => ['stage_result'],
    'prosecution_refused'   => ['stage_result'],
    'completed'             => ['final_result'],
]);

function cases_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $tables = [
        'CREATE TABLE IF NOT EXISTS cases (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            reg_number VARCHAR(64) NOT NULL,
            subject VARCHAR(128) NOT NULL,
            case_type VARCHAR(32) NOT NULL,
            source VARCHAR(32) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT \'registered\',
            applicant_name VARCHAR(255) NOT NULL DEFAULT \'\',
            applicant_contact TEXT DEFAULT NULL,
            description TEXT NOT NULL,
            faction_id VARCHAR(64) DEFAULT NULL,
            forum_link VARCHAR(512) DEFAULT NULL,
            severity VARCHAR(32) DEFAULT NULL,
            deadline DATE DEFAULT NULL,
            deadline_extended TINYINT(1) NOT NULL DEFAULT 0,
            deadline_extension_reason TEXT DEFAULT NULL,
            deadline_original DATE DEFAULT NULL,
            next_control_date DATE DEFAULT NULL,
            assigned_staff_id VARCHAR(64) DEFAULT NULL,
            supervisor_id VARCHAR(64) DEFAULT NULL,
            created_by VARCHAR(64) NOT NULL,
            comments TEXT DEFAULT NULL,
            stage_result TEXT DEFAULT NULL,
            final_result TEXT DEFAULT NULL,
            links_json LONGTEXT DEFAULT NULL,
            discord_thread_id VARCHAR(64) DEFAULT NULL,
            discord_message_id VARCHAR(64) DEFAULT NULL,
            discord_channel_id VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_cases_subject_status (subject, status, created_at),
            INDEX idx_cases_assigned (assigned_staff_id, status),
            INDEX idx_cases_supervisor (supervisor_id, status),
            INDEX idx_cases_deadline (deadline, status),
            INDEX idx_cases_reg (reg_number)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS case_status_history (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            case_id VARCHAR(32) NOT NULL,
            from_status VARCHAR(64) DEFAULT NULL,
            to_status VARCHAR(64) NOT NULL,
            changed_by VARCHAR(64) NOT NULL,
            comment TEXT DEFAULT NULL,
            stage_result TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_csh_case (case_id, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS case_comments (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            case_id VARCHAR(32) NOT NULL,
            author_id VARCHAR(64) NOT NULL,
            body TEXT NOT NULL,
            is_service_note TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_cc_case (case_id, created_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS case_links (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            case_id VARCHAR(32) NOT NULL,
            link_type VARCHAR(32) NOT NULL DEFAULT \'other\',
            url VARCHAR(1024) NOT NULL,
            label VARCHAR(255) DEFAULT NULL,
            added_by VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_cl_case (case_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // Migration: add severity & deadline extension columns
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN severity VARCHAR(32) DEFAULT NULL AFTER forum_link");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN deadline_extended TINYINT(1) NOT NULL DEFAULT 0 AFTER deadline");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN deadline_extension_reason TEXT DEFAULT NULL AFTER deadline_extended");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN deadline_original DATE DEFAULT NULL AFTER deadline_extension_reason");
    } catch (Exception $e) { /* already exists */ }

    // Migration: add image_url to case_comments
    try {
        $pdo->exec("ALTER TABLE case_comments ADD COLUMN image_url VARCHAR(1024) DEFAULT NULL AFTER body");
    } catch (Exception $e) { /* already exists */ }

    // Migration: add basis_link to checks
    try {
        $pdo->exec("ALTER TABLE checks ADD COLUMN basis_link VARCHAR(1024) DEFAULT NULL AFTER basis_text");
    } catch (Exception $e) { /* already exists */ }

    // Migration: add sk_executor_name to cases
    try {
        $pdo->exec("ALTER TABLE cases ADD COLUMN sk_executor_name VARCHAR(255) DEFAULT NULL AFTER supervisor_id");
    } catch (Exception $e) { /* already exists */ }
}

function cases_generate_reg_number(PDO $pdo, string $subject): string
{
    $year = date('Y');
    $prefix = mb_strtoupper(mb_substr($subject, 0, 3, 'UTF-8'), 'UTF-8');
    $count = (int)checks_fetch_one($pdo, 'SELECT COUNT(*) as cnt FROM cases WHERE YEAR(created_at) = :year AND subject = :subject', [
        ':year' => $year,
        ':subject' => $subject,
    ])['cnt'];
    $seq = $count + 1;
    return sprintf('%s-%04d', $prefix, $seq);
}

function cases_user_can_create(?array $user): bool
{
    if (!$user) return false;
    // Все сотрудники прокуратуры могут регистрировать обращения
    return true;
}

function cases_user_can_view(?array $user, array $case): bool
{
    if (!$user) return false;
    if (has_system_admin_access($user)) return true;
    if (($user['role'] ?? '') === 'FEDERAL') return true;
    if (in_array($user['role'] ?? '', ['BOSS', 'SENIOR_STAFF', 'USP'], true) && ($user['subject'] ?? '') === ($case['subject'] ?? '')) return true;
    if (($case['assigned_staff_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['supervisor_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['created_by'] ?? '') === ($user['id'] ?? '')) return true;
    return false;
}

function cases_user_can_manage(?array $user, array $case): bool
{
    if (!$user) return false;
    if (has_system_admin_access($user)) return true;
    if (($user['role'] ?? '') === 'FEDERAL') return true;
    if (($user['subject'] ?? '') === GENERAL_SUBJECT) return true;
    if (in_array($user['role'] ?? '', ['BOSS', 'SENIOR_STAFF', 'USP'], true) && ($user['subject'] ?? '') === ($case['subject'] ?? '')) return true;
    if (($case['supervisor_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['assigned_staff_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['created_by'] ?? '') === ($user['id'] ?? '')) return true;
    return false;
}

function cases_user_can_change_status(?array $user, array $case, string $newStatus): bool
{
    if (!$user) return false;
    $currentStatus = $case['status'] ?? '';
    $allowed = CASES_STATUS_TRANSITIONS[$currentStatus] ?? [];
    if (!in_array($newStatus, $allowed, true)) return false;

    if (has_system_admin_access($user)) return true;
    if (($user['role'] ?? '') === 'FEDERAL') return true;
    if (in_array($user['role'] ?? '', ['BOSS', 'SENIOR_STAFF', 'USP'], true) && ($user['subject'] ?? '') === ($case['subject'] ?? '')) return true;
    if (($case['assigned_staff_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['supervisor_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['created_by'] ?? '') === ($user['id'] ?? '')) return true;
    return false;
}

function cases_user_can_comment(?array $user, array $case): bool
{
    if (!$user) return false;
    if (cases_user_can_manage($user, $case)) return true;
    if (($case['assigned_staff_id'] ?? '') === ($user['id'] ?? '')) return true;
    if (($case['supervisor_id'] ?? '') === ($user['id'] ?? '')) return true;
    return false;
}

function cases_format_row(array $row, array $state): array
{
    $users = $state['users'] ?? [];
    $factions = $state['factions'] ?? [];
    $findUserName = function(string $userId) use ($users): string {
        foreach ($users as $u) {
            if (($u['id'] ?? '') === $userId) {
                return trim(($u['surname'] ?? '') . ' ' . ($u['name'] ?? '')) ?: ($u['login'] ?? $userId);
            }
        }
        return $userId;
    };
    $findFactionName = function(?string $factionId) use ($factions): ?string {
        if (!$factionId) return null;
        foreach ($factions as $f) {
            if (($f['id'] ?? '') === $factionId) {
                return $f['name'] ?? $factionId;
            }
        }
        return $factionId;
    };

    return [
        'id' => $row['id'],
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'caseType' => $row['case_type'],
        'source' => $row['source'],
        'status' => $row['status'],
        'statusLabel' => CASES_STATUSES[$row['status']] ?? $row['status'],
        'applicantName' => $row['applicant_name'],
        'applicantContact' => $row['applicant_contact'],
        'description' => $row['description'],
        'factionId' => $row['faction_id'],
        'factionName' => $findFactionName($row['faction_id']),
        'forumLink' => $row['forum_link'],
        'severity' => $row['severity'] ?? null,
        'severityLabel' => isset($row['severity'], CASES_SEVERITY[$row['severity']]) ? CASES_SEVERITY[$row['severity']]['label'] : null,
        'deadline' => $row['deadline'],
        'deadlineExtended' => (bool)($row['deadline_extended'] ?? false),
        'deadlineExtensionReason' => $row['deadline_extension_reason'] ?? null,
        'deadlineOriginal' => $row['deadline_original'] ?? null,
        'nextControlDate' => $row['next_control_date'],
        'preliminaryDeadline' => $row['preliminary_deadline'] ?? null,
        'incidentDate' => $row['incident_date'] ?? null,
        'decisionDeadline' => $row['decision_deadline'] ?? null,
        'assignedStaffId' => $row['assigned_staff_id'],
        'assignedStaffName' => ($row['assigned_staff_name'] ?? '') !== '' ? $row['assigned_staff_name'] : ($row['assigned_staff_id'] ? $findUserName($row['assigned_staff_id']) : null),
        'supervisorId' => $row['supervisor_id'],
        'supervisorName' => $row['supervisor_id'] ? $findUserName($row['supervisor_id']) : null,
        'skExecutorName' => $row['sk_executor_name'] ?? null,
        'createdBy' => $row['created_by'],
        'createdByName' => $findUserName($row['created_by']),
        'serviceNote' => $row['comments'],
        'stageResult' => $row['stage_result'],
        'finalResult' => $row['final_result'],
        'discordThreadId' => $row['discord_thread_id'],
        'discordMessageId' => $row['discord_message_id'],
        'discordChannelId' => $row['discord_channel_id'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ];
}

define('CASES_DISCORD_EVENT_CONFIG', [
    'case.created'              => ['color' => 0x0077b6, 'title' => '📨 Новое обращение'],
    'case.assigned'             => ['color' => 0x1d70d1, 'title' => '👤 Назначен исполнитель'],
    'case.supervisor_assigned'  => ['color' => 0x0353a4, 'title' => '👁 Назначен прокурор'],
    'case.status_changed'       => ['color' => 0xd69a2d, 'title' => '🔄 Статус обращения'],
    'case.deadline_approaching' => ['color' => 0xd69a2d, 'title' => '⏰ Приближается срок'],
    'case.overdue'              => ['color' => 0xb34739, 'title' => '🔴 Просрочено'],
    'case.completed'            => ['color' => 0x2f9e8f, 'title' => '✅ Обращение завершено'],
    'case.comment_added'        => ['color' => 0x5865F2, 'title' => '💬 Новый комментарий'],
    'case.link_added'           => ['color' => 0x57F287, 'title' => '📎 Добавлен материал'],
    'case.deadline_extended'    => ['color' => 0xE67E22, 'title' => '📅 Срок продлён'],
]);

define('CASES_DISCORD_WEBHOOK_URLS', [
    'Рублёвка'    => 'https://discord.com/api/webhooks/1484152689750310942/jqCl9W_sHChL-g30Sv0hYzZBMHXMO4DCr7Tmxmv-fES-5Sor04a7oHMpu8nIQfLpjvYL',
    'Арбат'       => 'https://discord.com/api/webhooks/1484218902241349744/QuUhtQCQdMYfmtRFE1dSWYmJrxXETzsWM7pLemc9bBc5lGI4A6tVjVcnVRGu6Y_2YyyK',
    'Патрики'     => 'https://discord.com/api/webhooks/1484203483623329793/CZUgFNsu9nntLYcuIBhqQx-nPPPxLg4ABBZWJ82Wvr4ONropUpEhrCHIKqlup24WZNsL',
    'Тверской'    => 'https://discord.com/api/webhooks/1484245772856856728/NJsX-EOvY4QY1ZK5DgGI0WV41raCF2nvo0pDHHpTK9vWoj7_3mTJS1YpcV7Ni0GGJ0W3',
    'Кутузовский' => 'https://discord.com/api/webhooks/1484219684302753822/5afzKGVPNKVV1HNAxe0wluy4hqLp2YDrT6YsSyIh-a1FwrotNKIO5-5DPRCe13XL57ZT',
    'Генеральная прокуратура' => 'https://discord.com/api/webhooks/1483799806152540242/E5zpYyPf3B8nfXoUxtZwyu8huC93nQ7_c6VBRgBqJXpwyeEuQpsj8kjrGUEUCucsS8VF',
]);
define('CASES_DISCORD_SK_ROLE_IDS', [
    'Рублёвка'    => ['1334917741068423288', '1335226739496058927'],
    'Арбат'       => ['1297985183575965757', '1297985926563102801'],
    'Патрики'     => ['1321237407579770901', '1332623372487626834'],
    'Тверской'    => ['1367620839725465790', '1367620841902313602'],
    'Кутузовский' => ['1465602276268703799', '1465602278978486272'],
]);
// Роли прокурора и зама субъекта — пинг на все события
define('CASES_DISCORD_PROSECUTOR_ROLE_IDS', [
    'Рублёвка'    => ['1334917739898343464', '1334917743144734843'],
    'Арбат'       => ['1246728779544395867', '1246728780198969465'],
    'Патрики'     => ['1321237406514544651', '1321237408418893927'],
    'Тверской'    => ['1367620838760779817', '1367620840866320435'],
    'Кутузовский' => ['1465602272149897248', '1465602274427404371'],
]);
define('CASES_DISCORD_BOT_TOKEN', 'Njk3MDY1NzczODg5MzU1ODA3.GJPgF3.TtGl2HSNOU_HyRg8NcKXGahkhuFfhcylsYKo-Q');
define('CASES_DISCORD_CHANNEL_IDS', [
    'Арбат'   => '1448021061638815817',
    'Патрики' => '', // TODO: вставить channel ID Патриков
]);

function cases_discord_bot_api(string $endpoint, array $jsonBody): ?array
{
    $url = 'https://discord.com/api/v10' . $endpoint;
    $ch = curl_init($url);
    if (!$ch) return null;
    $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bot ' . CASES_DISCORD_BOT_TOKEN,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ? json_decode($result, true) : null;
}

function cases_build_discord_embed(string $event, array $data): array
{
    $config = CASES_DISCORD_EVENT_CONFIG[$event] ?? ['color' => 0x0077b6, 'title' => $event];

    $embed = [
        'title' => $config['title'],
        'color' => $config['color'],
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'fields' => [],
    ];

    $descParts = [];
    if (!empty($data['description']))    $descParts[] = mb_substr($data['description'], 0, 300, 'UTF-8');
    if (!empty($data['assignedName']))   $descParts[] = '**Исполнитель:** ' . $data['assignedName'];
    if (!empty($data['supervisorName'])) $descParts[] = '**Прокурор:** ' . $data['supervisorName'];
    if (!empty($data['skExecutorName'])) $descParts[] = '**Следователь:** ' . $data['skExecutorName'];
    if (!empty($data['oldStatusLabel']) && !empty($data['statusLabel'])) {
        $descParts[] = '~~' . $data['oldStatusLabel'] . '~~ → **' . $data['statusLabel'] . '**';
    } elseif (!empty($data['statusLabel'])) {
        $descParts[] = '**Статус:** ' . $data['statusLabel'];
    }
    if (count($descParts) > 0) {
        $embed['description'] = implode("\n", $descParts);
    }

    if (!empty($data['regNumber']))     $embed['fields'][] = ['name' => 'Рег. №',     'value' => $data['regNumber'],     'inline' => true];
    if (!empty($data['applicantName'])) $embed['fields'][] = ['name' => 'Заявитель',   'value' => $data['applicantName'], 'inline' => true];
    if (!empty($data['caseType']))      $embed['fields'][] = ['name' => 'Тип',         'value' => $data['caseType'],      'inline' => true];
    if (!empty($data['deadline'])) {
        $ts = strtotime($data['deadline']);
        $embed['fields'][] = ['name' => 'Срок', 'value' => $ts ? '<t:' . $ts . ':D>' : $data['deadline'], 'inline' => true];
    }

    // Forum link
    if (!empty($data['forumLink'])) {
        $embed['fields'][] = ['name' => '🔗 Форум', 'value' => '[Ссылка на форум](' . $data['forumLink'] . ')', 'inline' => false];
    }
    // Video link
    if (!empty($data['videoLink'])) {
        $embed['fields'][] = ['name' => '🎥 Видео', 'value' => '[Видео регистрации обращения](' . $data['videoLink'] . ')', 'inline' => false];
    }

    // Comment
    if (!empty($data['commentBody'])) {
        $embed['description'] = mb_substr($data['commentBody'], 0, 1000, 'UTF-8');
    }
    if (!empty($data['commentAuthor'])) {
        $embed['footer'] = ['text' => $data['commentAuthor']];
    }

    // Image (comment or link)
    if (!empty($data['imageUrl'])) {
        $imgUrl = $data['imageUrl'];
        if (!preg_match('#^https?://#i', $imgUrl)) {
            $imgUrl = 'https://prosecutors-office-rmrp.ru/' . ltrim($imgUrl, '/');
        }
        $embed['image'] = ['url' => $imgUrl];
    }

    // Link added
    if (!empty($data['linkUrl'])) {
        $linkUrl = $data['linkUrl'];
        // Make relative URLs absolute
        if ($linkUrl && !preg_match('#^https?://#i', $linkUrl)) {
            $linkUrl = 'https://prosecutors-office-rmrp.ru/' . ltrim($linkUrl, '/');
        }
        // If it's an image, show it as embed image
        if (preg_match('/\.(png|jpe?g|gif|webp)$/i', $linkUrl)) {
            $embed['image'] = ['url' => $linkUrl];
        } else {
            $embed['description'] = ($embed['description'] ?? '') . "\n[" . ($data['linkLabel'] ?: basename($linkUrl)) . "](" . $linkUrl . ")";
        }
    }

    return $embed;
}

function cases_dispatch_webhook(string $event, array $data): void
{
    $subject = $data['subject'] ?? '';

    // Only enabled for subjects with new forum webhook URLs
    $enabledWebhookSubjects = ['Рублёвка', 'Патрики', 'Арбат', 'Кутузовский', 'Тверской'];
    if (!in_array($subject, $enabledWebhookSubjects, true)) return;

    $webhookUrl = CASES_DISCORD_WEBHOOK_URLS[$subject] ?? '';
    if ($webhookUrl === '') return;

    $config = CASES_DISCORD_EVENT_CONFIG[$event] ?? null;
    if (!$config) return;

    $embed = cases_build_discord_embed($event, $data);

    $mentionParts = [];
    if (!empty($data['discordUserIds']) && is_array($data['discordUserIds'])) {
        foreach ($data['discordUserIds'] as $uid) {
            $mentionParts[] = '<@' . $uid . '>';
        }
    }

    // Пинг ролей прокурора и зама субъекта на все события
    $roleMentions = [];
    $prosecutorRoles = CASES_DISCORD_PROSECUTOR_ROLE_IDS[$subject] ?? [];
    foreach ($prosecutorRoles as $roleId) {
        $mentionParts[] = '<@&' . $roleId . '>';
        $roleMentions[] = $roleId;
    }

    // Пинг ролей руководства СК при «Передано в следствие»
    if (($data['newStatus'] ?? '') === 'transferred_investigation') {
        $skRoles = CASES_DISCORD_SK_ROLE_IDS[$subject] ?? [];
        foreach ($skRoles as $roleId) {
            $mentionParts[] = '<@&' . $roleId . '>';
            $roleMentions[] = $roleId;
        }
    }

    $mentions = implode(' ', $mentionParts);

    $payload = [
        'embeds' => [$embed],
        'allowed_mentions' => ['parse' => ['users'], 'roles' => $roleMentions],
        'username' => 'ЕИАС «Фемида»',
    ];
    if ($mentions !== '') {
        $payload['content'] = $mentions;
    }

    $pdo = $data['_pdo'] ?? null;
    $caseId = $data['caseId'] ?? '';
    $threadId = $data['discordThreadId'] ?? '';

    if ($event === 'case.created') {
        // ── Форум-тред: создаём новый пост в форум-канале ──
        $regNumber = $data['regNumber'] ?? 'N/A';
        $caseType = $data['caseType'] ?? 'Обращение';
        $payload['thread_name'] = $regNumber . ' — ' . $caseType;

        $url = $webhookUrl . '?wait=true';
        $response = cases_discord_http($url, $payload);

        // Сохраняем thread_id из ответа (channel_id = ID форум-поста)
        if ($response && !empty($response['channel_id']) && $pdo && $caseId) {
            $newThreadId = (string)$response['channel_id'];
            checks_execute($pdo, 'UPDATE cases SET discord_thread_id = :tid, updated_at = :now WHERE id = :id', [
                ':tid' => $newThreadId,
                ':now' => checks_now_storage(),
                ':id' => $caseId,
            ]);
        }
    } else {
        // ── Ход дела: отправляем в тред ──
        if ($threadId === '') return; // нет треда — не шлём
        $url = $webhookUrl . '?thread_id=' . urlencode($threadId);
        cases_discord_http($url, $payload);
    }
}

function cases_discord_http(string $url, array $payload): ?array
{
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    if (!$ch) return null;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result && $httpCode >= 200 && $httpCode < 300) {
        return json_decode($result, true) ?: null;
    }
    return null;
}

function cases_resolve_discord_ids(array $case, array $state): array
{
    $userIds = array_filter([
        $case['assigned_staff_id'] ?? null,
        $case['supervisor_id'] ?? null,
    ]);
    $discordIds = [];
    foreach ($state['users'] ?? [] as $u) {
        if (in_array($u['id'] ?? '', $userIds, true) && !empty($u['discordId'])) {
            $discordIds[] = $u['discordId'];
        }
    }
    return $discordIds;
}

function cases_notify_participants(PDO $pdo, array $case, string $type, string $title, string $body, string $priority = 'info', array $state = []): void
{
    $recipients = [];
    if (!empty($case['assigned_staff_id'])) $recipients[] = $case['assigned_staff_id'];
    if (!empty($case['supervisor_id'])) $recipients[] = $case['supervisor_id'];
    if (!empty($case['created_by'])) $recipients[] = $case['created_by'];
    $recipients = array_unique($recipients);

    $now = checks_now_storage();
    foreach ($recipients as $uid) {
        // For status changes, upsert: update existing unread notification for this case instead of creating duplicates
        if ($type === 'case.status_changed') {
            $existing = checks_fetch_one($pdo,
                'SELECT id FROM notifications WHERE recipient_user_id = :uid AND type = :type AND entity_type = :etype AND entity_id = :eid AND read_at IS NULL AND deleted_at IS NULL LIMIT 1',
                [':uid' => $uid, ':type' => $type, ':etype' => 'case', ':eid' => $case['id']]);
            if ($existing) {
                checks_execute($pdo,
                    'UPDATE notifications SET title = :title, body = :body, priority = :priority, created_at = :now WHERE id = :id',
                    [':title' => $title, ':body' => $body, ':priority' => $priority, ':now' => $now, ':id' => $existing['id']]);
                continue;
            }
        }
        checks_create_notification($pdo, $uid, $type, 'case', $case['id'], $title, $body, $priority, 'cases', ['caseId' => $case['id']]);
    }
}

// ── Handlers ──

function cases_handle_create(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    if (!cases_user_can_create($user)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для создания обращения']);
    }

    $body = read_json_body();
    $caseType = trim((string)($body['caseType'] ?? ''));
    $source = trim((string)($body['source'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $applicantName = trim((string)($body['applicantName'] ?? ''));
    $factionId = trim((string)($body['factionId'] ?? ''));
    $forumLink = trim((string)($body['forumLink'] ?? ''));
    $videoLink = trim((string)($body['videoLink'] ?? ''));
    $severity = trim((string)($body['severity'] ?? ''));
    $deadline = trim((string)($body['deadline'] ?? ''));
    $comments = trim((string)($body['comments'] ?? ''));
    $subject = trim((string)($body['subject'] ?? ''));
    $assignedStaffId = trim((string)($body['assignedStaffId'] ?? ''));
    $supervisorId = trim((string)($body['supervisorId'] ?? ''));
    $applicantContact = trim((string)($body['applicantContact'] ?? ''));
    $incidentDate = trim((string)($body['incidentDate'] ?? ''));
    $skExecutorName = trim((string)($body['skExecutorName'] ?? ''));

    if (!in_array($caseType, ['appeal', 'complaint'], true)) {
        respond(422, ['ok' => false, 'error' => 'Укажите тип: обращение или жалоба']);
    }
    if (!in_array($source, ['forum', 'oral', 'internal', 'sk_transfer', 'fsb_transfer', 'other'], true)) {
        respond(422, ['ok' => false, 'error' => 'Укажите источник поступления']);
    }
    if ($description === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите описание обращения']);
    }
    if ($source === 'forum' && $forumLink === '') {
        respond(422, ['ok' => false, 'error' => 'Для источника "Форум" укажите ссылку']);
    }
    if ($source === 'oral' && $videoLink === '') {
        respond(422, ['ok' => false, 'error' => 'Для устного обращения укажите ссылку на видео']);
    }

    if ($severity !== '' && !isset(CASES_SEVERITY[$severity])) {
        respond(422, ['ok' => false, 'error' => 'Неизвестная тяжесть статьи']);
    }

    // Auto-calculate deadline from severity based on incident date (or today)
    if ($deadline === '' && $severity !== '') {
        if ($incidentDate !== '') {
            $deadline = cases_calc_deadline_by_severity($severity, $incidentDate);
        } else {
            $deadline = cases_calc_deadline_by_severity($severity);
        }
    }

    if ($subject === '') {
        $subject = $user['subject'] ?? '';
    }

    $id = checks_uuid();
    $customRegNumber = trim((string)($body['customRegNumber'] ?? ''));
    $regNumber = $customRegNumber !== '' ? $customRegNumber : cases_generate_reg_number($pdo, $subject);
    $now = checks_now_storage();

    $status = 'registered';
    if ($assignedStaffId !== '') $status = 'assigned_staff';
    if ($assignedStaffId !== '' && $supervisorId !== '') $status = 'assigned_supervisor';

    // Передано из СК / ФСБ — сразу на этапе ВУД
    if ($source === 'sk_transfer' || $source === 'fsb_transfer') {
        $status = 'criminal_case_opened';
    }

    // 48 часов на принятие решения о передаче в следствие
    $decisionDeadline = (new DateTime($now))->modify('+48 hours')->format('Y-m-d H:i:s');

    checks_execute($pdo, 'INSERT INTO cases (id, reg_number, subject, case_type, source, status, severity, applicant_name, applicant_contact, description, faction_id, forum_link, deadline, deadline_original, incident_date, decision_deadline, assigned_staff_id, supervisor_id, sk_executor_name, created_by, comments, created_at, updated_at)
        VALUES (:id, :reg_number, :subject, :case_type, :source, :status, :severity, :applicant_name, :applicant_contact, :description, :faction_id, :forum_link, :deadline, :deadline_original, :incident_date, :decision_deadline, :assigned_staff_id, :supervisor_id, :sk_executor_name, :created_by, :comments, :created_at, :updated_at)', [
        ':id' => $id,
        ':reg_number' => $regNumber,
        ':subject' => $subject,
        ':case_type' => $caseType,
        ':source' => $source,
        ':status' => $status,
        ':severity' => $severity ?: null,
        ':applicant_name' => $applicantName,
        ':applicant_contact' => $applicantContact ?: null,
        ':description' => $description,
        ':faction_id' => $factionId ?: null,
        ':forum_link' => $forumLink ?: null,
        ':deadline' => $deadline ?: null,
        ':deadline_original' => $deadline ?: null,
        ':incident_date' => $incidentDate ?: null,
        ':decision_deadline' => $decisionDeadline,
        ':assigned_staff_id' => $assignedStaffId ?: null,
        ':supervisor_id' => $supervisorId ?: null,
        ':sk_executor_name' => $skExecutorName ?: null,
        ':created_by' => $user['id'],
        ':comments' => $comments ?: null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    // Status history
    checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, created_at)
        VALUES (:id, :case_id, NULL, :to_status, :changed_by, :comment, :created_at)', [
        ':id' => checks_uuid(),
        ':case_id' => $id,
        ':to_status' => $status,
        ':changed_by' => $user['id'],
        ':comment' => 'Обращение зарегистрировано',
        ':created_at' => $now,
    ]);

    // Auto-add video link for oral source
    if ($source === 'oral' && $videoLink !== '') {
        checks_execute($pdo, 'INSERT INTO case_links (id, case_id, link_type, url, label, added_by, created_at) VALUES (:id, :case_id, :link_type, :url, :label, :added_by, :created_at)', [
            ':id' => checks_uuid(),
            ':case_id' => $id,
            ':link_type' => 'material',
            ':url' => $videoLink,
            ':label' => 'Видео регистрации обращения',
            ':added_by' => $user['id'],
            ':created_at' => $now,
        ]);
    }

    // Audit
    checks_log_audit($pdo, 'case', $id, 'case.created', $user, null, [
        'regNumber' => $regNumber,
        'caseType' => $caseType,
        'subject' => $subject,
        'status' => $status,
    ]);

    $caseRow = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $id]);
    $formatted = cases_format_row($caseRow, $state);

    // Notifications
    if ($assignedStaffId) {
        checks_create_notification($pdo, $assignedStaffId, 'case.assigned', 'case', $id,
            'Назначение на обращение ' . $regNumber,
            'Вы назначены исполнителем по обращению ' . $regNumber,
            'warning', 'cases', ['caseId' => $id]);
    }
    if ($supervisorId) {
        checks_create_notification($pdo, $supervisorId, 'case.supervisor_assigned', 'case', $id,
            'Надзор за обращением ' . $regNumber,
            'Вы назначены прокурором по обращению ' . $regNumber,
            'info', 'cases', ['caseId' => $id]);
    }

    // Discord webhook
    cases_dispatch_webhook('case.created', [
        '_pdo' => $pdo,
        'caseId' => $id,
        'regNumber' => $regNumber,
        'caseType' => $caseType === 'appeal' ? 'Обращение' : 'Жалоба',
        'source' => $source,
        'applicantName' => $applicantName,
        'description' => mb_substr($description, 0, 200, 'UTF-8'),
        'subject' => $subject,
        'factionId' => $factionId,
        'assignedName' => $formatted['assignedStaffName'],
        'supervisorName' => $formatted['supervisorName'],
        'deadline' => $deadline,
        'status' => CASES_STATUSES[$status],
        'forumLink' => $forumLink,
        'videoLink' => $videoLink,
        'discordUserIds' => cases_resolve_discord_ids($caseRow, $state),
    ]);

    respond(200, ['ok' => true, 'detail' => $formatted]);
}

function cases_handle_list(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $statusFilter = trim((string)($body['status'] ?? ''));
    $subjectFilter = trim((string)($body['subject'] ?? ''));
    $tab = trim((string)($body['tab'] ?? 'all'));

    $sql = 'SELECT * FROM cases WHERE deleted_at IS NULL';
    $params = [];

    $role = $user['role'] ?? '';
    if ($role === 'STAFF') {
        $sql .= ' AND (assigned_staff_id = :uid OR supervisor_id = :uid2 OR created_by = :uid3)';
        $params[':uid'] = $user['id'];
        $params[':uid2'] = $user['id'];
        $params[':uid3'] = $user['id'];
    } elseif ($role === 'BOSS' || $role === 'SENIOR_STAFF' || $role === 'USP') {
        $sql .= ' AND subject = :subject';
        $params[':subject'] = $user['subject'] ?? '';
    }
    // FEDERAL and ADMIN see all

    if ($statusFilter !== '' && isset(CASES_STATUSES[$statusFilter])) {
        $sql .= ' AND status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($subjectFilter !== '' && (($role === 'FEDERAL') || has_system_admin_access($user))) {
        $sql .= ' AND subject = :subject_filter';
        $params[':subject_filter'] = $subjectFilter;
    }

    if ($tab === 'archive') {
        $sql .= ' AND status = \'archive\'';
    } else {
        // Exclude archive from all other tabs
        $sql .= ' AND status != \'archive\'';
        if ($tab === 'my') {
            $sql .= ' AND assigned_staff_id = :my_uid';
            $params[':my_uid'] = $user['id'];
        } elseif ($tab === 'supervised') {
            $sql .= ' AND supervisor_id = :sv_uid AND status NOT IN (\'completed\', \'check_terminated\', \'criminal_case_refused\')';
            $params[':sv_uid'] = $user['id'];
        } elseif ($tab === 'overdue') {
            $sql .= ' AND deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN (\'completed\', \'check_terminated\', \'criminal_case_refused\', \'prosecution_refused\')';
        }
    }

    $sql .= ' ORDER BY created_at DESC';

    $rows = checks_fetch_all($pdo, $sql, $params);
    $result = [];
    foreach ($rows as $row) {
        $result[] = cases_format_row($row, $state);
    }

    respond(200, ['ok' => true, 'items' => $result]);
}

function cases_handle_get(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    if ($caseId === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) {
        respond(404, ['ok' => false, 'error' => 'Дело не найдено']);
    }
    if (!cases_user_can_view($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Нет доступа к этому делу']);
    }

    $formatted = cases_format_row($row, $state);

    // Timeline
    $history = checks_fetch_all($pdo, 'SELECT * FROM case_status_history WHERE case_id = :cid ORDER BY created_at ASC', [':cid' => $caseId]);
    $timeline = [];
    $users = $state['users'] ?? [];
    foreach ($history as $h) {
        $changerName = '';
        foreach ($users as $u) {
            if (($u['id'] ?? '') === $h['changed_by']) {
                $changerName = trim(($u['surname'] ?? '') . ' ' . ($u['name'] ?? '')) ?: ($u['login'] ?? '');
                break;
            }
        }
        $timeline[] = [
            'id' => $h['id'],
            'fromStatus' => $h['from_status'],
            'toStatus' => $h['to_status'],
            'toStatusLabel' => CASES_STATUSES[$h['to_status']] ?? $h['to_status'],
            'fromStatusLabel' => $h['from_status'] ? (CASES_STATUSES[$h['from_status']] ?? $h['from_status']) : null,
            'changedBy' => $h['changed_by'],
            'changedByName' => $changerName,
            'comment' => $h['comment'],
            'stageResult' => $h['stage_result'],
            'createdAt' => $h['created_at'],
        ];
    }

    // Comments
    $commentsRows = checks_fetch_all($pdo, 'SELECT * FROM case_comments WHERE case_id = :cid AND deleted_at IS NULL ORDER BY created_at ASC', [':cid' => $caseId]);
    $comments = [];
    foreach ($commentsRows as $c) {
        $authorName = '';
        foreach ($users as $u) {
            if (($u['id'] ?? '') === $c['author_id']) {
                $authorName = trim(($u['surname'] ?? '') . ' ' . ($u['name'] ?? '')) ?: ($u['login'] ?? '');
                break;
            }
        }
        $comments[] = [
            'id' => $c['id'],
            'authorId' => $c['author_id'],
            'authorName' => $authorName,
            'body' => $c['body'],
            'imageUrl' => $c['image_url'] ?? null,
            'isServiceNote' => (bool)$c['is_service_note'],
            'createdAt' => $c['created_at'],
        ];
    }

    // Links
    $linksRows = checks_fetch_all($pdo, 'SELECT * FROM case_links WHERE case_id = :cid ORDER BY created_at ASC', [':cid' => $caseId]);
    $links = [];
    foreach ($linksRows as $l) {
        $adderName = '';
        foreach ($users as $u) {
            if (($u['id'] ?? '') === $l['added_by']) {
                $adderName = trim(($u['surname'] ?? '') . ' ' . ($u['name'] ?? '')) ?: ($u['login'] ?? '');
                break;
            }
        }
        $links[] = [
            'id' => $l['id'],
            'linkType' => $l['link_type'],
            'url' => $l['url'],
            'label' => $l['label'],
            'addedBy' => $l['added_by'],
            'addedByName' => $adderName,
            'createdAt' => $l['created_at'],
        ];
    }

    // Audit for this case
    $auditRows = checks_fetch_all($pdo, 'SELECT * FROM audit_logs WHERE entity_type = :et AND entity_id = :eid ORDER BY created_at DESC LIMIT 50', [
        ':et' => 'case',
        ':eid' => $caseId,
    ]);
    $audit = [];
    foreach ($auditRows as $a) {
        $audit[] = [
            'actionCode' => $a['action_code'],
            'actorUserId' => $a['actor_user_id'],
            'actorRole' => $a['actor_role'],
            'createdAt' => $a['created_at'],
        ];
    }

    $formatted['timeline'] = $timeline;
    $formatted['commentsThread'] = $comments;
    $formatted['links'] = $links;
    $formatted['audit'] = $audit;
    $formatted['allowedTransitions'] = CASES_STATUS_TRANSITIONS[$row['status']] ?? [];

    respond(200, ['ok' => true, 'detail' => $formatted]);
}

function cases_handle_update(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    if ($caseId === '') respond(422, ['ok' => false, 'error' => 'Укажите ID дела']);

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    $isManager = cases_user_can_manage($user, $row);
    $isAssigned = ($row['assigned_staff_id'] ?? '') === ($user['id'] ?? '');
    if (!$isManager && !$isAssigned) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    $updates = [];
    $params = [':id' => $caseId];

    $allowedFields = $isManager
        ? ['description', 'applicant_name', 'applicant_contact', 'faction_id', 'forum_link', 'severity', 'deadline', 'next_control_date', 'comments', 'stage_result', 'final_result', 'incident_date', 'sk_executor_name']
        : ['next_control_date', 'stage_result', 'comments'];

    $fieldMap = [
        'description' => 'description',
        'applicantName' => 'applicant_name',
        'applicantContact' => 'applicant_contact',
        'factionId' => 'faction_id',
        'forumLink' => 'forum_link',
        'severity' => 'severity',
        'deadline' => 'deadline',
        'nextControlDate' => 'next_control_date',
        'comments' => 'comments',
        'stageResult' => 'stage_result',
        'finalResult' => 'final_result',
        'incidentDate' => 'incident_date',
        'skExecutorName' => 'sk_executor_name',
    ];

    foreach ($fieldMap as $jsKey => $dbCol) {
        if (array_key_exists($jsKey, $body) && in_array($dbCol, $allowedFields, true)) {
            $val = trim((string)$body[$jsKey]);
            $updates[] = "$dbCol = :$dbCol";
            $params[":$dbCol"] = $val !== '' ? $val : null;
        }
    }

    if (empty($updates)) {
        respond(422, ['ok' => false, 'error' => 'Нечего обновлять']);
    }

    $updates[] = 'updated_at = :updated_at';
    $params[':updated_at'] = checks_now_storage();

    checks_execute($pdo, 'UPDATE cases SET ' . implode(', ', $updates) . ' WHERE id = :id', $params);

    checks_log_audit($pdo, 'case', $caseId, 'case.updated', $user, $row, $body);

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);
    respond(200, ['ok' => true, 'detail' => cases_format_row($updated, $state)]);
}

function cases_handle_change_status(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $newStatus = trim((string)($body['newStatus'] ?? ''));
    $comment = trim((string)($body['comment'] ?? ''));
    $stageResult = trim((string)($body['stageResult'] ?? ''));
    $finalResult = trim((string)($body['finalResult'] ?? ''));

    if ($caseId === '' || $newStatus === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела и новый статус']);
    }
    if (!isset(CASES_STATUSES[$newStatus])) {
        respond(422, ['ok' => false, 'error' => 'Неизвестный статус']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    if (!cases_user_can_change_status($user, $row, $newStatus)) {
        respond(403, ['ok' => false, 'error' => 'Переход «' . ($row['status'] ?? '') . ' → ' . $newStatus . '» недоступен']);
    }

    // Check required fields
    $required = CASES_TRANSITION_REQUIRED_FIELDS[$newStatus] ?? [];
    foreach ($required as $field) {
        if ($field === 'assigned_staff_id' && empty($row['assigned_staff_id'])) {
            respond(422, ['ok' => false, 'error' => 'Сначала назначьте исполнителя']);
        }
        if ($field === 'supervisor_id' && empty($row['supervisor_id'])) {
            respond(422, ['ok' => false, 'error' => 'Сначала назначьте прокурора']);
        }
        if ($field === 'stage_result' && $stageResult === '') {
            respond(422, ['ok' => false, 'error' => 'Укажите результат этапа']);
        }
        if ($field === 'final_result' && $finalResult === '') {
            respond(422, ['ok' => false, 'error' => 'Укажите итоговый результат']);
        }
    }

    $now = checks_now_storage();
    $oldStatus = $row['status'];

    $updateFields = ['status = :status', 'updated_at = :updated_at'];
    $updateParams = [':status' => $newStatus, ':updated_at' => $now, ':id' => $caseId];
    if ($stageResult !== '') {
        $updateFields[] = 'stage_result = :stage_result';
        $updateParams[':stage_result'] = $stageResult;
    }
    if ($finalResult !== '') {
        $updateFields[] = 'final_result = :final_result';
        $updateParams[':final_result'] = $finalResult;
    }

    // Save SK executor name when transitioning to transferred_investigation
    $skExecutorName = trim((string)($body['skExecutorName'] ?? ''));
    if ($newStatus === 'criminal_case_opened' && $skExecutorName !== '') {
        $updateFields[] = 'sk_executor_name = :sk_executor_name';
        $updateParams[':sk_executor_name'] = $skExecutorName;
    }

    checks_execute($pdo, 'UPDATE cases SET ' . implode(', ', $updateFields) . ' WHERE id = :id', $updateParams);

    // History
    checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, stage_result, created_at)
        VALUES (:id, :case_id, :from_status, :to_status, :changed_by, :comment, :stage_result, :created_at)', [
        ':id' => checks_uuid(),
        ':case_id' => $caseId,
        ':from_status' => $oldStatus,
        ':to_status' => $newStatus,
        ':changed_by' => $user['id'],
        ':comment' => $comment ?: null,
        ':stage_result' => $stageResult ?: null,
        ':created_at' => $now,
    ]);

    checks_log_audit($pdo, 'case', $caseId, 'case.status_changed', $user, ['status' => $oldStatus], ['status' => $newStatus]);

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);

    // Notifications
    cases_notify_participants($pdo, $updated, 'case.status_changed',
        'Статус обращения ' . $row['reg_number'] . ' изменён',
        'Новый статус: ' . (CASES_STATUSES[$newStatus] ?? $newStatus),
        'info', $state);

    // Discord thread
    cases_dispatch_webhook('case.status_changed', [
        '_pdo' => $pdo,
        'caseId' => $caseId,
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'discordThreadId' => $updated['discord_thread_id'] ?? '',
        'oldStatusLabel' => CASES_STATUSES[$oldStatus] ?? $oldStatus,
        'statusLabel' => CASES_STATUSES[$newStatus] ?? $newStatus,
        'newStatus' => $newStatus,
        'skExecutorName' => $updated['sk_executor_name'] ?? '',
        'discordUserIds' => cases_resolve_discord_ids($updated, $state),
    ]);

    respond(200, ['ok' => true, 'detail' => cases_format_row($updated, $state), 'transition' => ['from' => $oldStatus, 'to' => $newStatus]]);
}

function cases_handle_assign_staff(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $staffId = trim((string)($body['staffId'] ?? ''));
    $staffName = trim((string)($body['staffName'] ?? ''));

    if ($caseId === '' || ($staffId === '' && $staffName === '')) {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела и ФИО следователя']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    // Any user who can view the case can assign staff
    if (!cases_user_can_view($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    $now = checks_now_storage();
    if ($staffId !== '') {
        // System user assignment
        checks_execute($pdo, 'UPDATE cases SET assigned_staff_id = :staff, assigned_staff_name = NULL, updated_at = :now WHERE id = :id', [
            ':staff' => $staffId, ':now' => $now, ':id' => $caseId,
        ]);
    } else {
        // Free-text name assignment
        checks_execute($pdo, 'UPDATE cases SET assigned_staff_id = NULL, assigned_staff_name = :name, updated_at = :now WHERE id = :id', [
            ':name' => $staffName, ':now' => $now, ':id' => $caseId,
        ]);
    }

    // If status is 'registered', auto-advance to 'assigned_staff'
    if ($row['status'] === 'registered') {
        checks_execute($pdo, 'UPDATE cases SET status = :status WHERE id = :id', [':status' => 'assigned_staff', ':id' => $caseId]);
        checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, created_at)
            VALUES (:id, :case_id, :from, :to, :by, :comment, :at)', [
            ':id' => checks_uuid(), ':case_id' => $caseId, ':from' => 'registered',
            ':to' => 'assigned_staff', ':by' => $user['id'], ':comment' => 'Следователь назначен', ':at' => $now,
        ]);
    }

    checks_log_audit($pdo, 'case', $caseId, 'case.assigned', $user, ['assignedStaffId' => $row['assigned_staff_id']], ['assignedStaffId' => $staffId, 'assignedStaffName' => $staffName]);

    if ($staffId !== '') {
        checks_create_notification($pdo, $staffId, 'case.assigned', 'case', $caseId,
            'Назначение на обращение ' . $row['reg_number'],
            'Вы назначены следователем по обращению ' . $row['reg_number'],
            'warning', 'cases', ['caseId' => $caseId]);
    }

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);
    $formatted = cases_format_row($updated, $state);

    // Discord thread
    cases_dispatch_webhook('case.assigned', [
        '_pdo' => $pdo,
        'caseId' => $caseId,
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'discordThreadId' => $updated['discord_thread_id'] ?? '',
        'assignedName' => $formatted['assignedStaffName'],
        'statusLabel' => CASES_STATUSES[$updated['status']] ?? $updated['status'],
        'discordUserIds' => cases_resolve_discord_ids($updated, $state),
    ]);

    respond(200, ['ok' => true, 'detail' => $formatted]);
}

function cases_handle_assign_supervisor(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $supervisorId = trim((string)($body['supervisorId'] ?? ''));

    if ($caseId === '' || $supervisorId === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела и ID прокурора']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);
    if (!cases_user_can_view($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    $now = checks_now_storage();
    checks_execute($pdo, 'UPDATE cases SET supervisor_id = :sup, updated_at = :now WHERE id = :id', [
        ':sup' => $supervisorId, ':now' => $now, ':id' => $caseId,
    ]);

    // Auto-advance status if appropriate
    if ($row['status'] === 'assigned_staff') {
        checks_execute($pdo, 'UPDATE cases SET status = :status WHERE id = :id', [':status' => 'assigned_supervisor', ':id' => $caseId]);
        checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, created_at)
            VALUES (:id, :case_id, :from, :to, :by, :comment, :at)', [
            ':id' => checks_uuid(), ':case_id' => $caseId, ':from' => 'assigned_staff',
            ':to' => 'assigned_supervisor', ':by' => $user['id'], ':comment' => 'Надзирающий назначен', ':at' => $now,
        ]);
    }

    checks_log_audit($pdo, 'case', $caseId, 'case.supervisor_assigned', $user, ['supervisorId' => $row['supervisor_id']], ['supervisorId' => $supervisorId]);

    checks_create_notification($pdo, $supervisorId, 'case.supervisor_assigned', 'case', $caseId,
        'Надзор за обращением ' . $row['reg_number'],
        'Вы назначены прокурором по обращению ' . $row['reg_number'],
        'info', 'cases', ['caseId' => $caseId]);

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);
    $formatted = cases_format_row($updated, $state);

    // Discord thread
    cases_dispatch_webhook('case.supervisor_assigned', [
        '_pdo' => $pdo,
        'caseId' => $caseId,
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'discordThreadId' => $updated['discord_thread_id'] ?? '',
        'supervisorName' => $formatted['supervisorName'],
        'statusLabel' => CASES_STATUSES[$updated['status']] ?? $updated['status'],
        'discordUserIds' => cases_resolve_discord_ids($updated, $state),
    ]);

    respond(200, ['ok' => true, 'detail' => $formatted]);
}

function cases_handle_add_comment(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $commentBody = trim((string)($body['body'] ?? ''));
    $imageUrl = trim((string)($body['imageUrl'] ?? ''));
    $isServiceNote = (bool)($body['isServiceNote'] ?? false);

    if ($caseId === '' || ($commentBody === '' && $imageUrl === '')) {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела и текст или изображение']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);
    if (!cases_user_can_comment($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    $commentId = checks_uuid();
    $now = checks_now_storage();
    checks_execute($pdo, 'INSERT INTO case_comments (id, case_id, author_id, body, is_service_note, created_at) VALUES (:id, :case_id, :author_id, :body, :is_service_note, :created_at)', [
        ':id' => $commentId,
        ':case_id' => $caseId,
        ':author_id' => $user['id'],
        ':body' => $commentBody ?: '',
        ':is_service_note' => $isServiceNote ? 1 : 0,
        ':created_at' => $now,
    ]);

    // Update image_url if provided (column may not exist yet on first run)
    if ($imageUrl !== '') {
        try {
            checks_execute($pdo, 'UPDATE case_comments SET image_url = :url WHERE id = :id', [
                ':url' => $imageUrl,
                ':id' => $commentId,
            ]);
        } catch (\Exception $e) { /* image_url column might not exist */ }
    }

    checks_log_audit($pdo, 'case', $caseId, 'case.comment_added', $user);

    // Discord thread
    if (!$isServiceNote) {
        $authorFullName = trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '')) ?: ($user['login'] ?? '');
        cases_dispatch_webhook('case.comment_added', [
            '_pdo' => $pdo,
            'caseId' => $caseId,
            'regNumber' => $row['reg_number'],
            'subject' => $row['subject'],
            'discordThreadId' => $row['discord_thread_id'] ?? '',
            'commentBody' => $commentBody,
            'commentAuthor' => $authorFullName,
            'imageUrl' => $imageUrl ?: null,
        ]);
    }

    respond(200, ['ok' => true, 'comment' => [
        'id' => $commentId,
        'authorId' => $user['id'],
        'authorName' => $authorName,
        'body' => $commentBody,
        'imageUrl' => $imageUrl ?: null,
        'isServiceNote' => $isServiceNote,
        'createdAt' => $now,
    ]]);
}

function cases_handle_add_link(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $url = trim((string)($body['url'] ?? ''));
    $linkType = trim((string)($body['linkType'] ?? 'other'));
    $label = trim((string)($body['label'] ?? ''));

    if ($caseId === '' || $url === '') {
        respond(422, ['ok' => false, 'error' => 'Укажите ID дела и URL']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);
    if (!cases_user_can_manage($user, $row) && ($row['assigned_staff_id'] ?? '') !== ($user['id'] ?? '')) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    if (!in_array($linkType, ['material', 'lawsuit', 'procedural', 'other'], true)) {
        $linkType = 'other';
    }

    $linkId = checks_uuid();
    $now = checks_now_storage();
    checks_execute($pdo, 'INSERT INTO case_links (id, case_id, link_type, url, label, added_by, created_at) VALUES (:id, :case_id, :link_type, :url, :label, :added_by, :created_at)', [
        ':id' => $linkId, ':case_id' => $caseId, ':link_type' => $linkType,
        ':url' => $url, ':label' => $label ?: null, ':added_by' => $user['id'], ':created_at' => $now,
    ]);

    checks_log_audit($pdo, 'case', $caseId, 'case.link_added', $user, null, ['url' => $url, 'linkType' => $linkType]);

    // Discord thread
    cases_dispatch_webhook('case.link_added', [
        '_pdo' => $pdo,
        'caseId' => $caseId,
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'discordThreadId' => $row['discord_thread_id'] ?? '',
        'linkUrl' => $url,
        'linkLabel' => $label ?: $url,
    ]);

    respond(200, ['ok' => true, 'link' => [
        'id' => $linkId, 'linkType' => $linkType, 'url' => $url, 'label' => $label,
        'addedBy' => $user['id'],
        'addedByName' => trim(($user['surname'] ?? '') . ' ' . ($user['name'] ?? '')) ?: ($user['login'] ?? ''),
        'createdAt' => $now,
    ]]);
}

function cases_handle_delete_link(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $linkId = trim((string)($body['linkId'] ?? ''));
    if ($linkId === '') respond(422, ['ok' => false, 'error' => 'Укажите ID ссылки']);

    $link = checks_fetch_one($pdo, 'SELECT * FROM case_links WHERE id = :id', [':id' => $linkId]);
    if (!$link) respond(404, ['ok' => false, 'error' => 'Ссылка не найдена']);

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $link['case_id']]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    $isOwner = ($link['added_by'] ?? '') === ($user['id'] ?? '');
    if (!cases_user_can_manage($user, $row) && !$isOwner) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    checks_execute($pdo, 'DELETE FROM case_links WHERE id = :id', [':id' => $linkId]);
    checks_log_audit($pdo, 'case', $link['case_id'], 'case.link_deleted', $user, $link);

    respond(200, ['ok' => true]);
}

function cases_handle_delete(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    if ($caseId === '') respond(422, ['ok' => false, 'error' => 'Укажите ID дела']);

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);
    if (!cases_user_can_manage($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для удаления']);
    }

    $now = checks_now_storage();
    checks_execute($pdo, 'UPDATE cases SET deleted_at = :now, updated_at = :now2 WHERE id = :id', [
        ':now' => $now, ':now2' => $now, ':id' => $caseId,
    ]);

    // Remove all notifications linked to this case
    checks_mark_notifications_deleted($pdo, 'case', $caseId);

    checks_log_audit($pdo, 'case', $caseId, 'case.deleted', $user, $row);

    respond(200, ['ok' => true]);
}

function cases_handle_analytics(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    if (($user['role'] ?? '') === 'STAFF') {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав']);
    }

    $subjectFilter = '';
    if (in_array($user['role'] ?? '', ['BOSS', 'SENIOR_STAFF'], true)) {
        $subjectFilter = $user['subject'] ?? '';
    }
    $body = read_json_body();
    if (!empty($body['subject']) && (in_array($user['role'] ?? '', ['FEDERAL', 'USP'], true) || has_system_admin_access($user))) {
        $subjectFilter = trim((string)$body['subject']);
    }

    $where = 'deleted_at IS NULL';
    $params = [];
    if ($subjectFilter !== '') {
        $where .= ' AND subject = :subject';
        $params[':subject'] = $subjectFilter;
    }

    $rows = checks_fetch_all($pdo, "SELECT * FROM cases WHERE $where", $params);

    $byStatus = [];
    foreach (CASES_STATUSES as $code => $label) {
        $byStatus[$code] = 0;
    }
    $bySource = ['forum' => 0, 'oral' => 0, 'internal' => 0, 'sk_transfer' => 0, 'fsb_transfer' => 0, 'other' => 0];
    $byType = ['appeal' => 0, 'complaint' => 0];
    $byFaction = [];
    $byStaff = [];
    $overdueCount = 0;
    $totalActive = 0;
    $now = new DateTime();

    foreach ($rows as $r) {
        $st = $r['status'] ?? '';
        if (isset($byStatus[$st])) $byStatus[$st]++;
        $src = $r['source'] ?? '';
        if (isset($bySource[$src])) $bySource[$src]++;
        $ct = $r['case_type'] ?? '';
        if (isset($byType[$ct])) $byType[$ct]++;

        $fid = $r['faction_id'] ?? '';
        if ($fid !== '') {
            if (!isset($byFaction[$fid])) $byFaction[$fid] = ['total' => 0, 'complaints' => 0, 'toInvestigation' => 0, 'toCourt' => 0, 'verdicts' => 0, 'terminated' => 0];
            $byFaction[$fid]['total']++;
            if ($ct === 'complaint') $byFaction[$fid]['complaints']++;
            if (in_array($st, ['transferred_investigation', 'investigation_check', 'criminal_case_opened', 'under_investigation', 'indictment_drafted', 'prosecution_review', 'prosecution_approved', 'sent_to_court', 'verdict_issued'], true)) $byFaction[$fid]['toInvestigation']++;
            if (in_array($st, ['sent_to_court', 'verdict_issued'], true)) $byFaction[$fid]['toCourt']++;
            if ($st === 'verdict_issued') $byFaction[$fid]['verdicts']++;
            if ($st === 'check_terminated') $byFaction[$fid]['terminated']++;
        }

        $staffUid = $r['assigned_staff_id'] ?? '';
        if ($staffUid !== '') {
            if (!isset($byStaff[$staffUid])) $byStaff[$staffUid] = ['assigned' => 0, 'completed' => 0, 'overdue' => 0];
            $byStaff[$staffUid]['assigned']++;
            if (in_array($st, CASES_TERMINAL_STATUSES, true)) $byStaff[$staffUid]['completed']++;
        }

        if (!in_array($st, CASES_TERMINAL_STATUSES, true)) {
            $totalActive++;
            if (!empty($r['deadline'])) {
                $dl = new DateTime($r['deadline']);
                if ($dl < $now) {
                    $overdueCount++;
                    if ($staffUid !== '' && isset($byStaff[$staffUid])) $byStaff[$staffUid]['overdue']++;
                }
            }
        }
    }

    // Resolve staff names
    $staffStats = [];
    foreach ($byStaff as $uid => $stats) {
        $staffName = $uid;
        foreach ($state['users'] ?? [] as $u) {
            if (($u['id'] ?? '') === $uid) {
                $staffName = trim(($u['surname'] ?? '') . ' ' . ($u['name'] ?? '')) ?: ($u['login'] ?? $uid);
                break;
            }
        }
        $staffStats[] = array_merge($stats, ['userId' => $uid, 'name' => $staffName]);
    }

    // Aggregate by subject
    $bySubject = [];
    foreach ($rows as $r) {
        $sub = $r['subject'] ?? '';
        if ($sub === '') continue;
        if (!isset($bySubject[$sub])) $bySubject[$sub] = ['total' => 0, 'active' => 0, 'overdue' => 0];
        $bySubject[$sub]['total']++;
        $st = $r['status'] ?? '';
        if (!in_array($st, CASES_TERMINAL_STATUSES, true)) {
            $bySubject[$sub]['active']++;
            if (!empty($r['deadline']) && new DateTime($r['deadline']) < $now) {
                $bySubject[$sub]['overdue']++;
            }
        }
    }

    respond(200, ['ok' => true, 'data' => [
        'total' => count($rows),
        'totalActive' => $totalActive,
        'overdue' => $overdueCount,
        'byStatus' => $byStatus,
        'bySource' => $bySource,
        'byType' => $byType,
        'byFaction' => $byFaction,
        'byStaff' => $staffStats,
        'bySubject' => $bySubject,
    ]]);
}

function cases_handle_deadline_check(PDO $pdo, array &$state): void
{
    $terminalStr = "'" . implode("','", CASES_TERMINAL_STATUSES) . "'";
    $rows = checks_fetch_all($pdo, "SELECT * FROM cases WHERE deleted_at IS NULL AND deadline IS NOT NULL AND status NOT IN ($terminalStr)", []);

    $now = new DateTime();
    $warningDays = 5;
    $warnings = 0;
    $overdues = 0;

    foreach ($rows as $r) {
        $dl = new DateTime($r['deadline']);
        $diff = (int)$now->diff($dl)->format('%r%a');

        if ($diff < 0) {
            // Overdue
            $overdues++;
            cases_notify_participants($pdo, $r, 'case.overdue',
                'Просрочено обращение ' . $r['reg_number'],
                'Срок: ' . $r['deadline'] . '. Просрочено на ' . abs($diff) . ' дн.',
                'critical', $state);
            cases_dispatch_webhook('case.overdue', [
                '_pdo' => $pdo,
                'caseId' => $r['id'],
                'regNumber' => $r['reg_number'],
                'subject' => $r['subject'],
                'discordThreadId' => $r['discord_thread_id'] ?? '',
                'deadline' => $r['deadline'],
                'description' => 'Просрочено на ' . abs($diff) . ' дн.',
                'discordUserIds' => cases_resolve_discord_ids($r, $state),
            ]);

        } elseif ($diff <= $warningDays && $diff >= 0) {
            $warnings++;
            cases_notify_participants($pdo, $r, 'case.deadline_approaching',
                'Срок по обращению ' . $r['reg_number'],
                'До срока осталось ' . $diff . ' дн. (' . $r['deadline'] . ')',
                'warning', $state);
            cases_dispatch_webhook('case.deadline_approaching', [
                '_pdo' => $pdo,
                'caseId' => $r['id'],
                'regNumber' => $r['reg_number'],
                'subject' => $r['subject'],
                'discordThreadId' => $r['discord_thread_id'] ?? '',
                'deadline' => $r['deadline'],
                'description' => 'До срока осталось ' . $diff . ' дн.',
                'discordUserIds' => cases_resolve_discord_ids($r, $state),
            ]);

        }
    }

    respond(200, ['ok' => true, 'warnings' => $warnings, 'overdues' => $overdues]);
}

function cases_handle_discord_sync(PDO $pdo, array &$state): void
{
    checks_discord_verify_secret();
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    if ($caseId === '') respond(422, ['ok' => false, 'error' => 'caseId required']);

    $updates = [];
    $params = [':id' => $caseId, ':now' => checks_now_storage()];
    if (!empty($body['discordThreadId'])) {
        $updates[] = 'discord_thread_id = :tid';
        $params[':tid'] = (string)$body['discordThreadId'];
    }
    if (!empty($body['discordMessageId'])) {
        $updates[] = 'discord_message_id = :mid';
        $params[':mid'] = (string)$body['discordMessageId'];
    }
    if (!empty($body['discordChannelId'])) {
        $updates[] = 'discord_channel_id = :cid';
        $params[':cid'] = (string)$body['discordChannelId'];
    }
    if (empty($updates)) {
        respond(200, ['ok' => true]);
    }
    $updates[] = 'updated_at = :now';
    checks_execute($pdo, 'UPDATE cases SET ' . implode(', ', $updates) . ' WHERE id = :id', $params);
    respond(200, ['ok' => true]);
}

function cases_handle_discord_list(PDO $pdo, array &$state): void
{
    checks_discord_verify_secret();
    $body = read_json_body();
    $status = trim((string)($body['status'] ?? ''));
    $limit = min(25, max(1, (int)($body['limit'] ?? 10)));

    $sql = 'SELECT id, reg_number, subject, case_type, source, status, applicant_name, deadline, created_at FROM cases WHERE deleted_at IS NULL';
    $params = [];
    if ($status !== '' && isset(CASES_STATUSES[$status])) {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

    $rows = checks_fetch_all($pdo, $sql, $params);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => $r['id'],
            'regNumber' => $r['reg_number'],
            'subject' => $r['subject'],
            'caseType' => $r['case_type'],
            'status' => $r['status'],
            'statusLabel' => CASES_STATUSES[$r['status']] ?? $r['status'],
            'applicantName' => $r['applicant_name'],
            'deadline' => $r['deadline'],
            'createdAt' => $r['created_at'],
        ];
    }
    respond(200, ['success' => true, 'data' => $items]);
}

function cases_build_bootstrap_meta(PDO $pdo, array $state, ?array $user): array
{
    $meta = [
        'counters' => ['total' => 0, 'assigned' => 0, 'supervised' => 0, 'overdue' => 0, 'active' => 0],
        'permissions' => ['canCreate' => false, 'canAccessModule' => false],
    ];
    if (!$user) return $meta;

    cases_ensure_schema($pdo);

    $meta['permissions']['canCreate'] = cases_user_can_create($user);
    $role = $user['role'] ?? '';
    $uid = $user['id'] ?? '';
    $subject = $user['subject'] ?? '';

    $where = 'deleted_at IS NULL';
    $params = [];
    if ($role === 'STAFF') {
        $where .= ' AND (assigned_staff_id = :uid OR supervisor_id = :uid2)';
        $params[':uid'] = $uid;
        $params[':uid2'] = $uid;
    } elseif ($role === 'BOSS' || $role === 'SENIOR_STAFF' || $role === 'USP') {
        $where .= ' AND subject = :subject';
        $params[':subject'] = $subject;
    }

    $rows = checks_fetch_all($pdo, "SELECT id, status, assigned_staff_id, supervisor_id, deadline FROM cases WHERE $where", $params);

    $now = new DateTime();
    $warn3 = (clone $now)->modify('+3 days');
    $total = count($rows);
    $assigned = 0;
    $supervised = 0;
    $overdue = 0;
    $active = 0;
    $approaching = 0;
    $approachingCases = [];
    foreach ($rows as $r) {
        if (($r['assigned_staff_id'] ?? '') === $uid) $assigned++;
        if (($r['supervisor_id'] ?? '') === $uid && !in_array($r['status'], CASES_TERMINAL_STATUSES, true)) $supervised++;
        if (!in_array($r['status'], CASES_TERMINAL_STATUSES, true)) {
            $active++;
            if (!empty($r['deadline'])) {
                $dl = new DateTime($r['deadline']);
                if ($dl < $now) {
                    $overdue++;
                } elseif ($dl <= $warn3) {
                    $approaching++;
                    $approachingCases[] = $r['id'];
                }
            }
        }
    }

    $meta['counters'] = compact('total', 'assigned', 'supervised', 'overdue', 'active', 'approaching');
    $meta['approachingCaseIds'] = $approachingCases;
    $meta['permissions']['canAccessModule'] = $total > 0 || $meta['permissions']['canCreate'] || has_system_admin_access($user) || in_array($role, ['FEDERAL', 'USP'], true);

    return $meta;
}

function cases_handle_set_deadline(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $deadline = trim((string)($body['deadline'] ?? ''));

    if ($caseId === '') respond(422, ['ok' => false, 'error' => 'Укажите ID дела']);
    if ($deadline === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        respond(422, ['ok' => false, 'error' => 'Укажите корректную дату крайнего срока']);
    }

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    if (!cases_user_can_manage($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для установки срока']);
    }

    if ($row['deadline']) {
        respond(422, ['ok' => false, 'error' => 'Крайний срок уже установлен. Используйте продление']);
    }

    if (in_array($row['status'], CASES_TERMINAL_STATUSES, true)) {
        respond(422, ['ok' => false, 'error' => 'Нельзя установить срок завершённого дела']);
    }

    $now = checks_now_storage();
    checks_execute($pdo, 'UPDATE cases SET deadline = :deadline, deadline_original = :deadline, updated_at = :updated_at WHERE id = :id', [
        ':deadline' => $deadline,
        ':updated_at' => $now,
        ':id' => $caseId,
    ]);

    checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, created_at)
        VALUES (:id, :case_id, :from_status, :to_status, :changed_by, :comment, :created_at)', [
        ':id' => checks_uuid(),
        ':case_id' => $caseId,
        ':from_status' => $row['status'],
        ':to_status' => $row['status'],
        ':changed_by' => $user['id'],
        ':comment' => 'Установлен крайний срок: ' . $deadline,
        ':created_at' => $now,
    ]);

    checks_log_audit($pdo, 'case', $caseId, 'case.deadline_set', $user, null, ['deadline' => $deadline]);

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);
    respond(200, ['ok' => true, 'detail' => cases_format_row($updated, $state)]);
}

function cases_handle_extend_deadline(PDO $pdo, array &$state): void
{
    $user = require_auth($state);
    $body = read_json_body();
    $caseId = trim((string)($body['caseId'] ?? ''));
    $reason = trim((string)($body['reason'] ?? ''));
    $imageUrl = trim((string)($body['imageUrl'] ?? ''));

    if ($caseId === '') respond(422, ['ok' => false, 'error' => 'Укажите ID дела']);
    if ($reason === '') respond(422, ['ok' => false, 'error' => 'Укажите основание продления (официальная бумага)']);

    $row = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id AND deleted_at IS NULL', [':id' => $caseId]);
    if (!$row) respond(404, ['ok' => false, 'error' => 'Дело не найдено']);

    if (!cases_user_can_manage($user, $row)) {
        respond(403, ['ok' => false, 'error' => 'Недостаточно прав для продления срока']);
    }

    if (in_array($row['status'], CASES_TERMINAL_STATUSES, true)) {
        respond(422, ['ok' => false, 'error' => 'Нельзя продлить срок завершённого дела']);
    }

    if (!$row['deadline']) {
        respond(422, ['ok' => false, 'error' => 'У дела не установлен крайний срок']);
    }

    if ($row['deadline_extended']) {
        respond(422, ['ok' => false, 'error' => 'Срок уже был продлён. Повторное продление невозможно']);
    }

    $currentDeadline = new DateTime($row['deadline']);
    $newDeadline = (clone $currentDeadline)->modify('+' . CASES_EXTENSION_DAYS . ' days');
    $now = checks_now_storage();

    checks_execute($pdo, 'UPDATE cases SET deadline = :deadline, deadline_extended = 1, deadline_extension_reason = :reason, deadline_original = :original, updated_at = :updated_at WHERE id = :id', [
        ':deadline' => $newDeadline->format('Y-m-d'),
        ':reason' => $reason,
        ':original' => $row['deadline'],
        ':updated_at' => $now,
        ':id' => $caseId,
    ]);

    // Status history entry
    checks_execute($pdo, 'INSERT INTO case_status_history (id, case_id, from_status, to_status, changed_by, comment, created_at)
        VALUES (:id, :case_id, :from_status, :to_status, :changed_by, :comment, :created_at)', [
        ':id' => checks_uuid(),
        ':case_id' => $caseId,
        ':from_status' => $row['status'],
        ':to_status' => $row['status'],
        ':changed_by' => $user['id'],
        ':comment' => 'Срок продлён на ' . CASES_EXTENSION_DAYS . ' дн. (с ' . $row['deadline'] . ' на ' . $newDeadline->format('Y-m-d') . '). Основание: ' . $reason,
        ':created_at' => $now,
    ]);

    checks_log_audit($pdo, 'case', $caseId, 'case.deadline_extended', $user, $row, [
        'oldDeadline' => $row['deadline'],
        'newDeadline' => $newDeadline->format('Y-m-d'),
        'reason' => $reason,
    ]);

    cases_notify_participants($pdo, $row, 'case.deadline_extended',
        'Срок продлён',
        'Срок дела ' . $row['reg_number'] . ' продлён до ' . $newDeadline->format('Y-m-d') . '. Основание: ' . $reason,
        'normal', $state);

    // Discord thread
    cases_dispatch_webhook('case.deadline_extended', [
        '_pdo' => $pdo,
        'caseId' => $caseId,
        'regNumber' => $row['reg_number'],
        'subject' => $row['subject'],
        'discordThreadId' => $row['discord_thread_id'] ?? '',
        'description' => 'Срок продлён с ' . $row['deadline'] . ' до ' . $newDeadline->format('Y-m-d') . "\nОснование: " . $reason,
        'deadline' => $newDeadline->format('Y-m-d'),
        'discordUserIds' => cases_resolve_discord_ids($row, $state),
    ]);

    // Save extension image as link if provided
    if ($imageUrl !== '') {
        checks_execute($pdo, 'INSERT INTO case_links (id, case_id, link_type, url, label, added_by, created_at) VALUES (:id, :case_id, :link_type, :url, :label, :added_by, :created_at)', [
            ':id' => checks_uuid(),
            ':case_id' => $caseId,
            ':link_type' => 'procedural',
            ':url' => $imageUrl,
            ':label' => 'Документ продления срока',
            ':added_by' => $user['id'],
            ':created_at' => $now,
        ]);
    }

    $updated = checks_fetch_one($pdo, 'SELECT * FROM cases WHERE id = :id', [':id' => $caseId]);
    respond(200, ['ok' => true, 'detail' => cases_format_row($updated, $state)]);
}

function cases_handle_action(PDO $pdo, string $action, array &$state): bool
{
    if (!str_starts_with($action, 'cases.')) {
        return false;
    }

    cases_ensure_schema($pdo);

    switch ($action) {
        case 'cases.list':
            cases_handle_list($pdo, $state);
            return true;
        case 'cases.get':
            cases_handle_get($pdo, $state);
            return true;
        case 'cases.create':
            cases_handle_create($pdo, $state);
            return true;
        case 'cases.update':
            cases_handle_update($pdo, $state);
            return true;
        case 'cases.change-status':
            cases_handle_change_status($pdo, $state);
            return true;
        case 'cases.assign-staff':
            cases_handle_assign_staff($pdo, $state);
            return true;
        case 'cases.assign-supervisor':
            cases_handle_assign_supervisor($pdo, $state);
            return true;
        case 'cases.add-comment':
            cases_handle_add_comment($pdo, $state);
            return true;
        case 'cases.add-link':
            cases_handle_add_link($pdo, $state);
            return true;
        case 'cases.delete-link':
            cases_handle_delete_link($pdo, $state);
            return true;
        case 'cases.set-deadline':
            cases_handle_set_deadline($pdo, $state);
            return true;
        case 'cases.extend-deadline':
            cases_handle_extend_deadline($pdo, $state);
            return true;
        case 'cases.delete':
            cases_handle_delete($pdo, $state);
            return true;
        case 'cases.analytics':
            cases_handle_analytics($pdo, $state);
            return true;
        case 'cases.deadline-check':
            cases_handle_deadline_check($pdo, $state);
            return true;
        case 'cases.discord.sync':
            cases_handle_discord_sync($pdo, $state);
            return true;
        case 'cases.discord.list':
            cases_handle_discord_list($pdo, $state);
            return true;
        default:
            respond(404, ['ok' => false, 'error' => 'Неизвестное действие модуля обращений']);
            return true;
    }
}
