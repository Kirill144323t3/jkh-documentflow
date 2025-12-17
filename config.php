<?php
session_start();

// Функции безопасности
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function isPasswordStrong($password) {
    return strlen($password) >= 6 && 
           preg_match('/[A-Za-z]/', $password) && 
           preg_match('/[0-9]/', $password);
}

// Настройки подключения к БД
$host = '134.90.167.42';
$port = '10306';
$dbname = 'project_Knyazev';
$username = 'Knyazev';
$password = '60eP8_';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // Проверяем соединение
    $pdo->query("SELECT 1");
    
} catch (PDOException $e) {
    $error_message = "Ошибка подключения к базе данных: " . htmlspecialchars($e->getMessage());
    $error_message .= "<br>Хост: $host, Порт: $port, База: $dbname";
    die($error_message);
}

// Функция для получения случайного ФИО из API
function getRandomFullName() {
    $api_url = "http://prb.sylas.ru/TransferSimulator/fullName";
    
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);
        
        $response = file_get_contents($api_url, false, $context);
        $data = json_decode($response, true);
        
        if (isset($data['value']) && !empty($data['value'])) {
            return $data['value'];
        }
    } catch (Exception $e) {
        error_log("API Error: " . $e->getMessage());
    }
    
    // Fallback - случайное ФИО если API недоступно
    $names = [
        'Иванов Иван Иванович',
        'Петрова Мария Сергеевна', 
        'Сидоров Алексей Петрович',
        'Козлова Анна Владимировна',
        'Николаев Дмитрий Сергеевич',
        'Федорова Елена Викторовна'
    ];
    return $names[array_rand($names)];
}

// Функции для капчи
function verifyCaptcha($submitted_placement) {
    if (!is_array($submitted_placement)) {
        return false;
    }
    
    return (
        isset($submitted_placement['0,0']) && $submitted_placement['0,0'] === '1' &&
        isset($submitted_placement['1,0']) && $submitted_placement['1,0'] === '2' &&
        isset($submitted_placement['0,1']) && $submitted_placement['0,1'] === '3' &&
        isset($submitted_placement['1,1']) && $submitted_placement['1,1'] === '4'
    );
}

function generateNewCaptcha() {
    $display_order = [1, 2, 3, 4];
    shuffle($display_order);
    $_SESSION['captcha_display_order'] = $display_order;
    return $display_order;
}

function getCurrentCaptchaOrder() {
    if (!isset($_SESSION['captcha_display_order'])) {
        return generateNewCaptcha();
    }
    return $_SESSION['captcha_display_order'];
}

function clearCaptcha() {
    unset($_SESSION['captcha_display_order']);
}

// Вспомогательные функции
function checkAndUnblockUser(&$user, $pdo) {
    if ($user['BlockedUntil'] && strtotime($user['BlockedUntil']) <= time()) {
        $stmt = $pdo->prepare("UPDATE registration SET WrongAttempts = 0, BlockedUntil = NULL, IsBlocked = 0 WHERE UserID = :userID");
        $stmt->execute([':userID' => $user['UserID']]);
        $user['BlockedUntil'] = null;
        $user['WrongAttempts'] = 0;
        $user['IsBlocked'] = 0;
    }
}

function isDepartmentHead($role_id) {
    $department_head_roles = [20, 21, 22, 23, 18, 4];
    return in_array($role_id, $department_head_roles);
}

function getDepartmentByHeadRole($role_id) {
    $department_map = [
        20 => 'Бухгалтерия',
        21 => 'Жилищная служба', 
        22 => 'Отдел энергоснабжения',
        23 => 'Благоустройство',
        18 => 'Руководитель',
        4 => 'Руководитель'
    ];
    return $department_map[$role_id] ?? null;
}

function getDepartmentIdByHeadRole($role_id, $pdo) {
    $department_map = [
        20 => 23,
        21 => 24, 
        22 => 25,  
        23 => 26
    ];
    
    $dept_id = $department_map[$role_id] ?? null;
    
    if (in_array($role_id, [18, 4])) {
        $stmt = $pdo->prepare("SELECT DepartmentID FROM users WHERE UserID = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $dept_id = $stmt->fetch(PDO::FETCH_COLUMN);
    }
    
    if ($dept_id) {
        $stmt = $pdo->prepare("SELECT DepartmentID FROM departments WHERE DepartmentID = ?");
        $stmt->execute([$dept_id]);
        return $stmt->fetch(PDO::FETCH_COLUMN) ? $dept_id : null;
    }
    
    return null;
}

function getDepartmentName($department_id, $pdo) {
    if (!$department_id) return null;
    
    $stmt = $pdo->prepare("SELECT DepartmentName FROM departments WHERE DepartmentID = ?");
    $stmt->execute([$department_id]);
    return $stmt->fetch(PDO::FETCH_COLUMN);
}

function canAccessDocument($document_id, $pdo) {
    if (!isset($_SESSION['user_id'])) return false;
    
    $user_id = $_SESSION['user_id'];
    $role_id = $_SESSION['role_id'];
    
    if ($role_id == 1) return true;
    
    if (isDepartmentHead($role_id)) {
        $user_dept_id = getDepartmentIdByHeadRole($role_id, $pdo);
        $stmt = $pdo->prepare("SELECT DepartmentID FROM documents WHERE DocumentID = ?");
        $stmt->execute([$document_id]);
        $doc_dept_id = $stmt->fetch(PDO::FETCH_COLUMN);
        
        return $user_dept_id == $doc_dept_id;
    }
    
    $stmt = $pdo->prepare("SELECT UserID FROM documents WHERE DocumentID = ?");
    $stmt->execute([$document_id]);
    $doc_user_id = $stmt->fetch(PDO::FETCH_COLUMN);
    
    return $user_id == $doc_user_id;
}

// Функции для работы с файлами
function uploadDocumentFile($file, $document_id, $pdo) {
    $uploadDir = __DIR__ . '/uploads/tasks/';
    
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $allowedTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed'
    ];
    
    $maxFileSize = 10 * 1024 * 1024;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Ошибка загрузки файла'];
    }
    
    if ($file['size'] > $maxFileSize) {
        return ['success' => false, 'error' => 'Файл слишком большой. Максимум 10MB'];
    }
    
    $fileType = mime_content_type($file['tmp_name']);
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileType, $allowedTypes) || !array_key_exists($fileExtension, $allowedTypes)) {
        return ['success' => false, 'error' => 'Недопустимый тип файла'];
    }
    
    $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $filePath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO document_files (DocumentID, FileName, FileType, FileSize, FilePath) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $document_id,
                $file['name'],
                $fileType,
                $file['size'],
                $fileName
            ]);
            
            return ['success' => true, 'file_id' => $pdo->lastInsertId()];
        } catch (PDOException $e) {
            unlink($filePath);
            return ['success' => false, 'error' => 'Ошибка сохранения в базу: ' . $e->getMessage()];
        }
    }
    
    return ['success' => false, 'error' => 'Ошибка перемещения файла'];
}

function getDocumentFiles($document_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM document_files WHERE DocumentID = ? ORDER BY UploadedAt DESC");
        $stmt->execute([$document_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function deleteDocumentFile($file_id, $pdo) {
    $stmt = $pdo->prepare("SELECT FilePath FROM document_files WHERE FileID = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        $filePath = __DIR__ . '/uploads/tasks/' . $file['FilePath'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        $stmt = $pdo->prepare("DELETE FROM document_files WHERE FileID = ?");
        $stmt->execute([$file_id]);
        return true;
    }
    
    return false;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function getUserType($role_id, $position, $pdo = null) {
    if ($role_id == 1) {
        return 'Администратор';
    } elseif (in_array($role_id, [20, 21, 22, 23, 18, 4])) {
        return 'Руководитель';
    } else {
        return $position ?: 'Сотрудник';
    }
}

// Функция для проверки существования таблиц
function checkDatabaseTables($pdo) {
    try {
        $tables = ['users', 'roles', 'departments', 'documents', 'documentstatus', 'registration', 'document_files'];
        $existing_tables = [];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $existing_tables[] = $table;
            }
        }
        
        return $existing_tables;
    } catch (PDOException $e) {
        return [];
    }
}

// Функции для блокировки пользователей при неверном пароле
function handleFailedLogin($login, $pdo) {
    try {
        // Находим пользователя по логину
        $stmt = $pdo->prepare("
            SELECT u.UserID, u.FullName, reg.WrongAttempts, reg.IsBlocked, reg.BlockedUntil 
            FROM users u 
            JOIN registration reg ON u.UserID = reg.UserID 
            WHERE reg.Login = :login
        ");
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Проверяем, не администратор ли это
            $stmt = $pdo->prepare("SELECT RoleID FROM users WHERE UserID = :userID");
            $stmt->execute([':userID' => $user['UserID']]);
            $role_id = $stmt->fetch(PDO::FETCH_COLUMN);
            
            // Администраторов не блокируем (RoleID = 1)
            if ($role_id == 1) {
                return "admin_immune";
            }
            
            $wrong_attempts = $user['WrongAttempts'] + 1;
            
            if ($wrong_attempts >= 3) {
                // Блокируем на 30 минут
                $block_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                $stmt = $pdo->prepare("
                    UPDATE registration 
                    SET WrongAttempts = :attempts, IsBlocked = 1, BlockedUntil = :blockUntil 
                    WHERE UserID = :userID
                ");
                $stmt->execute([
                    ':attempts' => $wrong_attempts,
                    ':blockUntil' => $block_until,
                    ':userID' => $user['UserID']
                ]);
                return "blocked";
            } else {
                // Увеличиваем счетчик неверных попыток
                $stmt = $pdo->prepare("
                    UPDATE registration 
                    SET WrongAttempts = :attempts 
                    WHERE UserID = :userID
                ");
                $stmt->execute([
                    ':attempts' => $wrong_attempts,
                    ':userID' => $user['UserID']
                ]);
                return "attempts_increased";
            }
        }
        return "user_not_found";
    } catch (PDOException $e) {
        error_log("Failed login handling error: " . $e->getMessage());
        return "error";
    }
}

function resetLoginAttempts($user_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE registration 
            SET WrongAttempts = 0, IsBlocked = 0, BlockedUntil = NULL 
            WHERE UserID = :userID
        ");
        $stmt->execute([':userID' => $user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Reset login attempts error: " . $e->getMessage());
        return false;
    }
}

function checkUserBlockStatus($user_id, $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT WrongAttempts, IsBlocked, BlockedUntil 
            FROM registration 
            WHERE UserID = :userID
        ");
        $stmt->execute([':userID' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Если блокировка истекла, разблокируем пользователя
            if ($user['IsBlocked'] && $user['BlockedUntil'] && strtotime($user['BlockedUntil']) <= time()) {
                resetLoginAttempts($user_id, $pdo);
                return [
                    'is_blocked' => false,
                    'wrong_attempts' => 0,
                    'blocked_until' => null,
                    'remaining_time' => 0
                ];
            }
            
            // Вычисляем оставшееся время блокировки
            $remaining_time = 0;
            if ($user['IsBlocked'] && $user['BlockedUntil']) {
                $remaining_time = max(0, strtotime($user['BlockedUntil']) - time());
            }
            
            return [
                'is_blocked' => (bool)$user['IsBlocked'],
                'wrong_attempts' => $user['WrongAttempts'],
                'blocked_until' => $user['BlockedUntil'],
                'remaining_time' => $remaining_time
            ];
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Check user block status error: " . $e->getMessage());
        return null;
    }
}

function formatRemainingTime($seconds) {
    if ($seconds <= 0) return "0 минут";
    
    $minutes = ceil($seconds / 60);
    $hours = floor($minutes / 60);
    $remaining_minutes = $minutes % 60;
    
    if ($hours > 0) {
        return $hours . " ч " . $remaining_minutes . " мин";
    } else {
        return $minutes . " мин";
    }
}

// Функции для вычисляемых запросов и аналитики
function getDepartmentStatistics($pdo) {
    try {
        $sql = "
            SELECT 
                d.DepartmentName,
                COUNT(DISTINCT u.UserID) as total_users,
                COUNT(DISTINCT doc.DocumentID) as total_tasks,
                COUNT(DISTINCT CASE WHEN doc.StatusID = 4 THEN doc.DocumentID END) as completed_tasks,
                COUNT(DISTINCT CASE WHEN doc.StatusID IN (1,2,3) THEN doc.DocumentID END) as active_tasks,
                AVG(CASE WHEN doc.StatusID = 4 THEN DATEDIFF(COALESCE(doc.UpdatedAt, doc.CreatedAt), doc.CreatedAt) END) as avg_completion_days,
                COUNT(DISTINCT CASE WHEN doc.Priority = 'high' THEN doc.DocumentID END) as high_priority_tasks
            FROM departments d
            LEFT JOIN users u ON d.DepartmentID = u.DepartmentID
            LEFT JOIN documents doc ON d.DepartmentID = doc.DepartmentID
            GROUP BY d.DepartmentID, d.DepartmentName
            ORDER BY total_tasks DESC
        ";
        
        $stmt = $pdo->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Если нет данных, создаем демо-данные
        if (empty($result)) {
            return generateDemoDepartmentStats();
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Department statistics error: " . $e->getMessage());
        return generateDemoDepartmentStats();
    }
}

function getUserPerformanceStats($pdo) {
    try {
        $sql = "
            SELECT 
                u.UserID,
                u.FullName,
                COALESCE(d.DepartmentName, 'Без отдела') as DepartmentName,
                COALESCE(r.RoleName, 'Не указана') as RoleName,
                COUNT(DISTINCT doc.DocumentID) as total_tasks_created,
                COUNT(DISTINCT CASE WHEN doc_assigned.AssignedTo = u.UserID THEN doc_assigned.DocumentID END) as total_tasks_assigned,
                COUNT(DISTINCT CASE WHEN doc_completed.StatusID = 4 AND doc_completed.UserID = u.UserID THEN doc_completed.DocumentID END) as completed_tasks,
                COUNT(DISTINCT CASE WHEN doc_late.Deadline < CURDATE() AND doc_late.StatusID IN (1,2,3) AND doc_late.UserID = u.UserID THEN doc_late.DocumentID END) as overdue_tasks,
                AVG(CASE WHEN doc_stats.StatusID = 4 THEN DATEDIFF(COALESCE(doc_stats.UpdatedAt, doc_stats.CreatedAt), doc_stats.CreatedAt) END) as avg_completion_time
            FROM users u
            LEFT JOIN departments d ON u.DepartmentID = d.DepartmentID
            LEFT JOIN roles r ON u.RoleID = r.RoleID
            LEFT JOIN documents doc ON u.UserID = doc.UserID
            LEFT JOIN documents doc_assigned ON u.UserID = doc_assigned.AssignedTo
            LEFT JOIN documents doc_completed ON u.UserID = doc_completed.UserID AND doc_completed.StatusID = 4
            LEFT JOIN documents doc_late ON u.UserID = doc_late.UserID AND doc_late.Deadline < CURDATE() AND doc_late.StatusID IN (1,2,3)
            LEFT JOIN documents doc_stats ON u.UserID = doc_stats.UserID AND doc_stats.StatusID = 4
            GROUP BY u.UserID, u.FullName, d.DepartmentName, r.RoleName
            ORDER BY completed_tasks DESC, total_tasks_created DESC
            LIMIT 10
        ";
        
        $stmt = $pdo->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($result)) {
            return generateDemoUserPerformance();
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("User performance stats error: " . $e->getMessage());
        return generateDemoUserPerformance();
    }
}

function getSystemEfficiencyMetrics($pdo) {
    try {
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM documents WHERE StatusID = 4) as total_completed_tasks,
                (SELECT COUNT(*) FROM documents WHERE StatusID IN (1,2,3)) as total_active_tasks,
                (SELECT COUNT(*) FROM documents WHERE Deadline < CURDATE() AND StatusID IN (1,2,3)) as total_overdue_tasks,
                (SELECT ROUND(AVG(DATEDIFF(COALESCE(UpdatedAt, CreatedAt), CreatedAt)), 2) 
                 FROM documents 
                 WHERE StatusID = 4 AND DATEDIFF(COALESCE(UpdatedAt, CreatedAt), CreatedAt) > 0) as avg_task_completion_days,
                (SELECT COUNT(*) FROM documents WHERE Priority = 'high' AND StatusID = 4) as high_priority_completed,
                (SELECT COUNT(*) FROM documents WHERE Priority = 'high') as total_high_priority,
                (SELECT COUNT(DISTINCT UserID) FROM documents WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as active_users_7days,
                (SELECT COUNT(DISTINCT UserID) FROM documents WHERE CreatedAt >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as active_users_30days
        ";
        
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return array_map(function($value) {
            return $value ?? 0;
        }, $result);
        
    } catch (PDOException $e) {
        error_log("System efficiency metrics error: " . $e->getMessage());
        return [
            'total_completed_tasks' => 24,
            'total_active_tasks' => 15,
            'total_overdue_tasks' => 3,
            'avg_task_completion_days' => 4.2,
            'high_priority_completed' => 8,
            'total_high_priority' => 12,
            'active_users_7days' => 8,
            'active_users_30days' => 15
        ];
    }
}

function getTaskCompletionTrends($pdo, $days = 30) {
    try {
        $sql = "
            SELECT 
                DATE(doc.CreatedAt) as date,
                COUNT(DISTINCT doc.DocumentID) as tasks_created,
                COUNT(DISTINCT CASE WHEN doc.StatusID = 4 THEN doc.DocumentID END) as tasks_completed,
                COUNT(DISTINCT CASE WHEN doc.StatusID IN (1,2,3) AND doc.Deadline < CURDATE() THEN doc.DocumentID END) as tasks_overdue,
                CASE 
                    WHEN COUNT(DISTINCT doc.DocumentID) > 0 
                    THEN ROUND(COUNT(DISTINCT CASE WHEN doc.StatusID = 4 THEN doc.DocumentID END) * 100.0 / COUNT(DISTINCT doc.DocumentID), 2)
                    ELSE 0 
                END as completion_rate
            FROM documents doc
            WHERE doc.CreatedAt >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(doc.CreatedAt)
            ORDER BY date DESC
            LIMIT 7
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($result)) {
            return generateDemoTrends(7);
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Task trends error: " . $e->getMessage());
        return generateDemoTrends(7);
    }
}

// Демо-данные для отображения
function generateDemoDepartmentStats() {
    return [
        [
            'DepartmentName' => 'Бухгалтерия',
            'total_users' => 6,
            'total_tasks' => 28,
            'completed_tasks' => 24,
            'active_tasks' => 4,
            'avg_completion_days' => 2.8,
            'high_priority_tasks' => 5
        ],
        [
            'DepartmentName' => 'Жилищная служба',
            'total_users' => 12,
            'total_tasks' => 45,
            'completed_tasks' => 32,
            'active_tasks' => 13,
            'avg_completion_days' => 5.2,
            'high_priority_tasks' => 8
        ],
        [
            'DepartmentName' => 'Отдел энергоснабжения', 
            'total_users' => 8,
            'total_tasks' => 22,
            'completed_tasks' => 18,
            'active_tasks' => 4,
            'avg_completion_days' => 3.5,
            'high_priority_tasks' => 3
        ]
    ];
}

function generateDemoUserPerformance() {
    return [
        [
            'FullName' => 'Иванов Александр Сергеевич',
            'DepartmentName' => 'Жилищная служба',
            'RoleName' => 'Специалист',
            'total_tasks_created' => 15,
            'completed_tasks' => 14,
            'overdue_tasks' => 1,
            'avg_completion_time' => 3.2
        ],
        [
            'FullName' => 'Петрова Мария Ивановна',
            'DepartmentName' => 'Бухгалтерия', 
            'RoleName' => 'Бухгалтер',
            'total_tasks_created' => 12,
            'completed_tasks' => 12,
            'overdue_tasks' => 0,
            'avg_completion_time' => 2.1
        ],
        [
            'FullName' => 'Сидоров Дмитрий Петрович',
            'DepartmentName' => 'Отдел энергоснабжения',
            'RoleName' => 'Инженер',
            'total_tasks_created' => 10,
            'completed_tasks' => 8,
            'overdue_tasks' => 2,
            'avg_completion_time' => 4.5
        ]
    ];
}

function generateDemoTrends($days) {
    $trends = [];
    $base_date = new DateTime();
    
    for ($i = $days; $i >= 0; $i--) {
        $date = clone $base_date;
        $date->modify("-$i days");
        
        $trends[] = [
            'date' => $date->format('Y-m-d'),
            'tasks_created' => rand(3, 8),
            'tasks_completed' => rand(2, 7),
            'tasks_overdue' => rand(0, 2),
            'completion_rate' => rand(65, 92)
        ];
    }
    
    return $trends;
}

// КОНСТАНТЫ ДЛЯ ЕДИНОГО СТИЛЯ ВСЕХ СТРАНИЦ
define('SITE_NAME', 'ЖКХ Система');
define('COMPANY_NAME', 'Управляющая компания');

// ЦВЕТА СИСТЕМЫ
define('COLOR_PRIMARY', '#3b82f6');
define('COLOR_SUCCESS', '#22c55e');
define('COLOR_WARNING', '#f59e0b');
define('COLOR_DANGER', '#ef4444');
define('COLOR_INFO', '#06b6d4');

// CSS КЛАССЫ ДЛЯ ЕДИНОГО СТИЛЯ
define('GLASS_CARD_CLASS', 'glass-card rounded-lg p-5');
define('INPUT_GLASS_CLASS', 'input-glass w-full px-4 py-3 rounded-lg focus:outline-none');
define('SELECT_GLASS_CLASS', 'select-glass w-full px-4 py-3 rounded-lg');
define('BTN_GLOW_CLASS', 'btn-glow text-white px-6 py-3 rounded-lg font-semibold transition-all duration-300');
define('BTN_PRIMARY_CLASS', 'btn-primary text-white px-4 py-2 rounded-lg font-medium');
define('BTN_SUCCESS_CLASS', 'btn-success text-white px-4 py-2 rounded-lg font-medium');
define('BTN_DANGER_CLASS', 'btn-danger text-white px-4 py-2 rounded-lg font-medium');
define('STATS_CARD_CLASS', 'stat-card glass-card p-4');
define('NAV_ITEM_CLASS', 'nav-item flex items-center px-4 py-3');
define('NOTIFICATION_CLASS', 'notification border-l-4');

// ФУНКЦИИ ДЛЯ СТИЛЕЙ
function getStatusBadgeClass($status_id) {
    $classes = [
        1 => 'status-new',
        2 => 'status-review', 
        3 => 'status-progress',
        4 => 'status-completed',
        5 => 'status-rejected',
        6 => 'status-cancelled',
        7 => 'status-revision'
    ];
    return $classes[$status_id] ?? 'status-new';
}

function getPriorityClass($priority) {
    $classes = [
        'high' => 'priority-high',
        'medium' => 'priority-medium',
        'low' => 'priority-low'
    ];
    return $classes[$priority] ?? 'priority-medium';
}

function getRoleBadgeClass($role_id) {
    if ($role_id == 1) return 'badge-danger';
    if (in_array($role_id, [20, 21, 22, 23, 18, 4])) return 'badge-warning';
    return 'badge-success';
}

// ФУНКЦИИ ДЛЯ ФОРМАТИРОВАНИЯ
function formatDateTime($datetime) {
    if (!$datetime) return '-';
    return date('d.m.Y H:i', strtotime($datetime));
}

function formatDate($date) {
    if (!$date) return '-';
    return date('d.m.Y', strtotime($date));
}

function formatTime($time) {
    if (!$time) return '-';
    return date('H:i', strtotime($time));
}

// ФУНКЦИИ БЕЗОПАСНОСТИ
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone($phone) {
    return preg_match('/^\+7\d{10}$/', $phone);
}

// ФУНКЦИИ ДЛЯ РОЛЕЙ И ПРАВ
function canManageUsers($role_id) {
    return $role_id == 1; // Только администратор
}

function canManageDepartments($role_id) {
    return $role_id == 1; // Только администратор
}

function canCreateTasks($role_id) {
    return $role_id != 1; // Все кроме администратора
}

function canViewAllTasks($role_id) {
    return $role_id == 1 || isDepartmentHead($role_id);
}

// ФУНКЦИИ ДЛЯ УВЕДОМЛЕНИЙ
function addSuccessMessage($message) {
    $_SESSION['messages']['success'][] = $message;
}

function addErrorMessage($message) {
    $_SESSION['messages']['error'][] = $message;
}

function getMessages() {
    $messages = $_SESSION['messages'] ?? [];
    unset($_SESSION['messages']);
    return $messages;
}

// ФУНКЦИИ ДЛЯ ЛОГИРОВАНИЯ
function logAction($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO action_logs (UserID, Action, Details, IPAddress, UserAgent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Log action error: " . $e->getMessage());
        return false;
    }
}

// ФУНКЦИИ ДЛЯ ЭКСПОРТА ДАННЫХ
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Заголовки
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        
        // Данные
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

function exportToJSON($data, $filename) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>