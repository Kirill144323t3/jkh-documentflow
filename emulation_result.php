<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Проверяем наличие данных эмуляции
if (!isset($_SESSION['emulation_data'])) {
    header("Location: emulator.php");
    exit;
}

$emulation_data = $_SESSION['emulation_data'];
unset($_SESSION['emulation_data']);

// Выполняем запрос к API эмулятора
$api_result = '';
$api_error = '';

try {
    $api_url = $emulation_data['api_url'];
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Результат эмуляции - ЖКХ Система</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); min-height: 100vh; color: white; }
        .glass-card { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 12px; backdrop-filter: blur(10px); }
    </style>
</head>
<body class="min-h-screen p-6">
    <div class="container mx-auto max-w-4xl">
        <div class="glass-card p-8">
            <!-- Заголовок -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-indigo-500/20 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-cogs text-indigo-400 text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white mb-2">Результат эмуляции ФИО</h1>
                <p class="text-white/60">Данные получены от API эмулятора</p>
            </div>

            <!-- Информация о клиенте -->
            <div class="mb-6">
                <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-user mr-3 text-blue-400"></i>
                    Информация о клиенте
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">ФИО клиента</p>
                        <p class="text-white font-semibold">
                            <?php echo htmlspecialchars($emulation_data['client_data']['LastName'] . ' ' . $emulation_data['client_data']['FirstName'] . ' ' . ($emulation_data['client_data']['MiddleName'] ?? '')); ?>
                        </p>
                    </div>
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">Критерий поиска</p>
                        <p class="text-white font-semibold">
                            <?php 
                            $criteria_names = [
                                'last_name' => 'По фамилии',
                                'first_name' => 'По имени', 
                                'all' => 'По всем критериям',
                                'services' => 'По услугам'
                            ];
                            echo $criteria_names[$emulation_data['search_criteria']] ?? 'Неизвестно';
                            ?>
                        </p>
                    </div>
                    <?php if (!empty($emulation_data['client_data']['Services'])): ?>
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">Услуги</p>
                        <p class="text-white font-semibold">
                            <?php echo htmlspecialchars($emulation_data['client_data']['Services']); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">ID клиента</p>
                        <p class="text-white font-semibold">#<?php echo $emulation_data['client_data']['ClientID']; ?></p>
                    </div>
                </div>
            </div>

            <!-- Результат эмуляции -->
            <div class="mb-6">
                <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-api mr-3 text-green-400"></i>
                    Результат эмуляции
                </h2>
                
                <?php if (!empty($api_error)): ?>
                    <div class="p-4 bg-red-500/20 border border-red-500/30 rounded-lg mb-4">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-red-400 mr-3"></i>
                            <span class="text-red-100"><?php echo htmlspecialchars($api_error); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($api_result)): ?>
                    <div class="p-4 bg-green-500/20 border border-green-500/30 rounded-lg mb-4">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-green-400 mr-3"></i>
                            <span class="text-green-100">API успешно ответило!</span>
                        </div>
                    </div>
                    
                    <div class="bg-white/10 rounded-lg p-6 text-center">
                        <p class="text-white/80 text-sm mb-2">Сгенерированное ФИО:</p>
                        <p class="text-white font-bold text-2xl"><?php echo htmlspecialchars($api_result); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Информация о запросе -->
            <div class="mb-6">
                <h2 class="text-lg font-bold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle mr-3 text-purple-400"></i>
                    Информация о запросе
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">URL API</p>
                        <p class="text-white font-mono text-sm"><?php echo htmlspecialchars($emulation_data['api_url']); ?></p>
                    </div>
                    <div class="bg-white/5 rounded-lg p-4">
                        <p class="text-white/60 text-sm mb-1">Время запроса</p>
                        <p class="text-white font-semibold"><?php echo htmlspecialchars($emulation_data['timestamp']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Кнопки действий -->
            <div class="flex justify-center space-x-4 mt-8">
                <a href="emulator.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-semibold transition-colors flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>Назад к эмулятору
                </a>
                <button onclick="window.close()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-semibold transition-colors flex items-center">
                    <i class="fas fa-times mr-2"></i>Закрыть вкладку
                </button>
            </div>
        </div>
    </div>

    <script>
        // Автоматическое закрытие через 10 секунд
        setTimeout(() => {
            if (confirm('Закрыть вкладку с результатом?')) {
                window.close();
            }
        }, 10000);
    </script>
</body>
</html>