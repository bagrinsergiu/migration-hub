import React, { useState, useEffect } from 'react';
import { api } from '../api/client';
import './Settings.css';

interface SettingsData {
  mb_site_id: number | null;
  mb_secret: string | null;
}

export const Settings: React.FC = () => {
  const [settings, setSettings] = useState<SettingsData>({
    mb_site_id: null,
    mb_secret: null,
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    loadSettings();
  }, []);

  const loadSettings = async () => {
    try {
      setLoading(true);
      const response = await api.getSettings();
      if (response.success && response.data) {
        setSettings({
          mb_site_id: response.data.mb_site_id || null,
          mb_secret: response.data.mb_secret || null,
        });
      }
    } catch (error: any) {
      console.error('Ошибка загрузки настроек:', error);
      setMessage({ type: 'error', text: error.error || 'Ошибка загрузки настроек' });
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setMessage(null);
      
      const data: SettingsData = {
        mb_site_id: settings.mb_site_id || null,
        mb_secret: settings.mb_secret || null,
      };
      
      const response = await api.saveSettings(data);
      if (response.success) {
        setMessage({ type: 'success', text: 'Настройки сохранены' });
        // Обновляем настройки из ответа
        if (response.data) {
          setSettings({
            mb_site_id: response.data.mb_site_id || null,
            mb_secret: response.data.mb_secret || null,
          });
        }
      } else {
        setMessage({ type: 'error', text: response.error || 'Ошибка сохранения настроек' });
      }
    } catch (error: any) {
      console.error('Ошибка сохранения настроек:', error);
      setMessage({ type: 'error', text: error.error || 'Ошибка сохранения настроек' });
    } finally {
      setSaving(false);
    }
  };

  const handleChange = (field: keyof SettingsData, value: string | number | null) => {
    if (field === 'mb_site_id') {
      setSettings(prev => ({ ...prev, [field]: value ? parseInt(String(value)) : null }));
    } else {
      setSettings(prev => ({ ...prev, [field]: value ? String(value) : null }));
    }
  };

  if (loading) {
    return (
      <div className="settings-container">
        <div className="settings-loading">Загрузка настроек...</div>
      </div>
    );
  }

  return (
    <div className="settings-container">
      <h1>Настройки</h1>
      <p className="settings-description">
        Настройте значения по умолчанию для миграций. Эти значения будут использоваться автоматически,
        если они не указаны при запуске миграции.
      </p>

      {message && (
        <div className={`settings-message settings-message-${message.type}`}>
          {message.text}
        </div>
      )}

      <form onSubmit={handleSave} className="settings-form">
        <div className="settings-field">
          <label htmlFor="mb_site_id">
            MB Site ID
            <span className="settings-hint">(необязательно, используется по умолчанию)</span>
          </label>
          <input
            type="number"
            id="mb_site_id"
            value={settings.mb_site_id || ''}
            onChange={(e) => handleChange('mb_site_id', e.target.value)}
            placeholder="Например: 31383"
          />
        </div>

        <div className="settings-field">
          <label htmlFor="mb_secret">
            MB Secret
            <span className="settings-hint">(необязательно, используется по умолчанию)</span>
          </label>
          <input
            type="password"
            id="mb_secret"
            value={settings.mb_secret || ''}
            onChange={(e) => handleChange('mb_secret', e.target.value)}
            placeholder="Введите секретный ключ"
          />
        </div>

        <div className="settings-actions">
          <button type="submit" disabled={saving} className="settings-save-btn">
            {saving ? 'Сохранение...' : 'Сохранить настройки'}
          </button>
          <button
            type="button"
            onClick={() => {
              setSettings({ mb_site_id: null, mb_secret: null });
              setMessage(null);
            }}
            className="settings-clear-btn"
          >
            Очистить
          </button>
        </div>
      </form>

      <div className="settings-info">
        <h3>Как это работает:</h3>
        <ul>
          <li>Если значения заданы здесь, они будут использоваться автоматически при запуске миграции</li>
          <li>Если значения не заданы, их нужно будет указывать каждый раз при запуске миграции</li>
          <li>Значения, указанные при запуске миграции, имеют приоритет над настройками по умолчанию</li>
        </ul>
      </div>
    </div>
  );
};
