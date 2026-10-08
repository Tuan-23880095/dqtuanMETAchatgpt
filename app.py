from flask import Flask, request, jsonify
from flask_cors import CORS
import urllib.request
import json

app = Flask(__name__)
CORS(app) # Allow cross-origin requests

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
    ai_response = get_ai_response(user_message)
    return jsonify({'reply': ai_response})

if __name__ == '__main__':
    app.run(debug=True, port=5000)
