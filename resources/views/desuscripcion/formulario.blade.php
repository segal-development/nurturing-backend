<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desuscribirse - Grupo Segal</title>
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
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: #1a365d;
            font-size: 24px;
        }
        
        h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 16px;
            text-align: center;
        }
        
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            color: #333;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            background-color: white;
            cursor: pointer;
        }
        
        select:focus {
            outline: none;
            border-color: #1a365d;
        }
        
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .radio-option:hover {
            background-color: #f9f9f9;
        }
        
        .radio-option input {
            margin-right: 12px;
        }
        
        .radio-option.selected {
            border-color: #1a365d;
            background-color: #f0f4f8;
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background-color: #dc2626;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #b91c1c;
        }
        
        .btn-secondary {
            background-color: #e5e7eb;
            color: #374151;
            margin-top: 12px;
        }
        
        .btn-secondary:hover {
            background-color: #d1d5db;
        }
        
        .note {
            font-size: 13px;
            color: #888;
            text-align: center;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>Grupo Segal</h1>
        </div>
        
        <h2>Confirmar desuscripcion</h2>
        <p>Lamentamos verte partir. Selecciona las opciones de desuscripcion:</p>
        
        <form action="/desuscribir/{{ $token }}" method="POST">
            @csrf
            
            <div class="form-group">
                <label>Desuscribirme de:</label>
                <div class="radio-group">
                    <label class="radio-option">
                        <input type="radio" name="canal" value="todos" checked>
                        <span>Todas las comunicaciones (email y SMS)</span>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="canal" value="email">
                        <span>Solo emails</span>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="canal" value="sms">
                        <span>Solo SMS</span>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="motivo">Motivo (opcional):</label>
                <select name="motivo" id="motivo">
                    <option value="">Seleccionar motivo...</option>
                    @foreach($motivos as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">
                Confirmar desuscripcion
            </button>
        </form>
        
        <p class="note">
            Si cambiaste de opinion, simplemente cierra esta pagina.
            No se realizara ningun cambio.
        </p>
    </div>
    
    <script>
        // Highlight selected radio option
        document.querySelectorAll('.radio-option input').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.radio-option').forEach(opt => opt.classList.remove('selected'));
                this.closest('.radio-option').classList.add('selected');
            });
        });
        // Initialize first option as selected
        document.querySelector('.radio-option').classList.add('selected');
    </script>
</body>
</html>
