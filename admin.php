<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header("Location: index.php");
    exit;
}

$csrf_token = generateCsrfToken();
$message = '';

// Определяем активный раздел
$section = $_GET['section'] ?? 'users';

// ОБЩИЕ ДАННЫЕ ДЛЯ ВСЕХ РАЗДЕЛОВ
try {
    // Для раздела пользователей
    $users = $pdo->query("
        SELECT u.UserID, u.FullName, u.Email, u.Position, r.RoleName, d.DepartmentName, 
               reg.Login, reg.IsBlocked, reg.BlockedUntil, reg.WrongAttempts, reg.PasswordExpires
        FROM users u
        LEFT JOIN roles r ON u.RoleID = r.RoleID
        LEFT JOIN departments d ON u.DepartmentID = d.DepartmentID
        LEFT JOIN registration reg ON u.UserID = reg.UserID
        ORDER BY u.FullName
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Для раздела отделов
    $departments = $pdo->query("
        SELECT d.DepartmentID, d.DepartmentName, COUNT(u.UserID) as user_count 
        FROM departments d 
        LEFT JOIN users u ON d.DepartmentID = u.DepartmentID 
        GROUP BY d.DepartmentID 
        ORDER BY d.DepartmentName
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Для форм
    $roles = $pdo->query("SELECT RoleID, RoleName FROM roles ORDER BY RoleName")->fetchAll(PDO::FETCH_ASSOC);
    $all_departments = $pdo->query("SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = ['type' => 'error', 'text' => 'Ошибка загрузки данных: ' . $e->getMessage()];
}

// ОБРАБОТКА ФОРМ (ВСЕХ РАЗДЕЛОВ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'])) {
    
    // РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ - Добавление пользователя
    if (isset($_POST['add_user'])) {
        $full_name = trim($_POST['full_name']);
        $login = trim($_POST['login']);
        $email = trim($_POST['email']);
        $position = trim($_POST['position']);
        $password = trim($_POST['password']);
        $confirm_password = trim($_POST['confirm_password']);
        $role_id = $_POST['role_id'];
        $department_id = $_POST['department_id'] ?: null;
        
        if (empty($full_name) || empty($login) || empty($role_id) || empty($password)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Обязательные поля: ФИО, Логин, Пароль и Роль!'];
        } elseif ($password !== $confirm_password) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Пароли не совпадают!'];
        } elseif (!isPasswordStrong($password)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Пароль должен быть не менее 6 символов и содержать буквы и цифры!'];
        } else {
            try {
                $pdo->beginTransaction();
                
                // Проверяем уникальность логина
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM registration WHERE Login = :login");
                $stmt->execute([':login' => $login]);
                $login_exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($login_exists > 0) {
                    $_SESSION['message'] = ['type' => 'error', 'text' => 'Пользователь с таким логином уже существует!'];
                    $pdo->rollBack();
                } else {
                    // Добавляем пользователя
                    $position = $position ?: 'Сотрудник';
                    $stmt = $pdo->prepare("INSERT INTO users (FullName, Email, Position, RoleID, DepartmentID) VALUES (:full_name, :email, :position, :role_id, :department_id)");
                    $stmt->execute([
                        ':full_name' => $full_name,
                        ':email' => $email,
                        ':position' => $position,
                        ':role_id' => $role_id,
                        ':department_id' => $department_id
                    ]);
                    
                    $user_id = $pdo->lastInsertId();
                    
                    // Создаем запись в регистрации
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("INSERT INTO registration (UserID, Login, Password, PasswordCreated, PasswordExpires, WrongAttempts, IsBlocked) VALUES (:user_id, :login, :password, NOW(), DATE_ADD(NOW(), INTERVAL 3 MONTH), 0, 0)");
                    $stmt->execute([
                        ':user_id' => $user_id,
                        ':login' => $login,
                        ':password' => $password_hash
                    ]);
                    
                    $pdo->commit();
                    
                    $_SESSION['message'] = ['type' => 'success', 'text' => '✅ Пользователь "' . htmlspecialchars($full_name) . '" успешно добавлен в систему!'];
                    
                    header("Location: admin.php?section=users");
                    exit;
                }
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Ошибка добавления пользователя: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
    
    // РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ - Блокировка/разблокировка
    elseif (isset($_POST['toggle_block'])) {
        $user_id = $_POST['user_id'];
        $action = $_POST['action'];
        
        // Получаем имя пользователя
        $stmt = $pdo->prepare("SELECT FullName FROM users WHERE UserID = :userID");
        $stmt->execute([':userID' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_name = $user ? $user['FullName'] : 'Пользователь';
        
        // Защита от блокировки самого себя
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Вы не можете заблокировать самого себя!'];
        } else {
            try {
                if ($action === 'block') {
                    // Блокируем на 30 минут
                    $block_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $stmt = $pdo->prepare("UPDATE registration SET IsBlocked = 1, BlockedUntil = :blockUntil WHERE UserID = :user_id");
                    $stmt->execute([':user_id' => $user_id, ':blockUntil' => $block_until]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => '🔒 Пользователь "' . htmlspecialchars($user_name) . '" заблокирован на 30 минут!'];
                } else {
                    // Разблокируем
                    $stmt = $pdo->prepare("UPDATE registration SET IsBlocked = 0, BlockedUntil = NULL, WrongAttempts = 0 WHERE UserID = :user_id");
                    $stmt->execute([':user_id' => $user_id]);
                    $_SESSION['message'] = ['type' => 'success', 'text' => '🔓 Пользователь "' . htmlspecialchars($user_name) . '" разблокирован!'];
                }
                
                header("Location: admin.php?section=users");
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Ошибка изменения статуса: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
    
    // РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ - Смена пароля
    elseif (isset($_POST['change_user_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        if (empty($user_id) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Все поля обязательны для заполнения!'];
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Пароли не совпадают!'];
        } elseif (!isPasswordStrong($new_password)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Пароль должен быть не менее 6 символов и содержать буквы и цифры!'];
        } else {
            try {
                $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE registration SET Password = :password, PasswordCreated = NOW(), PasswordExpires = DATE_ADD(NOW(), INTERVAL 3 MONTH), WrongAttempts = 0, BlockedUntil = NULL, IsBlocked = 0 WHERE UserID = :userID");
                $stmt->execute([
                    ':password' => $new_password_hash,
                    ':userID' => $user_id
                ]);
                
                // Получаем имя пользователя
                $stmt = $pdo->prepare("SELECT FullName FROM users WHERE UserID = :userID");
                $stmt->execute([':userID' => $user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $_SESSION['message'] = ['type' => 'success', 'text' => '✅ Пароль для пользователя "' . htmlspecialchars($user['FullName']) . '" успешно изменен!'];
                
                header("Location: admin.php?section=users");
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Ошибка изменения пароля: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
    
    // РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ - Удаление пользователя
    elseif (isset($_POST['delete_user'])) {
        $user_id = $_POST['user_id'];
        
        // Защита от удаления самого себя
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Вы не можете удалить самого себя!'];
        } else {
            try {
                $pdo->beginTransaction();
                
                // Получаем информацию о пользователе
                $stmt = $pdo->prepare("SELECT FullName FROM users WHERE UserID = :userID");
                $stmt->execute([':userID' => $user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Удаляем из registration
                    $stmt = $pdo->prepare("DELETE FROM registration WHERE UserID = :userID");
                    $stmt->execute([':userID' => $user_id]);
                    
                    // Удаляем из users
                    $stmt = $pdo->prepare("DELETE FROM users WHERE UserID = :userID");
                    $stmt->execute([':userID' => $user_id]);
                    
                    $pdo->commit();
                    
                    $_SESSION['message'] = ['type' => 'success', 'text' => '✅ Пользователь "' . htmlspecialchars($user['FullName']) . '" успешно удален из системы!'];
                    
                    header("Location: admin.php?section=users");
                    exit;
                } else {
                    $pdo->rollBack();
                    $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Пользователь не найден!'];
                }
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Ошибка удаления пользователя: ' . htmlspecialchars($e->getMessage())];
            }
        }
    }
    
    // РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ - Сброс счетчика попыток
    elseif (isset($_POST['reset_attempts'])) {
        $user_id = $_POST['user_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE registration SET WrongAttempts = 0 WHERE UserID = :userID");
            $stmt->execute([':userID' => $user_id]);
            
            // Получаем имя пользователя
            $stmt = $pdo->prepare("SELECT FullName FROM users WHERE UserID = :userID");
            $stmt->execute([':userID' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $_SESSION['message'] = ['type' => 'success', 'text' => '✅ Счетчик неверных попыток для пользователя "' . htmlspecialchars($user['FullName']) . '" сброшен!'];
            
            header("Location: admin.php?section=users");
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '❌ Ошибка сброса счетчика: ' . htmlspecialchars($e->getMessage())];
        }
    }
    
    // РАЗДЕЛ: ОТДЕЛЫ - Добавление отдела
    elseif (isset($_POST['add_department'])) {
        $name = trim($_POST['name']);
        if (empty($name)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Название отдела обязательно!'];
        } else {
            // Проверяем существование отдела
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM departments WHERE DepartmentName = :name");
            $stmt->execute([':name' => $name]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($exists > 0) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Отдел с таким названием уже существует!'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO departments (DepartmentName) VALUES (:name)");
                $stmt->execute([':name' => $name]);
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Отдел успешно добавлен!'];
                header("Location: admin.php?section=departments");
                exit;
            }
        }
    }
    
    // РАЗДЕЛ: ОТДЕЛЫ - Удаление отдела
    elseif (isset($_POST['delete_department'])) {
        $id = $_POST['department_id'];
        
        // Проверяем есть ли пользователи в отделе
        $stmt = $pdo->prepare("SELECT COUNT(*) as user_count FROM users WHERE DepartmentID = :id");
        $stmt->execute([':id' => $id]);
        $user_count = $stmt->fetch(PDO::FETCH_ASSOC)['user_count'];
        
        if ($user_count > 0) {
            $_SESSION['message'] = ['type' => 'error', 'text' => "Нельзя удалить отдел: в нем есть $user_count пользователь(ей). Сначала переместите их в другой отдел."];
        } else {
            // Нет пользователей - удаляем
            try {
                $stmt = $pdo->prepare("DELETE FROM departments WHERE DepartmentID = :id");
                $stmt->execute([':id' => $id]);
                $_SESSION['message'] = ['type' => 'success', 'text' => 'Отдел успешно удален!'];
            } catch (PDOException $e) {
                $_SESSION['message'] = ['type' => 'error', 'text' => 'Ошибка удаления: ' . htmlspecialchars($e->getMessage())];
            }
        }
        header("Location: admin.php?section=departments");
        exit;
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
    <title>ЖКХ Система - Административная панель</title>
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

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .badge-primary {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }

        .table-container {
            overflow: auto;
            border-radius: 12px;
        }

        .enhanced-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .enhanced-table th {
            background: rgba(59, 130, 246, 0.2);
            backdrop-filter: blur(10px);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: white;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .enhanced-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.9);
        }

        .enhanced-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .current-user {
            background: rgba(59, 130, 246, 0.1);
            border-left: 3px solid #3b82f6;
        }
        
        .current-user:hover {
            background: rgba(59, 130, 246, 0.15);
        }

        .department-card {
            transition: all 0.3s ease;
            border-radius: 12px;
        }

        .department-card:hover {
            transform: translateY(-2px);
        }

        .user-count-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
        }

        .user-count-low {
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .user-count-medium {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .user-count-high {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
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
                <span class="inline-block mt-2 px-3 py-1 bg-blue-500/20 text-blue-300 rounded-full text-xs font-medium">👑 Администратор</span>
            </div>
            
            <nav class="space-y-1">
                <a href="dashboard.php" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-chart-line mr-3 text-blue-400"></i>
                    <span class="font-medium">Дашборд</span>
                </a>
                <a href="admin.php?section=users" class="nav-item flex items-center px-4 py-3 <?php echo $section === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users mr-3 text-green-400"></i>
                    <span class="font-medium">Пользователи</span>
                </a>
                <a href="admin.php?section=departments" class="nav-item flex items-center px-4 py-3 <?php echo $section === 'departments' ? 'active' : ''; ?>">
                    <i class="fas fa-sitemap mr-3 text-purple-400"></i>
                    <span class="font-medium">Отделы</span>
                </a>
                <a href="tasks.php" class="nav-item flex items-center px-4 py-3">
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

        <!-- Main Content -->
        <div class="flex-1 p-6">
            <!-- Хлебные крошки -->
            <div class="mb-6">
                <nav class="flex text-sm text-white/60 mb-2">
                    <a href="dashboard.php" class="hover:text-white transition-colors">Главная</a>
                    <span class="mx-2">/</span>
                    <span class="text-white">
                        <?php 
                        switch($section) {
                            case 'users': echo 'Пользователи'; break;
                            case 'departments': echo 'Отделы'; break;
                            default: echo 'Административная панель';
                        }
                        ?>
                    </span>
                </nav>
                <h1 class="text-2xl font-bold text-white mb-1">
                    <?php 
                    switch($section) {
                        case 'users': echo 'Управление пользователями'; break;
                        case 'departments': echo 'Управление отделами'; break;
                        default: echo 'Административная панель';
                    }
                    ?>
                </h1>
                <p class="text-white/60">
                    <?php 
                    switch($section) {
                        case 'users': echo 'Добавление, редактирование и блокировка пользователей системы'; break;
                        case 'departments': echo 'Управление структурой отделов организации'; break;
                    }
                    ?>
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

            <!-- СОДЕРЖИМОЕ РАЗДЕЛОВ -->
            <?php if ($section === 'users'): ?>
                <!-- РАЗДЕЛ: ПОЛЬЗОВАТЕЛИ -->
                <div class="space-y-6">
                    <!-- Статистика -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Всего пользователей</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo count($users); ?></h3>
                                </div>
                                <div class="text-blue-400">
                                    <i class="fas fa-users text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Заблокировано</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo count(array_filter($users, fn($u) => $u['IsBlocked'])); ?></h3>
                                </div>
                                <div class="text-red-400">
                                    <i class="fas fa-lock text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">С ошибками входа</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo count(array_filter($users, fn($u) => $u['WrongAttempts'] > 0)); ?></h3>
                                </div>
                                <div class="text-yellow-400">
                                    <i class="fas fa-exclamation-triangle text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Ролей в системе</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo count(array_unique(array_column($users, 'RoleName'))); ?></h3>
                                </div>
                                <div class="text-purple-400">
                                    <i class="fas fa-user-tag text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Кнопка добавления -->
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white">Список пользователей</h2>
                        <button onclick="openCreateUserModal()" class="btn-glow text-white px-4 py-2 rounded-lg font-semibold flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>Добавить пользователя
                        </button>
                    </div>

                    <!-- Список пользователей -->
                    <div class="glass-card p-5">
                        <div class="table-container">
                            <table class="enhanced-table w-full">
                                <thead>
                                    <tr>
                                        <th>ФИО</th>
                                        <th>Логин</th>
                                        <th>Должность</th>
                                        <th>Роль</th>
                                        <th>Отдел</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($users)): ?>
                                        <?php foreach ($users as $user): ?>
                                        <tr class="<?php echo $user['UserID'] == $_SESSION['user_id'] ? 'current-user' : ''; ?>">
                                            <td class="font-medium">
                                                <div class="flex items-center">
                                                    <?php if ($user['UserID'] == $_SESSION['user_id']): ?>
                                                        <span class="badge badge-primary mr-2">Вы</span>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($user['FullName']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['Login']); ?></td>
                                            <td><?php echo $user['Position'] ? htmlspecialchars($user['Position']) : '<span class="text-white/40">—</span>'; ?></td>
                                            <td>
                                                <span class="badge badge-success"><?php echo htmlspecialchars($user['RoleName']); ?></span>
                                            </td>
                                            <td><?php echo $user['DepartmentName'] ? htmlspecialchars($user['DepartmentName']) : '<span class="text-white/40">—</span>'; ?></td>
                                            <td>
                                                <?php if ($user['IsBlocked']): ?>
                                                    <div class="blocked-status">
                                                        <span class="badge badge-danger">
                                                            <i class="fas fa-lock mr-1"></i>Заблокирован
                                                            <?php if ($user['BlockedUntil']): ?>
                                                                <br><small class="text-xs">до <?php echo date('H:i', strtotime($user['BlockedUntil'])); ?></small>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge badge-success">Активен</span>
                                                <?php endif; ?>
                                                
                                                <?php if ($user['WrongAttempts'] > 0): ?>
                                                    <div class="mt-1">
                                                        <span class="bg-yellow-500/20 text-yellow-300 text-xs px-2 py-1 rounded">
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                                            Попыток: <?php echo $user['WrongAttempts']; ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="flex items-center space-x-2">
                                                    <!-- Смена пароля -->
                                                    <button onclick="openChangePasswordModal(<?php echo $user['UserID']; ?>, '<?php echo htmlspecialchars($user['FullName']); ?>')" 
                                                            class="bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 px-3 py-2 rounded-lg transition-all duration-200" 
                                                            title="Сменить пароль">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    
                                                    <!-- Блокировка/разблокировка -->
                                                    <?php if ($user['UserID'] != $_SESSION['user_id']): ?>
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['UserID']; ?>">
                                                            <?php if ($user['IsBlocked']): ?>
                                                                <input type="hidden" name="action" value="unblock">
                                                                <button type="submit" name="toggle_block" 
                                                                        class="bg-green-500/20 text-green-300 hover:bg-green-500/30 px-3 py-2 rounded-lg transition-all duration-200" 
                                                                        title="Разблокировать">
                                                                    <i class="fas fa-unlock"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <input type="hidden" name="action" value="block">
                                                                <button type="submit" name="toggle_block" 
                                                                        class="bg-yellow-500/20 text-yellow-300 hover:bg-yellow-500/30 px-3 py-2 rounded-lg transition-all duration-200" 
                                                                        title="Заблокировать на 30 минут">
                                                                    <i class="fas fa-lock"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </form>
                                                        
                                                        <!-- Сброс счетчика попыток -->
                                                        <?php if ($user['WrongAttempts'] > 0): ?>
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['UserID']; ?>">
                                                            <button type="submit" name="reset_attempts" 
                                                                    class="bg-purple-500/20 text-purple-300 hover:bg-purple-500/30 px-3 py-2 rounded-lg transition-all duration-200" 
                                                                    title="Сбросить счетчик неверных попыток">
                                                                <i class="fas fa-sync-alt"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Удаление -->
                                                        <form method="POST" class="inline" onsubmit="return confirm('Вы уверены, что хотите удалить пользователя <?php echo htmlspecialchars(addslashes($user['FullName'])); ?>?')">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['UserID']; ?>">
                                                            <button type="submit" name="delete_user" 
                                                                    class="bg-red-500/20 text-red-300 hover:bg-red-500/30 px-3 py-2 rounded-lg transition-all duration-200" 
                                                                    title="Удалить">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-white/40 text-sm px-3 py-2">Текущий пользователь</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-8 text-white/60">
                                                <i class="fas fa-users text-4xl mb-4 opacity-50"></i>
                                                <p>Пользователи не найдены</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($section === 'departments'): ?>
                <!-- РАЗДЕЛ: ОТДЕЛЫ -->
                <div class="space-y-6">
                    <!-- Статистика отделов -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Всего отделов</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo count($departments); ?></h3>
                                </div>
                                <div class="text-blue-400">
                                    <i class="fas fa-sitemap text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Всего сотрудников</p>
                                    <h3 class="text-2xl font-bold text-white"><?php echo array_sum(array_column($departments, 'user_count')); ?></h3>
                                </div>
                                <div class="text-green-400">
                                    <i class="fas fa-users text-xl"></i>
                                </div>
                            </div>
                        </div>
                        <div class="stat-card glass-card p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-white/60 text-sm mb-1">Отделов с сотрудниками</p>
                                    <h3 class="text-2xl font-bold text-white">
                                        <?php 
                                        $total_depts = count($departments);
                                        $depts_with_users = count(array_filter($departments, fn($d) => $d['user_count'] > 0));
                                        echo $total_depts > 0 ? round(($depts_with_users / $total_depts) * 100) : 0;
                                        ?>%
                                    </h3>
                                </div>
                                <div class="text-purple-400">
                                    <i class="fas fa-chart-pie text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Кнопка добавления -->
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-bold text-white">Список отделов</h2>
                        <button onclick="openCreateDepartmentModal()" class="btn-glow text-white px-4 py-2 rounded-lg font-semibold flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>Добавить отдел
                        </button>
                    </div>

                    <!-- Список отделов -->
                    <div class="glass-card p-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php if (!empty($departments)): ?>
                                <?php foreach ($departments as $dept): 
                                    $user_count_class = 'user-count-low';
                                    if ($dept['user_count'] > 10) $user_count_class = 'user-count-high';
                                    elseif ($dept['user_count'] > 5) $user_count_class = 'user-count-medium';
                                ?>
                                <div class="department-card glass-card p-4">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center">
                                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-xl flex items-center justify-center text-white text-lg font-bold mr-3">
                                                <i class="fas fa-sitemap"></i>
                                            </div>
                                            <div>
                                                <h3 class="text-white font-semibold text-lg"><?php echo htmlspecialchars($dept['DepartmentName']); ?></h3>
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <span class="user-count-badge <?php echo $user_count_class; ?>">
                                                        <i class="fas fa-users mr-1"></i>
                                                        <?php echo $dept['user_count']; ?> сотрудников
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-between items-center pt-4 border-t border-white/10">
                                        <div class="text-white/60 text-sm">
                                            <?php if ($dept['user_count'] == 0): ?>
                                                <span class="text-yellow-400"><i class="fas fa-exclamation-circle mr-1"></i>Нет сотрудников</span>
                                            <?php elseif ($dept['user_count'] <= 3): ?>
                                                <span class="text-blue-400"><i class="fas fa-info-circle mr-1"></i>Маленький отдел</span>
                                            <?php elseif ($dept['user_count'] <= 8): ?>
                                                <span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>Средний отдел</span>
                                            <?php else: ?>
                                                <span class="text-purple-400"><i class="fas fa-users mr-1"></i>Большой отдел</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex space-x-2">
                                            <button onclick="openDeleteDepartmentModal(<?php echo $dept['DepartmentID']; ?>, '<?php echo htmlspecialchars($dept['DepartmentName']); ?>', <?php echo $dept['user_count']; ?>)" 
                                                    class="bg-red-500/20 text-red-300 hover:bg-red-500/30 px-3 py-2 rounded-lg transition-all duration-200"
                                                    title="Удалить отдел">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-span-3 text-center py-12 text-white/60">
                                    <i class="fas fa-sitemap text-4xl mb-4 opacity-50"></i>
                                    <p class="text-lg">Отделы не найдены</p>
                                    <p class="text-sm mt-2">Создайте первый отдел используя кнопку выше</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Модальные окна остаются без изменений -->
    <!-- ... -->

    <script>
        // Функции для модальных окон
        function openCreateUserModal() {
            document.getElementById('createUserModal').classList.remove('hidden');
        }

        function openCreateDepartmentModal() {
            document.getElementById('createDepartmentModal').classList.remove('hidden');
        }

        function openDeleteDepartmentModal(departmentId, departmentName, userCount) {
            document.getElementById('delete_department_id').value = departmentId;
            const infoElement = document.getElementById('delete_department_info');
            
            if (userCount > 0) {
                infoElement.textContent = `Нельзя удалить отдел "${departmentName}" - в нем ${userCount} сотрудник(ов). Сначала переместите их в другой отдел.`;
            } else {
                infoElement.textContent = `Вы уверены, что хотите удалить отдел "${departmentName}"?`;
            }
            
            document.getElementById('deleteDepartmentModal').classList.remove('hidden');
        }

        function openChangePasswordModal(userId, userName) {
            document.getElementById('change_password_user_id').value = userId;
            document.getElementById('change_password_user_name').textContent = 'Пользователь: ' + userName;
            document.getElementById('changePasswordModal').classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Закрытие модальных окон при клике вне их
        document.addEventListener('DOMContentLoaded', () => {
            const modals = ['createUserModal', 'createDepartmentModal', 'deleteDepartmentModal', 'changePasswordModal'];
            
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    window.addEventListener('click', function(event) {
                        if (event.target === modal) {
                            closeModal(modalId);
                        }
                    });
                }
            });
        });

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