import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import './common.css';
import './Logs.css';

interface LogEntry {
  mb_project_uuid: string;
  brz_project_id: number;
  migration_uuid: string;
  status: string;
  created_at: string;
}

export default function Logs() {
  const [logs, setLogs] = useState<LogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [limit, setLimit] = useState(10);
  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(null);
  const [projectLogs, setProjectLogs] = useState<any>(null);
  const [loadingProjectLogs, setLoadingProjectLogs] = useState(false);

  useEffect(() => {
    loadRecentLogs();
  }, [limit]);

  const loadRecentLogs = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getRecentLogs(limit);
      if (response.success && response.data) {
        setLogs(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки логов');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки логов');
    } finally {
      setLoading(false);
    }
  };

  const loadProjectLogs = async (projectId: number) => {
    try {
      setLoadingProjectLogs(true);
      setSelectedProjectId(projectId);
      const response = await api.getLogs(projectId);
      if (response.success && response.data) {
        setProjectLogs(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки логов проекта');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки логов проекта');
    } finally {
      setLoadingProjectLogs(false);
    }
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка логов...</p>
      </div>
    );
  }

  if (error && !logs.length) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={loadRecentLogs} className="btn btn-primary">
          Попробовать снова
        </button>
      </div>
    );
  }

  return (
    <div className="logs">
      <div className="page-header">
        <h2>Логи миграций</h2>
        <div className="header-actions">
          <select
            value={limit}
            onChange={(e) => setLimit(parseInt(e.target.value))}
            className="form-input"
            style={{ width: 'auto' }}
          >
            <option value="10">10 записей</option>
            <option value="25">25 записей</option>
            <option value="50">50 записей</option>
            <option value="100">100 записей</option>
          </select>
          <button onClick={loadRecentLogs} className="btn btn-secondary">
            Обновить
          </button>
        </div>
      </div>

      {error && (
        <div className="alert alert-error">
          {error}
        </div>
      )}

      <div className="logs-grid">
        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Последние миграции</h3>
          </div>
          <div className="logs-list">
            {logs.length === 0 ? (
              <div className="empty-state">Логи не найдены</div>
            ) : (
              logs.map((log, index) => {
                const statusConfig = getStatusConfig(log.status as any);
                return (
                  <div
                    key={index}
                    className={`log-item ${selectedProjectId === log.brz_project_id ? 'active' : ''}`}
                    onClick={() => loadProjectLogs(log.brz_project_id)}
                  >
                    <div className="log-item-header">
                      <span className="log-project-id">#{log.brz_project_id}</span>
                      <span
                        className="status-badge"
                        style={{
                          color: statusConfig.color,
                          backgroundColor: statusConfig.bgColor,
                        }}
                      >
                        {statusConfig.label}
                      </span>
                    </div>
                    <div className="log-item-body">
                      <div className="log-item-info">
                        <span className="log-label">UUID:</span>
                        <span className="log-value uuid">{formatUUID(log.migration_uuid)}</span>
                      </div>
                      <div className="log-item-info">
                        <span className="log-label">MB UUID:</span>
                        <span className="log-value uuid">{formatUUID(log.mb_project_uuid)}</span>
                      </div>
                      {log.created_at && (
                        <div className="log-item-info">
                          <span className="log-label">Создано:</span>
                          <span className="log-value">{formatDate(log.created_at)}</span>
                        </div>
                      )}
                    </div>
                    <div className="log-item-actions">
                      <Link
                        to={`/migrations/${log.brz_project_id}`}
                        className="btn btn-sm btn-link"
                        onClick={(e) => e.stopPropagation()}
                      >
                        Детали →
                      </Link>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>

        {selectedProjectId && (
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Логи проекта #{selectedProjectId}</h3>
              <button
                onClick={() => {
                  setSelectedProjectId(null);
                  setProjectLogs(null);
                }}
                className="btn btn-sm btn-secondary"
              >
                Закрыть
              </button>
            </div>
            {loadingProjectLogs ? (
              <div className="loading-container">
                <div className="spinner"></div>
                <p>Загрузка логов...</p>
              </div>
            ) : projectLogs ? (
              <div className="project-logs">
                {projectLogs.logs && projectLogs.logs.length > 0 ? (
                  <div className="json-viewer">
                    <pre>{JSON.stringify(projectLogs.logs, null, 2)}</pre>
                  </div>
                ) : (
                  <div className="empty-state">Логи для этого проекта не найдены</div>
                )}
              </div>
            ) : (
              <div className="empty-state">Не удалось загрузить логи</div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
