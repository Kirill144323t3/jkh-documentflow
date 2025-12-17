<?php
// captcha.php: Пазл-капча 2x2

// Порядок отображения частей (перемешанный)
$display_order = [1, 2, 3, 4];
shuffle($display_order);

// Путь к изображениям
$images_path = 'captcha-images/';

// Создаем папку для изображений, если её нет
if (!file_exists($images_path)) {
    mkdir($images_path, 0755, true);
}

// Создаем простые изображения для пазла, если их нет
for ($i = 1; $i <= 4; $i++) {
    $image_file = $images_path . $i . '.png';
    if (!file_exists($image_file)) {
        createPuzzleImage($i, $image_file);
    }
}

// Функция для создания простых изображений пазла
function createPuzzleImage($number, $filename) {
    $width = 100;
    $height = 100;
    
    // Создаем изображение
    $image = imagecreate($width, $height);
    
    // Цвета
    $colors = [
        1 => [imagecolorallocate($image, 59, 130, 246), imagecolorallocate($image, 37, 99, 235)], // Синий
        2 => [imagecolorallocate($image, 16, 185, 129), imagecolorallocate($image, 5, 150, 105)],  // Зеленый
        3 => [imagecolorallocate($image, 245, 158, 11), imagecolorallocate($image, 217, 119, 6)],  // Оранжевый
        4 => [imagecolorallocate($image, 139, 92, 246), imagecolorallocate($image, 124, 58, 237)] // Фиолетовый
    ];
    
    $bg_color = $colors[$number][0];
    $text_color = imagecolorallocate($image, 255, 255, 255);
    $border_color = $colors[$number][1];
    
    // Заливаем фон
    imagefill($image, 0, 0, $bg_color);
    
    // Рисуем границу
    imagerectangle($image, 2, 2, $width-3, $height-3, $border_color);
    imagerectangle($image, 3, 3, $width-4, $height-4, $border_color);
    
    // Добавляем текст с номером
    $font = 5; // Встроенный шрифт GD
    $text = (string)$number;
    $text_width = imagefontwidth($font) * strlen($text);
    $text_height = imagefontheight($font);
    $x = ($width - $text_width) / 2;
    $y = ($height - $text_height) / 2;
    
    imagestring($image, $font, $x, $y, $text, $text_color);
    
    // Сохраняем изображение
    imagepng($image, $filename);
    imagedestroy($image);
}
?>

<div class="captcha-container bg-gradient-to-br from-gray-800/50 to-gray-900/50 backdrop-blur-lg rounded-2xl p-6 border border-white/10 shadow-2xl">
    <div class="text-center mb-4">
        <div class="flex items-center justify-center space-x-2 mb-2">
            <i class="fas fa-puzzle-piece text-blue-400 text-lg"></i>
            <h3 class="text-white font-semibold text-lg">Соберите пазл</h3>
        </div>
        <p class="text-white/70 text-sm">Перетащите все части пазла на любые позиции</p>
    </div>

    <div class="puzzle-area">
        <!-- Сетка 2x2 для сборки -->
        <div class="puzzle-grid mb-6">
            <div class="grid grid-cols-2 gap-3 bg-black/20 rounded-xl p-4 border-2 border-white/10">
                <!-- Верхний ряд -->
                <div class="puzzle-slot aspect-square bg-gradient-to-br from-green-500/10 to-green-600/10 border-2 border-dashed border-green-400/30 rounded-lg flex items-center justify-center transition-all duration-300"
                     data-position="0,0"
                     id="slot_0_0">
                    <div class="empty-state text-center">
                        <i class="fas fa-border-top-left text-green-400 text-xl"></i>
                        <p class="text-green-400 text-xs font-medium mt-1">Верх-лево</p>
                    </div>
                </div>
                <div class="puzzle-slot aspect-square bg-gradient-to-br from-blue-500/10 to-blue-600/10 border-2 border-dashed border-blue-400/30 rounded-lg flex items-center justify-center transition-all duration-300"
                     data-position="1,0"
                     id="slot_1_0">
                    <div class="empty-state text-center">
                        <i class="fas fa-border-top-right text-blue-400 text-xl"></i>
                        <p class="text-blue-400 text-xs font-medium mt-1">Верх-право</p>
                    </div>
                </div>
                
                <!-- Нижний ряд -->
                <div class="puzzle-slot aspect-square bg-gradient-to-br from-purple-500/10 to-purple-600/10 border-2 border-dashed border-purple-400/30 rounded-lg flex items-center justify-center transition-all duration-300"
                     data-position="0,1"
                     id="slot_0_1">
                    <div class="empty-state text-center">
                        <i class="fas fa-border-bottom-left text-purple-400 text-xl"></i>
                        <p class="text-purple-400 text-xs font-medium mt-1">Низ-лево</p>
                    </div>
                </div>
                <div class="puzzle-slot aspect-square bg-gradient-to-br from-orange-500/10 to-orange-600/10 border-2 border-dashed border-orange-400/30 rounded-lg flex items-center justify-center transition-all duration-300"
                     data-position="1,1"
                     id="slot_1_1">
                    <div class="empty-state text-center">
                        <i class="fas fa-border-bottom-right text-orange-400 text-xl"></i>
                        <p class="text-orange-400 text-xs font-medium mt-1">Низ-право</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Части пазла -->
        <div class="puzzle-pieces">
            <div class="grid grid-cols-4 gap-2 p-4 bg-white/5 rounded-xl">
                <?php foreach ($display_order as $piece): ?>
                    <?php
                    $image_file = $images_path . $piece . '.png';
                    $image_exists = file_exists($image_file);
                    ?>
                    <div class="puzzle-piece aspect-square bg-white/10 border-2 border-white/20 rounded-lg cursor-grab active:cursor-grabbing transition-all duration-300 hover:border-blue-400 hover:bg-blue-500/20 hover:scale-105 flex items-center justify-center"
                         draggable="true"
                         data-piece="<?php echo $piece; ?>"
                         id="piece_<?php echo $piece; ?>">
                        <div class="text-center">
                            <?php if ($image_exists): ?>
                                <img src="<?php echo $image_file; ?>" 
                                     alt="Пазл <?php echo $piece; ?>"
                                     class="w-16 h-16 object-cover rounded-lg mx-auto">
                            <?php else: ?>
                                <!-- Fallback если изображение не создалось -->
                                <div class="w-16 h-16 rounded-lg mx-auto flex items-center justify-center text-white font-bold text-lg
                                    <?php echo $piece == 1 ? 'bg-blue-500' : ''; ?>
                                    <?php echo $piece == 2 ? 'bg-green-500' : ''; ?>
                                    <?php echo $piece == 3 ? 'bg-orange-500' : ''; ?>
                                    <?php echo $piece == 4 ? 'bg-purple-500' : ''; ?>">
                                    <?php echo $piece; ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-white/80 text-xs mt-1">Часть <?php echo $piece; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Скрытые поля для результата -->
    <input type="hidden" name="captcha_result" id="captcha_result" value="">
    <input type="hidden" name="captcha_completed" id="captcha_completed" value="0">

    <!-- Статус и управление -->
    <div class="mt-4 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div id="status-icon" class="w-8 h-8 bg-yellow-500/20 rounded-full flex items-center justify-center">
                <i class="fas fa-clock text-yellow-400 text-sm"></i>
            </div>
            <div>
                <p id="status-text" class="text-white font-medium text-sm">Соберите пазл</p>
            </div>
        </div>
        
        <div class="flex space-x-2">
            <button type="button" id="reset-captcha" class="px-3 py-2 bg-gray-500/20 text-gray-300 rounded-lg text-sm hover:bg-gray-500/30 transition-colors">
                <i class="fas fa-redo mr-1"></i>Сброс
            </button>
        </div>
    </div>

    <!-- Прогресс -->
    <div class="mt-3">
        <div class="flex items-center justify-between mb-1">
            <span class="text-white/70 text-xs">Прогресс:</span>
            <span id="progress-text" class="text-white text-xs font-medium">0/4</span>
        </div>
        <div class="w-full bg-white/10 rounded-full h-2">
            <div id="progress-bar" class="bg-gradient-to-r from-green-400 to-blue-500 h-2 rounded-full transition-all duration-500" style="width: 0%"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    class PuzzleCaptcha2x2 {
        constructor() {
            this.pieces = document.querySelectorAll('.puzzle-piece');
            this.slots = document.querySelectorAll('.puzzle-slot');
            this.captchaResult = document.getElementById('captcha_result');
            this.captchaCompleted = document.getElementById('captcha_completed');
            this.progressBar = document.getElementById('progress-bar');
            this.progressText = document.getElementById('progress-text');
            this.statusIcon = document.getElementById('status-icon');
            this.statusText = document.getElementById('status-text');
            this.resetButton = document.getElementById('reset-captcha');
            
            this.placedPieces = 0;
            this.totalPieces = 4;
            this.currentPlacement = {}; // { position: piece }
            
            this.init();
        }
        
        init() {
            this.setupEventListeners();
            this.updateProgress();
        }
        
        setupEventListeners() {
            // Drag & Drop для частей пазла
            this.pieces.forEach(piece => {
                piece.addEventListener('dragstart', this.handleDragStart.bind(this));
                piece.addEventListener('dragend', this.handleDragEnd.bind(this));
            });
            
            this.slots.forEach(slot => {
                slot.addEventListener('dragover', this.handleDragOver.bind(this));
                slot.addEventListener('dragleave', this.handleDragLeave.bind(this));
                slot.addEventListener('drop', this.handleDrop.bind(this));
            });
            
            // Кнопка сброса
            this.resetButton.addEventListener('click', this.handleReset.bind(this));
        }
        
        handleDragStart(e) {
            const piece = e.target.closest('.puzzle-piece');
            piece.classList.add('dragging');
            e.dataTransfer.setData('text/plain', piece.dataset.piece);
            
            setTimeout(() => {
                piece.style.opacity = '0.4';
            }, 0);
        }
        
        handleDragEnd(e) {
            const piece = e.target.closest('.puzzle-piece');
            piece.classList.remove('dragging');
            piece.style.opacity = '1';
            this.slots.forEach(slot => slot.classList.remove('drag-over'));
        }
        
        handleDragOver(e) {
            e.preventDefault();
            const slot = e.target.closest('.puzzle-slot');
            if (slot && !slot.classList.contains('filled')) {
                slot.classList.add('drag-over');
            }
        }
        
        handleDragLeave(e) {
            const slot = e.target.closest('.puzzle-slot');
            if (slot) {
                slot.classList.remove('drag-over');
            }
        }
        
        handleDrop(e) {
            e.preventDefault();
            
            const slot = e.target.closest('.puzzle-slot');
            if (!slot || slot.classList.contains('filled')) return;
            
            slot.classList.remove('drag-over');
            
            const pieceId = e.dataTransfer.getData('text/plain');
            const piece = document.getElementById(`piece_${pieceId}`);
            const position = slot.dataset.position;
            
            if (piece && position) {
                this.placePiece(piece, slot, pieceId, position);
            }
        }
        
        placePiece(piece, slot, pieceId, position) {
            console.log('Placing piece:', pieceId, 'at position:', position);
            
            // Убираем часть из предыдущей позиции если была
            for (const [pos, pid] of Object.entries(this.currentPlacement)) {
                if (pid === pieceId) {
                    delete this.currentPlacement[pos];
                    const prevSlot = document.getElementById(`slot_${pos.replace(',', '_')}`);
                    if (prevSlot) {
                        prevSlot.classList.remove('filled', 'correct', 'incorrect');
                        this.restoreEmptySlot(prevSlot, pos);
                    }
                    this.placedPieces--;
                    break;
                }
            }
            
            // Помещаем в новую позицию
            slot.classList.add('filled');
            
            // Клонируем содержимое
            const pieceContent = piece.innerHTML;
            slot.innerHTML = pieceContent;
            
            // Сохраняем размещение
            this.currentPlacement[position] = pieceId;
            this.updateResults();
            
            // Считаем заполненные слоты
            this.placedPieces = Object.keys(this.currentPlacement).length;
            this.updateProgress();
            
            // Проверяем завершение сразу после размещения
            if (this.placedPieces === this.totalPieces) {
                this.checkCompletion();
            }
        }
        
        restoreEmptySlot(slot, position) {
            const positions = {
                '0,0': { icon: 'border-top-left', color: 'green', text: 'Верх-лево' },
                '1,0': { icon: 'border-top-right', color: 'blue', text: 'Верх-право' },
                '0,1': { icon: 'border-bottom-left', color: 'purple', text: 'Низ-лево' },
                '1,1': { icon: 'border-bottom-right', color: 'orange', text: 'Низ-право' }
            };
            
            const config = positions[position];
            if (config) {
                slot.innerHTML = `
                    <div class="empty-state text-center">
                        <i class="fas fa-${config.icon} text-${config.color}-400 text-xl"></i>
                        <p class="text-${config.color}-400 text-xs font-medium mt-1">${config.text}</p>
                    </div>
                `;
            }
        }
        
        updateResults() {
            // Сохраняем текущее размещение
            this.captchaResult.value = JSON.stringify(this.currentPlacement);
            console.log('Captcha result updated:', this.captchaResult.value);
        }
        
        updateProgress() {
            const progress = (this.placedPieces / this.totalPieces) * 100;
            this.progressBar.style.width = `${progress}%`;
            this.progressText.textContent = `${this.placedPieces}/${this.totalPieces}`;
            
            if (this.placedPieces === this.totalPieces) {
                this.statusIcon.innerHTML = '<i class="fas fa-check text-green-400 text-sm"></i>';
                this.statusIcon.className = 'w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center';
                this.statusText.textContent = 'Пазл собран!';
                this.statusText.className = 'text-green-400 font-medium text-sm';
            } else {
                this.statusIcon.innerHTML = '<i class="fas fa-puzzle-piece text-yellow-400 text-sm"></i>';
                this.statusIcon.className = 'w-8 h-8 bg-yellow-500/20 rounded-full flex items-center justify-center';
                this.statusText.textContent = 'Соберите пазл';
                this.statusText.className = 'text-white font-medium text-sm';
            }
        }
        
        checkCompletion() {
            // ВСЕГДА считаем капчу пройденной, если собраны все 4 части
            // Неважно в каком порядке!
            const isComplete = this.placedPieces === this.totalPieces;
            
            console.log('All pieces placed! Captcha completed.');
            console.log('Current placement:', this.currentPlacement);
            
            if (isComplete) {
                this.captchaCompleted.value = '1';
                this.showSuccess();
                console.log('CAPTCHA COMPLETED! Value set to:', this.captchaCompleted.value);
            }
            
            // Обновляем скрытое поле
            this.updateResults();
        }
        
        showSuccess() {
            this.slots.forEach(slot => {
                slot.classList.add('correct');
            });
            
            const container = document.querySelector('.captcha-container');
            container.style.borderColor = '#10B981';
            container.style.background = 'rgba(16, 185, 129, 0.1)';
            
            this.statusText.textContent = 'Пазл собран! Можно входить в систему.';
            this.statusIcon.innerHTML = '<i class="fas fa-check text-green-400 text-sm"></i>';
            this.statusIcon.className = 'w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center';
            
            console.log('Success shown, captcha_completed:', this.captchaCompleted.value);
        }
        
        handleReset() {
            console.log('Resetting captcha...');
            
            this.placedPieces = 0;
            this.currentPlacement = {};
            this.captchaResult.value = '';
            this.captchaCompleted.value = '0';
            
            this.slots.forEach(slot => {
                slot.classList.remove('filled', 'correct', 'incorrect', 'drag-over');
                const position = slot.dataset.position;
                this.restoreEmptySlot(slot, position);
            });
            
            const container = document.querySelector('.captcha-container');
            container.style.borderColor = '';
            container.style.background = '';
            
            this.updateProgress();
            this.updateResults();
            
            console.log('Captcha reset complete, captcha_completed:', this.captchaCompleted.value);
        }
    }
    
    // Инициализация CAPTCHA
    new PuzzleCaptcha2x2();
});
</script>

<style>
.puzzle-slot.drag-over {
    border-style: solid !important;
    transform: scale(1.05);
    border-color: #3b82f6 !important;
}

.puzzle-slot.filled {
    border-style: solid !important;
}

.puzzle-slot.correct {
    border-style: solid !important;
    border-color: #10B981 !important;
}

.puzzle-piece.dragging {
    opacity: 0.6;
    transform: rotate(5deg) scale(1.05);
}

img {
    -webkit-user-drag: none;
    -khtml-user-drag: none;
    -moz-user-drag: none;
    -o-user-drag: none;
    user-drag: none;
}

.empty-state {
    opacity: 0.7;
}
</style>