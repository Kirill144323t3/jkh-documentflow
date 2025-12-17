<?php
if (!file_exists('config.php')) {
    die('Файл config.php не найден!');
}

require_once 'config.php';

$csrf_token = generateCsrfToken();
$message = '';

if (!isset($_SESSION['captcha_display_order'])) {
    generateNewCaptcha();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_login'])) {
    $login = trim($_POST['login']);
    $password = trim($_POST['password']);

    $captcha_completed = $_POST['captcha_completed'] ?? '0';
    $captcha_result = $_POST['captcha_result'] ?? '';
    
    if ($captcha_completed !== '1') {
        $message = ['type' => 'error', 'text' => 'Пожалуйста, завершите капчу для входа!'];
    } elseif (empty($captcha_result)) {
        $message = ['type' => 'error', 'text' => 'Ошибка проверки капчи!'];
    } else {
        $submitted_placement = json_decode($captcha_result, true);
        $is_captcha_valid = verifyCaptcha($submitted_placement);
        
        if ($is_captcha_valid) {
            if (empty($login) || empty($password)) {
                $message = ['type' => 'error', 'text' => 'Логин и пароль обязательны!'];
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT u.UserID, u.FullName, u.RoleID, u.Position,
                              r.RoleName, 
                              d.DepartmentName,
                              reg.Login, reg.Password, reg.IsBlocked, reg.BlockedUntil, reg.WrongAttempts
                        FROM users u 
                        JOIN registration reg ON u.UserID = reg.UserID 
                        LEFT JOIN roles r ON u.RoleID = r.RoleID
                        LEFT JOIN departments d ON u.DepartmentID = d.DepartmentID
                        WHERE reg.Login = :login
                    ");
                    $stmt->execute([':login' => $login]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user) {
                        $message = ['type' => 'error', 'text' => 'Неверный логин или пароль.'];
                        generateNewCaptcha();
                    } else {
                        // Проверяем статус блокировки
                        $block_status = checkUserBlockStatus($user['UserID'], $pdo);
                        
                        if ($block_status && $block_status['is_blocked']) {
                            $remaining_time = formatRemainingTime($block_status['remaining_time']);
                            $message = ['type' => 'error', 'text' => "🔒 Аккаунт заблокирован. Попробуйте через " . $remaining_time];
                            generateNewCaptcha();
                        } elseif ($user['IsBlocked'] && $user['BlockedUntil'] && strtotime($user['BlockedUntil']) > time()) {
                            $remaining_time = formatRemainingTime(strtotime($user['BlockedUntil']) - time());
                            $message = ['type' => 'error', 'text' => "🔒 Аккаунт заблокирован. Попробуйте через " . $remaining_time];
                            generateNewCaptcha();
                        } elseif (password_verify($password, $user['Password'])) {
                            // Успешный вход - сбрасываем счетчик попыток
                            resetLoginAttempts($user['UserID'], $pdo);
                            
                            $_SESSION['user_id'] = $user['UserID'];
                            $_SESSION['login'] = $user['Login'];
                            $_SESSION['role_id'] = $user['RoleID'];
                            $_SESSION['role_name'] = $user['RoleName'];
                            $_SESSION['full_name'] = $user['FullName'];
                            $_SESSION['position'] = $user['Position'] ?? 'Сотрудник';
                            $_SESSION['department_name'] = $user['DepartmentName'] ?? '';
                            
                            clearCaptcha();
                            header("Location: dashboard.php");
                            exit;
                        } else {
                            // Неверный пароль - увеличиваем счетчик попыток
                            $result = handleFailedLogin($login, $pdo);
                            
                            if ($result === "blocked") {
                                $message = ['type' => 'error', 'text' => "🔒 Аккаунт заблокирован на 30 минут из-за 3 неверных попыток входа!"];
                            } elseif ($result === "admin_immune") {
                                $message = ['type' => 'error', 'text' => 'Неверный пароль. У администраторов нет ограничений по попыткам.'];
                            } else {
                                // Получаем актуальное количество оставшихся попыток
                                $stmt = $pdo->prepare("SELECT WrongAttempts FROM registration WHERE Login = :login");
                                $stmt->execute([':login' => $login]);
                                $attempts_data = $stmt->fetch(PDO::FETCH_ASSOC);
                                $remaining_attempts = 3 - ($attempts_data['WrongAttempts'] ?? 0);
                                
                                if ($remaining_attempts <= 0) {
                                    $message = ['type' => 'error', 'text' => "🔒 Аккаунт заблокирован на 30 минут!"];
                                } else {
                                    $message = ['type' => 'error', 'text' => "Неверный пароль. Осталось попыток: " . $remaining_attempts];
                                }
                            }
                            generateNewCaptcha();
                        }
                    }
                } catch (PDOException $e) {
                    $message = ['type' => 'error', 'text' => 'Ошибка сервера.'];
                    generateNewCaptcha();
                }
            }
        } else {
            $message = ['type' => 'error', 'text' => 'Капча пройдена неправильно! Попробуйте снова.'];
            generateNewCaptcha();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ЖКХ Система - Вход</title>
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
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="container mx-auto max-w-md">
        <div class="glass-card p-8">
            <!-- Заголовок -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-blue-500/20 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-building text-blue-400 text-2xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-white mb-2">ЖКХ Система</h1>
                <p class="text-white/60">Войдите в систему</p>
            </div>

            <!-- Информация о безопасности -->
            <div class="mb-6 p-4 bg-blue-500/20 border border-blue-500/30 rounded-lg">
                <div class="flex items-center text-blue-300 text-sm">
                    <i class="fas fa-shield-alt mr-2"></i>
                    <span>Система защиты: 3 неверные попытки = блокировка на 30 минут</span>
                </div>
            </div>

            <!-- Сообщения -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-lg notification <?php echo $message['type'] === 'error' ? 'bg-red-500/20 text-red-100 border border-red-500/30' : 'bg-green-500/20 text-green-100 border border-green-500/30'; ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $message['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> mr-3"></i>
                        <?php echo htmlspecialchars($message['text']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Форма входа -->
            <form method="POST" class="space-y-6" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <!-- Поле логина -->
                <div class="space-y-2">
                    <label class="block text-white/80 text-sm font-medium">Логин</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-user text-white/60"></i>
                        </div>
                        <input type="text" name="login" class="input-glass w-full pl-12 pr-4 py-3" required placeholder="Введите ваш логин" value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>">
                    </div>
                </div>
                
                <!-- Поле пароля -->
                <div class="space-y-2">
                    <label class="block text-white/80 text-sm font-medium">Пароль</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-white/60"></i>
                        </div>
                        <input type="password" name="password" id="password" class="input-glass w-full pl-12 pr-12 py-3" required placeholder="Введите ваш пароль">
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center">
                            <button type="button" class="text-white/60 hover:text-white toggle-password">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Капча -->
                <div class="space-y-4">
                    <label class="block text-white/80 text-sm font-medium">
                        <i class="fas fa-shield-alt mr-2"></i>Защита от роботов
                    </label>
                    <?php include 'captcha.php'; ?>
                </div>

                <!-- Кнопка входа -->
                <button type="submit" name="submit_login" class="btn-glow w-full text-white py-3 rounded-lg font-semibold">
                    <i class="fas fa-sign-in-alt mr-2"></i>Войти в систему
                </button>
            </form>

            <!-- Информация о системе -->
            <div class="mt-6 p-4 bg-white/10 rounded-lg border border-white/20">
                <h3 class="text-white font-semibold mb-3 text-sm flex items-center">
                    <i class="fas fa-info-circle mr-2"></i>Система безопасности
                </h3>
                <div class="space-y-2 text-white/70 text-xs">
                    <div class="flex items-center">
                        <i class="fas fa-clock w-4 mr-2 text-yellow-400"></i>
                        <span>3 неверные попытки → блокировка 30 минут</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-user-shield w-4 mr-2 text-green-400"></i>
                        <span>Администраторы защищены от блокировки</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-sync-alt w-4 mr-2 text-blue-400"></i>
                        <span>Счетчик сбрасывается при успешном входе</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Переключение видимости пароля
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const passwordInput = document.getElementById('password');
                    const icon = this.querySelector('i');
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                });
            });

            // Проверка капчи перед отправкой формы
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                const captchaCompleted = document.getElementById('captcha_completed').value;
                if (captchaCompleted !== '1') {
                    e.preventDefault();
                    alert('Пожалуйста, завершите сборку пазла!');
                    return false;
                }
            });
        });
    </script>
</body>
</html>