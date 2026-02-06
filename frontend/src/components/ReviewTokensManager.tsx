import { useState, useEffect } from 'react';
import { api } from '../api/client';
import { formatDate } from '../utils/format';
import './ReviewTokensManager.css';

interface ReviewToken {
  id: number;
  token: string;
  name?: string;
  description?: string;
  created_at: string;
  expires_at?: string;
  is_active: number;
  review_url: string;
  created_by_username?: string;
  projects?: Array<{
    mb_uuid: string;
    allowed_tabs: string[];
    is_active: number;
  }>;
}

interface ReviewTokensManagerProps {
  waveId: string;
  projects: Array<{ mb_uuid: string; [key: string]: any }>;
}

export default function ReviewTokensManager({ waveId, projects }: ReviewTokensManagerProps) {
  const [tokens, setTokens] = useState<ReviewToken[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [projectsAccordionOpen, setProjectsAccordionOpen] = useState<Set<number>>(new Set());
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    expires_in_days: '',
    project_settings: {} as Record<string, { allowed_tabs: string[]; is_active: boolean }>,
    applyToAllTemplate: { allowed_tabs: [] as string[], is_active: true }
  });

  const availableTabs = ['overview', 'details', 'logs', 'screenshots', 'quality'];

  useEffect(() => {
    loadTokens();
  }, [waveId]);

  const loadTokens = async () => {
    try {
      setLoading(true);
      const response = await api.getReviewTokens(waveId);
      if (response.success && response.data) {
        setTokens(response.data);
      } else {
        setError(response.error || 'Ошибка загрузки токенов');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки токенов');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateToken = async () => {
    try {
      setError(null);
      const data = {
        name: formData.name || undefined,
        description: formData.description || undefined,
        expires_in_days: formData.expires_in_days ? parseInt(formData.expires_in_days) : undefined,
        project_settings: formData.project_settings
      };

      const response = await api.createReviewToken(waveId, data);
      if (response.success) {
        setShowCreateModal(false);
        resetForm();
        loadTokens();
      } else {
        setError(response.error || 'Ошибка создания токена');
      }
    } catch (err: any) {
      const code = err?.code;
      const status = err?.response?.status;
      let msg: string;
      if (code === 'ERR_NETWORK' || code === 'ERR_NETWORK_CHANGED') {
        msg = 'Ошибка сети. Проверьте подключение и что бэкенд (localhost:8088) запущен. Попробуйте ещё раз.';
      } else if (status === 502) {
        msg = 'Бэкенд недоступен (502 Bad Gateway). Убедитесь, что сервер запущен на порту 8088.';
      } else if (status === 500) {
        msg = err?.response?.data?.error || 'Ошибка сервера (500). Проверьте логи бэкенда.';
      } else {
        msg = err?.response?.data?.error || err.message || 'Ошибка создания токена';
      }
      setError(msg);
    }
  };

  const handleUpdateToken = async (tokenId: number, updates: any) => {
    try {
      setError(null);
      const response = await api.updateReviewToken(waveId, tokenId, updates);
      if (response.success) {
        loadTokens();
      } else {
        setError(response.error || 'Ошибка обновления токена');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка обновления токена');
    }
  };

  const handleDeleteToken = async (tokenId: number) => {
    if (!confirm('Вы уверены, что хотите удалить этот токен?')) {
      return;
    }

    try {
      setError(null);
      const response = await api.deleteReviewToken(waveId, tokenId);
      if (response.success) {
        loadTokens();
      } else {
        setError(response.error || 'Ошибка удаления токена');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка удаления токена');
    }
  };

  const handleToggleToken = async (token: ReviewToken) => {
    await handleUpdateToken(token.id, { is_active: !token.is_active });
  };

  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      expires_in_days: '',
      project_settings: {},
      applyToAllTemplate: { allowed_tabs: [], is_active: true }
    });
  };

  const applyToAllProjects = () => {
    const template = formData.applyToAllTemplate;
    const newSettings: Record<string, { allowed_tabs: string[]; is_active: boolean }> = {};
    projects.forEach(p => {
      newSettings[p.mb_uuid] = {
        allowed_tabs: [...template.allowed_tabs],
        is_active: template.is_active
      };
    });
    setFormData({
      ...formData,
      project_settings: newSettings
    });
  };

  const toggleApplyToAllTab = (tab: string) => {
    const current = formData.applyToAllTemplate.allowed_tabs;
    const allowedTabs = current.includes(tab)
      ? current.filter(t => t !== tab)
      : [...current, tab];
    setFormData({
      ...formData,
      applyToAllTemplate: {
        ...formData.applyToAllTemplate,
        allowed_tabs: allowedTabs
      }
    });
  };

  const toggleProjectTab = (mbUuid: string, tab: string) => {
    const current = formData.project_settings[mbUuid] || { allowed_tabs: [], is_active: true };
    const allowedTabs = current.allowed_tabs.includes(tab)
      ? current.allowed_tabs.filter(t => t !== tab)
      : [...current.allowed_tabs, tab];
    
    setFormData({
      ...formData,
      project_settings: {
        ...formData.project_settings,
        [mbUuid]: {
          ...current,
          allowed_tabs: allowedTabs
        }
      }
    });
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка токенов...</p>
      </div>
    );
  }

  return (
    <div className="review-tokens-manager">
      <div className="tokens-header">
        <h3>Публичные ссылки для ревью</h3>
        <button
          className="btn btn-primary"
          onClick={() => setShowCreateModal(true)}
        >
          + Создать ссылку
        </button>
      </div>

      {error && (
        <div className="alert alert-error">
          {error}
        </div>
      )}

      {tokens.length === 0 ? (
        <div className="empty-message">
          <p>Нет созданных ссылок для ревью</p>
          <p style={{ fontSize: '0.875rem', color: '#666', marginTop: '0.5rem' }}>
            Создайте ссылку, чтобы поделиться волной для мануального ревью
          </p>
        </div>
      ) : (
        <div className="tokens-list">
          {tokens.map((token) => (
            <div key={token.id} className="token-card">
              <div className="token-header">
                <div className="token-info">
                  <h4>{token.name || 'Без названия'}</h4>
                  {token.description && (
                    <p className="token-description">{token.description}</p>
                  )}
                  <div className="token-meta">
                    <span>Создано: {formatDate(token.created_at)}</span>
                    {token.expires_at && (
                      <span>Истекает: {formatDate(token.expires_at)}</span>
                    )}
                    {token.created_by_username && (
                      <span>Создал: {token.created_by_username}</span>
                    )}
                  </div>
                </div>
                <div className="token-actions">
                  <button
                    className={`btn btn-sm ${token.is_active ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => handleToggleToken(token)}
                  >
                    {token.is_active ? 'Деактивировать' : 'Активировать'}
                  </button>
                  <button
                    className="btn btn-sm btn-danger"
                    onClick={() => handleDeleteToken(token.id)}
                  >
                    Удалить
                  </button>
                </div>
              </div>

              <div className="token-url">
                <label>Ссылка для ревью:</label>
                <div className="url-input-group">
                  <input
                    type="text"
                    value={token.review_url}
                    readOnly
                    className="url-input"
                  />
                  <button
                    className="btn btn-sm btn-secondary"
                    onClick={() => {
                      navigator.clipboard.writeText(token.review_url);
                      alert('Ссылка скопирована в буфер обмена');
                    }}
                  >
                    Копировать
                  </button>
                </div>
              </div>

              {token.projects && token.projects.length > 0 && (
                <div className="token-projects token-projects-accordion">
                  <button
                    type="button"
                    className="token-projects-accordion-trigger"
                    onClick={() => {
                      setProjectsAccordionOpen((prev) => {
                        const next = new Set(prev);
                        if (next.has(token.id)) next.delete(token.id);
                        else next.add(token.id);
                        return next;
                      });
                    }}
                    aria-expanded={projectsAccordionOpen.has(token.id)}
                  >
                    <span className="token-projects-accordion-title">
                      Настройки доступа по проектам: <span className="token-projects-count">({token.projects.length})</span>
                    </span>
                    <span className={`token-projects-accordion-icon ${projectsAccordionOpen.has(token.id) ? 'is-open' : ''}`} aria-hidden>▼</span>
                  </button>
                  {projectsAccordionOpen.has(token.id) && (
                    <div className="projects-access-list">
                      {token.projects.map((project) => {
                        const projectInfo = projects.find(p => p.mb_uuid === project.mb_uuid);
                        return (
                          <div key={project.mb_uuid} className="project-access-item">
                            <div className="project-access-header">
                              <span className="project-uuid">{projectInfo?.brizy_project_domain || project.mb_uuid}</span>
                              <span className={`status-badge ${project.is_active ? 'active' : 'inactive'}`}>
                                {project.is_active ? 'Активен' : 'Неактивен'}
                              </span>
                            </div>
                            <div className="project-access-tabs">
                              <span className="tabs-label">Доступные вкладки:</span>
                              <div className="tabs-list">
                                {project.allowed_tabs.length > 0 ? (
                                  project.allowed_tabs.map(tab => (
                                    <span key={tab} className="tab-badge">{tab}</span>
                                  ))
                                ) : (
                                  <span className="no-tabs">Нет доступа</span>
                                )}
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {showCreateModal && (
        <div className="modal-overlay" onClick={() => setShowCreateModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Создать ссылку для ревью</h3>
              <button
                className="btn-close"
                onClick={() => {
                  setShowCreateModal(false);
                  resetForm();
                }}
              >
                ×
              </button>
            </div>
            <div className="modal-body">
              <div className="form-group">
                <label>Название ссылки:</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Например: Ревью для команды разработки"
                />
              </div>

              <div className="form-group">
                <label>Описание:</label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Описание ссылки (необязательно)"
                  rows={3}
                />
              </div>

              <div className="form-group">
                <label>Срок действия (дней):</label>
                <input
                  type="number"
                  value={formData.expires_in_days}
                  onChange={(e) => setFormData({ ...formData, expires_in_days: e.target.value })}
                  placeholder="Оставьте пустым для бессрочной ссылки"
                  min="1"
                />
              </div>

              <div className="form-group">
                <label>Настройки доступа по проектам:</label>
                <div className="apply-to-all-block">
                  <div className="apply-to-all-header">
                    <span className="apply-to-all-title">Применить ко всем проектам:</span>
                    <button
                      type="button"
                      className="btn btn-sm btn-secondary"
                      onClick={applyToAllProjects}
                    >
                      Применить ко всем
                    </button>
                  </div>
                  <div className="apply-to-all-settings">
                    <label className="toggle-row">
                      <input
                        type="checkbox"
                        checked={formData.applyToAllTemplate.is_active}
                        onChange={(e) =>
                          setFormData({
                            ...formData,
                            applyToAllTemplate: {
                              ...formData.applyToAllTemplate,
                              is_active: e.target.checked
                            }
                          })
                        }
                      />
                      <span>Доступ включён</span>
                    </label>
                    <div className="apply-to-all-tabs">
                      {availableTabs.map((tab) => (
                        <label key={tab} className="tab-checkbox">
                          <input
                            type="checkbox"
                            checked={formData.applyToAllTemplate.allowed_tabs.includes(tab)}
                            onChange={() => toggleApplyToAllTab(tab)}
                          />
                          <span>{tab}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                </div>
                <div className="projects-settings">
                  {projects.map((project) => {
                    const projectSettings = formData.project_settings[project.mb_uuid] || {
                      allowed_tabs: [],
                      is_active: true
                    };
                    
                    return (
                      <div key={project.mb_uuid} className="project-settings-item">
                        <div className="project-settings-header">
                          <span className="project-name">
                            {project.brizy_project_domain || project.mb_uuid}
                          </span>
                          <label className="toggle-switch">
                            <input
                              type="checkbox"
                              checked={projectSettings.is_active}
                              onChange={(e) => {
                                setFormData({
                                  ...formData,
                                  project_settings: {
                                    ...formData.project_settings,
                                    [project.mb_uuid]: {
                                      ...projectSettings,
                                      is_active: e.target.checked
                                    }
                                  }
                                });
                              }}
                            />
                            <span className="toggle-slider"></span>
                          </label>
                        </div>
                        {projectSettings.is_active && (
                          <div className="project-tabs-settings">
                            <span className="tabs-label">Выберите доступные вкладки:</span>
                            <div className="tabs-checkboxes">
                              {availableTabs.map((tab) => (
                                <label key={tab} className="tab-checkbox">
                                  <input
                                    type="checkbox"
                                    checked={projectSettings.allowed_tabs.includes(tab)}
                                    onChange={() => toggleProjectTab(project.mb_uuid, tab)}
                                  />
                                  <span>{tab}</span>
                                </label>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
            <div className="modal-footer">
              <button
                className="btn btn-secondary"
                onClick={() => {
                  setShowCreateModal(false);
                  resetForm();
                }}
              >
                Отмена
              </button>
              <button
                className="btn btn-primary"
                onClick={handleCreateToken}
              >
                Создать ссылку
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
