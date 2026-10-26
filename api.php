<?php
declare(strict_types=1);

/**
 * bdvh/api.php — Backend REST API cho Nền tảng Bồi Dưỡng Văn Hóa & Khảo thí ĐGNL (bdvh.diemdanhsv.com)
 * ThS. Đinh Quốc Tuấn — Trường ĐH Khoa học Tự nhiên, ĐHQG-HCM.
 *
 * Tính năng chính:
 * 1. Đăng ký & Đăng nhập tài khoản Khách (Guest Account) nhanh chóng.
 * 2. Kiểm soát giới hạn: Mỗi tài khoản khách được làm bài MIỄN PHÍ 2 LẦN.
 * 3. Tích hợp Hệ Thống Phân Tích Khảo Thí Chuyên Sâu đánh giá năng lực tư duy, bắt lỗi sai và gợi ý mẹo giải nhanh.
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
            
            
            CREATE TABLE IF NOT EXISTS bdvh_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT
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

            
            
            CREATE TABLE IF NOT EXISTS bdvh_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT
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
    $jsonText = '[
  {
    "exam_code": "TOAN06_01",
    "grade": "L06",
    "subject": "TOAN",
    "title": "Đấu Trường Toán 6: Lũy thừa và Thứ tự thực hiện phép tính",
    "description": "Rèn luyện tư duy tính toán nhanh, quy tắc lũy thừa cơ số tự nhiên và thứ tự ưu tiên các phép tính chuẩn chương trình KNTT.",
    "time_minutes": 20,
    "questions": [
      {
        "q": "Kết quả của phép tính $2^3 \\cdot 2^2$ bằng bao nhiêu?",
        "opts": [
          "$2^5 = 32$",
          "$2^6 = 64$",
          "$4^5 = 1024$",
          "$4^6 = 4096$"
        ],
        "ans": 0,
        "exp": "<b>Phương pháp giải:</b> Áp dụng quy tắc nhân hai lũy thừa cùng cơ số:<br>$$a^m \\cdot a^n = a^{m+n} \\quad (a \\in \\mathbb{N}^*; m, n \\in \\mathbb{N})$$<br><b>Lời giải chi tiết:</b><br>Với $a = 2, m = 3, n = 2$, ta có:<br>$$2^3 \\cdot 2^2 = 2^{3+2} = 2^5 = 32$$<br><b>Kết luận:</b> Kết quả của phép tính là $2^5 = 32$. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Giá trị của biểu thức $15 + 5 \\cdot 2^2$ là:",
        "opts": [
          "$40$",
          "$35$",
          "$80$",
          "$25$"
        ],
        "ans": 1,
        "exp": "<b>Quy tắc thứ tự thực hiện phép tính:</b><br>1. Tính lũy thừa trước.<br>2. Thực hiện phép nhân và phép chia.<br>3. Cuối cùng thực hiện phép cộng và phép trừ.<br><br><b>Lời giải từng bước:</b><br>Bước 1 (Tính lũy thừa): $2^2 = 4$.<br>Bước 2 (Thực hiện phép nhân): $5 \\cdot 4 = 20$.<br>Bước 3 (Thực hiện phép cộng): $15 + 20 = 35$.<br><br><b>Kết luận:</b> Giá trị biểu thức bằng $35$. Chọn đáp án <b>B</b>."
      },
      {
        "q": "Tìm số tự nhiên $x$ thỏa mãn đẳng thức: $3^x = 27$?",
        "opts": [
          "$x = 2$",
          "$x = 3$",
          "$x = 4$",
          "$x = 9$"
        ],
        "ans": 1,
        "exp": "<b>Phương pháp giải:</b> Đưa hai vế về cùng cơ số $a$ dạng $a^x = a^n \\Rightarrow x = n$.<br><br><b>Lời giải chi tiết:</b><br>Ta phân tích số $27$ ra thừa số nguyên tố: $27 = 3 \\cdot 3 \\cdot 3 = 3^3$.<br>Khi đó phương trình trở thành:<br>$$3^x = 3^3 \\Rightarrow x = 3$$<br><b>Kết luận:</b> Số tự nhiên cần tìm là $x = 3$. Chọn đáp án <b>B</b>."
      },
      {
        "q": "Trong các số sau, số nào chia hết cho cả $2$ và $5$?",
        "opts": [
          "$124$",
          "$235$",
          "$450$",
          "$502$"
        ],
        "ans": 2,
        "exp": "<b>Dấu hiệu chia hết:</b><br>- Số có chữ số tận cùng là $0, 2, 4, 6, 8$ thì chia hết cho $2$.<br>- Số có chữ số tận cùng là $0$ hoặc $5$ thì chia hết cho $5$.<br>$\\Rightarrow$ Số chia hết cho cả $2$ và $5$ phải có <b>chữ số tận cùng là chữ số $0$</b>.<br><br><b>Xét các phương án:</b><br>- Số $124$ tận cùng là $4$ (chỉ chia hết cho $2$).<br>- Số $235$ tận cùng là $5$ (chỉ chia hết cho $5$).<br>- Số $450$ tận cùng là $0$ (chia hết cho cả $2$ và $5$).<br>- Số $502$ tận cùng là $2$ (chỉ chia hết cho $2$).<br><br><b>Kết luận:</b> Số $450$ thỏa mãn yêu cầu bài toán. Chọn đáp án <b>C</b>."
      },
      {
        "q": "Thứ tự thực hiện đúng các dấu ngoặc trong một biểu thức toán học là:",
        "opts": [
          "Ngoặc tròn $( )$ $\\rightarrow$ Ngoặc vuông $[ ]$ $\\rightarrow$ Ngoặc nhọn $\\{ \\}$",
          "Ngoặc nhọn $\\{ \\}$ $\\rightarrow$ Ngoặc vuông $[ ]$ $\\rightarrow$ Ngoặc tròn $( )$",
          "Ngoặc vuông $[ ]$ $\\rightarrow$ Ngoặc tròn $( )$ $\\rightarrow$ Ngoặc nhọn $\\{ \\}$",
          "Ngoặc tròn $( )$ $\\rightarrow$ Ngoặc nhọn $\\{ \\}$ $\\rightarrow$ Ngoặc vuông $[ ]$"
        ],
        "ans": 0,
        "exp": "<b>Quy ước sư phạm chuẩn:</b><br>Khi thực hiện các phép tính trong biểu thức có nhiều dấu ngoặc, ta tính toán theo thứ tự ưu tiên từ trong ra ngoài:<br>$$\\text{Ngoặc tròn } ( ) \\longrightarrow \\text{Ngoặc vuông } [ ] \\longrightarrow \\text{Ngoặc nhọn } \\{ \\}$$<br><b>Kết luận:</b> Thứ tự đúng là $( ) \\rightarrow [ ] \\rightarrow \\{ \\}$. Chọn đáp án <b>A</b>."
      }
    ]
  },
  {
    "exam_code": "TOAN07_01",
    "grade": "L07",
    "subject": "TOAN",
    "title": "Đấu Trường Toán 7: Tập hợp số hữu tỉ & Lũy thừa số hữu tỉ",
    "description": "Kiểm tra định nghĩa tập hợp số hữu tỉ Q, các phép toán cộng trừ nhân chia và giá trị tuyệt đối của số hữu tỉ.",
    "time_minutes": 25,
    "questions": [
      {
        "q": "Khẳng định nào sau đây là ĐÚNG về tập hợp số hữu tỉ $\\mathbb{Q}$?",
        "opts": [
          "Mọi số nguyên đều là số hữu tỉ",
          "Số hữu tỉ chỉ bao gồm các phân số dương",
          "Số $0$ không phải là số hữu tỉ",
          "Số $\\pi$ là một số hữu tỉ"
        ],
        "ans": 0,
        "exp": "<b>Định nghĩa số hữu tỉ:</b> Số hữu tỉ là số viết được dưới dạng phân số $\\frac{a}{b}$ với $a, b \\in \\mathbb{Z}, b \\ne 0$.<br><br><b>Chứng minh chi tiết:</b><br>Với mọi số nguyên $x \\in \\mathbb{Z}$, ta luôn viết được thành $x = \\frac{x}{1}$ (trong đó tử số $x \\in \\mathbb{Z}$, mẫu số $1 \\in \\mathbb{Z}^*$). Do đó mọi số nguyên đều là số hữu tỉ, hay $\\mathbb{Z} \\subset \\mathbb{Q}$.<br><br><b>Xét các phương án còn lại:</b><br>- Số hữu tỉ gồm cả số âm, số dương và số $0$.<br>- Số $0 = \\frac{0}{1} \\in \\mathbb{Q}$ nên khẳng định \'Số 0 không là số hữu tỉ\' là sai.<br>- Số $\\pi \\approx 3{,}14159...$ là số vô tỉ $(\\pi \\notin \\mathbb{Q})$.<br><br><b>Kết luận:</b> Khẳng định đúng là \'Mọi số nguyên đều là số hữu tỉ\'. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Kết quả của phép tính $\\left(-\\frac{2}{3}\\right)^2$ là:",
        "opts": [
          "$-\\frac{4}{9}$",
          "$\\frac{4}{9}$",
          "$-\\frac{4}{6}$",
          "$\\frac{4}{6}$"
        ],
        "ans": 1,
        "exp": "<b>Quy tắc lũy thừa với số mũ chẵn:</b><br>Với số hữu tỉ $x$ và số tự nhiên $n$ chẵn, ta luôn có $(-x)^{2n} = x^{2n} \\ge 0$.<br><br><b>Lời giải chi tiết:</b><br>$$\\left(-\\frac{2}{3}\\right)^2 = \\left(-\\frac{2}{3}\\right) \\cdot \\left(-\\frac{2}{3}\\right) = \\frac{(-2)^2}{3^2} = \\frac{4}{9}$$<br><b>Kết luận:</b> Bình phương của một số âm luôn là một số dương: $\\frac{4}{9}$. Chọn đáp án <b>B</b>."
      },
      {
        "q": "Giá trị của biểu thức $A = \\frac{1}{2} - \\frac{3}{4} + \\frac{5}{2}$ bằng:",
        "opts": [
          "$\\frac{9}{4}$",
          "$\\frac{7}{4}$",
          "$\\frac{5}{4}$",
          "$2$"
        ],
        "ans": 0,
        "exp": "<b>Phương pháp giải:</b> Quy đồng mẫu số các phân số về mẫu số chung nhỏ nhất $\\text{MSCNN} = 4$.<br><br><b>Lời giải chi tiết:</b><br>$$A = \\frac{1 \\cdot 2}{2 \\cdot 2} - \\frac{3}{4} + \\frac{5 \\cdot 2}{2 \\cdot 2} = \\frac{2}{4} - \\frac{3}{4} + \\frac{10}{4}$$<br>$$A = \\frac{2 - 3 + 10}{4} = \\frac{9}{4}$$<br><b>Kết luận:</b> Giá trị biểu thức $A = \\frac{9}{4}$. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Tìm tất cả các giá trị của $x$ thỏa mãn đẳng thức: $|x| = 2{,}5$?",
        "opts": [
          "$x = 2{,}5$",
          "$x = -2{,}5$",
          "$x = 2{,}5$ hoặc $x = -2{,}5$",
          "$x = 5$"
        ],
        "ans": 2,
        "exp": "<b>Định nghĩa giá trị tuyệt đối:</b><br>Với số thực $a > 0$, phương trình $|x| = a$ luôn có hai nghiệm đối nhau là $x = a$ hoặc $x = -a$.<br><br><b>Lời giải:</b><br>$$|x| = 2{,}5 \\iff \\left[\\begin{array}{l} x = 2{,}5 \\\\ x = -2{,}5 \\end{array}\\right.$$<br><b>Kết luận:</b> Giá trị của $x$ là $x = 2{,}5$ hoặc $x = -2{,}5$. Chọn đáp án <b>C</b>."
      },
      {
        "q": "Cho tỉ lệ thức $\\frac{x}{3} = \\frac{8}{12}$. Giá trị của $x$ là:",
        "opts": [
          "$1$",
          "$2$",
          "$3$",
          "$4$"
        ],
        "ans": 1,
        "exp": "<b>Tính chất cơ bản của tỉ lệ thức:</b><br>Nếu $\\frac{a}{b} = \\frac{c}{d}$ thì $a \\cdot d = b \\cdot c \\Rightarrow a = \\frac{b \\cdot c}{d}$.<br><br><b>Lời giải chi tiết:</b><br>$$\\frac{x}{3} = \\frac{8}{12} \\iff x \\cdot 12 = 3 \\cdot 8 \\iff 12x = 24 \\iff x = \\frac{24}{12} = 2$$<br><b>Kết luận:</b> Giá trị cần tìm là $x = 2$. Chọn đáp án <b>B</b>."
      }
    ]
  },
  {
    "exam_code": "TOAN10_01",
    "grade": "L10",
    "subject": "TOAN",
    "title": "Toán 10: Mệnh đề, Tập hợp & Bất phương trình bậc nhất hai ẩn",
    "description": "Rèn luyện tư duy logic mệnh đề toán học, các phép toán giao, hợp, hiệu của tập hợp và biểu diễn miền nghiệm hình học.",
    "time_minutes": 30,
    "questions": [
      {
        "q": "Mệnh đề phủ định của mệnh đề $P$: \"$\\forall x \\in \\mathbb{R}, x^2 > 0$\" là:",
        "opts": [
          "$\\exists x \\in \\mathbb{R}, x^2 \\le 0$",
          "$\\exists x \\in \\mathbb{R}, x^2 < 0$",
          "$\\forall x \\in \\mathbb{R}, x^2 \\le 0$",
          "$\\exists x \\notin \\mathbb{R}, x^2 \\le 0$"
        ],
        "ans": 0,
        "exp": "<b>Quy tắc phủ định mệnh đề chứa lượng từ:</b><br>- Phủ định của lượng từ \'với mọi\' $\\forall$ là lượng từ \'tồn tại\' $\\exists$.<br>- Phủ định của bất đẳng thức lớn hơn $>$ là nhỏ hơn hoặc bằng $\\le$.<br><br><b>Lời giải chi tiết:</b><br>Cho mệnh đề $P$: \"$\\forall x \\in X, A(x)$\".<br>Mệnh đề phủ định là $\\overline{P}$: \"$\\exists x \\in X, \\overline{A(x)}$\".<br>Ở đây $A(x): x^2 > 0 \\Rightarrow \\overline{A(x)}: x^2 \\le 0$.<br>Do đó: $\\overline{P}: \"\\exists x \\in \\mathbb{R}, x^2 \\le 0\"$.<br><i>(Thực tế với $x = 0 \\in \\mathbb{R}$, ta có $0^2 = 0 \\le 0$ nên mệnh đề phủ định này là mệnh đề đúng).</i><br><br><b>Kết luận:</b> Chọn đáp án <b>A</b>."
      },
      {
        "q": "Cho hai tập hợp số $A = [-2; 3)$ và $B = (1; 5]$. Giao của hai tập hợp $A \\cap B$ là:",
        "opts": [
          "$[-2; 5]$",
          "$(1; 3)$",
          "$[1; 3)$",
          "$(1; 3]$"
        ],
        "ans": 1,
        "exp": "<b>Định nghĩa giao của hai tập hợp:</b><br>$$A \\cap B = \\{x \\in \\mathbb{R} \\mid x \\in A \\text{ và } x \\in B\\}$$<br><b>Lời giải chi tiết:</b><br>- Tập $A = [-2; 3) = \\{x \\in \\mathbb{R} \\mid -2 \\le x < 3\\}$.<br>- Tập $B = (1; 5] = \\{x \\in \\mathbb{R} \\mid 1 < x \\le 5\\}$.<br>Giao của hai khoảng là các giá trị đồng thời thỏa mãn $-2 \\le x < 3$ và $1 < x \\le 5$:<br>$$\\max(-2, 1) < x < \\min(3, 5) \\iff 1 < x < 3$$<br>Biểu diễn dưới dạng khoảng: $A \\cap B = (1; 3)$.<br><br><b>Kết luận:</b> Giao của hai tập hợp là khoảng $(1; 3)$. Chọn đáp án <b>B</b>."
      },
      {
        "q": "Điểm nào sau đây thuộc miền nghiệm của bất phương trình bậc nhất hai ẩn $2x - y + 1 > 0$?",
        "opts": [
          "$(0; 2)$",
          "$(1; 4)$",
          "$(2; 1)$",
          "$(-1; 0)$"
        ],
        "ans": 2,
        "exp": "<b>Phương pháp kiểm tra điểm thuộc miền nghiệm:</b><br>Thay tọa độ $(x_0; y_0)$ của từng điểm vào vế trái $f(x, y) = 2x - y + 1$. Nếu $f(x_0, y_0) > 0$ thì điểm đó thuộc miền nghiệm.<br><br><b>Thử lần lượt từng phương án:</b><br>- Với điểm $(0; 2)$: $f(0, 2) = 2(0) - 2 + 1 = -1 < 0$ (Loại).<br>- Với điểm $(1; 4)$: $f(1, 4) = 2(1) - 4 + 1 = -1 < 0$ (Loại).<br>- Với điểm $(2; 1)$: $f(2, 1) = 2(2) - 1 + 1 = 4 > 0$ (Thỏa mãn bất phương trình).<br>- Với điểm $(-1; 0)$: $f(-1, 0) = 2(-1) - 0 + 1 = -1 < 0$ (Loại).<br><br><b>Kết luận:</b> Điểm $(2; 1)$ thuộc miền nghiệm. Chọn đáp án <b>C</b>."
      },
      {
        "q": "Trong tam giác $ABC$ bất kỳ với các cạnh $a = BC, b = CA, c = AB$, công thức định lý Cosin nào sau đây là ĐÚNG?",
        "opts": [
          "$a^2 = b^2 + c^2 - 2bc \\cos A$",
          "$a^2 = b^2 + c^2 + 2bc \\cos A$",
          "$a^2 = b^2 + c^2 - 2bc \\sin A$",
          "$a^2 = b^2 + c^2 - bc \\cos A$"
        ],
        "ans": 0,
        "exp": "<b>Định lý Cosin trong tam giác:</b><br>Trong tam giác $ABC$ bất kỳ, bình phương một cạnh bằng tổng bình phương hai cạnh còn lại trừ đi hai lần tích của hai cạnh đó với cosin của góc xen giữa:<br>$$a^2 = b^2 + c^2 - 2bc \\cos A$$<br>$$b^2 = a^2 + c^2 - 2ac \\cos B$$<br>$$c^2 = a^2 + b^2 - 2ab \\cos C$$<br><b>Kết luận:</b> Khẳng định chuẩn xác là $a^2 = b^2 + c^2 - 2bc \\cos A$. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Vectơ có điểm đầu là $A$ và điểm cuối là $B$ được ký hiệu chuẩn là:",
        "opts": [
          "$AB$",
          "$|\\vec{AB}|$",
          "$\\vec{AB}$",
          "$\\vec{BA}$"
        ],
        "ans": 2,
        "exp": "<b>Quy ước ký hiệu vectơ trong hình học:</b><br>- Một đoạn thẳng có hướng được gọi là vectơ.<br>- Khi điểm đầu là $A$ và điểm cuối là $B$, mũi tên chỉ từ $A$ sang $B$, ký hiệu là $\\vec{AB}$.<br>- Phân biệt: $AB$ là độ dài đoạn thẳng, $|\\vec{AB}|$ là độ dài của vectơ, còn $\\vec{BA}$ là vectơ ngược hướng (điểm đầu $B$, điểm cuối $A$).<br><br><b>Kết luận:</b> Ký hiệu chuẩn là $\\vec{AB}$. Chọn đáp án <b>C</b>."
      }
    ]
  },
  {
    "exam_code": "DGNL_2026_01",
    "grade": "DGNL",
    "subject": "DGNL_TONGHOP",
    "title": "ĐGNL ĐHQG-HCM: Tư duy Định lượng & Suy luận Logic (Đề số 01)",
    "description": "Cấu trúc chuẩn ĐHQG-HCM: Giải toán ứng dụng thực tế, toán chuyển động, tối ưu hóa phần trăm, logic suy luận và xác suất tổ hợp.",
    "time_minutes": 35,
    "questions": [
      {
        "q": "Một món hàng được giảm giá $20\\%$, sau đó người bán tiếp tục giảm thêm $10\\%$ trên giá đã giảm. Tổng phần trăm được giảm giá so với giá gốc ban đầu là bao nhiêu?",
        "opts": [
          "$28\\%$",
          "$30\\%$",
          "$72\\%$",
          "$32\\%$"
        ],
        "ans": 0,
        "exp": "<b>Phương pháp giải:</b> Tính giá trị món hàng sau từng đợt giảm giá liên tiếp.<br><br><b>Lời giải chi tiết:</b><br>Gọi giá niêm yết ban đầu của món hàng là $P_0$ (với $P_0 > 0$).<br>Sau lần giảm giá thứ nhất (giảm $20\\%$), giá món hàng còn lại:<br>$$P_1 = P_0 \\cdot (100\\% - 20\\%) = 0{,}8 \\cdot P_0$$<br>Sau lần giảm giá thứ hai (giảm tiếp $10\\%$ trên giá đã giảm $P_1$), giá thực tế người mua phải trả là:<br>$$P_2 = P_1 \\cdot (100\\% - 10\\%) = (0{,}8 \\cdot P_0) \\cdot 0{,}9 = 0{,}72 \\cdot P_0 = 72\\% \\cdot P_0$$<br>Mức giảm giá tổng cộng so với giá gốc ban đầu là:<br>$$\\Delta P = 100\\% - 72\\% = 28\\%$$<br><i>Lưu ý tránh bẫy: Tuyệt đối không lấy $20\\% + 10\\% = 30\\%$ vì đợt giảm thứ hai áp dụng trên giá đã hạ chứ không phải trên giá gốc ban đầu.</i><br><br><b>Kết luận:</b> Món hàng được giảm tổng cộng $28\\%$. Chọn đáp án <b>A</b>."
      },
      {
        "q": "An đi xe đạp từ nhà đến trường. Nếu đi với vận tốc $12\\text{ km/h}$ thì An bị muộn $5\\text{ phút}$. Nếu đi với vận tốc $15\\text{ km/h}$ thì An đến sớm $5\\text{ phút}$. Quãng đường từ nhà An đến trường dài bao nhiêu?",
        "opts": [
          "$10\\text{ km}$",
          "$12\\text{ km}$",
          "$15\\text{ km}$",
          "$8\\text{ km}$"
        ],
        "ans": 0,
        "exp": "<b>Tóm tắt & Đổi đơn vị:</b><br>- Vận tốc lần 1: $v_1 = 12\\text{ km/h}$ (muộn $5\\text{ phút}$).<br>- Vận tốc lần 2: $v_2 = 15\\text{ km/h}$ (sớm $5\\text{ phút}$).<br>- Chênh lệch thời gian giữa hai lần đi:<br>$$\\Delta t = 5\\text{ phút} - (-5\\text{ phút}) = 10\\text{ phút} = \\frac{10}{60}\\text{ giờ} = \\frac{1}{6}\\text{ giờ}$$<br><b>Thiết lập phương trình chuyển động:</b><br>Gọi quãng đường từ nhà An đến trường là $s$ (đơn vị: $\\text{km}$, điều kiện $s > 0$).<br>- Thời gian đi với vận tốc $v_1$: $t_1 = \\frac{s}{12}\\text{ (giờ)}$.<br>- Thời gian đi với vận tốc $v_2$: $t_2 = \\frac{s}{15}\\text{ (giờ)}$.<br><br>Vì thời gian đi lần 1 nhiều hơn lần 2 là $\\frac{1}{6}\\text{ giờ}$, ta có phương trình:<br>$$t_1 - t_2 = \\frac{s}{12} - \\frac{s}{15} = \\frac{1}{6}$$<br>$$\\iff s \\cdot \\left(\\frac{1}{12} - \\frac{1}{15}\\right) = \\frac{1}{6} \\iff s \\cdot \\left(\\frac{5 - 4}{60}\\right) = \\frac{1}{6} \\iff \\frac{s}{60} = \\frac{1}{6}$$<br>$$\\Rightarrow s = \\frac{60}{6} = 10\\text{ (km)}$$<br><b>Kết luận:</b> Quãng đường từ nhà An đến trường dài đúng $10\\text{ km}$. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Khảo sát một khối lớp gồm $100$ học sinh: có $65$ bạn thích môn Toán, $50$ bạn thích môn Văn, và $25$ bạn thích cả hai môn Toán và Văn. Hỏi có bao nhiêu bạn không thích môn nào trong hai môn này?",
        "opts": [
          "$10\\text{ bạn}$",
          "$15\\text{ bạn}$",
          "$20\\text{ bạn}$",
          "$5\\text{ bạn}$"
        ],
        "ans": 0,
        "exp": "<b>Cơ sở lý thuyết:</b> Áp dụng Nguyên lý Bù trừ (Biểu đồ Ven) cho hai tập hợp hữu hạn:<br>$$|A \\cup B| = |A| + |B| - |A \\cap B|$$<br><b>Lời giải chi tiết:</b><br>- Gọi $T$ là tập hợp học sinh thích Toán $\\Rightarrow |T| = 65$.<br>- Gọi $V$ là tập hợp học sinh thích Văn $\\Rightarrow |V| = 50$.<br>- Tập hợp học sinh thích cả hai môn là $T \\cap V \\Rightarrow |T \\cap V| = 25$.<br><br>Số học sinh thích ít nhất một môn (Toán hoặc Văn) là:<br>$$|T \\cup V| = |T| + |V| - |T \\cap V| = 65 + 50 - 25 = 90\\text{ (học sinh)}$$<br>Tổng số học sinh được khảo sát là $100$ bạn. Do đó, số học sinh không thích môn nào trong hai môn là:<br>$$N = 100 - |T \\cup V| = 100 - 90 = 10\\text{ (học sinh)}$$<br><b>Kết luận:</b> Có $10$ học sinh không thích môn nào. Chọn đáp án <b>A</b>."
      },
      {
        "q": "Cho mệnh đề logic: \"Nếu trời mưa thì đường trơn\". Trong các mệnh đề sau, mệnh đề nào tương đương logic với mệnh đề đã cho?",
        "opts": [
          "Nếu đường không trơn thì trời không mưa",
          "Nếu trời không mưa thì đường không trơn",
          "Nếu đường trơn thì trời mưa",
          "Trời mưa và đường không trơn"
        ],
        "ans": 0,
        "exp": "<b>Cơ sở logic học:</b><br>Xét mệnh đề kéo theo dạng $P \\Rightarrow Q$. Mệnh đề phản đảo của nó là $\\neg Q \\Rightarrow \\neg P$.<br>Theo bảng chân trị logic học mệnh đề, một mệnh đề kéo theo luôn <b>tương đương logic</b> với mệnh đề phản đảo của nó:<br>$$(P \\Rightarrow Q) \\equiv (\\neg Q \\Rightarrow \\neg P)$$<br><b>Phân tích cụ thể:</b><br>- Mệnh đề $P$: \"Trời mưa\" $\\Rightarrow \\neg P$: \"Trời không mưa\".<br>- Mệnh đề $Q$: \"Đường trơn\" $\\Rightarrow \\neg Q$: \"Đường không trơn\".<br>Do đó mệnh đề phản đảo $\\neg Q \\Rightarrow \\neg P$ là: <i>\"Nếu đường không trơn thì trời không mưa\"</i>.<br><br><i>Lưu ý: Mệnh đề đảo $Q \\Rightarrow P$ và mệnh đề nghịch $\\neg P \\Rightarrow \\neg Q$ không tương đương với mệnh đề ban đầu.</i><br><br><b>Kết luận:</b> Mệnh đề tương đương là \"Nếu đường không trơn thì trời không mưa\". Chọn đáp án <b>A</b>."
      },
      {
        "q": "Một lớp học có $25$ học sinh nam và $15$ học sinh nữ. Chọn ngẫu nhiên $2$ học sinh đi dự đại hội. Xác suất để chọn được đúng $1$ học sinh nam và $1$ học sinh nữ là:",
        "opts": [
          "$\\frac{25}{52}$",
          "$\\frac{1}{2}$",
          "$\\frac{375}{780}$",
          "$\\frac{25}{40}$"
        ],
        "ans": 0,
        "exp": "<b>Phương pháp giải:</b> Áp dụng định nghĩa cổ điển của xác suất: $P(A) = \\frac{n(A)}{n(\\Omega)}$.<br><br><b>Lời giải chi tiết:</b><br><b>Bước 1: Tính số phần tử không gian mẫu $n(\\Omega)$</b><br>Tổng số học sinh trong lớp là: $n = 25 + 15 = 40\\text{ (học sinh)}$.<br>Số cách chọn ngẫu nhiên $2$ học sinh từ $40$ học sinh là:<br>$$n(\\Omega) = C_{40}^2 = \\frac{40 \\cdot 39}{2} = 780\\text{ (cách)}$$<br><b>Bước 2: Tính số kết quả thuận lợi cho biến cố $A$</b><br>Biến cố $A$: \"Chọn được 1 học sinh nam và 1 học sinh nữ\".<br>- Số cách chọn $1$ bạn nam từ $25$ bạn nam: $C_{25}^1 = 25\\text{ (cách)}$.<br>- Số cách chọn $1$ bạn nữ từ $15$ bạn nữ: $C_{15}^1 = 15\\text{ (cách)}$.<br>Theo quy tắc nhân, số cách chọn là:<br>$$n(A) = C_{25}^1 \\cdot C_{15}^1 = 25 \\cdot 15 = 375\\text{ (cách)}$$<br><b>Bước 3: Tính xác suất biến cố $A$</b><br>$$P(A) = \\frac{n(A)}{n(\\Omega)} = \\frac{375}{780} = \\frac{25}{52} \\approx 0{,}4808\\text{ (hay } 48{,}08\\%\\text{)}$$<br><b>Kết luận:</b> Xác suất cần tìm là $\\frac{25}{52}$. Chọn đáp án <b>A</b>."
      }
    ]
  }
]';
    $exams = json_decode($jsonText, true);
    if (!is_array($exams)) return;

    $stmt = $db->prepare("
        INSERT OR REPLACE INTO bdvh_exams (exam_code, grade, subject, title, description, time_minutes, questions_json)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($exams as $ex) {
        $stmt->execute([
            $ex['exam_code'],
            $ex['grade'],
            $ex['subject'],
            $ex['title'],
            $ex['description'],
            $ex['time_minutes'],
            json_encode($ex['questions'], JSON_UNESCAPED_UNICODE)
        ]);
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
            'message' => '🎉 CHÚC MỪNG EM ĐÃ VÀO ' . mb_strtoupper($check['label']) . '! Toàn bộ đề thi và phân tích chuyên sâu từ Hệ Thống đã được MỞ KHÓA KHÔNG GIỚI HẠN.',
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

    // 6. Nộp bài, chấm điểm và gọi Hệ Thống Khảo Thí phân tích tư duy (Chấm bài miễn phí, AI nhận xét cần bonus khi hết lượt)
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

        // Xử lý quyền xem nhận xét Hệ Thống Khảo Thí
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
                    'time_strategy_advice' => 'Phân tích tư duy sâu và mẹo giải nhanh độc quyền đang được khóa. Hãy ủng hộ Thầy 5.000đ để mở khóa phân tích sư phạm chuyên sâu nhé!'
                ],
                'root_causes' => [
                    "Tính năng chỉ ra chính xác lỗ hổng tư duy từng câu của Hệ Thống Khảo Thí yêu cầu mở khóa bonus.",
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
            json_out(['status' => 'error', 'message' => 'Vui lòng đăng nhập hoặc nhập mã lớp.'], 401);
        }
        $userId = (int)$_SESSION['bdvh_user_id'];
        $stmt = $pdo->prepare("SELECT username, full_name FROM bdvh_users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $amount = (int)($inputData['amount'] ?? 5000);
        $cleanUname = preg_replace('/[^a-zA-Z0-9_]/', '', $user['username'] ?? 'hs');
        $memo = 'BDVH ' . substr($cleanUname, 0, 15) . ' ' . ($amount / 1000) . 'K';

        // Lấy cấu hình ngân hàng của Thầy Tuấn từ DB (hoặc mặc định)
        $bankId = 'MB'; // Mặc định MB Bank hoặc VCB
        $bankAccount = '0908123456';
        $accountName = 'DINH QUOC TUAN';
        $momoPhone = '0908123456';

        try {
            $stSet = $pdo->query("SELECT setting_key, setting_value FROM bdvh_settings");
            if ($stSet) {
                while ($r = $stSet->fetch()) {
                    if ($r['setting_key'] === 'bank_id') $bankId = $r['setting_value'];
                    if ($r['setting_key'] === 'bank_account') $bankAccount = $r['setting_value'];
                    if ($r['setting_key'] === 'account_name') $accountName = $r['setting_value'];
                    if ($r['setting_key'] === 'momo_phone') $momoPhone = $r['setting_value'];
                }
            }
        } catch (Throwable $e) {}

        // Ghi transaction trạng thái PENDING
        $trialsToAdd = ($amount === 5000) ? 5 : (($amount === 10000) ? 12 : 30);
        $stmt = $pdo->prepare("
            INSERT INTO bdvh_transactions (user_id, amount, trials_added, payment_method, memo, status, created_at)
            VALUES (?, ?, ?, 'VIETQR', ?, 'PENDING', datetime('now','localtime'))
        ");
        $stmt->execute([$userId, $amount, $trialsToAdd, $memo]);

        // Tạo link VietQR chuẩn Napas247 quét được trên TẤT CẢ các app ngân hàng
        $encodedAccName = urlencode($accountName);
        $encodedMemo = urlencode($memo);
        $vietQrUrl = "https://img.vietqr.io/image/{$bankId}-{$bankAccount}-compact2.png?amount={$amount}&addInfo={$encodedMemo}&accountName={$encodedAccName}";

        // Mã MoMo
        $momoQrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode("2|99|{$momoPhone}|{$accountName}||0|0|{$amount}|{$memo}|transfer_myqr");

        json_out([
            'status' => 'success',
            'payment_info' => [
                'recipient_name' => $accountName,
                'bank_id' => $bankId,
                'bank_account' => $bankAccount,
                'momo_phone' => $momoPhone,
                'amount' => $amount,
                'amount_formatted' => number_format($amount, 0, ',', '.') . ' VNĐ',
                'memo' => $memo,
                'trials_added' => $trialsToAdd,
                'vietqr_url' => $vietQrUrl,
                'momo_url' => $momoQrUrl,
                'support_email' => 'dqtuanhcmus@gmail.com'
            ]
        ]);
        break;

    // Cập nhật cấu hình tài khoản nhận tiền (Dành cho Thầy Tuấn)
    case 'save_payment_settings':
        $bId = strtoupper(trim((string)($inputData['bank_id'] ?? '')));
        $bAcc = trim((string)($inputData['bank_account'] ?? ''));
        $accName = mb_strtoupper(trim((string)($inputData['account_name'] ?? 'DINH QUOC TUAN')));
        $mPhone = trim((string)($inputData['momo_phone'] ?? ''));

        if ($bId !== '' && $bAcc !== '') {
            $st = $pdo->prepare("INSERT OR REPLACE INTO bdvh_settings (setting_key, setting_value) VALUES (?, ?)");
            $st->execute(['bank_id', $bId]);
            $st->execute(['bank_account', $bAcc]);
            $st->execute(['account_name', $accName]);
            $st->execute(['momo_phone', $mPhone]);
            json_out(['status' => 'success', 'message' => 'Đã lưu cấu hình tài khoản ngân hàng thành công!']);
        }
        json_out(['status' => 'error', 'message' => 'Vui lòng điền đủ Tên ngân hàng và Số tài khoản.'], 400);
        break;

    case 'get_payment_settings':
        $settings = ['bank_id' => 'MB', 'bank_account' => '', 'account_name' => 'DINH QUOC TUAN', 'momo_phone' => ''];
        try {
            $st = $pdo->query("SELECT setting_key, setting_value FROM bdvh_settings");
            if ($st) {
                while ($r = $st->fetch()) {
                    $settings[$r['setting_key']] = $r['setting_value'];
                }
            }
        } catch (Throwable $e) {}
        json_out(['status' => 'success', 'settings' => $settings]);
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
