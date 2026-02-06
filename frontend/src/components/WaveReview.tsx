import { useState, useEffect, useMemo, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { WaveDetails as WaveDetailsType } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import { useTranslation } from '../hooks/useTranslation';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import './common.css';
import './WaveReview.css';

export default function WaveReview() {
  const { t } = useTranslation();
  const { token } = useParams<{ token: string }>();
  const navigate = useNavigate();
  const [details, setDetails] = useState<WaveDetailsType | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  
  // Используем useRef для отслеживания, был ли уже сделан запрос для этого токена
  const loadingRef = useRef<string | null>(null);
  const loadedRef = useRef<string | null>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!token) {
      setError(t('tokenOrUuidNotSpecified'));
      setLoading(false);
      return;
    }

    // Если уже загружены данные для этого токена, не делаем повторный запрос
    if (loadedRef.current === token && details) {
      console.log('Data already loaded for token:', token);
      return;
    }

    // Если уже загружаем этот токен, не делаем повторный запрос
    if (loadingRef.current === token) {
      console.log('Already loading token:', token);
      return;
    }

    // Отменяем предыдущий запрос, если он был
    if (abortControllerRef.current) {
      console.log('Aborting previous request');
      abortControllerRef.current.abort();
    }

    // Создаем новый AbortController для этого запроса
    const abortController = new AbortController();
    abortControllerRef.current = abortController;
    loadingRef.current = token;

    const loadWaveDetails = async () => {
      try {
        setLoading(true);
        setError(null);
        
        console.log('[WaveReview] Loading wave details for token:', token);
        
        // Используем публичный endpoint для ревью
        const response = await fetch(`/api/review/wave/${token}`, {
          signal: abortController.signal
        });
        
        if (abortController.signal.aborted) {
          console.log('[WaveReview] Request aborted');
          return;
        }
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('[WaveReview] API Error:', response.status, errorText);
          setError(`Ошибка загрузки: ${response.status} ${response.statusText}`);
          loadingRef.current = null;
          return;
        }
        
        const data = await response.json();
        
        if (abortController.signal.aborted) {
          console.log('[WaveReview] Request aborted after response');
          return;
        }
        
        console.log('[WaveReview] API Response received, success:', data.success);
        
        if (data.success && data.data) {
          // Проверяем структуру данных
          if (!data.data.wave) {
            console.error('[WaveReview] Invalid data structure: missing wave', data.data);
            setError('Неверная структура данных: отсутствует информация о волне');
            loadingRef.current = null;
            return;
          }
          
          if (!Array.isArray(data.data.migrations)) {
            console.error('[WaveReview] Invalid data structure: migrations is not an array', data.data);
            setError('Неверная структура данных: migrations не является массивом');
            loadingRef.current = null;
            return;
          }
          
          console.log('[WaveReview] Setting details, migrations count:', data.data.migrations.length);
          setDetails(data.data);
          loadedRef.current = token; // Отмечаем, что данные загружены
          // Сохраняем информацию о токене и настройках доступа
          if (data.data.token_info) {
            // Можно использовать для отображения информации о токене
          }
        } else {
          setError(data.error || t('waveNotFound'));
          loadingRef.current = null;
        }
      } catch (err: any) {
        if (abortController.signal.aborted) {
          console.log('[WaveReview] Request aborted in catch');
          return;
        }
        if (err.name === 'AbortError') {
          console.log('[WaveReview] Fetch aborted');
          return;
        }
        console.error('[WaveReview] Fetch error:', err);
        setError(err.message || t('errorLoadingData'));
        loadingRef.current = null;
      } finally {
        if (!abortController.signal.aborted) {
          setLoading(false);
          loadingRef.current = null;
        }
      }
    };

    loadWaveDetails();
    
    // Cleanup function для отмены запроса при размонтировании или изменении зависимостей
    return () => {
      console.log('[WaveReview] Cleanup: aborting request for token:', token);
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      loadingRef.current = null;
      abortControllerRef.current = null;
    };
  }, [token]); // Только token - details не должен быть в зависимостях, иначе будет бесконечный цикл

  // Фильтрация проектов - ВСЕГДА вызывается, даже если details еще нет
  const filteredMigrations = useMemo(() => {
    if (!details?.migrations) return [];
    
    return details.migrations.filter((migration) => {
      // Фильтр по поисковому запросу
      const searchLower = searchTerm.toLowerCase();
      const reviewerName = (migration as any).reviewer?.person_brizy?.toLowerCase() || '';
      const matchesSearch = 
        !searchTerm ||
        migration.mb_project_uuid?.toLowerCase().includes(searchLower) ||
        migration.brizy_project_domain?.toLowerCase().includes(searchLower) ||
        migration.brz_project_id?.toString().includes(searchLower) ||
        reviewerName.includes(searchLower);
      
      // Фильтр по статусу
      const matchesStatus = 
        statusFilter === 'all' || 
        migration.status === statusFilter;
      
      return matchesSearch && matchesStatus;
    });
  }, [details?.migrations, searchTerm, statusFilter]);

  // Получаем уникальные статусы для фильтра - ВСЕГДА вызывается
  const availableStatuses = useMemo(() => {
    if (!details?.migrations) return [];
    const statuses = new Set(details.migrations.map(m => m.status).filter(Boolean));
    return Array.from(statuses);
  }, [details?.migrations]);

  // Условные возвраты ПОСЛЕ всех хуков
  if (loading) {
    return (
      <div className="wave-review-page wave-review-skeleton">
        <div className="review-header">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }}>
            <div className="skeleton skeleton-title" style={{ width: 320, height: 32 }} />
            <div style={{ display: 'flex', gap: '1rem' }}>
              <div className="skeleton" style={{ width: 40, height: 32 }} />
              <div className="skeleton" style={{ width: 40, height: 32 }} />
            </div>
          </div>
          <div className="review-info" style={{ marginTop: '1rem' }}>
            <div className="skeleton skeleton-badge" style={{ width: 100, height: 24 }} />
            <div className="skeleton" style={{ width: 180, height: 20 }} />
          </div>
        </div>
        <div className="review-content">
          <div className="wave-summary">
            {[1, 2, 3].map((i) => (
              <div key={i} className="summary-item">
                <div className="skeleton" style={{ width: 100, height: 18 }} />
                <div className="skeleton" style={{ width: 200, height: 18 }} />
              </div>
            ))}
          </div>
          <div className="projects-section">
            <div className="projects-header">
              <div className="skeleton" style={{ width: 220, height: 24 }} />
              <div className="skeleton" style={{ width: 280, height: 36 }} />
            </div>
            <div className="skeleton skeleton-table">
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
            </div>
          </div>
        </div>
        <p className="skeleton-loading-text">{t('loading')}</p>
      </div>
    );
  }

  if (error || !details) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error || t('dataNotFound')}</p>
        <p style={{ marginTop: '1rem', color: '#666' }}>
          {t('checkReviewLink')}
        </p>
      </div>
    );
  }

  const wave = details.wave;
  const statusConfig = getStatusConfig(wave.status as any);
  const progressPercent = wave.progress.total > 0
    ? Math.round((wave.progress.completed / wave.progress.total) * 100)
    : 0;

  return (
    <div className="wave-review-page">
      <div className="review-header">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }}>
          <h1>{t('manualReview')} {wave.name}</h1>
          <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
            <LanguageSelector />
            <ThemeToggle />
          </div>
        </div>
        <div className="review-info">
          <span
            className="status-badge"
            style={{
              color: statusConfig.color,
              backgroundColor: statusConfig.bgColor,
            }}
          >
            {statusConfig.label}
          </span>
          <span className="progress-text">
            {t('progress')}: {wave.progress.completed} / {wave.progress.total} ({progressPercent}%)
          </span>
        </div>
      </div>

      <div className="review-content">
        <div className="wave-summary">
          <div className="summary-item">
            <span className="summary-label">{t('workspace')}</span>
            <span className="summary-value">{wave.workspace_name} (ID: {wave.workspace_id})</span>
          </div>
          <div className="summary-item">
            <span className="summary-label">{t('created')}:</span>
            <span className="summary-value">{formatDate(wave.created_at)}</span>
          </div>
          {wave.completed_at && (
            <div className="summary-item">
              <span className="summary-label">{t('completed')}:</span>
              <span className="summary-value">{formatDate(wave.completed_at)}</span>
            </div>
          )}
        </div>

        <div className="projects-section">
          <div className="projects-header">
            <h2>{t('projectsInMigration')}</h2>
            <div className="filters">
              <div className="filter-group">
                <label htmlFor="search">{t('search')}:</label>
                <input
                  id="search"
                  type="text"
                  placeholder={t('searchPlaceholder')}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="filter-input"
                />
              </div>
              <div className="filter-group">
                <label htmlFor="status">{t('status')}:</label>
                <select
                  id="status"
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="filter-select"
                >
                  <option value="all">{t('allStatuses')}</option>
                  {availableStatuses.map(status => {
                    const config = getStatusConfig(status as any);
                    return (
                      <option key={status} value={status}>
                        {config.label}
                      </option>
                    );
                  })}
                </select>
              </div>
            </div>
          </div>

          {details.migrations.length === 0 ? (
            <p className="empty-message">{t('projectsNotAdded')}</p>
          ) : filteredMigrations.length === 0 ? (
            <p className="empty-message">{t('projectsNotFound')}</p>
          ) : (
            <div className="projects-table-container">
              <table className="projects-table">
                <thead>
                  <tr>
                    <th>{t('domain')}</th>
                    <th>{t('reviewer')}</th>
                    <th>{t('mbUuid')}</th>
                    <th>{t('brizyProjectId')}</th>
                    <th>{t('status')}</th>
                    <th>{t('progress')}</th>
                    <th>{t('completed')}</th>
                    <th>{t('reviewReady')}</th>
                    <th>{t('errors')}</th>
                    <th>{t('back')}</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredMigrations.map((migration, index) => {
                    const migrationStatusConfig = getStatusConfig(migration.status as any);
                    const progress = migration.result_data?.progress;
                    const reviewAccess = (migration as any).review_access;
                    // Проект доступен, если review_access отсутствует (null) или is_active не равен false
                    // Если review_access === null, это означает, что проект доступен по умолчанию
                    const hasAccess = reviewAccess === null || reviewAccess === undefined || reviewAccess.is_active !== false;
                    
                    return (
                      <tr 
                        key={migration.mb_project_uuid || index}
                        className={!hasAccess ? 'project-disabled' : ''}
                      >
                        <td>
                          {migration.brizy_project_domain ? (
                            <a
                              href={migration.brizy_project_domain}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="link"
                            >
                              {migration.brizy_project_domain.replace(/^https?:\/\//, '').replace(/\/$/, '')}
                            </a>
                          ) : (
                            <span className="no-domain">—</span>
                          )}
                        </td>
                        <td>
                          {(migration as any).reviewer?.person_brizy ? (
                            <span title={(migration as any).reviewer?.uuid}>
                              {(migration as any).reviewer.person_brizy}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="uuid-cell">{formatUUID(migration.mb_project_uuid)}</td>
                        <td>{migration.brz_project_id && migration.brz_project_id !== 0 ? migration.brz_project_id : '—'}</td>
                        <td>
                          <span
                            className="status-badge"
                            style={{
                              color: migrationStatusConfig.color,
                              backgroundColor: migrationStatusConfig.bgColor,
                            }}
                          >
                            {migrationStatusConfig.label}
                          </span>
                        </td>
                        <td>
                          {progress ? (
                            <span>
                              {progress.Success || 0} / {progress.Total || 0}
                              {progress.processTime && ` (${progress.processTime.toFixed(1)}s)`}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td>{migration.completed_at ? formatDate(migration.completed_at) : '—'}</td>
                        <td>
                          {(() => {
                            const pr = (migration as any).project_review;
                            const status = pr?.review_status;
                            if (!pr || status === 'pending') return '—';
                            const statusLabels: Record<string, string> = {
                              approved: t('approved'),
                              rejected: t('rejected'),
                              needs_changes: t('needsChanges'),
                            };
                            const statusColors: Record<string, { color: string; bg: string }> = {
                              approved: { color: '#059669', bg: '#d1fae5' },
                              rejected: { color: '#dc2626', bg: '#fee2e2' },
                              needs_changes: { color: '#d97706', bg: '#ffedd5' },
                            };
                            const cfg = statusColors[status] || { color: '#6b7280', bg: '#f3f4f6' };
                            const label = statusLabels[status] || status;
                            return (
                              <span
                                className="status-badge"
                                style={{ color: cfg.color, backgroundColor: cfg.bg }}
                                title={pr.reviewed_at ? `${t('reviewReady')} ${formatDate(pr.reviewed_at)}` : t('reviewReady')}
                              >
                                ✓ {label}
                              </span>
                            );
                          })()}
                        </td>
                        <td>
                          {migration.error ? (
                            <span className="error-text" title={migration.error}>
                              ❌ {t('error')}
                            </span>
                          ) : migration.result_data?.warnings && migration.result_data.warnings.length > 0 ? (
                            <span className="warning-text">
                              ⚠ {migration.result_data.warnings.length}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td>
                          {hasAccess ? (
                            <button
                              className="btn-view-details"
                              onClick={() => {
                                // Используем brz_project_id (уникальный Brizy Project ID)
                                // ВАЖНО: НЕ используем migration.id или migration.migration_id - это ID из таблицы migrations, а не Brizy Project ID
                                let brzProjectId = migration.brz_project_id;
                                
                                console.log('[WaveReview] Navigation click - migration data:', {
                                  mb_project_uuid: migration.mb_project_uuid,
                                  brz_project_id: brzProjectId,
                                  migration_id: migration.migration_id,
                                  full_migration: migration
                                });
                                
                                // Если brz_project_id отсутствует или равен 0, пытаемся получить из result_data
                                if (!brzProjectId || brzProjectId === 0) {
                                  if (migration.result_data?.brizy_project_id) {
                                    brzProjectId = migration.result_data.brizy_project_id;
                                    console.log('[WaveReview] Using brz_project_id from result_data:', brzProjectId);
                                  }
                                }
                                
                                if (brzProjectId && brzProjectId !== 0 && brzProjectId !== null) {
                                  console.log('[WaveReview] Navigating to project with brz_project_id:', brzProjectId);
                                  navigate(`/review/${token}/project/${brzProjectId}`);
                                } else {
                                  console.error('[WaveReview] Brizy Project ID not found or invalid for migration:', {
                                    brz_project_id: brzProjectId,
                                    migration_id: migration.migration_id,
                                    full_migration: migration
                                  });
                                  alert(`Brizy Project ID не найден или недействителен для этого проекта.\n\nДоступные данные:\n- brz_project_id: ${brzProjectId}\n- migration_id: ${migration.migration_id}\n\nПожалуйста, убедитесь, что проект был создан в Brizy.`);
                                }
                              }}
                              title={t('overview')}
                            >
                              👁️ {t('overview')}
                            </button>
                          ) : (
                            <span className="no-action">—</span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              <div className="table-footer">
                <span className="results-count">
                  {t('shown')} {filteredMigrations.length} {t('of')} {details.migrations.length} {t('projects')}
                </span>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
