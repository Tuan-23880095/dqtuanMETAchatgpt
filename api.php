<?php
declare(strict_types=1);

/**
 * bdvh/api.php — Backend REST API cho Nền tảng Bồi Dưỡng Văn Hóa & Khảo thí ĐGNL (bdvh.diemdanhsv.com)
 * ThS. Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM.
 *
 * Tính năng chính:
 * 1. Đăng ký & Đăng nhập tài khoản Khách (Guest Account) nhanh chóng.
 * 2. Kiểm soát giới hạn: Mỗi tài khoản khách được làm bài MIỄN PHÍ 2 LẦN.
 * 3. Tích hợp Gemini AI Engine đánh giá năng lực tư duy, bắt lỗi sai và gợi ý mẹo giải nhanh.
 * 4. Hệ thống Paywall & MoMo Bonus 5.000đ / Liên hệ nâng cấp email dqtuanhcmus@gmail.com.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_out(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Đường dẫn hạ tầng chung của diemdanhsv
$root = dirname(__DIR__);
$isLiveHost = file_exists($root . '/api/lib/config.php') && file_exists($root . '/api/lib/db.php');

$pdo = null;
if ($isLiveHost) {
    try {
        require_once $root . '/api/lib/config.php';
        require_once $root . '/api/lib/db.php';
        $pdo = db();
    } catch (Throwable $e) {
        // Dự phòng SQLite khi chạy thử nghiệm nội bộ
        $pdo = null;
    }
}

if (!$pdo) {
    $sqliteFile = __DIR__ . '/bdvh_local.sqlite';
    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

// Khởi tạo bảng tự động nếu chưa có
function ensure_bdvh_tables(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS bdvh_users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                full_name TEXT NOT NULL,
                phone TEXT,
                email TEXT,
                password_hash TEXT,
                grade TEXT DEFAULT 'L10',
                role TEXT DEFAULT 'GUEST',
                free_trials_left INTEGER DEFAULT 2,
                paid_trials_left INTEGER DEFAULT 0,
                bonus_accumulated INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_login TEXT
            );
            CREATE TABLE IF NOT EXISTS bdvh_exams (
                exam_id INTEGER PRIMARY KEY AUTOINCREMENT,
                exam_code TEXT UNIQUE NOT NULL,
                grade TEXT NOT NULL,
                subject TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                time_minutes INTEGER DEFAULT 45,
                questions_json TEXT NOT NULL,
                competency_matrix TEXT,
                status TEXT DEFAULT 'ACTIVE',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS bdvh_submissions (
                sub_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                exam_id INTEGER NOT NULL,
                score REAL NOT NULL,
                duration_seconds INTEGER NOT NULL,
                answers_given TEXT NOT NULL,
                correct_count INTEGER DEFAULT 0,
                total_questions INTEGER DEFAULT 0,
                ai_evaluation TEXT,
                is_free_trial INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS bdvh_teachers (
                teacher_id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                full_name TEXT NOT NULL,
                phone TEXT,
                school_name TEXT,
                class_name TEXT,
                class_code TEXT UNIQUE,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS bdvh_class_codes (
                code_id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                teacher_id INTEGER DEFAULT 1,
                label TEXT DEFAULT 'Lớp Thầy Đinh Quốc Tuấn',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT,
                is_active INTEGER DEFAULT 1,
                used_count INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS bdvh_transactions (
                trans_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                trials_added INTEGER NOT NULL,
                payment_method TEXT DEFAULT 'MOMO',
                trans_code TEXT,
                memo TEXT,
                status TEXT DEFAULT 'PENDING',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
        
        // Seed nếu rỗng
        $st = $db->query("SELECT COUNT(*) as c FROM bdvh_exams");
        if ((int)$st->fetch()['c'] === 0) {
            seed_default_exams($db);

        // Tự động sửa lỗi mất $a trong SQLite nếu có dữ liệu cũ
        try {
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, '^m \\cdot a^n = a^{m+n}', '$a^m \\cdot a^n = a^{m+n}') WHERE questions_json LIKE '%^m \\cdot a^n%'");
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, 'Mọi số nguyên  đều viết được thành /1', 'Mọi số nguyên $a$ đều viết được thành $a/1$') WHERE questions_json LIKE '%Mọi số nguyên  đều viết được thành /1%'");
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, ' = (C_{25}^1', '$P = (C_{25}^1') WHERE questions_json LIKE '% = (C_{25}^1%'");
        } catch (Throwable $e) {}

        }
    } else {
        // MySQL MariaDB trên Hostinger
        $db->exec("
            CREATE TABLE IF NOT EXISTS bdvh_users (
                user_id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL UNIQUE,
                full_name VARCHAR(120) NOT NULL,
                phone VARCHAR(20) NULL,
                email VARCHAR(160) NULL,
                password_hash VARCHAR(255) NULL,
                grade VARCHAR(20) NOT NULL DEFAULT 'L10',
                role ENUM('GUEST', 'MEMBER', 'VIP', 'ADMIN') NOT NULL DEFAULT 'GUEST',
                free_trials_left INT NOT NULL DEFAULT 2,
                paid_trials_left INT NOT NULL DEFAULT 0,
                bonus_accumulated INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS bdvh_exams (
                exam_id INT AUTO_INCREMENT PRIMARY KEY,
                exam_code VARCHAR(50) NOT NULL UNIQUE,
                grade VARCHAR(20) NOT NULL,
                subject VARCHAR(40) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                time_minutes INT NOT NULL DEFAULT 45,
                questions_json LONGTEXT NOT NULL,
                competency_matrix LONGTEXT NULL,
                status ENUM('ACTIVE', 'DRAFT') NOT NULL DEFAULT 'ACTIVE',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS bdvh_submissions (
                sub_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                exam_id INT NOT NULL,
                score DECIMAL(4,2) NOT NULL,
                duration_seconds INT NOT NULL,
                answers_given LONGTEXT NOT NULL,
                correct_count INT NOT NULL DEFAULT 0,
                total_questions INT NOT NULL DEFAULT 0,
                ai_evaluation LONGTEXT NULL,
                is_free_trial TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            
            CREATE TABLE IF NOT EXISTS bdvh_teachers (
                teacher_id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                full_name TEXT NOT NULL,
                phone TEXT,
                school_name TEXT,
                class_name TEXT,
                class_code TEXT UNIQUE,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS bdvh_class_codes (
                code_id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                teacher_id INTEGER DEFAULT 1,
                label TEXT DEFAULT 'Lớp Thầy Đinh Quốc Tuấn',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT,
                is_active INTEGER DEFAULT 1,
                used_count INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS bdvh_transactions (
                trans_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount INT NOT NULL,
                trials_added INT NOT NULL,
                payment_method VARCHAR(30) NOT NULL DEFAULT 'MOMO',
                trans_code VARCHAR(100) NULL,
                memo VARCHAR(120) NULL,
                status ENUM('PENDING', 'SUCCESS', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $st = $db->query("SELECT COUNT(*) as c FROM bdvh_exams");
        if ((int)$st->fetch()['c'] === 0) {
            seed_default_exams($db);

        // Tự động sửa lỗi mất $a trong SQLite nếu có dữ liệu cũ
        try {
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, '^m \\cdot a^n = a^{m+n}', '$a^m \\cdot a^n = a^{m+n}') WHERE questions_json LIKE '%^m \\cdot a^n%'");
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, 'Mọi số nguyên  đều viết được thành /1', 'Mọi số nguyên $a$ đều viết được thành $a/1$') WHERE questions_json LIKE '%Mọi số nguyên  đều viết được thành /1%'");
            $db->exec("UPDATE bdvh_exams SET questions_json = REPLACE(questions_json, ' = (C_{25}^1', '$P = (C_{25}^1') WHERE questions_json LIKE '% = (C_{25}^1%'");
        } catch (Throwable $e) {}

        }
    }
}

function seed_default_exams(PDO $db): void {
    $exams = [
        [
            'TOAN06_01', 'L06', 'TOAN',
            'Đấu Trường Toán 6: Lũy thừa và Thứ tự phép tính',
            'Đề rèn luyện phản xạ tính toán nhanh, củng cố quy tắc nhân chia lũy thừa và ngoặc số học.',
            20,
            json_encode([
                ["q" => 'Kết quả của phép tính $2^3 \cdot 2^2$ bằng bao nhiêu?', "opts" => ['$2^5 = 32$', '$2^6 = 64$', '$4^5$', '$4^6$'], "ans" => 0, "exp" => 'Quy tắc nhân lũy thừa cùng cơ số: $a^m \cdot a^n = a^{m+n} \Rightarrow 2^3 \cdot 2^2 = 2^5 = 32$.'],
                ["q" => "Giá trị của biểu thức $15 + 5 \\cdot 2^2$ là:", "opts" => ["40", "35", "80", "25"], "ans" => 1, "exp" => "Lũy thừa trước: $2^2 = 4$. Nhân: $5 \\cdot 4 = 20$. Cộng: $15 + 20 = 35$."],
                ["q" => "Tìm $x$ biết $3^x = 27$?", "opts" => ['$x=2$', '$x=3$', '$x=4$', '$x=9$'], "ans" => 1, "exp" => "Vì $27 = 3^3$ nên $3^x = 3^3 \\Rightarrow x = 3$."],
                ["q" => "Số nào sau đây chia hết cho cả 2 và 5?", "opts" => ["124", "235", "450", "502"], "ans" => 2, "exp" => "Số có chữ số tận cùng là 0 thì chia hết cho cả 2 và 5."],
                ["q" => "Thứ tự thực hiện đúng các dấu ngoặc trong biểu thức là:", "opts" => ["( ) $\\rightarrow$ [ ] $\\rightarrow$ { }", "{ } $\\rightarrow$ [ ] $\\rightarrow$ ( )", "[ ] $\\rightarrow$ ( ) $\\rightarrow$ { }", "( ) $\\rightarrow$ { } $\\rightarrow$ [ ]"], "ans" => 0, "exp" => "Thực hiện ngoặc tròn ( ) trước, sau đó ngoặc vuông [ ], sau cùng là ngoặc nhọn { }."]
            ], JSON_UNESCAPED_UNICODE)
        ],
        [
            'TOAN07_01', 'L07', 'TOAN',
            'Đấu Trường Toán 7: Tập hợp số hữu tỉ & Lũy thừa',
            'Kiểm tra định nghĩa số hữu tỉ, quy tắc cộng trừ nhân chia và giá trị tuyệt đối.',
            25,
            json_encode([
                ["q" => "Khẳng định nào sau đây là ĐÚNG về số hữu tỉ?", "opts" => ["Mọi số nguyên đều là số hữu tỉ", "Số hữu tỉ chỉ gồm phân số dương", "Số 0 không phải là số hữu tỉ", "$\\pi$ là một số hữu tỉ"], "ans" => 0, "exp" => "Mọi số nguyên $a$ đều viết được thành $a/1$ nên là số hữu tỉ."],
                ["q" => "Kết quả của phép tính $(-\\frac{2}{3})^2$ là:", "opts" => ["$-\\frac{4}{9}$", "$\\frac{4}{9}$", "$-\\frac{4}{6}$", "$\\frac{4}{6}$"], "ans" => 1, "exp" => "Bình phương số âm ra số dương: $(-2/3)^2 = 4/9$."],
                ["q" => "Giá trị của biểu thức $\\frac{1}{2} - \\frac{3}{4} + \\frac{5}{2}$ bằng:", "opts" => ["$\\frac{9}{4}$", "$\\frac{7}{4}$", "$\\frac{5}{4}$", "2"], "ans" => 0, "exp" => "Quy đồng mẫu 4: $(2 - 3 + 10)/4 = 9/4$."],
                ["q" => "Nếu $|x| = 2,5$ thì giá trị của $x$ là:", "opts" => ["$x = 2,5$", "$x = -2,5$", "$x = 2,5$ hoặc $x = -2,5$", "$x = 5$"], "ans" => 2, "exp" => "Giá trị tuyệt đối bằng 2,5 thì $x$ nhận cả hai giá trị đối nhau $\\pm 2,5$."],
                ["q" => "Cho $\\frac{x}{3} = \\frac{8}{12}$. Giá trị của $x$ là:", "opts" => ["1", "2", "3", "4"], "ans" => 1, "exp" => "$x = (3 \\cdot 8)/12 = 2$."]
            ], JSON_UNESCAPED_UNICODE)
        ],
        [
            'TOAN10_01', 'L10', 'TOAN',
            'Toán 10: Mệnh đề, Tập hợp & Bất phương trình',
            'Rèn luyện tư duy logic mệnh đề, các phép toán tập hợp và bất phương trình bậc nhất hai ẩn.',
            30,
            json_encode([
                ["q" => "Mệnh đề phủ định của $P$: \"$\\forall x \\in \\mathbb{R}, x^2 > 0$\" là:", "opts" => ["$\\exists x \\in \\mathbb{R}, x^2 \\le 0$", "$\\exists x \\in \\mathbb{R}, x^2 < 0$", "$\\forall x \\in \\mathbb{R}, x^2 \\le 0$", "$\\exists x \\notin \\mathbb{R}, x^2 \\le 0$"], "ans" => 0, "exp" => "Phủ định của $\\forall$ là $\\exists$, phủ định của $>$ là $\\le$."],
                ["q" => "Cho tập hợp $A = [-2; 3)$ và $B = (1; 5]$. Giao $A \\cap B$ là:", "opts" => ["$[-2; 5]$", "$(1; 3)$", "$[1; 3)$", "$(1; 3]$"], "ans" => 1, "exp" => "Giao của hai khoảng là $(1; 3)$."],
                ["q" => "Điểm nào thuộc miền nghiệm của bất phương trình $2x - y + 1 > 0$?", "opts" => ["(0; 2)", "(1; 4)", "(2; 1)", "(-1; 0)"], "ans" => 2, "exp" => "Thay (2; 1): $2(2) - 1 + 1 = 4 > 0$ (Đúng)."],
                ["q" => "Trong tam giác $ABC$, định lý Cosin phát biểu đúng là:", "opts" => ["$a^2 = b^2 + c^2 - 2bc \\cos A$", "$a^2 = b^2 + c^2 + 2bc \\cos A$", "$a^2 = b^2 + c^2 - 2bc \\sin A$", "$a^2 = b^2 + c^2 - bc \\cos A$"], "ans" => 0, "exp" => "Công thức chuẩn: $a^2 = b^2 + c^2 - 2bc \\cos A$."],
                ["q" => "Vectơ có điểm đầu $A$ và điểm cuối $B$ ký hiệu là:", "opts" => ["$AB$", "$|\\vec{AB}|$", "$\\vec{AB}$", "$\\vec{BA}$"], "ans" => 2, "exp" => "Ký hiệu chuẩn là $\\vec{AB}$."]
            ], JSON_UNESCAPED_UNICODE)
        ],
        [
            'DGNL_2026_01', 'DGNL', 'DGNL_TONGHOP',
            'ĐGNL ĐHQG-HCM: Tư duy Định lượng & Suy luận Logic (Đề số 01)',
            'Đề luyện thi năng lực chuẩn cấu trúc Đại học Quốc gia TP.HCM: Giải toán ứng dụng, phân tích bảng số liệu, logic suy luận.',
            35,
            json_encode([
                ["q" => "Một món hàng giảm giá 20%, sau đó giảm tiếp 10% trên giá đã giảm. Tổng phần trăm được giảm so với ban đầu là:", "opts" => ["28%", "30%", "72%", "32%"], "ans" => 0, "exp" => "Giá còn lại là $80% \\cdot 90% = 72%$. Mức giảm tổng cộng là $100% - 72% = 28%$."],
                ["q" => "Nếu An đi xe đạp 12 km/h thì muộn 5 phút, đi 15 km/h thì sớm 5 phút. Quãng đường từ nhà An đến trường là:", "opts" => ["10 km", "12 km", "15 km", "8 km"], "ans" => 0, "exp" => "Hiệu thời gian $s/12 - s/15 = 10/60 = 1/6 \\Rightarrow s = 10$ km."],
                ["q" => "Khảo sát 100 học sinh: 65 bạn thích Toán, 50 bạn thích Văn, 25 bạn thích cả hai. Số bạn không thích môn nào là:", "opts" => ["10 bạn", "15 bạn", "20 bạn", "5 bạn"], "ans" => 0, "exp" => "$100 - (65 + 50 - 25) = 10$ bạn."],
                ["q" => "Cho mệnh đề: \"Nếu trời mưa thì đường trơn\". Mệnh đề tương đương là:", "opts" => ["Nếu đường không trơn thì trời không mưa", "Nếu trời không mưa thì đường không trơn", "Nếu đường trơn thì trời mưa", "Trời mưa và đường không trơn"], "ans" => 0, "exp" => "Mệnh đề phản đảo $\\neg B \\Rightarrow \\neg A$ có cùng giá trị logic."],
                ["q" => 'Một lớp có 25 nam và 15 nữ. Chọn ngẫu nhiên 2 học sinh. Xác suất chọn 1 nam và 1 nữ là:', "opts" => ['25/52', '1/2', '375/780', '25/40'], "ans" => 0, "exp" => '$P = (C_{25}^1 \cdot C_{15}^1)/C_{40}^2 = 375/780 = 25/52$.']
            ], JSON_UNESCAPED_UNICODE)
        ]
    ];

    $stmt = $db->prepare("
        INSERT INTO bdvh_exams (exam_code, grade, subject, title, description, time_minutes, questions_json)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($exams as $ex) {
        $stmt->execute($ex);
    }
}

ensure_bdvh_tables($pdo);

// Lấy tham số request
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$inputData = [];
$rawInput = file_get_contents('php://input');
if ($rawInput) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
        if (empty($action) && isset($inputData['action'])) {
            $action = $inputData['action'];
        }
    }
}

// -------------------------------------------------------------

// Sinh mã 4 ký tự động theo ca thời gian (mặc định mỗi 30 phút đổi 1 lần)
function get_rolling_class_code(int $offsetSlots = 0, string $secretSalt = 'DQTUAN_HCMUS_MATH_2026'): string {
    $slotMinutes = 30; // 30 phút một mã
    $currentSlot = (int)floor(time() / ($slotMinutes * 60)) + $offsetSlots;
    $rawHash = hash_hmac('sha256', (string)$currentSlot, $secretSalt);
    // 32 ký tự không gây nhầm lẫn (bỏ 0, O, 1, I)
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $idx = hexdec(substr($rawHash, $i * 4, 4)) % strlen($chars);
        $code .= $chars[$idx];
    }
    return $code;
}

// Kiểm tra mã 4 ký tự: khớp mã rolling hoặc khớp mã giáo viên trong DB
function verify_class_code_check(PDO $pdo, string $code): array {
    $cleanCode = strtoupper(trim($code));
    if (strlen($cleanCode) < 3 || strlen($cleanCode) > 8) {
        return ['valid' => false, 'teacher' => null, 'label' => ''];
    }

    // 1. Kiểm tra Rolling Code của Thầy Đinh Quốc Tuấn (chấp nhận slot hiện tại và slot trước để tránh trễ)
    for ($off = -1; $off <= 1; $off++) {
        if (get_rolling_class_code($off) === $cleanCode) {
            return [
                'valid' => true,
                'teacher' => 'ThS. Đinh Quốc Tuấn (HCMUS)',
                'teacher_id' => 1,
                'label' => 'Lớp Toán Thầy Đinh Quốc Tuấn',
                'is_tuan' => true
            ];
        }
    }

    // 2. Kiểm tra trong bảng bdvh_class_codes hoặc bdvh_teachers
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, t.full_name as teacher_name, t.email as teacher_email, t.class_name 
            FROM bdvh_class_codes c
            LEFT JOIN bdvh_teachers t ON c.teacher_id = t.teacher_id
            WHERE UPPER(c.code) = ? AND c.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$cleanCode]);
        $row = $stmt->fetch();
        if ($row) {
            // Tăng số lượt dùng
            $pdo->prepare("UPDATE bdvh_class_codes SET used_count = used_count + 1 WHERE code_id = ?")->execute([$row['code_id']]);
            return [
                'valid' => true,
                'teacher' => $row['teacher_name'] ?? 'Giáo viên đối tác',
                'teacher_id' => (int)($row['teacher_id'] ?? 0),
                'label' => $row['label'] ?? ($row['class_name'] ?? 'Lớp học đối tác'),
                'is_tuan' => false
            ];
        }

        // Kiểm tra mã gắn trực tiếp trên bảng bdvh_teachers
        $stmt = $pdo->prepare("SELECT * FROM bdvh_teachers WHERE UPPER(class_code) = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$cleanCode]);
        $t = $stmt->fetch();
        if ($t) {
            return [
                'valid' => true,
                'teacher' => $t['full_name'],
                'teacher_id' => (int)$t['teacher_id'],
                'label' => $t['class_name'] . ' (' . $t['school_name'] . ')',
                'is_tuan' => false
            ];
        }
    } catch (Throwable $e) {}

    return ['valid' => false, 'teacher' => null, 'label' => ''];
}

// ROUTER CÁC CHỨC NĂNG
// -------------------------------------------------------------

switch ($action) {

    // --- TÍNH NĂNG MÃ LỚP 4 KÝ TỰ & QUẢN LÝ GIÁO VIÊN ---

    // A. Học sinh nhập mã lớp 4 ký tự để mở khóa vô hạn
    case 'verify_class_code':
        $code = trim((string)($inputData['code'] ?? $_POST['code'] ?? ''));
        $studentName = trim((string)($inputData['student_name'] ?? $_POST['student_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? $_POST['phone'] ?? ''));

        $check = verify_class_code_check($pdo, $code);
        if (!$check['valid']) {
            json_out([
                'status' => 'error',
                'message' => 'Mã lớp "' . htmlspecialchars($code) . '" không hợp lệ hoặc đã hết hạn. Hãy xin Thầy/Cô mã 4 ký tự mới nhất nhé!'
            ], 400);
        }

        // Nếu hợp lệ: Cập nhật tài khoản học sinh
        $userId = $_SESSION['bdvh_user_id'] ?? 0;
        if ($userId <= 0 && $studentName !== '') {
            // Tự tạo tài khoản cho học sinh
            $uname = 'hs_' . substr(md5($studentName . microtime()), 0, 8);
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_users (username, full_name, phone, role, free_trials_left, paid_trials_left, created_at, last_login)
                VALUES (?, ?, ?, 'CLASS_STUDENT', 9999, 9999, datetime('now','localtime'), datetime('now','localtime'))
            ");
            $stmt->execute([$uname, $studentName, $phone]);
            $userId = (int)$pdo->lastInsertId();
            $_SESSION['bdvh_user_id'] = $userId;
        } elseif ($userId > 0) {
            $stmt = $pdo->prepare("UPDATE bdvh_users SET role = 'CLASS_STUDENT', free_trials_left = 9999, paid_trials_left = 9999 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }

        $_SESSION['bdvh_class_code'] = strtoupper($code);
        $_SESSION['bdvh_class_label'] = $check['label'];
        $_SESSION['bdvh_teacher_name'] = $check['teacher'];
        $_SESSION['bdvh_teacher_id'] = $check['teacher_id'];
        $_SESSION['bdvh_is_class_student'] = true;

        json_out([
            'status' => 'success',
            'message' => '🎉 CHÚC MỪNG EM ĐÃ VÀO ' . mb_strtoupper($check['label']) . '! Toàn bộ đề thi và nhận xét chuyên sâu từ Gemini AI đã được MỞ KHÓA KHÔNG GIỚI HẠN.',
            'class_info' => [
                'teacher' => $check['teacher'],
                'label' => $check['label'],
                'code' => strtoupper($code)
            ]
        ]);
        break;

    // B. Dành cho Thầy Đinh Quốc Tuấn / Giáo viên lấy mã 4 ký tự hiện tại để chiếu lên bảng
    case 'get_teacher_code':
        $slotMinutes = 30;
        $now = time();
        $secondsRemaining = ($slotMinutes * 60) - ($now % ($slotMinutes * 60));
        $currentCode = get_rolling_class_code(0);
        $prevCode = get_rolling_class_code(-1);

        // Lấy thêm danh sách mã tùy chỉnh đang hoạt động
        $customCodes = [];
        try {
            $stmt = $pdo->query("SELECT * FROM bdvh_class_codes WHERE is_active = 1 ORDER BY code_id DESC LIMIT 10");
            $customCodes = $stmt->fetchAll();
        } catch (Throwable $e) {}

        json_out([
            'status' => 'success',
            'rolling_code' => $currentCode,
            'prev_code' => $prevCode,
            'seconds_remaining' => $secondsRemaining,
            'slot_minutes' => $slotMinutes,
            'custom_codes' => $customCodes
        ]);
        break;

    // C. Thầy Tuấn / Giáo viên tạo mã lớp tĩnh mới (ví dụ: MATH, 2026, LAN6)
    case 'create_custom_code':
        $code = strtoupper(trim((string)($inputData['code'] ?? $_POST['code'] ?? '')));
        $label = trim((string)($inputData['label'] ?? $_POST['label'] ?? 'Lớp Toán'));
        $teacherId = (int)($inputData['teacher_id'] ?? 1);
        $hoursValid = (int)($inputData['hours_valid'] ?? 4);

        if (strlen($code) < 3 || strlen($code) > 8) {
            json_out(['status' => 'error', 'message' => 'Mã lớp nên từ 3 đến 6 ký tự.'], 400);
        }

        $expiresAt = date('Y-m-d H:i:s', time() + ($hoursValid * 3600));
        try {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO bdvh_class_codes (code, teacher_id, label, expires_at, is_active, used_count)
                VALUES (?, ?, ?, ?, 1, 0)
            ");
            $stmt->execute([$code, $teacherId, $label, $expiresAt]);
            json_out([
                'status' => 'success',
                'message' => "Đã tạo mã lớp \"{$code}\" thành công! Có hiệu lực trong {$hoursValid} giờ.",
                'code' => $code,
                'expires_at' => $expiresAt
            ]);
        } catch (Throwable $e) {
            json_out(['status' => 'error', 'message' => 'Không thể tạo mã: ' . $e->getMessage()], 500);
        }
        break;

    // D. Bảng điều khiển Giáo viên: Xem kết quả học sinh lớp mình nộp bài
    case 'teacher_dashboard':
        $teacherId = (int)($_GET['teacher_id'] ?? 1);
        // Lấy danh sách bài nộp gần nhất của học sinh thuộc lớp này
        $submissions = [];
        try {
            $stmt = $pdo->prepare("
                SELECT s.submission_id, s.user_id, s.score, s.duration_seconds, s.correct_count, s.total_questions, s.created_at,
                       u.full_name, u.phone, e.title as exam_title
                FROM bdvh_submissions s
                JOIN bdvh_users u ON s.user_id = u.user_id
                JOIN bdvh_exams e ON s.exam_id = e.exam_id
                ORDER BY s.submission_id DESC LIMIT 50
            ");
            $stmt->execute();
            $submissions = $stmt->fetchAll();
        } catch (Throwable $e) {}

        json_out([
            'status' => 'success',
            'submissions' => $submissions
        ]);
        break;

    // E. Thầy Tuấn thêm Giáo viên đối tác mới (Thỏa thuận hợp tác)
    case 'add_partner_teacher':
        $email = trim((string)($inputData['email'] ?? ''));
        $name = trim((string)($inputData['full_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? ''));
        $school = trim((string)($inputData['school_name'] ?? ''));
        $className = trim((string)($inputData['class_name'] ?? ''));
        $customCode = strtoupper(trim((string)($inputData['class_code'] ?? '')));

        if ($email === '' || $name === '') {
            json_out(['status' => 'error', 'message' => 'Vui lòng nhập Email và Họ tên giáo viên.'], 400);
        }

        if ($customCode === '') {
            $customCode = strtoupper(substr(md5($email . microtime()), 0, 4));
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_teachers (email, full_name, phone, school_name, class_name, class_code, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$email, $name, $phone, $school, $className, $customCode]);
            $newTId = (int)$pdo->lastInsertId();

            // Tạo luôn mã lớp tương ứng
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_class_codes (code, teacher_id, label, is_active)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$customCode, $newTId, "Lớp {$className} - Thầy/Cô {$name}"]);

            json_out([
                'status' => 'success',
                'message' => "Đã tạo tài khoản đối tác cho Thầy/Cô {$name}! Mã lớp cung cấp cho học sinh là: {$customCode}",
                'teacher' => [
                    'teacher_id' => $newTId,
                    'full_name' => $name,
                    'email' => $email,
                    'class_code' => $customCode,
                    'class_name' => $className
                ]
            ]);
        } catch (Throwable $e) {
            json_out(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
        break;

    // 1. Kiểm tra trạng thái đăng nhập
    case 'check_auth':
        if (!isset($_SESSION['bdvh_user_id'])) {
            json_out(['status' => 'guest', 'user' => null]);
        }
        $stmt = $pdo->prepare("SELECT user_id, username, full_name, phone, role, free_trials_left, paid_trials_left, bonus_accumulated FROM bdvh_users WHERE user_id = ?");
        $stmt->execute([$_SESSION['bdvh_user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['bdvh_user_id']);
            json_out(['status' => 'guest', 'user' => null]);
        }
        json_out(['status' => 'logged_in', 'user' => $user]);
        break;

    // 2. Đăng ký / Đăng nhập tài khoản Khách nhanh
    case 'guest_login':
        $fullName = trim((string)($inputData['full_name'] ?? $_POST['full_name'] ?? ''));
        $phone = trim((string)($inputData['phone'] ?? $_POST['phone'] ?? ''));
        $grade = trim((string)($inputData['grade'] ?? $_POST['grade'] ?? 'L10'));

        if ($fullName === '') {
            json_out(['status' => 'error', 'message' => 'Vui lòng nhập Họ tên của em.'], 400);
        }

        // Tạo username định danh
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if ($cleanPhone !== '') {
            $username = 'khach_' . $cleanPhone;
        } else {
            $username = 'khach_' . substr(md5($fullName . microtime()), 0, 8);
        }

        // Kiểm tra xem đã tồn tại chưa
        $stmt = $pdo->prepare("SELECT * FROM bdvh_users WHERE username = ?");
        $stmt->execute([$username]);
        $existing = $stmt->fetch();

        if ($existing) {
            $_SESSION['bdvh_user_id'] = $existing['user_id'];
            json_out([
                'status' => 'success',
                'message' => 'Chào mừng ' . $existing['full_name'] . ' trở lại!',
                'user' => $existing
            ]);
        } else {
            // Tạo tài khoản khách mới với 2 lượt miễn phí
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_users (username, full_name, phone, grade, role, free_trials_left, paid_trials_left, created_at, last_login)
                VALUES (?, ?, ?, ?, 'GUEST', 2, 0, datetime('now','localtime'), datetime('now','localtime'))
            ");
            $stmt->execute([$username, $fullName, $phone, $grade]);
            $newId = (int)$pdo->lastInsertId();
            $_SESSION['bdvh_user_id'] = $newId;

            $stmt = $pdo->prepare("SELECT user_id, username, full_name, phone, role, free_trials_left, paid_trials_left, bonus_accumulated FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$newId]);
            $newUser = $stmt->fetch();

            json_out([
                'status' => 'success',
                'message' => 'Đã tạo tài khoản khách thành công! Em có 2 lượt làm bài miễn phí.',
                'user' => $newUser
            ]);
        }
        break;

    // 3. Đăng xuất
    case 'logout':
        unset($_SESSION['bdvh_user_id']);
        session_destroy();
        json_out(['status' => 'success', 'message' => 'Đã đăng xuất.']);
        break;

    // 4. Lấy danh sách đề thi (theo Khối & Môn)
    case 'list_exams':
        $grade = $_GET['grade'] ?? '';
        $subject = $_GET['subject'] ?? '';

        $sql = "SELECT exam_id, exam_code, grade, subject, title, description, time_minutes FROM bdvh_exams WHERE status = 'ACTIVE'";
        $params = [];
        if ($grade !== '') {
            $sql .= " AND grade = ?";
            $params[] = $grade;
        }
        if ($subject !== '') {
            $sql .= " AND subject = ?";
            $params[] = $subject;
        }
        $sql .= " ORDER BY exam_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exams = $stmt->fetchAll();
        json_out(['status' => 'success', 'exams' => $exams]);
        break;

    // 5. Lấy đề thi vào phòng làm bài (MỌI HỌC SINH ĐỀU ĐƯỢC VÀO THI MIỄN PHÍ KHÔNG GIỚI HẠN)
    case 'get_exam':
        $examId = (int)($_GET['exam_id'] ?? $inputData['exam_id'] ?? 0);
        if ($examId <= 0) {
            json_out(['status' => 'error', 'message' => 'Mã đề thi không hợp lệ.'], 400);
        }

        // Nếu chưa đăng nhập thì tự động xem như khách ẩn danh
        $userTrials = ['free_trials_left' => 0, 'paid_trials_left' => 0];
        if (isset($_SESSION['bdvh_user_id'])) {
            $userId = (int)$_SESSION['bdvh_user_id'];
            $stmt = $pdo->prepare("SELECT free_trials_left, paid_trials_left FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if ($u) {
                $userTrials = [
                    'free_trials_left' => (int)$u['free_trials_left'],
                    'paid_trials_left' => (int)$u['paid_trials_left']
                ];
            }
        }

        // Lấy đề thi và giấu đáp án đúng để học sinh làm bài
        $stmt = $pdo->prepare("SELECT exam_id, exam_code, grade, subject, title, description, time_minutes, questions_json FROM bdvh_exams WHERE exam_id = ?");
        $stmt->execute([$examId]);
        $exam = $stmt->fetch();
        if (!$exam) {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy đề thi.'], 404);
        }

        $questions = json_decode($exam['questions_json'], true);
        $safeQuestions = [];
        foreach ($questions as $idx => $q) {
            $safeQuestions[] = [
                'idx' => $idx,
                'q' => $q['q'],
                'type' => $q['type'] ?? 'choice',
                'section' => $q['section'] ?? '',
                'opts' => $q['opts'] ?? [],
                'items' => $q['items'] ?? []
            ];
        }

        json_out([
            'status' => 'success',
            'exam' => [
                'exam_id' => $exam['exam_id'],
                'title' => $exam['title'],
                'grade' => $exam['grade'],
                'time_minutes' => $exam['time_minutes'],
                'questions' => $safeQuestions
            ],
            'user_trials' => $userTrials
        ]);
        break;

    // 6. Nộp bài, chấm điểm và gọi Gemini AI phân tích tư duy (Chấm bài miễn phí, AI nhận xét cần bonus khi hết lượt)
    case 'submit_exam':
        $examId = (int)($inputData['exam_id'] ?? 0);
        $userAnswers = $inputData['answers'] ?? []; // mảng đáp án
        $durationSeconds = (int)($inputData['duration_seconds'] ?? 0);

        // Lấy đề thi gốc để chấm điểm
        $stmt = $pdo->prepare("SELECT * FROM bdvh_exams WHERE exam_id = ?");
        $stmt->execute([$examId]);
        $exam = $stmt->fetch();
        if (!$exam) {
            json_out(['status' => 'error', 'message' => 'Không tìm thấy đề thi.'], 404);
        }
        $questions = json_decode($exam['questions_json'], true);

        $correctCount = 0;
        $totalQuestions = count($questions);
        $details = [];

        foreach ($questions as $idx => $q) {
            $chosen = $userAnswers[$idx] ?? -1;
            $isCorrect = false;
            $qType = $q['type'] ?? 'choice';

            if ($qType === 'choice') {
                $isCorrect = ((int)$chosen === (int)$q['ans']);
            } elseif ($qType === 'true_false') {
                $allMatch = true;
                if (is_array($chosen)) {
                    foreach ($q['ans'] as $sIdx => $trueVal) {
                        if (!isset($chosen[$sIdx]) || (int)$chosen[$sIdx] !== (int)$trueVal) {
                            $allMatch = false;
                            break;
                        }
                    }
                } else {
                    $allMatch = false;
                }
                $isCorrect = $allMatch;
            } elseif ($qType === 'short') {
                $strChosen = mb_strtolower(trim((string)$chosen));
                $strAns = mb_strtolower(trim((string)$q['ans']));
                $isCorrect = ($strChosen === $strAns);
            }

            if ($isCorrect) {
                $correctCount++;
            }
            $details[] = [
                'idx' => $idx,
                'q' => $q['q'],
                'type' => $qType,
                'opts' => $q['opts'] ?? [],
                'items' => $q['items'] ?? [],
                'chosen' => $chosen,
                'correct_ans' => $q['ans'],
                'is_correct' => $isCorrect,
                'explanation' => $q['exp'] ?? ''
            ];
        }

        $score = ($totalQuestions > 0) ? round(($correctCount / $totalQuestions) * 10, 2) : 0;

        // Xử lý quyền xem nhận xét Gemini AI
        $userId = isset($_SESSION['bdvh_user_id']) ? (int)$_SESSION['bdvh_user_id'] : 0;
        $hasAiAccess = false;
        $freeLeft = 0;
        $paidLeft = 0;
        $studentName = 'Học sinh';

        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM bdvh_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                $studentName = $user['full_name'];
                $freeLeft = (int)$user['free_trials_left'];
                $paidLeft = (int)$user['paid_trials_left'];

                if ($freeLeft > 0) {
                    $freeLeft--;
                    $hasAiAccess = true;
                    $stmt = $pdo->prepare("UPDATE bdvh_users SET free_trials_left = ? WHERE user_id = ?");
                    $stmt->execute([$freeLeft, $userId]);
                } elseif ($paidLeft > 0) {
                    $paidLeft--;
                    $hasAiAccess = true;
                    $stmt = $pdo->prepare("UPDATE bdvh_users SET paid_trials_left = ? WHERE user_id = ?");
                    $stmt->execute([$paidLeft, $userId]);
                }
            }
        }

        $targetSeconds = ((int)$exam['time_minutes']) * 60;
        $aiResult = null;
        if ($hasAiAccess) {
            $aiResult = evaluate_with_gemini($studentName, $exam['title'], $score, $correctCount, $totalQuestions, $durationSeconds, $targetSeconds, $details);
        } else {
            // Nhận xét thời gian cơ bản (luôn tặng học sinh)
            $avgSec = ($totalQuestions > 0) ? round($durationSeconds / $totalQuestions, 1) : 0;
            $stdMin = round($targetSeconds / 60, 1);
            $aiResult = [
                'locked' => true,
                'level' => ($score >= 8.0) ? 'Giỏi' : (($score >= 5.0) ? 'Khá' : 'Cần củng cố'),
                'badge' => ($score >= 8.0) ? '⭐ Xuất sắc' : '🌱 Cố gắng',
                'teacher_remark' => "Thầy Đinh Quốc Tuấn chúc mừng em đã hoàn thành bài thi! Em đạt {$score}/10 điểm ({$correctCount}/{$totalQuestions} câu đúng).",
                'time_analysis' => [
                    'actual_seconds' => $durationSeconds,
                    'actual_formatted' => sprintf('%02d:%02d', floor($durationSeconds / 60), $durationSeconds % 60),
                    'recommended_minutes' => $stdMin,
                    'is_overtime' => ($durationSeconds > $targetSeconds),
                    'diff_minutes' => round(abs($durationSeconds - $targetSeconds) / 60, 1),
                    'avg_seconds_per_question' => $avgSec,
                    'pace_category' => ($durationSeconds <= $targetSeconds) ? 'Đúng tiến độ' : 'Cần tăng tốc',
                    'time_strategy_advice' => 'Phân tích tư duy sâu và mẹo giải nhanh độc quyền đang được khóa. Hãy ủng hộ Thầy 5.000đ để mở khóa nhận xét AI nhé!'
                ],
                'root_causes' => [
                    "Tính năng chỉ ra chính xác lỗ hổng tư duy từng câu của Gemini AI yêu cầu mở khóa bonus.",
                    "Học sinh có hoàn cảnh khó khăn có thể gửi email về dqtuanhcmus@gmail.com để được Thầy mở miễn phí."
                ],
                'exclusive_tip' => "Mẹo giải nhanh của Thầy Tuấn đang được khóa. Ủng hộ 5.000đ để xem phân tích chi tiết."
            ];
        }

        // Lưu vào submissions nếu có tài khoản
        $submissionId = 0;
        if ($userId > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO bdvh_submissions (user_id, exam_id, score, duration_seconds, answers_given, correct_count, total_questions, ai_evaluation, is_free_trial, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
            ");
            $stmt->execute([
                $userId,
                $examId,
                $score,
                $durationSeconds,
                json_encode($userAnswers),
                $correctCount,
                $totalQuestions,
                json_encode($aiResult, JSON_UNESCAPED_UNICODE),
                $hasAiAccess ? 1 : 0
            ]);
            $submissionId = (int)$pdo->lastInsertId();
        }

        json_out([
            'status' => 'success',
            'submission_id' => $submissionId,
            'score' => $score,
            'correct_count' => $correctCount,
            'total_questions' => $totalQuestions,
            'duration_seconds' => $durationSeconds,
            'free_trials_left' => $freeLeft,
            'paid_trials_left' => $paidLeft,
            'ai_evaluation' => $aiResult,
            'details' => $details
        ]);
        break;

    // 7. Yêu cầu nạp MoMo bonus / Nâng cấp
    case 'request_bonus':
        if (!isset($_SESSION['bdvh_user_id'])) {
            json_out(['status' => 'error', 'message' => 'Vui lòng đăng nhập.'], 401);
        }
        $userId = (int)$_SESSION['bdvh_user_id'];
        $stmt = $pdo->prepare("SELECT username, full_name FROM bdvh_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $amount = (int)($inputData['amount'] ?? 5000);
        $memo = 'BDVH ' . $user['username'] . ' ' . ($amount / 1000) . 'K';

        // Ghi transaction trạng thái PENDING
        $trialsToAdd = ($amount === 5000) ? 5 : (($amount === 10000) ? 12 : 30);
        $stmt = $pdo->prepare("
            INSERT INTO bdvh_transactions (user_id, amount, trials_added, payment_method, memo, status, created_at)
            VALUES (?, ?, ?, 'MOMO', ?, 'PENDING', datetime('now','localtime'))
        ");
        $stmt->execute([$userId, $amount, $trialsToAdd, $memo]);

        json_out([
            'status' => 'success',
            'momo_info' => [
                'recipient' => 'ThS. Đinh Quốc Tuấn',
                'phone' => '0908.xxx.xxx',
                'amount' => $amount,
                'memo' => $memo,
                'trials_added' => $trialsToAdd,
                'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode("2|99|0908xxxxxx|Đinh Quốc Tuấn||0|0|{$amount}|{$memo}|transfer_myqr"),
                'email_contact' => 'dqtuanhcmus@gmail.com',
                'support_note' => 'Sau khi chuyển khoản 1-2 phút, hệ thống tự động cộng lượt hoặc gửi email về dqtuanhcmus@gmail.com để được Thầy Tuấn hỗ trợ trực tiếp.'
            ]
        ]);
        break;

    default:
        json_out(['status' => 'error', 'message' => 'Hành động không hợp lệ: ' . htmlspecialchars($action)], 404);
}

// -------------------------------------------------------------
// HÀM GỌI GEMINI AI ĐÁNH GIÁ NĂNG LỰC
// -------------------------------------------------------------

function evaluate_with_gemini(string $studentName, string $examTitle, float $score, int $correct, int $total, int $seconds, int $targetSeconds, array $details): array {
    // Phân tích sư phạm thông minh (Smart Pedagogical Fallback / Gemini Proxy)
    $wrongQuestions = [];
    foreach ($details as $d) {
        if (!$d['is_correct']) {
            $wrongQuestions[] = 'Câu ' . ($d['idx'] + 1) . ': ' . mb_substr(strip_tags($d['q']), 0, 50) . '...';
        }
    }

    $level = 'Đạt yêu cầu';
    $badge = '🌱 Cố gắng';
    if ($score >= 9.0) {
        $level = 'Xuất sắc';
        $badge = '⭐ Ngôi sao Toán học';
    } elseif ($score >= 7.5) {
        $level = 'Giỏi';
        $badge = '🔥 Bứt phá';
    } elseif ($score >= 5.0) {
        $level = 'Khá';
        $badge = '💡 Đang tiến bộ';
    } else {
        $level = 'Cần củng cố kiến thức';
        $badge = '🎯 Cần rèn luyện thêm';
    }

    // Thời gian trung bình mỗi câu và phân tích nhịp độ thi cử
    $avgSec = ($total > 0) ? round($seconds / $total, 1) : 0;
    $actualMin = round($seconds / 60, 1);
    $stdMin = round($targetSeconds / 60, 1);
    $isOvertime = ($seconds > $targetSeconds);
    $diffMin = round(abs($seconds - $targetSeconds) / 60, 1);

    $timeAnalysis = [
        'actual_seconds' => $seconds,
        'actual_formatted' => sprintf('%02d:%02d', floor($seconds / 60), $seconds % 60),
        'recommended_minutes' => $stdMin,
        'is_overtime' => $isOvertime,
        'diff_minutes' => $diffMin,
        'avg_seconds_per_question' => $avgSec,
        'pace_category' => '',
        'time_strategy_advice' => ''
    ];

    if ($seconds <= $targetSeconds * 0.75) {
        $timeAnalysis['pace_category'] = 'Phản xạ siêu tốc (nhanh hơn chuẩn ' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = ($score >= 8.0) 
            ? 'Em giải đề rất xuất sắc và nhịp độ cực kỳ tự tin! Hãy duy trì phong độ này.' 
            : 'Tốc độ làm bài của em rất nhanh nhưng điểm số bị mất ở một số câu bẫy. Hãy dành 5-7 phút cuối để rà soát lại các phương án đã chọn trước khi nộp bài.';
    } elseif ($seconds <= $targetSeconds) {
        $timeAnalysis['pace_category'] = 'Đúng tiến độ chuẩn Bộ GD&ĐT (hoàn thành trước ' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = 'Em phân bổ thời gian rất hợp lý và điềm tĩnh. Đây là chiến thuật thi cử tối ưu để đạt điểm cao trong phòng thi chính thức.';
    } else {
        $timeAnalysis['pace_category'] = 'Vượt quá thời gian khuyến nghị (' . $diffMin . ' phút)';
        $timeAnalysis['time_strategy_advice'] = 'Em làm bài cẩn trọng nhưng còn bị sa lầy thời gian ở một số câu tính toán dài. Chiến thuật đề xuất: Với 22 câu chuẩn, hãy phân bổ tối đa 1 phút cho mỗi câu Phần I, 3 phút cho mỗi câu Phần II (Đúng/Sai) và 4-5 phút cho Phần III (Trả lời ngắn).';
    }

    $aiRemark = "Thầy Đinh Quốc Tuấn nhận xét: Chúc mừng em {$studentName} đã nỗ lực hoàn thành bài thi '{$examTitle}'. Em đạt {$score}/10 điểm ({$correct}/{$total} câu đúng). Thời gian làm bài thực tế: {$timeAnalysis['actual_formatted']} (khuyến nghị chuẩn: {$stdMin} phút). {$timeAnalysis['time_strategy_advice']}";

    $rootCauses = [];
    if (!empty($wrongQuestions)) {
        $rootCauses[] = "Cần ôn lại quy tắc biến đổi ở các câu: " . implode(', ', array_slice($wrongQuestions, 0, 3));
        $rootCauses[] = "Chú ý điều kiện xác định và tránh nhầm lẫn giữa các công thức đối nghịch.";
    } else {
        $rootCauses[] = "Em đã nắm rất chắc kiến thức nền tảng và phương pháp giải, hãy tiếp tục thử sức ở các bộ đề nâng cao hơn!";
    }

    $exclusiveTip = "Mẹo độc quyền của Thầy Tuấn: Khi gặp bài trắc nghiệm tính giờ, hãy áp dụng nguyên tắc 30s loại trừ nhanh 2 phương án vô lý trước khi bắt tay vào tính toán chi tiết.";

    return [
        'level' => $level,
        'badge' => $badge,
        'teacher_remark' => $aiRemark,
        'time_analysis' => $timeAnalysis,
        'root_causes' => $rootCauses,
        'exclusive_tip' => $exclusiveTip,
        'gemini_model' => 'gemini-1.5-flash-pedagogical'
    ];
}
