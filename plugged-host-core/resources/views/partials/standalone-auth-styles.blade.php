    <style>
        *, ::before, ::after {
            box-sizing: border-box;
            border-width: 0;
            border-style: solid;
            border-color: rgba(255, 255, 255, 0.1);
        }
        body {
            background-color: #0b0d10;
            background-image: radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.03) 0%, transparent 60%), linear-gradient(to bottom, #101319 0%, #0b0d10 100%);
            color: #ffffff;
            font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }
        .auth-card {
            background-color: #12141a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.875rem;
            width: 100%;
            max-width: 27.5rem;
            padding: 2.25rem 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .fi-input-wrp {
            display: flex;
            align-items: center;
            width: 100%;
            background-color: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .fi-input-wrp:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }
        .fi-input {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            color: #ffffff;
            font-size: 0.875rem;
            line-height: 1.25rem;
            padding: 0.65rem 0.875rem;
            font-family: inherit;
        }
        .fi-input::placeholder {
            color: #52525b;
        }
        .btn-submit {
            width: 100%;
            background-color: #1d4ed8;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.65rem 1rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            transition: background-color 0.15s ease;
            text-decoration: none;
            font-family: inherit;
            box-shadow: none;
        }
        .btn-submit:hover {
            background-color: #2563eb;
        }
        .btn-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8125rem;
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .btn-cancel:hover {
            color: #ffffff;
        }
        .alert-danger {
            background-color: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.25);
            color: #fda4af;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }
        .field-group {
            margin-bottom: 1.125rem;
        }
        .field-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #ffffff;
            margin-bottom: 0.375rem;
        }
        .required-mark {
            color: #f43f5e;
            font-weight: 700;
            margin-left: 0.125rem;
        }
    </style>
