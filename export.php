<?php
require_once 'config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Получаем параметры
$export_type = $_GET['type'] ?? 'tasks';
$format = $_GET['format'] ?? 'html';

// Проверяем доступ
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$is_admin = ($role_id == 1);

// Обработка запроса
try {
    $data = [];
    $title = '';
    
    switch ($export_type) {
        case 'tasks':
            $data = getTasksData($pdo, $user_id, $role_id, $is_admin);
            $title = 'задачам';
            break;
            
        case 'users':
            if (!$is_admin) {
                die('Доступ запрещен');
            }
            $data = getUsersData($pdo);
            $title = 'пользователям';
            break;
            
        case 'departments':
            if (!$is_admin) {
                die('Доступ запрещен');
            }
            $data = getDepartmentsData($pdo);
            $title = 'отделам';
            break;
            
        case 'statistics':
            $data = getStatisticsData($pdo, $user_id, $is_admin);
            $title = 'статистике';
            break;
            
        default:
            die('Неверный тип экспорта');
    }
    
    // Вывод в нужном формате
    switch ($format) {
        case 'json':
            exportAsJson($data, $export_type);
            break;
            
        case 'pdf':
            exportAsHtml($data, $export_type, $title);
            break;
            
        default:
            exportAsHtml($data, $export_type, $title, false);
    }
    
} catch (Exception $e) {
    die('Ошибка: ' . $e->getMessage());
}

// Функция получения данных задач
function getTasksData($pdo, $user_id, $role_id, $is_admin) {
    if ($is_admin) {
        // Админ видит все задачи
        $sql = "SELECT d.*, u.FullName as author_name, 
                       dep.DepartmentName, ds.StatusName,
                       au.FullName as assigned_user_name
                FROM documents d
                LEFT JOIN users u ON d.UserID = u.UserID
                LEFT JOIN departments dep ON d.DepartmentID = dep.DepartmentID
                LEFT JOIN documentstatus ds ON d.StatusID = ds.StatusID
                LEFT JOIN users au ON d.AssignedTo = au.UserID
                ORDER BY d.CreatedAt DESC";
        $stmt = $pdo->query($sql);
    } else {
        // Обычный пользователь видит свои задачи
        $sql = "SELECT d.*, u.FullName as author_name, 
                       dep.DepartmentName, ds.StatusName,
                       au.FullName as assigned_user_name
                FROM documents d
                LEFT JOIN users u ON d.UserID = u.UserID
                LEFT JOIN departments dep ON d.DepartmentID = dep.DepartmentID
                LEFT JOIN documentstatus ds ON d.StatusID = ds.StatusID
                LEFT JOIN users au ON d.AssignedTo = au.UserID
                WHERE d.UserID = ? OR d.AssignedTo = ?
                ORDER BY d.CreatedAt DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id]);
    }
    
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем даты
    foreach ($tasks as &$task) {
        $task['CreatedAt_formatted'] = date('d.m.Y H:i', strtotime($task['CreatedAt']));
        $task['Deadline_formatted'] = $task['Deadline'] ? date('d.m.Y', strtotime($task['Deadline'])) : 'Не указан';
    }
    
    return $tasks;
}

// Функция получения данных пользователей
function getUsersData($pdo) {
    $sql = "SELECT u.UserID, u.FullName, u.Email, u.Position, 
                   r.RoleName, d.DepartmentName, reg.Login,
                   CASE WHEN reg.IsBlocked = 1 THEN 'Заблокирован' ELSE 'Активен' END as Status
            FROM users u
            LEFT JOIN roles r ON u.RoleID = r.RoleID
            LEFT JOIN departments d ON u.DepartmentID = d.DepartmentID
            LEFT JOIN registration reg ON u.UserID = reg.UserID
            ORDER BY u.FullName";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Функция получения данных отделов
function getDepartmentsData($pdo) {
    $sql = "SELECT d.DepartmentID, d.DepartmentName, 
                   COUNT(u.UserID) as user_count 
            FROM departments d 
            LEFT JOIN users u ON d.DepartmentID = u.DepartmentID 
            GROUP BY d.DepartmentID, d.DepartmentName
            ORDER BY d.DepartmentName";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Функция получения статистики
function getStatisticsData($pdo, $user_id, $is_admin) {
    $stats = [];
    
    // Статистика по задачам
    if ($is_admin) {
        $sql = "SELECT 
                    COUNT(*) as total_tasks,
                    COUNT(CASE WHEN StatusID = 1 THEN 1 END) as pending_tasks,
                    COUNT(CASE WHEN StatusID = 2 THEN 1 END) as in_progress_tasks,
                    COUNT(CASE WHEN StatusID = 3 THEN 1 END) as completed_tasks
                FROM documents";
        $stats['tasks'] = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        
        // Статистика по пользователям
        $sql = "SELECT 
                    COUNT(*) as total_users,
                    COUNT(CASE WHEN reg.IsBlocked = 1 THEN 1 END) as blocked_users,
                    COUNT(CASE WHEN u.RoleID = 1 THEN 1 END) as admin_users
                FROM users u
                LEFT JOIN registration reg ON u.UserID = reg.UserID";
        $stats['users'] = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT 
                    COUNT(*) as total_tasks,
                    COUNT(CASE WHEN StatusID = 1 THEN 1 END) as pending_tasks,
                    COUNT(CASE WHEN StatusID = 2 THEN 1 END) as in_progress_tasks,
                    COUNT(CASE WHEN StatusID = 3 THEN 1 END) as completed_tasks
                FROM documents
                WHERE UserID = ? OR AssignedTo = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id]);
        $stats['tasks'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $stats;
}

// Экспорт в JSON
function exportAsJson($data, $type) {
    $filename = $type . '_' . date('Y-m-d_H-i') . '.json';
    
    $result = [
        'success' => true,
        'type' => $type,
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $_SESSION['full_name'],
        'data' => $data
    ];
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Экспорт в HTML/PDF
function exportAsHtml($data, $type, $title, $download = true) {
    if ($download) {
        $filename = $type . '_report_' . date('Y-m-d') . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Отчет по <?= $title ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; line-height: 1.4; }
        .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #333; }
        .header h1 { color: #2c3e50; margin-bottom: 10px; }
        .header-info { color: #666; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 12px; }
        th { background: #34495e; color: white; padding: 10px; text-align: left; border: 1px solid #ddd; }
        td { padding: 8px 10px; border: 1px solid #ddd; }
        tr:nth-child(even) { background: #f8f9fa; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db; }
        .stat-number { font-size: 24px; font-weight: bold; color: #2c3e50; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; padding-top: 10px; border-top: 1px solid #ddd; }
        .no-data { text-align: center; padding: 40px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Отчет по <?= $title ?></h1>
        <div class="header-info">
            Сгенерировано: <?= date('d.m.Y H:i') ?> | 
            Пользователь: <?= htmlspecialchars($_SESSION['full_name']) ?> | 
            Роль: <?= htmlspecialchars($_SESSION['role_name']) ?>
        </div>
    </div>

    <?php if ($type === 'tasks'): ?>
        <?php if (!empty($data)): ?>
            <?php
            $total = count($data);
            $completed = 0;
            $in_progress = 0;
            $pending = 0;
            
            foreach ($data as $task) {
                if ($task['StatusID'] == 3) $completed++;
                elseif ($task['StatusID'] == 2) $in_progress++;
                else $pending++;
            }
            ?>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= $total ?></div>
                    <div class="stat-label">Всего задач</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $completed ?></div>
                    <div class="stat-label">Завершено</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $in_progress ?></div>
                    <div class="stat-label">В работе</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $pending ?></div>
                    <div class="stat-label">Ожидает</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Название</th>
                        <th>Статус</th>
                        <th>Автор</th>
                        <th>Исполнитель</th>
                        <th>Отдел</th>
                        <th>Приоритет</th>
                        <th>Срок</th>
                        <th>Создана</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $index => $task): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($task['Title']) ?></td>
                        <td><?= htmlspecialchars($task['StatusName']) ?></td>
                        <td><?= htmlspecialchars($task['author_name']) ?></td>
                        <td><?= htmlspecialchars($task['assigned_user_name'] ?? 'Не назначен') ?></td>
                        <td><?= htmlspecialchars($task['DepartmentName'] ?? 'Без отдела') ?></td>
                        <td><?= htmlspecialchars($task['Priority'] ?? 'Средний') ?></td>
                        <td><?= $task['Deadline_formatted'] ?></td>
                        <td><?= date('d.m.Y', strtotime($task['CreatedAt'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Нет данных для отображения</div>
        <?php endif; ?>

    <?php elseif ($type === 'users'): ?>
        <?php if (!empty($data)): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ФИО</th>
                        <th>Email</th>
                        <th>Должность</th>
                        <th>Роль</th>
                        <th>Отдел</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $index => $user): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($user['FullName']) ?></td>
                        <td><?= htmlspecialchars($user['Email']) ?></td>
                        <td><?= htmlspecialchars($user['Position']) ?></td>
                        <td><?= htmlspecialchars($user['RoleName']) ?></td>
                        <td><?= htmlspecialchars($user['DepartmentName'] ?? 'Не указан') ?></td>
                        <td><?= htmlspecialchars($user['Status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Нет данных для отображения</div>
        <?php endif; ?>

    <?php elseif ($type === 'departments'): ?>
        <?php if (!empty($data)): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Название отдела</th>
                        <th>Количество сотрудников</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $index => $dept): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($dept['DepartmentName']) ?></td>
                        <td><?= $dept['user_count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Нет данных для отображения</div>
        <?php endif; ?>

    <?php elseif ($type === 'statistics'): ?>
        <div class="stats">
            <?php if (isset($data['tasks'])): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $data['tasks']['total_tasks'] ?></div>
                <div class="stat-label">Всего задач</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $data['tasks']['pending_tasks'] ?></div>
                <div class="stat-label">Ожидает выполнения</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $data['tasks']['in_progress_tasks'] ?></div>
                <div class="stat-label">В работе</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $data['tasks']['completed_tasks'] ?></div>
                <div class="stat-label">Завершено</div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($data['users'])): ?>
            <div class="stat-card">
                <div class="stat-number"><?= $data['users']['total_users'] ?></div>
                <div class="stat-label">Всего пользователей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $data['users']['admin_users'] ?></div>
                <div class="stat-label">Администраторов</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $data['users']['blocked_users'] ?></div>
                <div class="stat-label">Заблокировано</div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        ЖКХ Система - Отчет сгенерирован автоматически<br>
        <?php if (is_array($data)): ?>
        Всего записей: <?= count($data) ?>
        <?php endif; ?>
    </div>

    <?php if ($download): ?>
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
    <?php endif; ?>
</body>
</html>
<?php
}
?>