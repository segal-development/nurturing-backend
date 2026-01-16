<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Grupo Segal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        
        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background-color: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }
        
        .logo h1 {
            color: #1a365d;
            font-size: 24px;
            margin-bottom: 30px;
        }
        
        h2 {
            color: #333;
            font-size: 22px;
            margin-bottom: 16px;
        }
        
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        
        .note {
            font-size: 14px;
            color: #888;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>Grupo Segal</h1>
        </div>
        
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </div>
        
        <h2>Enlace no valido</h2>
        <p>{{ $mensaje }}</p>
        
        <p class="note">
            Si necesitas ayuda para desuscribirte, por favor contactanos directamente.
        </p>
    </div>
</body>
</html>
