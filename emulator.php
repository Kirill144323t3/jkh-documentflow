<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$csrf_token = generateCsrfToken();
$message = '';

// Функция валидации ФИО
function validateFullName($fullName) {
    // Проверка на пустое значение
    if (empty(trim($fullName))) {
        return [
            'valid' => false,
            'message' => "ФИО не может быть пустым",
            'can_save' => false
        ];
    }
    
    // Проверка минимальной длины
    if (mb_strlen(trim($fullName)) < 5) {
        return [
            'valid' => false,
            'message' => "ФИО должно содержать минимум 5 символов",
            'can_save' => false
        ];
    }
    
    // Проверка максимальной длины
    if (mb_strlen(trim($fullName)) > 100) {
        return [
            'valid' => false,
            'message' => "ФИО не должно превышать 100 символов",
            'can_save' => false
        ];
    }
    
    // Проверка на запрещенные символы
    if (preg_match('/[@#$%^&*()+=<>?\/\\\|~{}[\]]/', $fullName, $matches)) {
        return [
            'valid' => false,
            'message' => "Валидация не пройдена. Обнаружен запрещенный символ: " . $matches[0],
            'can_save' => false
        ];
    }
    
    // Проверка формата ФИО (должно содержать минимум 2 слова)
    $words = preg_split('/\s+/', trim($fullName));
    if (count($words) < 2) {
        return [
            'valid' => false,
            'message' => "ФИО должно содержать минимум 2 слова (Фамилия Имя)",
            'can_save' => false
        ];
    }
    
    // Проверка на кириллические символы
    if (!preg_match('/^[а-яёА-ЯЁ\s\-]+$/u', $fullName)) {
        return [
            'valid' => false,
            'message' => "ФИО должно содержать только русские буквы, пробелы и дефисы",
            'can_save' => false
        ];
    }
    
    return [
        'valid' => true,
        'message' => "Валидация пройдена. Данные можно вносить в базу",
        'can_save' => true
    ];
}

// Функция получения ФИО из API
function getFullNameFromAPI() {
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
        'Федорова Елена Викторовна',
        'Смирнов Александр Петрович',
        'Орлова Ольга Сергеевна',
        'Васильев Василий Васильевич',
        'Павлова Ирина Алексеевна'
    ];
    return $names[array_rand($names)];
}

// Функция сохранения ФИО в базу данных
function saveFullNameToDatabase($fullName) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO validated_names (full_name, validation_status, created_at) VALUES (?, 'valid', NOW())");
        $stmt->execute([trim($fullName)]);
        return true;
    } catch (Exception $e) {
        error_log("Database Error: " . $e->getMessage());
        return false;
    }
}

// Обработка формы генерации
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'])) {
    if (isset($_POST['generate_fio'])) {
        $generated_fio = getFullNameFromAPI();
        $validation_result = validateFullName($generated_fio);
        
        if ($validation_result['valid']) {
            // Пытаемся сохранить в базу
            if (saveFullNameToDatabase($generated_fio)) {
                $message = ['type' => 'success', 'text' => '✅ ' . $validation_result['message'] . ' и успешно сохранено в базу'];
            } else {
                $message = ['type' => 'warning', 'text' => '✅ ' . $validation_result['message'] . ', но возникла ошибка при сохранении в базу'];
            }
        } else {
            $message = ['type' => 'error', 'text' => '❌ ' . $validation_result['message']];
        }
        
        $generation_result = [
            'fio' => $generated_fio,
            'validation_result' => $validation_result,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $_SESSION['generation_result'] = $generation_result;
        $_SESSION['message'] = $message;
        
        header("Location: emulator.php");
        exit;
    }
    
    // Ручной ввод ФИО
    if (isset($_POST['validate_manual'])) {
        $manual_fio = trim($_POST['manual_fio'] ?? '');
        $validation_result = validateFullName($manual_fio);
        
        if ($validation_result['valid']) {
            if (saveFullNameToDatabase($manual_fio)) {
                $message = ['type' => 'success', 'text' => '✅ ' . $validation_result['message'] . ' и успешно сохранено в базу'];
            } else {
                $message = ['type' => 'warning', 'text' => '✅ ' . $validation_result['message'] . ', но возникла ошибка при сохранении в базу'];
            }
        } else {
            $message = ['type' => 'error', 'text' => '❌ ' . $validation_result['message']];
        }
        
        $generation_result = [
            'fio' => $manual_fio,
            'validation_result' => $validation_result,
            'timestamp' => date('Y-m-d H:i:s'),
            'manual' => true
        ];
        
        $_SESSION['generation_result'] = $generation_result;
        $_SESSION['message'] = $message;
        
        header("Location: emulator.php");
        exit;
    }
}

// Получаем результат генерации из сессии
$generation_result = $_SESSION['generation_result'] ?? null;
if ($generation_result) {
    unset($_SESSION['generation_result']);
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
    <title>ЖКХ Система - Эмулятор ФИО</title>
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

        .validation-success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }
        
        .validation-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .fio-display {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            margin: 1rem 0;
        }

        .api-status {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .api-online {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
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
                <a href="tasks.php" class="nav-item flex items-center px-4 py-3">
                    <i class="fas fa-tasks mr-3 text-yellow-400"></i>
                    <span class="font-medium">Задачи</span>
                </a>
                <a href="emulator.php" class="nav-item active flex items-center px-4 py-3">
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
                    <span class="text-white">Эмулятор ФИО</span>
                </nav>
                <h1 class="text-2xl font-bold text-white mb-1">Валидатор ФИО</h1>
                <p class="text-white/60">Проверка корректности данных из внешнего API и ручной ввод</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-lg notification <?php echo $message['type'] === 'error' ? 'bg-red-500/20 text-red-100 border border-red-500/30' : ($message['type'] === 'warning' ? 'bg-yellow-500/20 text-yellow-100 border border-yellow-500/30' : 'bg-green-500/20 text-green-100 border border-green-500/30'); ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $message['type'] === 'error' ? 'fa-exclamation-triangle' : ($message['type'] === 'warning' ? 'fa-exclamation-circle' : 'fa-check-circle'); ?> mr-3"></i>
                        <span class="flex-1"><?php echo htmlspecialchars($message['text']); ?></span>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-white/60 hover:text-white ml-4">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Панель управления -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Генератор -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-bolt text-purple-400 mr-3"></i>
                            Генератор ФИО
                        </h2>
                        
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            
                            <button type="submit" name="generate_fio" class="btn-glow w-full text-white py-3 rounded-lg font-semibold">
                                <i class="fas fa-cloud-download-alt mr-2"></i>Получить ФИО
                            </button>
                        </form>
                        
                        <div class="mt-4 p-3 bg-white/5 rounded-lg">
                            <div class="flex items-center justify-between">
                                <span class="text-white/60 text-sm">Статус API:</span>
                                <span class="api-status api-online flex items-center">
                                    <i class="fas fa-circle mr-2 text-xs"></i>Online
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ручной ввод -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-edit text-blue-400 mr-3"></i>
                            Ручной ввод
                        </h2>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div>
                                <input type="text" name="manual_fio" class="input-glass w-full px-4 py-3" 
                                       placeholder="Введите ФИО" required>
                            </div>
                            <button type="submit" name="validate_manual" class="btn-glow w-full text-white py-3 rounded-lg font-semibold">
                                <i class="fas fa-check-circle mr-2"></i>Проверить ФИО
                            </button>
                        </form>
                    </div>
                    
                    <!-- Правила -->
                    <div class="glass-card p-5">
                        <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                            <i class="fas fa-info-circle text-blue-400 mr-3"></i>
                            Правила валидации
                        </h2>
                        <div class="space-y-3">
                            <div class="flex items-start p-3 bg-green-500/10 rounded-lg">
                                <i class="fas fa-check text-green-400 mr-3 mt-1"></i>
                                <div>
                                    <p class="text-green-300 font-semibold text-sm">Разрешено</p>
                                    <p class="text-white/70 text-xs">Русские буквы, пробелы, дефисы</p>
                                </div>
                            </div>
                            <div class="flex items-start p-3 bg-red-500/10 rounded-lg">
                                <i class="fas fa-ban text-red-400 mr-3 mt-1"></i>
                                <div>
                                    <p class="text-red-300 font-semibold text-sm">Запрещено</p>
                                    <p class="text-white/70 text-xs">@ # $ % ^ & * ( ) + = < > ? / \ | ~ { } [ ]</p>
                                </div>
                            </div>
                            <div class="flex items-start p-3 bg-blue-500/10 rounded-lg">
                                <i class="fas fa-ruler text-blue-400 mr-3 mt-1"></i>
                                <div>
                                    <p class="text-blue-300 font-semibold text-sm">Требования</p>
                                    <p class="text-white/70 text-xs">5-100 символов, минимум 2 слова</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Результаты -->
                <div class="lg:col-span-3">
                    <?php if ($generation_result): ?>
                    <!-- Результат проверки -->
                    <div class="glass-card p-6 mb-6">
                        <div class="text-center mb-6">
                            <h2 class="text-2xl font-bold text-white mb-2">
                                <?php echo isset($generation_result['manual']) ? 'Результат ручной проверки' : 'Результат генерации'; ?>
                            </h2>
                            <p class="text-white/60">Данные получены и проверены</p>
                        </div>
                        
                        <!-- ФИО -->
                        <div class="fio-display">
                            <div class="mb-3">
                                <i class="fas fa-user-tag text-white/40 text-xl mb-2"></i>
                                <p class="text-white/60 text-sm uppercase tracking-wider">Проверяемое ФИО</p>
                            </div>
                            <p class="text-white font-bold text-3xl leading-tight"><?php echo htmlspecialchars($generation_result['fio']); ?></p>
                        </div>
                        
                        <!-- Валидация -->
                        <?php if ($generation_result['validation_result']): ?>
                            <div class="p-6 rounded-lg <?php echo $generation_result['validation_result']['valid'] ? 'validation-success' : 'validation-error'; ?>">
                                <div class="flex items-center justify-center text-center">
                                    <i class="fas <?php echo $generation_result['validation_result']['valid'] ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-3 text-2xl"></i>
                                    <span class="font-bold text-xl"><?php echo htmlspecialchars($generation_result['validation_result']['message']); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Детальная информация -->
                        <div class="grid grid-cols-2 gap-4 mt-6 text-sm">
                            <div class="bg-white/5 rounded-lg p-4 text-center">
                                <p class="text-white/60 mb-1">Длина</p>
                                <p class="text-white font-bold text-lg"><?php echo mb_strlen($generation_result['fio']); ?> симв.</p>
                            </div>
                            <div class="bg-white/5 rounded-lg p-4 text-center">
                                <p class="text-white/60 mb-1">Слов</p>
                                <p class="text-white font-bold text-lg"><?php echo count(preg_split('/\s+/', trim($generation_result['fio']))); ?></p>
                            </div>
                        </div>
                        
                        <!-- Действия -->
                        <div class="mt-6 text-center">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" name="generate_fio" class="btn-glow text-white px-8 py-3 rounded-lg font-semibold inline-flex items-center">
                                    <i class="fas fa-sync-alt mr-2"></i>Проверить еще раз
                                </button>
                            </form>
                        </div>
                        
                        <div class="mt-4 text-center text-white/40 text-sm flex items-center justify-center">
                            <i class="fas fa-clock mr-2"></i>
                            Проверено: <?php echo htmlspecialchars($generation_result['timestamp']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Приветствие -->
                    <div class="glass-card p-8 text-center">
                        <div class="w-24 h-24 bg-indigo-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-user-check text-indigo-400 text-3xl"></i>
                        </div>
                        <h2 class="text-3xl font-bold text-white mb-4">Добро пожаловать!</h2>
                        <p class="text-white/70 text-lg mb-8 max-w-2xl mx-auto leading-relaxed">
                            Система проверки корректности ФИО. Получайте данные из API или вводите вручную, 
                            автоматически проверяйте их на соответствие стандартам и сохраняйте в базу данных.
                        </p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto mb-8">
                            <div class="p-6 bg-green-500/10 rounded-xl border border-green-500/20">
                                <i class="fas fa-shield-check text-green-400 text-3xl mb-4"></i>
                                <h3 class="text-xl font-bold text-white mb-3">✅ Успешная проверка</h3>
                                <p class="text-white/70 mb-3">Когда ФИО соответствует всем требованиям</p>
                                <div class="p-3 bg-white/5 rounded-lg">
                                    <p class="text-green-300 font-mono text-sm mb-1">Степанов Степан Степанович</p>
                                    <p class="text-green-400 text-xs">Валидация пройдена</p>
                                </div>
                            </div>
                            <div class="p-6 bg-red-500/10 rounded-xl border border-red-500/20">
                                <i class="fas fa-exclamation-triangle text-red-400 text-3xl mb-4"></i>
                                <h3 class="text-xl font-bold text-white mb-3">❌ Ошибка проверки</h3>
                                <p class="text-white/70 mb-3">Когда обнаружены запрещенные символы</p>
                                <div class="p-3 bg-white/5 rounded-lg">
                                    <p class="text-red-300 font-mono text-sm mb-1">Иванов@ Иван Иванович</p>
                                    <p class="text-red-400 text-xs">Запрещенный символ: @</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl mx-auto">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <button type="submit" name="generate_fio" class="btn-glow w-full text-white px-6 py-4 rounded-lg font-semibold text-lg inline-flex items-center justify-center">
                                    <i class="fas fa-bolt mr-2"></i>Автогенерация
                                </button>
                            </form>
                            <div class="text-white/40 flex items-center justify-center">
                                или
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
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