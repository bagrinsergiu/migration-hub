import React, { useState } from 'react';
import { api } from '../../api/client';
import './GoogleSheets.css';

interface GoogleSheetsConnectProps {
  onConnected?: () => void;
}

export default function GoogleSheetsConnect({ onConnected }: GoogleSheetsConnectProps) {
  const [spreadsheetId, setSpreadsheetId] = useState('');
  const [spreadsheetName, setSpreadsheetName] = useState('');
  const [connecting, setConnecting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!spreadsheetId.trim()) {
      setError('Введите ID таблицы');
      return;
    }

    try {
      setConnecting(true);
      setError(null);
      setSuccess(false);

      const response = await api.connectGoogleSheet(
        spreadsheetId.trim(),
        spreadsheetName.trim() || undefined
      );

      if (response.success) {
        setSuccess(true);
        setSpreadsheetId('');
        setSpreadsheetName('');
        if (onConnected) {
          onConnected();
        }
        setTimeout(() => setSuccess(false), 3000);
      } else {
        setError(response.error || 'Ошибка подключения таблицы');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка подключения таблицы');
    } finally {
      setConnecting(false);
    }
  };

  const handleOAuth = async () => {
    try {
      const response = await api.getOAuthAuthorizeUrl();
      if (response.success && response.data?.url) {
        window.location.href = response.data.url;
      } else {
        setError(response.error || 'Ошибка получения URL авторизации');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка получения URL авторизации');
    }
  };

  return (
    <div className="google-sheets-connect">
      <h3>Подключить Google таблицу</h3>
      <form onSubmit={handleSubmit} className="connect-form">
        <div className="form-group">
          <label htmlFor="spreadsheet-id">
            ID таблицы <span className="required">*</span>
          </label>
          <input
            id="spreadsheet-id"
            type="text"
            value={spreadsheetId}
            onChange={(e) => setSpreadsheetId(e.target.value)}
            placeholder="Например: 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms"
            disabled={connecting}
            required
          />
          <small className="form-hint">
            ID таблицы можно найти в URL: https://docs.google.com/spreadsheets/d/<strong>SPREADSHEET_ID</strong>/edit
          </small>
        </div>

        <div className="form-group">
          <label htmlFor="spreadsheet-name">
            Название таблицы (опционально)
          </label>
          <input
            id="spreadsheet-name"
            type="text"
            value={spreadsheetName}
            onChange={(e) => setSpreadsheetName(e.target.value)}
            placeholder="Название для отображения"
            disabled={connecting}
          />
        </div>

        {error && (
          <div className="message message-error">{error}</div>
        )}

        {success && (
          <div className="message message-success">Таблица успешно подключена!</div>
        )}

        <div className="form-actions">
          <button
            type="submit"
            disabled={connecting || !spreadsheetId.trim()}
            className="btn btn-primary"
          >
            {connecting ? 'Подключение...' : 'Подключить'}
          </button>
          <button
            type="button"
            onClick={handleOAuth}
            className="btn btn-secondary"
            title="Авторизоваться через Google OAuth"
          >
            🔐 OAuth авторизация
          </button>
        </div>
      </form>
    </div>
  );
}
