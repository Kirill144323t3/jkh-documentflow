<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$csrf_token = generateCsrfToken();
$message = '';

// Определяем доступные действия в зависимости от роли
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$is_admin = ($role_id == 1);
$is_department_head = isDepartmentHead($role_id);
$can_create_tasks = !$is_admin;

// Получаем отдел пользователя (для руководителей)
$user_department_id = null;
if ($is_department_head) {
    $user_department_id = getDepartmentIdByHeadRole($role_id, $pdo);
}

// Фильтрация задач
$filter_status = $_GET['status'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$filter_department = $_GET['department'] ?? 'all';

// Базовый SQL запрос
$sql_where = [];
$sql_params = [];

if ($is_admin) {
    $base_sql = "
        SELECT d.*, u.FullName as author_name, 
               dep.DepartmentName, ds.StatusName,
               au.FullName as assigned_user_name
        FROM documents d
        LEFT JOIN users u ON d.UserID = u.UserID
        LEFT JOIN departments dep ON d.DepartmentID = dep.DepartmentID
        LEFT JOIN documentstatus ds ON d.StatusID = ds.StatusID
        LEFT JOIN users au ON d.AssignedTo = au.UserID
    ";
} elseif ($is_department_head && $user_department_id) {
    $base_sql = "
        SELECT d.*, u.FullName as author_name, 
               dep.DepartmentName, ds.StatusName,
               au.FullName as assigned_user_name
        FROM documents d
        LEFT JOIN users u ON d.UserID = u.UserID
        LEFT JOIN departments dep ON d.DepartmentID = dep.DepartmentID
        LEFT JOIN documentstatus ds ON d.StatusID = ds.StatusID
        LEFT JOIN users au ON d.AssignedTo = au.UserID
        WHERE d.DepartmentID = ? OR d.UserID = ? OR d.AssignedTo = ?
    ";
    $sql_params = [$user_department_id, $user_id, $user_id];
} else {
    $base_sql = "
        SELECT d.*, u.FullName as author_name, 
               dep.DepartmentName, ds.StatusName,
               au.FullName as assigned_user_name
        FROM documents d
        LEFT JOIN users u ON d.UserID = u.UserID
        LEFT JOIN departments dep ON d.DepartmentID = dep.DepartmentID
        LEFT JOIN documentstatus ds ON d.StatusID = ds.StatusID
        LEFT JOIN users au ON d.AssignedTo = au.UserID
        WHERE d.UserID = ? OR d.AssignedTo = ?
    ";
    $sql_params = [$user_id, $user_id];
}

// Применяем фильтры
if ($filter_status !== 'all') {
    $sql_where[] = "d.StatusID = ?";
    $sql_params[] = $filter_status;
}

if ($filter_priority !== 'all') {
    $sql_where[] = "d.Priority = ?";
    $sql_params[] = $filter_priority;
}

if ($filter_department !== 'all' && $is_admin) {
    $sql_where[] = "d.DepartmentID = ?";
    $sql_params[] = $filter_department;
}

// Формируем полный SQL
if (!empty($sql_where)) {
    $base_sql .= (strpos($base_sql, 'WHERE') === false ? " WHERE " : " AND ") . implode(" AND ", $sql_where);
}

$base_sql .= " ORDER BY d.CreatedAt DESC";

try {
    $stmt = $pdo->prepare($base_sql);
    $stmt->execute($sql_params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = ['type' => 'error', 'text' => 'Ошибка загрузки задач: ' . $e->getMessage()];
    $tasks = [];
}

// Получаем списки для форм
try {
    $statuses = $pdo->query("SELECT StatusID, StatusName FROM documentstatus ORDER BY StatusID")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName")->fetchAll(PDO::FETCH_ASSOC);
    
    $users_sql = "
        SELECT u.UserID, u.FullName, d.DepartmentName 
        FROM users u 
        LEFT JOIN departments d ON u.DepartmentID = d.DepartmentID 
        WHERE u.UserID IN (SELECT UserID FROM registration WHERE IsBlocked = 0)
        ORDER BY u.FullName
    ";
    $users = $pdo->query($users_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = ['type' => 'error', 'text' => 'Ошибка загрузки данных: ' . $e->getMessage()];
    $statuses = $departments = $users = [];
}

// Получаем файлы для всех задач
$task_files = [];
foreach ($tasks as $task) {
    $task_files[$task['DocumentID']] = getDocumentFiles($task['DocumentID'], $pdo);
}

// Обработка создания новой задачи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'])) {
    if (isset($_POST['create_task'])) {
        if (!$can_create_tasks) {
            $message = ['type' => 'error', 'text' => 'У вас нет прав для создания задач!'];
        } else {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $deadline = $_POST['deadline'];
            $priority = $_POST['priority'];
            $department_id = $_POST['department_id'] ?: null;
            $assigned_to = $_POST['assigned_to'] ?: $user_id;
            
            if (empty($title) || empty($description)) {
                $message = ['type' => 'error', 'text' => 'Название и описание задачи обязательны!'];
            } else {
                try {
                    $initial_status = 1;
                    
                    if ($assigned_to && $assigned_to != $user_id) {
                        $initial_status = 2;
                    }
                    
                    // Проверяем существование столбца AssignedTo
                    $stmt = $pdo->query("SHOW COLUMNS FROM documents LIKE 'AssignedTo'");
                    $assigned_to_exists = $stmt->rowCount() > 0;
                    
                    if ($assigned_to_exists) {
                        $sql = "
                            INSERT INTO documents (Title, TaskDescription, Deadline, Priority, UserID, StatusID, DepartmentID, AssignedTo) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $title,
                            $description,
                            $deadline ?: null,
                            $priority,
                            $user_id,
                            $initial_status,
                            $department_id,
                            $assigned_to
                        ]);
                    } else {
                        $sql = "
                            INSERT INTO documents (Title, TaskDescription, Deadline, Priority, UserID, StatusID, DepartmentID) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $title,
                            $description,
                            $deadline ?: null,
                            $priority,
                            $user_id,
                            $initial_status,
                            $department_id
                        ]);
                    }
                    
                    $document_id = $pdo->lastInsertId();
                    
                    // Обработка загрузки файлов при создании задачи
                    if (isset($_FILES['task_files']) && !empty($_FILES['task_files']['name'][0])) {
                        $uploaded_files = $_FILES['task_files'];
                        $upload_count = 0;
                        
                        for ($i = 0; $i < count($uploaded_files['name']); $i++) {
                            if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                                $file = [
                                    'name' => $uploaded_files['name'][$i],
                                    'type' => $uploaded_files['type'][$i],
                                    'tmp_name' => $uploaded_files['tmp_name'][$i],
                                    'error' => $uploaded_files['error'][$i],
                                    'size' => $uploaded_files['size'][$i]
                                ];
                                
                                $upload_result = uploadDocumentFile($file, $document_id, $pdo);
                                if ($upload_result['success']) {
                                    $upload_count++;
                                }
                            }
                        }
                        
                        if ($upload_count > 0) {
                            $message = ['type' => 'success', 'text' => "✅ Задача создана! Успешно загружено $upload_count файл(ов)"];
                        } else {
                            $message = ['type' => 'success', 'text' => '✅ Задача успешно создана!'];
                        }
                    } else {
                        $message = ['type' => 'success', 'text' => '✅ Задача успешно создана!'];
                    }
                    
                    header("Location: tasks.php");
                    exit;
                    
                } catch (PDOException $e) {
                    $message = ['type' => 'error', 'text' => '❌ Ошибка создания задачи: ' . $e->getMessage()];
                }
            }
        }
    }
    
    // Обработка загрузки файлов к существующей задаче
    elseif (isset($_POST['upload_files'])) {
        $task_id = $_POST['task_id'];
        
        if (isset($_FILES['additional_files']) && !empty($_FILES['additional_files']['name'][0])) {
            $uploaded_files = $_FILES['additional_files'];
            $upload_count = 0;
            
            for ($i = 0; $i < count($uploaded_files['name']); $i++) {
                if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $uploaded_files['name'][$i],
                        'type' => $uploaded_files['type'][$i],
                        'tmp_name' => $uploaded_files['tmp_name'][$i],
                        'error' => $uploaded_files['error'][$i],
                        'size' => $uploaded_files['size'][$i]
                    ];
                    
                    $upload_result = uploadDocumentFile($file, $task_id, $pdo);
                    if ($upload_result['success']) {
                        $upload_count++;
                    }
                }
            }
            
            if ($upload_count > 0) {
                $message = ['type' => 'success', 'text' => "✅ Успешно загружено $upload_count файл(ов)"];
            } else {
                $message = ['type' => 'error', 'text' => '❌ Не удалось загрузить файлы'];
            }
            
            header("Location: tasks.php");
            exit;
        }
    }
    
    // Обработка изменения статуса задачи
    elseif (isset($_POST['update_status'])) {
        $task_id = $_POST['task_id'];
        $new_status = $_POST['new_status'];
        
        try {
            $sql = "SELECT UserID, AssignedTo, DepartmentID FROM documents WHERE DocumentID = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$task_id]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $can_edit = false;
            
            if ($is_admin) {
                $can_edit = true;
            } elseif ($is_department_head && $task && $task['DepartmentID'] == $user_department_id) {
                $can_edit = true;
            } elseif ($task && ($task['UserID'] == $user_id || $task['AssignedTo'] == $user_id)) {
                $can_edit = true;
            }
            
            if ($can_edit) {
                $sql = "UPDATE documents SET StatusID = ? WHERE DocumentID = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$new_status, $task_id]);
                
                $message = ['type' => 'success', 'text' => '✅ Статус задачи обновлен!'];
                header("Location: tasks.php");
                exit;
            } else {
                $message = ['type' => 'error', 'text' => '❌ У вас нет прав для изменения этой задачи'];
            }
            
        } catch (PDOException $e) {
            $message = ['type' => 'error', 'text' => '❌ Ошибка обновления статуса: ' . $e->getMessage()];
        }
    }
}

// Получаем сообщение из сессии
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ЖКХ Система - Задачи и документы</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .sidebar {
            background: rgba(15, 23, 42, 0.95);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        .nav-item {
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.2);
            border-left: 3px solid #3b82f6;
        }

        .input-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 8px;
        }
        
        .input-glass:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: #3b82f6;
            outline: none;
        }

        .select-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 8px;
            padding: 12px 16px;
            width: 100%;
        }

        .select-glass:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: #3b82f6;
            outline: none;
        }

        .btn-glow {
            background: #3b82f6;
            transition: background-color 0.2s ease;
        }
        
        .btn-glow:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: background-color 0.2s ease;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        /* Статусы задач */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-new { background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
        .status-review { background: rgba(168, 85, 247, 0.2); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3); }
        .status-progress { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-completed { background: rgba(34, 197, 94, 0.2); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .status-cancelled { background: rgba(107, 114, 128, 0.2); color: #6b7280; border: 1px solid rgba(107, 114, 128, 0.3); }
        .status-revision { background: rgba(249, 115, 22, 0.2); color: #f97316; border: 1px solid rgba(249, 115, 22, 0.3); }
        
        .priority-high { border-left: 4px solid #ef4444; background: linear-gradient(90deg, rgba(239, 68, 68, 0.1), transparent); }
        .priority-medium { border-left: 4px solid #f59e0b; background: linear-gradient(90deg, rgba(245, 158, 11, 0.1), transparent); }
        .priority-low { border-left: 4px solid #22c55e; background: linear-gradient(90deg, rgba(34, 197, 94, 0.1), transparent); }

        .notification {
            border-left: 4px solid;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .stat-card {
            border-radius: 12px;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .task-card {
            transition: all 0.3s ease;
            border-radius: 12px;
        }

        .task-card:hover {
            transform: translateY(-2px);
        }

        .file-upload-area {
            border: 2px dashed rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .file-upload-area:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }
    </style>
</head>
<body class="min-h-screen text-white">
    <div class="flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 min-h-screen p-6">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-blue-500/20 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-building text-blue-400 text-2xl"></i>
                </div>
                <h2 class="text-xl font-bold">ЖКХ Система</h2>
                <p class="text-white/70 text-sm mt-1"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                <span class="inline-block mt-2 px-3 py-1 bg-blue-500/20 text-blue-300 rounded-full text-xs font-medium">
                    <?php echo htmlspecialchars($_SESSION['role_name']); ?>
                </span>
            </div>
            
            <nav class="space-y-1">
                <a href="dashboard.php" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-chart-line mr-3 text-blue-400"></i>
                    <span class="font-medium">Дашборд</span>
                </a>
                <?php if ($_SESSION['role_id'] == 1): ?>
                <a href="admin.php?section=users" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-users mr-3 text-green-400"></i>
                    <span class="font-medium">Пользователи</span>
                </a>
                <a href="admin.php?section=departments" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-sitemap mr-3 text-purple-400"></i>
                    <span class="font-medium">Отделы</span>
                </a>
                <?php endif; ?>
                <a href="tasks.php" class="nav-item active flex items-center px-4 py-3">
                    <i class="fas fa-tasks mr-3 text-yellow-400"></i>
                    <span class="font-medium">Задачи</span>
                </a>
                <a href="emulator.php" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-cogs mr-3 text-indigo-400"></i>
                    <span class="font-medium">Эмулятор</span>
                </a>
                <a href="logout.php" class="nav-item flex items-center px-4 py-3 text-red-300 hover:text-red-200">
                    <i class="fas fa-sign-out-alt mr-3"></i>
                    <span class="font-medium">Выход</span>
                </a>
            </nav>
        </div>

        <!-- Основной контент -->
        <div class="flex-1 p-6">
            <!-- Хлебные крошки -->
            <div class="mb-6">
                <nav class="flex text-sm text-white/60 mb-2">
                    <a href="dashboard.php" class="hover:text-white transition-colors">Главная</a>
                    <span class="mx-2">/</span>
                    <span class="text-white">Задачи и документы</span>
                </nav>
                <h1 class="text-2xl font-bold text-white mb-1">Управление задачами</h1>
                <p class="text-white/60">
                    <?php if ($is_admin): ?>
                        Просмотр всех задач системы
                    <?php elseif ($is_department_head): ?>
                        Управление задачами отдела
                    <?php else: ?>
                        Мои задачи и документы
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-lg notification <?php echo $message['type'] === 'error' ? 'bg-red-500/20 text-red-100 border border-red-500/30' : 'bg-green-500/20 text-green-100 border border-green-500/30'; ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $message['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> mr-3"></i>
                        <span class="flex-1"><?php echo htmlspecialchars($message['text']); ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-white/60 hover:text-white ml-4">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Статистика задач -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Всего задач</p>
                            <h3 class="text-2xl font-bold text-white"><?php echo count($tasks); ?></h3>
                        </div>
                        <div class="text-blue-400">
                            <i class="fas fa-tasks text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">В работе</p>
                            <h3 class="text-2xl font-bold text-white">
                                <?php echo count(array_filter($tasks, fn($t) => in_array($t['StatusID'], [2, 3]))); ?>
                            </h3>
                        </div>
                        <div class="text-yellow-400">
                            <i class="fas fa-spinner text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Выполнено</p>
                            <h3 class="text-2xl font-bold text-white">
                                <?php echo count(array_filter($tasks, fn($t) => $t['StatusID'] == 4)); ?>
                            </h3>
                        </div>
                        <div class="text-green-400">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Просрочено</p>
                            <h3 class="text-2xl font-bold text-white">
                                <?php 
                                $overdue = 0;
                                foreach ($tasks as $task) {
                                    if ($task['Deadline'] && strtotime($task['Deadline']) < time() && $task['StatusID'] != 4) {
                                        $overdue++;
                                    }
                                }
                                echo $overdue;
                                ?>
                            </h3>
                        </div>
                        <div class="text-red-400">
                            <i class="fas fa-exclamation-triangle text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Форма создания задачи (скрыта для администратора) -->
                <?php if ($can_create_tasks): ?>
                <div class="lg:col-span-1">
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-plus-circle text-green-400 mr-3"></i>
                            <?php if ($is_department_head): ?>
                                Новая задача
                            <?php else: ?>
                                Новая задача
                            <?php endif; ?>
                        </h2>
                        
                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Название задачи *</label>
                                <input type="text" name="title" class="input-glass w-full px-4 py-3" required placeholder="Введите название задачи">
                            </div>
                            
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Описание *</label>
                                <textarea name="description" rows="4" class="input-glass w-full px-4 py-3" required placeholder="Подробное описание задачи"></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Срок выполнения</label>
                                <input type="date" name="deadline" class="input-glass w-full px-4 py-3">
                            </div>
                            
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Приоритет</label>
                                <select name="priority" class="select-glass">
                                    <option value="low">🟢 Низкий</option>
                                    <option value="medium" selected>🟡 Средний</option>
                                    <option value="high">🔴 Высокий</option>
                                </select>
                            </div>
                            
                            <!-- Руководители могут выбирать отдел и назначать на других -->
                            <?php if ($is_department_head): ?>
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Отдел</label>
                                <select name="department_id" class="select-glass">
                                    <option value="">-- Выберите отдел --</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <?php if ($dept['DepartmentID'] == $user_department_id): ?>
                                            <option value="<?php echo $dept['DepartmentID']; ?>" selected>
                                                <?php echo htmlspecialchars($dept['DepartmentName']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Назначить на</label>
                                <select name="assigned_to" class="select-glass">
                                    <option value="<?php echo $user_id; ?>">-- Самому себе --</option>
                                    <?php foreach ($users as $user): ?>
                                        <?php if ($user['UserID'] != $user_id && $user['DepartmentID'] == $user_department_id): ?>
                                        <option value="<?php echo $user['UserID']; ?>">
                                            <?php echo htmlspecialchars($user['FullName']); ?>
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <!-- Обычные пользователи создают задачи только для себя -->
                            <input type="hidden" name="department_id" value="">
                            <input type="hidden" name="assigned_to" value="<?php echo $user_id; ?>">
                            <?php endif; ?>
                            
                            <!-- Поле для загрузки файлов -->
                            <div>
                                <label class="block text-white/80 text-sm font-medium mb-2">Прикрепить файлы</label>
                                <div class="file-upload-area" onclick="document.getElementById('task_files').click()">
                                    <i class="fas fa-cloud-upload-alt text-2xl text-white/60 mb-2"></i>
                                    <p class="text-white/70 text-sm">Перетащите файлы сюда или кликните для выбора</p>
                                    <p class="text-white/50 text-xs mt-1">Максимум 10MB на файл</p>
                                </div>
                                <input type="file" id="task_files" name="task_files[]" multiple 
                                       class="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar">
                                <div id="file-list" class="mt-2 space-y-1"></div>
                            </div>
                            
                            <button type="submit" name="create_task" class="btn-glow w-full text-white py-3 rounded-lg font-semibold">
                                <i class="fas fa-plus-circle mr-2"></i>Создать задачу
                            </button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <!-- Для администратора - информационный блок вместо формы -->
                <div class="lg:col-span-1">
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-info-circle text-blue-400 mr-3"></i>
                            Информация
                        </h2>
                        <div class="space-y-4">
                            <div class="bg-blue-500/20 border border-blue-500/30 rounded-lg p-4">
                                <div class="flex items-center mb-2">
                                    <i class="fas fa-shield-alt text-blue-400 mr-2"></i>
                                    <span class="text-white font-semibold">Роль администратора</span>
                                </div>
                                <p class="text-white/70 text-sm">Вы можете просматривать все задачи системы и изменять их статусы, но не создавать новые задачи.</p>
                            </div>
                            <div class="bg-white/10 rounded-lg p-4">
                                <h3 class="text-white font-semibold mb-3 text-sm">Статистика</h3>
                                <div class="space-y-2 text-white/70 text-xs">
                                    <div class="flex justify-between">
                                        <span>Всего задач:</span>
                                        <span class="font-semibold"><?php echo count($tasks); ?></span>
                                    </div>
                                    <?php
                                    $status_counts = [];
                                    foreach ($tasks as $task) {
                                        $status_id = $task['StatusID'];
                                        $status_counts[$status_id] = ($status_counts[$status_id] ?? 0) + 1;
                                    }
                                    ?>
                                    <?php foreach ($statuses as $status): ?>
                                        <?php if (isset($status_counts[$status['StatusID']])): ?>
                                        <div class="flex justify-between">
                                            <span><?php echo htmlspecialchars($status['StatusName']); ?>:</span>
                                            <span class="font-semibold"><?php echo $status_counts[$status['StatusID']]; ?></span>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Список задач -->
                <div class="<?php echo $can_create_tasks ? 'lg:col-span-3' : 'lg:col-span-4'; ?>">
                    <div class="glass-card p-5">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-6">
                            <h2 class="text-lg font-bold text-white mb-4 lg:mb-0">
                                <?php if ($is_admin): ?>
                                    Все задачи системы
                                <?php elseif ($is_department_head): ?>
                                    Задачи отдела
                                <?php else: ?>
                                    Мои задачи
                                <?php endif; ?>
                            </h2>
                            
                            <!-- Фильтры -->
                            <div class="flex flex-wrap gap-3">
                                <!-- Фильтр по статусу -->
                                <div class="relative">
                                    <select id="statusFilter" class="select-glass text-sm pr-8 appearance-none cursor-pointer">
                                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>Все статусы</option>
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?php echo $status['StatusID']; ?>" <?php echo $filter_status == $status['StatusID'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($status['StatusName']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="absolute right-2 top-1/2 transform -translate-y-1/2 pointer-events-none">
                                        <i class="fas fa-chevron-down text-white/60"></i>
                                    </div>
                                </div>
                                
                                <!-- Фильтр по приоритету -->
                                <div class="relative">
                                    <select id="priorityFilter" class="select-glass text-sm pr-8 appearance-none cursor-pointer">
                                        <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>Все приоритеты</option>
                                        <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>🔴 Высокий</option>
                                        <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>🟡 Средний</option>
                                        <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>🟢 Низкий</option>
                                    </select>
                                    <div class="absolute right-2 top-1/2 transform -translate-y-1/2 pointer-events-none">
                                        <i class="fas fa-chevron-down text-white/60"></i>
                                    </div>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                <!-- Фильтр по отделу -->
                                <div class="relative">
                                    <select id="departmentFilter" class="select-glass text-sm pr-8 appearance-none cursor-pointer">
                                        <option value="all" <?php echo $filter_department === 'all' ? 'selected' : ''; ?>>Все отделы</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['DepartmentID']; ?>" <?php echo $filter_department == $dept['DepartmentID'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['DepartmentName']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="absolute right-2 top-1/2 transform -translate-y-1/2 pointer-events-none">
                                        <i class="fas fa-chevron-down text-white/60"></i>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <button onclick="applyFilters()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm flex items-center transition-colors">
                                    <i class="fas fa-filter mr-2"></i>Применить
                                </button>
                                
                                <button onclick="clearFilters()" class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg text-sm flex items-center transition-colors">
                                    <i class="fas fa-times mr-2"></i>Сбросить
                                </button>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if (!empty($tasks)): ?>
                                <?php foreach ($tasks as $task): 
                                    $status_class = 'status-new';
                                    switch ($task['StatusID']) {
                                        case 2: $status_class = 'status-review'; break;
                                        case 3: $status_class = 'status-progress'; break;
                                        case 4: $status_class = 'status-completed'; break;
                                        case 5: $status_class = 'status-rejected'; break;
                                        case 6: $status_class = 'status-cancelled'; break;
                                        case 7: $status_class = 'status-revision'; break;
                                    }
                                    
                                    $priority_class = 'priority-' . ($task['Priority'] ?? 'medium');
                                    $is_overdue = $task['Deadline'] && strtotime($task['Deadline']) < time() && $task['StatusID'] != 4;
                                ?>
                                <div class="task-card glass-card p-5 <?php echo $priority_class; ?>">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex-1">
                                            <div class="flex items-start justify-between mb-3">
                                                <div class="flex items-center space-x-3">
                                                    <h3 class="text-white font-semibold text-lg"><?php echo htmlspecialchars($task['Title']); ?></h3>
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo htmlspecialchars($task['StatusName']); ?>
                                                    </span>
                                                    <?php if ($is_overdue): ?>
                                                    <span class="status-badge status-rejected">
                                                        <i class="fas fa-clock mr-1"></i>Просрочено
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Управление статусом -->
                                                <?php if ($task['UserID'] == $user_id || $task['AssignedTo'] == $user_id || $is_admin || ($is_department_head && $task['DepartmentID'] == $user_department_id)): ?>
                                                <div class="flex items-center space-x-2">
                                                    <form method="POST" class="flex items-center space-x-2">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="task_id" value="<?php echo $task['DocumentID']; ?>">
                                                        <select name="new_status" class="px-3 py-1 bg-white/10 border border-white/20 rounded text-white text-sm focus:outline-none focus:border-blue-500">
                                                            <?php foreach ($statuses as $status): ?>
                                                                <option value="<?php echo $status['StatusID']; ?>" <?php echo $status['StatusID'] == $task['StatusID'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($status['StatusName']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="update_status" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition-colors flex items-center">
                                                            <i class="fas fa-sync-alt mr-1"></i>Обновить
                                                        </button>
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <p class="text-white/70 mb-4 leading-relaxed"><?php echo htmlspecialchars($task['TaskDescription']); ?></p>
                                            
                                            <div class="flex flex-wrap items-center gap-4 text-sm text-white/60">
                                                <span class="flex items-center">
                                                    <i class="fas fa-user mr-2 text-blue-400"></i>
                                                    <?php echo htmlspecialchars($task['author_name']); ?>
                                                </span>
                                                
                                                <?php if ($task['assigned_user_name'] && $task['assigned_user_name'] != $task['author_name']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-user-check mr-2 text-green-400"></i>
                                                        <?php echo htmlspecialchars($task['assigned_user_name']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($task['DepartmentName']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-building mr-2 text-purple-400"></i>
                                                        <?php echo htmlspecialchars($task['DepartmentName']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($task['Deadline']): ?>
                                                    <span class="flex items-center <?php echo $is_overdue ? 'text-red-400' : 'text-yellow-400'; ?>">
                                                        <i class="fas fa-calendar mr-2"></i>
                                                        <?php echo date('d.m.Y', strtotime($task['Deadline'])); ?>
                                                        <?php if ($is_overdue): ?>
                                                            <i class="fas fa-exclamation-triangle ml-1"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <span class="flex items-center">
                                                    <i class="fas fa-clock mr-2 text-gray-400"></i>
                                                    <?php echo date('d.m.Y H:i', strtotime($task['CreatedAt'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Блок с файлами -->
                                    <?php if (!empty($task_files[$task['DocumentID']])): ?>
                                    <div class="mt-4 pt-4 border-t border-white/10">
                                        <h4 class="text-white font-semibold mb-3 flex items-center">
                                            <i class="fas fa-paperclip mr-2 text-blue-400"></i>
                                            Прикрепленные файлы
                                        </h4>
                                        <div class="space-y-2">
                                            <?php foreach ($task_files[$task['DocumentID']] as $file): ?>
                                            <div class="flex items-center justify-between p-3 rounded-lg bg-white/5">
                                                <div class="flex items-center">
                                                    <i class="fas fa-file text-blue-400 mr-3"></i>
                                                    <div>
                                                        <p class="text-white font-medium text-sm"><?php echo htmlspecialchars($file['FileName']); ?></p>
                                                        <p class="text-white/50 text-xs"><?php echo formatFileSize($file['FileSize']); ?> • <?php echo date('d.m.Y H:i', strtotime($file['UploadedAt'])); ?></p>
                                                    </div>
                                                </div>
                                                <a href="download_file.php?file_id=<?php echo $file['FileID']; ?>" 
                                                   class="bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 px-3 py-2 rounded text-sm transition-colors ml-2 flex items-center"
                                                   title="Скачать файл">
                                                    <i class="fas fa-download mr-1"></i>Скачать
                                                </a>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Форма для добавления файлов -->
                                    <?php if ($task['UserID'] == $user_id || $task['AssignedTo'] == $user_id || $is_admin || ($is_department_head && $task['DepartmentID'] == $user_department_id)): ?>
                                    <div class="mt-4 pt-4 border-t border-white/10">
                                        <form method="POST" enctype="multipart/form-data" class="flex items-center space-x-2">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="task_id" value="<?php echo $task['DocumentID']; ?>">
                                            <input type="file" name="additional_files[]" multiple 
                                                   class="flex-1 input-glass text-sm" 
                                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar">
                                            <button type="submit" name="upload_files" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded text-sm transition-colors whitespace-nowrap flex items-center">
                                                <i class="fas fa-upload mr-1"></i>Загрузить
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-12 text-white/60">
                                    <i class="fas fa-tasks text-4xl mb-4 opacity-50"></i>
                                    <p class="text-lg">Задачи не найдены</p>
                                    <p class="text-sm mt-2">
                                        <?php if ($is_admin): ?>
                                            В системе пока нет созданных задач
                                        <?php else: ?>
                                            <?php echo $can_create_tasks ? 'Создайте первую задачу используя форму слева' : 'Ожидайте назначения задач от руководителя'; ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Обработка drag & drop для загрузки файлов
        const fileUploadArea = document.querySelector('.file-upload-area');
        const fileInput = document.getElementById('task_files');
        const fileList = document.getElementById('file-list');

        if (fileUploadArea) {
            fileUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadArea.classList.add('dragover');
            });

            fileUploadArea.addEventListener('dragleave', () => {
                fileUploadArea.classList.remove('dragover');
            });

            fileUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadArea.classList.remove('dragover');
                fileInput.files = e.dataTransfer.files;
                updateFileList();
            });

            fileInput.addEventListener('change', updateFileList);
        }

        function updateFileList() {
            if (!fileList) return;
            
            fileList.innerHTML = '';
            if (fileInput.files.length > 0) {
                for (let file of fileInput.files) {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'flex items-center justify-between p-2 bg-white/5 rounded text-sm';
                    fileItem.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-file text-white/60 mr-2"></i>
                            <span class="text-white/80">${file.name}</span>
                        </div>
                        <span class="text-white/50 text-xs">${formatFileSize(file.size)}</span>
                    `;
                    fileList.appendChild(fileItem);
                }
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Функции фильтрации
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const priority = document.getElementById('priorityFilter').value;
            const department = document.getElementById('departmentFilter') ? document.getElementById('departmentFilter').value : 'all';
            
            const params = new URLSearchParams();
            if (status !== 'all') params.set('status', status);
            if (priority !== 'all') params.set('priority', priority);
            if (department !== 'all') params.set('department', department);
            
            window.location.href = 'tasks.php?' + params.toString();
        }

        function clearFilters() {
            window.location.href = 'tasks.php';
        }

        // Auto-hide notifications
        document.addEventListener('DOMContentLoaded', () => {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach(notification => {
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.style.opacity = '0';
                        setTimeout(() => {
                            if (notification.parentElement) {
                                notification.remove();
                            }
                        }, 500);
                    }
                }, 5000);
            });
        });
    </script>
</body>
</html>