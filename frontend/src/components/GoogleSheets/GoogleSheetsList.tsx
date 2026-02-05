import { useState, useEffect } from 'react';
import { api, GoogleSheet } from '../../api/client';
import { formatDate } from '../../utils/format';
import './GoogleSheets.css';

interface GoogleSheetsListProps {
  onRefresh?: () => void;
  onSync?: (id: number) => void;
  onDelete?: (id: number) => void;
}

export default function GoogleSheetsList({ onSync, onDelete }: GoogleSheetsListProps) {
  const [sheets, setSheets] = useState<GoogleSheet[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [syncing, setSyncing] = useState<number | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);

  const loadSheets = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getGoogleSheetsList();
      if (response.success && response.data) {
        setSheets(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки списка таблиц');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки списка таблиц');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSheets();
  }, []);

  const handleSync = async (id: number) => {
    try {
      setSyncing(id);
      const response = await api.syncGoogleSheet(id);
      if (response.success) {
        await loadSheets();
        if (onSync) onSync(id);
      } else {
        alert(response.error || 'Ошибка синхронизации');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка синхронизации');
    } finally {
      setSyncing(null);
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Вы уверены, что хотите удалить эту таблицу?')) {
      return;
    }

    try {
      setDeleting(id);
      const response = await api.deleteGoogleSheet(id);
      if (response.success) {
        await loadSheets();
        if (onDelete) onDelete(id);
      } else {
        alert(response.error || 'Ошибка удаления');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка удаления');
    } finally {
      setDeleting(null);
    }
  };

  if (loading) {
    return (
      <div className="google-sheets-list">
        <div className="loading">Загрузка таблиц...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="google-sheets-list">
        <div className="error">{error}</div>
        <button onClick={loadSheets} className="btn btn-primary">Повторить</button>
      </div>
    );
  }

  if (sheets.length === 0) {
    return (
      <div className="google-sheets-list">
        <div className="empty-state">
          <p>Нет подключенных таблиц</p>
          <p className="empty-state-hint">Подключите первую таблицу, чтобы начать работу</p>
        </div>
      </div>
    );
  }

  const getStatusBadge = (status?: string) => {
    if (!status) return null;
    const statusMap: Record<string, { label: string; className: string }> = {
      'pending': { label: 'Ожидает', className: 'status-pending' },
      'in_progress': { label: 'В процессе', className: 'status-in-progress' },
      'completed': { label: 'Завершена', className: 'status-completed' },
      'error': { label: 'Ошибка', className: 'status-error' },
    };
    const statusInfo = statusMap[status] || { label: status, className: 'status-unknown' };
    return <span className={`status-badge ${statusInfo.className}`}>{statusInfo.label}</span>;
  };

  return (
    <div className="google-sheets-list">
      <div className="table-container">
        <table className="data-table">
          <thead>
            <tr>
              <th>Таблица</th>
              <th>Лист</th>
              <th>Волна</th>
              <th>Статус волны</th>
              <th>Workspace</th>
              <th>Последняя синхронизация</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            {sheets.map((sheet) => (
              <tr key={sheet.id}>
                <td>
                  <div className="table-cell-content">
                    <div className="table-name">{sheet.spreadsheet_name || sheet.spreadsheet_id}</div>
                    <div className="table-id">{sheet.spreadsheet_id}</div>
                  </div>
                </td>
                <td>{sheet.sheet_name || '-'}</td>
                <td>
                  {sheet.wave_id ? (
                    <div className="table-cell-content">
                      <div className="wave-name">{sheet.wave_name || sheet.wave_id}</div>
                      {sheet.wave_id && <div className="wave-id">ID: {sheet.wave_id}</div>}
                    </div>
                  ) : (
                    <span className="text-muted">Не привязана</span>
                  )}
                </td>
                <td>{sheet.wave_id ? getStatusBadge(sheet.wave_status) : '-'}</td>
                <td>{sheet.workspace_name || '-'}</td>
                <td>{sheet.last_synced_at ? formatDate(sheet.last_synced_at) : 'Никогда'}</td>
                <td>
                  <div className="table-actions">
                    <button
                      onClick={() => handleSync(sheet.id)}
                      disabled={syncing === sheet.id}
                      className="btn btn-sm btn-primary"
                      title="Синхронизировать"
                    >
                      {syncing === sheet.id ? '⏳' : '🔄'}
                    </button>
                    <button
                      onClick={() => handleDelete(sheet.id)}
                      disabled={deleting === sheet.id}
                      className="btn btn-sm btn-danger"
                      title="Удалить"
                    >
                      {deleting === sheet.id ? '⏳' : '🗑️'}
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
