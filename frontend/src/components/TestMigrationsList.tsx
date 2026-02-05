import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, TestMigration } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import './MigrationsList.css';
import './common.css';

export default function TestMigrationsList() {
  const navigate = useNavigate();
  const [allMigrations, setAllMigrations] = useState<TestMigration[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState({
    status: '',
    mb_project_uuid: '',
    brz_project_id: '',
  });

  // Загружаем все тестовые миграции при монтировании
  useEffect(() => {
    loadAllMigrations();
  }, []);

  // Фильтруем миграции локально
  const filteredMigrations = allMigrations.filter(migration => {
    if (filters.status) {
      if (filters.status === 'success') {
        if (migration.status !== 'success' && migration.status !== 'completed') {
          return false;
        }
      } else if (filters.status === 'completed') {
        if (migration.status !== 'completed') {
          return false;
        }
      } else {
        if (migration.status !== filters.status) {
          return false;
        }
      }
    }
    if (filters.mb_project_uuid && !migration.mb_project_uuid?.toLowerCase().includes(filters.mb_project_uuid.toLowerCase())) {
      return false;
    }
    if (filters.brz_project_id && migration.brz_project_id !== parseInt(filters.brz_project_id)) {
      return false;
    }
    return true;
  });

  const loadAllMigrations = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getTestMigrations({});
      if (response.success && response.data) {
        setAllMigrations(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки тестовых миграций');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки тестовых миграций');
    } finally {
      setLoading(false);
    }
  };

  const handleFilterChange = (key: string, value: string) => {
    setFilters(prev => ({ ...prev, [key]: value }));
  };

  const clearFilters = () => {
    setFilters({
      status: '',
      mb_project_uuid: '',
      brz_project_id: '',
    });
  };

  const handleStatClick = (status: string) => {
    setFilters(prev => ({
      ...prev,
      status: status,
      mb_project_uuid: '',
      brz_project_id: '',
    }));
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Вы уверены, что хотите удалить эту тестовую миграцию?')) {
      return;
    }
    
    try {
      const response = await api.deleteTestMigration(id);
      if (response.success) {
        loadAllMigrations();
      } else {
        alert('Ошибка удаления: ' + (response.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      alert('Ошибка удаления: ' + err.message);
    }
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка тестовых миграций...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={loadAllMigrations} className="btn btn-primary">
          Попробовать снова
        </button>
      </div>
    );
  }

  const handleRunMigration = async (id: number) => {
    if (!confirm('Запустить тестовую миграцию?')) {
      return;
    }
    
    try {
      const response = await api.runTestMigration(id);
      if (response.success) {
        alert('Тестовая миграция запущена');
        loadAllMigrations();
      } else {
        alert('Ошибка запуска: ' + (response.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      alert('Ошибка запуска: ' + err.message);
    }
  };

  return (
    <div className="migrations-list">
      <div className="page-header">
        <h2>Тестовые миграции</h2>
        <Link to="/test/run" className="btn btn-primary">
          + Добавить тестовую миграцию
        </Link>
      </div>

      <div className="filters">
        <div className="filter-group">
          <label>Статус:</label>
          <select
            value={filters.status}
            onChange={(e) => handleFilterChange('status', e.target.value)}
          >
            <option value="">Все</option>
            <option value="pending">Ожидает</option>
            <option value="in_progress">Выполняется</option>
            <option value="success">Успешно</option>
            <option value="completed">Завершено</option>
            <option value="error">Ошибка</option>
          </select>
        </div>

        <div className="filter-group">
          <label>MB UUID:</label>
          <input
            type="text"
            placeholder="Поиск по UUID..."
            value={filters.mb_project_uuid}
            onChange={(e) => handleFilterChange('mb_project_uuid', e.target.value)}
          />
        </div>

        <div className="filter-group">
          <label>Brizy ID:</label>
          <input
            type="number"
            placeholder="ID проекта..."
            value={filters.brz_project_id}
            onChange={(e) => handleFilterChange('brz_project_id', e.target.value)}
          />
        </div>

        <button onClick={clearFilters} className="btn btn-secondary">
          Сбросить
        </button>
      </div>

      <div className="stats">
        <div 
          className={`stat-card ${filters.status === '' && !filters.mb_project_uuid && !filters.brz_project_id ? 'stat-card-active' : ''}`}
          onClick={() => handleStatClick('')}
          style={{ cursor: 'pointer' }}
        >
          <div className="stat-value">{allMigrations.length}</div>
          <div className="stat-label">Всего тестовых миграций</div>
        </div>
        <div 
          className={`stat-card ${filters.status === 'success' ? 'stat-card-active' : ''}`}
          onClick={() => handleStatClick('success')}
          style={{ cursor: 'pointer' }}
        >
          <div className="stat-value success">
            {allMigrations.filter(m => m.status === 'success' || m.status === 'completed').length}
          </div>
          <div className="stat-label">Успешных</div>
        </div>
        <div 
          className={`stat-card ${filters.status === 'error' ? 'stat-card-active' : ''}`}
          onClick={() => handleStatClick('error')}
          style={{ cursor: 'pointer' }}
        >
          <div className="stat-value error">
            {allMigrations.filter(m => m.status === 'error').length}
          </div>
          <div className="stat-label">С ошибками</div>
        </div>
        <div 
          className={`stat-card ${filters.status === 'in_progress' ? 'stat-card-active' : ''}`}
          onClick={() => handleStatClick('in_progress')}
          style={{ cursor: 'pointer' }}
        >
          <div className="stat-value info">
            {allMigrations.filter(m => m.status === 'in_progress').length}
          </div>
          <div className="stat-label">В процессе</div>
        </div>
      </div>

      <div className="table-container">
        <div className="text-muted" style={{ marginBottom: '0.5rem', fontSize: '0.9rem', fontStyle: 'italic' }}>
          💡 Кликните на строку таблицы, чтобы открыть детали тестовой миграции
        </div>
        <table className="migrations-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>MB UUID</th>
              <th>Brizy ID</th>
              <th>Страница</th>
              <th>Элемент</th>
              <th>Статус</th>
              <th>Создано</th>
              <th>Обновлено</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            {filteredMigrations.length === 0 ? (
              <tr>
                <td colSpan={9} className="empty-state">
                  Тестовые миграции не найдены
                </td>
              </tr>
            ) : (
              filteredMigrations.map((migration) => {
                const statusConfig = getStatusConfig(migration.status);
                return (
                  <tr 
                    key={migration.id}
                    style={{ cursor: 'pointer' }}
                    onClick={(e) => {
                      // Не переходим, если клик был по кнопке или ссылке
                      const target = e.target as HTMLElement;
                      if (target.tagName === 'BUTTON' || target.tagName === 'A' || target.closest('button') || target.closest('a')) {
                        return;
                      }
                      navigate(`/test/${migration.id}`);
                    }}
                    className="migrations-table-row"
                  >
                    <td>{migration.id}</td>
                    <td className="uuid-cell">{formatUUID(migration.mb_project_uuid)}</td>
                    <td>{migration.brz_project_id}</td>
                    <td>{migration.mb_page_slug || '-'}</td>
                    <td>{migration.mb_element_name || '-'}</td>
                    <td>
                      <span
                        className="status-badge"
                        style={{
                          color: statusConfig.color,
                          backgroundColor: statusConfig.bgColor,
                        }}
                      >
                        {statusConfig.label}
                      </span>
                    </td>
                    <td>{formatDate(migration.created_at)}</td>
                    <td>{formatDate(migration.updated_at)}</td>
                    <td onClick={(e) => e.stopPropagation()}>
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <Link
                          to={`/test/${migration.id}`}
                          className="btn btn-sm btn-secondary"
                          onClick={(e) => e.stopPropagation()}
                        >
                          Детали
                        </Link>
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleRunMigration(migration.id);
                          }}
                          className="btn btn-sm btn-primary"
                          disabled={migration.status === 'in_progress'}
                        >
                          Запустить
                        </button>
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            handleDelete(migration.id);
                          }}
                          className="btn btn-sm btn-danger"
                        >
                          Удалить
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
