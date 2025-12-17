<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (isset($_GET['file_id'])) {
    $file_id = $_GET['file_id'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT df.*, d.DocumentID, d.UserID, d.AssignedTo, d.DepartmentID
            FROM document_files df
            LEFT JOIN documents d ON df.DocumentID = d.DocumentID
            WHERE df.FileID = ?
        ");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($file) {
            // Проверяем права доступа
            $can_access = false;
            $user_id = $_SESSION['user_id'];
            $role_id = $_SESSION['role_id'];
            $is_admin = ($role_id == 1);
            $is_department_head = isDepartmentHead($role_id);
            
            if ($is_admin) {
                $can_access = true;
            } elseif ($is_department_head && $file['DepartmentID']) {
                $user_department_id = getDepartmentIdByHeadRole($role_id, $pdo);
                $can_access = ($file['DepartmentID'] == $user_department_id);
            } elseif ($file['UserID'] == $user_id || $file['AssignedTo'] == $user_id) {
                $can_access = true;
            }
            
            if ($can_access) {
                $file_path = __DIR__ . '/uploads/tasks/' . $file['FilePath'];
                
                if (file_exists($file_path)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . $file['FileType']);
                    header('Content-Disposition: attachment; filename="' . $file['FileName'] . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . $file['FileSize']);
                    readfile($file_path);
                    exit;
                } else {
                    die('Файл не найден на сервере');
                }
            } else {
                die('У вас нет прав для скачивания этого файла');
            }
        } else {
            die('Файл не найден');
        }
    } catch (PDOException $e) {
        die('Ошибка базы данных: ' . $e->getMessage());
    }
} else {
    die('Не указан ID файла');
}
?>