from flask import Flask, request, jsonify
from flask_cors import CORS
import urllib.request
import json
import subprocess

app = Flask(__name__)
CORS(app)

def call_antigravity(prompt):
    try:
        # Gọi CLI của Antigravity (agy) để thực thi lệnh (dùng cờ --print thay vì run)
        result = subprocess.check_output(['agy', '--print', prompt], text=True, encoding='utf-8')
        return f"🤖 [Ba Sáu / Antigravity đã thực thi]:\n{result}"
    except subprocess.CalledProcessError as e:
        return f"Lỗi gọi Ba Sáu (Exit code {e.returncode}):\nOutput: {e.output}"
    except Exception as e:
        return f"Lỗi khi gọi Ba Sáu: {str(e)}"

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
        return f"Lỗi gọi Ollama: {str(e)}"

@app.route('/send', methods=['POST'])
def send_message():
    data = request.json
    user_message = data.get('message', '')
    
    # Nếu tin nhắn bắt đầu bằng @basau, chuyển lệnh cho Antigravity (Agent)
    if user_message.strip().lower().startswith('@basau'):
        task = user_message[6:].strip()
        ai_response = call_antigravity(task)
    else:
        # Ngược lại, chỉ dùng Qwen3 để chat bình thường
        ai_response = get_ai_response(user_message)
        
    return jsonify({'reply': ai_response})

if __name__ == '__main__':
    app.run(debug=True, port=5000)
