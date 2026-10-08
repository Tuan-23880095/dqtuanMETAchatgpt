from flask import Flask, request, jsonify
from flask_cors import CORS
import urllib.request
import json
import subprocess
import os

app = Flask(__name__)
CORS(app)

def call_antigravity(prompt):
    try:
        # Gọi CLI của Antigravity (agy) để thực thi lệnh (dùng cờ --print)
        result = subprocess.check_output(['agy', '--print', prompt], text=True, encoding='utf-8')
        return f"🤖 [Ba Sáu / Antigravity đã thực thi]:\n{result}"
    except subprocess.CalledProcessError as e:
        return f"Lỗi gọi Ba Sáu (Exit code {e.returncode}):\nOutput: {e.output}"
    except Exception as e:
        return f"Lỗi khi gọi Ba Sáu: {str(e)}"

def call_metagpt(prompt):
    try:
        # Gọi MetaGPT chạy ngầm để không bị treo giao diện web
        conda_python = r'C:\Users\Quoc_\miniconda3\envs\metagpt\python.exe'
        metagpt_dir = r'D:\GPU-work\Github_Research\MetaGPT'
        
        # Popen giúp chạy nền không chờ kết quả ngay (tránh timeout)
        subprocess.Popen([conda_python, '-m', 'metagpt.software_company', prompt], cwd=metagpt_dir)
        
        return (f"🏭 [MetaGPT - Công ty phần mềm ảo đã nhận dự án!]\n\n"
                f"Dự án: '{prompt}' đang được triển khai ngầm.\n"
                f"Quá trình này sẽ mất khoảng 3-10 phút tùy độ khó. "
                f"Code hoàn thiện sẽ được xuất tự động vào thư mục:\n"
                f"👉 D:\\GPU-work\\Github_Research\\MetaGPT\\workspace")
    except Exception as e:
        return f"Lỗi khi khởi động MetaGPT: {str(e)}"

def get_ai_response(message):
    try:
        url = "http://localhost:11434/api/generate"
        data = {
            "model": "qwen3:8b",
            "prompt": message,
            "stream": False
        }
        req = urllib.request.Request(url, data=json.dumps(data).encode('utf-8'), headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(req) as response:
            result = json.loads(response.read().decode('utf-8'))
            return result['response']
    except Exception as e:
        return f"Lỗi gọi AI nội bộ (Ollama): {str(e)}"

@app.route('/send', methods=['POST'])
def send_message():
    data = request.json
    user_message = data.get('message', '')
    msg_lower = user_message.strip().lower()
    
    # 1. Gọi Agent Ba Sáu
    if msg_lower.startswith('@basau'):
        task = user_message[6:].strip()
        ai_response = call_antigravity(task)
        
    # 2. Gọi Công ty ảo MetaGPT
    elif msg_lower.startswith('@metagpt'):
        task = user_message[8:].strip()
        ai_response = call_metagpt(task)
        
    # 3. Chat thường với Qwen3
    else:
        ai_response = get_ai_response(user_message)
        
    return jsonify({'reply': ai_response})

if __name__ == '__main__':
    app.run(debug=True, port=5000)
