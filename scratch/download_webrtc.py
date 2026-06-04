import urllib.request

url = "https://surpriseville.co.in/assets/js/webrtc_client.js"
output_path = "scratch/webrtc_client.js"

try:
    with urllib.request.urlopen(url) as response:
        content = response.read().decode('utf-8')
        with open(output_path, 'w', encoding='utf-8') as f:
            f.write(content)
        print("Success! Downloaded", len(content), "characters.")
except Exception as e:
    print("Error:", e)
