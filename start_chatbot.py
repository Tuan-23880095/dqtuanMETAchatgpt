import subprocess
import time
import re
import paramiko
import os
import threading

def start_flask():
    print("[1] Đang khởi động Flask AI Server...")
    # Start flask in background
    subprocess.Popen([r"C:\Users\Quoc_\miniconda3\python.exe", "app.py"], cwd=r"D:\GPU-work\LocalChatbot")

def start_tunnel_and_update_hostinger():
    print("[2] Đang tạo đường hầm Cloudflare ra Internet...")
    process = subprocess.Popen(
        [r"D:\GPU-work\cloudflared.exe", "tunnel", "--url", "http://localhost:5000"],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding='utf-8'
    )
    
    url = None
    # Đọc log của cloudflared để tìm URL
    for line in process.stdout:
        print("  -", line.strip())
        match = re.search(r'https://[a-zA-Z0-9-]+\.trycloudflare\.com', line)
        if match:
            url = match.group(0)
            break
            
    if not url:
        print("[!] Không tìm thấy URL Cloudflare!")
        return
        
    print(f"\n[3] Đã tạo thành công đường hầm: {url}")
    
    # Đọc index.html cục bộ và thay url fetch
    print("[4] Cập nhật URL vào index.html cục bộ...")
    with open(r"D:\GPU-work\LocalChatbot\index.html", "r", encoding="utf-8") as f:
        html = f.read()
    
    # Thay thế url fetch cũ bằng url mới (dùng regex)
    html = re.sub(r"fetch\('https?://[^/]+/send'", f"fetch('{url}/send'", html)
    
    with open(r"D:\GPU-work\LocalChatbot\index.html", "w", encoding="utf-8") as f:
        f.write(html)
        
    print("[5] Đang kết nối Hostinger để đẩy cập nhật web...")
    host = '45.130.228.134'
    port = 65002
    username = 'u464424582'
    password = 'SEAnary171203#'

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, port=port, username=username, password=password)
    
    # Đẩy code lên Hostinger qua git (đã push index.html lên github chưa? 
    # Thay vì push git, ta upload thẳng index.html qua sftp cho nhanh!)
    sftp = client.open_sftp()
    sftp.put(r"D:\GPU-work\LocalChatbot\index.html", "/home/u464424582/domains/dqtuanchatgpt.diemdanhsv.com/public_html/index.html")
    sftp.close()
    client.close()
    
    print("[6] Hoàn tất! BẠN CÓ THỂ MỞ ĐIỆN THOẠI TRUY CẬP: http://dqtuanchatgpt.diemdanhsv.com")
    print("-----------------------------------------------------")
    print("Vui lòng KHÔNG TẮT cửa sổ này (nó đang giữ kết nối mạng).")
    process.wait()

if __name__ == "__main__":
    t = threading.Thread(target=start_flask)
    t.daemon = True
    t.start()
    time.sleep(3)
    start_tunnel_and_update_hostinger()
