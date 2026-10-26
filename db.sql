-- =====================================================================
--  bdvh/db.sql — CSDL Nền tảng Bồi Dưỡng Văn Hóa & Đánh Giá Năng Lực
--  Hệ thống khảo thí thông minh có trợ lý Gemini AI - ThS. Đinh Quốc Tuấn
--  Chạy trên MariaDB/MySQL Hostinger (bdvh.diemdanhsv.com)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1. Bảng tài khoản người dùng & Khách làm bài thử
CREATE TABLE IF NOT EXISTS bdvh_users (
  user_id           INT AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(60) NOT NULL UNIQUE,
  full_name         VARCHAR(120) NOT NULL,
  phone             VARCHAR(20) NULL,
  email             VARCHAR(160) NULL,
  password_hash     VARCHAR(255) NULL,             -- NULL với tài khoản khách nhanh
  grade             VARCHAR(20) NOT NULL DEFAULT 'L10', -- L06, L07, L08, L09, L10, L11, L12, DGNL
  role              ENUM('GUEST', 'MEMBER', 'VIP', 'ADMIN') NOT NULL DEFAULT 'GUEST',
  free_trials_left  INT NOT NULL DEFAULT 2,        -- Mặc định 2 lượt miễn phí
  paid_trials_left  INT NOT NULL DEFAULT 0,        -- Số lượt nạp thêm qua MoMo bonus
  bonus_accumulated INT NOT NULL DEFAULT 0,        -- Tổng số tiền bonus đã ủng hộ Thầy (VNĐ)
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login        DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bảng ngân hàng đề thi & bài tập (Lớp 6 đến 12 & ĐGNL ĐHQG)
CREATE TABLE IF NOT EXISTS bdvh_exams (
  exam_id           INT AUTO_INCREMENT PRIMARY KEY,
  exam_code         VARCHAR(50) NOT NULL UNIQUE,   -- VD: TOAN06_01, DGNL_2026_01
  grade             VARCHAR(20) NOT NULL,          -- L06, L07, L08, L09, L10, L11, L12, DGNL
  subject           VARCHAR(40) NOT NULL,          -- TOAN, KHTN, DGNL_TONGHOP
  title             VARCHAR(255) NOT NULL,
  description       TEXT NULL,
  time_minutes      INT NOT NULL DEFAULT 45,
  questions_json    LONGTEXT NOT NULL,             -- JSON mảng các câu hỏi, 4 phương án, đáp án đúng, gợi ý giải
  competency_matrix LONGTEXT NULL,                 -- Ma trận năng lực: Nhận biết, Thông hiểu, Vận dụng
  status            ENUM('ACTIVE', 'DRAFT') NOT NULL DEFAULT 'ACTIVE',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bảng lịch sử bài làm & Phân tích của Gemini AI
CREATE TABLE IF NOT EXISTS bdvh_submissions (
  sub_id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  exam_id           INT NOT NULL,
  score             DECIMAL(4,2) NOT NULL,         -- Thang điểm 10 hoặc thang 1200
  duration_seconds  INT NOT NULL,
  answers_given     LONGTEXT NOT NULL,             -- JSON kết quả chọn từng câu của học sinh
  correct_count     INT NOT NULL DEFAULT 0,
  total_questions   INT NOT NULL DEFAULT 0,
  ai_evaluation     LONGTEXT NULL,                 -- Nhận xét phân tích tư duy từ Gemini AI
  is_free_trial     TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES bdvh_users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (exam_id) REFERENCES bdvh_exams(exam_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng nhật ký giao dịch nạp MoMo bonus & Nâng cấp VIP
CREATE TABLE IF NOT EXISTS bdvh_transactions (
  trans_id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  amount            INT NOT NULL,                  -- 5000, 10000, 20000, 50000...
  trials_added      INT NOT NULL,                  -- Số lượt thi cộng thêm
  payment_method    VARCHAR(30) NOT NULL DEFAULT 'MOMO',
  trans_code        VARCHAR(100) NULL,             -- Mã giao dịch MoMo hoặc mã chuyển khoản
  memo              VARCHAR(120) NULL,             -- Cú pháp chuyển tiền: BDVH [username] 5K
  status            ENUM('PENDING', 'SUCCESS', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES bdvh_users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- NẠP DỮ LIỆU ĐỀ THI MẪU CHUẨN SƯ PHẠM (KHỐI 6, 7, 10 VÀ ĐGNL)
-- =====================================================================

INSERT INTO bdvh_exams (exam_code, grade, subject, title, description, time_minutes, questions_json)
VALUES 
(
  'TOAN06_01', 
  'L06', 
  'TOAN', 
  'Đấu Trường Toán 6: Lũy thừa và Thứ tự phép tính', 
  'Đề luyện phản xạ tính toán nhanh, củng cố quy tắc lũy thừa, thứ tự ngoặc tròn - vuông - nhọn.', 
  20,
  '[
    {"q":"Kết quả của phép tính $2^3 \\cdot 2^2$ bằng bao nhiêu?","opts":["$2^5 = 32$","$2^6 = 64$","$4^5$","$4^6$"],"ans":0,"exp":"Áp dụng quy tắc nhân hai lũy thừa cùng cơ số: $a^m \\cdot a^n = a^{m+n} \\Rightarrow 2^3 \\cdot 2^2 = 2^{3+2} = 2^5 = 32$."},
    {"q":"Giá trị của biểu thức $15 + 5 \\cdot 2^2$ là:","opts":["40","35","80","25"],"ans":1,"exp":"Thực hiện lũy thừa trước: $2^2 = 4$. Sau đó nhân: $5 \\cdot 4 = 20$. Cuối cùng cộng: $15 + 20 = 35$."},
    {"q":"Tìm $x$ biết: $3^x = 27$?","opts":["$x=2$","$x=3$","$x=4$","$x=9$"],"ans":1,"exp":"Ta có $27 = 3^3$, do đó $3^x = 3^3 \\Rightarrow x = 3$."},
    {"q":"Số nào sau đây chia hết cho cả 2 và 5?","opts":["124","235","450","502"],"ans":2,"exp":"Số có chữ số tận cùng là 0 thì chia hết cho cả 2 và 5. Do đó 450 thỏa mãn."},
    {"q":"Thứ tự thực hiện đúng các dấu ngoặc trong biểu thức là:","opts":["( ) $\\rightarrow$ [ ] $\\rightarrow$ { }","{ } $\\rightarrow$ [ ] $\\rightarrow$ ( )","[ ] $\\rightarrow$ ( ) $\\rightarrow$ { }","( ) $\\rightarrow$ { } $\\rightarrow$ [ ]"],"ans":0,"exp":"Quy tắc chuẩn: Thực hiện trong ngoặc tròn ( ) trước, rồi đến ngoặc vuông [ ], sau cùng là ngoặc nhọn { }."}
  ]'
),
(
  'TOAN07_01', 
  'L07', 
  'TOAN', 
  'Đấu Trường Toán 7: Tập hợp số hữu tỉ & Lũy thừa', 
  'Kiểm tra định nghĩa số hữu tỉ, quy tắc cộng trừ nhân chia số hữu tỉ và quy tắc dấu ngoặc.', 
  25,
  '[
    {"q":"Khẳng định nào sau đây là ĐÚNG về số hữu tỉ?","opts":["Mọi số nguyên đều là số hữu tỉ","Số hữu tỉ chỉ gồm phân số dương","Số 0 không phải là số hữu tỉ","$\\pi$ là một số hữu tỉ"],"ans":0,"exp":"Số nguyên $a$ có thể viết dưới dạng phân số $\\frac{a}{1}$ nên mọi số nguyên đều là số hữu tỉ."},
    {"q":"Kết quả của phép tính $\\left(-\\frac{2}{3}\\right)^2$ là:","opts":["$-\\frac{4}{9}$","$\\frac{4}{9}$","$-\\frac{4}{6}$","$\\frac{4}{6}$"],"ans":1,"exp":"Lũy thừa bậc chẵn của số âm luôn cho kết quả dương: $\\left(-\\frac{2}{3}\\right)^2 = \\frac{(-2)^2}{3^2} = \\frac{4}{9}$."},
    {"q":"Giá trị của biểu thức $\\frac{1}{2} - \\frac{3}{4} + \\frac{5}{2}$ bằng:","opts":["$\\frac{9}{4}$","$\\frac{7}{4}$","$\\frac{5}{4}$","2"],"ans":0,"exp":"Quy đồng mẫu số chung là 4: $\\frac{2}{4} - \\frac{3}{4} + \\frac{10}{4} = \\frac{2 - 3 + 10}{4} = \\frac{9}{4}$."},
    {"q":"Nếu $|x| = 2,5$ thì giá trị của $x$ là:","opts":["$x = 2,5$","$x = -2,5$","$x = 2,5$ hoặc $x = -2,5$","$x = 5$"],"ans":2,"exp":"Giá trị tuyệt đối của số dương và số âm đối nhau đều bằng chính số dương đó."},
    {"q":"Cho $\\frac{x}{3} = \\frac{8}{12}$. Giá trị của $x$ là:","opts":["1","2","3","4"],"ans":1,"exp":"Ta có $x = \\frac{3 \\cdot 8}{12} = \\frac{24}{12} = 2$."}
  ]'
),
(
  'TOAN10_01', 
  'L10', 
  'TOAN', 
  'Toán 10: Mệnh đề, Tập hợp & Bất phương trình', 
  'Củng cố tư duy mệnh đề logic, phép toán giao hợp tập hợp và giải bất phương trình bậc nhất hai ẩn.', 
  30,
  '[
    {"q":"Mệnh đề phủ định của mệnh đề $P$: \\\"$\\forall x \\in \\mathbb{R}, x^2 > 0$\\\" là:","opts":["$\\exists x \\in \\mathbb{R}, x^2 \\le 0$","$\\exists x \\in \\mathbb{R}, x^2 < 0$","$\\forall x \\in \\mathbb{R}, x^2 \\le 0$","$\\exists x \\notin \\mathbb{R}, x^2 \\le 0$"],"ans":0,"exp":"Phủ định của $\\forall$ là $\\exists$, phủ định của $>$ là $\\le$. Do đó phủ định là $\\exists x \\in \\mathbb{R}, x^2 \\le 0$ (ứng với $x=0$)."},
    {"q":"Cho tập hợp $A = [ -2; 3 )$ và $B = ( 1; 5 ]$. Giao của hai tập hợp $A \\cap B$ là:","opts":["$[-2; 5]$","$(1; 3)$","$[1; 3)$","$(1; 3]$"],"ans":1,"exp":"Các phần tử vừa thuộc $[-2; 3)$ vừa thuộc $(1; 5]$ là khoảng $(1; 3)$."},
    {"q":"Điểm nào sau đây thuộc miền nghiệm của bất phương trình $2x - y + 1 > 0$?","opts":["(0; 2)","(1; 4)","(2; 1)","(-1; 0)"],"ans":2,"exp":"Thay tọa độ (2; 1): $2(2) - 1 + 1 = 4 > 0$ (Đúng). Do đó (2; 1) thuộc miền nghiệm."},
    {"q":"Cho tam giác $ABC$ có $BC = a, CA = b, AB = c$. Định lý cosin phát biểu đúng là:","opts":["$a^2 = b^2 + c^2 - 2bc \\cos A$","$a^2 = b^2 + c^2 + 2bc \\cos A$","$a^2 = b^2 + c^2 - 2bc \\sin A$","$a^2 = b^2 + c^2 - bc \\cos A$"],"ans":0,"exp":"Định lý cosin chuẩn trong tam giác: $a^2 = b^2 + c^2 - 2bc \\cos A$."},
    {"q":"Vectơ có điểm đầu là $A$ và điểm cuối là $B$ được ký hiệu là:","opts":["$AB$","$|\\vec{AB}|$","$\\vec{AB}$","$\\vec{BA}$"],"ans":2,"exp":"Vectơ có điểm đầu $A$ và điểm cuối $B$ ký hiệu là $\\vec{AB}$."}
  ]'
),
(
  'DGNL_2026_01', 
  'DGNL', 
  'DGNL_TONGHOP', 
  'ĐGNL ĐHQG-HCM: Tư duy Định lượng & Suy luận Logic (Đề số 01)', 
  'Đề luyện thi năng lực chuẩn cấu trúc Đại học Quốc gia TP.HCM: Giải toán ứng dụng, phân tích bảng số liệu, logic suy luận.', 
  35,
  '[
    {"q":"Một cửa hàng giảm giá 20% cho một món hàng, sau đó lại giảm tiếp 10% trên giá đã giảm. Hỏi sau hai lần giảm, món hàng được giảm tất cả bao nhiêu phần trăm so với giá ban đầu?","opts":["28%","30%","72%","32%"],"ans":0,"exp":"Gọi giá ban đầu là 100%. Lần 1 giảm 20% còn 80%. Lần 2 giảm 10% của 80% tức là giảm $80% \\times 0,1 = 8%$. Giá còn lại là $80% - 8% = 72%$. Vậy mức giảm tổng cộng là $100% - 72% = 28%$."},
    {"q":"Nếu An đi học bằng xe đạp với vận tốc 12 km/h thì đến trường muộn 5 phút. Nếu An đi với vận tốc 15 km/h thì đến trường sớm 5 phút. Quãng đường từ nhà An đến trường là:","opts":["10 km","12 km","15 km","8 km"],"ans":0,"exp":"Đổi 5 phút = $\\frac{1}{12}$ giờ. Gọi quãng đường là $s$ (km). Hiệu thời gian hai lần đi: $\\frac{s}{12} - \\frac{s}{15} = \\frac{5 + 5}{60} = \\frac{1}{6}$ giờ. Ta có: $\\frac{s}{60} = \\frac{1}{6} \\Rightarrow s = 10$ km."},
    {"q":"Trong một cuộc khảo sát 100 học sinh, có 65 bạn thích môn Toán, 50 bạn thích môn Văn và 25 bạn thích cả hai môn. Số bạn không thích cả hai môn là:","opts":["10 bạn","15 bạn","20 bạn","5 bạn"],"ans":0,"exp":"Số bạn thích ít nhất một môn: $n(T \\cup V) = 65 + 50 - 25 = 90$ bạn. Số bạn không thích cả hai môn: $100 - 90 = 10$ bạn."},
    {"q":"Cho mệnh đề: \\\"Nếu trời mưa thì đường trơn\\\". Mệnh đề phản đảo có giá trị logic tương đương là:","opts":["Nếu đường không trơn thì trời không mưa","Nếu trời không mưa thì đường không trơn","Nếu đường trơn thì trời mưa","Trời mưa và đường không trơn"],"ans":0,"exp":"Mệnh đề phản đảo của $A \\Rightarrow B$ là $\\neg B \\Rightarrow \\neg A$ (Nếu đường không trơn thì trời không mưa), luôn có cùng chân trị logic với mệnh đề ban đầu."},
    {"q":"Một lớp có 40 học sinh gồm 25 nam và 15 nữ. Chọn ngẫu nhiên 2 học sinh đi dự đại hội. Xác suất để chọn được 1 nam và 1 nữ là:","opts":["$\\frac{25}{52}$","$\\frac{1}{2}$","$\\frac{375}{780}$","$\\frac{25}{40}$"],"ans":0,"exp":"Số cách chọn 2 học sinh bất kỳ: $C_{40}^2 = 780$. Số cách chọn 1 nam và 1 nữ: $C_{25}^1 \\cdot C_{15}^1 = 25 \\cdot 15 = 375$. Xác suất: $P = \\frac{375}{780} = \\frac{25}{52}$."}
  ]'
)
ON DUPLICATE KEY UPDATE title = VALUES(title), questions_json = VALUES(questions_json);
