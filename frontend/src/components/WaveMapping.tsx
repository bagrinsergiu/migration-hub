import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { api, WaveMapping as WaveMappingType } from '../api/client';
import { formatDate, formatUUID } from '../utils/format';
import './common.css';
import './WaveMapping.css';

export default function WaveMapping() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [mappings, setMappings] = useState<WaveMappingType[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [toggling, setToggling] = useState<number | null>(null);

  useEffect(() => {
    if (id) {
      loadMapping();
    }
  }, [id]);

  const loadMapping = async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const response = await api.getWaveMapping(id);
      if (response.success && response.data) {
        setMappings(response.data);
      } else {
        setError(response.error || 'Маппинг не найден');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки маппинга');
    } finally {
      setLoading(false);
    }
  };

  const handleToggleCloning = async (brzProjectId: number, currentValue: boolean) => {
    if (!id) return;
    
    setToggling(brzProjectId);
    try {
      const newValue = !currentValue;
      const response = await api.toggleCloning(id, brzProjectId, newValue);
      
      if (response.success) {
        // Обновляем локальное состояние
        setMappings(prev => prev.map(m => 
          m.brz_project_id === brzProjectId 
            ? { ...m, cloning_enabled: newValue }
            : m
        ));
      } else {
        setError(response.error || 'Ошибка обновления параметра клонирования');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка обновления параметра клонирования');
    } finally {
      setToggling(null);
    }
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка маппинга...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={() => navigate(`/wave/${id}`)} className="btn btn-primary">
          Вернуться к волне
        </button>
      </div>
    );
  }

  return (
    <div className="wave-mapping">
      <div className="page-header">
        <button onClick={() => navigate(`/wave/${id}`)} className="btn btn-secondary">
          ← Назад к волне
        </button>
        <h2>Маппинг проектов для волны {id}</h2>
        <div className="header-actions">
          <button onClick={loadMapping} className="btn btn-primary">
            🔄 Обновить
          </button>
        </div>
      </div>

      {mappings.length === 0 ? (
        <div className="empty-message">
          <p>Маппинг проектов не найден</p>
        </div>
      ) : (
        <div className="card">
          <div className="card-header">
            <h3 className="card-title">
              Маппинг проектов ({mappings.length})
            </h3>
          </div>
          <div className="mapping-table-container">
            <table className="mapping-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>MB Project UUID</th>
                  <th>Brizy Project ID</th>
                  <th>Domain</th>
                  <th>Клонирование</th>
                  <th>Changes JSON</th>
                  <th>Создано</th>
                  <th>Обновлено</th>
                  <th>Действия</th>
                </tr>
              </thead>
              <tbody>
                {mappings.map((mapping, index) => (
                  <tr key={mapping.id || `${mapping.brz_project_id}-${index}`}>
                    <td>{mapping.id || '-'}</td>
                    <td className="uuid-cell">{formatUUID(mapping.mb_project_uuid)}</td>
                    <td>
                      {mapping.brz_project_id ? (
                        <Link
                          to={`/migrations/${mapping.brz_project_id}`}
                          className="link"
                        >
                          {mapping.brz_project_id}
                        </Link>
                      ) : (
                        '-'
                      )}
                    </td>
                    <td>
                      {mapping.brizy_project_domain ? (
                        <a
                          href={mapping.brizy_project_domain}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="link"
                        >
                          {mapping.brizy_project_domain}
                        </a>
                      ) : (
                        '-'
                      )}
                    </td>
                    <td>
                      {mapping.brz_project_id ? (
                        <label className="toggle-switch">
                          <input
                            type="checkbox"
                            checked={mapping.cloning_enabled ?? false}
                            onChange={() => handleToggleCloning(
                              mapping.brz_project_id,
                              mapping.cloning_enabled ?? false
                            )}
                            disabled={toggling === mapping.brz_project_id}
                          />
                          <span className="toggle-slider"></span>
                          <span className="toggle-label">
                            {mapping.cloning_enabled ? 'Вкл' : 'Выкл'}
                          </span>
                        </label>
                      ) : (
                        '-'
                      )}
                    </td>
                    <td className="json-cell">
                      {mapping.changes_json ? (
                        <details>
                          <summary>Показать JSON</summary>
                          <pre>{JSON.stringify(mapping.changes_json, null, 2)}</pre>
                        </details>
                      ) : (
                        '-'
                      )}
                    </td>
                    <td>{formatDate(mapping.created_at)}</td>
                    <td>{formatDate(mapping.updated_at)}</td>
                    <td>
                      <div className="action-buttons">
                        {mapping.brz_project_id && (
                          <Link
                            to={`/migrations/${mapping.brz_project_id}`}
                            className="btn btn-sm btn-link"
                            title="Детали миграции"
                          >
                            👁
                          </Link>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
