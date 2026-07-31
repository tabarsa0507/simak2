<!DOCTYPE html>
<html>
<head>
    <style>
        #loader {
            position: fixed;
            inset: 0;
            background: var(--bg-primary);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s;
        }
        #loader.hide {
            opacity: 0;
            pointer-events: none;
        }
        .loader-bar {
            width: 300px;
            height: 4px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .loader-bar .progress {
            height: 100%;
            background: linear-gradient(90deg, #6C63FF, #FF6584);
            animation: loadProgress 2s ease-in-out forwards;
        }
        @keyframes loadProgress {
            0% { width: 0%; }
            100% { width: 90%; }
        }
        .loader-text {
            margin-top: 20px;
            color: var(--text-secondary);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div id="loader">
        <div class="loader-bar">
            <div class="progress"></div>
        </div>
        <div class="loader-text">در حال بارگذاری سامانه...</div>
    </div>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('loader').classList.add('hide');
            }, 1500);
        });
    </script>
</body>
</html>