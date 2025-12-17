<?php
require_once 'config.php';

// Проверяем авторизацию и права
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

$action = $_GET['action'] ?? 'create';

try {
    switch ($action) {
        case 'create':
            createBackup();
            break;
        case 'list':
            showBackupList();
            break;
        case 'download':
            downloadBackup();
            break;
        case 'delete':
            deleteBackup();
            break;
        default:
            createBackup();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function createBackup() {
    global $pdo;
    
    // Создаем папку для бэкапов
    $backupDir = 'backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // Генерируем имя файла
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;
    
    // Получаем список таблиц
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $sqlContent = "-- MySQL Backup\n";
    $sqlContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sqlContent .= "-- Database: " . DB_NAME . "\n\n";
    
    foreach ($tables as $table) {
        // Сохраняем структуру таблицы
        $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $sqlContent .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlContent .= $createTable['Create Table'] . ";\n\n";
        
        // Сохраняем данные таблицы
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $sqlContent .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES \n";
            
            $values = [];
            foreach ($rows as $row) {
                $escapedValues = array_map(function($value) use ($pdo) {
                    if ($value === null) return 'NULL';
                    return $pdo->quote($value);
                }, $row);
                
                $values[] = "(" . implode(', ', $escapedValues) . ")";
            }
            
            $sqlContent .= implode(",\n", $values) . ";\n\n";
        }
    }
    
    // Сохраняем файл
    if (file_put_contents($filepath, $sqlContent)) {
        // Удаляем старые бэкапы (старше 30 дней)
        cleanupOldBackups($backupDir);
        
        echo json_encode([
            'success' => true,
            'message' => 'Резервная копия создана успешно',
            'filename' => $filename,
            'size' => formatFilesize(filesize($filepath))
        ]);
    } else {
        throw new Exception('Не удалось сохранить файл бэкапа');
    }
}

function showBackupList() {
    $backupDir = 'backups/';
    
    if (!is_dir($backupDir)) {
        echo "<h3>Нет созданных бэкапов</h3>";
        return;
    }
    
    $backups = [];
    $files = glob($backupDir . "backup_*.sql");
    
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'time' => filemtime($file)
        ];
    }
    
    // Сортируем по дате (новые сначала)
    usort($backups, function($a, $b) {
        return $b['time'] - $a['time'];
    });
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Список бэкапов</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-database"></i> Резервные копии базы данных</h1>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Назад в дашборд
                </a>
            </div>
            
            <?php if (empty($backups)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Нет созданных резервных копий
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Имя файла</th>
                                <th>Размер</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-file-code text-primary me-2"></i>
                                    <?php echo htmlspecialchars($backup['name']); ?>
                                </td>
                                <td><?php echo formatFilesize($backup['size']); ?></td>
                                <td><?php echo date('d.m.Y H:i', $backup['time']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="backup.php?action=download&file=<?php echo urlencode($backup['name']); ?>" 
                                           class="btn btn-success" title="Скачать">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <a href="backup.php?action=delete&file=<?php echo urlencode($backup['name']); ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('Вы уверены, что хотите удалить этот бэкап?')"
                                           title="Удалить">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Внимание:</strong> Бэкапы автоматически удаляются через 30 дней
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

function downloadBackup() {
    $filename = $_GET['file'] ?? '';
    $filepath = 'backups/' . $filename;
    
    // Проверяем безопасность
    if (file_exists($filepath) && strpos($filename, 'backup_') === 0) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        echo "Файл не найден";
    }
}

function deleteBackup() {
    $filename = $_GET['file'] ?? '';
    $filepath = 'backups/' . $filename;
    
    if (file_exists($filepath) && unlink($filepath)) {
        header('Location: backup.php?action=list');
    } else {
        echo "Ошибка при удалении файла";
    }
}

function cleanupOldBackups($dir) {
    $files = glob($dir . "backup_*.sql");
    $keepTime = time() - (30 * 24 * 60 * 60); // 30 дней
    
    foreach ($files as $file) {
        if (filemtime($file) < $keepTime) {
            unlink($file);
        }
    }
}

function formatFilesize($bytes) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>