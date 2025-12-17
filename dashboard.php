<?php
require_once 'config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Получаем статистику для дашборда
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$is_admin = ($role_id == 1);

// Статистика задач
if ($is_admin) {
    $stats_sql = "SELECT 
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN StatusID = 1 THEN 1 END) as new_tasks,
        COUNT(CASE WHEN StatusID = 2 THEN 1 END) as in_progress_tasks,
        COUNT(CASE WHEN StatusID = 3 THEN 1 END) as completed_tasks,
        COUNT(CASE WHEN Deadline < CURDATE() AND StatusID != 3 THEN 1 END) as overdue_tasks
    FROM documents";
    $task_stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);
} else {
    $stats_sql = "SELECT 
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN StatusID = 1 THEN 1 END) as new_tasks,
        COUNT(CASE WHEN StatusID = 2 THEN 1 END) as in_progress_tasks,
        COUNT(CASE WHEN StatusID = 3 THEN 1 END) as completed_tasks,
        COUNT(CASE WHEN Deadline < CURDATE() AND StatusID != 3 THEN 1 END) as overdue_tasks
    FROM documents 
    WHERE UserID = ? OR AssignedTo = ?";
    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute([$user_id, $user_id]);
    $task_stats = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Обработка теста API
$api_result = '';
$api_error = '';
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'])) {
    if (isset($_POST['test_api'])) {
        try {
            $api_url = "http://prb.sylas.ru/TransferSimulator/fullName";
            $response = file_get_contents($api_url);
            $data = json_decode($response, true);
            
            if (isset($data['value'])) {
                $api_result = $data['value'];
            } else {
                $api_error = 'Некорректный ответ от API';
            }
        } catch (Exception $e) {
            $api_error = 'Ошибка подключения к API: ' . $e->getMessage();
        }
    }
}

// Умные уведомления - ТОЛЬКО ПОЗИТИВНЫЕ
$notifications = [];

// Генерация умных уведомлений - ТОЛЬКО ПОЗИТИВНЫЕ
if (($task_stats['completed_tasks'] ?? 0) > 5) {
    $notifications[] = [
        'type' => 'success',
        'icon' => 'fa-trophy',
        'message' => "🎉 Отлично! Выполнено " . ($task_stats['completed_tasks'] ?? 0) . " задач"
    ];
}

if (($task_stats['total_tasks'] ?? 0) > 0) {
    $completion_rate = round((($task_stats['completed_tasks'] ?? 0) / ($task_stats['total_tasks'] ?? 1)) * 100);
    if ($completion_rate > 70) {
        $notifications[] = [
            'type' => 'success', 
            'icon' => 'fa-chart-line',
            'message' => "📈 Высокая эффективность! Завершено $completion_rate% задач"
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ЖКХ Система - Дашборд</title>
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
                <a href="dashboard.php" class="nav-item active flex items-center px-4 py-3">
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

        <!-- Основной контент -->
        <div class="flex-1 p-6">
            <!-- Хлебные крошки -->
            <div class="mb-6">
                <nav class="flex text-sm text-white/60 mb-2">
                    <a href="dashboard.php" class="hover:text-white transition-colors">Главная</a>
                    <span class="mx-2">/</span>
                    <span class="text-white">Дашборд</span>
                </nav>
                <h1 class="text-2xl font-bold text-white mb-1">Обзор системы</h1>
                <p class="text-white/60">Статистика и ключевые показатели эффективности</p>
            </div>

            <?php if (!empty($notifications)): ?>
            <div class="mb-6 space-y-3">
                <?php foreach ($notifications as $notification): ?>
                <div class="notification glass-card p-4 rounded-lg border-green-500 bg-green-500/10">
                    <div class="flex items-center">
                        <i class="fas <?php echo $notification['icon']; ?> mr-3"></i>
                        <span class="font-medium"><?php echo $notification['message']; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Статистика -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Всего задач</p>
                            <h3 class="text-2xl font-bold text-white"><?php echo $task_stats['total_tasks'] ?? 0; ?></h3>
                        </div>
                        <div class="text-blue-400">
                            <i class="fas fa-tasks text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Выполнено</p>
                            <h3 class="text-2xl font-bold text-white"><?php echo $task_stats['completed_tasks'] ?? 0; ?></h3>
                        </div>
                        <div class="text-green-400">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">В работе</p>
                            <h3 class="text-2xl font-bold text-white"><?php echo $task_stats['in_progress_tasks'] ?? 0; ?></h3>
                        </div>
                        <div class="text-yellow-400">
                            <i class="fas fa-spinner text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card glass-card p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Просрочено</p>
                            <h3 class="text-2xl font-bold text-white"><?php echo $task_stats['overdue_tasks'] ?? 0; ?></h3>
                        </div>
                        <div class="text-red-400">
                            <i class="fas fa-exclamation-triangle text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Основной контент -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Быстрый доступ -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-rocket text-purple-400 mr-3"></i>
                            Быстрый доступ
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <a href="tasks.php" class="flex items-center p-4 bg-white/5 rounded-lg hover:bg-white/10 transition-all duration-300 group">
                                <div class="w-12 h-12 bg-blue-500/20 rounded-xl flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-tasks text-blue-400 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold">Мои задачи</h3>
                                    <p class="text-white/60 text-sm">Управление задачами</p>
                                </div>
                            </a>
                            
                            <?php if ($is_admin): ?>
                            <a href="admin.php?section=users" class="flex items-center p-4 bg-white/5 rounded-lg hover:bg-white/10 transition-all duration-300 group">
                                <div class="w-12 h-12 bg-purple-500/20 rounded-xl flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-users text-purple-400 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold">Пользователи</h3>
                                    <p class="text-white/60 text-sm">Управление доступом</p>
                                </div>
                            </a>
                            
                            <a href="admin.php?section=departments" class="flex items-center p-4 bg-white/5 rounded-lg hover:bg-white/10 transition-all duration-300 group">
                                <div class="w-12 h-12 bg-orange-500/20 rounded-xl flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-sitemap text-orange-400 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold">Отделы</h3>
                                    <p class="text-white/60 text-sm">Организационная структура</p>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <a href="emulator.php" class="flex items-center p-4 bg-white/5 rounded-lg hover:bg-white/10 transition-all duration-300 group">
                                <div class="w-12 h-12 bg-indigo-500/20 rounded-xl flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-cogs text-indigo-400 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-white font-semibold">Эмулятор</h3>
                                    <p class="text-white/60 text-sm">Проверка ФИО</p>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Тестирование API -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-api text-indigo-400 mr-3"></i>
                            Тестирование API ФИО
                        </h2>
                        
                        <div class="space-y-4">
                            <?php if (!empty($api_error)): ?>
                                <div class="p-4 bg-red-500/20 border border-red-500/30 rounded-lg">
                                    <div class="flex items-center">
                                        <i class="fas fa-exclamation-triangle text-red-400 mr-3"></i>
                                        <span class="text-red-100"><?php echo htmlspecialchars($api_error); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($api_result)): ?>
                                <div class="p-4 bg-green-500/20 border border-green-500/30 rounded-lg">
                                    <div class="flex items-center">
                                        <i class="fas fa-check-circle text-green-400 mr-3"></i>
                                        <span class="text-green-100">API успешно ответило!</span>
                                    </div>
                                </div>
                                <div class="p-4 bg-white/10 rounded-lg">
                                    <p class="text-white/80 text-sm mb-2">Сгенерированное ФИО:</p>
                                    <p class="text-white font-semibold text-xl"><?php echo htmlspecialchars($api_result); ?></p>
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                
                                <div class="space-y-2">
                                    <label class="block text-white/80 text-sm font-medium">URL API</label>
                                    <input type="text" value="http://prb.sylas.ru/TransferSimulator/fullName" class="input-glass w-full px-4 py-3" readonly>
                                </div>
                                
                                <div class="flex space-x-3">
                                    <button type="submit" name="test_api" class="btn-glow flex-1 text-white py-3 rounded-lg font-semibold">
                                        <i class="fas fa-play mr-2"></i>Протестировать API
                                    </button>
                                </div>
                            </form>

                            <!-- JavaScript тест в реальном времени -->
                            <div class="mt-4 pt-4 border-t border-white/10">
                                <h3 class="text-white font-semibold mb-4 flex items-center">
                                    <i class="fas fa-bolt mr-2 text-yellow-400"></i>
                                    Тест в реальном времени
                                </h3>
                                
                                <div class="space-y-3">
                                    <div class="flex space-x-2">
                                        <input type="text" id="realtime_full_name" class="input-glass flex-1 px-4 py-3" placeholder="Сгенерированное ФИО появится здесь" readonly>
                                        <button type="button" onclick="generateRandomNameRealTime()" class="bg-purple-500/20 text-purple-300 hover:bg-purple-500/30 px-4 py-3 rounded-lg transition-all duration-200 flex items-center">
                                            <i class="fas fa-random mr-2"></i>
                                            <span>Сгенерировать</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Боковая панель -->
                <div class="space-y-6">
                    <!-- Информация о системе -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-info-circle text-blue-400 mr-3"></i>
                            Информация о системе
                        </h2>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center py-2 border-b border-white/10">
                                <span class="text-white/60 text-sm">Пользователь:</span>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-white/10">
                                <span class="text-white/60 text-sm">Роль:</span>
                                <span class="text-white font-medium"><?php echo htmlspecialchars($_SESSION['role_name']); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-white/10">
                                <span class="text-white/60 text-sm">Версия PHP:</span>
                                <span class="text-white font-medium"><?php echo PHP_VERSION; ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-white/60 text-sm">База данных:</span>
                                <span class="text-white font-medium">MySQL</span>
                            </div>
                        </div>
                    </div>

                    <!-- Статус системы -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-server text-green-400 mr-3"></i>
                            Статус системы
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 bg-green-500/10 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 bg-green-400 rounded-full mr-3"></div>
                                    <span class="text-white font-medium text-sm">Система</span>
                                </div>
                                <span class="text-green-400 font-semibold text-sm">Активна</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-blue-500/10 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 bg-blue-400 rounded-full mr-3"></div>
                                    <span class="text-white font-medium text-sm">База данных</span>
                                </div>
                                <span class="text-blue-400 font-semibold text-sm">Online</span>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-green-500/10 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-3 h-3 bg-green-400 rounded-full mr-3"></div>
                                    <span class="text-white font-medium text-sm">API ФИО</span>
                                </div>
                                <span class="text-green-400 font-semibold text-sm">Доступно</span>
                            </div>
                        </div>
                    </div>

                    <!-- Информация об API -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-shield-alt text-purple-400 mr-3"></i>
                            Информация об API
                        </h2>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between py-2 border-b border-white/10">
                                <span class="text-white/60">Эндпоинт:</span>
                                <span class="text-white font-medium">/fullName</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-white/10">
                                <span class="text-white/60">Метод:</span>
                                <span class="text-white font-medium">GET</span>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <span class="text-white/60">Формат:</span>
                                <span class="text-white font-medium">JSON</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Функция для генерации случайного ФИО в реальном времени
        async function generateRandomNameRealTime() {
            const nameField = document.getElementById('realtime_full_name');
            const button = event.currentTarget;
            
            // Сохраняем оригинальный текст
            const originalText = button.innerHTML;
            
            // Показываем загрузку
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><span>Загрузка...</span>';
            button.disabled = true;
            
            try {
                // Делаем запрос к API
                const response = await fetch('http://prb.sylas.ru/TransferSimulator/fullName');
                
                if (!response.ok) {
                    throw new Error('Ошибка API: ' + response.status);
                }
                
                const data = await response.json();
                
                if (data.value) {
                    // Заполняем поле ФИО
                    nameField.value = data.value;
                    showNotification('✅ ФИО успешно сгенерировано из API!', 'success');
                } else {
                    throw new Error('Некорректный ответ от API');
                }
                
            } catch (error) {
                console.error('Ошибка:', error);
                
                // Fallback - локальная генерация
                const fallbackNames = [
                    'Иванов Иван Иванович',
                    'Петрова Мария Сергеевна', 
                    'Сидоров Алексей Петрович',
                    'Козлова Анна Владимировна'
                ];
                const randomName = fallbackNames[Math.floor(Math.random() * fallbackNames.length)];
                nameField.value = randomName;
                
                showNotification('⚠️ Использовано локальное ФИО (API недоступно)', 'warning');
            } finally {
                // Восстанавливаем кнопку
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Функция для показа уведомлений
        function showNotification(message, type = 'info') {
            // Создаем элемент уведомления
            const notification = document.createElement('div');
            notification.className = `mb-4 p-4 rounded-lg border-l-4 ${
                type === 'success' ? 'bg-green-500/20 text-green-100 border-green-500' :
                type === 'warning' ? 'bg-yellow-500/20 text-yellow-100 border-yellow-500' :
                'bg-blue-500/20 text-blue-100 border-blue-500'
            }`;
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${
                        type === 'success' ? 'fa-check-circle' :
                        type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'
                    } mr-3"></i>
                    <span class="flex-1">${message}</span>
                    <button onclick="this.parentElement.parentElement.remove()" class="text-white/60 hover:text-white ml-4">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            // Вставляем уведомление перед секцией API
            const apiSection = document.querySelector('.glass-card:has(.fa-api)');
            if (apiSection) {
                apiSection.parentNode.insertBefore(notification, apiSection);
            }
            
            // Авто-удаление через 5 секунд
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Автоматическое скрытие уведомлений через 8 секунд
        setTimeout(() => {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach(notification => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 500);
            });
        }, 8000);
    </script>
</body>
</html>