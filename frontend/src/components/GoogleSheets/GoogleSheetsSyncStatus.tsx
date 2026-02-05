import { useState, useEffect } from 'react';
import { api, GoogleSheet } from '../../api/client';
import { formatDate } from '../../utils/format';
import './GoogleSheets.css';

interface GoogleSheetsSyncStatusProps {
  sheetId?: number;
  onSync?: () => void;
}

export default function GoogleSheetsSyncStatus({ sheetId, onSync }: GoogleSheetsSyncStatusProps) {
  const [sheet, setSheet] = useState<GoogleSheet | null>(null);
  const [loading, setLoading] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (sheetId) {
      loadSheet();
    }
  }, [sheetId]);

  const loadSheet = async () => {
    if (!sheetId) return;

    try {
      setLoading(true);
      const response = await api.getGoogleSheet(sheetId);
      if (response.success && response.data) {
        setSheet(response.data);
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки информации о таблице');
    } finally {
      setLoading(false);
    }
  };

  const handleSync = async () => {
    if (!sheetId) return;

    try {
      setSyncing(true);
      setError(null);
      const response = await api.syncGoogleSheet(sheetId);
      if (response.success) {
        await loadSheet();
        if (onSync) onSync();
      } else {
        setError(response.error || 'Ошибка синхронизации');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка синхронизации');
    } finally {
      setSyncing(false);
    }
  };

  if (loading) {
    return (
      <div className="google-sheets-sync-status">
        <div className="loading">Загрузка статуса...</div>
      </div>
    );
  }

  if (!sheet) {
    return (
      <div className="google-sheets-sync-status">
        <div className="empty-state">Таблица не выбрана</div>
      </div>
    );
  }

  return (
    <div className="google-sheets-sync-status">
      <div className="sync-status-card">
        <div className="sync-status-header">
          <h4>Статус синхронизации</h4>
          <button
            onClick={handleSync}
            disabled={syncing}
            className="btn btn-sm btn-primary"
          >
            {syncing ? 'Синхронизация...' : '🔄 Синхронизировать сейчас'}
          </button>
        </div>

        <div className="sync-status-body">
          <div className="status-info">
            <div className="info-row">
              <span className="info-label">Таблица:</span>
              <span className="info-value">{sheet.spreadsheet_name || sheet.spreadsheet_id}</span>
            </div>
            {sheet.sheet_name && (
              <div className="info-row">
                <span className="info-label">Лист:</span>
                <span className="info-value">{sheet.sheet_name}</span>
              </div>
            )}
            {sheet.last_synced_at ? (
              <div className="info-row">
                <span className="info-label">Последняя синхронизация:</span>
                <span className="info-value">{formatDate(sheet.last_synced_at)}</span>
              </div>
            ) : (
              <div className="info-row">
                <span className="info-label">Статус:</span>
                <span className="info-value warning">Никогда не синхронизировалась</span>
              </div>
            )}
          </div>

          {error && (
            <div className="message message-error">{error}</div>
          )}
        </div>
      </div>
    </div>
  );
}
